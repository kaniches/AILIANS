<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ModelFlow (LLM via SaaS through Core).
 *
 * This flow is the non-deterministic fallback when no other flow matches.
 *
 * @INVARIANT This flow MUST NOT create pending actions.
 * WHY: Actions are produced only by DeterministicFlow / QueryFlow.
 */
class APAI_Brain_Model_Flow {

    /**
     * Static entrypoint used by the Pipeline.
     */
    public static function handle( $message, $m_norm = '', $store_state = null, $pending = null ) {
        $flow = new self();
        return $flow->run( null, array(
            'input_text'  => (string) $message,
            'message_norm'=> (string) $m_norm,
            'store_state' => is_array( $store_state ) ? $store_state : array(),
        ) );
    }

    public function matches( $input, $ctx ) {
        // Always last fallback.
        return true;
    }

    public function run( $request, $ctx ) {
        $input = isset( $ctx['input_text'] ) ? trim( (string) $ctx['input_text'] ) : '';
        $m_norm = isset( $ctx['message_norm'] ) ? (string) $ctx['message_norm'] : '';
        $store_state = isset( $ctx['store_state'] ) && is_array( $ctx['store_state'] ) ? $ctx['store_state'] : array();

        // Brain response builder (keeps UI contract stable).
        $rb = class_exists( 'APAI_Brain_Response_Builder' ) ? 'APAI_Brain_Response_Builder' : null;

        // Empty input -> soft greeting (no LLM call).
        if ( $input === '' ) {
            $text = 'Hola 😊 ¿En qué puedo ayudarte hoy?';
            if ( $rb ) { return call_user_func( array( $rb, 'make_response' ), 'chat', $text, array(), null, null, array() ); }
            return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $text, 'reply' => $text );
        }

