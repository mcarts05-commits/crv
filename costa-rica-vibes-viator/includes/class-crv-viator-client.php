<?php
/**
 * Thin wrapper around the Viator Partner API v2.
 *
 * Every response is cached in a transient. A longer-lived "stale" copy is kept
 * too, so a Viator outage or rate limit shows yesterday's tours instead of an
 * empty box.
 */

defined( 'ABSPATH' ) || exit;

class CRV_Viator_Client {

	const BASE_URL_PRODUCTION = 'https://api.viator.com/partner';
	const BASE_URL_SANDBOX    = 'https://api.sandbox.viator.com/partner';

	const CACHE_PREFIX = 'crv_vt_';
	const STALE_TTL    = WEEK_IN_SECONDS;

	/** Sort keys accepted by the shortcode, mapped to Viator's sorting object. */
	const SORTS = array(
		'featured'   => array( 'sort' => 'DEFAULT' ),
		'rating'     => array( 'sort' => 'TRAVELER_RATING', 'order' => 'DESCENDING' ),
		'price_low'  => array( 'sort' => 'PRICE', 'order' => 'ASCENDING' ),
		'price_high' => array( 'sort' => 'PRICE', 'order' => 'DESCENDING' ),
		'newest'     => array( 'sort' => 'DATE_ADDED', 'order' => 'DESCENDING' ),
	);

	private $api_key;
	private $base_url;
	private $language;
	private $currency;
	private $cache_ttl;

	public function __construct( $api_key, $environment = 'production', $currency = 'USD', $language = 'en-US', $cache_ttl = 6 * HOUR_IN_SECONDS ) {
		$this->api_key   = (string) $api_key;
		$this->base_url  = 'sandbox' === $environment ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
		$this->currency  = $currency;
		$this->language  = $language;
		$this->cache_ttl = (int) $cache_ttl;
	}

	public static function from_settings() {
		$s = CRV_Viator_Settings::get();
		return new self(
			CRV_Viator_Settings::api_key(),
			$s['environment'],
			$s['currency'],
			$s['language'],
			max( 1, (int) $s['cache_hours'] ) * HOUR_IN_SECONDS
		);
	}

	public function has_key() {
		return '' !== $this->api_key;
	}

	/**
	 * Products in a destination, via POST /products/search.
	 *
	 * @param array $args {
	 *     @type string   $destination Viator destination ID (required).
	 *     @type int      $count       1–50.
	 *     @type string   $sort        Key of self::SORTS.
	 *     @type string[] $flags       e.g. FREE_CANCELLATION, PRIVATE_TOUR, SPECIAL_OFFER.
	 *     @type int[]    $tags        Viator tag IDs.
	 *     @type float    $min_rating  0–5.
	 *     @type float    $max_price   In the configured currency.
	 * }
	 * @return array|WP_Error List of products.
	 */
	public function search_products( array $args ) {
		$body = array(
			'filtering'  => array_merge(
				array( 'destination' => (string) $args['destination'] ),
				$this->filters( $args )
			),
			'sorting'    => $this->sorting( $args ),
			'pagination' => array(
				'start' => 1,
				'count' => $this->count( $args ),
			),
			'currency'   => $this->currency,
		);

		return $this->cached(
			'search_' . md5( wp_json_encode( $body ) . $this->language ),
			function () use ( $body ) {
				$data = $this->request( 'POST', '/products/search', $body );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				return isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
			}
		);
	}

	/**
	 * Keyword search ("zipline", "Arenal volcano hike"), optionally limited to
	 * a destination, via POST /search/freetext.
	 *
	 * @param string $term Search term.
	 * @param array  $args Same keys as search_products(); destination optional.
	 * @return array|WP_Error List of products.
	 */
	public function freetext_search( $term, array $args = array() ) {
		$filtering = $this->filters( $args );
		if ( ! empty( $args['destination'] ) ) {
			$filtering['destination'] = (string) $args['destination'];
		}

		$body = array(
			'searchTerm'  => (string) $term,
			'searchTypes' => array(
				array(
					'searchType' => 'PRODUCTS',
					'pagination' => array(
						'start' => 1,
						'count' => $this->count( $args ),
					),
				),
			),
			'currency'    => $this->currency,
		);
		if ( $filtering ) {
			$body['productFiltering'] = $filtering;
		}
		if ( isset( $args['sort'] ) && 'featured' !== $args['sort'] ) {
			$body['productSorting'] = $this->sorting( $args );
		}

		return $this->cached(
			'text_' . md5( wp_json_encode( $body ) . $this->language ),
			function () use ( $body ) {
				$data = $this->request( 'POST', '/search/freetext', $body );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				return isset( $data['products']['results'] ) && is_array( $data['products']['results'] ) ? $data['products']['results'] : array();
			}
		);
	}

