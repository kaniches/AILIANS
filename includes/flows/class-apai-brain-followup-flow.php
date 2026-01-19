<?php
/**
 * FollowupFlow — short follow-ups like "mejor a 50000" bound to last_target_product_id (or last_product fallback).
 *
 * @FLOW Followup
 *
 * @INVARIANTS
 * - Conservative: if the field is unclear (price vs stock), ask for clarification.
 * - Does NOT run if pending_action exists (PendingFlow owns that).
 * - Does NOT run if target selection is pending (TargetedUpdateFlow owns that).
 * - Creates pending_action server-side (Step 2/2.5).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Followup_Flow {

    public static function try_handle( $message_raw, $m_norm, $store_state ) {
        $message_raw = (string) $message_raw;
        $m_norm      = is_string( $m_norm ) ? $m_norm : (string) $message_raw;

		// FollowupFlow should only claim *short* followups (e.g. "7", "precio", "stock").
		// If the user sends an explicit command sentence, let other flows handle it.
		$raw_lc = strtolower( trim( $message_raw ) );
		if ( strlen( $raw_lc ) > 18 ) {
			$has_verb  = (bool) preg_match( '/\b(cambia|cambi\xC3\xA1|cambiar|pone|pon\xC3\xA9|poner|set)\b/u', $raw_lc );
			$has_field = (bool) preg_match( '/\b(precio|stock)\b/u', $raw_lc );
			$has_num   = (bool) preg_match( '/\d/u', $raw_lc );
			if ( $has_verb && ( $has_field || $has_num ) ) {
				// Also clear any stale numeric-followup memory so it doesn't leak into later turns.
				if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
					APAI_Brain_Memory_Store::patch( array( 'pending_last_update' => null, 'pending_followup_action' => null ) );
				}
				return null;
			}
		}

		// -------------------------------------------------------------
		// Step 2/2.5 — si venimos de un comando incompleto del tipo
		// "poné stock del producto 386 a" y el usuario responde solo un número,
		// lo tomamos como el valor faltante (SIN inventar y SIN preguntar genérico).
		// -------------------------------------------------------------
		if ( is_array( $store_state ) && ! empty( $store_state['pending_followup_action'] ) && is_array( $store_state['pending_followup_action'] ) ) {
			$pfa = $store_state['pending_followup_action'];
			if ( isset( $pfa['expect'] ) && $pfa['expect'] === 'number' ) {
				// Only accept if the current message is (mostly) numeric.
				if ( preg_match( '/^\s*\d+(?:[\.,]\d+)?\s*$/u', $m_norm ) ) {
					$field = isset( $pfa['field'] ) ? (string) $pfa['field'] : '';
					$pid   = isset( $pfa['product_id'] ) ? intval( $pfa['product_id'] ) : 0;
					if ( $pid <= 0 && ! empty( $store_state['last_target_product_id'] ) ) {
						$pid = intval( $store_state['last_target_product_id'] );
					}

					if ( $pid > 0 && ( $field === 'stock' || $field === 'price' ) ) {
						if ( $field === 'stock' ) {
							// Stock debe ser entero. Si viene decimal, pedimos aclaración.
							if ( preg_match( '/[\.,]/u', $m_norm ) ) {
								return array(
									'type'    => 'clarify',
									'message' => 'Para **stock** necesito un número entero (sin decimales). ¿A qué cantidad querés dejar el stock del producto #' . $pid . '?',
									'actions' => array(),
								);
							}
							$qty_num = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_number( $m_norm ) : null;
							$qty     = is_null( $qty_num ) ? 0 : intval( $qty_num );
							if ( $qty <= 0 ) {
								// fall back to normal followup handling
							} else {
								$act = array(
									'type'       => 'update_product',
									'kind'       => 'stock',
									'product_id'  => $pid,
									'changes'    => array(
										'manage_stock'   => true,
										'stock_quantity' => $qty,
									),
								);

								if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
									APAI_Brain_Memory_Store::patch( array(
										'pending_action'          => $act,
										'pending_followup_action' => null,
										'pending_last_update'     => null,
										'last_target_product_id'  => $pid,
										'last_action_kind'        => 'stock',
									) );
								}

								return APAI_Brain_Response_Builder::make_response(
									'pending_action',
									'Dale, preparé la acción: Cambiar stock del producto #' . $pid . ' a ' . $qty . '.\n\n¿Confirmás para ejecutarlo?',
									array( $act ),
									array( 'route' => 'FollowupFlow' )
								);
							}
						}

						// price: parse to canonical string (2 decimals) for safety/consistency.
						$pn = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_price_number( $m_norm ) : null;
						if ( is_null( $pn ) || empty( $pn['number_str'] ) ) {
							return array(
								'type'    => 'clarify',
								'message' => '¿Qué precio querés ponerle al producto #' . $pid . '? (Ej: "precio ... a 1999")',
								'actions' => array(),
							);
						}
						$val_str = (string) $pn['number_str'];
						$act = array(
							'type'      => 'update_product',
							'kind'      => 'price',
							'product_id' => $pid,
							'changes'   => array(
								'regular_price' => $val_str,
							),
						);
						if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
							APAI_Brain_Memory_Store::patch( array(
								'pending_action'          => $act,
								'pending_followup_action' => null,
								'pending_last_update'     => null,
								'last_target_product_id'  => $pid,
								'last_action_kind'        => 'price',
							) );
						}
						return APAI_Brain_Response_Builder::make_response(
							'pending_action',
							'Dale, preparé la acción: Cambiar precio del producto #' . $pid . ' a ' . $val_str . '.\n\n¿Confirmás para ejecutarlo?',
							array( $act ),
							array( 'route' => 'FollowupFlow' )
						);
					}
				}
			}
		}

        // -------------------------------------------------------------
        // Resolve a pending "implicit update" where we already captured a number
        // but still need the user to specify whether it was PRICE or STOCK.
        // -------------------------------------------------------------
        $pending_last = null;
        if ( is_array( $store_state ) && ! empty( $store_state['pending_last_update'] ) && is_array( $store_state['pending_last_update'] ) ) {
            $pending_last = $store_state['pending_last_update'];
        }

        if ( $pending_last ) {
            // Guard: if the user is sending a full explicit command (contains digits in the same message),
            // do NOT reinterpret it as resolving a previous numeric-only followup.
            // Example: user previously typed "7" and later writes "cambiá el precio del último a 3333".
            if ( preg_match( '/\d/u', $m_norm ) ) {
                // Let deterministic / semantic flows handle it.
                return null;
            }
            $field = null;
            if ( preg_match( '/\bprecio\b/u', $m_norm ) ) {
                $field = 'price';
            } elseif ( preg_match( '/\bstock\b/u', $m_norm ) ) {
                $field = 'stock';
            }

            if ( $field ) {
                $product_id = isset( $pending_last['product_id'] ) ? intval( $pending_last['product_id'] ) : 0;
                if ( $product_id <= 0 && is_array( $store_state ) && ! empty( $store_state['last_target_product_id'] ) ) {
                    $product_id = intval( $store_state['last_target_product_id'] );
                }
                if ( $product_id <= 0 && is_array( $store_state ) && ! empty( $store_state['last_product']['id'] ) ) {
                    $product_id = intval( $store_state['last_product']['id'] );
                }

                // We may store either a raw int (legacy) or a formatted price string.
                $value_i   = isset( $pending_last['value'] ) ? intval( $pending_last['value'] ) : null;
                $value_str = isset( $pending_last['value_str'] ) ? sanitize_text_field( (string) $pending_last['value_str'] ) : null;

                if ( $product_id <= 0 || ( $value_i === null && $value_str === null ) ) {
                    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                        APAI_Brain_Memory_Store::patch( array( 'pending_last_update' => null ) );
                    }
                    return APAI_Brain_Response_Builder::make_response(
                        'message',
                        'No pude determinar qué producto era. Probá de nuevo con: "precio del último a 100" o "stock del último a 5".',
                        array( 'route' => 'FollowupFlow' )
                    );
                }

                if ( $field === 'price' ) {
                    // Prefer formatted string if present; otherwise format now.
                    if ( $value_str === null ) {
                        $num = floatval( $value_i );
                        if ( class_exists( 'APAI_Brain_Normalizer' ) ) {
                            $fmt = APAI_Brain_Normalizer::format_price_for_wc( $num );
                            $value_str = ( $fmt === null ) ? (string) $value_i : (string) $fmt;
                        } else {
                            $value_str = (string) $value_i;
                        }
                    }

                    $num_check = floatval( str_replace( ',', '.', $value_str ) );
                    if ( $num_check < 0 ) {
                        return APAI_Brain_Response_Builder::make_response( 'message', 'El precio no puede ser negativo.', array( 'route' => 'FollowupFlow' ) );
                    }
                    if ( $num_check == 0.0 ) {
                        return APAI_Brain_Response_Builder::make_response( 'message', 'El precio debe ser mayor a 0.', array( 'route' => 'FollowupFlow' ) );
                    }

					$action = array(
						'type'          => 'update_product',
						'kind'          => 'price',
						'change_keys'   => array( 'regular_price' ),
						'product_id'    => $product_id,
                        'target'        => array( 'product_id' => $product_id ),
                        'changes'       => array( 'regular_price' => $value_str ),
                        'human_summary' => 'Cambiar precio del producto #' . $product_id . ' a ' . $value_str,
                        'meta'          => array( 'source' => 'followup_implicit_kind' ),
                    );

                    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                        APAI_Brain_Memory_Store::persist_pending_action( $action );
                        APAI_Brain_Memory_Store::patch( array( 'pending_last_update' => null, 'pending_followup_action' => null, 'last_action_kind' => 'price' ) );
                    }
                    return APAI_Brain_Response_Builder::action_prepared( $action, array( 'route' => 'FollowupFlow' ) );
                }

                // stock
                if ( $value_i === null ) {
                    $value_i = 0;
                }
                if ( $value_i < 0 ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', 'El stock no puede ser negativo.', array( 'route' => 'FollowupFlow' ) );
                }

				$action = array(
					'type'          => 'update_product',
					'kind'          => 'stock',
					'change_keys'   => array( 'manage_stock', 'stock_quantity' ),
					'product_id'    => $product_id,
                    'target'        => array( 'product_id' => $product_id ),
                    'changes'       => array( 'manage_stock' => true, 'stock_quantity' => $value_i ),
                    'human_summary' => 'Cambiar stock del producto #' . $product_id . ' a ' . $value_i,
                    'meta'          => array( 'source' => 'followup_implicit_kind' ),
                );

                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                    APAI_Brain_Memory_Store::persist_pending_action( $action );
                    APAI_Brain_Memory_Store::patch( array( 'pending_last_update' => null, 'pending_followup_action' => null, 'last_action_kind' => 'stock' ) );
                }
                return APAI_Brain_Response_Builder::action_prepared( $action, array( 'route' => 'FollowupFlow' ) );
            }
        }

        if ( ! is_array( $store_state ) ) { return null; }

        // 1) No pending_action (pending edits are handled by PendingFlow).
        $pending_env = null;
        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            $pending_env = APAI_Brain_Memory_Store::extract_pending_action_from_store( $store_state );
        }
        if ( $pending_env ) { return null; }

        // 2) Do not interfere with product selection follow-ups.
        if ( ! empty( $store_state['pending_target_selection'] ) || ! empty( $store_state['pending_targeted_update'] ) ) {
            return null;
        }

        // 2b) Semantic/vague follow-up envelope (e.g. "ponelo más barato" -> then user replies "2000").
        $followup_env = ( isset( $store_state['pending_followup_action'] ) && is_array( $store_state['pending_followup_action'] ) )
            ? $store_state['pending_followup_action']
            : null;

        // 3) Resolve target product (prefer last_target_product_id, fallback last_product).
        $lp      = ( isset( $store_state['last_product'] ) && is_array( $store_state['last_product'] ) ) ? $store_state['last_product'] : null;
        $lp_id   = ( $lp && isset( $lp['id'] ) ) ? intval( $lp['id'] ) : 0;
        $lp_name = ( $lp && isset( $lp['name'] ) ) ? trim( wp_strip_all_tags( (string) $lp['name'] ) ) : '';

        $target_id = 0;
        // If we have a followup envelope with an explicit product_id, prefer it.
        if ( $followup_env && ! empty( $followup_env['product_id'] ) ) {
            $target_id = intval( $followup_env['product_id'] );
        } elseif ( ! empty( $store_state['last_target_product_id'] ) ) {
            $target_id = intval( $store_state['last_target_product_id'] );
        }
        if ( $target_id <= 0 ) {
            $target_id = $lp_id;
        }
        if ( $target_id <= 0 ) {
            return null;
        }
        // We only have the name if last_product matches target id.
        $target_name = ( $lp_id === $target_id ) ? $lp_name : '';

        // 4) Must look like a follow-up with a number.
        $lc_raw = function_exists( 'mb_strtolower' ) ? mb_strtolower( $message_raw, 'UTF-8' ) : strtolower( $message_raw );
        $lc_raw = trim( $lc_raw );
        if ( ! preg_match( '/\d/u', $lc_raw ) ) { return null; }

        $is_followup_prefix = (bool) preg_match( APAI_Patterns::PENDING_FOLLOWUP_PREFIX_STRICT, $lc_raw );
        $is_number_only     = (bool) preg_match( '/^\s*[-+]?\d+(?:[\.,]\d+)?\s*(?:k|mil|miles|luca|lucas)?\s*$/iu', $lc_raw );

        if ( ! $is_followup_prefix && ! $is_number_only ) {
            // avoid catching messages like "tengo 2 dudas..."
            return null;
        }

        // Guardrail: a bare number with no active follow-up is too ambiguous.
        // Do NOT assume a product or a field; instead ask what the number refers to.
        if ( ! $followup_env && $is_number_only ) {
	        $n = trim( $message_raw );
	        $msg = 'Escribiste **' . $n . '** 👀

	¿Querés usarlo como **precio** o como **stock**?

	💰 Precio: `precio ' . $n . '`
	📦 Stock: `stock ' . $n . '`

	Decime qué producto es (ID/SKU/nombre) y te lo dejo listo para aplicar ✅';
            return APAI_Brain_Response_Builder::make_response(
                'consult',
                $msg,
                array( 'precio', 'stock' ),
                null,
                null,
                array( 'followup' => true, 'status' => 'bare_number' )
            );
        }

        // 5) Decide field (price vs stock) conservatively.
        $field = null;
        // If a semantic clarify envelope exists, force the intended field.
        if ( $followup_env && ! empty( $followup_env['intent'] ) ) {
            $intent = strtolower( (string) $followup_env['intent'] );
            if ( $intent === 'set_price' ) { $field = 'price'; }
            if ( $intent === 'set_stock' ) { $field = 'stock'; }
        }
        if ( preg_match( APAI_Patterns::STOCK_WORD_STRICT, $lc_raw ) ) {
            $field = 'stock';
        } elseif ( preg_match( APAI_Patterns::PRICE_WORD_STRICT, $lc_raw ) || preg_match( APAI_Patterns::PRICE_WORD, $lc_raw ) ) {
            $field = 'price';
        }

        // If field is unknown, ask and store the number for a deterministic follow-up.
        if ( $field === null ) {
            $parsed_any = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_price_number( $message_raw ) : null;
            if ( $parsed_any !== null ) {
                $num = floatval( $parsed_any );
                $value_i = (int) round( $num );
                $value_str = null;
                if ( class_exists( 'APAI_Brain_Normalizer' ) ) {
                    $value_str = APAI_Brain_Normalizer::format_price_for_wc( $num );
                }
                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                    APAI_Brain_Memory_Store::patch( array(
                        'pending_last_update' => array(
                            'product_id'  => $target_id,
                            'value'       => $value_i,
                            'value_str'   => $value_str,
                            'created_at'  => time(),
                        ),
						'pending_followup_action' => array(
							'expect' => 'field',
							'product_id' => $target_id,
							'value' => $value_i,
							'value_str' => $value_str,
							'created_at' => time(),
						),
                    ) );
                }
            }

	            $label = $target_name ? ( '**' . $target_name . '**' ) : ( 'el producto #' . $target_id );
	            $shown = $value_str ? $value_str : trim( $message_raw );
	            if ( $shown === '' ) { $shown = (string) $value_i; }
	            // Evitar "7.00" en la pregunta inicial. Mostrar "7" cuando aplica.
	            $shown_clean = rtrim( rtrim( (string) $shown, '0' ), '.' );
	            if ( $shown_clean === '' ) { $shown_clean = (string) $shown; }
	            // UX: explicar por qué usa ese producto (último objetivo), y dar salida si era otro.
	            $msg = 'Escribiste **' . $shown_clean . '** 👀' . "\n\n"
	                . '¿Querés usarlo como **precio** o como **stock** para ' . $label . '?' . "\n\n"
	                . '*(Lo tomo como ' . $label . ' porque fue el último producto con el que venías trabajando. Si era otro, decime ID/SKU/nombre.)*';
            return APAI_Brain_Response_Builder::make_response(
                'consult',
                $msg,
                array('precio','stock'),
                null,
                null,
                array( 'followup' => true, 'status' => 'need_field' )
            );
        }

        // 6) Parse value.
        $value_i   = null;
        $value_str = null;

        if ( $field === 'price' ) {
            $parsed = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_price_number( $message_raw ) : null;
            if ( $parsed === null ) { return null; }
            $num = floatval( $parsed );
            if ( $num <= 0 ) { return null; }
            $value_str = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::format_price_for_wc( $num ) : null;
            if ( $value_str === null ) { return null; }
        } else {
            $raw_num = null;
            if ( preg_match_all( APAI_Patterns::NUMBER_CAPTURE_STRICT, $message_raw, $mm ) && ! empty( $mm[1] ) ) {
                $raw_num = end( $mm[1] );
            }
            if ( ! is_string( $raw_num ) || trim( $raw_num ) === '' ) { return null; }
            $parsed = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_number( $raw_num ) : null;
            if ( $parsed === null ) { return null; }
            $value_i = (int) round( (float) $parsed );
            if ( $value_i < 0 ) { return null; }
        }

        // 7) Build action.
        $changes = array();
        if ( $field === 'price' ) {
            $changes = array( 'regular_price' => (string) $value_str );
        } else {
            $changes = array( 'manage_stock' => true, 'stock_quantity' => $value_i );
        }

        // No-op guard (honest): if the requested change already matches the current product,
        // do not create a pending action.
        if ( $target_id > 0 && function_exists( 'wc_get_product' ) ) {
            $wc_product = wc_get_product( $target_id );
            if ( $wc_product ) {
                if ( $field === 'stock' ) {
                    $cur_manage = (bool) $wc_product->managing_stock();
                    $cur_qty    = $wc_product->get_stock_quantity();
                    $cur_qty_i  = is_null( $cur_qty ) ? null : intval( $cur_qty );
                    if ( $cur_manage === true && $cur_qty_i === intval( $changes['stock_quantity'] ) ) {
                        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                            APAI_Brain_Memory_Store::patch( array( 'pending_followup_action' => null ) );
                        }
                        return APAI_Brain_Response_Builder::make_response(
                            'message',
                            'Listo ✅ No había cambios para aplicar (ya estaba así).',
                            array(),
                            null,
                            null,
                            array( 'followup' => true, 'status' => 'noop', 'field' => $field )
                        );
                    }
                } elseif ( $field === 'price' ) {
                    $cur_price = $wc_product->get_regular_price();
                    $cur_price = is_string( $cur_price ) ? trim( $cur_price ) : (string) $cur_price;
                    if ( $cur_price === (string) $changes['regular_price'] ) {
                        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                            APAI_Brain_Memory_Store::patch( array( 'pending_followup_action' => null ) );
                        }
                        return APAI_Brain_Response_Builder::make_response(
                            'message',
                            'Listo ✅ No había cambios para aplicar (ya estaba así).',
                            array(),
                            null,
                            null,
                            array( 'followup' => true, 'status' => 'noop', 'field' => $field )
                        );
                    }
                }
            }
        }

        $label = $target_name ? ( '**' . $target_name . '**' ) : ( 'el producto #' . $target_id );

        $action = array(
            'type'          => 'update_product',
            'kind'          => $field,
            'product_id'    => $target_id,
            'changes'       => $changes,
            'change_keys'   => array_keys( $changes ),
            'human_summary' => ( $field === 'price' ? 'Cambiar precio' : 'Actualizar el stock' ) . ' de #' . $target_id . ( $target_name ? ( ' (' . $target_name . ')' ) : '' ) . ' a ' . ( $field === 'price' ? $value_str : $value_i ) . '.',
        );

        $confirmation = array(
            'required'      => true,
            'prompt'        => '',
            'ok'            => 'Confirmar y ejecutar acción',
            'cancel'        => 'Cancelar',
            'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
        );

        $msg = ( $field === 'price' )
            ? 'Dale, preparé el cambio de precio de ' . $label . ' a ' . $value_str . '.'
            : 'Dale, preparé el cambio de stock de ' . $label . ' a ' . $value_i . '.';

        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            APAI_Brain_Memory_Store::persist_pending_action( $action );
            APAI_Brain_Memory_Store::patch( array(
                'last_action_kind'         => $field,
                'pending_followup_action'  => null,
            ) );
        }

        return APAI_Brain_Response_Builder::make_response(
            'execute',
            $msg,
            array( $action ),
            $confirmation,
            null,
            array( 'followup' => true, 'status' => 'pending_created', 'field' => $field )
        );
    }
}
