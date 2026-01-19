<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * PendingFlow
 *
 * Conservative handling when a pending action exists.
 *
 * Rules:
 * - With pending_action: intercept anything that is NOT confirm/cancel/correction.
 *   (Queries A1–A8 are handled earlier by QueryFlow and remain read-only.)
 * - Confirmation/cancellation is expected to be done via UI buttons.
 */
class APAI_Brain_Pending_Flow {

	/**
	 * Normalize pending_action to the preferred envelope shape:
	 *   { type, action, created_at }
	 *
	 * Back-compat:
	 * - Legacy: pending_action stored directly as an action (array with 'type')
	 * - Older debug/QA shapes (e.g. {type, summary, after, ...})
	 *
	 * @param mixed $pending_action
	 * @return array|null
	 */
	private static function normalize_pending_envelope( $pending_action ) {
		if ( ! is_array( $pending_action ) || empty( $pending_action ) ) {
			return null;
		}

		// Preferred: wrapper already present.
		if ( isset( $pending_action['action'] ) && is_array( $pending_action['action'] ) ) {
			if ( empty( $pending_action['type'] ) && isset( $pending_action['action']['type'] ) ) {
				$pending_action['type'] = sanitize_text_field( (string) $pending_action['action']['type'] );
			}
			if ( empty( $pending_action['created_at'] ) ) {
				$pending_action['created_at'] = time();
			}
			return $pending_action;
		}

		// Legacy: action stored directly (or QA/debug shapes that still include 'type').
		if ( isset( $pending_action['type'] ) ) {
			$action = $pending_action;

			// Map older debug fields into the canonical action shape.
			if ( ! isset( $action['human_summary'] ) && isset( $action['summary'] ) ) {
				$action['human_summary'] = (string) $action['summary'];
			}
			if ( ! isset( $action['changes'] ) && isset( $action['after'] ) && is_array( $action['after'] ) ) {
				$action['changes'] = $action['after'];
			}

			return array(
				'type'       => sanitize_text_field( (string) $pending_action['type'] ),
				'action'     => $action,
				'created_at' => isset( $pending_action['created_at'] ) ? intval( $pending_action['created_at'] ) : time(),
			);
		}

		return null;
	}

	/**
	 * Signature used by the Pipeline.
	 *
	 * @param string $message_raw
	 * @param string $message_norm
	 * @param array|null $store_state
	 * @param array|null $pending
	 * @return array|null
	 */
	public static function try_handle( $message_raw, $message_norm, $store_state, $pending ) {
		return self::handle_message( $message_raw, $message_norm, $store_state, $pending );
	}

	/**
	 * @param string $message_raw
	 * @param string $message_norm
	 * @param array|null $pending
	 * @return array|null
	 */
	private static function handle_message( $message_raw, $message_norm, $store_state, $pending ) {
		$pending_action = $pending;
		$penv = self::normalize_pending_envelope( $pending_action );
		$has_target_selection = ( is_array( $store_state ) && ! empty( $store_state['pending_target_selection'] ) );

		// No pending action, but there is a pending target selection (e.g. after asking user to pick a product).
		if ( $penv === null && $has_target_selection ) {
			$raw  = trim( (string) $message_raw );
			$norm = trim( (string) $message_norm );
			$norm_lc = strtolower( $norm );

			// Cancel selection.
			if ( self::is_cancel_token( $norm_lc ) ) {
				if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
					APAI_Brain_Memory_Store::update_state( array(
							'pending_target_selection' => null,
							'pending_targeted_update'  => null,
							'last_event' => array(
								'type' => 'cancelled_target_selection',
								'summary' => 'Selección de producto',
								'timestamp' => time(),
							),
						) );
				}
				return APAI_Brain_Response_Builder::make_response(
					'chat',
					'✅ Selección cancelada. Decime qué querés hacer y con qué producto 😊',
					array(),
					null,
					null,
					array( 'should_clear_pending' => true )
				);
			}

			// If it looks like the user is answering the selection (index / ID / SKU), let TargetedUpdateFlow handle.
			// IMPORTANT: During target selection we allow a plain product name/title reply to flow through,
			// but we keep blocking anything that looks like a NEW action request.
			if ( self::looks_like_target_selection_reply( $raw, $norm_lc ) ) {
				return null;
			}

			// Otherwise, block new actions until the user chooses (or cancels).
			return APAI_Brain_Response_Builder::make_response(
				'chat',
				'Tengo una selección de producto pendiente. Elegí una opción de la lista (o decime el ID/SKU), o escribí "cancelar" para dejarla de lado.',
				array()
			);
		}


