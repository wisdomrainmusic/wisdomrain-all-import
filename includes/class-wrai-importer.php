<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Handles dry-run import analysis and summary for Wisdom Rain All Import.
 */
class WRAI_Importer {

    /**
     * Collected URLs during the import process for post-run validation.
     *
     * @var array
     */
    private $collected_image_urls = [];

    /**
     * @var array
     */
    private $collected_file_urls = [];

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
        $log_entries = [
            'parents_created'    => 0,
            'parents_updated'    => 0,
            'variations_created' => 0,
            'attributes_found'   => [],
            'terms_created'      => [],
            'images_imported'    => 0,
        ];

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'wrai' ) );
        }

        check_admin_referer( 'wrai_fullimport_nonce', 'wrai_fullimport_nonce_field' );

        $file = WRAI_Uploader::get_last_upload();
        if ( ! $file || empty( $file['path'] ) || ! file_exists( $file['path'] ) ) {
            wp_die( __( 'No uploaded file found.', 'wrai' ) );
        }

        $rows = [];
        $this->collected_image_urls = [];
        $this->collected_file_urls  = [];
        if ( ( $handle = fopen( $file['path'], 'r' ) ) !== false ) {
            $header = fgetcsv( $handle, 0, ',' );
            while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
                $row = array_combine( $header, $data );
                if ( $row ) {
                    if ( ! empty( $row['image_url'] ) ) {
                        $this->collected_image_urls[] = esc_url_raw( trim( $row['image_url'] ) );
                    }

                    if ( ! empty( $row['file_urls'] ) ) {
                        $file_parts = array_filter( array_map( 'trim', explode( ',', (string) $row['file_urls'] ) ) );
                        foreach ( $file_parts as $file_url ) {
                            $this->collected_file_urls[] = esc_url_raw( $file_url );
                        }
                    }
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
            $parent_id        = WRAI_Product::upsert_parent_product( $gid, $primary );

            if ( ! $parent_id ) {
                $errors[] = "Group {$gid}: parent product create/update failed.";
                continue;
            }

            $parent_created = ! $parent_id_before;

            if ( $parent_created ) {
                $log_entries['parents_created']++;
            } else {
                $log_entries['parents_updated']++;
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
                    $log_entries['images_imported']++;
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
                    $existing_lang_term = get_term_by( 'name', $lang_name, $tax_lang );
                    $term               = WRAI_Product::get_or_create_term( $tax_lang, $lang_name );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $lang_slug = $term->slug;

                        if ( ! $existing_lang_term ) {
                            $log_entries['attributes_found'][] = $tax_lang;
                            $log_entries['terms_created']      = array_merge(
                                $log_entries['terms_created'],
                                [ $term->slug ]
                            );
                        }
                    }
                }

                if ( $format_name !== '' ) {
                    $existing_format_term = get_term_by( 'name', $format_name, $tax_format );
                    $term                 = WRAI_Product::get_or_create_term( $tax_format, $format_name );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $format_slug = $term->slug;

                        if ( ! $existing_format_term ) {
                            $log_entries['attributes_found'][] = $tax_format;
                            $log_entries['terms_created']      = array_merge(
                                $log_entries['terms_created'],
                                [ $term->slug ]
                            );
                        }
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
                        $log_entries['images_imported']++;
                        $gallery_image_ids[] = $variation_image_id;
                    }
                }

                $update_mode = self::normalize_update_mode( $row['update_mode'] ?? 'auto' );
                $row['update_mode'] = $update_mode;

                $existing_variations = self::find_existing_variations( $parent_id, $attr_map );

                try {
                    $action       = null;
                    $variation_id = 0;

                    switch ( $update_mode ) {
                        case 'update_only':
                            if ( $existing_variations ) {
                                $variation_id = self::update_variation( $parent_id, $row, $attr_map );
                                $action       = 'update';
                            } else {
                                error_log( '⚪ Skipped creation because update_only mode found no existing variation' );
                            }
                            break;

                        case 'create_only':
                            if ( ! $existing_variations ) {
                                $variation_id = self::create_variation( $parent_id, $row, $attr_map );
                                $action       = 'create';
                            } else {
                                $first_existing = reset( $existing_variations );
                                $existing_id    = ( is_object( $first_existing ) && method_exists( $first_existing, 'get_id' ) )
                                    ? (int) $first_existing->get_id()
                                    : 0;
                                if ( $existing_id ) {
                                    error_log( "⚪ Skipped update for existing variation {$existing_id} due to create_only mode" );
                                } else {
                                    error_log( '⚪ Skipped update for existing variation due to create_only mode' );
                                }
                            }
                            break;

                        default:
                            if ( $existing_variations ) {
                                $variation_id = self::update_variation( $parent_id, $row, $attr_map );
                                $action       = 'update';
                            } else {
                                $variation_id = self::create_variation( $parent_id, $row, $attr_map );
                                $action       = 'create';
                            }
                            break;
                    }

                    if ( $variation_id ) {
                        if ( $attr_map ) {
                            WRAI_Product::set_variation_attributes( $variation_id, $attr_map );
                        }

                        if ( $action ) {
                            $variations++;
                        }

                        if ( 'create' === $action ) {
                            $log_entries['variations_created']++;
                        }
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

        $log_entries['attributes_found'] = array_values( array_unique( $log_entries['attributes_found'] ) );
        $log_entries['terms_created']    = array_values( array_unique( $log_entries['terms_created'] ) );

        $this->collected_image_urls = array_values( array_unique( array_filter( $this->collected_image_urls ) ) );
        $this->collected_file_urls  = array_values( array_unique( array_filter( $this->collected_file_urls ) ) );

        $link_validation_summary = null;
        if ( class_exists( 'WRAI_Link_Validator' ) ) {
            $link_validation_summary = WRAI_Link_Validator::run([
                'image_urls' => $this->collected_image_urls,
                'file_urls'  => $this->collected_file_urls,
            ]);

            if ( $link_validation_summary ) {
                $log_entries['link_validation'] = $link_validation_summary;
            }
        }

        set_transient( 'wrai_fullimport_summary', [
            'groups'     => count( $groups ),
            'created'    => $created,
            'updated'    => $updated,
            'variations' => $variations,
            'errors'     => $errors,
            'when'       => current_time( 'mysql' ),
            'log_entries' => $log_entries,
            'link_validation' => $link_validation_summary,
        ], 30 * MINUTE_IN_SECONDS );

        update_option( '_wrai_last_import_log', $log_entries );

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

    private static function normalize_update_mode( $mode ) {
        $mode = strtolower( trim( (string) $mode ) );

        if ( function_exists( 'sanitize_key' ) ) {
            $mode = sanitize_key( $mode );
        }

        if ( ! in_array( $mode, [ 'auto', 'update_only', 'create_only' ], true ) ) {
            return 'auto';
        }

        return $mode;
    }

    private static function find_existing_variations( $parent_id, array $attribute_slugs ) {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $meta_query = [];

        foreach ( $attribute_slugs as $taxonomy => $slug ) {
            $slug = (string) $slug;

            if ( $slug === '' ) {
                continue;
            }

            $meta_query[] = [
                'key'   => 'attribute_' . $taxonomy,
                'value' => $slug,
            ];
        }

        if ( ! $meta_query ) {
            return [];
        }

        if ( count( $meta_query ) > 1 ) {
            $meta_query = array_merge( [ 'relation' => 'AND' ], $meta_query );
        }

        return wc_get_products([
            'parent'     => $parent_id,
            'type'       => 'variation',
            'limit'      => -1,
            'meta_query' => $meta_query,
        ]);
    }

    private static function update_variation( $parent_id, $row, array $attribute_slugs ) {
        return WRAI_Product::upsert_variation( $parent_id, $row, $attribute_slugs );
    }

    private static function create_variation( $parent_id, $row, array $attribute_slugs ) {
        return WRAI_Product::upsert_variation( $parent_id, $row, $attribute_slugs );
    }
}

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $log = get_option( '_wrai_last_import_log' );
    if ( empty( $log ) || ! is_array( $log ) ) {
        return;
    }

    $parents_created    = intval( $log['parents_created'] ?? 0 );
    $variations_imported = intval( $log['variations_created'] ?? 0 );
    $attributes_found   = ! empty( $log['attributes_found'] ) && is_array( $log['attributes_found'] )
        ? array_unique( array_filter( array_map( 'strval', $log['attributes_found'] ) ) )
        : [];
    $terms_created      = ! empty( $log['terms_created'] ) && is_array( $log['terms_created'] )
        ? array_unique( array_filter( array_map( 'strval', $log['terms_created'] ) ) )
        : [];
    $images_imported    = intval( $log['images_imported'] ?? 0 );
    $link_validation    = isset( $log['link_validation'] ) && is_array( $log['link_validation'] )
        ? $log['link_validation']
        : null;

    $notice  = '<div class="notice notice-success is-dismissible">';
    $notice .= '<p><strong>WRAI Import Summary</strong></p>';
    $notice .= '<ul style="margin-left:1em;">';
    $notice .= '<li>✅ Parents Created: ' . esc_html( $parents_created ) . '</li>';
    $notice .= '<li>✅ Variations Imported: ' . esc_html( $variations_imported ) . '</li>';
    $notice .= '<li>🧩 Attributes: ' . esc_html( implode( ', ', $attributes_found ) ) . '</li>';
    $notice .= '<li>🏷️ Terms Created: ' . esc_html( implode( ', ', $terms_created ) ) . '</li>';
    $notice .= '<li>🖼️ Images Imported: ' . esc_html( $images_imported ) . '</li>';
    $notice .= '</ul>';

    if ( $link_validation ) {
        $links_checked = intval( $link_validation['total_checked'] ?? 0 );
        $links_ok      = intval( $link_validation['ok'] ?? 0 );
        $links_broken  = intval( $link_validation['broken'] ?? 0 );

        $notice .= sprintf(
            '<p>🔗 Links Checked: %d total, %d OK, %d broken</p>',
            $links_checked,
            $links_ok,
            $links_broken
        );

        if ( $links_broken > 0 && ! empty( $link_validation['broken_list'] ) && is_array( $link_validation['broken_list'] ) ) {
            $notice .= '<ul style="margin:0.5em 0 0 1.5em;">';
            foreach ( $link_validation['broken_list'] as $item ) {
                $notice .= '<li>' . esc_html( (string) $item ) . '</li>';
            }
            $notice .= '</ul>';
        }
    }

    $notice .= '</div>';

    echo $notice;
} );
