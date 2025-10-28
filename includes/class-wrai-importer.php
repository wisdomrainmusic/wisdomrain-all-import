<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Handles dry-run import analysis and summary for Wisdom Rain All Import.
 */
class WRAI_Importer {

    public function __construct() {
        add_action( 'admin_post_wrai_dry_run', [ $this, 'handle_dry_run' ] );
    }

    /**
     * Perform Dry Run (simulation)
     */
    public function handle_dry_run() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wrai' ) );
        }

        check_admin_referer( 'wrai_dryrun_nonce', 'wrai_dryrun_nonce_field' );

        $file = WRAI_Uploader::get_last_upload();
        if ( ! $file || empty( $file['path'] ) || ! file_exists( $file['path'] ) ) {
            wp_die( __( 'No uploaded file found.', 'wrai' ) );
        }

        $rows = [];
        if ( ( $handle = fopen( $file['path'], 'r' ) ) !== false ) {
            $header = fgetcsv( $handle, 0, ',' );
            while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
                $row = array_combine( $header, $data );
                if ( $row ) {
                    $rows[] = $row;
                }
            }
            fclose( $handle );
        }

        $grouped = [];
        foreach ( $rows as $r ) {
            $gid = trim( $r['group_id'] ?? '' );
            if ( ! $gid ) {
                continue;
            }
            if ( ! isset( $grouped[ $gid ] ) ) {
                $grouped[ $gid ] = [];
            }
            $grouped[ $gid ][] = $r;
        }

        $summary = [
            'total_groups'     => count( $grouped ),
            'total_variations' => count( $rows ),
            'warnings'         => [],
        ];

        // Check missing critical fields
        foreach ( $rows as $i => $r ) {
            $missing = [];
            foreach ( [ 'product_title', 'language', 'format', 'file_urls' ] as $field ) {
                if ( empty( $r[ $field ] ) ) {
                    $missing[] = $field;
                }
            }
            if ( $missing ) {
                $summary['warnings'][] = 'Row ' . ( $i + 1 ) . ' missing: ' . implode( ', ', $missing );
            }
        }

        // Save results for display
        set_transient( 'wrai_dryrun_summary', $summary, 30 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=wrai-all-import&wrai_dryrun=1' ) );
        exit;
    }

    public static function get_summary() {
        return get_transient( 'wrai_dryrun_summary' );
    }
}
