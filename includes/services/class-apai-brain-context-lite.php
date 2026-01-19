<?php
/**
 * Context Lite builder (F6.2)
 *
 * @FLOW Brain
 * @INVARIANT Context Lite is the ONLY context that may be sent to the model.
 * WHY: Keep prompts small, stable, cheap, and safe.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Context_Lite {

    /**
     * Build a minimal, structured Context Lite payload.
     *
     * IMPORTANT: Do NOT include full catalog dumps, long lists, or pending_target_selection candidates.
     */
    public static function build( $store_state = array() ) {
        $store_state = is_array( $store_state ) ? $store_state : array();

        $last = null;
        if ( isset( $store_state['last_product'] ) && is_array( $store_state['last_product'] ) ) {
            $lp = $store_state['last_product'];
            $last = array(
                'id'        => isset( $lp['id'] ) ? intval( $lp['id'] ) : 0,
                'name'      => isset( $lp['name'] ) ? self::short_text( $lp['name'], 140 ) : '',
                'permalink' => isset( $lp['permalink'] ) ? esc_url_raw( $lp['permalink'] ) : '',
            );
            if ( $last['id'] <= 0 ) { $last = null; }
        }

        $has_pending_action = ! empty( $store_state['pending_action'] );
        $has_target_sel     = ! empty( $store_state['pending_target_selection'] );

        $currency = '';
        if ( function_exists( 'get_woocommerce_currency' ) ) {
            $currency = (string) get_woocommerce_currency();
        }
        if ( $currency === '' ) {
            $currency = (string) get_option( 'woocommerce_currency', 'USD' );
        }
        $currency_sym = '';
        if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
            $currency_sym = (string) get_woocommerce_currency_symbol( $currency );
        }
        if ( $currency_sym === '' ) {
            $currency_sym = '$';
        }

        $total_products = self::get_total_published_products_cached();

        return array(
            'lite_version' => '1.0',
            'stats' => array(
                'total_products' => (int) $total_products,
                'currency'       => self::short_text( $currency, 8 ),
                'currency_sym'   => self::short_text( $currency_sym, 16 ),
            ),
            'store_state' => array(
                // Booleans only. The UI receives the full store_state separately via ResponseBuilder.
                'has_pending_action'          => (bool) $has_pending_action,
                'has_pending_target_selection'=> (bool) $has_target_sel,
                // Null when unknown/empty to avoid misleading "0" IDs in Lite.
                'last_target_product_id'      => ( isset( $store_state['last_target_product_id'] ) && intval( $store_state['last_target_product_id'] ) > 0 )
                    ? intval( $store_state['last_target_product_id'] )
                    : null,
                'last_action_kind'            => isset( $store_state['last_action_kind'] ) ? self::short_text( $store_state['last_action_kind'], 24 ) : '',
                'last_product'                => $last,
            ),
            'flags' => array(
                'has_last_product' => (bool) ( is_array( $last ) && ! empty( $last['id'] ) ),
                'has_pending_action' => (bool) $has_pending_action,
                'has_pending_target_selection' => (bool) $has_target_sel,
            ),
        );
    }

    /**
     * Encode as JSON string for prompt usage.
     */
    public static function to_json( $context_lite ) {
        $context_lite = is_array( $context_lite ) ? $context_lite : array();
        $json = wp_json_encode( $context_lite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }
        // Safety: keep it small. If it grows, we prefer dropping extra fields.
        if ( strlen( $json ) > 2500 ) {
            $minimal = array(
                'lite_version' => isset( $context_lite['lite_version'] ) ? $context_lite['lite_version'] : '1.0',
                'stats'        => isset( $context_lite['stats'] ) ? $context_lite['stats'] : array(),
                'store_state'  => isset( $context_lite['store_state'] ) ? $context_lite['store_state'] : array(),
                'flags'        => isset( $context_lite['flags'] ) ? $context_lite['flags'] : array(),
            );
            $json2 = wp_json_encode( $minimal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( is_string( $json2 ) && $json2 !== '' ) {
                $json = $json2;
            }
        }
        return $json;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function short_text( $s, $max = 120 ) {
        $s = is_string( $s ) ? $s : ( is_scalar( $s ) ? strval( $s ) : '' );
        $s = wp_strip_all_tags( $s );
        $s = trim( preg_replace( '/\s+/u', ' ', $s ) );
        if ( $max > 0 && strlen( $s ) > $max ) {
            $s = substr( $s, 0, $max - 1 ) . '…';
        }
        return $s;
    }

    /**
     * Lightweight count of published products with short caching.
     * WHY: Avoid doing the COUNT query on every message.
     */
    private static function get_total_published_products_cached() {
        $blog_id = function_exists( 'get_current_blog_id' ) ? intval( get_current_blog_id() ) : 1;
        $key = 'apai_brain_total_products_' . $blog_id;
        $cached = get_transient( $key );
        if ( is_numeric( $cached ) ) {
            return (int) $cached;
        }
        global $wpdb;
        $total = 0;
        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->posts ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
        }
        set_transient( $key, $total, 5 * MINUTE_IN_SECONDS );
        return $total;
    }
}
