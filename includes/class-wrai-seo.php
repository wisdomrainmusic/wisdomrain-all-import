<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WRAI_SEO {
    public static function apply_rankmath_meta( $post_id, $row ) {
        if ( ! $post_id ) return;
        if ( ! empty( $row['focus_keyword'] ) ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $row['focus_keyword'] ) );
        }
        if ( ! empty( $row['seo_title'] ) ) {
            update_post_meta( $post_id, 'rank_math_title', wp_kses_post( $row['seo_title'] ) );
        }
        if ( ! empty( $row['seo_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_description', wp_kses_post( $row['seo_description'] ) );
        }
        // Short description -> excerpt zaten parent oluştururken yazıldı.
    }
}
