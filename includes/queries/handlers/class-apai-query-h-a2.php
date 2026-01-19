<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A2 — Productos sin descripción
 */
class APAI_Query_H_A2 {

    public static function matches( $m_norm, $wants_count ) {
        return ( strpos( $m_norm, 'sin descripcion' ) !== false || strpos( $m_norm, 'sin descripción' ) !== false );
    }

    public static function handle( $message_raw, $m_norm, $context_full, $wants_count, $limit ) {
        $wants_full = ( strpos( $m_norm, 'full' ) !== false || strpos( $m_norm, 'top' ) !== false || strpos( $m_norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

		$count = (int) APAI_Catalog_Repository::a2_without_description_count();

		if ( $wants_count && ! $wants_full ) {
			$msg = APAI_Query_Presenter::a2_no_description_message_count( $count );
			return APAI_Brain_Response_Builder::make_response( 'consult', $msg );
		}

		$items = APAI_Catalog_Repository::a2_without_description_list( $limit );
		$msg   = APAI_Query_Presenter::a2_no_description_message( $count, $items, $limit, false );
		return APAI_Brain_Response_Builder::make_response( 'consult', $msg );
    }
}
