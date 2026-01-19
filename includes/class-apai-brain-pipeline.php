<?php
/**
 * Brain Pipeline — internal flow router for admin chat.
 *
 * @FLOW Router
 * @INVARIANT Anti-parche: this file routes only (strict order). Business rules live inside flows.
 * @INVARIANT Queries must be read-only (no pending, no buttons). Full context never reaches the model.
 *
 * Goals:
 * - Make it easy to add new features without touching the monolithic handler logic.
 * - Keep behaviour stable (no external changes).
 *
 * How to extend:
 * - Add a new handler method/class that inspects the request and returns a WP_REST_Response when it applies.
 * - If it doesn't apply, return null so the pipeline falls through.
 *
 * Invariants:
 * - Queries MUST NOT create pending_action.
 * - UI action buttons are derived ONLY from store_state.pending_action (server-side truth).
 * - Full context is NEVER sent to the model.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Flows used by the pipeline.
// Keep paths consistent (APAI_BRAIN_PATH) to avoid accidental double-includes
// across different include path representations.
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-pending-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-followup-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-intent-parse-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-deterministic-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-targeted-update-a2-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-targeted-update-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-query-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-info-query-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-target-correction-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-model-flow.php';

class APAI_Brain_Pipeline {

    private static function trace_route( $request, $route, $message_norm = '' ) {
        if ( ! class_exists( 'APAI_Brain_Trace' ) ) {
            return;
        }
        $trace_id = null;
        if ( $request && method_exists( $request, 'get_param' ) ) {
            $trace_id = $request->get_param( '_apai_trace_id' );
        }
        if ( ! is_string( $trace_id ) || $trace_id === '' ) {
            $trace_id = APAI_Brain_Trace::current_trace_id();
        }
        if ( ! is_string( $trace_id ) || $trace_id === '' ) {
            return;
        }
        APAI_Brain_Trace::emit( $trace_id, 'route', array(
            'route' => (string) $route,
            'message_norm' => (string) $message_norm,
        ) );
    }

    /**
     * Lightweight route logging for audit/debug.
     *
     * @INVARIANT No behavioural changes: logging only.
     */
    private static function log_route( $route, $message_norm = '' ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $m = (string) $message_norm;
            if ( strlen( $m ) > 120 ) { $m = substr( $m, 0, 120 ) . '…'; }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[APAI_ROUTE] ' . (string) $route . ' | ' . $m );
        }
    }

	/** Attach route info to response meta (debug only). */
	private static function with_route_meta( $resp, $route, WP_REST_Request $request = null ) {
        if ( ! is_array( $resp ) ) { return $resp; }
        if ( ! isset( $resp['meta'] ) || ! is_array( $resp['meta'] ) ) { $resp['meta'] = array(); }
        $resp['meta']['route'] = (string) $route;

        // OBS: feature/action are small, stable debug labels (no UX impact).
        // feature: broad subsystem (Brain), action: route (flow).
        if ( ! isset( $resp['meta']['feature'] ) ) { $resp['meta']['feature'] = 'brain'; }
        if ( ! isset( $resp['meta']['action'] ) ) { $resp['meta']['action'] = (string) $route; }


		// Provide trace_id to the UI (used by the "Copiar" button to pull trace excerpts).
		// Prefer the request-scoped trace id if present; fallback to the global.
		$tid = '';
		if ( $request instanceof WP_REST_Request ) {
			$tid = (string) $request->get_param( '_apai_trace_id' );
		}
		if ( $tid === '' && class_exists( 'APAI_Brain_Trace' ) ) {
			$tid = (string) APAI_Brain_Trace::current_trace_id();
		}
		if ( $tid !== '' ) {
			$resp['meta']['trace_id'] = $tid;
		}

        return $resp;
    }

	/**
	 * Standard REST response wrapper: ensures meta.trace_id and exposes it in a header.
	 * This makes the frontend trace collector robust even if some layer strips meta.
	 */
	private static function wrap_rest_response( $resp, $route, WP_REST_Request $request, $status = 200 ) {
        $out = self::with_route_meta( $resp, $route, $request );

        $tid = '';
        $feature = '';
        $action  = '';

        if ( is_array( $out ) && isset( $out['meta'] ) && is_array( $out['meta'] ) ) {
            if ( isset( $out['meta']['trace_id'] ) ) { $tid = (string) $out['meta']['trace_id']; }
            if ( isset( $out['meta']['feature'] ) ) { $feature = (string) $out['meta']['feature']; }
            if ( isset( $out['meta']['action'] ) ) { $action = (string) $out['meta']['action']; }
        }

        $res = new WP_REST_Response( $out, (int) $status );

        // Headers are useful for debugging in DevTools and for external log correlation.
        if ( $tid !== '' ) {
            $res->header( 'X-APAI-Trace-Id', $tid );
            // Some hosts/proxies strip unknown X-* headers; keep a non-X mirror for debugging.
            $res->header( 'APAI-Trace-Id', $tid );
        }
        $res->header( 'X-APAI-Route', (string) $route );
        $res->header( 'APAI-Route', (string) $route );
        if ( $feature !== '' ) {
            $res->header( 'X-APAI-Feature', $feature );
            $res->header( 'APAI-Feature', $feature );
        }
        if ( $action !== '' ) {
            $res->header( 'X-APAI-Action', $action );
            $res->header( 'APAI-Action', $action );
        }

        // CORS: expose debug headers so JS (and DevTools in some contexts) can see them.
        // This is a no-op for same-origin, but helps for SaaS and embedded scenarios.
        $want_expose = array(
            'X-APAI-Trace-Id', 'X-APAI-Route', 'X-APAI-Feature', 'X-APAI-Action',
            'APAI-Trace-Id', 'APAI-Route', 'APAI-Feature', 'APAI-Action',
        );
        $existing = $res->get_headers();
        $cur = '';
        if ( is_array( $existing ) && isset( $existing['Access-Control-Expose-Headers'] ) ) {
            $cur = (string) $existing['Access-Control-Expose-Headers'];
        }
        $parts = array();
        if ( $cur !== '' ) {
            foreach ( explode( ',', $cur ) as $p ) {
                $p = trim( $p );
                if ( $p !== '' ) { $parts[] = $p; }
            }
        }
        foreach ( $want_expose as $h ) { $parts[] = $h; }
        $parts = array_values( array_unique( $parts ) );
        $res->header( 'Access-Control-Expose-Headers', implode( ', ', $parts ) );

        // OBS: add a compact response event to trace buffer (no behaviour change).
        try {
            if ( class_exists( 'APAI_Brain_Trace' ) && $tid !== '' ) {
                $has_pending = false;
                $actions_n = 0;
                if ( is_array( $out ) ) {
                    if ( isset( $out['store_state'] ) && is_array( $out['store_state'] ) && ! empty( $out['store_state']['pending_action'] ) ) {
                        $has_pending = true;
                    }
                    if ( isset( $out['actions'] ) && is_array( $out['actions'] ) ) {
                        $actions_n = count( $out['actions'] );
                    }
                }
                APAI_Brain_Trace::emit( $tid, 'response', array(
                    'route'       => (string) $route,
                    'feature'     => (string) $feature,
                    'action'      => (string) $action,
                    'has_pending' => $has_pending,
                    'actions_n'   => $actions_n,
                    'status'      => (int) $status,
                ) );
            }
        } catch ( \Throwable $e ) {
            // swallow
        }

        return $res;
    }

    /**
     * Execute pipeline.
     *
     * NOTE: Legacy fallback is intentionally disabled. The monolithic legacy
     * handler caused inconsistent pending behaviour and is being replaced
     * incrementally by flows.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function run( $request ) {
        // Strict priority order:
        // 1) Queries (A1–A8 + followups)
        // 2) Pending flow (confirm/cancel/edit)
        // 3) Followups (short replies bound to last_product)
        // 4) IntentParse (brain_parse: IA determinista -> allowlist)
        // 5) Deterministic (last/first price/stock)
        // 6) Targeted updates (explicit product by ID/SKU/name)
        // 7) Model

        $params  = $request->get_json_params();
        $message = isset( $params['message'] ) ? sanitize_textarea_field( $params['message'] ) : '';
        // Normalize once for all flows.
        $m_norm = class_exists( 'APAI_Brain_Normalizer' )
            ? APAI_Brain_Normalizer::normalize_intent_text( $message )
            : $message;

        // Flow handlers are loaded at file load time (require_once above).

        // Read server-side state once.
        $store_state = class_exists( 'APAI_Brain_Memory_Store' )
            ? APAI_Brain_Memory_Store::store_get_state()
            : array();
        // Always normalize pending_action (back-compat):
        // - Preferred envelope: { type, action, created_at }
        // - Legacy: action stored directly (array with 'type')
        // WHY: Some stores may still carry legacy pending_action format.
        $pending = null;
        if ( class_exists( 'APAI_Brain_Memory_Store' ) && method_exists( 'APAI_Brain_Memory_Store', 'extract_pending_action_from_store' ) ) {
            $pending = APAI_Brain_Memory_Store::extract_pending_action_from_store( $store_state );
        } elseif ( isset( $store_state['pending_action'] ) ) {
            $pending = $store_state['pending_action'];
        }


		// 1) PendingFlow (confirm/cancel/correct).
		// NOTE: This must run first so we don't accidentally interpret "confirmar" or "cancelar" as something else.
        if ( $resp = APAI_Brain_Pending_Flow::try_handle( $message, $m_norm, $store_state, $pending ) ) {
            self::log_route( 'PendingFlow', $m_norm );
            self::trace_route( $request, 'PendingFlow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
            return self::wrap_rest_response( $resp, 'PendingFlow', $request, 200 );
        }

		// 2) FollowupFlow (short replies bound to last_product / pending followups)
		// WHY: If we asked something like "¿precio o stock?" and the user answers "stock",
		// we must resolve that before InfoQueryFlow hijacks "stock" as a read-only question.
        if ( class_exists( 'APAI_Brain_Followup_Flow' ) && ( $resp = APAI_Brain_Followup_Flow::try_handle( $message, $m_norm, $store_state ) ) ) {
            self::log_route( 'FollowupFlow', $m_norm );
            self::trace_route( $request, 'FollowupFlow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'FollowupFlow', $request, 200 );
        }

		// 3) QueryFlow
		if ( $resp = APAI_Brain_Query_Flow::try_handle( $message, $m_norm, null, $store_state ) ) {
			self::log_route( 'QueryFlow', $m_norm );
			self::trace_route( $request, 'QueryFlow', $m_norm );
			$resp = self::ensure_store_state_in_response( $resp );
				return self::wrap_rest_response( $resp, 'QueryFlow', $request, 200 );
		}

		// 3.5) InfoQueryFlow (read-only questions)
		if ( class_exists( 'APAI_Brain_Info_Query_Flow' ) && ( $resp = APAI_Brain_Info_Query_Flow::try_handle( $message, $m_norm, $store_state ) ) ) {
			self::log_route( 'InfoQueryFlow', $m_norm );
			self::trace_route( $request, 'InfoQueryFlow', $m_norm );
			$resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'InfoQueryFlow', $request, 200 );
		}

        // 3.5) TargetCorrectionFlow ("perdón, era el #149")
        // Updates last_target_product_id so follow-ups like "¿qué stock tiene?" hit the corrected product.
	        if ( class_exists( 'APAI_Brain_Target_Correction_Flow' ) ) {
	            // Static on purpose: avoids constructor wiring and keeps pipeline deterministic.
	            $resp = APAI_Brain_Target_Correction_Flow::try_handle( $message, $m_norm, $store_state );
	        }
	        if ( $resp ) {
            self::log_route( 'TargetCorrectionFlow', $m_norm );
            self::trace_route( $request, 'TargetCorrectionFlow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'TargetCorrectionFlow', $request, 200 );
        }

        // 4) TargetedUpdateFlow (A2: nombre/descripcion corta/categorias)
        // WHY: Si el usuario ya dio contexto suficiente (ID/SKU + campo + valor), no queremos
        // que la IA haga repreguntas genéricas. Esto mantiene el chat "humano" y rápido.
        if ( class_exists( 'APAI_Brain_Targeted_Update_A2_Flow' ) && ( $resp = APAI_Brain_Targeted_Update_A2_Flow::try_handle( $message, $m_norm, $store_state ) ) ) {
            self::log_route( 'TargetedUpdateA2Flow', $m_norm );
            self::trace_route( $request, 'TargetedUpdateA2Flow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'TargetedUpdateA2Flow', $request, 200 );
        }

        // 5) DeterministicFlow (price/stock last/first)
        if ( $resp = APAI_Brain_Deterministic_Flow::try_handle( $message, $m_norm, $store_state ) ) {
            self::log_route( 'DeterministicFlow', $m_norm );
            self::trace_route( $request, 'DeterministicFlow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'DeterministicFlow', $request, 200 );
        }

        // 6) TargetedUpdateFlow (price/stock for explicit product targets by ID/SKU/name)
        if ( $resp = APAI_Brain_Targeted_Update_Flow::try_handle( $message, $m_norm, $store_state ) ) {
            self::log_route( 'TargetedUpdateFlow', $m_norm );
            self::trace_route( $request, 'TargetedUpdateFlow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'TargetedUpdateFlow', $request, 200 );
        }

        // 7) IntentParseFlow (brain_parse: IA -> allowlist)
        // WHY: Solo como fallback cuando no hay una ruta determinista segura.
        if ( class_exists( 'APAI_Brain_Intent_Parse_Flow' ) && ( $resp = APAI_Brain_Intent_Parse_Flow::try_handle( $message, $m_norm, $store_state ) ) ) {
            self::log_route( 'IntentParseFlow', $m_norm );
            self::trace_route( $request, 'IntentParseFlow', $m_norm );
            $resp = self::ensure_store_state_in_response( $resp );
			return self::wrap_rest_response( $resp, 'IntentParseFlow', $request, 200 );
        }

        // 7) ChitChatFlow (human chat, no pending)
        if ( class_exists( 'APAI_Brain_ChitChat_Flow' ) ) {
            // Pass $pending so ChitChatFlow can avoid stepping on action workflows.
            $resp = APAI_Brain_ChitChat_Flow::handle( $message, $m_norm, $store_state, $pending );
            if ( is_array( $resp ) ) {
                self::log_route( 'ChitChatFlow', $m_norm );
                self::trace_route( $request, 'ChitChatFlow', $m_norm );
                $resp = self::ensure_store_state_in_response( $resp );
                return self::wrap_rest_response( $resp, 'ChitChatFlow', $request, 200 );
            }
        }

        // 8) ModelFlow (safe fallback: no side-effects)
        self::log_route( 'ModelFlow', $m_norm );
        self::trace_route( $request, 'ModelFlow', $m_norm );
        $resp = APAI_Brain_Model_Flow::handle( $message, $m_norm, $store_state, $pending );
        $resp = self::ensure_store_state_in_response( $resp );
		return self::wrap_rest_response( $resp, 'ModelFlow', $request, 200 );
    }

    /**
     * The REST adapter previously ensured store_state was always present.
     * Keep the invariant here so debug JSON remains stable.
     */
    private static function ensure_store_state_in_response( $resp ) {
        if ( ! is_array( $resp ) ) {
            return $resp;
        }
        if ( ! isset( $resp['ok'] ) ) {
            $resp['ok'] = true;
        }
        if ( ! isset( $resp['store_state'] ) || ! is_array( $resp['store_state'] ) ) {
            $resp['store_state'] = class_exists( 'APAI_Brain_Memory_Store' )
                ? APAI_Brain_Memory_Store::store_get_state()
                : array();
        }
        return $resp;
    }
}
