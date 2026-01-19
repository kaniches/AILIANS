<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Product search (admin-only)
 *
 * WHY: The target selector UI needs to browse large catalogs without sending
 * huge payloads in a single chat response.
 *
 * @INVARIANT: Read-only. Never mutates products or memory.
 */
class APAI_Brain_Product_Search {

    /**
     * Search published products by title (LIKE), with pagination.
     *
     * @param string $q
     * @param int $limit
     * @param int $offset
     * @return array{total:int, items:array<int, array{id:int,title:string,sku:string,price:string,thumb_url:string,categories:array<int,string>}>}
     */
    public static function search_by_title_like( $q, $limit = 20, $offset = 0 ) {
        global $wpdb;

        $q = sanitize_text_field( (string) $q );
        $limit = (int) $limit;
        $offset = (int) $offset;

        if ( $limit <= 0 ) { $limit = 20; }
        if ( $limit > 100 ) { $limit = 100; }
        if ( $offset < 0 ) { $offset = 0; }

        // Empty query: return latest products (still paginated).
        $where = "post_type='product' AND post_status='publish'";
        $params = array();
        if ( $q !== '' ) {
            $where .= ' AND post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $q ) . '%';
        }

        // Total
        $sql_total = 'SELECT COUNT(ID) FROM ' . $wpdb->posts . ' WHERE ' . $where;
        if ( ! empty( $params ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $params ) );
        } else {
            $total = (int) $wpdb->get_var( $sql_total );
        }

        // Items
        $sql_items = 'SELECT ID, post_title FROM ' . $wpdb->posts . ' WHERE ' . $where . ' ORDER BY ID DESC LIMIT %d OFFSET %d';
        $params_items = $params;
        $params_items[] = $limit;
        $params_items[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( $sql_items, $params_items ), ARRAY_A );

        $items = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $id = isset( $r['ID'] ) ? (int) $r['ID'] : 0;
                if ( $id <= 0 ) { continue; }
                $title = isset( $r['post_title'] ) ? (string) $r['post_title'] : '';
                $title = trim( wp_strip_all_tags( $title ) );

                $sku = '';
                $price = '';
                $thumb_url = '';
                $cats = array();
                if ( function_exists( 'wc_get_product' ) ) {
                    $p = wc_get_product( $id );
                    if ( $p ) {
                        $sku = (string) $p->get_sku();
                        $price = (string) $p->get_price();
                        $thumb_url = self::get_product_thumb_url( $p );
                        $cats = self::get_product_category_names( $id );
                    }
                }

                $items[] = array(
                    'id'    => $id,
                    'title' => ( $title !== '' ? $title : ( 'Producto #' . $id ) ),
                    'sku'   => $sku,
                    'price' => $price,
                    'thumb_url'  => $thumb_url,
                    'categories' => $cats,
                );
            }
        }

        return array(
            'total' => $total,
            'items' => $items,
        );
    }

    /** UI-only: thumbnail URL for a product (safe, read-only). */
    private static function get_product_thumb_url( $product ) {
        try {
            if ( ! $product ) { return ''; }
            $image_id = (int) $product->get_image_id();
            if ( $image_id > 0 ) {
                $url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
                return is_string( $url ) ? $url : '';
            }
            if ( function_exists( 'wc_placeholder_img_src' ) ) {
                return (string) wc_placeholder_img_src( 'thumbnail' );
            }
        } catch ( \Throwable $e ) {
            return '';
        }
        return '';
    }

    /** UI-only: category names for a product. */
    private static function get_product_category_names( $product_id ) {
        try {
            $names = array();
            $terms = get_the_terms( (int) $product_id, 'product_cat' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $t ) {
                    if ( isset( $t->name ) && $t->name !== '' ) {
                        $names[] = (string) $t->name;
                    }
                }
            }
            return $names;
        } catch ( \Throwable $e ) {
            return array();
        }
    }
}
