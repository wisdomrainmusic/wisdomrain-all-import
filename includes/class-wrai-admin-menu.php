<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WRAI_Admin_Menu
 * Handles admin menu and initial UI
 */
class WRAI_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
    }

    /**
     * Register admin menu and submenu pages
     */
    public function register_menu_page() {
        
        // Ana menü: "Wisdom Rain"
        add_menu_page(
            __( 'Wisdom Rain Import', 'wrai' ),
            __( 'Wisdom Rain', 'wrai' ),
            'manage_options',
            'wrai-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-database-import',
            56
        );

        // Alt menü: "All Import"
        add_submenu_page(
            'wrai-dashboard',
            __( 'All Import', 'wrai' ),
            __( 'All Import', 'wrai' ),
            'manage_options',
            'wrai-all-import',
            array( $this, 'render_all_import' )
        );
    }

    /**
     * Dashboard page content
     */
    public function render_dashboard() {
        echo '<div class="wrap">';
        echo '<h1>Wisdom Rain All Import</h1>';
        echo '<p>Welcome to the import manager for Wisdom Rain Platform.</p>';
        echo '<p><strong>Version:</strong> ' . esc_html( WRAI_VERSION ) . '</p>';
        echo '</div>';
    }

    /**
     * All Import page content
     */
    public function render_all_import() {
        echo '<div class="wrap">';
        echo '<h1>All Import</h1>';
        echo '<p>CSV/Excel upload and import functionality coming soon...</p>';
        echo '</div>';
    }
}
