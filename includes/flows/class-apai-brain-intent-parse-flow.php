<?php
/**
 * AutoProduct AI - Brain
 *
 * @package AutoProduct_AI_Brain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IntentParseFlow (brain_parse) via SaaS
 *
 * @FLOW IntentParseFlow
 * @INVARIANT Full nunca al modelo. Solo Context Lite (estructurado).
 * @INVARIANT Si falla parse/validator -> NO rompe: cae a Deterministic/Targeted/Model.
 */
class APAI_Brain_Intent_Parse_Flow {

    /**
     * @INVARIANT Safety: selectors must be grounded in the user's message.
     *
     * WHY:
     * - We saw real traces where a price update was applied to a previous/last product because
     *   an upstream parse returned selector.type="id" even though the user typed a product name.
     * - If the user didn't explicitly provide an id/sku OR say "último/primero", we must not
     *   accept a guessed selector.
     */
    private static function is_selector_grounded_in_message( $sel_type, $sel_value, $message_norm ) {
        $m = (string) $message_norm;
        $sel_type = (string) $sel_type;

        // Explicit IDs: "#123", "id 123", "ID:123" etc.
        if ( $sel_type === 'id' ) {
            if ( preg_match( '/\b(id|#)\s*[:#-]?\s*' . preg_quote( (string) $sel_value, '/' ) . '\b/i', $m ) ) {
                return true;
            }


            // Not explicitly mentioned -> reject (prevents "last product" guess).
            return false;
        }

        // Explicit SKUs: must contain "sku" and the value, or an exact token match.
        if ( $sel_type === 'sku' ) {
            $v = preg_quote( (string) $sel_value, '/' );
            if ( preg_match( '/\bsku\b[^\S\r\n]*[:#-]?[^\S\r\n]*' . $v . '\b/i', $m ) ) {
                return true;
            }
            if ( preg_match( '/\b' . $v . '\b/i', $m ) ) {
                return true;
            }
            return false;
        }

        // Relative selectors are safe if they match their literal words.
        if ( $sel_type === 'last' ) {
            return (bool) preg_match( '/\b(ultimo|último|ultima|última|last)\b/i', $m );
        }
        if ( $sel_type === 'first' ) {
            return (bool) preg_match( '/\b(primer|primero|primera|first)\b/i', $m );
        }

        // Unknown selector type -> reject.
        return false;
    }
    private static function sanitize_clarify_for_display( $message_norm, $parse_json, $store_state = null ) {
        $action = isset($parse_json['action']) && is_array($parse_json['action']) ? $parse_json['action'] : array();
        $intent = isset($action['intent']) ? strtolower(trim($action['intent'])) : '';

        // Small UX helper: if we have a last target product, mention it so the user understands context.
        $target_hint = '';
        if ( is_array( $store_state ) ) {
            $pid = isset( $store_state['last_target_product_id'] ) ? intval( $store_state['last_target_product_id'] ) : 0;
            if ( $pid > 0 ) {
                $target_hint = ' (producto #' . $pid . ')';
            }
        }

        // If the model gave irrelevant options, we override with safe, predictable followups.
        if ( $intent === 'set_stock' ) {
			$hint = $target_hint ? "\n\nSi te referís al último producto que estabas viendo{$target_hint}, decime el número y listo. 😉" : '';
            return array(
				'question' => "Perfecto 👍\n\n¿De qué producto querés cambiar el **stock**?\nPasame **ID, SKU o nombre** y el **stock final**.\n\nPor ejemplo: \"al ID 386 dejalo en 9\"." . $hint,
				'options'  => array(),
            );
        }
        if ( $intent === 'set_price' ) {
			$hint = $target_hint ? "\n\nSi te referís al último producto que estabas viendo{$target_hint}, decime el precio y listo. 😉" : '';
            return array(
				'question' => "Perfecto 👍\n\n¿De qué producto querés cambiar el **precio**?\nPasame **ID, SKU o nombre** y el **nuevo precio**.\n\nPor ejemplo: \"al SKU X ponelo en 2000\"." . $hint,
				'options'  => array(),
            );
        }

        // Heurística mínima por texto (solo UX): "más barato" casi siempre es precio.
        if ( strpos($message_norm, 'barat') !== false || strpos($message_norm, 'precio') !== false ) {
			$hint = $target_hint ? "\n\nSi te referís al último producto que estabas viendo{$target_hint}, decime el precio y listo. 😉" : '';
            return array(
				'question' => "Perfecto 👍\n\n¿De qué producto querés cambiar el **precio**?\nPasame **ID, SKU o nombre** y el **nuevo precio**.\n\nPor ejemplo: \"al SKU X ponelo en 2000\"." . $hint,
				'options'  => array(),
            );
        }
        if ( strpos($message_norm, 'stock') !== false ) {
			$hint = $target_hint ? "\n\nSi te referís al último producto que estabas viendo{$target_hint}, decime el número y listo. 😉" : '';
            return array(
				'question' => "Perfecto 👍\n\n¿De qué producto querés cambiar el **stock**?\nPasame **ID, SKU o nombre** y el **stock final**.\n\nPor ejemplo: \"al ID 386 dejalo en 9\"." . $hint,
				'options'  => array(),
            );
        }

        return null;
    }
    /**
     * IntentParseFlow (LLM) como "último recurso" del pipeline.
     *
     * En builds anteriores se ejecutaba sólo con keywords para ahorrar costo.
     * Eso hacía que mensajes humanos ("ok, y el stock del #150?", "quiero saber el stock de X")
     * a veces no entren al carril de IA cuando los flows deterministas no alcanzan.
     *
     * Nueva regla (segura): si el pipeline llegó hasta acá, intentamos parsear siempre.
     * - Si el parse/validator falla o la confidence es baja, devolvemos null o clarify.
     * - El fallback final sigue siendo ModelFlow (sin side-effects).
     */
    private static function should_run( $m_norm ) {
        $m = trim( (string) $m_norm );

        // Mensajes vacíos o demasiado cortos: evitamos gastar inferencia.
        if ( $m === '' ) {
            return false;
        }
        if ( strlen( $m ) <= 2 && ! preg_match( '/\d/', $m ) ) {
            return false;
        }

        return true;
    }

