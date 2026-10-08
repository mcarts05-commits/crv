<?php
/**
 * Removes the plugin's settings and cached tours when it is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'crv_viator_settings' );

global $wpdb;
foreach ( array( '_transient_crv_vt_', '_transient_timeout_crv_vt_' ) as $prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
}
