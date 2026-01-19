<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Query_H_A4 {
    // Signature must accept $wants_count for registry compatibility (PHP 8+).
    public static function matches( $message_norm, $wants_count = false ) {
        return ( strpos( $message_norm, 'sin categoria' ) !== false || strpos( $message_norm, 'sin categoría' ) !== false );
    }
    public static function handle( $raw, $norm, $ctx, $wants_count, $limit ) {
        $wants_full = ( strpos( $norm, 'full' ) !== false || strpos( $norm, 'top' ) !== false || strpos( $norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

        $count = (int) APAI_Catalog_Repository::a4_without_category_including_uncategorized_count();

        if ( $wants_count && ! $wants_full ) {
            $msg = APAI_Query_Presenter::a4_no_category_message( $count, array(), $limit, true );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A4' ) );
        }

        $rows = APAI_Catalog_Repository::a4_without_category_including_uncategorized_list( $limit );
        $msg  = APAI_Query_Presenter::a4_no_category_message( $count, $rows, $limit, false );

        return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A4' ) );
    }
}
