<?php

/**
 * Catalog Repository (read-only)
 *
 * @FLOW QueryFlow
 *
 * WHY: Centralize SQL for A1–A8 queries so REST stays as an orchestrator.
 *
 * @INVARIANT: Read-only. Never mutates products or memory.
 * @INVARIANT: Never creates pending_action.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


require_once __DIR__ . '/traits/trait-apai-catalog-repository-a6.php';
require_once __DIR__ . '/traits/trait-apai-catalog-repository-a7.php';
require_once __DIR__ . '/traits/trait-apai-catalog-repository-a8.php';
class APAI_Catalog_Repository {

    use APAI_Catalog_Repository_A6, APAI_Catalog_Repository_A7, APAI_Catalog_Repository_A8;


	/**
	 * A1 — Products without price.
	 *
	 * @INVARIANT Read-only DB query. No dependency on context_full.
	 */
	public static function a1_without_price_count() {
		global $wpdb;
		$sql = "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} rp
				ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
			LEFT JOIN {$wpdb->postmeta} pp
				ON pp.post_id = p.ID AND pp.meta_key = '_price'
			WHERE p.post_type = 'product'
			  AND p.post_status = 'publish'
			  AND (rp.meta_id IS NULL OR rp.meta_value IS NULL OR rp.meta_value = '')
			  AND (pp.meta_id IS NULL OR pp.meta_value IS NULL OR pp.meta_value = '')
		";
		return (int) $wpdb->get_var( $sql );
	}

	public static function a1_without_price_list( $limit = 10 ) {
		global $wpdb;
		$limit = (int) $limit;
		if ( $limit <= 0 ) { $limit = 10; }
		$sql = $wpdb->prepare(
			"
			SELECT DISTINCT p.ID, p.post_title
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} rp
				ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
			LEFT JOIN {$wpdb->postmeta} pp
				ON pp.post_id = p.ID AND pp.meta_key = '_price'
			WHERE p.post_type = 'product'
			  AND p.post_status = 'publish'
			  AND (rp.meta_id IS NULL OR rp.meta_value IS NULL OR rp.meta_value = '')
			  AND (pp.meta_id IS NULL OR pp.meta_value IS NULL OR pp.meta_value = '')
			ORDER BY p.ID DESC
			LIMIT %d
			",
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

    /**
     * A2 — Products without description.
     */
    public static function a2_without_description_count() {
        global $wpdb;
        $where = "post_type = %s AND post_status = %s AND (post_content IS NULL OR post_content = '') AND (post_excerpt IS NULL OR post_excerpt = '')";
        $sql_count = $wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE {$where}",
            'product',
            'publish'
        );
        return (int) $wpdb->get_var( $sql_count );
    }

    public static function a2_without_description_list( $limit = 10 ) {
        global $wpdb;
        $where = "post_type = %s AND post_status = %s AND (post_content IS NULL OR post_content = '') AND (post_excerpt IS NULL OR post_excerpt = '')";
        $sql_list = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE {$where} ORDER BY ID DESC LIMIT %d",
            'product',
            'publish',
            (int) $limit
        );
        $rows = $wpdb->get_results( $sql_list, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * A3 — Products without SKU.
     */
    public static function a3_without_sku_count() {
        global $wpdb;
        $sql_count = "
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (pm.meta_id IS NULL OR pm.meta_value IS NULL OR pm.meta_value = '')
        ";
        return (int) $wpdb->get_var( $sql_count );
    }

    public static function a3_without_sku_list( $limit = 10 ) {
        global $wpdb;
        $sql_list = $wpdb->prepare(
            "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (pm.meta_id IS NULL OR pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.ID DESC
            LIMIT %d
            ",
            (int) $limit
        );
        $rows = $wpdb->get_results( $sql_list, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * A4 — Products without category.
     */
    public static function a4_without_category_count() {
        global $wpdb;
        $sql = "
            SELECT COUNT(1) FROM (
                SELECT p.ID
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->term_relationships} tr ON (tr.object_id = p.ID)
                LEFT JOIN {$wpdb->term_taxonomy} tt ON (tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy='product_cat')
                WHERE p.post_type='product' AND p.post_status='publish'
                GROUP BY p.ID
                HAVING SUM(CASE WHEN tt.term_taxonomy_id IS NOT NULL THEN 1 ELSE 0 END) = 0
            ) t
        ";
        return (int) $wpdb->get_var( $sql );
    }

    public static function a4_without_category_list( $limit = 10 ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->term_relationships} tr ON (tr.object_id = p.ID)
            LEFT JOIN {$wpdb->term_taxonomy} tt ON (tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy='product_cat')
            WHERE p.post_type='product' AND p.post_status='publish'
            GROUP BY p.ID
            HAVING SUM(CASE WHEN tt.term_taxonomy_id IS NOT NULL THEN 1 ELSE 0 END) = 0
            ORDER BY p.ID DESC
            LIMIT %d
            ",
            (int) $limit
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * A5 — Products without featured image.
     */
    public static function a5_without_featured_image_count() {
        global $wpdb;
        $sql = "
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_th ON (pm_th.post_id=p.ID AND pm_th.meta_key='_thumbnail_id')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND (pm_th.meta_id IS NULL OR pm_th.meta_value IS NULL OR pm_th.meta_value = '' OR pm_th.meta_value = '0')
        ";
        return (int) $wpdb->get_var( $sql );
    }

    public static function a5_without_featured_image_list( $limit = 10 ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_th ON (pm_th.post_id=p.ID AND pm_th.meta_key='_thumbnail_id')
            WHERE p.post_type='product' AND p.post_status='publish'
              AND (pm_th.meta_id IS NULL OR pm_th.meta_value IS NULL OR pm_th.meta_value = '' OR pm_th.meta_value = '0')
            ORDER BY p.ID DESC
            LIMIT %d
            ",
            (int) $limit
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * A4 (legacy-compatible) — Products without category OR only assigned to the default uncategorized term.
     *
     * WHY: Preserve exact behavior used by the original A4 handler ("incluye \"Sin categorizar\"").
     * @INVARIANT: Read-only.
     */
    public static function a4_without_category_including_uncategorized_count() {
        global $wpdb;

        // Count products with NO product_cat terms OR ONLY the default uncategorized slugs.
        $sql_count = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
            GROUP BY p.ID
            HAVING SUM(CASE WHEN t.slug IS NULL THEN 0 ELSE 1 END) = 0
               OR (
                    SUM(CASE WHEN (t.slug = 'sin-categorizar' OR t.slug = 'uncategorized') THEN 1 ELSE 0 END) = COUNT(t.slug)
                  )
        ";
        $rows = $wpdb->get_results( $sql_count );
        return is_array( $rows ) ? (int) count( $rows ) : 0;
    }

    public static function a4_without_category_including_uncategorized_list( $limit = 10 ) {
        global $wpdb;

        $limit = (int) $limit;
        if ( $limit <= 0 ) { $limit = 10; }

        $sql_list = $wpdb->prepare( "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
            GROUP BY p.ID
            HAVING SUM(CASE WHEN t.slug IS NULL THEN 0 ELSE 1 END) = 0
               OR (
                    SUM(CASE WHEN (t.slug = 'sin-categorizar' OR t.slug = 'uncategorized') THEN 1 ELSE 0 END) = COUNT(t.slug)
                  )
            ORDER BY p.ID DESC
            LIMIT %d
        ", $limit );

        $rows = $wpdb->get_results( $sql_list, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

}
