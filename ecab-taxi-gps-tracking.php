<?php
/**
 * Plugin Name: E-cab Taxi GPS Tracking
 * Plugin URI: https://mage-people.com/
 * Description: Consent-based browser and PWA live GPS tracking for E-cab Taxi Booking Manager PRO.
 * Version: 1.0.0
 * Author: MagePeople Team
 * Author URI: https://mage-people.com/
 * Text Domain: ecab-taxi-gps-tracking
 * Domain Path: /languages/
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

define( 'MPTBM_GPS_VERSION', '1.0.0' );
define( 'MPTBM_GPS_FILE', __FILE__ );
define( 'MPTBM_GPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPTBM_GPS_URL', plugin_dir_url( __FILE__ ) );

require_once MPTBM_GPS_DIR . 'includes/class-mptbm-gps-tracking.php';

register_activation_hook( __FILE__, array( 'MPTBM_GPS_Tracking', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MPTBM_GPS_Tracking', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		MPTBM_GPS_Tracking::instance();
	}
);
