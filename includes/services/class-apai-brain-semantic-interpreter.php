<?php
/**
 * SemanticInterpreter — LLM-based semantic normalization (parser-only).
 *
 * @FLOW SemanticInterpreter
 * @INVARIANT Full context NEVER reaches the model. Only Context Lite.
 * @INVARIANT This class MUST NOT execute actions and MUST NOT persist pending.
 *            It only returns a normalized intent structure (or null).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Semantic_Interpreter {

    /**
     * Interpret user message into a validated normalized intent.
     *
     * Returns the output of APAI_Brain_Intent_Validator::validate (kind: action|query|clarify|chitchat|unknown)
     * or null on failure.
     *
     * @param string $message_raw
     * @param string $message_norm
     * @param array  $store_state
     * @return array|null
     */
    public static function interpret( $message_raw, $message_norm, $store_state ) {
        // Requires Core in SaaS mode.
        if ( ! class_exists( 'APAI_Core' ) || ! method_exists( 'APAI_Core', 'llm_inference' ) ) {
            self::trace_semantic( false, null, 'core_missing', null );
            return null;
        }

        // Build Context Lite ONLY.
        $lite = class_exists( 'APAI_Brain_Context_Lite' ) ? APAI_Brain_Context_Lite::build( $store_state ) : array();

        // Keep schema explicit and stable (auditable contract).
        $prompt_schema = array(
            'schema_version' => '1.0',
            'kind' => 'action|query|chitchat|unknown',
            'confidence' => 0.0,
            'needs_clarification' => false,
            'clarify' => array( 'question' => '', 'options' => array() ),
            'action' => array(
                'intent' => 'set_price|set_stock',
                'field' => 'price|stock',
                'selector' => array( 'type' => 'last|first|id|sku|name|unknown', 'value' => '' ),
                'raw_value_text' => '',
            ),
            'query' => array(
                'code' => 'A1|A2|A3|A4|A5|A6|A7|A8',
                'mode' => 'summary|full|top5',
            ),
        );

        $sys = "Sos un parser de intención para AutoProduct AI. Respondé SOLO JSON válido (sin markdown, sin texto extra).\n"
            . 'Schema v1.0 (respetar keys): ' . wp_json_encode( $prompt_schema ) . "\n"
            . "Reglas estrictas:\n"
            . "1) Si el usuario pide cambiar precio/stock y hay valor numérico explícito, devolvé kind=action con intent, field, selector y raw_value_text.\n"
            . "2) Si el usuario dice \"último/primero\" o \"#ID\" o \"SKU\", eso define el selector. No inventes selectores.\n"
            . "3) Confidence: usá un número realista entre 0 y 1. Si el mensaje ES claro (ej: \"cambiá el precio del último a 9999\" o \"cambiá el stock del último a 5\"), NO devuelvas confidence=0.\n"
            . "4) Si falta información para ejecutar de forma segura (por ejemplo, no se entiende si es precio o stock, o no hay valor), entonces needs_clarification=true con una pregunta corta y 2-4 opciones.\n"
            . "Ejemplos claros (deben salir como kind=action, NO como clarify):\n"
            . "- cambiá el precio del último a 9999\n"
            . "- cambiá el stock del último a 5\n";

        $user = array(
            'message_raw'  => (string) $message_raw,
            'message_norm' => (string) $message_norm,
            'context_lite' => $lite,
        );

        $messages = array(
            array( 'role' => 'system', 'content' => $sys ),
            array( 'role' => 'user',   'content' => wp_json_encode( $user ) ),
        );

        $meta = array(
            'feature'  => 'brain',
            'action'   => 'brain_parse',
            'origin'   => 'apai_brain',
            'route'    => 'SemanticInterpreter',
        );

        if ( class_exists( 'APAI_Brain_Trace' ) ) {
            $tid = (string) APAI_Brain_Trace::current_trace_id();
            if ( $tid !== '' ) {
                $meta['trace_id'] = $tid;
            }
        }

        $opts = array(
            'temperature' => 0,
            'max_tokens'  => 280,
        );

        $res = null;
        try {
            $res = APAI_Core::llm_inference( $messages, $meta, $opts );
        } catch ( Exception $e ) {
            self::trace_semantic( false, null, 'exception', null );
            return null;
        }

        $output_text = '';
        if ( is_wp_error( $res ) ) {
            self::trace_semantic( false, null, $res->get_error_code(), null );
            return null;
        }
        if ( is_array( $res ) ) {
            $ok = isset( $res['ok'] ) ? (bool) $res['ok'] : false;
            if ( ! $ok ) {
                self::trace_semantic( false, null, isset( $res['error'] ) ? (string) $res['error'] : 'saas_error', null );
                return null;
            }
            if ( isset( $res['data']['output_text'] ) ) {
                $output_text = (string) $res['data']['output_text'];
            } elseif ( isset( $res['output_text'] ) ) {
                $output_text = (string) $res['output_text'];
            }
        } else {
            $output_text = (string) $res;
        }

        $raw = trim( (string) $output_text );
        if ( $raw === '' ) {
            self::trace_semantic( false, null, 'empty_output', null );
            return null;
        }

        // Some providers wrap JSON in text; extract first JSON object.
        $json_str = $raw;
        $first_brace = strpos( $raw, '{' );
        $last_brace  = strrpos( $raw, '}' );
        if ( $first_brace !== false && $last_brace !== false && $last_brace > $first_brace ) {
            $json_str = substr( $raw, $first_brace, $last_brace - $first_brace + 1 );
        }

        $parsed = json_decode( $json_str, true );
        $val = class_exists( 'APAI_Brain_Intent_Validator' )
            ? APAI_Brain_Intent_Validator::validate( $parsed )
            : array( 'ok' => false, 'reason' => 'validator_missing' );

        self::trace_semantic(
            ! empty( $val['ok'] ),
            class_exists( 'APAI_Brain_Intent_Validator' ) ? APAI_Brain_Intent_Validator::sanitize_for_trace( $parsed ) : null,
            isset( $val['reason'] ) ? $val['reason'] : null,
            isset( $val['kind'] ) ? $val['kind'] : null
        );

        if ( empty( $val['ok'] ) ) {
            return null;
        }

        return $val;
    }

    /**
     * Trace event for semantic normalization (best-effort).
     */
    private static function trace_semantic( $ok, $parse_json = null, $reason = null, $kind = null ) {
        if ( ! class_exists( 'APAI_Brain_Trace' ) ) { return; }
        $trace_id = (string) APAI_Brain_Trace::current_trace_id();
        if ( $trace_id === '' ) { return; }

        $payload = array( 'ok' => (bool) $ok );
        if ( $parse_json !== null ) { $payload['parse_json'] = $parse_json; }
        if ( $reason !== null ) { $payload['reason'] = $reason; }
        if ( $kind !== null ) { $payload['kind'] = $kind; }

        APAI_Brain_Trace::emit( $trace_id, 'semantic_intent', $payload );
    }
}
