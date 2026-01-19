<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait APAI_Catalog_Repository_A6 {

    /**
     * A6 — Incomplete products (legacy-compatible rules).
     *
     * @return array{total:int, items:array}
     */
    public static function a6_incomplete_products( $only_count = false, $candidate_limit = 500, $list_limit = 50 ) {
        global $wpdb;

        $only_count      = (bool) $only_count;
        $candidate_limit = (int) $candidate_limit;
        $list_limit      = (int) $list_limit;
        if ( $candidate_limit <= 0 ) { $candidate_limit = 500; }
        if ( $list_limit <= 0 ) { $list_limit = 50; }

        $sql_base = "
            SELECT
                p.ID AS id,
                MAX(CASE WHEN tt_cat.term_taxonomy_id IS NULL THEN 0 ELSE 1 END) AS has_cat,
                MAX(CASE
                    WHEN pm_thumb.meta_id IS NULL THEN 0
                    WHEN pm_thumb.meta_value = '' OR pm_thumb.meta_value = '0' THEN 0
                    ELSE 1 END
                ) AS has_thumb,
                MAX(CASE
                    WHEN pm_price.meta_id IS NULL THEN 0
                    WHEN pm_price.meta_value = '' OR pm_price.meta_value = '0' THEN 0
                    ELSE 1 END
                ) AS has_price,
                CASE
                    WHEN p.post_content IS NULL OR TRIM(p.post_content) = '' THEN 0
                    ELSE 1 END AS has_desc,
                MAX(CASE WHEN ttype.slug = 'variable' THEN 1 ELSE 0 END) AS is_variable
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->term_relationships} tr_cat
                ON tr_cat.object_id = p.ID
            LEFT JOIN {$wpdb->term_taxonomy} tt_cat
                ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'product_cat'
            LEFT JOIN {$wpdb->term_relationships} tr_type
                ON tr_type.object_id = p.ID
            LEFT JOIN {$wpdb->term_taxonomy} tt_type
                ON tt_type.term_taxonomy_id = tr_type.term_taxonomy_id AND tt_type.taxonomy = 'product_type'
            LEFT JOIN {$wpdb->terms} ttype
                ON ttype.term_id = tt_type.term_id
            LEFT JOIN {$wpdb->postmeta} pm_thumb
                ON pm_thumb.post_id = p.ID AND pm_thumb.meta_key = '_thumbnail_id'
            LEFT JOIN {$wpdb->postmeta} pm_price
                ON pm_price.post_id = p.ID AND pm_price.meta_key = '_price'
            WHERE p.post_type = 'product' AND p.post_status = 'publish'
            GROUP BY p.ID
            HAVING (has_cat = 0 OR has_thumb = 0 OR has_price = 0 OR has_desc = 0 OR is_variable = 1)
            ORDER BY p.ID DESC
        ";

        if ( $only_count ) {
            $rows = $wpdb->get_results( $sql_base );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql_base . ' LIMIT %d', $candidate_limit ) );
        }

        $items = array();
        $total = 0;

        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $pid = isset( $r->id ) ? (int) $r->id : 0;
                if ( $pid <= 0 ) { continue; }

                $reasons = array();

                if ( isset( $r->has_cat ) && (int) $r->has_cat === 0 ) {
                    $reasons[] = 'no_category';
                }
                if ( isset( $r->has_thumb ) && (int) $r->has_thumb === 0 ) {
                    $reasons[] = 'no_featured_image';
                }
                if ( isset( $r->has_price ) && (int) $r->has_price === 0 ) {
                    $reasons[] = 'no_price';
                }
                if ( isset( $r->has_desc ) && (int) $r->has_desc === 0 ) {
                    $reasons[] = 'no_description';
                }

                $is_variable = ( isset( $r->is_variable ) && (int) $r->is_variable === 1 );

                // Extra rules ONLY for variable products (keep legacy behavior)
                if ( $is_variable ) {
                    $var_count = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(1) FROM {$wpdb->posts} v
                         WHERE v.post_type = 'product_variation'
                           AND v.post_status = 'publish'
                           AND v.post_parent = %d",
                        $pid
                    ) );

                    if ( $var_count <= 0 ) {
                        $reasons[] = 'no_variations';
                    } else {
                        $attrs = get_post_meta( $pid, '_product_attributes', true );
                        $expected_attr_meta_keys = array();

                        if ( is_array( $attrs ) ) {
                            foreach ( $attrs as $attr_key => $attr_def ) {
                                $is_var = false;
                                if ( is_array( $attr_def ) ) {
                                    if ( isset( $attr_def['is_variation'] ) && (int) $attr_def['is_variation'] === 1 ) {
                                        $is_var = true;
                                    } elseif ( isset( $attr_def['variation'] ) && (int) $attr_def['variation'] === 1 ) {
                                        $is_var = true;
                                    }
                                }
                                if ( $is_var ) {
                                    $expected_attr_meta_keys[] = 'attribute_' . sanitize_key( $attr_key );
                                }
                            }
                        }

                        if ( ! empty( $expected_attr_meta_keys ) ) {
                            $or_parts = array();
                            foreach ( $expected_attr_meta_keys as $mk ) {
                                $or_parts[] = $wpdb->prepare(
                                    "(NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = v.ID AND m.meta_key = %s)
                                      OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id = v.ID AND m2.meta_key = %s AND (m2.meta_value IS NULL OR m2.meta_value = '')))",
                                    $mk,
                                    $mk
                                );
                            }

                            if ( ! empty( $or_parts ) ) {
                                $sql_bad = "
                                    SELECT v.ID
                                    FROM {$wpdb->posts} v
                                    WHERE v.post_type = 'product_variation'
                                      AND v.post_status = 'publish'
                                      AND v.post_parent = %d
                                      AND ( " . implode( ' OR ', $or_parts ) . " )
                                    LIMIT 1
                                ";
                                $bad_id = (int) $wpdb->get_var( $wpdb->prepare( $sql_bad, $pid ) );
                                if ( $bad_id > 0 ) {
                                    $reasons[] = 'invalid_variation_combinations';
                                }
                            }
                        }
                    }
                }

                if ( empty( $reasons ) ) {
                    continue;
                }

                $total++;

                if ( ! $only_count ) {
                    $items[] = array(
                        'id'        => $pid,
                        'name'      => get_the_title( $pid ),
                        'title'     => get_the_title( $pid ),
                        'permalink' => get_permalink( $pid ),
                        'type'      => $is_variable ? 'variable' : 'simple',
                        'reasons'   => array_values( array_unique( $reasons ) ),
                    );

                    if ( count( $items ) >= $list_limit ) {
                        break;
                    }
                }
            }
        }

        return array(
            'total' => (int) $total,
            'items' => $items,
        );
    }


}
