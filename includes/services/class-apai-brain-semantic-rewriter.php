<?php
// Best-effort reformulation layer (parser-only).
// Produces a high-confidence rewritten message string so the semantic interpreter
// can classify actions/queries more reliably. Never creates pending actions.

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Semantic_Rewriter {

    /**
     * Very cheap heuristic to decide if we should even ask the model to rewrite.
     * Keeps reformulation conservative to avoid rewriting normal conversation.
     */
    public static function should_try_rewrite( $message_norm ) {
        $message_norm = is_string( $message_norm ) ? $message_norm : '';
        if ( $message_norm === '' ) { return false; }
        if ( strlen( $message_norm ) > 220 ) { return false; }

        // A rewrite is only helpful if the user message looks like a shop intent.
        if ( preg_match( '/\d/', $message_norm ) ) { return true; }
        if ( preg_match( '/\b(precio|stock|categoria|categor[ií]a|etiqueta|tag|producto|sku|id|ultimo|último|primero)\b/u', $message_norm ) ) {
            return true;
        }
        return false;
    }

    /**
     * @return array|null
     *   { ok: bool, kind: string, confidence: float, rewrite: string }
     */
    public static function rewrite( $message_raw, $message_norm, $store_state ) {
        if ( ! class_exists( 'APAI_Core' ) ) { return null; }

        $message_raw  = is_string( $message_raw ) ? $message_raw : '';
        $message_norm = is_string( $message_norm ) ? $message_norm : '';
        if ( ! self::should_try_rewrite( $message_norm ) ) { return null; }

        $context_lite = array(
            'store_state' => array(
                'has_pending_action' => ! empty( $store_state['pending_action'] ),
                'last_target_product_id' => ! empty( $store_state['last_target_product_id'] ) ? $store_state['last_target_product_id'] : null,
            ),
            'capabilities' => array(
                'actions' => array( 'set_price', 'set_stock' ),
                'selectors' => array( 'last', 'first', 'id', 'sku', 'name' ),
                'notes' => 'Do not invent product ids/names/skus. If missing, keep selector as last/first or leave it implied.',
            ),
        );

        $prompt = "Reescribí el mensaje del usuario en una frase corta y canónica para un parser, sin cambiar el significado.\n" .
            "Reglas: no inventes productos/ids/skus; no agregues acciones nuevas; si falta dato, NO lo inventes.\n" .
            "Devolvé SOLO JSON con: {ok:boolean, kind:string, confidence:number, rewrite:string}.\n" .
            "kind debe ser: action|query|chitchat. rewrite debe ser una sola frase en español.";

        $args = array(
            'feature' => 'brain',
            'action'  => 'semantic_rewrite',
            'timeout' => 30,
            'input'   => array(
                'message' => $message_raw,
                'context_lite' => $context_lite,
                'prompt' => $prompt,
            ),
        );

        $res = APAI_Core::llm_inference( $args );
        if ( ! is_array( $res ) || empty( $res['ok'] ) ) { return null; }
        if ( empty( $res['data'] ) || ! is_string( $res['data'] ) ) { return null; }

        $json = json_decode( $res['data'], true );
        if ( ! is_array( $json ) ) { return null; }

        $ok   = ! empty( $json['ok'] );
        $kind = isset( $json['kind'] ) ? strtolower( trim( (string) $json['kind'] ) ) : '';
        $conf = isset( $json['confidence'] ) ? floatval( $json['confidence'] ) : 0.0;
        $rw   = isset( $json['rewrite'] ) ? trim( (string) $json['rewrite'] ) : '';

        if ( ! $ok ) { return null; }
        if ( $rw === '' ) { return null; }
        if ( strlen( $rw ) > 180 ) { return null; }
        if ( ! in_array( $kind, array( 'action', 'query' ), true ) ) { return null; }
        if ( $conf < 0.75 ) { return null; }

        return array(
            'ok' => true,
            'kind' => $kind,
            'confidence' => $conf,
            'rewrite' => $rw,
        );
    }
}
