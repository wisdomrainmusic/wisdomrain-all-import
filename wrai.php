<?php
/**
 * Plugin Name: Wisdom Rain All Import
 * Plugin URI:  https://wisdomrainbookmusic.com
 * Description: Multi-language & multi-format product importer for Wisdom Rain platform. Handles CSV/Excel imports with WooCommerce, Rank Math, and WPML integration.
 * Version: 1.0.0
 * Author: Wisdom Rain Dev Team
 * Text Domain: wrai
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Minimum PHP version check
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Wisdom Rain All Import</strong> requires PHP 7.4 or higher.</p></div>';
    });
    return;
}

/**
 * Define plugin constants
 */
define( 'WRAI_VERSION', '1.0.0' );
define( 'WRAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'WRAI_URL', plugin_dir_url( __FILE__ ) );
define( 'WRAI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader
 */
spl_autoload_register( function( $class ) {
    if ( strpos( $class, 'WRAI_' ) === 0 ) {
        $path = WRAI_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
        if ( file_exists( $path ) ) {
            include_once $path;
        }
    }
});

/**
 * Initialize plugin
 */
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        // Core admin class will be created in next commit
        if ( class_exists( 'WRAI_Admin_Menu' ) ) {
            new WRAI_Admin_Menu();
        }

        if ( class_exists( 'WRAI_Uploader' ) ) {
            new WRAI_Uploader();
        }
    } else {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-warning"><p><strong>Wisdom Rain All Import</strong> requires WooCommerce to be installed and active.</p></div>';
        });
    }
});

/**
 * Activation hook
 */
register_activation_hook( __FILE__, function() {
    // Future table creation / setup tasks here
});
