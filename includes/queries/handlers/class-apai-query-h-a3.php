<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A3 — Productos sin SKU
 */
class APAI_Query_H_A3 {

    public static function matches( $m_norm, $wants_count ) {
        return ( strpos( $m_norm, 'sin sku' ) !== false || strpos( $m_norm, 'sin SKU' ) !== false );
    }

    public static function handle( $message_raw, $m_norm, $context_full, $wants_count, $limit ) {
        $wants_full = ( strpos( $m_norm, 'full' ) !== false || strpos( $m_norm, 'top' ) !== false || strpos( $m_norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

		$count = (int) APAI_Catalog_Repository::a3_without_sku_count();
		if ( $wants_count && ! $wants_full ) {
			$msg = APAI_Query_Presenter::a3_no_sku_message_count( $count );
			return APAI_Brain_Response_Builder::make_response( 'consult', $msg );
		}

		$items = APAI_Catalog_Repository::a3_without_sku_list( $limit );
		$msg   = APAI_Query_Presenter::a3_no_sku_message( $count, $items, $limit, false );
		return APAI_Brain_Response_Builder::make_response( 'consult', $msg );
    }
}
