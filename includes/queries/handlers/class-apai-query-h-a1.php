<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A1 — Productos sin precio
 */
class APAI_Query_H_A1 {

    public static function matches( $m_norm, $wants_count ) {
        return ( strpos( $m_norm, 'sin precio' ) !== false || strpos( $m_norm, 'productos sin precio' ) !== false );
    }

    public static function handle( $message_raw, $m_norm, $context_full, $wants_count, $limit ) {
        $wants_full = ( strpos( $m_norm, 'full' ) !== false || strpos( $m_norm, 'top' ) !== false || strpos( $m_norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

		$total = APAI_Catalog_Repository::a1_without_price_count();

        // If user asks count and didn't ask for full list, just count.
        if ( $wants_count && ! $wants_full ) {
            $msg = APAI_Query_Presenter::a1_no_price_message_count( $total );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg );
        }

		$rows = APAI_Catalog_Repository::a1_without_price_list( $limit );
		$has_more = ( (int) $total > (int) count( $rows ) );

		$msg = APAI_Query_Presenter::a1_no_price_message( $total, $rows, $limit, $has_more );
        return APAI_Brain_Response_Builder::make_response( 'consult', $msg );
    }
}
