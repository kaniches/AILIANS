<?php
/**
 * Context Full builder (F6.OBS)
 *
 * @FLOW Observability
 * @INVARIANT Context Full MUST NEVER be sent to the model.
 * WHY: Context Full may contain large / sensitive debug details.
 *
 * This payload is intended ONLY for human debugging via the Brain debug endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Context_Full {

    /**
     * Build a richer, human-only debug context.
     *
     * IMPORTANT: Keep it bounded. This is not a full catalog dump.
     */
    public static function build( $store_state = array(), $opts = array() ) {
        $store_state = is_array( $store_state ) ? $store_state : array();
        $opts = is_array( $opts ) ? $opts : array();

        $top_limit = isset( $opts['top_limit'] ) ? (int) $opts['top_limit'] : 3;
        if ( $top_limit < 0 ) { $top_limit = 0; }
        if ( $top_limit > 10 ) { $top_limit = 10; }

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

        $catalog_health = null;
        if ( class_exists( 'APAI_Catalog_Repository' ) ) {
            try {
                // NOTE: This is the same bounded data as A8 (counts + top examples).
                $catalog_health = APAI_Catalog_Repository::a8_catalog_health( $top_limit );
            } catch ( \Throwable $e ) {
                $catalog_health = array( 'error' => 'a8_exception', 'message' => $e->getMessage() );
            }
        }

        return array(
            'full_version' => '1.0',
            'note' => 'SOLO DEBUG HUMANO — prohibido enviar este contexto al modelo.',
            'stats' => array(
                'currency'     => self::short_text( $currency, 8 ),
                'currency_sym' => self::short_text( $currency_sym, 16 ),
            ),
            // Store memory is the core of debugging server-side behavior.
            'store_state' => $store_state,
            // Optional: catalog health snapshot (bounded).
            'catalog_health' => $catalog_health,
        );
    }

    public static function to_json( $context_full ) {
        $context_full = is_array( $context_full ) ? $context_full : array();
        $json = wp_json_encode( $context_full, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }
        // Safety bound: debug payload shouldn't explode.
        if ( strlen( $json ) > 35000 ) {
            $minimal = array(
                'full_version' => isset( $context_full['full_version'] ) ? $context_full['full_version'] : '1.0',
                'note'         => isset( $context_full['note'] ) ? $context_full['note'] : '',
                'stats'         => isset( $context_full['stats'] ) ? $context_full['stats'] : array(),
                'store_state'   => isset( $context_full['store_state'] ) ? $context_full['store_state'] : array(),
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
}
