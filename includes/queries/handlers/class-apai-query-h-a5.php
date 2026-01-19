<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Query_H_A5 {
    // Signature must accept $wants_count for registry compatibility (PHP 8+).
    public static function matches( $message_norm, $wants_count = false ) {
        return ( strpos( $message_norm, 'sin imagen' ) !== false );
    }
    public static function handle( $raw, $norm, $ctx, $wants_count, $limit ) {
        $wants_full = ( strpos( $norm, 'full' ) !== false || strpos( $norm, 'top' ) !== false || strpos( $norm, 'detall' ) !== false );
        $limit      = $wants_full ? 50 : 5;

        $count = (int) APAI_Catalog_Repository::a5_without_featured_image_count();
        if ( $wants_count && ! $wants_full ) {
            $msg = APAI_Query_Presenter::a5_no_image_message_count( $count );
            return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A5' ) );
        }

        $rows = APAI_Catalog_Repository::a5_without_featured_image_list( $limit );
        $msg  = APAI_Query_Presenter::a5_no_image_message( $count, $rows, $limit, false );

        return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'query' => 'A5' ) );
    }
}
