<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WRAI_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu_page' ] );

        if ( class_exists( 'WRAI_Uploader' ) ) new WRAI_Uploader();
        if ( class_exists( 'WRAI_Parser' ) ) new WRAI_Parser();
        if ( class_exists( 'WRAI_Importer' ) ) new WRAI_Importer();
    }

    public function register_menu_page() {
        add_menu_page(
            __( 'Wisdom Rain Import', 'wrai' ),
            __( 'Wisdom Rain', 'wrai' ),
            'manage_options',
            'wrai-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-database-import',
            56
        );

        add_submenu_page(
            'wrai-dashboard',
            __( 'All Import', 'wrai' ),
            __( 'All Import', 'wrai' ),
            'manage_options',
            'wrai-all-import',
            [ $this, 'render_all_import' ]
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

        $last = WRAI_Uploader::get_last_upload();
        $preview = ( isset( $_GET['wrai_preview'] ) && $_GET['wrai_preview'] == 1 ) ? WRAI_Parser::get_preview_data() : null;

        echo '<div class="wrap">';
        echo '<h1>All Import</h1>';
        echo '<p>Upload your CSV/Excel to start the import process.</p>';

        // Upload form
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="margin-top:16px;">';
        wp_nonce_field( 'wrai_upload_nonce', 'wrai_upload_nonce_field' );
        echo '<input type="hidden" name="action" value="wrai_upload" />';
        echo '<input type="file" name="wrai_import_file" accept=".csv,.xlsx,.xls" required />';
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

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
            wp_nonce_field( 'wrai_preview_nonce', 'wrai_preview_nonce_field' );
            echo '<input type="hidden" name="action" value="wrai_preview" />';
            submit_button( __( 'Preview First 10 Rows', 'wrai' ), 'secondary', 'submit', false );
            echo '</form>';
        } else {
            echo '<p>No uploads yet.</p>';
        }

        // Preview table
        if ( $preview && isset( $preview['rows'] ) ) {
            echo '<hr style="margin:24px 0;" />';
            echo '<h2>Preview (First 10 Rows)</h2>';
            echo '<p>Total Lines: <strong>' . esc_html( $preview['summary']['total_lines'] ) . '</strong> | ';
            echo 'Unique Groups: <strong>' . esc_html( $preview['summary']['unique_groups'] ) . '</strong></p>';
            echo '<div style="overflow:auto; max-height:500px;">';
            echo '<table class="widefat striped"><thead><tr>';
            foreach ( $preview['header'] as $h ) {
                echo '<th>' . esc_html( $h ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ( $preview['rows'] as $r ) {
                echo '<tr>';
                foreach ( $r as $cell ) {
                    echo '<td>' . esc_html( $cell ) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }

        // Dry Run section
        if ( $last && $preview ) {
            echo '<hr style="margin:24px 0;" />';
            echo '<h2>Dry Run Import</h2>';
            echo '<p>Run a simulated import to analyze product groups and variations before creating products.</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wrai_dryrun_nonce', 'wrai_dryrun_nonce_field' );
            echo '<input type="hidden" name="action" value="wrai_dry_run" />';
            submit_button( __( 'Start Dry Run Import', 'wrai' ), 'primary', 'submit', false );
            echo '</form>';
        }

        // Dry Run Summary Display
        if ( isset( $_GET['wrai_dryrun'] ) && $_GET['wrai_dryrun'] == 1 ) {
            $summary = WRAI_Importer::get_summary();
            if ( $summary ) {
                echo '<hr style="margin:24px 0;" />';
                echo '<h2>Dry Run Summary</h2>';
                echo '<ul style="line-height:1.8">';
                echo '<li><strong>Total Product Groups:</strong> ' . esc_html( $summary['total_groups'] ) . '</li>';
                echo '<li><strong>Total Variations:</strong> ' . esc_html( $summary['total_variations'] ) . '</li>';
                echo '</ul>';

                if ( ! empty( $summary['warnings'] ) ) {
                    echo '<div class="notice notice-warning"><p><strong>Warnings:</strong></p><ul>';
                    foreach ( $summary['warnings'] as $w ) {
                        echo '<li>' . esc_html( $w ) . '</li>';
                    }
                    echo '</ul></div>';
                } else {
                    echo '<div class="notice notice-success"><p>No missing fields detected. Ready for full import ✅</p></div>';
                }
            }
        }

        echo '</div>';
    }
}
