<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * No-op detection for product updates.
 *
 * Goal: avoid creating a pending action when the requested change would not
 * actually modify the product (e.g., setting the same price/stock again).
 */
class APAI_Brain_NoOp {

    /**
     * Filters a change-set against the current product state.
     *
     * @param int   $product_id WooCommerce product id.
     * @param array $changes    Desired changes (e.g., ['regular_price' => '10.00']).
     *
     * @return array{
     *   noop: bool,
     *   changes: array,
     *   dropped: string[],
     *   current: array
     * }
     */
    public static function filter_product_changes( $product_id, $changes ) {
        $result = array(
            'noop'    => false,
            'changes' => is_array( $changes ) ? $changes : array(),
            'dropped' => array(),
            'current' => array(),
        );

        if ( ! function_exists( 'wc_get_product' ) ) {
            return $result;
        }

        $product = wc_get_product( (int) $product_id );
        if ( ! $product ) {
            return $result;
        }

        $filtered = $result['changes'];

        // PRICE
        if ( isset( $filtered['regular_price'] ) ) {
            $desired_raw  = (string) $filtered['regular_price'];
            $current_raw  = (string) $product->get_regular_price();
            $desired_norm = number_format( (float) $desired_raw, 2, '.', '' );
            $current_norm = number_format( (float) $current_raw, 2, '.', '' );

            $result['current']['regular_price'] = $current_norm;

            if ( $desired_norm === $current_norm ) {
                unset( $filtered['regular_price'] );
                $result['dropped'][] = 'regular_price';
            } else {
                $filtered['regular_price'] = $desired_norm;
            }
        }

        // STOCK (only treat as no-op when manage_stock is true already and qty matches)
        $has_stock_fields = isset( $filtered['stock_quantity'] ) || isset( $filtered['manage_stock'] );
        if ( $has_stock_fields ) {
            $desired_manage = isset( $filtered['manage_stock'] ) ? (bool) $filtered['manage_stock'] : null;
            $desired_qty    = isset( $filtered['stock_quantity'] ) ? (int) $filtered['stock_quantity'] : null;

            $current_manage = (bool) $product->get_manage_stock();
            $current_qty    = (int) $product->get_stock_quantity();

            $result['current']['manage_stock']   = $current_manage;
            $result['current']['stock_quantity'] = $current_qty;

            if ( true === $desired_manage && null !== $desired_qty ) {
                if ( true === $current_manage && $desired_qty === $current_qty ) {
                    // Both fields become no-ops.
                    unset( $filtered['manage_stock'] );
                    unset( $filtered['stock_quantity'] );
                    $result['dropped'][] = 'manage_stock';
                    $result['dropped'][] = 'stock_quantity';
                }
            }
        }

        $result['changes'] = $filtered;
        $result['noop']    = empty( $filtered );

        return $result;
    }
}