	/**
	 * All Viator destinations, trimmed to the fields we use. Cached for a week:
	 * the full list is large and rarely changes.
	 *
	 * @return array|WP_Error Map of destination ID => array( name, type, parent ).
	 */
	public function get_destinations() {
		return $this->cached(
			'destinations',
			function () {
				$data = $this->request( 'GET', '/destinations' );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$out = array();
				foreach ( (array) ( $data['destinations'] ?? array() ) as $d ) {
					if ( ! isset( $d['destinationId'], $d['name'] ) ) {
						continue;
					}
					$out[ (int) $d['destinationId'] ] = array(
						'name'   => (string) $d['name'],
						'type'   => (string) ( $d['type'] ?? '' ),
						'parent' => (int) ( $d['parentDestinationId'] ?? 0 ),
					);
				}
				return $out;
			},
			WEEK_IN_SECONDS
		);
	}

	/** Deletes every cached response (fresh and stale). */
	public static function flush_cache() {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$like = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	private function filters( array $args ) {
		$f = array();
		if ( ! empty( $args['flags'] ) ) {
			$f['flags'] = array_values( array_map( 'strval', (array) $args['flags'] ) );
		}
		if ( ! empty( $args['tags'] ) ) {
			$f['tags'] = array_values( array_map( 'intval', (array) $args['tags'] ) );
		}
		if ( ! empty( $args['min_rating'] ) ) {
			$f['rating'] = array(
				'from' => (float) $args['min_rating'],
				'to'   => 5,
			);
		}
		if ( ! empty( $args['max_price'] ) ) {
			$f['highestPrice'] = (float) $args['max_price'];
		}
		return $f;
	}

	private function sorting( array $args ) {
		$key = isset( $args['sort'], self::SORTS[ $args['sort'] ] ) ? $args['sort'] : 'featured';
		return self::SORTS[ $key ];
	}

	private function count( array $args ) {
		return min( 50, max( 1, (int) ( $args['count'] ?? 6 ) ) );
	}

	/**
	 * @return array|WP_Error Decoded JSON body.
	 */
	private function request( $method, $path, $body = null ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'crv_viator_no_key', __( 'No Viator API key is configured.', 'crv-viator' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'exp-api-key'     => $this->api_key,
				'Accept'          => 'application/json;version=2.0',
				'Accept-Language' => $this->language,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : wp_remote_retrieve_response_message( $response );
			if ( 401 === $code || 403 === $code ) {
				$message .= ' ' . sprintf(
					/* translators: %s: Production or Sandbox. */
					__( '(Sent to %s. Check the key was copied in full and matches the Environment setting.)', 'crv-viator' ),
					self::BASE_URL_SANDBOX === $this->base_url ? 'Sandbox' : 'Production'
				);
			}
			return new WP_Error(
				'crv_viator_http_' . $code,
				/* translators: 1: HTTP status code, 2: error message from Viator. */
				sprintf( __( 'Viator API error %1$d: %2$s', 'crv-viator' ), $code, $message )
			);
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'crv_viator_bad_json', __( 'Viator returned an unreadable response.', 'crv-viator' ) );
		}
		return $data;
	}

	/**
	 * Returns the cached value for $key, or runs $fetch. On a fetch error, falls
	 * back to the stale copy when there is one.
	 */
	private function cached( $key, callable $fetch, $ttl = null ) {
		$key   = self::CACHE_PREFIX . $key;
		$stale = $key . '_stale';

		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}

		$value = $fetch();
		if ( is_wp_error( $value ) ) {
			$old = get_transient( $stale );
			return false !== $old ? $old : $value;
		}

		$ttl = null === $ttl ? $this->cache_ttl : (int) $ttl;
		set_transient( $key, $value, $ttl );
		set_transient( $stale, $value, max( $ttl, self::STALE_TTL ) );
		return $value;
	}
}