    public static function try_handle( $message_raw, $message_norm, $store_state ) {
        if ( ! self::should_run( $message_norm ) ) {
            return null;
        }

        // Semantic normalization (parser-only) — new stable seam.
        // NOTE: interpreter traces its own event; we keep IntentParseFlow trace for continuity.
        $val = class_exists( 'APAI_Brain_Semantic_Interpreter' )
            ? APAI_Brain_Semantic_Interpreter::interpret( $message_raw, $message_norm, $store_state )
            : null;

        // Semantic reformulation layer (safe): if we got low-signal (chitchat/unknown/clarify)
        // but the text looks like it might be an action/query, try a best-effort rewrite and re-interpret.
        $is_low_signal_kind = is_array( $val ) && ! empty( $val['ok'] ) && isset( $val['kind'] ) && in_array( $val['kind'], array( 'chitchat', 'unknown', 'clarify' ), true );
        $has_no_action_or_query = true;
        if ( is_array( $val ) ) {
            $has_action = isset( $val['action'] ) && ! empty( $val['action'] );
            $has_query  = isset( $val['query'] ) && ! empty( $val['query'] );
            $has_no_action_or_query = ! $has_action && ! $has_query;
        }

        // Follow-up (value): if semantic intent says it's an action but needs a value (e.g. "ponelo más barato"),
        // store a follow-up envelope so the next user message can provide the missing number.
        // IMPORTANT: do not override the model's clarify question/options; we only persist the missing-intent context.
        if ( is_array( $val ) && ! empty( $val['ok'] ) && isset( $val['kind'] ) && $val['kind'] === 'clarify' ) {
            $needs = ! empty( $val['parse_json']['needs_clarification'] );
            $action = isset( $val['parse_json']['action'] ) ? $val['parse_json']['action'] : null;
            if ( $needs && is_array( $action ) && ! empty( $action['intent'] ) ) {
                $intent = (string) $action['intent'];
                if ( in_array( $intent, array( 'set_price', 'set_stock' ), true ) ) {
                    $sel = isset( $action['selector'] ) && is_array( $action['selector'] ) ? $action['selector'] : array();
                    $pid = self::resolve_target_product_id( (string) ( $sel['type'] ?? '' ), (string) ( $sel['value'] ?? '' ), $store_state );
                    $follow = array(
                        'expect' => 'value',
                        'intent' => $intent,
                        'field' => ( $intent === 'set_price' ) ? 'price' : 'stock',
                        'selector' => $sel,
                        'product_id' => $pid ? (int) $pid : null,
                        'ts' => time(),
                    );
                    APAI_Brain_Memory_Store::set_value( 'pending_followup_action', $follow );
                }
            }
        }
        if ( $is_low_signal_kind && $has_no_action_or_query ) {
            if ( class_exists( 'APAI_Brain_Semantic_Rewriter' ) && APAI_Brain_Semantic_Rewriter::should_try_rewrite( $message_norm ) ) {
                $rw = APAI_Brain_Semantic_Rewriter::rewrite( $message_raw, $message_norm, $store_state );
                if ( is_array( $rw ) && ! empty( $rw['ok'] ) ) {
                    APAI_Brain_Trace::emit( 'semantic_rewrite', array(
                        'ok' => true,
                        'rewrite' => isset( $rw['rewrite'] ) ? (string) $rw['rewrite'] : '',
                        'kind' => isset( $rw['kind'] ) ? (string) $rw['kind'] : '',
                        'confidence' => isset( $rw['confidence'] ) ? (float) $rw['confidence'] : 0,
                    ) );
                    $rewrite_txt = isset( $rw['rewrite'] ) ? (string) $rw['rewrite'] : '';
                    $rewrite_norm = APAI_Brain_Normalizer::normalize_intent_text( $rewrite_txt );
                    if ( $rewrite_norm !== '' ) {
                        $val2 = APAI_Brain_Semantic_Interpreter::interpret( $rewrite_txt, $rewrite_norm, $store_state );
                        if ( is_array( $val2 ) && ! empty( $val2['ok'] ) && isset( $val2['kind'] ) && ! in_array( $val2['kind'], array( 'chitchat', 'unknown', 'clarify' ), true ) ) {
                            $val = $val2;
                        }
                    }
                }
            }
        }

        self::trace_intent_parse(
            is_array( $val ) && ! empty( $val['ok'] ),
            null,
            is_array( $val ) && isset( $val['reason'] ) ? $val['reason'] : null,
            is_array( $val ) && isset( $val['kind'] ) ? $val['kind'] : null
        );

        if ( ! is_array( $val ) || empty( $val['ok'] ) ) {
            return null;
        }

        // Clarification: deterministic UX + set a follow-up expectation (NO pending action).
        if ( isset( $val['kind'] ) && $val['kind'] === 'clarify' ) {
            $parse = isset( $val['parse_json'] ) && is_array( $val['parse_json'] ) ? $val['parse_json'] : array();
            $action = isset( $parse['action'] ) && is_array( $parse['action'] ) ? $parse['action'] : array();

            // Determine what we are expecting next.
            $intent = isset( $action['intent'] ) ? strtolower( (string) $action['intent'] ) : '';
            $field  = isset( $action['field'] ) ? strtolower( (string) $action['field'] ) : '';

            $expect = 'field';
            $kind   = '';
            if ( $field === 'stock' || strpos( $intent, 'stock' ) !== false ) {
                $expect = 'value';
                $kind   = 'stock';
            } elseif ( $field === 'price' || strpos( $intent, 'price' ) !== false ) {
                $expect = 'value';
                $kind   = 'price';
            }

            // Resolve a best-effort product target. Prefer last targeted product (user context) over DB "last".
            $product_id = 0;
            if ( isset( $store_state['last_target_product_id'] ) ) {
                $product_id = intval( $store_state['last_target_product_id'] );
            }
            if ( $product_id <= 0 && isset( $action['selector'] ) && is_array( $action['selector'] ) ) {
                $product_id = self::resolve_target_product_id( $action['selector'], $store_state );
            }

            // Store follow-up state so the next user message (e.g. a number) can complete the intent without another question.
            if ( $expect === 'value' && $kind !== '' && $product_id > 0 ) {
                $store_state['pending_followup_action'] = array(
                    'expect'     => 'value',
                    'product_id' => $product_id,
                    'kind'       => $kind,
                    'created_at' => time(),
                );
            } else {
                // Fallback: we only know we need more info, but we don't guess.
                $store_state['pending_followup_action'] = array(
                    'expect'     => 'field',
                    'product_id' => $product_id > 0 ? $product_id : ( isset( $store_state['last_target_product_id'] ) ? intval( $store_state['last_target_product_id'] ) : 0 ),
                    'created_at' => time(),
                );
            }
			// Persist follow-up expectation using the existing Memory_Store API.
			// (Avoid undefined variables / methods that could break the entire site.)
			APAI_Brain_Memory_Store::patch( array(
				'pending_followup_action' => isset( $store_state['pending_followup_action'] ) ? $store_state['pending_followup_action'] : null,
			) );

	            // sanitize_clarify_for_display returns an associative array:
	            //   [ 'question' => string, 'options' => array<string> ]
	            // Older code expected a tuple [question, options] and could throw warnings.
	            $pair  = self::sanitize_clarify_for_display( $message_norm, $parse, $store_state );
	            $q     = '';
	            $opts2 = array();
	            if ( is_array( $pair ) ) {
	                if ( isset( $pair['question'] ) && is_string( $pair['question'] ) ) {
	                    $q = $pair['question'];
	                } elseif ( isset( $pair[0] ) && is_string( $pair[0] ) ) {
	                    // Back-compat (tuple)
	                    $q = $pair[0];
	                }
	                if ( isset( $pair['options'] ) && is_array( $pair['options'] ) ) {
	                    $opts2 = $pair['options'];
	                } elseif ( isset( $pair[1] ) && is_array( $pair[1] ) ) {
	                    // Back-compat (tuple)
	                    $opts2 = $pair[1];
	                }
	            }
	            $msg = trim( (string) $q );
            if ( $msg === '' ) { return null; }

            if ( ! empty( $opts2 ) ) {
                $msg .= "\n\nOpciones:\n";
                foreach ( $opts2 as $o ) {
                    $o = trim( (string) $o );
                    if ( $o === '' ) { continue; }
                    $msg .= '• ' . $o . "\n";
                }
                $msg = rtrim( $msg );
            }

            return APAI_Brain_Response_Builder::make_response( 'chat', $msg, array(), null, null, array( 'route' => 'IntentParseFlow' ) );
        }

        // Query path: route to QueryRegistry (single place).
        if ( isset( $val['kind'] ) && $val['kind'] === 'query' ) {
            if ( class_exists( 'APAI_Query_Registry' ) && method_exists( 'APAI_Query_Registry', 'handle_by_code' ) ) {
                return APAI_Query_Registry::handle_by_code( $val['query']['code'], $val['query']['mode'], $message_raw, $message_norm );
            }
            return null;
        }

        // Action path: only handle selectors we can resolve deterministically without ambiguity.
        if ( isset( $val['kind'] ) && $val['kind'] === 'action' ) {
            // NEW: Dispatch normalized action to deterministic preparers (no duplication).
            if ( class_exists( 'APAI_Brain_Semantic_Dispatch' ) ) {
                $resp = APAI_Brain_Semantic_Dispatch::dispatch( $val, $message_raw, $message_norm, $store_state );
                if ( $resp !== null ) {
                    return $resp;
                }
            }

            $action = $val['action'];

            $sel_type  = $action['selector']['type'];
            $sel_value = $action['selector']['value'];

            // Safety: if selector isn't explicitly present in the user's message, don't trust it.
            // Let TargetedUpdateFlow do the safe name-based selection instead.
            if ( ! self::is_selector_grounded_in_message( $sel_type, $sel_value, $message_norm ) ) {
                return null;
            }

            // Avoid duplicating TargetedUpdateFlow (name-based selection).
            if ( $sel_type === 'name' ) {
                return null;
            }

            $target_product_id = self::resolve_target_product_id( $sel_type, $sel_value, $store_state );
            if ( ! $target_product_id ) {
                $msg = "¿A qué producto te referís? Podés decir:\n"
                    . "• \"último producto\"\n"
                    . "• \"primer producto\"\n"
                    . "• \"ID 123\" o \"SKU ABC123\"";
                return APAI_Brain_Response_Builder::make_response( 'chat', $msg, array(), null, null, array( 'route' => 'IntentParseFlow' ) );
            }

            $field = $action['field'];
            $raw_value_text = $action['raw_value_text'];

            if ( $field === 'price' ) {
                $num = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_price_number( $raw_value_text ) : null;
                if ( $num === null || $num < 0 ) {
                    return APAI_Brain_Response_Builder::make_response( 'chat', 'Ese precio no me quedó claro. ¿Qué valor querés poner? (ej: 999, 10k, 10 lucas) 😊', array(), null, null, array( 'route' => 'IntentParseFlow' ) );
                }

                $price_str = number_format( floatval( $num ), 2, '.', '' );

                $pending_action = array(
                    'type'       => 'update_product',
                    'product_id'  => intval( $target_product_id ),
                    'changes'     => array(
                        'regular_price' => $price_str,
                    ),
                );

// F6.6: ensure UI has a deterministic summary for pending cards.
if ( class_exists( 'APAI_Brain_NLG' ) && isset( $pending_action['changes'] ) ) {
    $pending_action['human_summary'] = APAI_Brain_NLG::summarize_update_product_changes( $pending_action['changes'] );
}
                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                    APAI_Brain_Memory_Store::persist_pending_action( $pending_action );
                }

                $human = 'Dale, preparé el cambio de precio del producto #' . intval( $target_product_id ) . ' a ' . $price_str . '.';
                return APAI_Brain_Response_Builder::action_prepared( $pending_action, $human, null, array( 'route' => 'IntentParseFlow' ) );
            }

            if ( $field === 'stock' ) {
                $num = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_number( $raw_value_text ) : null;
                if ( $num === null ) {
                    return APAI_Brain_Response_Builder::make_response( 'chat', 'Ese stock no me quedó claro. ¿Qué número querés poner? 😊', array(), null, null, array( 'route' => 'IntentParseFlow' ) );
                }
                $stock_i = intval( $num );
                if ( $stock_i < 0 ) {
                    return APAI_Brain_Response_Builder::make_response( 'chat', 'No puedo poner stock negativo. ¿Querés 0 o un número mayor? 😊', array(), null, null, array( 'route' => 'IntentParseFlow' ) );
                }

                $pending_action = array(
                    'type'       => 'update_product',
                    'product_id'  => intval( $target_product_id ),
                    'changes'     => array(
                        'manage_stock'   => true,
                        'stock_quantity' => $stock_i,
                    ),
                );

// F6.6: ensure UI has a deterministic summary for pending cards.
if ( class_exists( 'APAI_Brain_NLG' ) && isset( $pending_action['changes'] ) ) {
    $pending_action['human_summary'] = APAI_Brain_NLG::summarize_update_product_changes( $pending_action['changes'] );
}
                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                    APAI_Brain_Memory_Store::persist_pending_action( $pending_action );
                }

                $human = 'Dale, preparé el cambio de stock del producto #' . intval( $target_product_id ) . ' a ' . $stock_i . '.';
                return APAI_Brain_Response_Builder::action_prepared( $pending_action, $human, null, array( 'route' => 'IntentParseFlow' ) );
            }

