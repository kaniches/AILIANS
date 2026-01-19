<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ActionPreparer
 *
 * Goal: keep "understanding" (LLM) separated from "execution".
 * This class builds deterministic pending actions from a normalized intent.
 *
 * IMPORTANT:
 * - Does NOT call the Agent.
 * - Does NOT execute side-effects besides persisting pending_action.
 * - Must be conservative & auditable.
 */
class APAI_Brain_Action_Preparer {

    /**
     * Prepare a pending update_product for price.
     */
    public static function prepare_set_price( $product_id, $raw_value_text, $route = 'SemanticDispatch' ) {
        $product_id = intval( $product_id );
        if ( $product_id <= 0 ) { return null; }

        // Normalize user-provided money text conservatively.
        // This build's Normalizer exposes parse_price_number + format_price_for_wc.
        $price_f = APAI_Brain_Normalizer::parse_price_number( $raw_value_text );
        if ( $price_f === null ) { return null; }

        $price_s = APAI_Brain_Normalizer::format_price_for_wc( $price_f );

        $pending_action = array(
            'type'   => 'update_product',
            'action' => array(
                'type'         => 'update_product',
                'product_id'   => $product_id,
                'changes'      => array(
                    'regular_price' => $price_s,
                ),
                'human_summary' => 'Actualizar producto: precio ' . self::format_human_number( $price_f ),
            ),
            'created_at' => time(),
        );

        APAI_Brain_Memory_Store::persist_pending_action( $pending_action );

        $human = 'Dale, preparé el cambio de precio del producto #' . $product_id . ' a ' . $price_s . '.';
        return APAI_Brain_Response_Builder::action_prepared( $pending_action, $human, null, array( 'route' => $route ) );
    }

    /**
     * Prepare a pending update_product for stock.
     */
    public static function prepare_set_stock( $product_id, $raw_value_text, $route = 'SemanticDispatch' ) {
        $product_id = intval( $product_id );
        if ( $product_id <= 0 ) { return null; }

        $stock_i = APAI_Brain_Normalizer::parse_int( $raw_value_text );
        if ( $stock_i === null ) { return null; }
        $stock_i = intval( $stock_i );
        if ( $stock_i < 0 ) { return null; }

        $pending_action = array(
            'type'   => 'update_product',
            'action' => array(
                'type'         => 'update_product',
                'product_id'   => $product_id,
                'changes'      => array(
                    'manage_stock'  => true,
                    'stock_quantity'=> $stock_i,
                ),
                'human_summary' => 'Actualizar producto: stock ' . $stock_i,
            ),
            'created_at' => time(),
        );

        APAI_Brain_Memory_Store::persist_pending_action( $pending_action );

        $human = 'Dale, preparé el cambio de stock del producto #' . $product_id . ' a ' . $stock_i . '.';
        return APAI_Brain_Response_Builder::action_prepared( $pending_action, $human, null, array( 'route' => $route ) );
    }

    private static function format_human_number( $n ) {
        // Keep Spanish-friendly output in summaries (no decimals when integer).
        $f = floatval( $n );
        if ( abs( $f - round( $f ) ) < 0.00001 ) { return strval( intval( round( $f ) ) ); }
        return rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
    }
}