        // SaaS-first: IA always goes through Core → SaaS.
        if ( ! class_exists( 'APAI_Core' ) ) {
            $text = 'Para usar IA, primero activá **AutoProduct AI Core**.';
            if ( $rb ) { return call_user_func( array( $rb, 'make_response' ), 'chat', $text, array(), null, null, array( 'error' => 'core_missing' ) ); }
            return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $text, 'reply' => $text );
        }

        // Hard rule: Brain only uses SaaS mode.
        $llm_mode = method_exists( 'APAI_Core', 'get_option' ) ? APAI_Core::get_option( 'llm_mode', 'saas' ) : 'saas';
        if ( $llm_mode !== 'saas' ) {
			// Use real newlines (not literal "\n") so the UI renders line breaks.
			$text = "La IA está deshabilitada porque esta tienda no está en modo **SaaS**.\n\nEntrá a **AutoProduct AI → Core** y configurá el modo LLM en **SaaS**.";
            if ( $rb ) { return call_user_func( array( $rb, 'make_response' ), 'chat', $text, array(), null, null, array( 'error' => 'llm_mode_not_saas' ) ); }
            return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $text, 'reply' => $text );
        }

        // NOTE: We do not touch model selection here; SaaS decides.

        // Context Lite (structured) — the only context allowed to reach the model.
        $context_lite = class_exists( 'APAI_Brain_Context_Lite' )
            ? APAI_Brain_Context_Lite::build( $store_state )
            : array();
        $context_lite_json = class_exists( 'APAI_Brain_Context_Lite' )
            ? APAI_Brain_Context_Lite::to_json( $context_lite )
            : '{}';

        // A1.5.28c — Safety net for "chit-chat" / off-domain.
        // If upstream flows miss it (e.g. semantic intent is low confidence), we MUST NOT
        // fall back to generic bot-like copy. We answer honestly and ask what the user meant.
        if ( class_exists( 'APAI_Brain_OffDomain_Detector' ) && class_exists( 'APAI_Brain_ChitChat_Redactor' ) ) {
            try {
                if ( APAI_Brain_OffDomain_Detector::is_chitchat( $m_norm ) ) {
                    $hint = '';
                    if ( is_array( $store_state ) ) {
                        if ( ! empty( $store_state['last_target_product_id'] ) ) {
                            $hint .= 'last_target_product_id=' . intval( $store_state['last_target_product_id'] ) . '; ';
                        }
                        if ( ! empty( $store_state['last_action_kind'] ) ) {
                            $hint .= 'last_action_kind=' . sanitize_text_field( (string) $store_state['last_action_kind'] ) . '; ';
                        }
                    }

                    $draft = APAI_Brain_ChitChat_Redactor::reply( $input, $context_lite_json, $hint );
                    $text  = is_array( $draft ) && ! empty( $draft['text'] )
                        ? (string) $draft['text']
                        : ( 'No estoy seguro a qué te referís con “' . $input . '”. ¿Podés aclararme un poco qué querés hacer?' );

                    if ( class_exists( 'APAI_Brain_Response_Builder' ) ) {
                        return APAI_Brain_Response_Builder::make_response( 'chat', $text, $store_state );
                    }

                    return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $text, 'reply' => $text );
                }
            } catch ( Exception $e ) {
                // Best-effort: ignore and keep ModelFlow running.
            }
        }

        // SemanticInterpreter (parser-only) — makes ModelFlow a true last resort.
        // If we can safely normalize the intent (grounded + high confidence), dispatch it deterministically.
        // Otherwise, fall back to plain ModelFlow chat (SaaS) to keep the UX natural.
        if ( class_exists( 'APAI_Brain_Semantic_Interpreter' ) && class_exists( 'APAI_Brain_Semantic_Dispatch' ) ) {
            try {
                $si = new APAI_Brain_Semantic_Interpreter();
                $parsed = $si->interpret( $input, $m_norm, $context_lite, $store_state );

                if ( is_array( $parsed ) && isset( $parsed['kind'] ) ) {
                    $kind = (string) $parsed['kind'];
                    $confidence = isset( $parsed['confidence'] ) ? floatval( $parsed['confidence'] ) : 0.0;
                    $needs_clarification = ! empty( $parsed['needs_clarification'] );

                    // Gate VERY hard: only dispatch when it is clearly grounded and does not require clarification.
                    // Otherwise, do NOT show the model-generated clarify/options (often feels robotic).
                    if ( in_array( $kind, array( 'action', 'query' ), true ) && $confidence >= 0.65 && ! $needs_clarification ) {
                        $dispatch_ctx = array(
                            'store_state'   => $store_state,
                            'context_lite'  => $context_lite,
                            'trace_id'      => isset( $ctx['trace_id'] ) ? $ctx['trace_id'] : null,
                            'level'         => isset( $ctx['level'] ) ? $ctx['level'] : '',
                        );

                        $resp = APAI_Brain_Semantic_Dispatch::dispatch( $parsed, $dispatch_ctx );
                        if ( $resp ) {
                            // Ensure meta route is explicit for tracing.
                            if ( is_array( $resp ) && isset( $resp['meta'] ) && is_array( $resp['meta'] ) ) {
                                $resp['meta']['route'] = 'ModelFlow(Semantic)';
                            }
                            return $resp;
                        }
                    }
                }
            } catch ( Exception $e ) {
                // Fall through to plain ModelFlow chat.
            }
        }

        // If semantic parsing ran but was low-confidence / needs clarification, we STILL call the model,
        // but ONLY for natural language guidance (no actions). This avoids robotic canned replies.
        $semantic_hint_json = '';
        if ( isset( $parsed ) && is_array( $parsed ) ) {
            $semantic_hint_json = wp_json_encode( $parsed, JSON_UNESCAPED_UNICODE );
        }

        // System prompt: conversational, safe, no side-effects.
        $system = "Sos AutoProduct AI (Brain) para WooCommerce.\n\nObjetivo:\n- Mantener una charla natural (tipo Kodee), PERO segura y auditada.\n\nReglas duras (obligatorias):\n- Hablá en español (tono amable, claro, rioplatense).\n- NO ejecutes acciones ni digas que ejecutaste algo.\n- NO crees pending, NO pidas confirmación de ejecución. Solo guiá.\n- No inventes datos del catálogo.\n- Solo podés usar CONTEXT_LITE_JSON.\n\nRegla de honestidad (MUY importante):\n- NO digas 'Entiendo' o 'perfecto' si no estás seguro de qué quiso decir el usuario.\n- Si no estás seguro, decilo explícitamente: 'No estoy seguro a qué te referís con...'.\n\nSobre categorías (anti-alucinación):\n- NO enumeres ni inventes nombres de categorías (ej: calzado, hogar, accesorios) salvo que estén textualmente en CONTEXT_LITE_JSON.\n- Si querés ofrecer categorías, pedí permiso para listarlas: 'Si querés, te muestro las categorías reales de tu tienda'.\n\nCómo responder:\n- Si el usuario pide algo ambiguo: hacé 1 pregunta clara y ofrecé 2–4 opciones concretas (solo opciones reales del plugin: precio / stock / categoría / buscar producto por ID/SKU/nombre).\n- Si el usuario dice algo conversacional ('hola', 'qué podés hacer', 'la billetera está vacía') y no hay acción clara: respondé humano + hacé 1 pregunta para encaminarlo a WooCommerce.\n- Si el usuario pide algo fuera de WooCommerce (ej: 'ponelo donde estaba el sillón'): explicá en 1 frase que eso no aplica al catálogo, y ofrecé alternativas reales (precio/stock/categoría) o pedir el producto (ID/SKU/nombre).\n- Si detectás que el usuario se refiere al último producto visto y existe en CONTEXT_LITE_JSON, podés mencionarlo como sugerencia (sin inventar).";

        $user = "CONTEXT_LITE_JSON:\n" . $context_lite_json . "\n\nSEMANTIC_HINT_JSON (solo para ayudarte a preguntar mejor; no implica acción):\n" . ( $semantic_hint_json !== '' ? $semantic_hint_json : '{}' ) . "\n\nMENSAJE_USUARIO:\n" . $input;

        $messages = array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user', 'content' => $user ),
        );

        $meta = array(
            'feature'  => 'brain',
            'action'   => 'brain_chat',
            'origin'   => 'apai_brain',
            'site_url' => function_exists( 'home_url' ) ? home_url() : '',
            'route'    => 'ModelFlow',
            'message_norm' => $m_norm,
        );

        // Attach trace_id when available (end-to-end observability).
        if ( class_exists( 'APAI_Brain_Trace' ) ) {
            $tid = (string) APAI_Brain_Trace::current_trace_id();
            if ( $tid !== '' ) {
                $meta['trace_id'] = $tid;
            }
        }

        // Call SaaS through Core (no model selection here).
        $res = null;
        try {
            if ( method_exists( 'APAI_Core', 'llm_inference' ) ) {
                $res = APAI_Core::llm_inference( $messages, $meta, array(
                    'max_tokens'   => 220,
                    'temperature'  => 0.2,
                ) );
            } elseif ( method_exists( 'APAI_Core', 'llm_chat' ) ) {
                // Legacy fallback: returns string.
                $res = APAI_Core::llm_chat( $messages, $meta );
            } else {
                $res = new WP_Error( 'core_llm_missing', 'Core no expone llm_inference/llm_chat.' );
            }
        } catch ( Exception $e ) {
            $res = new WP_Error( 'brain_llm_exception', $e->getMessage() );
        }

        // Normalize output.
        if ( is_wp_error( $res ) ) {
            $err = $res->get_error_message();
            $text = "No pude consultar la IA en este momento.\n\nProbá de nuevo en unos segundos, o revisá **AutoProduct AI → Core** (conexión SaaS).";
            $meta_out = array( 'error' => $res->get_error_code() );
            $data = $res->get_error_data();
            if ( is_array( $data ) ) { $meta_out['error_data'] = $data; }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $meta_out['debug_error_message'] = (string) $err;
            }
            if ( $rb ) { return call_user_func( array( $rb, 'make_response' ), 'chat', $text, array(), null, null, $meta_out ); }
            return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $text, 'reply' => $text, 'meta' => $meta_out );
        }

        $output = '';
        $meta_out = array();

        if ( is_array( $res ) ) {
            // Expected shape from Core llm_inference: {ok:true,data:{output_text,usage,trace_id}}
            $ok = isset( $res['ok'] ) ? (bool) $res['ok'] : false;
            if ( ! $ok ) {
                $text = "No pude consultar la IA en este momento.\n\nRevisá **AutoProduct AI → Core** (conexión SaaS) y volvé a intentar.";
                $meta_out = array( 'error' => $res['error'] ?? 'saas_error' );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    $meta_out['debug_result'] = $res;
                }
                if ( $rb ) { return call_user_func( array( $rb, 'make_response' ), 'chat', $text, array(), null, null, $meta_out ); }
                return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $text, 'reply' => $text, 'meta' => $meta_out );
            }

            if ( isset( $res['data']['output_text'] ) ) {
                $output = (string) $res['data']['output_text'];
            } elseif ( isset( $res['output_text'] ) ) {
                $output = (string) $res['output_text'];
            }

            $trace_id = $res['data']['trace_id'] ?? ( $res['trace_id'] ?? null );
            $usage = $res['data']['usage'] ?? ( $res['usage'] ?? null );
            if ( $trace_id ) { $meta_out['saas_trace_id'] = (string) $trace_id; }
            if ( is_array( $usage ) ) { $meta_out['saas_usage'] = $usage; }
        } else {
            // Legacy clients may return a plain string.
            $output = (string) $res;
        }

	        // --- UX guardrail (deterministic) ---
	        // The model is allowed to ask clarifying questions, but for the common cases
	        // "poné más stock" / "ponelo más barato" we keep the UX short and consistent
	        // (ID/SKU/nombre + valor), avoiding overly long prompts.
	        $m_lc = strtolower( (string) $m_norm );
	        $o_lc = strtolower( (string) $output );
	        $mentions_sku = ( strpos( $o_lc, 'sku' ) !== false );
	        $looks_verbose = ( strpos( $o_lc, 'para poder ayudarte' ) !== false ) || ( strpos( $o_lc, 'necesito que me digas' ) !== false );
	        if ( ( $mentions_sku || $looks_verbose ) && ( strpos( $m_lc, 'stock' ) !== false ) ) {
	            $output = "Perfecto 👍\n\n¿De qué producto querés cambiar el **stock**?\nPasame **ID, SKU o nombre** y el **stock final**.\n\nPor ejemplo: \"al SKU X ponelo en 10\".";
	        } elseif ( ( $mentions_sku || $looks_verbose ) && ( strpos( $m_lc, 'precio' ) !== false || strpos( $m_lc, 'barat' ) !== false || strpos( $m_lc, 'car' ) !== false ) ) {
	            $output = "Perfecto 👍\n\n¿De qué producto querés cambiar el **precio**?\nPasame **ID, SKU o nombre** y el **nuevo precio**.\n\nPor ejemplo: \"al SKU X ponelo en 2000\".";
	        }

	        // A1.5.18: el modelo no debe decir “no tengo la capacidad / no puedo”.
	        // Pero en vez de responder algo genérico (que suena a bot), hacemos un fallback
	        // contextual según el mensaje del usuario (sin crear pending ni inventar acciones).
	        $o_lc2 = strtolower( (string) $output );
	        if ( strpos( $o_lc2, 'no tengo la capacidad' ) !== false || strpos( $o_lc2, 'no puedo' ) !== false ) {
	            $looks_like_physical_move = false;
	            foreach ( array( 'sillon', 'sof', 'mueble', 'al lado', 'alado', 'lado', 'mover', 'ubic', 'posicion', 'donde estaba' ) as $kw ) {
	                if ( strpos( $m_lc, $kw ) !== false ) { $looks_like_physical_move = true; break; }
	            }

	            if ( $looks_like_physical_move ) {
	                $output = "Entiendo 🙂\n\nEn el catálogo de WooCommerce no existe ‘moverlo al lado del sillón’ (eso sería una **ubicación física**).\n\nSi lo que querés es cambiar algo del producto (por ejemplo **precio**, **stock** o **categoría**), decime cuál (ID/SKU/nombre) y qué cambio querés, y lo preparo con botones.";
	            } else {
	                $output = "No estoy seguro de a qué te referís con eso.\n\n¿Querés hacer algo en el catálogo (por ejemplo **precio**, **stock**, **categoría** o consultar un producto)?\nSi me decís el **producto** (ID/SKU/nombre) y el **cambio**, lo preparo con botones.";
	            }
	        }

	        // Guardrail anti-invención (chitchat): no listar "categorías sugeridas" que no existan.
	        // Si el modelo sugiere categorías genéricas (calzado/hogar/etc.) lo reemplazamos por una
	        // pregunta honesta (sin asumir intención).
	        $o_lc3 = strtolower( (string) $output );
	        $looks_like_list = ( preg_match( '/\n\s*1\./', (string) $output ) === 1 ) || ( strpos( $o_lc3, 'aquí' ) !== false && strpos( $o_lc3, 'opcion' ) !== false );
	        $cat_hits = 0;
	        foreach ( array( 'calzado', 'artículos de hogar', 'articulos de hogar', 'hogar' ) as $kw ) {
	            if ( strpos( $o_lc3, $kw ) !== false ) { $cat_hits++; }
	        }
	        // "accesorios" puede ser real, no lo usamos como único indicador.
	        if ( strpos( $o_lc3, 'accesorios' ) !== false ) { $cat_hits++; }
	        if ( $looks_like_list && $cat_hits >= 2 ) {
	            $output = "No estoy seguro de a qué te referís con eso.\n\n¿Querías decir que estás sin plata / con poco presupuesto, o estabas hablando de otra cosa?\nSi querés, decime qué producto te interesa (ID/SKU/nombre) y te muestro **precio** y **stock**, o te ayudo a buscar algo en tu catálogo.";
	        }

        $output = trim( $output );
        if ( $output === '' ) {
            $output = 'Listo. ¿Qué querés hacer ahora?';
        }

        if ( $rb ) {
            return call_user_func( array( $rb, 'make_response' ), 'chat', $output, array(), null, null, $meta_out );
        }

        return array( 'ok' => true, 'mode' => 'chat', 'message_to_user' => $output, 'reply' => $output, 'meta' => $meta_out );
    }
}
