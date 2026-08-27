<?php
/**
 * Plugin Name:       MyHome Eagle Sync
 * Description:       Import real-estate listings from the Eagle Software GraphQL API (v3) into the myhome_listing post type.
 * Version:           0.3.1
 * Author:            Tasnim Rizvy
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       myhome-eagle-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MES_VERSION', '0.3.1' );
define( 'MES_PLUGIN_FILE', __FILE__ );
define( 'MES_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'MES_OPTION_CLIENT_ID', 'mes_client_id' );
define( 'MES_OPTION_CLIENT_SECRET', 'mes_client_secret' );
define( 'MES_OPTION_SYNC_STATE', 'mes_sync_state' );
define( 'MES_OPTION_SYNC_SUMMARY', 'mes_sync_summary' );
define( 'MES_OPTION_LOG', 'mes_log' );
define( 'MES_TRANSIENT_TOKEN', 'mes_eagle_token' );

require_once MES_PLUGIN_PATH . 'includes/class-eagle-logger.php';
require_once MES_PLUGIN_PATH . 'includes/class-eagle-api-client.php';
require_once MES_PLUGIN_PATH . 'includes/class-eagle-field-manager.php';
require_once MES_PLUGIN_PATH . 'includes/class-eagle-sync-engine.php';
require_once MES_PLUGIN_PATH . 'includes/class-eagle-settings.php';
require_once MES_PLUGIN_PATH . 'includes/class-eagle-ajax.php';

add_action( 'plugins_loaded', static function () {
	Eagle_Logger::init();

	if ( ! class_exists( 'Tangibledesign\Framework\Core\App' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'MyHome Eagle Sync requires the MyHome Core plugin to be active.', 'myhome-eagle-sync' )
				. '</p></div>';
		} );
	}

	new Eagle_Settings();
	new Eagle_Ajax();

	if ( is_admin() ) {
		add_action( 'admin_init', [ Eagle_Settings::class, 'handle_post' ] );
	}
} );
