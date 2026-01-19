<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A8 — Salud del catálogo
 *
 * Keep behavior identical to the previous inline QueryFlow implementation.
 */
class APAI_Query_H_A8 {

    public static function matches( $message_norm, $wants_count ) {
        return (
            strpos( $message_norm, 'salud del catalogo' ) !== false ||
            strpos( $message_norm, 'salud catalogo' ) !== false ||
            strpos( $message_norm, 'health' ) !== false
        );
    }

    public static function handle( $message_raw, $message_norm, $context_full, $wants_count, $limit ) {
        $wants_full = (
            strpos( $message_norm, 'full' ) !== false ||
            strpos( $message_norm, 'top' ) !== false ||
            strpos( $message_norm, 'detall' ) !== false ||
            strpos( $message_norm, 'complet' ) !== false
        );

		// UX invariant: "full" shows **top 5** examples per alert.
		$top_limit            = $wants_full ? 5 : 0;
        $low_stock_threshold  = 3;
        $data = APAI_Catalog_Repository::a8_catalog_health( $top_limit, $low_stock_threshold );
		$msg  = APAI_Query_Presenter::a8_catalog_health_message( $data, $wants_full );

        return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array(
            'route'      => 'QueryFlow',
            'query_code' => 'A8',
        ) );
    }
}
