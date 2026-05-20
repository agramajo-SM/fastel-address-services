<?php
/**
 * Plugin Name: Avalaunch Address Services
 * Description: Search for an address via Google Maps and fetch available services from Salesforce.
 * Version: 1.0.0
 * Author: Avalaunch Team
 * Text Domain: avalaunch-address-services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Plugin Constants
define( 'AVALAUNCH_PLUGIN_VERSION', '1.0.0' );
define( 'AVALAUNCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AVALAUNCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include Classes
require_once AVALAUNCH_PLUGIN_DIR . 'includes/class-settings.php';
require_once AVALAUNCH_PLUGIN_DIR . 'includes/class-salesforce.php';
require_once AVALAUNCH_PLUGIN_DIR . 'includes/class-frontend.php';

// Initialize Classes
function avalaunch_init_plugin() {
	new Avalaunch_Settings();
	new Avalaunch_Frontend();
}
add_action( 'plugins_loaded', 'avalaunch_init_plugin' );