            return null;
        }

        return null;
    }

    private static function trace_intent_parse( $ok, $parse_json = null, $reason = null, $kind = null ) {
		if ( ! class_exists( 'APAI_Brain_Trace' ) ) { return; }
		$trace_id = (string) APAI_Brain_Trace::current_trace_id();
		if ( $trace_id === '' ) { return; }

		$payload = array(
			'ok' => (bool) $ok,
		);
		if ( $parse_json !== null ) { $payload['parse_json'] = $parse_json; }
		if ( $reason !== null ) { $payload['reason'] = $reason; }
		if ( $kind !== null ) { $payload['kind'] = $kind; }

		// NOTE: Trace uses emit(trace_id, event, data). Keep it best-effort.
		APAI_Brain_Trace::emit( $trace_id, 'intent_parse', $payload );
    }

    private static function resolve_target_product_id( $sel_type, $sel_value, $store_state ) {
        global $wpdb;

        if ( $sel_type === 'last' ) {
            // UX definition: "último producto" = newest by creation order (highest product ID),
            // NOT "last product mentioned" (that's handled by follow-ups via last_product/last_target).
            // Keep consistent with DeterministicFlow.
            if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts ) ) { return null; }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','draft','private','pending','future') ORDER BY ID DESC LIMIT 1" );
            return $id ? intval( $id ) : null;
        }

        if ( $sel_type === 'first' ) {
            if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts ) ) { return null; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $id = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','draft','private','pending','future') ORDER BY ID ASC LIMIT 1" );
            return $id ? intval( $id ) : null;
        }

        if ( $sel_type === 'id' ) {
            $id = intval( preg_replace( '/[^0-9]/', '', strval( $sel_value ) ) );
            if ( $id > 0 ) {
                $p = get_post( $id );
                if ( $p && isset( $p->post_type ) && $p->post_type === 'product' ) { return $id; }
            }
            return null;
        }

        if ( $sel_type === 'sku' ) {
            if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->postmeta ) ) { return null; }
            $sku = trim( strval( $sel_value ) );
            if ( $sku === '' ) { return null; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $sql = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1", $sku );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $id = $wpdb->get_var( $sql );
            return $id ? intval( $id ) : null;
        }

        return null;
    }
}