		// No pending action recognized.
		if ( $penv === null ) {
			// Guardrail: if the user typed "confirmar" / "cancelar" but there is no pending_action,
			// do NOT let other flows (semantic/model) reinterpret it as a new intent.
			$norm_lc = strtolower( trim( (string) $message_norm ) );
			if ( self::is_confirm_token( $norm_lc ) ) {
				// Also clear any followup envelope so a stray confirm does not keep a latent followup around.
				if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
					APAI_Brain_Memory_Store::patch( array(
						'pending_followup_action' => null,
						'pending_last_update'     => null,
					) );
				}
				return APAI_Brain_Response_Builder::make_response(
					'chat',
					'Parece que no hay acciones pendientes en tu tienda, todo está en orden. Si querés hacer un cambio, decime por ejemplo: "cambiá el precio del último a 200".',
					array()
				);
			}
			if ( self::is_cancel_token( $norm_lc ) ) {
				if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
					APAI_Brain_Memory_Store::patch( array(
						'pending_followup_action' => null,
						'pending_last_update'     => null,
					) );
				}
				return APAI_Brain_Response_Builder::make_response(
					'chat',
					'No hay ninguna acción pendiente para cancelar. Decime qué querés hacer y lo preparo con botones.',
					array()
				);
			}
			return null;
		}
		$pending_action = $penv;

		$raw  = trim( (string) $message_raw );
		$norm = trim( (string) $message_norm );
		$norm_lc = strtolower( $norm );
		$continue_after_cancel = ( $norm_lc === 'cancelar y continuar' );

		// F6.7 QA: si hay pending_action, incluso smalltalk debe ser interceptado
		// para que el usuario no "pierda" la acción propuesta.
		if ( self::is_smalltalk( $norm_lc ) ) {
			$action  = ( isset( $pending_action['action'] ) && is_array( $pending_action['action'] ) ) ? $pending_action['action'] : null;
			$actions = $action ? array( $action ) : array();
			$msg     = APAI_Brain_NLG::msg_pending_block();
			return APAI_Brain_Response_Builder::make_response(
				'chat',
				$msg,
				$actions,
				null,
				null,
				array( 'pending_intercept' => true )
			);
		}

		// Explicit UI button tokens.
		if ( self::is_cancel_token( $norm_lc ) ) {
			$summary = isset( $pending_action['action']['human_summary'] ) ? $pending_action['action']['human_summary'] : 'Acción pendiente';
			// Canceling a pending action should also clear followup breadcrumbs.
			if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
				APAI_Brain_Memory_Store::patch( array(
					'pending_followup_action' => null,
					'pending_last_update'     => null,
				) );
			}
			$pid = 0;
			if ( isset( $pending_action['action']['product_id'] ) ) {
				$pid = intval( $pending_action['action']['product_id'] );
			} elseif ( isset( $pending_action['action']['target'] ) && is_array( $pending_action['action']['target'] ) && isset( $pending_action['action']['target']['product_id'] ) ) {
				$pid = intval( $pending_action['action']['target']['product_id'] );
			}

			if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
				APAI_Brain_Memory_Store::update_state( self::build_cancel_patch( $summary, $pid ) );
			}
			return APAI_Brain_Response_Builder::make_response(
					'chat',
					APAI_Brain_NLG::msg_pending_cancelled( $summary, $continue_after_cancel ),
					array(),
					null,
					null,
					array( 'should_clear_pending' => true, 'pending_cleared_label' => '❌ Cancelada', 'pending_cleared_kind' => 'cancel', 'pending_cleared_summary' => $summary )
			);
		}

		// We keep confirm handling minimal and deterministic.
		// The UI confirmation button usually triggers executor-side effects; here we only guide.
		if ( self::is_confirm_token( $norm_lc ) ) {
			return APAI_Brain_Response_Builder::make_response(
				'chat',
				APAI_Brain_NLG::msg_pending_confirm_hint(),
				array( $pending_action['action'] )
			);
		}

		// Follow-up edits (price/stock) like: "mejor a 50000".
		$patched = self::patch_pending_action_from_followup( $pending_action, $raw, $norm_lc );
		if ( is_array( $patched ) ) {
			if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
				APAI_Brain_Memory_Store::persist_pending_action( $patched );
					$k = ( isset( $patched['changes'] ) && is_array( $patched['changes'] ) && array_key_exists( 'regular_price', $patched['changes'] ) ) ? 'price' : ( ( isset( $patched['changes'] ) && is_array( $patched['changes'] ) && array_key_exists( 'stock_quantity', $patched['changes'] ) ) ? 'stock' : null );
					if ( $k ) { APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => $k ) ); }
					}
			return APAI_Brain_Response_Builder::make_response(
				'chat',
				APAI_Brain_NLG::msg_pending_adjusted(),
				array( $patched )
			);
		}



		// If user writes a NEW action request while something is pending, show the choice UI.
		if ( self::looks_like_new_action_request( $norm_lc ) ) {
			// UX: si el usuario está ajustando la MISMA acción sobre el MISMO producto pendiente,
			// no lo bloqueamos con \"confirm/cancel\": actualizamos la acción pendiente (merge de cambios)
			// y re-mostramos la tarjeta actualizada.
			$merged = self::try_merge_pending_update_if_same_product( $pending_action, $raw, $norm_lc, $store_state );
			if ( is_array( $merged ) ) {
				return $merged;
			}

			// UX: nombres consistentes y claros.
			// - confirm: ejecuta/continúa con la acción pendiente.
			// - cancel: reemplaza la pendiente por la nueva intención del usuario.
			$labels = array(
				'confirm' => 'Seguir con la pendiente',
				'cancel'  => 'Reemplazar por la nueva',
			);
			return APAI_Brain_Pending_UI::build_choice_payload( $pending_action, 'switch', $raw, $labels );
		}

			// Anything else: bloqueamos hasta resolver la acción pendiente (F6.7).
			$action  = ( isset( $pending_action['action'] ) && is_array( $pending_action['action'] ) ) ? $pending_action['action'] : null;
			$actions = $action ? array( $action ) : array();
			$msg     = APAI_Brain_NLG::msg_pending_block();
			return APAI_Brain_Response_Builder::make_response(
				'chat',
				$msg,
				$actions,
				null,
				null,
				array(
					'pending_intercept' => true,
				)
			);
	}

	
	private static function build_cancel_patch( $summary, $pid ) {
		$patch = array(
			'pending_action' => null,
			'pending_followup_action' => null,
			'pending_last_update' => null,
			'last_event' => array(
				'type' => 'cancelled',
				'summary' => $summary,
				'timestamp' => time(),
			),
		);
		if ( intval( $pid ) > 0 ) {
			$patch['last_target_product_id'] = intval( $pid );
		}
		return $patch;
	}

