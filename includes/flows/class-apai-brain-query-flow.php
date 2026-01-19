<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * QueryFlow
 *
 * Responsibility: route A1–A8 read-only catalog queries (no pending, no memory mutation).
 * The actual query implementations live in QueryRegistry + per-query handlers.
 */
class APAI_Brain_Query_Flow {

    /**
     * @return array|null Response payload (Brain) or null if not a query.
     */
    public static function try_handle( $message_raw, $message_norm, $context_full = null, $store_state = null ) {
        $m_norm = (string) $message_norm;

	        // Quick help for extremely short messages that would otherwise fall into ModelFlow.
	        $m_trim = trim( $m_norm );
	        if ( $m_trim === 'precio' ) {
	            return APAI_Brain_Response_Builder::make_response(
	                'message',
	                "¿Querés consultar o cambiar precios?\n\n• Para *cambiar*: `cambiá el precio del último a 2500`\n• Para *consultar*: `¿cuántos productos tengo sin precio?`",
	                array( 'flow' => 'QueryFlow_help', 'topic' => 'precio' )
	            );
	        }
	        if ( $m_trim === 'stock' ) {
	            return APAI_Brain_Response_Builder::make_response(
	                'message',
	                "¿Querés ver o cambiar stock?\n\n• Para *cambiar*: `cambiá el stock del último a 5`\n• Para *consultar*: `¿cuántos productos tengo sin stock?`",
	                array( 'flow' => 'QueryFlow_help', 'topic' => 'stock' )
	            );
	        }

        // Guard: avoid treating action intents as queries.
        // WHY: product names can include query-like tokens (e.g. "sin imagen") and we must not hijack
        // an explicit update like "cambiá el precio del producto \"Producto 2 sin imagen\" a 1200".
        // @INVARIANT Queries stay read-only; updates must route to Deterministic/Targeted flows.
        if ( class_exists( 'APAI_Patterns' ) ) {
            $lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $m_norm, 'UTF-8' ) : strtolower( $m_norm );
            $looks_like_update = (
                preg_match( APAI_Patterns::ACTION_VERB_STRICT, $lc )
                && ( preg_match( APAI_Patterns::PRICE_WORD_STRICT, $lc ) || preg_match( APAI_Patterns::STOCK_WORD_STRICT, $lc ) )
                && preg_match( APAI_Patterns::NUMBER_CAPTURE_STRICT, (string) $message_raw )
            );
            if ( $looks_like_update ) {
                return null;
            }


// Guard: if we are waiting for a targeted product selection (2–5 candidates),
// do NOT hijack the follow-up answer as a query (product titles can contain tokens like "sin imagen").
if ( is_array( $store_state ) ) {
    $has_follow = ( isset( $store_state['pending_targeted_update'] ) && is_array( $store_state['pending_targeted_update'] ) );
    $has_sel    = ( isset( $store_state['pending_target_selection'] ) && is_array( $store_state['pending_target_selection'] ) );
    if ( $has_follow || $has_sel ) {
        $raw = trim( (string) $message_raw );
        $lc_raw = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );

        $looks_like_answer = (
            preg_match( '/^(?:#\s*)?\d{1,10}$/u', $raw ) // "#23" or "23"
            || preg_match( '/^id\s*[:#]?\s*\d{1,10}$/iu', $raw )
            || preg_match( '/^sku\s*[:#]?\s*[A-Za-z0-9_\-\.]{2,64}$/iu', $raw )
            || preg_match( '/^(?:el\s+)?(primero|primer|segundo|tercero|cuarto|quinto|[1-5])$/iu', $lc_raw )
        );

        if ( ! $looks_like_answer && $has_sel && isset( $store_state['pending_target_selection']['candidates'] ) && is_array( $store_state['pending_target_selection']['candidates'] ) ) {
            foreach ( $store_state['pending_target_selection']['candidates'] as $c ) {
                if ( ! is_array( $c ) ) { continue; }
                $t = isset( $c['title'] ) ? trim( (string) $c['title'] ) : '';
                if ( $t === '' ) { continue; }
                $t_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
                if ( $t_lc === $lc_raw ) {
                    $looks_like_answer = true;
                    break;
                }
            }
        }

        if ( $looks_like_answer ) {
            return null;
        }
    }
}

        }

        // Keep match heuristics unchanged (move-only). Registry enforces the same rules.
        $is_query = (
            strpos( $m_norm, 'productos sin' ) !== false ||
            strpos( $m_norm, 'producto sin' ) !== false ||
            strpos( $m_norm, 'sin precio' ) !== false ||
            strpos( $m_norm, 'sin descripcion' ) !== false ||
            strpos( $m_norm, 'sin descripción' ) !== false ||
            strpos( $m_norm, 'sin sku' ) !== false ||
            strpos( $m_norm, 'sin categoria' ) !== false ||
            strpos( $m_norm, 'sin categoría' ) !== false ||
			// Accept shorthand "sin imagen" but avoid "en galeria" (handled by other flows).
			( strpos( $m_norm, 'sin imagen' ) !== false && strpos( $m_norm, 'galeria' ) === false && strpos( $m_norm, 'galería' ) === false ) ||
            strpos( $m_norm, 'sin imagen destacada' ) !== false ||
            strpos( $m_norm, 'sin stock' ) !== false ||
			strpos( $m_norm, 'bajo stock' ) !== false ||
			strpos( $m_norm, 'stock bajo' ) !== false ||
			strpos( $m_norm, 'low stock' ) !== false ||
			strpos( $m_norm, 'backorder' ) !== false ||
			strpos( $m_norm, 'en backorder' ) !== false ||
			strpos( $m_norm, 'incompleto' ) !== false ||
			strpos( $m_norm, 'incompletos' ) !== false ||
            strpos( $m_norm, 'salud del catalogo' ) !== false ||
            strpos( $m_norm, 'salud del catálogo' ) !== false ||
            strpos( $m_norm, 'catalog health' ) !== false
        );

        if ( ! $is_query ) {
            return null;
        }

        $wants_count = ( strpos( $m_norm, 'cuant' ) !== false || strpos( $m_norm, 'count' ) !== false );
        $limit       = 10;

        require_once APAI_BRAIN_PATH . 'includes/queries/class-apai-query-registry.php';
        return APAI_Query_Registry::try_handle( $message_raw, $m_norm, $context_full, $wants_count, $limit );
    }
}
