<?php
/**
 * [viator_tours] – renders a grid of Viator tour cards.
 */

defined( 'ABSPATH' ) || exit;

class CRV_Viator_Shortcode {

	const TAG = 'viator_tours';

	const FLAG_LABELS = array(
		'FREE_CANCELLATION'  => 'Free cancellation',
		'LIKELY_TO_SELL_OUT' => 'Likely to sell out',
		'SPECIAL_OFFER'      => 'Special offer',
		'PRIVATE_TOUR'       => 'Private tour',
		'SKIP_THE_LINE'      => 'Skip the line',
	);

	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'crv-viator', CRV_VIATOR_URL . 'assets/css/viator-tours.css', array(), CRV_VIATOR_VERSION );
	}

	public static function render( $atts ) {
		$settings = CRV_Viator_Settings::get();
		$atts     = shortcode_atts(
			array(
				'destination' => '',
				'search'      => '',
				'count'       => $settings['count'],
				'sort'        => 'featured',
				'flags'       => '',
				'tags'        => '',
				'min_rating'  => '',
				'max_price'   => '',
				'columns'     => 3,
				'title'       => '',
				'campaign'    => '',
			),
			$atts,
			self::TAG
		);

		$args = array(
			'destination' => preg_replace( '/\D/', '', $atts['destination'] ),
			'count'       => (int) $atts['count'],
			'sort'        => sanitize_key( $atts['sort'] ),
			'flags'       => self::csv( strtoupper( $atts['flags'] ) ),
			'tags'        => array_filter( array_map( 'intval', self::csv( $atts['tags'] ) ) ),
			'min_rating'  => (float) $atts['min_rating'],
			'max_price'   => (float) $atts['max_price'],
		);
		$search = trim( $atts['search'] );

		if ( '' === $args['destination'] && '' === $search ) {
			return self::admin_error( __( '[viator_tours] needs a destination or search attribute.', 'crv-viator' ) );
		}

		$client = CRV_Viator_Client::from_settings();
		if ( ! $client->has_key() ) {
			return self::admin_error( __( 'Add your Viator API key under Settings → Viator Tours.', 'crv-viator' ) );
		}

		$products = '' !== $search ? $client->freetext_search( $search, $args ) : $client->search_products( $args );
		if ( is_wp_error( $products ) ) {
			return self::admin_error( $products->get_error_message() );
		}
		if ( ! $products ) {
			return self::admin_error( __( 'No Viator tours matched this shortcode.', 'crv-viator' ) );
		}

		// Campaign shows up in Viator's reports, so you can see which page earned each booking.
		$campaign = '' !== $atts['campaign'] ? $atts['campaign'] : get_post_field( 'post_name', get_the_ID() );
		$campaign = substr( sanitize_title( (string) $campaign ), 0, 60 );

		wp_enqueue_style( 'crv-viator' );

		$columns = min( 4, max( 1, (int) $atts['columns'] ) );
		$html    = '<section class="crv-tours crv-tours--cols-' . $columns . '">';
		if ( '' !== $atts['title'] ) {
			$html .= '<h3 class="crv-tours__title">' . esc_html( $atts['title'] ) . '</h3>';
		}
		$html .= '<div class="crv-tours__grid">';
		foreach ( $products as $product ) {
			$html .= self::card( $product, $campaign, $settings['currency'] );
		}
		$html .= '</div>';
		if ( '' !== $settings['disclosure'] ) {
			$html .= '<p class="crv-tours__disclosure">' . esc_html( $settings['disclosure'] ) . '</p>';
		}
		$html .= '</section>';

		return $html;
	}

	public static function card( array $p, $campaign, $currency ) {
		$url = isset( $p['productUrl'] ) ? (string) $p['productUrl'] : '';
		if ( '' === $url ) {
			return '';
		}
		if ( '' !== $campaign ) {
			$url = add_query_arg( 'campaign', $campaign, $url );
		}

		$title   = (string) ( $p['title'] ?? '' );
		$image   = self::image_url( $p );
		$rating  = (float) ( $p['reviews']['combinedAverageRating'] ?? 0 );
		$reviews = (int) ( $p['reviews']['totalReviews'] ?? 0 );
		$price   = $p['pricing']['summary']['fromPrice'] ?? null;
		$cur     = (string) ( $p['pricing']['currency'] ?? $currency );
		$length  = self::duration( $p['duration'] ?? array() );
		$flags   = array_intersect_key( self::FLAG_LABELS, array_flip( (array) ( $p['flags'] ?? array() ) ) );

		$link_attrs = 'href="' . esc_url( $url ) . '" target="_blank" rel="sponsored nofollow noopener"';

		$h  = '<article class="crv-tour">';
		$h .= '<a class="crv-tour__media" ' . $link_attrs . ' tabindex="-1" aria-hidden="true">';
		if ( $image ) {
			$h .= '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async">';
		}
		$h .= '</a><div class="crv-tour__body">';
		$h .= '<h4 class="crv-tour__name"><a ' . $link_attrs . '>' . esc_html( $title ) . '</a></h4>';

		if ( $reviews > 0 ) {
			$h .= '<p class="crv-tour__rating"><span class="crv-tour__stars" style="--crv-rating:' . esc_attr( round( $rating, 1 ) ) . '" aria-hidden="true">★★★★★</span> '
				/* translators: 1: average rating, 2: number of reviews. */
				. esc_html( sprintf( __( '%1$s (%2$s reviews)', 'crv-viator' ), number_format_i18n( $rating, 1 ), number_format_i18n( $reviews ) ) ) . '</p>';
		}

		$meta = array();
		if ( $length ) {
			$meta[] = esc_html( $length );
		}
		foreach ( $flags as $label ) {
			$meta[] = '<span class="crv-tour__flag">' . esc_html( $label ) . '</span>';
		}
		if ( $meta ) {
			$h .= '<p class="crv-tour__meta">' . implode( ' · ', $meta ) . '</p>';
		}

		$h .= '<div class="crv-tour__footer">';
		if ( null !== $price ) {
			/* translators: %s: formatted price. */
			$h .= '<p class="crv-tour__price">' . esc_html( sprintf( __( 'From %s', 'crv-viator' ), self::money( (float) $price, $cur ) ) ) . '</p>';
		}
		$h .= '<a class="crv-tour__cta" ' . $link_attrs . '>' . esc_html__( 'Check availability', 'crv-viator' ) . '</a>';
		$h .= '</div></div></article>';

		return $h;
	}

	/** Cover image variant closest to 720px wide (sharp on retina at card size). */
	public static function image_url( array $p ) {
		$images = (array) ( $p['images'] ?? array() );
		if ( ! $images ) {
			return '';
		}
		$image = $images[0];
		foreach ( $images as $img ) {
			if ( ! empty( $img['isCover'] ) ) {
				$image = $img;
				break;
			}
		}
		$best = '';
		$diff = PHP_INT_MAX;
		foreach ( (array) ( $image['variants'] ?? array() ) as $v ) {
			$d = abs( (int) ( $v['width'] ?? 0 ) - 720 );
			if ( ! empty( $v['url'] ) && $d < $diff ) {
				$best = $v['url'];
				$diff = $d;
			}
		}
		return $best;
	}

	public static function duration( array $d ) {
		if ( ! empty( $d['fixedDurationInMinutes'] ) ) {
			return self::minutes( (int) $d['fixedDurationInMinutes'] );
		}
		if ( ! empty( $d['variableDurationFromMinutes'] ) && ! empty( $d['variableDurationToMinutes'] ) ) {
			return self::minutes( (int) $d['variableDurationFromMinutes'] ) . '–' . self::minutes( (int) $d['variableDurationToMinutes'] );
		}
		return '';
	}

	private static function minutes( $m ) {
		if ( $m >= 1440 ) {
			$days = round( $m / 1440, 1 );
			/* translators: %s: number of days. */
			return sprintf( _n( '%s day', '%s days', (int) ceil( $days ), 'crv-viator' ), number_format_i18n( $days, floor( $days ) == $days ? 0 : 1 ) );
		}
		if ( $m >= 60 ) {
			$hours = round( $m / 60, 1 );
			/* translators: %s: number of hours. */
			return sprintf( __( '%s h', 'crv-viator' ), number_format_i18n( $hours, floor( $hours ) == $hours ? 0 : 1 ) );
		}
		/* translators: %d: number of minutes. */
		return sprintf( __( '%d min', 'crv-viator' ), $m );
	}

	private static function money( $amount, $currency ) {
		$symbols = array(
			'USD' => '$',
			'CAD' => 'CA$',
			'AUD' => 'A$',
			'EUR' => '€',
			'GBP' => '£',
		);
		$decimals = floor( $amount ) == $amount ? 0 : 2;
		$number   = number_format_i18n( $amount, $decimals );
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $number . ' ' . $currency;
	}

	private static function csv( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	/** Errors are shown only to admins; visitors just see nothing. */
	private static function admin_error( $message ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<div class="crv-tours__error"><strong>Viator Tours:</strong> ' . esc_html( $message ) . ' <em>(' . esc_html__( 'only admins see this', 'crv-viator' ) . ')</em></div>';
	}
}