private static function is_smalltalk( $norm_lc ) {
		$txt = trim( (string) $norm_lc );
		if ( $txt === '' ) { return true; }

		// IMPORTANTE:
		// Con una acción pendiente, el usuario puede hablar "normal".
		// Sólo consideramos smalltalk cuando el mensaje es CORTO y NO parece un comando.
		if ( strlen( $txt ) > 24 ) { return false; }
		if ( preg_match( APAI_Patterns::PENDING_ACTIONLIKE_STRICT, $txt ) ) {
			return false;
		}

		// Acepta saludos simples, incluso si viene con alguna palabra extra.
		return (bool) preg_match( APAI_Patterns::PENDING_SMALLTALK_GREETING_STRICT, $txt );
	}

	private static function is_cancel_token( $norm_lc ) {
		$norm_lc = trim( (string) $norm_lc );
		// Tolerancia a typos comunes: "ccancelar", "cccance...".
		if ( preg_match( '/^c+ancelar$/u', $norm_lc ) ) { return true; }
		// Variantes naturales (sin "r"): "cancelemos", "cancele", "cancelá".
		// (Conservador: solo tokens cortos y claramente relacionados a cancelar.)
		if ( preg_match( '/^cancele(mos|)$|^cancel(e|emos)$|^cancela$/u', $norm_lc ) ) { return true; }
		// A1.5.18: tolerancia conservadora a typos tipo "canceelar".
		// (Solo tokens cortos y muy cerca de "cancelar".)
		if ( strlen( $norm_lc ) <= 12 && $norm_lc[0] === 'c' ) {
			$dist = levenshtein( $norm_lc, 'cancelar' );
			if ( $dist >= 0 && $dist <= 2 ) { return true; }
		}
		if ( $norm_lc === 'cancelar y continuar' ) { return true; }
		if ( in_array( $norm_lc, array( 'dejala de lado', 'dejarla de lado', 'dejalo de lado', 'dejarlo de lado' ), true ) ) { return true; }
		return (bool) preg_match( APAI_Patterns::PENDING_CANCEL_TOKEN_STRICT, $norm_lc );
	}

	private static function is_confirm_token( $norm_lc ) {
		return (bool) preg_match( APAI_Patterns::PENDING_CONFIRM_TOKEN_STRICT, $norm_lc );
	}

	private static function looks_like_new_action_request( $norm_lc ) {
		/**
		 * Pending guard: when there is a pending_action, ANY new action-like message must be blocked
		 * behind the pending-choice UI (except smalltalk/confirm/cancel/followup edit).
		 *
		 * Bug we are fixing:
		 * - "bajá el stock del primero a 5" was slipping through because we only detected "cambiá".
		 */
		if ( preg_match( APAI_Patterns::PENDING_ACTIONLIKE_STRICT, $norm_lc ) ) {
			return true;
		}

		// Backward compatibility: keep the old stricter checks too.
		if ( preg_match( APAI_Patterns::PENDING_LOOKS_CAMBIA_STRICT, $norm_lc ) ) {
			if ( preg_match( APAI_Patterns::PENDING_LOOKS_PRECIO_STRICT, $norm_lc ) ) { return true; }
			if ( preg_match( APAI_Patterns::PENDING_LOOKS_STOCK_STRICT, $norm_lc ) ) { return true; }
		}
		return false;
	}

	/**
	 * Try to patch the pending action based on a follow-up message.
	 * Returns patched action array or null.
	 */
	
	private static function looks_like_target_selection_reply( $raw, $norm_lc ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return false;
		}

		// Normalize common "I typed the title with a leading #" pattern.
		$raw_nohash = preg_replace( '/^\s*#\s*/u', '', $raw );
		$raw_nohash = is_string( $raw_nohash ) ? trim( $raw_nohash ) : $raw;

		// 1) Simple numeric selection: "2"
		if ( preg_match( '/^\d+$/', $raw ) ) {
			return true;
		}

		// 2) Ordinal / option style: "opcion 2" / "opción 2", "la 2", "el 2"
		if ( preg_match( '/^(opcion|opción)\s*\d+$/iu', $raw ) ) {
			return true;
		}
		if ( preg_match( '/^(la|el)\s*\d+$/iu', $raw ) ) {
			return true;
		}

		// 3) Explicit ID selection: "#123", "id 123", "ID:123".
		// WHY: The UI selector sends "ID <n>"; if we don't treat it as a selection reply,
		// PendingFlow will incorrectly block it and the user gets stuck.
		if ( preg_match( '/^(?:#\s*)?\d{1,10}$/u', $raw ) ) {
			return true;
		}
		if ( preg_match( '/^id\s*[:#]?\s*\d{1,10}$/iu', $raw ) ) {
			return true;
		}

		// 4) SKU selection: "sku REM-001".
		if ( preg_match( '/^sku\s*[:#]?\s*[A-Za-z0-9_\-\.]{2,64}$/iu', $raw ) ) {
			return true;
		}

		// 5) Spanish ordinals by word (covers typing instead of clicking).
		if ( preg_match( '/\b(primero|primer|segunda|segundo|tercera|tercero|cuarta|cuarto|quinta|quinto)\b/iu', $raw ) ) {
			return true;
		}

		// 6) Plain title reply (no action verbs): allow TargetedUpdateFlow to try matching against candidates.
		// WHY: Users often paste/type a shortened product name instead of the full title.
		// SAFETY: We still block anything that looks like a NEW action request (price/stock verbs).
		// QA/UX: Do NOT treat greetings/smalltalk like "hola" as a selection reply.
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw_nohash, 'UTF-8' ) : strtolower( $raw_nohash );
		$lc = trim( $lc );
		if ( $lc !== '' ) {
			$is_greeting = (bool) preg_match( '/^(hola|buenas|buenos\s+dias|buenas\s+tardes|buenas\s+noches|hi|hello|hey)\b/iu', $raw_nohash );
			$looks_like_new_action = (bool) preg_match( '/\b(cambia|cambi...ad|crear|crea|borra|borrá|elimina|eliminá)\b/iu', $raw_nohash );
			$has_letters = (bool) preg_match( '/[\p{L}]{3,}/u', $raw_nohash );
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $raw_nohash, 'UTF-8' ) : strlen( $raw_nohash );
			// Require a slightly longer string to reduce false positives (e.g. "hola").
			if ( ! $is_greeting && $has_letters && ! $looks_like_new_action && $len >= 6 && $len <= 140 ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Detect when the user repeats the exact SAME action request/value that is already pending.
	 * Example: user says "cambiá el precio del último producto a 9999" twice.
	 *
	 * @INVARIANT Never executes anything. Only re-shows the pending action card.
	 */

private static function patch_pending_action_from_followup( $pending_action, $message_raw, $norm_lc ) {
			if ( empty( $pending_action['action'] ) || ! is_array( $pending_action['action'] ) ) { return null; }
			$action = $pending_action['action'];
			if ( empty( $action['type'] ) || $action['type'] !== 'update_product' ) { return null; }
			if ( empty( $action['changes'] ) || ! is_array( $action['changes'] ) ) { return null; }

			$is_price = array_key_exists( 'regular_price', $action['changes'] );
			$is_stock = array_key_exists( 'stock_quantity', $action['changes'] );
			if ( ! $is_price && ! $is_stock ) { return null; }

			$raw_trim = trim( (string) $message_raw );
			// Guard: if the user mentions an explicit target (id/#/primer/último/producto/etc) or provides multiple numeric groups,
			// treat it as a NEW action request (handled by PendingChoice UI) instead of patching the existing pending action.
			$wc_tokens = preg_split( '/\s+/u', trim( (string) $norm_lc ), -1, PREG_SPLIT_NO_EMPTY );
			$wc        = is_array( $wc_tokens ) ? count( $wc_tokens ) : 0;
			if ( $wc > 4 ) {
				return null;
			}
			if ( false !== strpos( (string) $norm_lc, '#' ) ) {
				return null;
			}
			if ( preg_match( '/\b(id)\b/u', (string) $norm_lc ) ) {
				return null;
			}
			if ( preg_match( '/\b(primer|primero|ultim|ultimo|ultima)\b/u', (string) $norm_lc ) ) {
				return null;
			}
			if ( preg_match( '/\bproducto\b/u', (string) $norm_lc ) ) {
				return null;
			}
			if ( preg_match_all( '/\d+(?:[\.,]\d+)*/u', (string) $raw_trim, $nums ) && isset( $nums[0] ) && count( $nums[0] ) > 1 ) {
				return null;
			}


			// Follow-up heuristic: typical edit prefixes like "mejor a...", "perdón..." etc.
			$looks_followup = (bool) preg_match( APAI_Patterns::PENDING_FOLLOWUP_PREFIX_STRICT, $norm_lc );

			// Allow full commands when they are the SAME kind as the pending action.
			$looks_same_kind_command = false;
			if ( $is_price ) {
				$has_num = (bool) preg_match( '/\d/u', $raw_trim );
				$has_price_hint = (bool) preg_match( '/\b(precio|\$|cambia|cambi[aá]|actualiza|modifica|poner|pon[eé]|setea|setear|lucas?|mil|k)\b/u', $norm_lc );
				$looks_same_kind_command = $has_num && $has_price_hint;
			}
			if ( $is_stock ) {
				$has_num = (bool) preg_match( '/\d/u', $raw_trim );
				$has_stock_hint = (bool) preg_match( '/\b(stock|existenc|inventario|cantidad)\b/u', $norm_lc );
				$looks_same_kind_command = $has_num && $has_stock_hint;
			}

			if ( ! $looks_followup && ! $looks_same_kind_command ) {
				// Only accept *pure* numeric replies (e.g., "50000").
				if ( ! preg_match( APAI_Patterns::PENDING_NUMERIC_ONLY_RAW_STRICT, $raw_trim ) ) {
					return null;
				}
			}

			// Parse value.
			if ( $is_price ) {
				$number = APAI_Brain_Normalizer::parse_price_number( $raw_trim );
				if ( $number === null ) { return null; }
				$number_f = floatval( $number );
				if ( $number_f <= 0 ) { return null; }
				$value_str = ( class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::format_price_for_wc( $number_f ) : self::format_price_string( $number_f ) );
				$action['changes']['regular_price'] = $value_str;
				$action['human_summary'] = self::rewrite_summary_value( $action['human_summary'], $value_str );
				return $action;
			}

			if ( $is_stock ) {
				$number = APAI_Brain_Normalizer::parse_number( $raw_trim );
				if ( $number === null ) { return null; }
				$value_i = (int) round( (float) $number );
				if ( $value_i < 0 ) { return null; }
				$action['changes']['stock_quantity'] = $value_i;
				$action['human_summary'] = self::rewrite_summary_value( $action['human_summary'], (string) $value_i );
				return $action;
			}

			return null;
		}

		private static function rewrite_summary_value( $summary, $value_str ) {
			$summary = (string) $summary;
			$value_str = (string) $value_str;
			// Replace the last "a <number>" occurrence.
			if ( preg_match( APAI_Patterns::PENDING_REWRITE_LAST_A_NUMBER_STRICT, $summary ) ) {
				return preg_replace( APAI_Patterns::PENDING_REWRITE_LAST_A_NUMBER_STRICT, ' a ' . $value_str, $summary );
			}
			// Fallback: just append.
			return trim( $summary ) . ' a ' . $value_str;
		}

    /**
     * Formats a price float into a WooCommerce-safe string.
     * Keeps decimals when present and drops trailing zeros.
     */
    private static function format_price_string( $price_n ) {
        if ( class_exists( 'APAI_Brain_Normalizer' ) ) {
            $s = APAI_Brain_Normalizer::format_price_for_wc( $price_n );
            if ( $s !== null ) {
                return (string) $s;
            }
        }

        // Fallback (should be rare): keep previous behavior.
        $price_n = floatval( $price_n );
        if ( abs( $price_n - round( $price_n ) ) > 0.000001 ) {
            $s = number_format( $price_n, 2, '.', '' );
            $s = rtrim( rtrim( $s, '0' ), '.' );
            return $s;
        }
        return (string) intval( round( $price_n ) );
    }


/**
 * If there's a pending update_product action and the user issues another update request targeting
 * the SAME product (e.g. price then stock), we merge the changes into the pending action and
 * re-show the updated card. This keeps UX fast: no forced cancel/confirm loop for the same target.
 *
 * IMPORTANT: This does NOT execute anything. It only updates the pending_action.
 */
private static function try_merge_pending_update_if_same_product( $pending_envelope, $raw, $norm_lc, $store_state ) {
	if ( ! is_array( $pending_envelope ) || empty( $pending_envelope['action'] ) || ! is_array( $pending_envelope['action'] ) ) {
		return null;
	}

	$action = $pending_envelope['action'];

	if ( empty( $action['type'] ) || $action['type'] !== 'update_product' ) {
		return null;
	}

	$pending_pid = isset( $action['product_id'] ) ? intval( $action['product_id'] ) : 0;
	if ( $pending_pid <= 0 ) {
		return null;
	}

	$norm = trim( (string) $norm_lc );
	$raw_s = (string) $raw;

	$wants_price = (bool) preg_match( APAI_Patterns::PENDING_LOOKS_PRECIO_STRICT, $norm );
	$wants_stock = (bool) preg_match( APAI_Patterns::PENDING_LOOKS_STOCK_STRICT, $norm );

	if ( ! $wants_price && ! $wants_stock ) {
		return null;
	}

	$target_pid = self::extract_target_product_id_for_update( $norm, $store_state, $pending_pid );
	if ( $target_pid !== $pending_pid ) {
		return null;
	}

	$new_changes = array();

	if ( $wants_price ) {
		$price = APAI_Brain_Normalizer::parse_price_number( $raw_s );
		if ( $price === null ) {
			return null;
		}
		$new_changes['regular_price'] = number_format( floatval( $price ), 2, '.', '' );
	}

	if ( $wants_stock ) {
		$stock = APAI_Brain_Normalizer::parse_stock_number( $raw_s );
		if ( $stock === null ) {
			return null;
		}
		$new_changes['manage_stock']   = true;
		$new_changes['stock_quantity'] = max( 0, intval( $stock ) );
	}

	if ( empty( $new_changes ) ) {
		return null;
	}

	$prev_changes = ( isset( $action['changes'] ) && is_array( $action['changes'] ) ) ? $action['changes'] : array();

	// A1.2 — Pending merge (No-op real):
	// Si el usuario "suma" un cambio que ya estaba en la acción propuesta
	// o que ya está aplicado en el producto, NO modificamos el pending.
	$noop_keys = array();
	$filtered_new_changes = array();
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pending_pid ) : null;

	foreach ( $new_changes as $k => $v ) {
		// 1) Ya estaba en la propuesta pendiente.
		if ( array_key_exists( $k, $prev_changes ) ) {
			if ( self::change_values_equal( $k, $prev_changes[ $k ], $v ) ) {
				$noop_keys[] = $k;
				continue;
			}
		}

		// 2) Ya está aplicado en el producto (si lo podemos leer).
		if ( $product ) {
			$cur = self::get_product_field_for_change_key( $product, $k );
			if ( $cur !== null && self::change_values_equal( $k, $cur, $v ) ) {
				$noop_keys[] = $k;
				continue;
			}
		}

		$filtered_new_changes[ $k ] = $v;
	}

	if ( empty( $filtered_new_changes ) ) {
		if ( class_exists( 'APAI_Brain_Trace' ) ) {
			APAI_Brain_Trace::emit_current( 'pending_merge_noop', array(
				'product_id'  => $pending_pid,
				'noop_keys'   => $noop_keys,
				'change_keys' => array_keys( $prev_changes ),
			) );
		}

		// No cambiamos nada: devolvemos la misma acción pendiente.
		return APAI_Brain_Response_Builder::make_response(
			'chat',
			APAI_Brain_NLG::msg_pending_merge_noop(),
			array( $action )
		);
	}

	$merged_action            = $action;
	$merged_action['changes'] = array_merge( $prev_changes, $filtered_new_changes );

	// Ensure consistency if we touch stock.
	if ( isset( $merged_action['changes']['stock_quantity'] ) && ! isset( $merged_action['changes']['manage_stock'] ) ) {
		$merged_action['changes']['manage_stock'] = true;
	}

	// Compact + additive summary: "Actualizar producto: precio 9999 + stock 4"
	$merged_action['human_summary'] = self::build_compact_update_summary( $merged_action['changes'] );

	APAI_Brain_Memory_Store::persist_pending_action( $merged_action );

	$kind = self::derive_kind_from_changes( $merged_action['changes'] );
	if ( $kind ) {
		APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => $kind ) );
	}

	if ( class_exists( 'APAI_Brain_Trace' ) ) {
		APAI_Brain_Trace::emit_current( 'pending_merge', array(
			'product_id'   => $pending_pid,
			'change_keys'  => array_keys( $merged_action['changes'] ),
			'merge_fields' => array_keys( $filtered_new_changes ),
			'noop_keys'    => $noop_keys,
		) );
	}

