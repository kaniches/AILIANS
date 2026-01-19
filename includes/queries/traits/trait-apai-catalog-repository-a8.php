<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait APAI_Catalog_Repository_A8 {

    /**
     * A8 — Catalog health data.
     * Returns counts + top examples (raw rows) and computed score.
     *
     * @return array
     */
    public static function a8_catalog_health( $top_limit = 5, $low_threshold_default = 2 ) {
        global $wpdb;

        $top_limit = (int) $top_limit;
        if ( $top_limit < 0 ) { $top_limit = 0; }

        // Total published products (keep same behavior as existing A8)
        $total_products = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );

        // A1–A5
        // A1: without price (both _regular_price and _price empty/null)
        $sql_no_price = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_price ON (pm_price.post_id=p.ID AND pm_price.meta_key='_regular_price')
            LEFT JOIN {$wpdb->postmeta} pm_price2 ON (pm_price2.post_id=p.ID AND pm_price2.meta_key='_price')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND ( (pm_price.meta_value IS NULL OR pm_price.meta_value='') AND (pm_price2.meta_value IS NULL OR pm_price2.meta_value='') )
            ORDER BY p.ID DESC
        ";
        $rows_no_price = $wpdb->get_results( $sql_no_price, ARRAY_A );
        $cnt_no_price  = is_array( $rows_no_price ) ? count( $rows_no_price ) : 0;
        $top_no_price  = ( $top_limit > 0 && $cnt_no_price > 0 ) ? array_slice( $rows_no_price, 0, $top_limit ) : array();

        // A2: without description (post_content empty/null)
        $sql_no_desc = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            WHERE p.post_type='product' AND p.post_status='publish'
              AND (p.post_content IS NULL OR p.post_content='')
            ORDER BY p.ID DESC
        ";
        $rows_no_desc = $wpdb->get_results( $sql_no_desc, ARRAY_A );
        $cnt_no_desc  = is_array( $rows_no_desc ) ? count( $rows_no_desc ) : 0;
        $top_no_desc  = ( $top_limit > 0 && $cnt_no_desc > 0 ) ? array_slice( $rows_no_desc, 0, $top_limit ) : array();

        // A3: without SKU
        $sql_no_sku = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_sku ON (pm_sku.post_id=p.ID AND pm_sku.meta_key='_sku')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND (pm_sku.meta_value IS NULL OR pm_sku.meta_value='')
            ORDER BY p.ID DESC
        ";
        $rows_no_sku = $wpdb->get_results( $sql_no_sku, ARRAY_A );
        $cnt_no_sku  = is_array( $rows_no_sku ) ? count( $rows_no_sku ) : 0;
        $top_no_sku  = ( $top_limit > 0 && $cnt_no_sku > 0 ) ? array_slice( $rows_no_sku, 0, $top_limit ) : array();

        // A4: without category (incluye "Sin categorizar" / categoría por defecto)
		$default_cat_id = (int) get_option( 'default_product_cat', 0 );
		if ( $default_cat_id <= 0 ) {
			$term = get_term_by( 'slug', 'uncategorized', 'product_cat' );
			if ( $term && ! is_wp_error( $term ) && ! empty( $term->term_id ) ) {
				$default_cat_id = (int) $term->term_id;
			}
		}

		if ( $default_cat_id > 0 ) {
			// Contar productos que NO tienen ninguna categoría distinta a las “default”.
			// Para ser consistentes con A4 (“incluye Sin categorizar”), excluimos:
			// - la categoría default configurada, y
			// - “uncategorized” (si difiere).
			$excluded_cat_ids = array( $default_cat_id );
			$unc_term         = get_term_by( 'slug', 'uncategorized', 'product_cat' );
			$unc_term_id      = ( $unc_term && ! is_wp_error( $unc_term ) && ! empty( $unc_term->term_id ) ) ? (int) $unc_term->term_id : 0;
			if ( $unc_term_id > 0 && ! in_array( $unc_term_id, $excluded_cat_ids, true ) ) {
				$excluded_cat_ids[] = $unc_term_id;
			}
			$in_placeholders = implode( ',', array_fill( 0, count( $excluded_cat_ids ), '%d' ) );

			$sql_no_cat = "
				SELECT p.ID, p.post_title
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr ON (tr.object_id=p.ID)
				LEFT JOIN {$wpdb->term_taxonomy} tt ON (tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat')
				WHERE p.post_type='product' AND p.post_status='publish'
				GROUP BY p.ID
				HAVING SUM(CASE WHEN tt.term_id IS NOT NULL AND tt.term_id NOT IN ({$in_placeholders}) THEN 1 ELSE 0 END) = 0
				ORDER BY p.ID DESC
			";
			$sql_no_cat = $wpdb->prepare( $sql_no_cat, $excluded_cat_ids );
        } else {
            // Fallback: productos sin ninguna categoría asignada.
            $sql_no_cat = "
                SELECT p.ID, p.post_title
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->term_relationships} tr ON (tr.object_id=p.ID)
                LEFT JOIN {$wpdb->term_taxonomy} tt ON (tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat')
                WHERE p.post_type='product' AND p.post_status='publish'
                GROUP BY p.ID
                HAVING SUM(CASE WHEN tt.taxonomy='product_cat' THEN 1 ELSE 0 END) = 0
                ORDER BY p.ID DESC
            ";
        }

        $rows_no_cat = $wpdb->get_results( $sql_no_cat, ARRAY_A );
        $cnt_no_cat  = is_array( $rows_no_cat ) ? count( $rows_no_cat ) : 0;
        $top_no_cat  = ( $top_limit > 0 && $cnt_no_cat > 0 ) ? array_slice( $rows_no_cat, 0, $top_limit ) : array();

        // A5: without featured image
        $sql_no_img = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_img ON (pm_img.post_id=p.ID AND pm_img.meta_key='_thumbnail_id')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND (pm_img.meta_value IS NULL OR pm_img.meta_value='')
            ORDER BY p.ID DESC
        ";
        $rows_no_img = $wpdb->get_results( $sql_no_img, ARRAY_A );
        $cnt_no_img  = is_array( $rows_no_img ) ? count( $rows_no_img ) : 0;
        $top_no_img  = ( $top_limit > 0 && $cnt_no_img > 0 ) ? array_slice( $rows_no_img, 0, $top_limit ) : array();

        // A7 Stock (for A8 only)
        $sql_oos = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_ss ON (pm_ss.post_id=p.ID AND pm_ss.meta_key='_stock_status')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND pm_ss.meta_value='outofstock'
            ORDER BY p.ID DESC
        ";
        $rows_oos = $wpdb->get_results( $sql_oos, ARRAY_A );
        $cnt_oos  = is_array( $rows_oos ) ? count( $rows_oos ) : 0;
        $top_oos  = ( $top_limit > 0 && $cnt_oos > 0 ) ? array_slice( $rows_oos, 0, $top_limit ) : array();

        $low_threshold = (int) $low_threshold_default;
        if ( $low_threshold <= 0 ) { $low_threshold = 2; }

        $sql_low = "
            SELECT p.ID, p.post_title, pm_qty.meta_value AS stock_qty
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_qty ON (pm_qty.post_id=p.ID AND pm_qty.meta_key='_stock')
            LEFT JOIN {$wpdb->postmeta} pm_ms ON (pm_ms.post_id=p.ID AND pm_ms.meta_key='_manage_stock')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND pm_ms.meta_value='yes'
              AND pm_qty.meta_value IS NOT NULL AND pm_qty.meta_value<>''
              AND CAST(pm_qty.meta_value AS SIGNED) > 0
              AND CAST(pm_qty.meta_value AS SIGNED) <= {$low_threshold}
            ORDER BY CAST(pm_qty.meta_value AS SIGNED) ASC, p.ID DESC
        ";
        $rows_low = $wpdb->get_results( $sql_low, ARRAY_A );
        $cnt_low  = is_array( $rows_low ) ? count( $rows_low ) : 0;
        $top_low  = ( $top_limit > 0 && $cnt_low > 0 ) ? array_slice( $rows_low, 0, $top_limit ) : array();

        $sql_bo = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_ss ON (pm_ss.post_id=p.ID AND pm_ss.meta_key='_stock_status')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND pm_ss.meta_value='onbackorder'
            ORDER BY p.ID DESC
        ";
        $rows_bo = $wpdb->get_results( $sql_bo, ARRAY_A );
        $cnt_bo  = is_array( $rows_bo ) ? count( $rows_bo ) : 0;
        $top_bo  = ( $top_limit > 0 && $cnt_bo > 0 ) ? array_slice( $rows_bo, 0, $top_limit ) : array();

        // Score
        $score_total = 100;
        if ( $total_products > 0 ) {
            $r_price = min( 1.0, $cnt_no_price / $total_products );
            $r_desc  = min( 1.0, $cnt_no_desc  / $total_products );
            $r_img   = min( 1.0, $cnt_no_img   / $total_products );
            $r_cat   = min( 1.0, $cnt_no_cat   / $total_products );
            $r_sku   = min( 1.0, $cnt_no_sku   / $total_products );

            $data_pen   = ( 0.30 * $r_price ) + ( 0.25 * $r_desc ) + ( 0.15 * $r_img ) + ( 0.15 * $r_cat ) + ( 0.15 * $r_sku );
            $score_data = (int) round( 100 * ( 1.0 - $data_pen ) );

            $r_oos = min( 1.0, $cnt_oos / $total_products );
            $r_low = min( 1.0, $cnt_low / $total_products );
            $r_bo  = min( 1.0, $cnt_bo  / $total_products );

            $stock_pen    = ( 0.50 * $r_oos ) + ( 0.30 * $r_low ) + ( 0.20 * $r_bo );
            $score_stock  = (int) round( 100 * ( 1.0 - $stock_pen ) );

            $score_total = (int) round( 0.70 * $score_data + 0.30 * $score_stock );
            if ( $score_total < 0 ) { $score_total = 0; }
            if ( $score_total > 100 ) { $score_total = 100; }
        }

        return array(
            'total_products' => (int) $total_products,
            'low_threshold'  => (int) $low_threshold,
            'score'          => (int) $score_total,
            'counts'         => array(
                'no_price'         => (int) $cnt_no_price,
                'no_description'   => (int) $cnt_no_desc,
                'no_sku'           => (int) $cnt_no_sku,
                'no_category'      => (int) $cnt_no_cat,
                'no_featured_image'=> (int) $cnt_no_img,
                'out_of_stock'     => (int) $cnt_oos,
                'low_stock'        => (int) $cnt_low,
                'backorder'        => (int) $cnt_bo,
            ),
            'top'            => array(
                'no_price'          => $top_no_price,
                'no_description'    => $top_no_desc,
                'no_sku'            => $top_no_sku,
                'no_category'       => $top_no_cat,
                'no_featured_image' => $top_no_img,
                'out_of_stock'      => $top_oos,
                'low_stock'         => $top_low,
                'backorder'         => $top_bo,
            ),
        );
    }

}
