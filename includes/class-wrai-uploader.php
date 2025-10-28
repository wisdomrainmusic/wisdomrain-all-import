<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Handles CSV/XLSX upload, validation, and storage.
 */
class WRAI_Uploader {

    const OPTION_LAST_UPLOAD = 'wrai_last_upload';

    public function __construct() {
        // POST action for file upload
        add_action( 'admin_post_wrai_upload', [ $this, 'handle_upload' ] );
        add_action( 'admin_post_nopriv_wrai_upload', [ $this, 'deny_upload' ] );
    }

    public function deny_upload() {
        wp_die( __( 'You are not allowed to perform this action.', 'wrai' ) );
    }

    /**
     * Handle file upload from admin form
     */
    public function handle_upload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wrai' ) );
        }

        check_admin_referer( 'wrai_upload_nonce', 'wrai_upload_nonce_field' );

        if ( empty( $_FILES['wrai_import_file']['name'] ) ) {
            $this->redirect_with_msg( 'wrai-all-import', 'error', __( 'No file selected.', 'wrai' ) );
        }

        $file     = $_FILES['wrai_import_file'];
        $filename = $file['name'];
        $ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        $allowed  = [ 'csv', 'xlsx', 'xls' ];
        if ( ! in_array( $ext, $allowed, true ) ) {
            $this->redirect_with_msg( 'wrai-all-import', 'error', __( 'Only CSV or Excel files are allowed.', 'wrai' ) );
        }

        // Prepare upload dir /wrai
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit( $upload_dir['basedir'] ) . 'wrai';
        if ( ! file_exists( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
        }

        // Unique file name
        $new_name   = 'wrai_' . time() . '_' . sanitize_file_name( $filename );
        $target     = trailingslashit( $target_dir ) . $new_name;

        // Move file
        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            $this->redirect_with_msg( 'wrai-all-import', 'error', __( 'Upload failed. Check folder permissions.', 'wrai' ) );
        }

        // Save meta about last upload
        $data = [
            'file_name' => $new_name,
            'path'      => $target,
            'url'       => trailingslashit( $upload_dir['baseurl'] ) . 'wrai/' . $new_name,
            'time'      => current_time( 'mysql' ),
            'ext'       => $ext,
            'size'      => filesize( $target ),
        ];
        update_option( self::OPTION_LAST_UPLOAD, $data, false );

        $this->redirect_with_msg( 'wrai-all-import', 'updated', __( 'File uploaded successfully.', 'wrai' ) );
    }

    private function redirect_with_msg( $page, $type, $msg ) {
        $url = add_query_arg(
            [
                'page' => $page,
                'wrai_msg' => $type,
                'wrai_note' => rawurlencode( $msg )
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Helper to fetch last upload info (used by UI)
     */
    public static function get_last_upload() {
        $data = get_option( self::OPTION_LAST_UPLOAD );
        return is_array( $data ) ? $data : null;
    }
}
