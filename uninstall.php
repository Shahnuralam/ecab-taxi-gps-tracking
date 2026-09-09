<?php
/** Remove GPS data created by this add-on. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mptbm_gps_locations" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mptbm_gps_latest' ), array( '%s' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mptbm_gps_active' ), array( '%s' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mptbm_gps_started_at' ), array( '%s' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mptbm_gps_trip_phase' ), array( '%s' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mptbm_gps_pickup_coordinates' ), array( '%s' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mptbm_gps_destination_coordinates' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_mptbm_gps_driver_available' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_mptbm_gps_driver_last_seen' ), array( '%s' ) );

foreach ( array( 'mptbm_gps_driver_page_id' => 'mptbm_gps_driver', 'mptbm_gps_tracking_page_id' => 'mptbm_gps_tracking' ) as $option => $shortcode ) {
	$page_id = absint( get_option( $option ) );
	if ( $page_id && has_shortcode( (string) get_post_field( 'post_content', $page_id ), $shortcode ) ) {
		wp_delete_post( $page_id, true );
	}
	delete_option( $option );
}
delete_option( 'mptbm_gps_settings' );
delete_option( 'mptbm_gps_db_version' );
delete_option( 'mptbm_gps_rewrite_version' );
wp_clear_scheduled_hook( 'mptbm_gps_cleanup_locations' );
