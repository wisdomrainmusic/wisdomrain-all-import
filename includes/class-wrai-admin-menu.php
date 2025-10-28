<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class WRAI_Admin_Menu
 * Handles admin menu and initial UI
 */
class WRAI_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
        // Ensure uploader is initialized
        if ( class_exists( 'WRAI_Uploader' ) ) {
            new WRAI_Uploader();
        }
    }

    public function register_menu_page() {
        add_menu_page(
            __( 'Wisdom Rain Import', 'wrai' ),
            __( 'Wisdom Rain', 'wrai' ),
            'manage_options',
            'wrai-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-database-import',
            56
        );

        add_submenu_page(
            'wrai-dashboard',
            __( 'All Import', 'wrai' ),
            __( 'All Import', 'wrai' ),
            'manage_options',
            'wrai-all-import',
            array( $this, 'render_all_import' )
        );
    }

    public function render_dashboard() {
        echo '<div class="wrap"><h1>Wisdom Rain All Import</h1>';
        echo '<p>Welcome to the import manager for Wisdom Rain Platform.</p>';
        echo '<p><strong>Version:</strong> ' . esc_html( WRAI_VERSION ) . '</p>';
        echo '</div>';
    }

    public function render_all_import() {
        if ( isset( $_GET['wrai_msg'], $_GET['wrai_note'] ) ) {
            $klass = $_GET['wrai_msg'] === 'updated' ? 'updated' : 'error';
            echo '<div class="notice notice-' . esc_attr( $klass ) . ' is-dismissible">';
            echo '<p>' . esc_html( rawurldecode( $_GET['wrai_note'] ) ) . '</p></div>';
        }

        $last = class_exists('WRAI_Uploader') ? WRAI_Uploader::get_last_upload() : null;

        echo '<div class="wrap">';
        echo '<h1>All Import</h1>';
        echo '<p>Upload your CSV/Excel to start the import process.</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="margin-top:16px;">';
        wp_nonce_field( 'wrai_upload_nonce', 'wrai_upload_nonce_field' );
        echo '<input type="hidden" name="action" value="wrai_upload" />';
        echo '<input type="file" name="wrai_import_file" accept=".csv, .xlsx, .xls" required />';
        submit_button( __( 'Upload File', 'wrai' ), 'primary', 'submit', false );
        echo '</form>';

        echo '<hr style="margin:24px 0;" />';

        echo '<h2>Last Upload</h2>';
        if ( $last ) {
            echo '<ul style="line-height:1.8">';
            echo '<li><strong>File:</strong> ' . esc_html( $last['file_name'] ) . '</li>';
            echo '<li><strong>Type:</strong> ' . esc_html( strtoupper( $last['ext'] ) ) . '</li>';
            echo '<li><strong>Size:</strong> ' . esc_html( size_format( (int) $last['size'] ) ) . '</li>';
            echo '<li><strong>Time:</strong> ' . esc_html( $last['time'] ) . '</li>';
            echo '<li><strong>URL:</strong> <a href="' . esc_url( $last['url'] ) . '" target="_blank" rel="noopener">Open</a></li>';
            echo '</ul>';
            echo '<p style="opacity:.7">Next step: parse this file into batches and start import.</p>';
        } else {
            echo '<p>No uploads yet.</p>';
        }

        echo '</div>';
    }
}
