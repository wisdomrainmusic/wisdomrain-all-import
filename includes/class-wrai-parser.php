<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Handles parsing and previewing of uploaded CSV files.
 */
class WRAI_Parser {

    public function __construct() {
        add_action( 'admin_post_wrai_preview', [ $this, 'handle_preview' ] );
    }

    /**
     * Read CSV file and show preview
     */
    public function handle_preview() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wrai' ) );
        }

        check_admin_referer( 'wrai_preview_nonce', 'wrai_preview_nonce_field' );

        $file = WRAI_Uploader::get_last_upload();
        if ( ! $file || empty( $file['path'] ) || ! file_exists( $file['path'] ) ) {
            wp_die( __( 'No uploaded file found.', 'wrai' ) );
        }

        $rows        = [];
        $header      = [];
        $total_lines = 0;
        if ( ( $handle = fopen( $file['path'], 'r' ) ) !== false ) {
            $header = fgetcsv( $handle, 0, ',' );
            $limit  = 10;
            while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
                $total_lines++;
                if ( count( $rows ) < $limit ) {
                    $rows[] = $data;
                }
            }
            fclose( $handle );
        }

        // Count total lines and groups
        $groups = [];
        foreach ( $rows as $r ) {
            $gid = isset( $r[0] ) ? trim( $r[0] ) : 'N/A';
            if ( $gid ) {
                $groups[ $gid ] = true;
            }
        }

        $summary = [
            'total_lines'   => $total_lines,
            'preview_count' => count( $rows ),
            'unique_groups' => count( $groups ),
        ];

        // Save preview info in transient for later use
        set_transient( 'wrai_last_preview', [
            'summary' => $summary,
            'header'  => $header,
            'rows'    => $rows,
        ], HOUR_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=wrai-all-import&wrai_preview=1' ) );
        exit;
    }

    /**
     * Helper to get last preview
     */
    public static function get_preview_data() {
        return get_transient( 'wrai_last_preview' );
    }
}
