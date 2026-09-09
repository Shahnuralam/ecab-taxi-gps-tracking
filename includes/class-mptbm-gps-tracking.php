<?php
/**
 * Main GPS tracking service.
 *
 * @package ECabTaxiGPSTracking
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

final class MPTBM_GPS_Tracking {
	const OPTION       = 'mptbm_gps_settings';
	const DB_VERSION   = '1.0.0';
	const DB_OPTION    = 'mptbm_gps_db_version';
	const CRON_HOOK    = 'mptbm_gps_cleanup_locations';
	const DRIVER_PAGE  = 'mptbm_gps_driver_page_id';
	const TRACK_PAGE   = 'mptbm_gps_tracking_page_id';
	const LATEST_META  = '_mptbm_gps_latest';
	const ACTIVE_META  = '_mptbm_gps_active';
	const STARTED_META = '_mptbm_gps_started_at';
	const PHASE_META   = '_mptbm_gps_trip_phase';
	const PICKUP_META  = '_mptbm_gps_pickup_coordinates';
	const DEST_META    = '_mptbm_gps_destination_coordinates';
	const DRIVER_AVAILABLE_META = '_mptbm_gps_driver_available';
	const DRIVER_LAST_SEEN_META = '_mptbm_gps_driver_last_seen';
	const REWRITE_OPTION  = 'mptbm_gps_rewrite_version';
	const REWRITE_VERSION = '2';

	/** @var self|null */
	private static $instance;

	/** @return self */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		if ( ! $this->dependencies_ready() ) {
			return;
		}

		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_action( 'template_redirect', array( $this, 'serve_pwa_resource' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'render_pwa_head' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_mptbm_gps_group', static function () { return 'manage_mptbm_transportation'; } );
		add_filter( 'mptbm_shell_menu_items', array( $this, 'add_shell_menu_item' ), 90 );
		add_filter( 'mptbm_shell_screen_ids', array( $this, 'add_shell_screen_id' ), 90 );
		add_filter( 'mp_settings_sec_reg', array( $this, 'add_global_settings_section' ), 90 );
		add_filter( 'mp_settings_sec_fields', array( $this, 'add_global_settings_fields' ), 90 );
		add_action( self::CRON_HOOK, array( $this, 'cleanup_locations' ) );

		add_shortcode( 'mptbm_gps_driver', array( $this, 'render_driver_app' ) );
		add_shortcode( 'mptbm_gps_tracking', array( $this, 'render_customer_tracker' ) );
		add_filter( 'the_content', array( $this, 'append_customer_tracking_action' ), 25 );
		add_action( 'mptbm_after_order_info', array( $this, 'render_booking_tracking_link' ) );
		add_action( 'woocommerce_account_mptbm-bookings_endpoint', array( $this, 'render_portal_tracking_link' ), 50 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_wc_order_tracking_links' ), 20 );
		add_action( 'mptbm_booking_status_updated', array( $this, 'maybe_stop_for_status' ), 10, 2 );
		add_action( 'updated_post_meta', array( $this, 'watch_driver_status_change' ), 10, 4 );
		add_action( 'mptbm_driver_reassigned', array( $this, 'stop_on_reassignment' ), 10, 1 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_driver_account_menu' ), 40 );
		add_action( 'woocommerce_account_gps-tracking_endpoint', array( $this, 'render_driver_account_endpoint' ) );
		add_action( 'woocommerce_account_driver-panel_endpoint', array( $this, 'render_driver_panel_tracking' ), 50 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'capture_cart_coordinates' ), 90, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'store_order_item_coordinates' ), 90, 4 );
		add_filter( 'add_mptbm_booking_data', array( $this, 'store_booking_coordinates' ), 90, 2 );
	}

	/** Whether both supported parent plugins are active and loaded. */
	private function dependencies_ready() {
		return defined( 'MPTBM_PLUGIN_DIR' ) && class_exists( 'MPTBM_Plugin_Pro' );
	}

	public function dependency_notice() {
		if ( $this->dependencies_ready() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'E-cab Taxi GPS Tracking requires both E-cab Taxi Booking Manager and E-cab Taxi Booking Manager PRO to be active.', 'ecab-taxi-gps-tracking' ) . '</p></div>';
	}

	/** Install the bounded location table, pages, cron event and rewrite rules. */
	public static function activate() {
		global $wpdb;
		self::register_rewrites();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'mptbm_gps_locations';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			driver_id bigint(20) unsigned NOT NULL,
			latitude decimal(10,7) NOT NULL,
			longitude decimal(10,7) NOT NULL,
			accuracy decimal(10,2) DEFAULT NULL,
			speed decimal(10,2) DEFAULT NULL,
			heading decimal(7,2) DEFAULT NULL,
			recorded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_time (booking_id, recorded_at),
			KEY recorded_at (recorded_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_OPTION, self::DB_VERSION );

		self::create_page( self::DRIVER_PAGE, 'driver-live-tracking', __( 'Driver Live Tracking', 'ecab-taxi-gps-tracking' ), '[mptbm_gps_driver]' );
		self::create_page( self::TRACK_PAGE, 'track-your-taxi', __( 'Track Your Taxi', 'ecab-taxi-gps-tracking' ), '[mptbm_gps_tracking]' );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
		flush_rewrite_rules();
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	private static function create_page( $option, $slug, $title, $content ) {
		$page_id = absint( get_option( $option ) );
		if ( $page_id && 'page' === get_post_type( $page_id ) ) {
			return;
		}
		$page = get_page_by_path( $slug );
		if ( $page instanceof WP_Post ) {
			update_option( $option, $page->ID );
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $content,
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
			)
		);
		if ( ! is_wp_error( $page_id ) ) {
			update_option( $option, $page_id );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}

	public static function register_rewrites() {
		add_rewrite_endpoint( 'gps-tracking', EP_ROOT | EP_PAGES );
		add_rewrite_rule( '^mptbm-gps-manifest\.webmanifest$', 'index.php?mptbm_gps_resource=manifest', 'top' );
		add_rewrite_rule( '^mptbm-gps-sw\.js$', 'index.php?mptbm_gps_resource=service-worker', 'top' );
		add_rewrite_tag( '%mptbm_gps_resource%', '([^&]+)' );
	}

	/** Repair rewrite rules for sites that activated an earlier addon build. */
	public function maybe_flush_rewrite_rules() {
		if ( self::REWRITE_VERSION === get_option( self::REWRITE_OPTION ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	public function serve_pwa_resource() {
		$resource = get_query_var( 'mptbm_gps_resource' );
		if ( 'manifest' === $resource ) {
			nocache_headers();
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
				echo wp_json_encode(
				array(
					'id'               => $this->driver_page_url(),
					'name'             => __( 'E-cab Driver GPS', 'ecab-taxi-gps-tracking' ),
					'short_name'       => __( 'Driver GPS', 'ecab-taxi-gps-tracking' ),
					'description'      => __( 'Share an assigned taxi driver location with dispatch and customers.', 'ecab-taxi-gps-tracking' ),
					'start_url'        => $this->driver_page_url(),
					'scope'            => wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
					'display'          => 'standalone',
					'background_color' => '#ffffff',
					'theme_color'      => '#0f766e',
					'icons'            => array(
						array( 'src' => MPTBM_GPS_URL . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
						array( 'src' => MPTBM_GPS_URL . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ),
						array( 'src' => MPTBM_GPS_URL . 'assets/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable' ),
					),
				)
			);
			exit;
		}
		if ( 'service-worker' === $resource ) {
			nocache_headers();
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: ' . wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
			$assets        = array( MPTBM_PLUGIN_URL . '/assets/leaflet/leaflet.css', MPTBM_PLUGIN_URL . '/assets/leaflet/leaflet.js', MPTBM_GPS_URL . 'assets/tracking.css', MPTBM_GPS_URL . 'assets/tracking.js', MPTBM_GPS_URL . 'assets/icon-192.png', MPTBM_GPS_URL . 'assets/icon-512.png', MPTBM_GPS_URL . 'assets/icon.svg' );
			$asset_paths   = array( MPTBM_PLUGIN_DIR . '/assets/leaflet/leaflet.css', MPTBM_PLUGIN_DIR . '/assets/leaflet/leaflet.js', MPTBM_GPS_DIR . 'assets/tracking.css', MPTBM_GPS_DIR . 'assets/tracking.js', MPTBM_GPS_DIR . 'assets/icon-192.png', MPTBM_GPS_DIR . 'assets/icon-512.png', MPTBM_GPS_DIR . 'assets/icon.svg' );
			$cache_version = max( array_map( 'filemtime', $asset_paths ) );
			echo "const CACHE='mptbm-gps-" . esc_js( MPTBM_GPS_VERSION . '-' . $cache_version ) . "';const SHELL=" . wp_json_encode( $assets ) . ";self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>c.addAll(SHELL)))});self.addEventListener('activate',e=>e.waitUntil(Promise.all([self.clients.claim(),caches.keys().then(ks=>Promise.all(ks.filter(k=>k.startsWith('mptbm-gps-')&&k!==CACHE).map(k=>caches.delete(k))))])));self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)))});self.addEventListener('notificationclick',e=>{e.notification.close();const u=e.notification.data&&e.notification.data.url;if(!u)return;e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(ws=>{for(const w of ws){if(w.url===u&&'focus'in w)return w.focus()}return clients.openWindow(u)}))})"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	}

	private function settings() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'enabled'          => 'yes',
				'high_accuracy'    => 'yes',
				'interval'         => 10,
				'min_distance'     => 10,
				'retention_hours'  => 24,
				'stale_minutes'    => 5,
				'arrival_radius'   => 100,
				'driver_availability' => 'yes',
				'customer_notifications' => 'yes',
				'auto_resume'      => 'yes',
			)
		);
	}

	public function register_settings() {
		register_setting( 'mptbm_gps_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function sanitize_settings( $input ) {
		$enabled       = isset( $input['enabled'] ) ? sanitize_key( (string) $input['enabled'] ) : 'no';
		$high_accuracy = isset( $input['high_accuracy'] ) ? sanitize_key( (string) $input['high_accuracy'] ) : 'no';
		return array(
			'enabled'         => in_array( $enabled, array( '1', 'yes', 'on' ), true ) ? 'yes' : 'no',
			'high_accuracy'   => in_array( $high_accuracy, array( '1', 'yes', 'on' ), true ) ? 'yes' : 'no',
			'interval'        => min( 120, max( 5, absint( $input['interval'] ?? 10 ) ) ),
			'min_distance'    => min( 1000, max( 0, absint( $input['min_distance'] ?? 10 ) ) ),
			'retention_hours' => min( 720, max( 1, absint( $input['retention_hours'] ?? 24 ) ) ),
			'stale_minutes'   => min( 60, max( 1, absint( $input['stale_minutes'] ?? 5 ) ) ),
			'arrival_radius'  => min( 500, max( 25, absint( $input['arrival_radius'] ?? 100 ) ) ),
			'driver_availability' => in_array( sanitize_key( (string) ( $input['driver_availability'] ?? 'no' ) ), array( '1', 'yes', 'on' ), true ) ? 'yes' : 'no',
			'customer_notifications' => in_array( sanitize_key( (string) ( $input['customer_notifications'] ?? 'no' ) ), array( '1', 'yes', 'on' ), true ) ? 'yes' : 'no',
			'auto_resume'     => in_array( sanitize_key( (string) ( $input['auto_resume'] ?? 'no' ) ), array( '1', 'yes', 'on' ), true ) ? 'yes' : 'no',
		);
	}

	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=mptbm_rent',
			__( 'Live GPS Tracking', 'ecab-taxi-gps-tracking' ),
			__( 'Live GPS', 'ecab-taxi-gps-tracking' ),
			'manage_mptbm_transportation',
			'mptbm-gps-tracking',
			array( $this, 'render_admin_page' )
		);
	}

	/** Add the monitor to the modern Transportation sidebar. */
	public function add_shell_menu_item( $items ) {
		$items   = is_array( $items ) ? $items : array();
		$items[] = array(
			'slug'  => 'mptbm-gps-tracking',
			'label' => __( 'Live GPS', 'ecab-taxi-gps-tracking' ),
			'icon'  => 'fas fa-location-arrow',
			'link'  => admin_url( 'edit.php?post_type=mptbm_rent&page=mptbm-gps-tracking' ),
		);
		return $items;
	}

	/** Ensure the shared shell assets and body classes load on the monitor. */
	public function add_shell_screen_id( $screen_ids ) {
		$screen_ids   = is_array( $screen_ids ) ? $screen_ids : array();
		$screen_ids[] = 'mptbm_rent_page_mptbm-gps-tracking';
		return array_values( array_unique( $screen_ids ) );
	}

	/** Add GPS to the modern Settings configuration-area navigation. */
	public function add_global_settings_section( $sections ) {
		$sections = is_array( $sections ) ? $sections : array();
		foreach ( $sections as $section ) {
			if ( isset( $section['id'] ) && self::OPTION === $section['id'] ) {
				return $sections;
			}
		}
		$sections[] = array(
			'id'    => self::OPTION,
			'icon'  => 'fas fa-location-arrow',
			'title' => __( 'GPS Tracking', 'ecab-taxi-gps-tracking' ),
		);
		return $sections;
	}

	/** Fields consumed by the existing MAGE_Setting_API renderer. */
	public function add_global_settings_fields( $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		if ( isset( $fields[ self::OPTION ] ) ) {
			return $fields;
		}
		$yes_no = array(
			'yes' => __( 'Yes', 'ecab-taxi-gps-tracking' ),
			'no'  => __( 'No', 'ecab-taxi-gps-tracking' ),
		);
		$fields[ self::OPTION ] = array(
			array(
				'name' => 'enabled', 'label' => __( 'Enable Live Tracking', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Allow assigned drivers to share their browser location for active bookings.', 'ecab-taxi-gps-tracking' ),
				'type' => 'select', 'default' => 'yes', 'options' => $yes_no,
			),
			array(
				'name' => 'high_accuracy', 'label' => __( 'Request High-Accuracy GPS', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Uses GPS where available. This can increase mobile battery usage.', 'ecab-taxi-gps-tracking' ),
				'type' => 'select', 'default' => 'yes', 'options' => $yes_no,
			),
			array(
				'name' => 'interval', 'label' => __( 'Update Interval', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Seconds between location uploads. Minimum 5, maximum 120.', 'ecab-taxi-gps-tracking' ),
				'type' => 'number', 'default' => 10, 'min' => 5, 'max' => 120, 'step' => 1,
			),
			array(
				'name' => 'min_distance', 'label' => __( 'Minimum Movement', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Do not store another point until the driver has moved this many metres.', 'ecab-taxi-gps-tracking' ),
				'type' => 'number', 'default' => 10, 'min' => 0, 'max' => 1000, 'step' => 1,
			),
			array(
				'name' => 'retention_hours', 'label' => __( 'Location History Retention', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Hours before stored historical coordinates are permanently deleted.', 'ecab-taxi-gps-tracking' ),
				'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 720, 'step' => 1,
			),
			array(
				'name' => 'stale_minutes', 'label' => __( 'Stale Location Threshold', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Show a warning when no fresh driver position arrives within this many minutes.', 'ecab-taxi-gps-tracking' ),
				'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 60, 'step' => 1,
			),
			array(
				'name' => 'arrival_radius', 'label' => __( 'Automatic Arrival Radius', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Metres from pickup or destination used to advance the live trip phase automatically.', 'ecab-taxi-gps-tracking' ),
				'type' => 'number', 'default' => 100, 'min' => 25, 'max' => 500, 'step' => 5,
			),
			array(
				'name' => 'driver_availability', 'label' => __( 'Driver GPS Presence', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Let drivers publish an online or offline GPS presence from the tracking panel. This does not change vehicle booking availability.', 'ecab-taxi-gps-tracking' ),
				'type' => 'select', 'default' => 'yes', 'options' => $yes_no,
			),
			array(
				'name' => 'customer_notifications', 'label' => __( 'Customer Arrival Alerts', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Let customers opt in to browser notifications when the driver reaches pickup or destination.', 'ecab-taxi-gps-tracking' ),
				'type' => 'select', 'default' => 'yes', 'options' => $yes_no,
			),
			array(
				'name' => 'auto_resume', 'label' => __( 'Resume Tracking After Reopen', 'ecab-taxi-gps-tracking' ),
				'desc' => __( 'Resume the last active booking after reopening the page or PWA when Location permission is already granted.', 'ecab-taxi-gps-tracking' ),
				'type' => 'select', 'default' => 'yes', 'options' => $yes_no,
			),
		);
		return $fields;
	}

	public function enqueue_assets() {
		global $post;
		$is_gps_page = is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'mptbm_gps_driver' ) || has_shortcode( $post->post_content, 'mptbm_gps_tracking' ) );
		if ( ! $is_gps_page && ! $this->is_driver_tracking_request() ) {
			return;
		}
		$this->enqueue_common_assets();
	}

	/**
	 * Detect both addon and PRO account endpoints throughout the request lifecycle.
	 *
	 * WooCommerce's endpoint helper can be false before the account template is
	 * rendered, while the parsed query variable is already available.
	 */
	private function is_driver_tracking_request() {
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'gps-tracking' ) || is_wc_endpoint_url( 'driver-panel' ) ) ) {
			return true;
		}

		global $wp;
		$query_vars = is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? $wp->query_vars : array();
		if ( array_key_exists( 'gps-tracking', $query_vars ) || array_key_exists( 'driver-panel', $query_vars ) ) {
			return true;
		}

		$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		return is_string( $request_path ) && (bool) preg_match( '#/(?:gps-tracking|driver-panel)/?$#', untrailingslashit( $request_path ) . '/' );
	}

	public function render_pwa_head() {
		global $post;
		$is_driver_page = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'mptbm_gps_driver' );
		if ( ! $is_driver_page && ! $this->is_driver_tracking_request() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( home_url( '/mptbm-gps-manifest.webmanifest' ) ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( MPTBM_GPS_URL . 'assets/icon-192.png' ) . '">' . "\n";
		echo '<meta name="theme-color" content="#0f766e">' . "\n";
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'mptbm_rent_page_mptbm-gps-tracking' === $hook ) {
			$this->enqueue_common_assets();
			return;
		}
		if ( 'mptbm_rent_page_mptbm_settings_page' === $hook ) {
			wp_enqueue_script( 'mptbm-gps-admin', MPTBM_GPS_URL . 'assets/admin.js', array( 'jquery' ), MPTBM_GPS_VERSION, true );
		}
	}

	private function enqueue_common_assets() {
		$settings       = $this->settings();
		$style_path     = MPTBM_GPS_DIR . 'assets/tracking.css';
		$script_path    = MPTBM_GPS_DIR . 'assets/tracking.js';
		$leaflet_css    = MPTBM_PLUGIN_DIR . '/assets/leaflet/leaflet.css';
		$leaflet_js     = MPTBM_PLUGIN_DIR . '/assets/leaflet/leaflet.js';
		$style_version  = file_exists( $style_path ) ? (string) filemtime( $style_path ) : MPTBM_GPS_VERSION;
		$script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : MPTBM_GPS_VERSION;
		wp_enqueue_style( 'mptbm-gps-leaflet', MPTBM_PLUGIN_URL . '/assets/leaflet/leaflet.css', array(), (string) filemtime( $leaflet_css ) );
		wp_enqueue_style( 'mptbm-gps', MPTBM_GPS_URL . 'assets/tracking.css', array(), $style_version );
		wp_enqueue_script( 'mptbm-gps-leaflet', MPTBM_PLUGIN_URL . '/assets/leaflet/leaflet.js', array(), (string) filemtime( $leaflet_js ), true );
		wp_enqueue_script( 'mptbm-gps', MPTBM_GPS_URL . 'assets/tracking.js', array( 'mptbm-gps-leaflet' ), $script_version, true );
		wp_localize_script(
			'mptbm-gps',
			'MPTBMGPS',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'mptbm-gps/v1/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'interval'      => absint( $settings['interval'] ) * 1000,
				'highAccuracy'  => 'yes' === $settings['high_accuracy'],
				'staleMinutes'  => absint( $settings['stale_minutes'] ),
				'userId'        => get_current_user_id(),
				'autoResume'    => 'yes' === $settings['auto_resume'],
				'customerNotifications' => 'yes' === $settings['customer_notifications'],
				'serviceWorker'      => home_url( '/mptbm-gps-sw.js' ),
				'serviceWorkerScope' => wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
				'manifest'           => home_url( '/mptbm-gps-manifest.webmanifest' ),
				'icon'               => MPTBM_GPS_URL . 'assets/icon-192.png',
				'i18n'          => array(
					'permission' => __( 'Allow location access in your browser to start tracking.', 'ecab-taxi-gps-tracking' ),
					'blocked'    => __( 'Location is blocked. Open browser site settings, allow Location, then retry.', 'ecab-taxi-gps-tracking' ),
					'insecure'   => __( 'GPS requires HTTPS (or localhost).', 'ecab-taxi-gps-tracking' ),
					'error'      => __( 'Your location could not be updated.', 'ecab-taxi-gps-tracking' ),
					'noBookings' => __( 'No assigned bookings found. Create a booking and assign its driver or vehicle before starting GPS.', 'ecab-taxi-gps-tracking' ),
					'pwaReady'   => __( 'PWA install support is ready.', 'ecab-taxi-gps-tracking' ),
					'pwaFailed'  => __( 'PWA setup failed. Reload after confirming HTTPS and pretty permalinks.', 'ecab-taxi-gps-tracking' ),
					'installHelp' => __( 'Use your browser menu and choose Install app or Add to Home screen.', 'ecab-taxi-gps-tracking' ),
				),
			)
		);
	}

	public function register_rest_routes() {
		register_rest_route( 'mptbm-gps/v1', '/driver/bookings', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_driver_bookings' ), 'permission_callback' => array( $this, 'is_driver_or_manager' ) ) );
		register_rest_route(
			'mptbm-gps/v1',
			'/driver/status',
			array(
				array( 'methods' => 'GET', 'callback' => array( $this, 'rest_driver_status' ), 'permission_callback' => array( $this, 'can_update_driver_status' ) ),
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'rest_driver_status' ), 'permission_callback' => array( $this, 'can_update_driver_status' ) ),
			)
		);
		register_rest_route(
			'mptbm-gps/v1',
			'/location/(?P<booking_id>\d+)',
			array(
				array( 'methods' => 'POST', 'callback' => array( $this, 'rest_save_location' ), 'permission_callback' => array( $this, 'can_update_booking' ) ),
				array( 'methods' => 'DELETE', 'callback' => array( $this, 'rest_stop_tracking' ), 'permission_callback' => array( $this, 'can_update_booking' ) ),
				array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get_location' ), 'permission_callback' => '__return_true' ),
			)
		);
	}

	public function is_driver_or_manager() {
		$user = wp_get_current_user();
		return current_user_can( 'manage_mptbm_transportation' ) || in_array( 'mptbm_driver_role', (array) $user->roles, true );
	}

	/** Require an authenticated driver/manager and a valid REST nonce. */
	public function can_update_driver_status( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'mptbm_gps_unauthorized', __( 'Authentication failed.', 'ecab-taxi-gps-tracking' ), array( 'status' => 401 ) );
		}
		return $this->is_driver_or_manager() ? true : new WP_Error( 'mptbm_gps_forbidden', __( 'This action is available only to drivers.', 'ecab-taxi-gps-tracking' ), array( 'status' => 403 ) );
	}

	/** Read or update the current driver's dispatch availability. */
	public function rest_driver_status( WP_REST_Request $request ) {
		if ( 'yes' !== $this->settings()['driver_availability'] ) {
			return new WP_Error( 'mptbm_gps_availability_disabled', __( 'Driver availability is disabled by the administrator.', 'ecab-taxi-gps-tracking' ), array( 'status' => 403 ) );
		}
		$user_id = get_current_user_id();
		if ( 'GET' !== $request->get_method() ) {
			$available = rest_sanitize_boolean( $request->get_param( 'available' ) );
			update_user_meta( $user_id, self::DRIVER_AVAILABLE_META, $available ? 'yes' : 'no' );
			update_user_meta( $user_id, self::DRIVER_LAST_SEEN_META, current_time( 'mysql', true ) );
			do_action( 'mptbm_gps_driver_availability_changed', $user_id, $available );
		}
		return rest_ensure_response( $this->driver_status( $user_id ) );
	}

	private function driver_status( $user_id ) {
		$last_seen = (string) get_user_meta( $user_id, self::DRIVER_LAST_SEEN_META, true );
		return array(
			'available' => 'yes' === get_user_meta( $user_id, self::DRIVER_AVAILABLE_META, true ),
			'lastSeen'  => $last_seen ? mysql2date( DATE_ATOM, $last_seen . ' +0000', false ) : null,
		);
	}

	public function can_update_booking( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'mptbm_gps_unauthorized', __( 'Authentication failed.', 'ecab-taxi-gps-tracking' ), array( 'status' => 401 ) );
		}
		$booking_id = absint( $request['booking_id'] );
		if ( 'mptbm_booking' !== get_post_type( $booking_id ) ) {
			return new WP_Error( 'mptbm_gps_invalid_booking', __( 'Invalid booking.', 'ecab-taxi-gps-tracking' ), array( 'status' => 404 ) );
		}
		if ( current_user_can( 'manage_mptbm_transportation' ) ) {
			return true;
		}
		return $this->driver_owns_booking( get_current_user_id(), $booking_id ) ? true : new WP_Error( 'mptbm_gps_forbidden', __( 'You are not assigned to this booking.', 'ecab-taxi-gps-tracking' ), array( 'status' => 403 ) );
	}

	private function driver_owns_booking( $driver_id, $booking_id ) {
		return $this->booking_driver_id( $booking_id ) === absint( $driver_id );
	}

	private function booking_driver_id( $booking_id ) {
		$direct = absint( get_post_meta( $booking_id, 'mptbm_driver_id', true ) ?: get_post_meta( $booking_id, 'mptbm_selected_driver', true ) );
		if ( $direct ) {
			return $direct;
		}
		$vehicle_id = absint( get_post_meta( $booking_id, 'mptbm_id', true ) );
		return $vehicle_id ? absint( get_post_meta( $vehicle_id, 'mptbm_selected_driver', true ) ) : 0;
	}

	/** Preserve the verified search coordinates through WooCommerce checkout. */
	public function capture_cart_coordinates( $cart_item_data, $product_id, $variation_id ) {
		unset( $variation_id );
		$vehicle_id = absint( $cart_item_data['mptbm_id'] ?? get_post_meta( $product_id, 'link_mptbm_id', true ) );
		if ( ! $vehicle_id || 'mptbm_rent' !== get_post_type( $vehicle_id ) ) {
			return $cart_item_data;
		}
		$context = class_exists( 'MPTBM_Function' ) ? MPTBM_Function::get_search_context() : array();
		$pickup  = $this->normalize_coordinates( $context['start_coords'] ?? array() );
		$dest    = $this->normalize_coordinates( $context['end_coords'] ?? array() );
		if ( $pickup ) {
			$cart_item_data['mptbm_gps_pickup_coordinates'] = $pickup;
		}
		if ( $dest ) {
			$cart_item_data['mptbm_gps_destination_coordinates'] = $dest;
		}
		return $cart_item_data;
	}

	/** Copy trip coordinates to the order item for delayed booking creation. */
	public function store_order_item_coordinates( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}
		foreach ( array( 'pickup', 'destination' ) as $point ) {
			$key = 'mptbm_gps_' . $point . '_coordinates';
			if ( ! empty( $values[ $key ] ) ) {
				$item->add_meta_data( '_' . $key, $this->normalize_coordinates( $values[ $key ] ), true );
			}
		}
	}

	/** Add dynamic pickup/destination coordinates to every booking engine. */
	public function store_booking_coordinates( $data, $vehicle_id ) {
		unset( $vehicle_id );
		$order_item_id = absint( $data['mptbm_order_item_id'] ?? 0 );
		$context       = class_exists( 'MPTBM_Function' ) ? MPTBM_Function::get_search_context() : array();
		$sources       = array(
			self::PICKUP_META => $order_item_id && function_exists( 'wc_get_order_item_meta' ) ? wc_get_order_item_meta( $order_item_id, '_mptbm_gps_pickup_coordinates', true ) : ( $context['start_coords'] ?? array() ),
			self::DEST_META   => $order_item_id && function_exists( 'wc_get_order_item_meta' ) ? wc_get_order_item_meta( $order_item_id, '_mptbm_gps_destination_coordinates', true ) : ( $context['end_coords'] ?? array() ),
		);
		foreach ( $sources as $key => $coordinates ) {
			$coordinates = $this->normalize_coordinates( $coordinates );
			if ( $coordinates ) {
				$data[ $key ] = $coordinates;
			}
		}
		return $data;
	}

	/** Normalize arrays, JSON, or the location taxonomy's "lat,lng" value. */
	private function normalize_coordinates( $coordinates ) {
		if ( is_string( $coordinates ) ) {
			$decoded = json_decode( wp_unslash( $coordinates ), true );
			if ( is_array( $decoded ) ) {
				$coordinates = $decoded;
			} elseif ( false !== strpos( $coordinates, ',' ) ) {
				$parts       = array_map( 'trim', explode( ',', $coordinates ) );
				$coordinates = array( 'latitude' => $parts[0] ?? null, 'longitude' => $parts[1] ?? null );
			}
		}
		if ( ! is_array( $coordinates ) ) {
			return array();
		}
		$lat = $coordinates['latitude'] ?? ( $coordinates['lat'] ?? null );
		$lng = $coordinates['longitude'] ?? ( $coordinates['lng'] ?? ( $coordinates['lon'] ?? null ) );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180 ) {
			return array();
		}
		return array( 'latitude' => round( (float) $lat, 7 ), 'longitude' => round( (float) $lng, 7 ) );
	}

	private function booking_point( $booking_id, $meta_key, $place_key ) {
		$coordinates = $this->normalize_coordinates( get_post_meta( $booking_id, $meta_key, true ) );
		$label       = (string) get_post_meta( $booking_id, $place_key, true );
		if ( ! $coordinates && $label ) {
			$term = get_term_by( 'name', $label, 'locations' );
			if ( $term instanceof WP_Term ) {
				$coordinates = $this->normalize_coordinates( get_term_meta( $term->term_id, 'mptbm_geo_location', true ) );
			}
		}
		return $coordinates ? array( 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude'], 'label' => $label ) : null;
	}

	private function trip_context( $booking_id ) {
		$pickup = $this->booking_point( $booking_id, self::PICKUP_META, 'mptbm_start_place' );
		$dest   = $this->booking_point( $booking_id, self::DEST_META, 'mptbm_end_place' );
		$phase  = sanitize_key( (string) get_post_meta( $booking_id, self::PHASE_META, true ) );
		if ( ! $phase ) {
			$phase = $pickup ? 'to_pickup' : 'to_destination';
		}
		return array( 'pickup' => $pickup, 'destination' => $dest, 'phase' => $phase );
	}

	private function update_trip_phase( $booking_id, $latitude, $longitude, $accuracy ) {
		$trip   = $this->trip_context( $booking_id );
		$radius = max( absint( $this->settings()['arrival_radius'] ), min( 500, (int) ceil( (float) $accuracy * 2 ) ) );
		if ( 'to_pickup' === $trip['phase'] && $trip['pickup'] && $this->distance_metres( $latitude, $longitude, $trip['pickup']['latitude'], $trip['pickup']['longitude'] ) <= $radius ) {
			$trip['phase'] = 'to_destination';
			update_post_meta( $booking_id, self::PHASE_META, $trip['phase'] );
			do_action( 'mptbm_gps_trip_phase_changed', $booking_id, $trip['phase'] );
		} elseif ( 'to_destination' === $trip['phase'] && $trip['destination'] && $this->distance_metres( $latitude, $longitude, $trip['destination']['latitude'], $trip['destination']['longitude'] ) <= $radius ) {
			$trip['phase'] = 'arrived_destination';
			update_post_meta( $booking_id, self::PHASE_META, $trip['phase'] );
			do_action( 'mptbm_gps_trip_phase_changed', $booking_id, $trip['phase'] );
		}
		return $trip;
	}

	public function rest_driver_bookings() {
		$args = array( 'post_type' => 'mptbm_booking', 'post_status' => array( 'publish', 'pending', 'draft' ), 'posts_per_page' => 100, 'orderby' => 'meta_value', 'meta_key' => 'mptbm_date', 'order' => 'DESC' );
		if ( ! current_user_can( 'manage_mptbm_transportation' ) ) {
			$args['meta_query'] = class_exists( 'MPTBM_Driver' ) ? MPTBM_Driver::driver_bookings_meta_query( get_current_user_id() ) : array( array( 'key' => 'mptbm_selected_driver', 'value' => get_current_user_id() ) );
		}
		$bookings = array();
		foreach ( get_posts( $args ) as $booking ) {
			$trip = $this->trip_context( $booking->ID );
			$driver_status = $this->driver_status( $this->booking_driver_id( $booking->ID ) );
			$item = array(
				'id'       => $booking->ID,
				'reference'=> (string) get_post_meta( $booking->ID, 'mptbm_pin', true ),
				'date'     => (string) get_post_meta( $booking->ID, 'mptbm_date', true ),
				'pickup'   => (string) get_post_meta( $booking->ID, 'mptbm_start_place', true ),
				'dropoff'  => (string) get_post_meta( $booking->ID, 'mptbm_end_place', true ),
				'active'   => 'yes' === get_post_meta( $booking->ID, self::ACTIVE_META, true ),
				'phase'    => $trip['phase'],
				'pickupPoint' => $trip['pickup'],
				'destinationPoint' => $trip['destination'],
				'driverAvailable' => $driver_status['available'],
				'driverLastSeen'  => $driver_status['lastSeen'],
			);
			if ( current_user_can( 'manage_mptbm_transportation' ) ) {
				$item['trackingUrl'] = $this->customer_tracking_url( $booking->ID );
			}
			$bookings[] = $item;
		}
		return rest_ensure_response( $bookings );
	}

	public function rest_save_location( WP_REST_Request $request ) {
		$settings = $this->settings();
		if ( 'yes' !== $settings['enabled'] ) {
			return new WP_Error( 'mptbm_gps_disabled', __( 'Live tracking is disabled by the administrator.', 'ecab-taxi-gps-tracking' ), array( 'status' => 403 ) );
		}

		$booking_id = absint( $request['booking_id'] );
		$status     = sanitize_key( (string) get_post_meta( $booking_id, 'mptbm_service_status', true ) );
		$terminal   = array_map( 'sanitize_key', (array) apply_filters( 'mptbm_gps_stop_statuses', array( 'completed', 'cancelled', 'canceled', 'refunded' ) ) );
		if ( $status && in_array( $status, $terminal, true ) ) {
			return new WP_Error( 'mptbm_gps_trip_finished', __( 'Tracking cannot start for a completed or cancelled trip.', 'ecab-taxi-gps-tracking' ), array( 'status' => 409 ) );
		}
		$allowed    = apply_filters( 'mptbm_gps_can_driver_track', true, get_current_user_id(), $booking_id, $request );
		if ( ! $allowed ) {
			return new WP_Error( 'mptbm_gps_trip_not_trackable', __( 'Tracking is not allowed for this trip.', 'ecab-taxi-gps-tracking' ), array( 'status' => 403 ) );
		}
		$latitude   = filter_var( $request->get_param( 'latitude' ), FILTER_VALIDATE_FLOAT );
		$longitude  = filter_var( $request->get_param( 'longitude' ), FILTER_VALIDATE_FLOAT );
		if ( false === $latitude || false === $longitude || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			return new WP_Error( 'mptbm_gps_invalid_coordinates', __( 'Invalid coordinates.', 'ecab-taxi-gps-tracking' ), array( 'status' => 400 ) );
		}

		$latest = get_post_meta( $booking_id, self::LATEST_META, true );
		if ( is_array( $latest ) && ! empty( $latest['recorded_at_gmt'] ) ) {
			$elapsed = time() - strtotime( $latest['recorded_at_gmt'] . ' UTC' );
			if ( $elapsed < max( 2, absint( $settings['interval'] ) - 2 ) ) {
				return new WP_Error( 'mptbm_gps_rate_limited', __( 'Location updates are arriving too quickly.', 'ecab-taxi-gps-tracking' ), array( 'status' => 429 ) );
			}
			if ( $this->distance_metres( $latest['latitude'], $latest['longitude'], $latitude, $longitude ) < absint( $settings['min_distance'] ) ) {
				$trip = $this->update_trip_phase( $booking_id, $latitude, $longitude, $this->nullable_number( $request->get_param( 'accuracy' ), 0, 100000 ) );
				update_user_meta( get_current_user_id(), self::DRIVER_LAST_SEEN_META, current_time( 'mysql', true ) );
				return rest_ensure_response( array( 'saved' => false, 'reason' => 'minimum_distance', 'phase' => $trip['phase'] ) );
			}
		}

		$payload = array(
			'booking_id'      => $booking_id,
			'driver_id'       => get_current_user_id(),
			'latitude'        => round( (float) $latitude, 7 ),
			'longitude'       => round( (float) $longitude, 7 ),
			'accuracy'        => $this->nullable_number( $request->get_param( 'accuracy' ), 0, 100000 ),
			'speed'           => $this->nullable_number( $request->get_param( 'speed' ), 0, 500 ),
			'heading'         => $this->nullable_number( $request->get_param( 'heading' ), 0, 360 ),
			'recorded_at_gmt' => current_time( 'mysql', true ),
		);
		$payload = apply_filters( 'mptbm_gps_location_payload', $payload, $request );
		$trip    = $this->update_trip_phase( $booking_id, $payload['latitude'], $payload['longitude'], $payload['accuracy'] );

		global $wpdb;
		$saved = $wpdb->insert(
			$wpdb->prefix . 'mptbm_gps_locations',
			array(
				'booking_id' => $payload['booking_id'], 'driver_id' => $payload['driver_id'],
				'latitude' => $payload['latitude'], 'longitude' => $payload['longitude'],
				'accuracy' => $payload['accuracy'], 'speed' => $payload['speed'], 'heading' => $payload['heading'],
				'recorded_at' => $payload['recorded_at_gmt'],
			),
			array( '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s' )
		);
		if ( false === $saved ) {
			return new WP_Error( 'mptbm_gps_storage_error', __( 'The location could not be saved.', 'ecab-taxi-gps-tracking' ), array( 'status' => 500 ) );
		}
		update_post_meta( $booking_id, self::LATEST_META, $payload );
		update_post_meta( $booking_id, self::ACTIVE_META, 'yes' );
		update_user_meta( get_current_user_id(), self::DRIVER_LAST_SEEN_META, $payload['recorded_at_gmt'] );
		if ( ! get_post_meta( $booking_id, self::STARTED_META, true ) ) {
			update_post_meta( $booking_id, self::STARTED_META, current_time( 'mysql', true ) );
			do_action( 'mptbm_gps_tracking_started', $booking_id, get_current_user_id() );
		}
		do_action( 'mptbm_gps_location_recorded', $booking_id, $payload );
		return rest_ensure_response( array( 'saved' => true, 'phase' => $trip['phase'], 'recordedAt' => mysql2date( DATE_ATOM, $payload['recorded_at_gmt'] . ' +0000', false ) ) );
	}

	private function nullable_number( $value, $min, $max ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return min( $max, max( $min, (float) $value ) );
	}

	private function distance_metres( $lat1, $lon1, $lat2, $lon2 ) {
		$earth = 6371000;
		$dlat  = deg2rad( $lat2 - $lat1 );
		$dlon  = deg2rad( $lon2 - $lon1 );
		$a     = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlon / 2 ) ** 2;
		return $earth * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	public function rest_stop_tracking( WP_REST_Request $request ) {
		$booking_id = absint( $request['booking_id'] );
		delete_post_meta( $booking_id, self::ACTIVE_META );
		delete_post_meta( $booking_id, self::STARTED_META );
		do_action( 'mptbm_gps_tracking_stopped', $booking_id, get_current_user_id() );
		return rest_ensure_response( array( 'stopped' => true ) );
	}

	public function rest_get_location( WP_REST_Request $request ) {
		$booking_id = absint( $request['booking_id'] );
		$token      = sanitize_text_field( (string) ( $request->get_header( 'X-MPTBM-GPS-Token' ) ?: $request->get_param( 'token' ) ) );
		if ( 'mptbm_booking' !== get_post_type( $booking_id ) || ! $this->customer_can_view( $booking_id, $token ) ) {
			return new WP_Error( 'mptbm_gps_forbidden', __( 'This tracking link is invalid or expired.', 'ecab-taxi-gps-tracking' ), array( 'status' => 403 ) );
		}
		$latest = get_post_meta( $booking_id, self::LATEST_META, true );
		$trip   = $this->trip_context( $booking_id );
		$assigned_driver_id = $this->booking_driver_id( $booking_id );
		$assigned_status    = $this->driver_status( $assigned_driver_id );
		if ( ! is_array( $latest ) || ! array_key_exists( 'latitude', $latest ) || ! array_key_exists( 'longitude', $latest ) ) {
			return rest_ensure_response( array( 'available' => false, 'active' => false, 'trip' => $trip, 'driverAvailable' => $assigned_status['available'], 'driverLastSeen' => $assigned_status['lastSeen'] ) );
		}
		$driver_id     = absint( $latest['driver_id'] ?? 0 ) ?: $assigned_driver_id;
		$driver        = get_userdata( $driver_id );
		$driver_status = $this->driver_status( $driver_id );
		$metrics = $this->route_metrics( $booking_id, $latest, $trip );
		return rest_ensure_response(
			array(
				'available'  => true,
				'active'     => 'yes' === get_post_meta( $booking_id, self::ACTIVE_META, true ),
				'latitude'   => (float) $latest['latitude'],
				'longitude'  => (float) $latest['longitude'],
				'accuracy'   => isset( $latest['accuracy'] ) ? (float) $latest['accuracy'] : null,
				'speed'      => isset( $latest['speed'] ) ? (float) $latest['speed'] : null,
				'heading'    => isset( $latest['heading'] ) ? (float) $latest['heading'] : null,
				'recordedAt' => mysql2date( DATE_ATOM, $latest['recorded_at_gmt'] . ' +0000', false ),
				'driver'     => $driver ? $driver->display_name : '',
				'driverAvailable' => $driver_status['available'],
				'driverLastSeen'  => $driver_status['lastSeen'],
				'reference'  => (string) get_post_meta( $booking_id, 'mptbm_pin', true ),
				'trip'       => $trip,
				'trail'      => $this->location_trail( $booking_id ),
				'metrics'    => $metrics,
			)
		);
	}

	private function route_metrics( $booking_id, $latest, $trip ) {
		if ( 'arrived_destination' === $trip['phase'] ) {
			return array( 'remainingDistanceMetres' => 0, 'etaSeconds' => 0, 'target' => 'destination' );
		}
		$target_name = 'to_pickup' === $trip['phase'] ? 'pickup' : 'destination';
		$target      = $trip[ $target_name ] ?? null;
		if ( ! $target ) {
			return null;
		}
		$cache_key = 'mptbm_gps_route_' . md5( $booking_id . '|' . $trip['phase'] . '|' . round( (float) $latest['latitude'], 3 ) . '|' . round( (float) $latest['longitude'], 3 ) );
		$route     = get_transient( $cache_key );
		if ( false === $route ) {
			$route = class_exists( 'MPTBM_Function' ) ? MPTBM_Function::get_server_distance( $latest['latitude'], $latest['longitude'], $target['latitude'], $target['longitude'] ) : null;
			if ( ! is_array( $route ) ) {
				$route = array( 'distance' => $this->distance_metres( $latest['latitude'], $latest['longitude'], $target['latitude'], $target['longitude'] ) );
			}
			set_transient( $cache_key, $route, MINUTE_IN_SECONDS );
		}
		$distance = max( 0, (float) ( $route['distance'] ?? 0 ) );
		$duration = isset( $route['duration'] ) ? max( 0, (int) $route['duration'] ) : null;
		if ( null === $duration && ! empty( $latest['speed'] ) && (float) $latest['speed'] > 1 ) {
			$duration = (int) round( $distance / (float) $latest['speed'] );
		}
		return array( 'remainingDistanceMetres' => round( $distance ), 'etaSeconds' => $duration, 'target' => $target_name );
	}

	private function location_trail( $booking_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT latitude, longitude, recorded_at FROM {$wpdb->prefix}mptbm_gps_locations WHERE booking_id = %d ORDER BY id DESC LIMIT 30", $booking_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$rows = array_reverse( $rows );
		return array_map(
			static function ( $row ) {
				return array( 'latitude' => (float) $row['latitude'], 'longitude' => (float) $row['longitude'], 'recordedAt' => mysql2date( DATE_ATOM, $row['recorded_at'] . ' +0000', false ) );
			},
			$rows
		);
	}

	private function customer_can_view( $booking_id, $token ) {
		if ( current_user_can( 'manage_mptbm_transportation' ) ) {
			return true;
		}
		$owner = absint( get_post_meta( $booking_id, 'mptbm_user_id', true ) );
		if ( $owner && get_current_user_id() === $owner ) {
			return true;
		}
		return $token && class_exists( 'MPTBM_Function' ) && method_exists( 'MPTBM_Function', 'verify_booking_access_token' ) && MPTBM_Function::verify_booking_access_token( $booking_id, $token );
	}

	private function customer_tracking_url( $booking_id ) {
		$token = class_exists( 'MPTBM_Function' ) && method_exists( 'MPTBM_Function', 'get_booking_access_token' ) ? MPTBM_Function::get_booking_access_token( $booking_id, true ) : '';
		return add_query_arg( array( 'booking_id' => absint( $booking_id ), 'token' => rawurlencode( $token ) ), get_permalink( absint( get_option( self::TRACK_PAGE ) ) ) );
	}

	private function driver_page_url() {
		$url = get_permalink( absint( get_option( self::DRIVER_PAGE ) ) );
		return $url ? $url : home_url( '/driver-live-tracking/' );
	}

	public function render_driver_app( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please sign in with a driver account to share location.', 'ecab-taxi-gps-tracking' ) . '</p>';
		}
		if ( ! $this->is_driver_or_manager() ) {
			return '<p>' . esc_html__( 'This page is available only to assigned drivers.', 'ecab-taxi-gps-tracking' ) . '</p>';
		}
		$settings = $this->settings();
		if ( 'yes' !== $settings['enabled'] ) {
			return '<p>' . esc_html__( 'Live tracking is currently disabled by the administrator.', 'ecab-taxi-gps-tracking' ) . '</p>';
		}
		$atts     = shortcode_atts( array( 'embedded' => '' ), (array) $atts, 'mptbm_gps_driver' );
		$embedded = 'driver-panel' === sanitize_key( $atts['embedded'] );
		ob_start();
		?>
		<div class="mptbm-gps-app is-driver-panel<?php echo $embedded ? ' is-embedded' : ''; ?>" data-mode="driver">
			<header class="mptbm-gps-driver-head">
				<span class="mptbm-gps-driver-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5Z"/></svg></span>
				<span class="mptbm-gps-driver-copy"><span class="mptbm-gps-kicker"><?php esc_html_e( 'Driver PWA', 'ecab-taxi-gps-tracking' ); ?></span><h2><?php esc_html_e( 'Live GPS Tracking', 'ecab-taxi-gps-tracking' ); ?></h2><p><?php esc_html_e( 'Choose your assigned trip, then start sharing so dispatch and your customer can follow the journey.', 'ecab-taxi-gps-tracking' ); ?></p></span>
				<span class="mptbm-gps-browser-badge"><?php esc_html_e( 'Browser based', 'ecab-taxi-gps-tracking' ); ?></span>
			</header>
			<div class="mptbm-gps-driver-body">
				<?php if ( 'yes' === $settings['driver_availability'] ) : ?>
					<div class="mptbm-gps-availability" data-gps-availability-panel>
						<span class="mptbm-gps-availability-copy"><i data-gps-availability-dot></i><span><strong data-gps-availability-label><?php esc_html_e( 'Checking GPS availability…', 'ecab-taxi-gps-tracking' ); ?></strong><small><?php esc_html_e( 'Go online when you are ready to share live trip status with dispatch.', 'ecab-taxi-gps-tracking' ); ?></small></span></span>
						<label class="mptbm-gps-switch"><span class="screen-reader-text"><?php esc_html_e( 'Driver online availability', 'ecab-taxi-gps-tracking' ); ?></span><input type="checkbox" data-gps-availability disabled><span aria-hidden="true"></span></label>
					</div>
				<?php endif; ?>
				<div class="mptbm-gps-booking-field"><label for="mptbm-gps-booking"><?php esc_html_e( 'Assigned booking', 'ecab-taxi-gps-tracking' ); ?></label><select id="mptbm-gps-booking"><option value=""><?php esc_html_e( 'Loading bookings…', 'ecab-taxi-gps-tracking' ); ?></option></select></div>
				<div class="mptbm-gps-actions"><button type="button" class="button button-primary" data-gps-start disabled><?php esc_html_e( 'Start sharing location', 'ecab-taxi-gps-tracking' ); ?></button><button type="button" class="button" data-gps-stop disabled><?php esc_html_e( 'Stop', 'ecab-taxi-gps-tracking' ); ?></button><button type="button" class="button" data-gps-install><?php esc_html_e( 'Install app', 'ecab-taxi-gps-tracking' ); ?></button></div>
				<p class="mptbm-gps-pwa-status" data-gps-pwa-status role="status" aria-live="polite"><?php esc_html_e( 'Checking PWA support…', 'ecab-taxi-gps-tracking' ); ?></p>
				<div class="mptbm-gps-device-health" aria-label="<?php esc_attr_e( 'Device tracking status', 'ecab-taxi-gps-tracking' ); ?>"><span data-gps-network><i></i><?php esc_html_e( 'Network: checking', 'ecab-taxi-gps-tracking' ); ?></span><span data-gps-signal><i></i><?php esc_html_e( 'GPS: waiting', 'ecab-taxi-gps-tracking' ); ?></span><span data-gps-battery><i></i><?php esc_html_e( 'Battery: checking', 'ecab-taxi-gps-tracking' ); ?></span></div>
				<div class="mptbm-gps-status" data-gps-status role="status" aria-live="polite"><?php esc_html_e( 'Location sharing is off.', 'ecab-taxi-gps-tracking' ); ?></div>
				<div class="mptbm-gps-map" data-gps-map><div class="mptbm-gps-map-empty"><span class="mptbm-gps-map-pin" aria-hidden="true"></span><strong><?php esc_html_e( 'Ready for your next trip', 'ecab-taxi-gps-tracking' ); ?></strong><span><?php esc_html_e( 'Your live position and route will appear here after tracking starts.', 'ecab-taxi-gps-tracking' ); ?></span></div></div>
				<p class="mptbm-gps-privacy"><span aria-hidden="true">&#128274;</span><?php printf( esc_html__( 'Location is sent every %d seconds while this page is open. Stop tracking when the ride ends.', 'ecab-taxi-gps-tracking' ), absint( $settings['interval'] ) ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_customer_tracker( $atts ) {
		$atts       = shortcode_atts( array( 'booking_id' => 0, 'token' => '' ), $atts, 'mptbm_gps_tracking' );
		$booking_id = absint( $atts['booking_id'] ?: ( $_GET['booking_id'] ?? 0 ) );
		$token      = sanitize_text_field( wp_unslash( $atts['token'] ?: ( $_GET['token'] ?? '' ) ) );
		if ( ! $booking_id || ! $this->customer_can_view( $booking_id, $token ) ) {
			return '<p>' . esc_html__( 'This tracking link is invalid or expired.', 'ecab-taxi-gps-tracking' ) . '</p>';
		}
		$settings = $this->settings();
		ob_start();
		?>
		<div class="mptbm-gps-app" data-mode="viewer" data-booking-id="<?php echo esc_attr( $booking_id ); ?>" data-token="<?php echo esc_attr( $token ); ?>">
			<header><span class="mptbm-gps-kicker"><?php esc_html_e( 'Live trip', 'ecab-taxi-gps-tracking' ); ?></span><h2><?php esc_html_e( 'Track your taxi', 'ecab-taxi-gps-tracking' ); ?></h2><p><?php echo esc_html( sprintf( __( 'Booking #%s', 'ecab-taxi-gps-tracking' ), get_post_meta( $booking_id, 'mptbm_pin', true ) ?: $booking_id ) ); ?></p></header>
			<?php if ( 'yes' === $settings['customer_notifications'] ) : ?><div class="mptbm-gps-notification-row"><div><strong><?php esc_html_e( 'Arrival alerts', 'ecab-taxi-gps-tracking' ); ?></strong><span><?php esc_html_e( 'Get a browser alert at pickup and destination.', 'ecab-taxi-gps-tracking' ); ?></span></div><button type="button" class="button" data-gps-notifications><?php esc_html_e( 'Enable alerts', 'ecab-taxi-gps-tracking' ); ?></button></div><?php endif; ?>
			<div class="mptbm-gps-status" data-gps-status role="status" aria-live="polite"><?php esc_html_e( 'Waiting for the driver’s location…', 'ecab-taxi-gps-tracking' ); ?></div>
			<div class="mptbm-gps-map" data-gps-map><p><?php esc_html_e( 'The map appears when the driver starts sharing.', 'ecab-taxi-gps-tracking' ); ?></p></div>
			<div class="mptbm-gps-details" data-gps-details></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_booking_tracking_link( $booking_id ) {
		$booking_id = absint( $booking_id );
		if ( ! $booking_id || 'mptbm_booking' !== get_post_type( $booking_id ) ) {
			return;
		}
		echo '<p class="mptbm-gps-booking-link"><a class="button" href="' . esc_url( $this->customer_tracking_url( $booking_id ) ) . '">' . esc_html__( 'Track taxi live', 'ecab-taxi-gps-tracking' ) . '</a></p>';
	}

	/** Add the tracking action to shortcode-based customer detail/confirmation pages. */
	public function append_customer_tracking_action( $content ) {
		global $post;
		if ( is_admin() || ! is_singular() || ! is_a( $post, 'WP_Post' ) || has_shortcode( $post->post_content, 'mptbm_gps_tracking' ) ) {
			return $content;
		}
		$booking_id = absint( $_GET['mptbm_view'] ?? ( $_GET['mptbm_booking_id'] ?? 0 ) );
		$token      = sanitize_text_field( wp_unslash( $_GET['mptbm_token'] ?? ( $_GET['token'] ?? '' ) ) );
		if ( $booking_id && $this->customer_can_view( $booking_id, $token ) ) {
			return $content . $this->tracking_button_html( $booking_id );
		}
		return $content;
	}

	/** Tracking action below the PRO My Bookings detail endpoint. */
	public function render_portal_tracking_link() {
		$booking_id = absint( $_GET['mptbm_view'] ?? 0 );
		if ( $booking_id && $this->customer_can_view( $booking_id, '' ) ) {
			echo $this->tracking_button_html( $booking_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Tracking actions below an authorized WooCommerce order detail. */
	public function render_wc_order_tracking_links( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		$booking_ids = get_posts(
			array(
				'post_type' => 'mptbm_booking', 'post_status' => 'any', 'posts_per_page' => 20,
				'fields' => 'ids', 'meta_key' => 'mptbm_order_id', 'meta_value' => absint( $order->get_id() ),
			)
		);
		foreach ( $booking_ids as $booking_id ) {
			echo $this->tracking_button_html( $booking_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	private function tracking_button_html( $booking_id ) {
		return '<p class="mptbm-gps-booking-link"><a class="button" href="' . esc_url( $this->customer_tracking_url( $booking_id ) ) . '"><i class="fas fa-location-arrow" aria-hidden="true"></i> ' . esc_html__( 'Track taxi live', 'ecab-taxi-gps-tracking' ) . '</a></p>';
	}

	public function add_driver_account_menu( $items ) {
		$user = wp_get_current_user();
		if ( in_array( 'mptbm_driver_role', (array) $user->roles, true ) ) {
			$items['gps-tracking'] = __( 'Live GPS', 'ecab-taxi-gps-tracking' );
		}
		return $items;
	}

	public function render_driver_account_endpoint() {
		echo do_shortcode( '[mptbm_gps_driver]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Embed tracking directly below the existing PRO Driver Panel. */
	public function render_driver_panel_tracking() {
		echo '<section class="mptbm-gps-driver-panel-section">';
		echo do_shortcode( '[mptbm_gps_driver embedded="driver-panel"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	public function maybe_stop_for_status( $booking_id, $new_status ) {
		$stopped = apply_filters( 'mptbm_gps_stop_statuses', array( 'completed', 'cancelled', 'canceled', 'refunded' ) );
		if ( in_array( sanitize_key( $new_status ), array_map( 'sanitize_key', $stopped ), true ) ) {
			$this->stop_booking( $booking_id );
		}
	}

	/** Catch status changes made directly by the PRO driver panel. */
	public function watch_driver_status_change( $meta_id, $booking_id, $meta_key, $new_status ) {
		unset( $meta_id );
		if ( 'mptbm_service_status' === $meta_key && 'mptbm_booking' === get_post_type( $booking_id ) ) {
			$this->maybe_stop_for_status( $booking_id, $new_status );
		}
	}

	public function stop_on_reassignment( $booking_id ) {
		$this->stop_booking( $booking_id );
	}

	private function stop_booking( $booking_id ) {
		delete_post_meta( absint( $booking_id ), self::ACTIVE_META );
		delete_post_meta( absint( $booking_id ), self::STARTED_META );
	}

	public function cleanup_locations() {
		global $wpdb;
		$settings = $this->settings();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - absint( $settings['retention_hours'] ) * HOUR_IN_SECONDS );
		$table    = $wpdb->prefix . 'mptbm_gps_locations';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_mptbm_transportation' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'ecab-taxi-gps-tracking' ) );
		}
		global $wpdb;
		$settings     = $this->settings();
		$settings_url = add_query_arg( 'mptbm_section', self::OPTION, admin_url( 'edit.php?post_type=mptbm_rent&page=mptbm_settings_page' ) );
		$driver_url   = $this->driver_page_url();
		$active_trips = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::ACTIVE_META, 'yes' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$point_count  = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mptbm_gps_locations" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( class_exists( 'MPTBM_Admin_Shell' ) ) {
			MPTBM_Admin_Shell::render_shell_open();
		}
		?>
		<div class="mptbm-gps-admin mptbm-gps-modern">
			<header class="mptbm-gps-hero">
				<div class="mptbm-gps-hero-copy"><span class="mptbm-gps-hero-icon" aria-hidden="true"><i class="fas fa-location-arrow"></i></span><div><span class="mptbm-gps-eyebrow"><?php esc_html_e( 'Fleet operations', 'ecab-taxi-gps-tracking' ); ?></span><h1><?php esc_html_e( 'Live GPS Tracking', 'ecab-taxi-gps-tracking' ); ?></h1><p><?php esc_html_e( 'Monitor assigned drivers, confirm location freshness, and share protected trip tracking with customers.', 'ecab-taxi-gps-tracking' ); ?></p></div></div>
				<div class="mptbm-gps-hero-actions"><a class="mptbm-gps-modern-btn" href="<?php echo esc_url( $driver_url ); ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i><?php esc_html_e( 'Open driver app', 'ecab-taxi-gps-tracking' ); ?></a><a class="mptbm-gps-modern-btn is-primary" href="<?php echo esc_url( $settings_url ); ?>"><i class="fas fa-sliders-h"></i><?php esc_html_e( 'GPS settings', 'ecab-taxi-gps-tracking' ); ?></a></div>
			</header>

			<div class="mptbm-gps-metrics">
				<div class="mptbm-gps-metric"><span class="mptbm-gps-metric-icon is-live"><i class="fas fa-satellite-dish"></i></span><div><strong><?php echo esc_html( $active_trips ); ?></strong><span><?php esc_html_e( 'Trips sharing now', 'ecab-taxi-gps-tracking' ); ?></span></div></div>
				<div class="mptbm-gps-metric"><span class="mptbm-gps-metric-icon"><i class="fas fa-map-marker-alt"></i></span><div><strong><?php echo esc_html( number_format_i18n( $point_count ) ); ?></strong><span><?php esc_html_e( 'Stored location points', 'ecab-taxi-gps-tracking' ); ?></span></div></div>
				<div class="mptbm-gps-metric"><span class="mptbm-gps-metric-icon"><i class="fas fa-sync-alt"></i></span><div><strong><?php echo esc_html( absint( $settings['interval'] ) ); ?>s</strong><span><?php esc_html_e( 'Update interval', 'ecab-taxi-gps-tracking' ); ?></span></div></div>
				<div class="mptbm-gps-metric"><span class="mptbm-gps-metric-icon"><i class="fas fa-shield-alt"></i></span><div><strong><?php echo esc_html( absint( $settings['retention_hours'] ) ); ?>h</strong><span><?php esc_html_e( 'History retention', 'ecab-taxi-gps-tracking' ); ?></span></div></div>
			</div>

			<div class="mptbm-gps-workspace">
				<section class="mptbm-gps-modern-card mptbm-gps-monitor-card"><div class="mptbm-gps-card-head"><div><span class="mptbm-gps-card-kicker"><?php esc_html_e( 'Dispatch monitor', 'ecab-taxi-gps-tracking' ); ?></span><h2><?php esc_html_e( 'Booking location', 'ecab-taxi-gps-tracking' ); ?></h2><p><?php esc_html_e( 'Select an assigned booking to view its latest driver position.', 'ecab-taxi-gps-tracking' ); ?></p></div><span class="mptbm-gps-live-badge"><i></i><?php esc_html_e( 'Auto refresh', 'ecab-taxi-gps-tracking' ); ?></span></div><div class="mptbm-gps-app" data-mode="admin"><label for="mptbm-gps-booking"><?php esc_html_e( 'Booking', 'ecab-taxi-gps-tracking' ); ?></label><select id="mptbm-gps-booking"><option value=""><?php esc_html_e( 'Loading assigned bookings…', 'ecab-taxi-gps-tracking' ); ?></option></select><div class="mptbm-gps-monitor-actions"><a class="mptbm-gps-modern-btn" data-gps-share href="#" target="_blank" rel="noopener" hidden><i class="fas fa-share-alt"></i><?php esc_html_e( 'Open customer link', 'ecab-taxi-gps-tracking' ); ?></a></div><div class="mptbm-gps-status" data-gps-status role="status" aria-live="polite"><?php esc_html_e( 'Choose a booking to begin monitoring.', 'ecab-taxi-gps-tracking' ); ?></div><div class="mptbm-gps-map" data-gps-map><div class="mptbm-gps-map-empty"><i class="fas fa-map-marked-alt"></i><strong><?php esc_html_e( 'No booking selected', 'ecab-taxi-gps-tracking' ); ?></strong><span><?php esc_html_e( 'The live map appears here after you choose a booking.', 'ecab-taxi-gps-tracking' ); ?></span></div></div><div class="mptbm-gps-details" data-gps-details></div></div></section>
				<aside class="mptbm-gps-modern-card mptbm-gps-guide"><div class="mptbm-gps-card-head"><div><span class="mptbm-gps-card-kicker"><?php esc_html_e( 'Driver workflow', 'ecab-taxi-gps-tracking' ); ?></span><h2><?php esc_html_e( 'How live tracking works', 'ecab-taxi-gps-tracking' ); ?></h2></div></div><ol><li><span>1</span><div><strong><?php esc_html_e( 'Assign the trip', 'ecab-taxi-gps-tracking' ); ?></strong><p><?php esc_html_e( 'Assign a PRO driver directly or through the booked vehicle.', 'ecab-taxi-gps-tracking' ); ?></p></div></li><li><span>2</span><div><strong><?php esc_html_e( 'Driver grants permission', 'ecab-taxi-gps-tracking' ); ?></strong><p><?php esc_html_e( 'The driver opens Driver Panel and taps Start sharing location.', 'ecab-taxi-gps-tracking' ); ?></p></div></li><li><span>3</span><div><strong><?php esc_html_e( 'Monitor and share', 'ecab-taxi-gps-tracking' ); ?></strong><p><?php esc_html_e( 'Dispatch sees updates here; customers use their protected tracking link.', 'ecab-taxi-gps-tracking' ); ?></p></div></li></ol><div class="mptbm-gps-browser-note"><i class="fas fa-lock"></i><p><?php esc_html_e( 'Browser security requires each driver to approve Location. Administrators cannot silently grant this permission.', 'ecab-taxi-gps-tracking' ); ?></p></div></aside>
			</div>
		</div>
		<?php
		if ( class_exists( 'MPTBM_Admin_Shell' ) ) {
			MPTBM_Admin_Shell::render_shell_close();
		}
	}
}
