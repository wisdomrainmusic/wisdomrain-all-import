<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WRAI_Product {

    /** Ensure attribute taxonomies and terms exist, return normalized slugs */
    public static function ensure_attributes_and_terms( $language, $format ) {
        // Register attributes if missing (Format, Language) – Woo uses 'pa_' prefix.
        self::maybe_register_attribute( 'Format', 'format' );
        self::maybe_register_attribute( 'Language', 'language' );

        // Ensure terms exist
        $lang_term   = self::maybe_create_term( 'pa_language', $language );
        $format_term = self::maybe_create_term( 'pa_format',   $format );

        return [
            'language' => $lang_term ? $lang_term['slug'] : sanitize_title( $language ),
            'format'   => $format_term ? $format_term['slug'] : sanitize_title( $format ),
        ];
    }

    private static function maybe_register_attribute( $label, $slug ) {
        if ( ! function_exists( 'wc_get_attribute_taxonomy_names' ) || ! function_exists( 'wc_create_attribute' ) ) {
            return;
        }

        $taxes = wc_get_attribute_taxonomy_names();
        $taxonomy = 'pa_' . $slug;

        if ( in_array( $taxonomy, $taxes, true ) ) {
            return;
        }

        $args = [
            'slug'         => $slug,
            'name'         => $label,
            'type'         => 'select',
            'orderby'      => 'name',
            'has_archives' => false,
        ];

        $created = wc_create_attribute( $args );

        if ( is_wp_error( $created ) ) {
            return;
        }

        register_taxonomy(
            $taxonomy,
            [ 'product' ],
            [
                'hierarchical' => false,
                'label'        => $label,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
            ]
        );
        flush_rewrite_rules();
    }

    private static function maybe_create_term( $taxonomy, $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) return null;
        $slug = sanitize_title( $name );
        $existing = get_term_by( 'slug', $slug, $taxonomy );
        if ( $existing ) return [ 'term_id' => $existing->term_id, 'slug' => $existing->slug ];

        $res = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
        if ( is_wp_error( $res ) ) return null;
        return [ 'term_id' => $res['term_id'], 'slug' => $slug ];
    }

    /** Create or update VARIABLE parent product by unique group_id */
    public static function upsert_parent_product( $group_id, $row ) {
        $title   = sanitize_text_field( $row['product_title'] ?? 'Untitled' );
        $slug    = sanitize_title( $row['slug'] ?? $title . '-' . $group_id );

        // Try find existing by meta _wrai_group_id
        $q = new WP_Query([
            'post_type'  => 'product',
            'post_status'=> 'any',
            'meta_key'   => '_wrai_group_id',
            'meta_value' => $group_id,
            'fields'     => 'ids',
            'posts_per_page' => 1,
        ]);
        $product_id = $q->have_posts() ? (int)$q->posts[0] : 0;

        if ( ! $product_id ) {
            // Create new variable product
            $product_id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_type'    => 'product',
                'post_status'  => 'publish',
                'post_content' => wp_kses_post( $row['product_description'] ?? '' ),
                'post_excerpt' => wp_kses_post( $row['short_description'] ?? '' ),
            ]);
            if ( is_wp_error( $product_id ) ) return 0;

            update_post_meta( $product_id, '_wrai_group_id', $group_id );
            update_post_meta( $product_id, '_visibility', 'visible' );

            $product = wc_get_product( $product_id );
            if ( $product && is_a( $product, 'WC_Product' ) ) {
                $product->set_catalog_visibility( 'visible' );
                $product->set_sold_individually( false );
                $product->set_manage_stock( false );
                $product->set_status( 'publish' );
                $product->set_type( 'variable' );
                $product->save();
            }
        } else {
            // Update content/excerpt if provided
            if ( ! empty( $row['product_description'] ) ) {
                wp_update_post([
                    'ID' => $product_id,
                    'post_content' => wp_kses_post( $row['product_description'] ),
                ]);
            }
            if ( ! empty( $row['short_description'] ) ) {
                wp_update_post([
                    'ID' => $product_id,
                    'post_excerpt' => wp_kses_post( $row['short_description'] ),
                ]);
            }
        }

        // Assign categories
        self::assign_categories( $product_id, $row['parent_category'] ?? '', $row['subcategory'] ?? '' );

        // Set featured image
        if ( ! empty( $row['product_image'] ) ) {
            self::set_product_image_from_url( $product_id, esc_url_raw( $row['product_image'] ) );
        }

        // Ensure attributes (enable for variations)
        self::ensure_parent_attributes( $product_id );

        return $product_id;
    }

    private static function assign_categories( $product_id, $parent_cat, $sub_cat ) {
        $terms = [];
        if ( $parent_cat ) {
            $parent_cat = trim( $parent_cat );
            $parent = term_exists( $parent_cat, 'product_cat' );
            if ( ! $parent ) {
                $parent = wp_insert_term( $parent_cat, 'product_cat', [ 'slug' => sanitize_title( $parent_cat ) ] );
            }
            $parent_id = is_array( $parent ) ? $parent['term_id'] : ( $parent['term_id'] ?? 0 );
            if ( $parent_id ) $terms[] = (int)$parent_id;

            if ( $sub_cat ) {
                $sub_cat = trim( $sub_cat );
                $child = term_exists( $sub_cat, 'product_cat' );
                if ( ! $child ) {
                    $child = wp_insert_term( $sub_cat, 'product_cat', [
                        'slug' => sanitize_title( $sub_cat ),
                        'parent' => $parent_id
                    ] );
                }
                $child_id = is_array( $child ) ? $child['term_id'] : ( $child['term_id'] ?? 0 );
                if ( $child_id ) $terms[] = (int)$child_id;
            }
        }
        if ( $terms ) {
            wp_set_post_terms( $product_id, $terms, 'product_cat', false );
        }
    }

    private static function set_product_image_from_url( $product_id, $url ) {
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) return;

        // Avoid duplicate downloads: try find existing attachment with source URL meta
        $existing = get_posts([
            'post_type'  => 'attachment',
            'meta_key'   => '_wrai_src_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        if ( $existing ) {
            set_post_thumbnail( $product_id, (int)$existing[0] );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_id = media_sideload_image( $url, $product_id, null, 'id' );
        if ( ! is_wp_error( $attach_id ) ) {
            update_post_meta( $attach_id, '_wrai_src_url', $url );
            set_post_thumbnail( $product_id, (int)$attach_id );
        }
    }

    private static function ensure_parent_attributes( $product_id ) {
        // Parent must declare which attributes are used for variations
        $product_attributes = [];
        $product_attributes['pa_language'] = [
            'name'         => 'pa_language',
            'value'        => '',
            'position'     => 0,
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 1,
        ];
        $product_attributes['pa_format'] = [
            'name'         => 'pa_format',
            'value'        => '',
            'position'     => 1,
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 1,
        ];
        update_post_meta( $product_id, '_product_attributes', $product_attributes );
    }

    /** Create or update a single variation for (language, format) */
    public static function upsert_variation( $parent_id, $row ) {
        $price_regular_raw = str_replace( ',', '.', (string) ( $row['price_regular'] ?? '' ) );
        $price_sale_raw    = str_replace( ',', '.', (string) ( $row['price_sale'] ?? '' ) );
        $price_regular     = $price_regular_raw !== '' ? wc_format_decimal( $price_regular_raw ) : '';
        $price_sale        = $price_sale_raw !== '' ? wc_format_decimal( $price_sale_raw ) : '';
        $stock_status  = sanitize_text_field( $row['stock_status'] ?? 'instock' );
        $valid_statuses = function_exists( 'wc_get_product_stock_statuses' ) ? array_keys( wc_get_product_stock_statuses() ) : [ 'instock', 'outofstock', 'onbackorder' ];
        if ( ! in_array( $stock_status, $valid_statuses, true ) ) {
            $stock_status = 'instock';
        }
        $file_urls     = trim( (string)( $row['file_urls'] ?? '' ) );
        $language      = (string)( $row['language'] ?? '' );
        $format        = (string)( $row['format'] ?? '' );

        // Ensure terms
        $terms = self::ensure_attributes_and_terms( $language, $format );
        $attr_lang  = $terms['language'];
        $attr_format= $terms['format'];

        // Find existing variation by attributes
        $existing_id = 0;
        $children = wc_get_products([
            'status'    => array_map( 'wc_clean', [ 'publish', 'private' ] ),
            'type'      => 'variation',
            'parent'    => $parent_id,
            'limit'     => -1,
            'return'    => 'ids',
        ]);
        foreach ( $children as $vid ) {
            $v = wc_get_product( $vid );
            if ( ! $v ) continue;
            $atts = $v->get_attributes();
            $lang_ok  = ( $atts['pa_language'] ?? '' ) === $attr_lang;
            $fmt_ok   = ( $atts['pa_format']   ?? '' ) === $attr_format;
            if ( $lang_ok && $fmt_ok ) { $existing_id = $vid; break; }
        }

        if ( $existing_id ) {
            $var = wc_get_product( $existing_id );
        } else {
            $var = new WC_Product_Variation();
            $var->set_parent_id( $parent_id );
        }

        // Attributes
        $var->set_attributes([
            'pa_language' => $attr_lang,
            'pa_format'   => $attr_format,
        ]);

        // Prices & stock
        if ( $price_regular !== '' ) {
            $var->set_regular_price( $price_regular );
        }
        if ( $price_sale !== '' ) {
            $var->set_sale_price( $price_sale );
        } else {
            $var->set_sale_price( '' );
        }
        $var->set_stock_status( $stock_status );

        // Downloadable files
        if ( $file_urls !== '' ) {
            $var->set_downloadable( true );
            $var->set_virtual( true );
            $downloads = [];

            // Support multiple files separated by comma
            $parts = array_map( 'trim', explode( ',', $file_urls ) );
            $i = 1;
            foreach ( $parts as $u ) {
                if ( ! $u ) continue;
                $dl = new WC_Product_Download();
                $dl->set_id( uniqid( 'wrai_', true ) );
                $dl->set_name( ( $row['product_title'] ?? 'file' ) . ' ' . $i );
                $dl->set_file( esc_url_raw( $u ) );
                $downloads[ $dl->get_id() ] = $dl;
                $i++;
            }
            $var->set_downloads( $downloads );
        } else {
            $var->set_downloadable( false );
            $var->set_virtual( false );
            $var->set_downloads( [] );
        }

        $var->set_status( 'publish' );

        $var->save();
        return $var->get_id();
    }

    /** Force parent to variable and sync its children/attributes */
    public static function finalize_variable_parent( $product_id ) {
        if ( ! $product_id ) return;

        // Tipi garanti altına al
        wp_set_object_terms( $product_id, 'variable', 'product_type' );

        // Parent attribute meta'sının kalıcı olduğundan emin ol
        self::ensure_parent_attributes( $product_id );

        // Woo tarafında tüm varyasyon ve attribute senkronizasyonu
        if ( class_exists( 'WC_Product_Variable' ) ) {
            WC_Product_Variable::sync( $product_id );
        }
    }
}
