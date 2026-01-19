<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait APAI_Catalog_Repository_A7 {

	/**
	 * A7 — Productos sin stock.
	 *
	 * Prefer the context stock precompute when available.
	 *
	 * @param int $limit
	 * @param array|null $context_full
	 * @return array{total:int,items:array}
	 */
	public static function a7_out_of_stock( $limit = 10, $context_full = null ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) { $limit = 10; }

		// Use precomputed stock lists if present.
		if ( is_array( $context_full ) && isset( $context_full['stock'] ) && is_array( $context_full['stock'] ) ) {
			$raw = isset( $context_full['stock']['out_of_stock'] ) && is_array( $context_full['stock']['out_of_stock'] ) ? $context_full['stock']['out_of_stock'] : array();
			$total = count( $raw );
			$items = array();
			foreach ( array_slice( $raw, 0, $limit ) as $row ) {
				$id = isset( $row['id'] ) ? (int) $row['id'] : ( isset( $row['ID'] ) ? (int) $row['ID'] : 0 );
				$title = isset( $row['title'] ) ? (string) $row['title'] : ( isset( $row['post_title'] ) ? (string) $row['post_title'] : '' );
				if ( $id <= 0 ) { continue; }
				$items[] = array( 'ID' => $id, 'post_title' => $title !== '' ? $title : get_the_title( $id ) );
			}
			return array( 'total' => (int) $total, 'items' => $items );
		}

		// Fallback SQL: WooCommerce stock_status meta.
		global $wpdb;
		$sql = "
			SELECT p.ID, p.post_title
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON (pm.post_id=p.ID AND pm.meta_key='_stock_status')
			WHERE p.post_type='product' AND p.post_status='publish'
			  AND pm.meta_value='outofstock'
			ORDER BY p.ID DESC
		";
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$total = is_array( $rows ) ? count( $rows ) : 0;
		$items = ( $limit > 0 && is_array( $rows ) ) ? array_slice( $rows, 0, $limit ) : array();
		return array( 'total' => (int) $total, 'items' => $items );
	}

	/**
	 * A7 — Low stock list.
	 * Uses precomputed context_full['stock']['low_stock'] when available, otherwise falls back to SQL.
	 *
	 * NOTE: threshold default is 3 to match UI copy ("≤ 3").
	 */
	public static function a7_low_stock( $limit = 10, $threshold = 3, $context_full = null ) {
		// Precomputed format: array of items with {id, title, stock_quantity}.
		if ( is_array( $context_full ) && isset( $context_full['stock'] ) && isset( $context_full['stock']['low_stock'] ) && is_array( $context_full['stock']['low_stock'] ) ) {
			$raw   = $context_full['stock']['low_stock'];
			$total = count( $raw );
			$raw   = array_slice( $raw, 0, (int) $limit );
			$items = array();
			foreach ( $raw as $row ) {
				$items[] = array(
					'id'            => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'post_title'    => isset( $row['title'] ) ? (string) $row['title'] : '',
					'stock_quantity' => isset( $row['stock_quantity'] ) ? (int) $row['stock_quantity'] : ( isset( $row['stock'] ) ? (int) $row['stock'] : null ),
				);
			}
			return array( 'total' => (int) $total, 'items' => $items );
		}

		global $wpdb;
		$limit     = (int) $limit;
		$threshold = (int) $threshold;
		$posts     = $wpdb->posts;
		$postmeta  = $wpdb->postmeta;
		$now       = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"SELECT SQL_CALC_FOUND_ROWS p.ID, p.post_title, CAST(stock.meta_value AS SIGNED) as stock_quantity
			 FROM {$posts} p
			 JOIN {$postmeta} stock ON stock.post_id=p.ID AND stock.meta_key='_stock'
			 LEFT JOIN {$postmeta} manage ON manage.post_id=p.ID AND manage.meta_key='_manage_stock'
			 LEFT JOIN {$postmeta} status ON status.post_id=p.ID AND status.meta_key='_stock_status'
			 WHERE p.post_type='product'
			   AND p.post_status='publish'
			   AND p.post_date <= %s
			   AND (manage.meta_value IS NULL OR manage.meta_value='yes')
			   AND (status.meta_value IS NULL OR status.meta_value='instock')
			   AND CAST(stock.meta_value AS SIGNED) > 0
			   AND CAST(stock.meta_value AS SIGNED) <= %d
			 ORDER BY CAST(stock.meta_value AS SIGNED) ASC, p.ID DESC
			 LIMIT %d",
			$now,
			$threshold,
			$limit
		);

		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		return array( 'total' => $total, 'items' => is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A7 — Backorder list.
	 * Uses precomputed context_full['stock']['on_backorder'] when available, otherwise falls back to SQL.
	 */
	public static function a7_backorder( $limit = 10, $context_full = null ) {
		if ( is_array( $context_full ) && isset( $context_full['stock'] ) && isset( $context_full['stock']['on_backorder'] ) && is_array( $context_full['stock']['on_backorder'] ) ) {
			$raw   = $context_full['stock']['on_backorder'];
			$total = count( $raw );
			$raw   = array_slice( $raw, 0, (int) $limit );
			$items = array();
			foreach ( $raw as $row ) {
				$items[] = array(
					'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'post_title' => isset( $row['title'] ) ? (string) $row['title'] : '',
				);
			}
			return array( 'total' => (int) $total, 'items' => $items );
		}

		global $wpdb;
		$limit    = (int) $limit;
		$posts    = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$now      = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"SELECT SQL_CALC_FOUND_ROWS p.ID, p.post_title
			 FROM {$posts} p
			 JOIN {$postmeta} status ON status.post_id=p.ID AND status.meta_key='_stock_status'
			 WHERE p.post_type='product'
			   AND p.post_status='publish'
			   AND p.post_date <= %s
			   AND status.meta_value='onbackorder'
			 ORDER BY p.ID DESC
			 LIMIT %d",
			$now,
			$limit
		);

		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		return array( 'total' => $total, 'items' => is_array( $rows ) ? $rows : array() );
	}


}
