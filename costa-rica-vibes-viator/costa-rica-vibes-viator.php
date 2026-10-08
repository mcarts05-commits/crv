<?php
/**
 * Plugin Name:       Costa Rica Vibes – Viator Tours
 * Description:       Shows live, bookable Viator tours on posts and pages with the [viator_tours] shortcode. Bookings are tracked to your Viator partner account.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Costa Rica Vibes
 * License:           GPL-2.0-or-later
 * Text Domain:       crv-viator
 */

defined( 'ABSPATH' ) || exit;

define( 'CRV_VIATOR_VERSION', '0.1.0' );
define( 'CRV_VIATOR_FILE', __FILE__ );
define( 'CRV_VIATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRV_VIATOR_URL', plugin_dir_url( __FILE__ ) );

require_once CRV_VIATOR_DIR . 'includes/class-crv-viator-client.php';
require_once CRV_VIATOR_DIR . 'includes/class-crv-viator-settings.php';
require_once CRV_VIATOR_DIR . 'includes/class-crv-viator-shortcode.php';

add_action(
	'plugins_loaded',
	static function () {
		CRV_Viator_Settings::init();
		CRV_Viator_Shortcode::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'options-general.php?page=' . CRV_Viator_Settings::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'crv-viator' ) . '</a>' );
		return $links;
	}
);
