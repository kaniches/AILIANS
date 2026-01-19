<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Query_H_A7 {

    // Signature must accept $wants_count for registry compatibility (PHP 8+).
    public static function matches( $message_norm, $wants_count = false ) {
        return ( strpos( $message_norm, 'sin stock' ) !== false || strpos( $message_norm, 'bajo stock' ) !== false || strpos( $message_norm, 'backorder' ) !== false );
    }

    public static function handle( $raw, $norm, $ctx, $wants_count, $limit ) {
        $wants_full = ( strpos( $norm, 'full' ) !== false || strpos( $norm, 'top' ) !== false || strpos( $norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

        $mode = 'out';
        if ( strpos( $norm, 'bajo stock' ) !== false ) { $mode = 'low'; }
        if ( strpos( $norm, 'backorder' ) !== false ) { $mode = 'backorder'; }

        if ( $mode === 'low' ) {
            $data  = APAI_Catalog_Repository::a7_low_stock( $limit, 3, $ctx );
            $total = isset( $data['total'] ) ? (int) $data['total'] : 0;
            $rows  = isset( $data['items'] ) ? $data['items'] : array();
            $msg   = $wants_count && ! $wants_full
                ? APAI_Query_Presenter::count_sentence( $total, 'con bajo stock (≤ 3)' )
                : APAI_Query_Presenter::list_with_ids( $total, $rows, $limit, 'con bajo stock (≤ 3)' );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A7' ) );
        }

        if ( $mode === 'backorder' ) {
            $data  = APAI_Catalog_Repository::a7_backorder( $limit, $ctx );
            $total = isset( $data['total'] ) ? (int) $data['total'] : 0;
            $rows  = isset( $data['items'] ) ? $data['items'] : array();
            $msg   = $wants_count && ! $wants_full
                ? APAI_Query_Presenter::count_sentence( $total, 'en backorder' )
                : APAI_Query_Presenter::list_with_ids( $total, $rows, $limit, 'en backorder' );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A7' ) );
        }

        // Default: sin stock
        $data  = APAI_Catalog_Repository::a7_out_of_stock( $limit, $ctx );
        $total = isset( $data['total'] ) ? (int) $data['total'] : 0;
        $rows  = isset( $data['items'] ) ? $data['items'] : array();

        if ( $wants_count && ! $wants_full ) {
            $msg = APAI_Query_Presenter::a7_no_stock_message_count( $total );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A7' ) );
        }

        $msg = APAI_Query_Presenter::a7_no_stock_message( $total, $rows, $limit, false );
        return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A7' ) );
    }
}