return APAI_Brain_Response_Builder::make_response(
			'chat',
			APAI_Brain_NLG::msg_pending_merge_added(),
			array( $merged_action )
		);
}

private static function derive_kind_from_changes( $changes ) {
	if ( ! is_array( $changes ) ) {
		return null;
	}
	$has_price = isset( $changes['regular_price'] );
	$has_stock = isset( $changes['stock_quantity'] );

	if ( $has_price && $has_stock ) {
		return 'update';
	}
	if ( $has_price ) {
		return 'price';
	}
	if ( $has_stock ) {
		return 'stock';
	}
	return null;
}

private static function build_compact_update_summary( $changes ) {
	$parts = array();

	if ( is_array( $changes ) && isset( $changes['regular_price'] ) ) {
		$parts[] = 'precio ' . self::format_human_number( $changes['regular_price'] );
	}
	if ( is_array( $changes ) && isset( $changes['stock_quantity'] ) ) {
		$parts[] = 'stock ' . intval( $changes['stock_quantity'] );
	}

	if ( empty( $parts ) ) {
		return 'Actualizar producto.';
	}

	return 'Actualizar producto: ' . implode( ' + ', $parts );
}

private static function format_human_number( $value ) {
	$s = trim( (string) $value );
	// Normalize a common Woo format like "9999.00" -> "9999"
	if ( preg_match( '/^\-?\d+(\.\d+)?$/', $s ) ) {
		if ( substr( $s, -3 ) === '.00' ) {
			return substr( $s, 0, -3 );
		}
		return $s;
	}
	return $s;
}

