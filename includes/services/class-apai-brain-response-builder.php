<?php
/**
 * @FLOW Brain
 * @INVARIANT Un solo ResponseBuilder para todas las respuestas del Brain.
 * WHY: Evita formatos divergentes y permite desacoplar APAI_Brain_REST sin cambiar comportamiento.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Response_Builder {

    /**
     * F6.6 — NLG controlado
     * Un prompt de confirmación único y consistente.
     * (Los flows pueden pasar confirmation con prompt vacío; acá lo normalizamos.)
     */
    private static function default_confirmation_prompt() {
        return '¿Confirmás ejecutar esta acción?';
    }

    /**
     * Build a structured Brain response (v1) with backward-compatible fields.
     *
     * @UI_CONTRACT
     * - Admin chat UI must show action buttons ONLY when store_state.pending_action != null.
 * - Target selector buttons (2–5 candidates) may be shown when store_state.pending_target_selection != null.
     * - Therefore, every response MUST include store_state (server truth), even for queries.
     *
     * NOTE: Esta implementación está copiada 1:1 desde APAI_Brain_REST::make_response
     * para mantener compatibilidad total (sin cambios externos).
     */
    public static function make_response( $mode, $message_to_user, $actions = array(), $confirmation = null, $clarification = null, $meta = array() ) {
        // Normaliza message_to_user a string para evitar "Array to string conversion".
        if ( is_array( $message_to_user ) ) {
            $message_to_user = implode( "\n", array_map( 'strval', $message_to_user ) );
        } elseif ( is_object( $message_to_user ) ) {
            $message_to_user = wp_json_encode( $message_to_user );
        }

        $actions_norm = is_array( $actions ) ? array_values( $actions ) : array();
        $first_action = isset( $actions_norm[0] ) ? $actions_norm[0] : null;

        // Read the server-side store_state early so we can attach it and also
        // surface the latest action event (executed/cancelled) in a stable way.
        $store_state = class_exists( 'APAI_Brain_Memory_Store' ) ? APAI_Brain_Memory_Store::get() : array();

	    // Ensure pending_action is executable by the frontend.
	    // The admin UI renders the pending card from store_state.pending_action and expects
	    // a nested `action` object that contains `changes` (and related metadata). If it's
	    // missing, the UI falls back to a generic action and loses `changes`, which causes
	    // confirmations to incorrectly no-op.
	    if ( isset( $store_state['pending_action'] ) && is_array( $store_state['pending_action'] ) ) {
	        $pa = $store_state['pending_action'];
	        if ( ! isset( $pa['action'] ) || ! is_array( $pa['action'] ) ) {
	            $kind   = isset( $pa['kind'] ) ? (string) $pa['kind'] : '';
	            $pid    = isset( $pa['product_id'] ) ? intval( $pa['product_id'] ) : 0;
	            $changes = isset( $pa['changes'] ) && is_array( $pa['changes'] ) ? $pa['changes'] : array();

	            // Best-effort, honest summary (never claim to have applied it).
	            $summary = 'Tenés una acción pendiente de modificación.';
	            if ( $pid > 0 ) {
	                if ( $kind === 'stock' && isset( $changes['stock_quantity'] ) ) {
	                    $summary = 'Actualizar el stock de #' . $pid . ' a ' . intval( $changes['stock_quantity'] ) . '.';
	                } elseif ( $kind === 'price' && isset( $changes['regular_price'] ) ) {
	                    $summary = 'Actualizar el precio de #' . $pid . ' a ' . $changes['regular_price'] . '.';
	                } else {
	                    $summary = 'Tenés una acción pendiente de modificación (ID ' . $pid . ').';
	                }
	            }

	            $pa['action'] = array(
	                'type'         => isset( $pa['type'] ) ? (string) $pa['type'] : 'update_product',
	                'kind'         => $kind,
	                'product_id'    => $pid,
	                'changes'       => $changes,
	                'change_keys'   => array_keys( $changes ),
	                'human_summary' => $summary,
	            );
	            $store_state['pending_action'] = $pa;
	        }
	    }

        // Persisted "last_event" badge (executed/cancelled) must exist for audit/UX,
        // but MUST NOT spam old events on unrelated turns.
        // @INVARIANT: show the badge only when it's fresh (just happened) and when this
        // response is NOT already proposing an action.
        // Queries (mode=consult) must never prepend last_event into the reply.
        // WHY: A1–A8 are read-only and should not look like they "executed" something.
        // Execution/cancel confirmation is already communicated on the action turn itself.
        // IMPORTANT (F3b): never prepend last_event when there is a pending action.
        // WHY: If the user chats ("hola", "gracias", etc.) while a pending action exists,
        // showing the *last* executed/cancelled badge creates a false impression that something
        // just happened now. Execution feedback already happens at execution time.
	    // NOTE: We keep last_event persisted for audit/debug (store_state), but we DO NOT
	    // prepend it into chat replies. The UI already shows executed/cancelled feedback on
	    // the action turn itself; re-prepending it on later messages (e.g. "hola") confuses users.
	    $has_pending = ( is_array( $store_state ) && ! empty( $store_state['pending_action'] ) );
	    $should_prepend_last_event = false;

        if ( $should_prepend_last_event && is_array( $store_state ) && isset( $store_state['last_event'] ) && is_array( $store_state['last_event'] ) ) {
            $ev = $store_state['last_event'];
            $ev_type = isset( $ev['type'] ) ? (string) $ev['type'] : '';
            $ev_summary = isset( $ev['summary'] ) ? trim( (string) $ev['summary'] ) : '';
            $ev_ts = isset( $ev['timestamp'] ) ? (int) $ev['timestamp'] : 0;

            // Freshness window: only prepend if the event happened very recently.
            // WHY: evita que un "cancelled" viejo aparezca pegado a consultas (A1–A8)
            // y genere confusión, mientras mantenemos el evento persistido en store_state.
            $is_fresh = ( $ev_ts > 0 ) ? ( ( time() - $ev_ts ) <= 20 ) : false;

            if ( ! $is_fresh ) {
                // Keep last_event in store_state but do not prepend it to the message.
                $ev_summary = '';
            }

            if ( $ev_summary !== '' ) {
                $ev_summary_clean = rtrim( $ev_summary, ". \t\n\r\0\x0B" );

                $ev_line = '';
                if ( $ev_type === 'executed' ) {
                    $ev_line = '✅ Acción ejecutada: ' . $ev_summary_clean . '.';
                } elseif ( $ev_type === 'cancelled' ) {
                    $ev_line = '❌ Acción cancelada: ' . $ev_summary_clean . '.';
                }

                if ( $ev_line !== '' ) {
                    $msg_norm = rtrim( (string) $message_to_user );
                    if ( strpos( $msg_norm, $ev_line ) === false ) {
                        $message_to_user = $ev_line . "\n\n" . (string) $message_to_user;
                    }
                }
            }
        }

	    $meta_norm = is_array( $meta ) ? $meta : array();
	    // Provide current trace_id for the frontend (copy can fetch trace excerpt).
	    if ( ! isset( $meta_norm['trace_id'] ) && class_exists( 'APAI_Brain_Trace' ) ) {
	        $tid = APAI_Brain_Trace::current_trace_id();
	        if ( is_string( $tid ) && $tid !== '' ) {
	            $meta_norm['trace_id'] = $tid;
	        }
	    }

	    // --- F6.6: NLG controlado (confirmación coherente) ---
	    // Si un flow pide confirmación pero deja el prompt vacío, lo completamos.
	    if ( is_array( $confirmation ) ) {
	        $required = isset( $confirmation['required'] ) ? (bool) $confirmation['required'] : false;
	        $prompt   = isset( $confirmation['prompt'] ) ? trim( (string) $confirmation['prompt'] ) : '';
	        if ( $required && $prompt === '' ) {
	            $confirmation['prompt'] = self::default_confirmation_prompt();
	        }
	    }

	    // Guardrail: si NO hay acción propuesta ni pending_action, evitamos respuestas del estilo
	    // "usá el botón confirmar" cuando NO hay botones/pending. OJO: NO debemos dispararlo por
	    // cualquier frase que contenga la palabra "confirmar" (porque rompe UX en clarifications).
	    if ( empty( $actions_norm ) && ( ! is_array( $store_state ) || empty( $store_state['pending_action'] ) ) ) {
	        $msg_lc = strtolower( (string) $message_to_user );
	        $looks_like_pending_instructions = ( strpos( $msg_lc, 'botón' ) !== false && strpos( $msg_lc, 'confirm' ) !== false )
	            || ( strpos( $msg_lc, 'confirmar y ejecutar' ) !== false )
	            || ( strpos( $msg_lc, 'usá el botón' ) !== false && strpos( $msg_lc, 'confirm' ) !== false );
	        if ( $looks_like_pending_instructions ) {
	            $message_to_user = 'Parece que no hay acciones pendientes en tu tienda, todo está en orden. Si querés hacer un cambio, decime por ejemplo: "cambiá el precio del último a 200".';
	        }
	    }

	    $payload = array(
	        'ok'             => true,
	        'mode'           => $mode,
	        'message_to_user'=> (string) $message_to_user,
	        'actions'        => $actions_norm,
	        'confirmation'   => $confirmation,
	        'clarification'  => $clarification,
	        'meta'           => $meta_norm,
	        // Backward compatibility:
	        'reply'          => (string) $message_to_user,
	        'action'         => $first_action,
	    );

        // Attach store_state (UI-only; never sent to the model).
        if ( is_array( $store_state ) ) {
            $payload['store_state'] = $store_state;
        }
        return $payload;
    }

	/**
	 * Helper/back-compat used by multiple flows.
	 *
	 * Supported call shapes:
	 * - action_prepared( $action, $message )
	 * - action_prepared( $message, $action, $confirmation )
	 * - action_prepared( $action, $meta )
	 * - action_prepared( $action )
	 */
	public static function action_prepared() {
		$args = func_get_args();
		$message      = null;
		$action       = null;
		$confirmation = null;
		$meta         = array();

		if ( empty( $args ) ) {
			return self::make_response( 'chat', '' );
		}

		// Normalize common call shapes.
		if ( is_array( $args[0] ) ) {
			$action = $args[0];
			if ( isset( $args[1] ) ) {
				if ( is_array( $args[1] ) ) {
					$meta = $args[1];
				} else {
					$message = strval( $args[1] );
				}
			}
			if ( isset( $args[2] ) && is_string( $args[2] ) ) {
				$confirmation = $args[2];
			}
			if ( isset( $args[3] ) && is_array( $args[3] ) ) {
				$meta = $args[3];
			}
		} elseif ( is_string( $args[0] ) ) {
			$message = strval( $args[0] );
			if ( isset( $args[1] ) && is_array( $args[1] ) ) {
				$action = $args[1];
			}
			if ( isset( $args[2] ) && is_string( $args[2] ) ) {
				$confirmation = $args[2];
			}
			if ( isset( $args[2] ) && is_array( $args[2] ) ) {
				$meta = $args[2];
			}
			if ( isset( $args[3] ) && is_array( $args[3] ) ) {
				$meta = $args[3];
			}
		}

		if ( ! is_array( $action ) ) {
			$action = null;
		}

		if ( null === $message || '' === trim( $message ) ) {
			$message = self::default_action_prepared_message( $action, $confirmation );
		}

		$actions = array();
		if ( is_array( $action ) ) {
			$actions = array( $action );
		}

		return self::make_response( 'chat', $message, $actions, $confirmation, null, $meta );
	}

	/**
	 * Produce a safe, human-facing default message for an action-prepared state.
	 */
	private static function default_action_prepared_message( $action, $confirmation = null ) {
		$summary = '';
		if ( is_array( $action ) ) {
			if ( isset( $action['human_summary'] ) && is_string( $action['human_summary'] ) ) {
				$summary = $action['human_summary'];
			} elseif ( isset( $action['summary'] ) && is_string( $action['summary'] ) ) {
				$summary = $action['summary'];
			}
		}

		$summary = trim( wp_strip_all_tags( $summary ) );
		if ( '' !== $summary ) {
			$msg = 'Dale, preparé la acción: ' . rtrim( $summary, " .\t\n\r\0\x0B" ) . '.';
		} else {
			$msg = 'Dale, preparé la acción.';
		}

		if ( null !== $confirmation && is_string( $confirmation ) && '' !== trim( $confirmation ) ) {
			$msg .= "\n\n" . trim( $confirmation );
		} else {
			$msg .= "\n\n¿Confirmás para ejecutarlo?";
		}

		return $msg;
	}

	/**
	 * Convenience helper for clarification responses.
	 *
	 * IMPORTANT: This must remain SAFE: it only produces a chat response with
	 * optional "clarification" payload; it never creates pending actions.
	 */
	public static function clarify( $message, $question = '', $options = array(), $clarification_raw = array(), $meta = array() ) {
		$clarification = array();
		if ( is_array( $clarification_raw ) ) {
			$clarification = $clarification_raw;
		}
		// Ensure the shape is stable.
		$clarification['question'] = is_string( $question ) ? $question : '';
		$clarification['options']  = is_array( $options ) ? array_values( $options ) : array();

		return self::make_response( 'chat', $message, array(), null, $clarification, is_array( $meta ) ? $meta : array() );
	}
}
