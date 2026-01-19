<?php
/**
 * AutoProduct AI - Brain
 *
 * @package AutoProduct_AI_Brain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IntentParseFlow Validator/Policy
 *
 * @FLOW IntentParseFlow
 * @INVARIANT Allowlist strict: actions set_price/set_stock; queries A1..A8.
 * @INVARIANT Conservative thresholds: action>=0.65, query>=0.60, chitchat>=0.50.
 */
class APAI_Brain_Intent_Validator {

    const SCHEMA_VERSION = '1.0';

    /**
     * Validate and normalize a brain_parse JSON payload.
     *
     * @return array{ok:bool, kind?:string, action?:array, query?:array, clarify?:array, reason?:string}
     */
    public static function validate( $json ) {
        if ( ! is_array( $json ) ) {
            return array( 'ok' => false, 'reason' => 'parse_not_array' );
        }

        $schema_version = isset( $json['schema_version'] ) ? strval( $json['schema_version'] ) : '';
        if ( $schema_version !== self::SCHEMA_VERSION ) {
            return array( 'ok' => false, 'reason' => 'bad_schema_version' );
        }

        $kind = isset( $json['kind'] ) ? strval( $json['kind'] ) : '';
        if ( ! in_array( $kind, array( 'action', 'query', 'chitchat', 'unknown' ), true ) ) {
            return array( 'ok' => false, 'reason' => 'bad_kind' );
        }

        $confidence = isset( $json['confidence'] ) ? floatval( $json['confidence'] ) : 0.0;

        /**
         * Safety-first UX improvement:
         * Some providers occasionally return confidence=0 for clearly structured actions.
         * We keep conservative gating, but avoid falling back to ModelFlow when the intent is
         * already well-formed and allowlisted.
         *
         * Policy:
         * - If kind=action and the payload is structurally complete (intent+selector+value),
         *   we allow a deterministic override to the minimum action threshold.
         * - Otherwise, we convert to a clarify response (no pending), so the Brain stays in control.
         */
        if ( $kind === 'action' && $confidence < 0.65 ) {
            $a = isset( $json['action'] ) && is_array( $json['action'] ) ? $json['action'] : array();
            $intent = isset( $a['intent'] ) ? strtolower( trim( strval( $a['intent'] ) ) ) : '';
            $field  = isset( $a['field'] ) ? strtolower( trim( strval( $a['field'] ) ) ) : '';
            $raw_value_text = isset( $a['raw_value_text'] ) ? trim( strval( $a['raw_value_text'] ) ) : '';
            $selector = isset( $a['selector'] ) && is_array( $a['selector'] ) ? $a['selector'] : array();
            $sel_type  = isset( $selector['type'] ) ? strtolower( trim( strval( $selector['type'] ) ) ) : 'unknown';

            $intent_ok = in_array( $intent, array( 'set_price', 'set_stock' ), true );
            $field_ok  = ( $intent === 'set_price' && $field === 'price' ) || ( $intent === 'set_stock' && $field === 'stock' );
            $sel_ok    = in_array( $sel_type, array( 'last','first','id','sku' ), true );
            $value_ok  = ( $raw_value_text !== '' );

            // Deterministic override: structurally complete + allowlisted.
            // Grounding is enforced later in IntentParseFlow.
            if ( $intent_ok && $field_ok && $sel_ok && $value_ok ) {
                $confidence = 0.70;
            } else {
                // Convert to clarify (Brain-controlled), avoiding ModelFlow hallucinations.
                $what = ( $field === 'price' ) ? 'precio' : ( ( $field === 'stock' ) ? 'stock' : 'ese valor' );
                $q = 'Entendí que querés cambiar el ' . $what . ', pero quiero confirmar para no equivocarme. ¿Qué querés hacer?';
                $opts = array();
                if ( $field === 'price' ) {
                    $opts[] = 'Cambiar el precio (decime el valor)';
                    $opts[] = 'Cambiar el stock (decime el número)';
                } elseif ( $field === 'stock' ) {
                    $opts[] = 'Cambiar el stock (decime el número)';
                    $opts[] = 'Cambiar el precio (decime el valor)';
                } else {
                    $opts[] = 'Cambiar el precio';
                    $opts[] = 'Cambiar el stock';
                }

                return array(
                    'ok'      => true,
                    'kind'    => 'clarify',
                    'clarify' => array(
                        'question' => $q,
                        'options'  => $opts,
                    ),
                    'reason'  => 'low_confidence_action_clarify',
                );
            }
        }

        // Clarification path.
        $needs_clarification = ! empty( $json['needs_clarification'] );
        if ( $needs_clarification ) {
            $clarify = isset( $json['clarify'] ) && is_array( $json['clarify'] ) ? $json['clarify'] : array();
            $q = isset( $clarify['question'] ) ? trim( strval( $clarify['question'] ) ) : '';
            $opts = isset( $clarify['options'] ) && is_array( $clarify['options'] ) ? $clarify['options'] : array();
            $opts_clean = array();
            foreach ( $opts as $o ) {
                $o = trim( strval( $o ) );
                if ( $o !== '' ) { $opts_clean[] = $o; }
            }
            if ( $q === '' ) {
                return array( 'ok' => false, 'reason' => 'clarify_missing_question' );
            }
            return array(
                'ok'      => true,
                'kind'    => 'clarify',
                'clarify' => array(
                    'question' => $q,
                    'options'  => $opts_clean,
                ),
            );
        }

        // Thresholds.
        // NOTE: Some providers occasionally output confidence=0 even when the structured action is clear.
        // We keep conservative gating, but avoid falling back to ModelFlow (which may hallucinate constraints).
        // Policy:
        // - If kind=action and confidence is low BUT the action is fully structured (allowlisted intent/field,
        //   selector not unknown, and raw_value_text present), we treat it as actionable with a minimal
        //   floor confidence. Execution is still protected downstream by grounding checks + deterministic resolvers.
        // - If kind=action is low confidence and NOT well-structured, convert into a clarify response.
        if ( $kind === 'action' && $confidence < 0.65 ) {
            $a = isset( $json['action'] ) && is_array( $json['action'] ) ? $json['action'] : array();
            $intent = isset( $a['intent'] ) ? strtolower( trim( strval( $a['intent'] ) ) ) : '';
            $field  = isset( $a['field'] ) ? strtolower( trim( strval( $a['field'] ) ) ) : '';
            $selector = isset( $a['selector'] ) && is_array( $a['selector'] ) ? $a['selector'] : array();
            $sel_type  = isset( $selector['type'] ) ? strtolower( trim( strval( $selector['type'] ) ) ) : 'unknown';
            $raw_value_text = isset( $a['raw_value_text'] ) ? trim( strval( $a['raw_value_text'] ) ) : '';

            $intent_ok = in_array( $intent, array( 'set_price', 'set_stock' ), true );
            $field_ok  = ( $intent === 'set_price' && $field === 'price' ) || ( $intent === 'set_stock' && $field === 'stock' );
            $sel_ok    = in_array( $sel_type, array( 'last', 'first', 'id', 'sku' ), true );
            $value_ok  = ( $raw_value_text !== '' );

            if ( $intent_ok && $field_ok && $sel_ok && $value_ok ) {
                // Floor confidence to allow deterministic pipeline to continue.
                $confidence = 0.70;
                $json['confidence'] = $confidence;
            } else {
                // Convert to clarify (no pending) to keep safety and avoid ModelFlow hallucinations.
                $what = ( $field === 'stock' ) ? 'stock' : 'precio';
                $q = 'Entendí que querés cambiar el **' . $what . '**, pero necesito confirmación rápida.';
                $opts = array(
                    'cambiar ' . $what . ' del **último** a un valor (ej: "' . $what . ' del último a 5")',
                    'cambiar ' . $what . ' del **#ID** (ej: "' . $what . ' del #150 a 5")',
                );
                return array(
                    'ok'   => true,
                    'kind' => 'clarify',
                    'clarify' => array(
                        'question' => $q,
                        'options'  => $opts,
                    ),
                );
            }
        }
        if ( $kind === 'query' && $confidence < 0.60 ) {
            return array( 'ok' => false, 'reason' => 'low_confidence_query' );
        }
        if ( $kind === 'chitchat' && $confidence < 0.50 ) {
            return array( 'ok' => false, 'reason' => 'low_confidence_chitchat' );
        }

        if ( $kind === 'query' ) {
            $q = isset( $json['query'] ) && is_array( $json['query'] ) ? $json['query'] : array();
            $code = isset( $q['code'] ) ? strtoupper( trim( strval( $q['code'] ) ) ) : '';
            $mode = isset( $q['mode'] ) ? strtolower( trim( strval( $q['mode'] ) ) ) : '';
            if ( ! in_array( $code, array( 'A1','A2','A3','A4','A5','A6','A7','A8' ), true ) ) {
                return array( 'ok' => false, 'reason' => 'query_not_allowlisted' );
            }
            if ( ! in_array( $mode, array( 'summary','full','top5','' ), true ) ) {
                $mode = 'summary';
            }
            if ( $mode === '' ) { $mode = 'summary'; }
            return array(
                'ok'   => true,
                'kind' => 'query',
                'query' => array(
                    'code' => $code,
                    'mode' => $mode,
                ),
            );
        }

        if ( $kind === 'action' ) {
            $a = isset( $json['action'] ) && is_array( $json['action'] ) ? $json['action'] : array();
            $intent = isset( $a['intent'] ) ? strtolower( trim( strval( $a['intent'] ) ) ) : '';
            if ( ! in_array( $intent, array( 'set_price', 'set_stock' ), true ) ) {
                return array( 'ok' => false, 'reason' => 'action_not_allowlisted' );
            }

            $field = isset( $a['field'] ) ? strtolower( trim( strval( $a['field'] ) ) ) : '';
            if ( $intent === 'set_price' && $field !== 'price' ) {
                return array( 'ok' => false, 'reason' => 'intent_field_mismatch' );
            }
            if ( $intent === 'set_stock' && $field !== 'stock' ) {
                return array( 'ok' => false, 'reason' => 'intent_field_mismatch' );
            }

            $selector = isset( $a['selector'] ) && is_array( $a['selector'] ) ? $a['selector'] : array();
            $sel_type  = isset( $selector['type'] ) ? strtolower( trim( strval( $selector['type'] ) ) ) : 'unknown';
            $sel_value = isset( $selector['value'] ) ? trim( strval( $selector['value'] ) ) : '';
            if ( ! in_array( $sel_type, array( 'last','first','id','sku','name','unknown' ), true ) ) {
                $sel_type = 'unknown';
            }
            if ( $sel_type === 'unknown' ) {
                return array( 'ok' => false, 'reason' => 'selector_unknown' );
            }

            $raw_value_text = isset( $a['raw_value_text'] ) ? trim( strval( $a['raw_value_text'] ) ) : '';
            if ( $raw_value_text === '' ) {
                return array( 'ok' => false, 'reason' => 'missing_raw_value_text' );
            }

            // Name selectors: if too short -> reject (fallback should ask / targeted selector).
            if ( $sel_type === 'name' && function_exists( 'mb_strlen' ) && mb_strlen( $sel_value ) < 3 ) {
                return array( 'ok' => false, 'reason' => 'selector_name_too_short' );
            }

            return array(
                'ok'   => true,
                'kind' => 'action',
                'action' => array(
                    'intent' => $intent,
                    'field'  => $field,
                    'selector' => array(
                        'type'  => $sel_type,
                        'value' => $sel_value,
                    ),
                    'raw_value_text' => $raw_value_text,
                ),
            );
        }

        // chitchat/unknown: let ModelFlow handle.
        return array( 'ok' => true, 'kind' => $kind );
    }

    /**
     * Sanitized JSON for tracing (no long strings).
     */
    public static function sanitize_for_trace( $json ) {
        if ( ! is_array( $json ) ) return null;
        $copy = $json;

        if ( isset( $copy['action']['raw_value_text'] ) && is_string( $copy['action']['raw_value_text'] ) ) {
            $copy['action']['raw_value_text'] = function_exists( 'mb_substr' )
                ? mb_substr( $copy['action']['raw_value_text'], 0, 40 )
                : substr( $copy['action']['raw_value_text'], 0, 40 );
        }
        if ( isset( $copy['clarify']['question'] ) && is_string( $copy['clarify']['question'] ) ) {
            $copy['clarify']['question'] = function_exists( 'mb_substr' )
                ? mb_substr( $copy['clarify']['question'], 0, 80 )
                : substr( $copy['clarify']['question'], 0, 80 );
        }
        if ( isset( $copy['clarify']['options'] ) && is_array( $copy['clarify']['options'] ) ) {
            $copy['clarify']['options'] = array_slice( $copy['clarify']['options'], 0, 5 );
        }
        return $copy;
    }
}
