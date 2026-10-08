<?php
/**
 * Settings → Viator Tours: API key, environment, currency, cache, a connection
 * test and a destination ID finder.
 */

defined( 'ABSPATH' ) || exit;

class CRV_Viator_Settings {

	const OPTION = 'crv_viator_settings';
	const PAGE   = 'crv-viator';

	const DEFAULTS = array(
		'api_key'     => '',
		'environment' => 'production',
		'currency'    => 'USD',
		'language'    => 'en-US',
		'cache_hours' => 6,
		'count'       => 6,
		'disclosure'  => 'We may earn a commission if you book through these links, at no extra cost to you.',
	);

	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_crv_viator_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_crv_viator_flush', array( __CLASS__, 'handle_flush' ) );
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
	}

	/** A CRV_VIATOR_API_KEY constant in wp-config.php wins over the saved option. */
	public static function api_key() {
		if ( defined( 'CRV_VIATOR_API_KEY' ) && CRV_VIATOR_API_KEY ) {
			return trim( (string) CRV_VIATOR_API_KEY );
		}
		return trim( (string) self::get()['api_key'] );
	}

	public static function add_page() {
		add_options_page(
			__( 'Viator Tours', 'crv-viator' ),
			__( 'Viator Tours', 'crv-viator' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	public static function sanitize( $input ) {
		$old = self::get();
		$in  = is_array( $input ) ? $input : array();

		$key = isset( $in['api_key'] ) ? trim( sanitize_text_field( $in['api_key'] ) ) : '';

		$out = array(
			// A blank key field means "keep the current key"; the saved key is never echoed back.
			'api_key'     => '' === $key ? $old['api_key'] : $key,
			'environment' => isset( $in['environment'] ) && 'sandbox' === $in['environment'] ? 'sandbox' : 'production',
			'currency'    => isset( $in['currency'] ) && in_array( $in['currency'], self::CURRENCIES, true ) ? $in['currency'] : 'USD',
			'language'    => isset( $in['language'] ) && preg_match( '/^[a-z]{2}(-[A-Z]{2})?$/', $in['language'] ) ? $in['language'] : 'en-US',
			'cache_hours' => isset( $in['cache_hours'] ) ? min( 168, max( 1, (int) $in['cache_hours'] ) ) : 6,
			'count'       => isset( $in['count'] ) ? min( 24, max( 1, (int) $in['count'] ) ) : 6,
			'disclosure'  => isset( $in['disclosure'] ) ? sanitize_text_field( $in['disclosure'] ) : '',
		);

		if ( ! empty( $in['clear_key'] ) ) {
			$out['api_key'] = '';
		}

		// New settings can change prices and currency; don't keep serving the old ones.
		CRV_Viator_Client::flush_cache();

		return $out;
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'crv-viator' ) );
		}
		check_admin_referer( 'crv_viator_test' );

		// Bypass the cache so the test really reaches Viator.
		CRV_Viator_Client::flush_cache();
		$result = CRV_Viator_Client::from_settings()->freetext_search( 'Costa Rica', array( 'count' => 1 ) );

		if ( is_wp_error( $result ) ) {
			$notice = array( 'error', $result->get_error_message() );
		} elseif ( empty( $result ) ) {
			$notice = array( 'warning', __( 'Connected, but the test search returned no tours.', 'crv-viator' ) );
		} else {
			/* translators: %s: tour title. */
			$notice = array( 'success', sprintf( __( 'Connected to Viator. Sample tour: %s', 'crv-viator' ), $result[0]['title'] ?? '' ) );
		}
		set_transient( 'crv_viator_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	public static function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'crv-viator' ) );
		}
		check_admin_referer( 'crv_viator_flush' );

		CRV_Viator_Client::flush_cache();
		set_transient( 'crv_viator_notice_' . get_current_user_id(), array( 'success', __( 'Tour cache cleared.', 'crv-viator' ) ), MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = self::get();
		$name       = self::OPTION;
		$from_const = defined( 'CRV_VIATOR_API_KEY' ) && CRV_VIATOR_API_KEY;
		$has_key    = '' !== self::api_key();

		$notice_key = 'crv_viator_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		delete_transient( $notice_key );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Viator Tours', 'crv-viator' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="crv-api-key"><?php esc_html_e( 'API key', 'crv-viator' ); ?></label></th>
						<td>
							<?php if ( $from_const ) : ?>
								<p><?php esc_html_e( 'Set in wp-config.php (CRV_VIATOR_API_KEY).', 'crv-viator' ); ?></p>
							<?php else : ?>
								<input type="password" id="crv-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="off"
									placeholder="<?php echo $has_key ? esc_attr__( 'Saved. Leave blank to keep it.', 'crv-viator' ) : ''; ?>">
								<?php if ( $has_key ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1"> <?php esc_html_e( 'Remove saved key', 'crv-viator' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Safer: add define( \'CRV_VIATOR_API_KEY\', \'your-key\' ); to wp-config.php instead.', 'crv-viator' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Environment', 'crv-viator' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[environment]" value="production" <?php checked( $s['environment'], 'production' ); ?>> <?php esc_html_e( 'Production', 'crv-viator' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[environment]" value="sandbox" <?php checked( $s['environment'], 'sandbox' ); ?>> <?php esc_html_e( 'Sandbox (test key, no commissions)', 'crv-viator' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="crv-currency"><?php esc_html_e( 'Currency', 'crv-viator' ); ?></label></th>
						<td>
							<select id="crv-currency" name="<?php echo esc_attr( $name ); ?>[currency]">
								<?php foreach ( self::CURRENCIES as $c ) : ?>
									<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $s['currency'], $c ); ?>><?php echo esc_html( $c ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="crv-language"><?php esc_html_e( 'Language', 'crv-viator' ); ?></label></th>
						<td><input type="text" id="crv-language" name="<?php echo esc_attr( $name ); ?>[language]" value="<?php echo esc_attr( $s['language'] ); ?>" class="small-text"> <span class="description">e.g. en-US, es-ES</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="crv-count"><?php esc_html_e( 'Tours per block', 'crv-viator' ); ?></label></th>
						<td><input type="number" id="crv-count" min="1" max="24" name="<?php echo esc_attr( $name ); ?>[count]" value="<?php echo esc_attr( $s['count'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Default when the shortcode has no count.', 'crv-viator' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="crv-cache"><?php esc_html_e( 'Cache (hours)', 'crv-viator' ); ?></label></th>
						<td><input type="number" id="crv-cache" min="1" max="168" name="<?php echo esc_attr( $name ); ?>[cache_hours]" value="<?php echo esc_attr( $s['cache_hours'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'How long tour results are reused before asking Viator again.', 'crv-viator' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="crv-disclosure"><?php esc_html_e( 'Affiliate disclosure', 'crv-viator' ); ?></label></th>
						<td><input type="text" id="crv-disclosure" name="<?php echo esc_attr( $name ); ?>[disclosure]" value="<?php echo esc_attr( $s['disclosure'] ); ?>" class="large-text"> <p class="description"><?php esc_html_e( 'Shown under every tour block. Leave blank to hide.', 'crv-viator' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Tools', 'crv-viator' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="crv_viator_test">
				<?php wp_nonce_field( 'crv_viator_test' ); ?>
				<?php submit_button( __( 'Test connection', 'crv-viator' ), 'secondary', 'submit', false, $has_key ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="crv_viator_flush">
				<?php wp_nonce_field( 'crv_viator_flush' ); ?>
				<?php submit_button( __( 'Clear tour cache', 'crv-viator' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_destination_finder( $has_key ); ?>

			<hr>
			<h2><?php esc_html_e( 'Shortcode', 'crv-viator' ); ?></h2>
			<p><code>[viator_tours destination="12345"]</code> <?php esc_html_e( 'top tours in a destination', 'crv-viator' ); ?></p>
			<p><code>[viator_tours destination="12345" search="zipline" count="3" sort="rating"]</code> <?php esc_html_e( 'keyword search within a destination', 'crv-viator' ); ?></p>
			<p><code>[viator_tours destination="12345" flags="FREE_CANCELLATION" min_rating="4.5" max_price="150" title="Best tours in La Fortuna"]</code></p>
			<p class="description"><?php esc_html_e( 'Sort: featured, rating, price_low, price_high, newest. Flags: FREE_CANCELLATION, PRIVATE_TOUR, SKIP_THE_LINE, SPECIAL_OFFER, LIKELY_TO_SELL_OUT.', 'crv-viator' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Lists Viator destination IDs. With no search term it lists everything
	 * under Costa Rica, so finding "La Fortuna" or "Manuel Antonio" is one click.
	 */
	private static function render_destination_finder( $has_key ) {
		// Read-only lookup on an admin page; no state changes, so no nonce needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = isset( $_GET['crv_dest'] ) ? sanitize_text_field( wp_unslash( $_GET['crv_dest'] ) ) : null;
		?>
		<hr>
		<h2><?php esc_html_e( 'Find a destination ID', 'crv-viator' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="search" name="crv_dest" value="<?php echo esc_attr( (string) $query ); ?>" placeholder="<?php esc_attr_e( 'e.g. Fortuna, Monteverde (blank = all of Costa Rica)', 'crv-viator' ); ?>" class="regular-text">
			<?php submit_button( __( 'Search', 'crv-viator' ), 'secondary', '', false, $has_key ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php
		if ( null === $query || ! $has_key ) {
			return;
		}

		$destinations = CRV_Viator_Client::from_settings()->get_destinations();
		if ( is_wp_error( $destinations ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $destinations->get_error_message() ) . '</p></div>';
			return;
		}

		$matches = self::find_destinations( $destinations, $query );
		if ( ! $matches ) {
			echo '<p>' . esc_html__( 'No destinations found.', 'crv-viator' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:800px;margin-top:12px">
			<thead><tr><th><?php esc_html_e( 'ID', 'crv-viator' ); ?></th><th><?php esc_html_e( 'Destination', 'crv-viator' ); ?></th><th><?php esc_html_e( 'Type', 'crv-viator' ); ?></th><th><?php esc_html_e( 'Shortcode', 'crv-viator' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $matches as $id => $path ) : ?>
				<tr>
					<td><?php echo esc_html( $id ); ?></td>
					<td><?php echo esc_html( $path ); ?></td>
					<td><?php echo esc_html( strtolower( $destinations[ $id ]['type'] ) ); ?></td>
					<td><code>[viator_tours destination="<?php echo esc_html( $id ); ?>"]</code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array  $destinations From CRV_Viator_Client::get_destinations().
	 * @param string $query        Name fragment; empty lists Costa Rica and everything under it.
	 * @return array Map of ID => "Costa Rica › Alajuela › La Fortuna", sorted by path.
	 */
	public static function find_destinations( array $destinations, $query ) {
		$path = static function ( $id ) use ( $destinations ) {
			$names = array();
			for ( $i = 0; $id && isset( $destinations[ $id ] ) && $i < 10; $i++ ) {
				array_unshift( $names, $destinations[ $id ]['name'] );
				$id = $destinations[ $id ]['parent'];
			}
			return implode( ' › ', $names );
		};

		$out = array();
		foreach ( $destinations as $id => $d ) {
			$p = $path( $id );
			if ( '' === $query ) {
				$hit = preg_match( '/(^| › )Costa Rica( › |$)/', $p );
			} else {
				$hit = false !== stripos( $d['name'], $query );
			}
			if ( $hit ) {
				$out[ $id ] = $p;
			}
			if ( count( $out ) >= 200 ) {
				break;
			}
		}
		asort( $out );
		return $out;
	}
}