private static function extract_target_product_id_for_update( $norm_lc, $store_state, $fallback_pid ) {
	$txt = trim( (string) $norm_lc );

	// Explicit ID references: "id 152", "producto #152", "#152" (if "producto" is present)
	if ( preg_match( '/\b(?:id|producto)\s*(?:#|id)?\s*(\d{1,7})\b/iu', $txt, $m ) ) {
		return intval( $m[1] );
	}
	if ( preg_match( '/\b#\s*(\d{1,7})\b/iu', $txt, $m ) && preg_match( '/\bproducto\b/iu', $txt ) ) {
		return intval( $m[1] );
	}

	// Common ordinal selectors.
	if ( preg_match( '/\bultimo\b/iu', $txt ) || preg_match( '/\bultima\b/iu', $txt ) ) {
		$pid = self::resolve_product_id_by_ordinal( 1, true );
		return $pid > 0 ? $pid : intval( $fallback_pid );
	}

	if ( preg_match( '/\bprimer\b/iu', $txt ) || preg_match( '/\bprimero\b/iu', $txt ) || preg_match( '/\bprimera\b/iu', $txt ) ) {
		$pid = self::resolve_product_id_by_ordinal( 1, false );
		return $pid > 0 ? $pid : intval( $fallback_pid );
	}

	// Fallback to pending product to keep UX fast in pending context.
	return intval( $fallback_pid );
}

