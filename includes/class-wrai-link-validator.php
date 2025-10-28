<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WRAI_Link_Validator {

    /**
     * Run link validation for given import results and log a health report.
     *
     * @param array $import_results
     * @return array
     */
    public static function run( $import_results ) {
        $summary = [
            'total_checked' => 0,
            'ok'            => 0,
            'broken'        => 0,
            'broken_list'   => [],
        ];

        $image_urls = array_values( array_unique( array_filter( (array) ( $import_results['image_urls'] ?? [] ) ) ) );
        $file_urls  = array_values( array_unique( array_filter( (array) ( $import_results['file_urls'] ?? [] ) ) ) );

        self::validate_urls( $image_urls, 'Image', $summary );
        self::validate_urls( $file_urls, 'File', $summary );

        self::log_results( $summary );

        return $summary;
    }

    /**
     * Validate an array of URLs and update the summary.
     *
     * @param array  $urls
     * @param string $context
     * @param array  $summary
     */
    private static function validate_urls( $urls, $context, array &$summary ) {
        foreach ( $urls as $url ) {
            $summary['total_checked']++;

            if ( self::check_url( $url ) ) {
                $summary['ok']++;
                continue;
            }

            $summary['broken']++;
            $summary['broken_list'][] = sprintf( '%s: %s', $context, esc_url_raw( $url ) );
        }
    }

    /**
     * Perform a lightweight HTTP check to verify URL availability.
     *
     * @param string $url
     * @return bool
     */
    private static function check_url( $url ) {
        $url = trim( (string) $url );
        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        $args = [
            'timeout'     => 10,
            'redirection' => 3,
            'sslverify'   => apply_filters( 'https_local_ssl_verify', true ),
        ];

        $response = wp_remote_head( $url, $args );

        if ( is_wp_error( $response ) ) {
            $response = wp_remote_get( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        return $code >= 200 && $code < 400;
    }

    /**
     * Save the validation results into an uploads-based log file.
     *
     * @param array $summary
     * @return void
     */
    private static function log_results( $summary ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'] ?? '';

        if ( ! $base_dir || ! is_dir( $base_dir ) || ! is_writable( $base_dir ) ) {
            return;
        }

        $filename = trailingslashit( $base_dir ) . 'wrai-import-log-' . gmdate( 'Y-m-d-His' ) . '.txt';

        $log_lines = [];
        $log_lines[] = '[Import Health Report - ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC]';
        $log_lines[] = 'Total Checked: ' . intval( $summary['total_checked'] ?? 0 );
        $log_lines[] = 'OK: ' . intval( $summary['ok'] ?? 0 );
        $log_lines[] = 'Broken: ' . intval( $summary['broken'] ?? 0 );

        if ( ! empty( $summary['broken_list'] ) && is_array( $summary['broken_list'] ) ) {
            $log_lines[] = '';
            $log_lines[] = 'Broken URLs:';
            foreach ( $summary['broken_list'] as $item ) {
                $log_lines[] = (string) $item;
            }
        }

        file_put_contents( $filename, implode( "\n", $log_lines ) );
    }
}
