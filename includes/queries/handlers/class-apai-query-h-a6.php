<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Query_H_A6 {

    // Signature must accept $wants_count for registry compatibility (PHP 8+).
    public static function matches( $message_norm, $wants_count = false ) {
        return ( strpos( $message_norm, 'incompleto' ) !== false );
    }

    public static function handle( $raw, $norm, $ctx, $wants_count, $limit ) {
        $wants_full = ( strpos( $norm, 'full' ) !== false || strpos( $norm, 'top' ) !== false || strpos( $norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

        $data  = APAI_Catalog_Repository::a6_incomplete_products( $limit, $ctx );
        $total = isset( $data['total'] ) ? (int) $data['total'] : 0;
        $items = isset( $data['items'] ) ? (array) $data['items'] : array();

        if ( $wants_count && ! $wants_full ) {
            $msg = APAI_Query_Presenter::a6_incomplete_products_message_count( $total );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A6' ) );
        }

        $msg = APAI_Query_Presenter::a6_incomplete_products_message( $total, $items, $limit, false );
        return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A6' ) );
    }
}