/**
 * Resolve the Nth published product by ID ordering (ASC for firsts, DESC for lasts).
 * This is used ONLY for target-matching (merge UX), not for executing changes here.
 */
private static function resolve_product_id_by_ordinal( $n, $from_end = false ) {
	$n = max( 1, intval( $n ) );
	$offset = $n - 1;

	global $wpdb;

	$table = $wpdb->posts; // with WP prefix
	$order = $from_end ? 'DESC' : 'ASC';

	$sql = $wpdb->prepare(
		"SELECT ID FROM {$table} WHERE post_type=%s AND post_status=%s ORDER BY ID {$order} LIMIT 1 OFFSET %d",
		'product',
		'publish',
		$offset
	);

	$id = $wpdb->get_var( $sql );
	return $id ? intval( $id ) : 0;
}

/**
 * Map a pending change_key to a WC_Product field name for comparison.
 * NOTE: intentionally small + conservative.
 */
private static function get_product_field_for_change_key( $change_key ) {
	switch ( $change_key ) {
		case 'regular_price':
			return 'regular_price';
		case 'sale_price':
			return 'sale_price';
		case 'manage_stock':
			return 'manage_stock';
		case 'stock_quantity':
			return 'stock_quantity';
		default:
			return null;
	}
}

/**
 * Compare current vs pending values in a type-aware way.
 */
