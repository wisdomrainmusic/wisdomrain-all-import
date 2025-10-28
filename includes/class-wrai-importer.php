<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Handles dry-run import analysis and summary for Wisdom Rain All Import.
 */
class WRAI_Importer {

    public function __construct() {
        add_action( 'admin_post_wrai_dry_run', [ $this, 'handle_dry_run' ] );
        add_action( 'admin_post_wrai_full_import', [ $this, 'handle_full_import' ] );
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

    /**
     * Perform the full import: create/update products and their variations.
     */
    public function handle_full_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wrai' ) );
        }

        check_admin_referer( 'wrai_fullimport_nonce', 'wrai_fullimport_nonce_field' );

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

        $groups = [];
        foreach ( $rows as $r ) {
            $gid = trim( $r['group_id'] ?? '' );
            if ( ! $gid ) {
                continue;
            }
            if ( ! isset( $groups[ $gid ] ) ) {
                $groups[ $gid ] = [];
            }
            $groups[ $gid ][] = $r;
        }

        $created = 0;
        $updated = 0;
        $variations = 0;
        $errors = [];

        foreach ( $groups as $gid => $items ) {
            $primary = null;
            foreach ( $items as $r ) {
                if ( strtolower( trim( $r['language'] ?? '' ) ) === 'english' ) {
                    $primary = $r;
                    break;
                }
            }

            if ( ! $primary ) {
                $primary = $items[0];
            }

            $parent_id_before = self::find_parent_by_group( $gid );
            $parent_id = WRAI_Product::upsert_parent_product( $gid, $primary );

            if ( ! $parent_id ) {
                $errors[] = "Group {$gid}: parent product create/update failed.";
                continue;
            }

            if ( $parent_id_before ) {
                $updated++;
            } else {
                $created++;
            }

            if ( class_exists( 'WRAI_SEO' ) ) {
                WRAI_SEO::apply_rankmath_meta( $parent_id, $primary );
            }

            $gallery_image_ids = [];
            $primary_image_id  = 0;

            if ( ! empty( $primary['image_url'] ) ) {
                $primary_image_id = WRAI_Product::attach_image_from_url(
                    $parent_id,
                    esc_url_raw( $primary['image_url'] )
                );

                if ( $primary_image_id ) {
                    $gallery_image_ids[] = $primary_image_id;
                }
            }

            foreach ( $items as $row ) {
                $tax_lang   = 'pa_language';
                $tax_format = 'pa_format';

                $lang_name   = trim( (string) ( $row['language'] ?? '' ) );
                $format_name = trim( (string) ( $row['format'] ?? '' ) );

                $lang_slug   = '';
                $format_slug = '';

                if ( $lang_name !== '' ) {
                    $term = WRAI_Product::get_or_create_term( $tax_lang, $lang_name );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $lang_slug = $term->slug;
                    }
                }

                if ( $format_name !== '' ) {
                    $term = WRAI_Product::get_or_create_term( $tax_format, $format_name );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $format_slug = $term->slug;
                    }
                }

                $attr_map = [];

                if ( $lang_slug ) {
                    $attr_map[ $tax_lang ] = $lang_slug;
                    WRAI_Product::ensure_parent_attribute_value( $parent_id, $tax_lang, $lang_slug );
                } elseif ( $lang_name !== '' ) {
                    $attr_map[ $tax_lang ] = sanitize_title( $lang_name );
                }

                if ( $format_slug ) {
                    $attr_map[ $tax_format ] = $format_slug;
                    WRAI_Product::ensure_parent_attribute_value( $parent_id, $tax_format, $format_slug );
                } elseif ( $format_name !== '' ) {
                    $attr_map[ $tax_format ] = sanitize_title( $format_name );
                }

                if ( ! empty( $row['image_url'] ) ) {
                    $variation_image_id = WRAI_Product::attach_image_from_url(
                        $parent_id,
                        esc_url_raw( $row['image_url'] )
                    );

                    if ( $variation_image_id ) {
                        $gallery_image_ids[] = $variation_image_id;
                    }
                }

                try {
                    $vid = WRAI_Product::upsert_variation( $parent_id, $row, $attr_map );
                    if ( $vid ) {
                        if ( $attr_map ) {
                            WRAI_Product::set_variation_attributes( $vid, $attr_map );
                        }
                        $variations++;
                    }
                } catch ( \Throwable $e ) {
                    $errors[] = 'Group ' . $gid . ': variation error - ' . $e->getMessage();
                }
            }

            if ( $gallery_image_ids ) {
                $current_gallery = get_post_meta( $parent_id, '_product_image_gallery', true );
                $current_ids     = [];

                if ( is_string( $current_gallery ) && $current_gallery !== '' ) {
                    $current_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $current_gallery ) ) ) );
                }

                $featured_id = (int) get_post_thumbnail_id( $parent_id );

                $merged = array_unique( array_map( 'intval', array_merge( $current_ids, $gallery_image_ids ) ) );
                if ( $featured_id ) {
                    $merged = array_values( array_diff( $merged, [ $featured_id ] ) );
                }

                if ( $merged ) {
                    update_post_meta( $parent_id, '_product_image_gallery', implode( ',', $merged ) );
                } else {
                    delete_post_meta( $parent_id, '_product_image_gallery' );
                }
            }

            if ( $primary_image_id ) {
                set_post_thumbnail( $parent_id, $primary_image_id );
            }

            // Tüm varyasyonlar oluşturulduktan sonra parent'ı finalize et
            WRAI_Product::finalize_variable_parent( $parent_id );
        }

        // Import işlemi tamamlandıktan sonra tüm parent ürünleri yeniden sync et
        $parents = get_posts([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'numberposts' => -1,
        ]);

        foreach ( $parents as $pid ) {
            wp_cache_delete( $pid, 'posts' );
            clean_post_cache( $pid );

            if ( class_exists( 'WC_Product_Variable' ) ) {
                WC_Product_Variable::sync( $pid );
            }
        }

        set_transient( 'wrai_fullimport_summary', [
            'groups'     => count( $groups ),
            'created'    => $created,
            'updated'    => $updated,
            'variations' => $variations,
            'errors'     => $errors,
            'when'       => current_time( 'mysql' ),
        ], 30 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=wrai-all-import&wrai_fullimport=1' ) );
        exit;
    }

    private static function find_parent_by_group( $group_id ) {
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'meta_key'       => '_wrai_group_id',
            'meta_value'     => $group_id,
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ]);

        return $q->have_posts() ? (int) $q->posts[0] : 0;
    }

    public static function get_fullimport_summary() {
        return get_transient( 'wrai_fullimport_summary' );
    }
}