private static function change_values_equal( $change_key, $a, $b ) {
	// Normalize null-ish
	if ( $a === '' ) { $a = null; }
	if ( $b === '' ) { $b = null; }

	switch ( $change_key ) {
		case 'regular_price':
		case 'sale_price':
			// Compare as decimals (2dp) when possible.
			$fa = is_null( $a ) ? null : floatval( str_replace( ',', '.', strval( $a ) ) );
			$fb = is_null( $b ) ? null : floatval( str_replace( ',', '.', strval( $b ) ) );
			if ( is_null( $fa ) && is_null( $fb ) ) { return true; }
			if ( is_null( $fa ) || is_null( $fb ) ) { return false; }
			return ( round( $fa, 2 ) === round( $fb, 2 ) );
		case 'stock_quantity':
			$ia = is_null( $a ) ? null : intval( $a );
			$ib = is_null( $b ) ? null : intval( $b );
			return ( $ia === $ib );
		case 'manage_stock':
			$ba = ( $a === true || $a === 1 || $a === '1' || $a === 'yes' || $a === 'true' );
			$bb = ( $b === true || $b === 1 || $b === '1' || $b === 'yes' || $b === 'true' );
			return ( $ba === $bb );
		default:
			// Fallback strict-ish string compare.
			return ( strval( $a ) === strval( $b ) );
	}
}

}