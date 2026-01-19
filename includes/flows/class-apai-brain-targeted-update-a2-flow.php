<?php
/**
 * @FLOW TargetedUpdateA2Flow
 *
 * Deterministic targeted updates (A2):
 * - A2.1: Cambiar nombre/título
 * - A2.2: Categorías (SET + ADD + REMOVE)
 * - A2.3: Descripción corta
 *
 * @INVARIANTS
 * - Nunca crear pending_action si falta product_id o changes.
 * - Preferir ruta determinista cuando el usuario ya dio contexto suficiente.
 * - Si falta un dato, pedirlo 1 vez y recordar contexto para el siguiente mensaje.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Targeted_Update_A2_Flow {

    const FOLLOWUP_KEY = 'pending_targeted_update_a2';

    /**
     * @return array|null Brain response array or null if not handled.
     */
    public static function try_handle( $message, $m_norm, $store_state ) {
        $msg_raw  = is_string( $message ) ? $message : '';
        $msg_norm = class_exists( 'APAI_Brain_Normalizer' )
            ? APAI_Brain_Normalizer::normalize_intent_text( $msg_raw )
            : strtolower( trim( $msg_raw ) );

        
        // 0) Natural query: "¿qué categorías tiene el producto #ID?"
        // Read-only: answer with real WooCommerce data and do NOT create pending_action.
        $sel_q = self::parse_selector( $msg_raw, $msg_norm );
        if ( is_array( $sel_q ) && isset( $sel_q['type'] ) && $sel_q['type'] === 'id' ) {
            $pid_q = isset( $sel_q['value'] ) ? intval( $sel_q['value'] ) : 0;
            $norm_q = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $msg_norm ) ) );
            if ( $pid_q > 0 && (
                strpos( $norm_q, 'que categorias tiene' ) !== false ||
                strpos( $norm_q, 'que categoria tiene' ) !== false ||
                strpos( $norm_q, 'categorias tiene' ) !== false ||
                strpos( $norm_q, 'categoria tiene' ) !== false ||
                strpos( $norm_q, 'cuales categorias tiene' ) !== false ||
                strpos( $norm_q, 'cuales tiene' ) !== false
            ) ) {
                if ( function_exists( 'wc_get_product' ) ) {
                    $p = wc_get_product( $pid_q );
                    if ( $p ) {
                        $ids = $p->get_category_ids();
                        if ( ! is_array( $ids ) ) { $ids = array(); }
                        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
                        $names = array();
                        foreach ( $ids as $tid ) {
                            $t = get_term( $tid, 'product_cat' );
                            if ( $t && ! is_wp_error( $t ) && ! empty( $t->name ) ) { $names[] = (string) $t->name; }
                        }
                        if ( empty( $names ) ) { $names[] = 'Uncategorized'; }
                        $txt = 'El producto #' . $pid_q . ' tiene estas categorías: ' . implode( ', ', $names ) . '.';
                        return APAI_Brain_Response_Builder::make_response( 'chat', $txt );
                    }
                }
            }
        }

// 0) Follow-up: we previously asked for missing value/category.
        $fu = ( is_array( $store_state ) && isset( $store_state[ self::FOLLOWUP_KEY ] ) && is_array( $store_state[ self::FOLLOWUP_KEY ] ) )
            ? $store_state[ self::FOLLOWUP_KEY ]
            : null;

        if ( is_array( $fu ) ) {
            // If the user explicitly targets a different product while a follow-up is active,
            // treat this as a NEW instruction (do not hijack with the old follow-up).
            $sel_now = self::parse_selector( $msg_raw, $msg_norm );
            if ( is_array( $sel_now ) && isset( $sel_now['type'] ) && $sel_now['type'] === 'id' ) {
                $fu_pid  = isset( $fu['product_id'] ) ? intval( $fu['product_id'] ) : 0;
                $sel_pid = isset( $sel_now['value'] ) ? intval( $sel_now['value'] ) : 0;
                if ( $fu_pid > 0 && $sel_pid > 0 && $fu_pid !== $sel_pid ) {
                    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                        APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
                    }
                    $fu = null;
                }
            }
        }

        if ( is_array( $fu ) ) {
            $resp = self::handle_followup( $msg_raw, $msg_norm, $fu );
            if ( $resp ) {
                return $resp;
            }
            // If follow-up context exists but this message is unrelated, don't hijack; let other flows handle.
        }

        // 1) Parse new intent. If it doesn't look like an A2 targeted instruction, return null.
        $intent = self::parse_intent( $msg_raw, $msg_norm );
        if ( ! is_array( $intent ) ) {
            return null;
        }

        // 2) Resolve product.
        $pid = self::resolve_product_id( $intent, $store_state );
        if ( $pid <= 0 ) {
            return APAI_Brain_Response_Builder::make_response(
                'chat',
                'No pude identificar el producto. Podés decirme el **#ID** o el **SKU**, por ejemplo: “Cambiá la descripción corta del #152 a …”'
            );
        }

        // 3) If intent missing a required piece, ask once and persist followup context.
        if ( empty( $intent['ready'] ) ) {
            $question = isset( $intent['question'] ) ? (string) $intent['question'] : '';
            if ( $question === '' ) {
                $question = 'Me falta un dato para preparar el cambio. ¿Podés completar la instrucción?';
            }

            $follow = array(
                'product_id' => $pid,
                'field'      => isset( $intent['field'] ) ? (string) $intent['field'] : '',
                'cat_mode'   => isset( $intent['cat_mode'] ) ? (string) $intent['cat_mode'] : '',
                'stage'      => isset( $intent['stage'] ) ? (string) $intent['stage'] : 'need_value',
            );

            if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => $follow ) );
            }

            return APAI_Brain_Response_Builder::make_response( 'chat', $question );
        }

        // 4) Build and persist pending action.
        $built = self::build_pending_action_for_product( $pid, $intent );
        if ( is_array( $built ) ) {
            // Clear follow-up context ONLY when we actually created a pending action (mode=execute).
            // If build_pending_action_for_product returned a chat message (e.g., missing category), we keep follow-up.
            if ( isset( $built['mode'] ) && $built['mode'] === 'execute' ) {
                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                    APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
                }
            }
            return $built;
        }

        return null;
    }

    private static function handle_followup( $msg_raw, $msg_norm, $fu ) {
        // Cancel follow-up.
        if ( preg_match( '/\b(cancelar|cancelÃ¡|cancela|no)\b/iu', $msg_raw ) ) {
            if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
            }
            return APAI_Brain_Response_Builder::make_response( 'chat', 'Listo, lo dejé de lado.' );
        }

        $pid = isset( $fu['product_id'] ) ? intval( $fu['product_id'] ) : 0;

        // Allow the user to correct the product id during a follow-up (e.g. "pero era el #150").
        $pid_override = 0;
        if ( preg_match_all( '/#\s*(\d{1,10})/u', $msg_raw, $mm_ids ) && ! empty( $mm_ids[1] ) ) {
            $pid_override = intval( $mm_ids[1][0] );
        } elseif ( preg_match( '/\bproducto\s*#?\s*(\d{1,10})\b/iu', $msg_raw, $mm_prod ) ) {
            $pid_override = intval( $mm_prod[1] );
        }
        if ( $pid_override > 0 && $pid_override !== $pid ) {
            $pid = $pid_override;
            $fu['product_id'] = $pid;
            if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => $fu ) );
            }
        }
        $field = isset( $fu['field'] ) ? (string) $fu['field'] : '';
        $stage = isset( $fu['stage'] ) ? (string) $fu['stage'] : 'need_value';
        $cat_mode = isset( $fu['cat_mode'] ) ? (string) $fu['cat_mode'] : '';

        if ( $pid <= 0 || $field === '' ) {
            return null;
        }

        // Category follow-up helpers (human UX):
        // - Allow "listo/ya está" to exit the follow-up
        // - Allow "¿qué/cual(es) categorías tiene?" to show current categories on that product
        if ( $field === 'category_ids' && $stage === 'need_category' ) {

            // If the user provides a full new instruction while a follow-up is active, do not treat it as a category name.
            // Example: "Sumá la categoría X al #150" while we are waiting for a category name.
            $sel_inline = self::parse_selector( $msg_raw, $msg_norm );
            if ( is_array( $sel_inline ) && isset( $sel_inline['type'] ) && $sel_inline['type'] === 'id' && ! empty( $sel_inline['value'] ) ) {
                if ( preg_match( '/\b(sumale|sumÃ¡|suma|agreg|aÃ±ad|met|pas|deja|dejÃ¡|saca|sacale|quita|quitÃ¡)\b/iu', $msg_norm ) ) {
                    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                        APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
                    }
                    return null;
                }
            }

			// Small-talk guard: if the user is clearly chatting while we're waiting for a category name,
			// keep the follow-up but respond politely instead of trying to resolve it as a category.
			$st_norm = trim( $msg_norm );
			$st_norm = preg_replace( '/\s+/', ' ', $st_norm );
			$smalltalk = array(
				'hola', 'buenas', 'buen dia', 'buen día', 'hey',
				'que podes hacer', 'qué podes hacer', 'qué podés hacer', 'ayuda', 'help',
			);
			$looks_like_question = ( strpos( $msg_raw, '?' ) !== false );
			if ( in_array( $st_norm, $smalltalk, true ) || $looks_like_question ) {
				$pid = isset( $fu['product_id'] ) ? intval( $fu['product_id'] ) : 0;
				$txt = "Te leo 🙂\n\nAhora mismo estoy esperando el **nombre de la categoría** para continuar con el cambio";
				if ( $pid > 0 ) {
					$txt .= " del producto #{$pid}.";
				} else {
					$txt .= ".";
				}
				$txt .= "\n\n👉 Si querés **seguir**, respondé con el nombre de la categoría.\n👉 Si querés **salir**, escribí: *cancelar*.";
				return APAI_Brain_Response_Builder::make_response( 'chat', $txt, array(), null, null, array( 'route' => 'TargetedUpdateA2Flow' ) );
			}
            // Exit follow-up without changes
            if ( preg_match( '/\b(listo|ya\s*estÃ¡|ya\s*esta|nada\s*mÃ¡s|nada\s*mas|no\s*gracias)\b/iu', $msg_raw ) ) {
                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                    APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
                }
                return APAI_Brain_Response_Builder::make_response( 'chat', 'Listo. No sumo nada más.' );
            }

            // "¿Qué categorías tiene?" — show current categories for the product.
            // NOTE: keep this very permissive: if the user is in a category follow-up and asks a question
            // about "qué/cuál/cuales" + "tiene" (especially mentioning categorías), we should answer with
            // real WooCommerce data and keep the follow-up alive.
                        $ask_cats = false;
            $norm_q = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $msg_norm ) ) );

            // Common user phrasings (keep it simple and robust).
            $trigs = array(
                'cual mas tiene',
                'que mas tiene',
                'cuales tiene',
                'que categorias tiene',
                'que categoria tiene',
                'categorias tiene',
                'categoria tiene',
            );
            foreach ( $trigs as $t ) {
                if ( $t !== '' && strpos( $norm_q, $t ) !== false ) { $ask_cats = true; break; }
            }

            // Fallback: mentions "categor" + "tiene/tenes/tengo".
            if ( ! $ask_cats ) {
                if ( strpos( $norm_q, 'categor' ) !== false && ( strpos( $norm_q, 'tiene' ) !== false || strpos( $norm_q, 'tenes' ) !== false || strpos( $norm_q, 'tengo' ) !== false ) ) {
                    $ask_cats = true;
                }
            }

if ( $ask_cats && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    $ids = $product->get_category_ids();
                    if ( ! is_array( $ids ) ) { $ids = array(); }
                    $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

                    $names = array();
                    foreach ( $ids as $tid ) {
                        $t = get_term( $tid, 'product_cat' );
                        if ( $t && ! is_wp_error( $t ) && ! empty( $t->name ) ) {
                            $names[] = (string) $t->name;
                        }
                    }
                    if ( empty( $names ) ) { $names[] = 'Uncategorized'; }

                    $txt = 'Ahora el producto #' . $pid . ' tiene estas categorías: ' . implode( ', ', $names ) . '.';
                    $txt .= ' Si querés sumar otra, decime el nombre exacto (o decí "listo").';
                    return APAI_Brain_Response_Builder::make_response( 'chat', $txt );
                }
            }
        }


		$intent = array(
            'field'    => $field,
            'cat_mode' => $cat_mode,
            'selector' => array( 'type' => 'id', 'value' => $pid ),
            'ready'    => true,
			// Mark: this intent came from a follow-up prompt (so we can keep context on NOOP).
			'from_followup' => true,
        );

	        if ( $field === 'name' || $field === 'short_description' ) {
            $val = trim( (string) $msg_raw );
            if ( $val === '' ) {
                return APAI_Brain_Response_Builder::make_response( 'chat', 'Necesito el texto exacto.' );
            }
            $intent['value'] = $val;
        } elseif ( $field === 'category_ids' ) {
            // Here the user is expected to provide the category name(s).
			// Guardrail: if we're waiting for a category name but the user sent a *new instruction*
			// (e.g. "ponelo más barato", "stock 5", "cancelar"), don't hijack the message.
			$mn = trim( $msg_norm );
			if (
				$mn === ''
				|| preg_match( '/\b(cancelar|confirmar)\b/iu', $mn )
				|| preg_match( '/\b(precio|stock|barat|car|cuesta|vale|cantidad|unidades)\b/iu', $mn )
				|| preg_match( '/\b(pon[ée]|pone|ponelo|ponela|agreg[áa]|agrega|sum[áa]|suma|quit[áa]|quita|sac[áa]|saca)\b/iu', $mn )
			) {
					// This is a *guardrail exit*: do not clear other pending keys here.
					// We only remove the A2 followup marker so other flows (chitchat/model)
					// can handle the message.
					APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
				return null;
			}
            $cats = self::extract_categories_from_followup_message( (string) $msg_raw );
            if ( empty( $cats ) ) {
                $cats = self::split_categories( (string) $msg_raw );
            }
            if ( empty( $cats ) ) {
                return APAI_Brain_Response_Builder::make_response( 'chat', 'Decime el nombre exacto de la categoría (por ejemplo: “Categoria1”).' );
            }
            $intent['categories'] = $cats;
            if ( $cat_mode === '' ) {
                // default to set if not specified
                $intent['cat_mode'] = 'set';
            }
	        } elseif ( $field === 'regular_price' ) {
	            $p = APAI_Brain_Normalizer::parse_price_number( (string) $msg_raw );
	            if ( $p === null ) {
	                return APAI_Brain_Response_Builder::make_response( 'chat', 'Decime el precio como número (por ejemplo: 2000 o 1999.99).' );
	            }
	            $intent['value'] = number_format( (float) $p, 2, '.', '' );
	        } elseif ( $field === 'stock_quantity' ) {
	            $q = APAI_Brain_Normalizer::parse_stock_number( (string) $msg_raw );
	            if ( $q === null ) {
	                return APAI_Brain_Response_Builder::make_response( 'chat', 'Decime el stock como número entero (por ejemplo: 5).' );
	            }
	            $intent['value'] = (int) $q;
	        } else {
	            return null;
	        }

		// Special: for category follow-ups, if the chosen category would be a NOOP (already present / not present),
		// keep the follow-up context so the user can immediately say another category without repeating everything.
		if ( $field === 'category_ids' && $stage === 'need_category' ) {
			$names = isset( $intent['categories'] ) && is_array( $intent['categories'] ) ? $intent['categories'] : array();
			$names = array_values( array_filter( array_map( 'trim', $names ) ) );
			if ( ! empty( $names ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $pid );
				if ( $product ) {
					$current = $product->get_category_ids();
					if ( ! is_array( $current ) ) { $current = array(); }
					$current = array_values( array_unique( array_map( 'intval', $current ) ) );

					$term_ids = self::resolve_category_term_ids( $names );
					if ( ! is_wp_error( $term_ids ) && is_array( $term_ids ) ) {
						$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
						$mode2 = $cat_mode !== '' ? $cat_mode : 'set';
						$final = $current;
						if ( $mode2 === 'set' ) {
							$final = $term_ids;
						} elseif ( $mode2 === 'add' ) {
							$final = array_values( array_unique( array_merge( $current, $term_ids ) ) );
						} elseif ( $mode2 === 'remove' ) {
							$final = array_values( array_diff( $current, $term_ids ) );
						}

						$cur_sorted = $current; $fin_sorted = $final;
						sort( $cur_sorted ); sort( $fin_sorted );
						if ( $cur_sorted === $fin_sorted ) {
							$cats_txt = implode( ', ', $names );
							if ( $mode2 === 'add' ) {
								// Keep follow-up: user likely wants to add a different category.
								return APAI_Brain_Response_Builder::make_response( 'chat', 'Ese producto ya tiene la categoría ' . $cats_txt . '. ¿Querés sumar otra? Decime el nombre exacto.' );
							}
							if ( $mode2 === 'remove' ) {
								return APAI_Brain_Response_Builder::make_response( 'chat', 'Ese producto no tiene la categoría ' . $cats_txt . '. ¿Querés quitar otra? Decime el nombre exacto.' );
							}
							// For SET, treat as done.
							if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
								APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
							}
							return APAI_Brain_Response_Builder::make_response( 'consult', 'Ese producto ya estaba con esas categorías. No hice cambios.' );
						}
					}
				}
			}
		}

		$built = self::build_pending_action_for_product( $pid, $intent );
		if ( is_array( $built ) ) {
			// Clear follow-up ONLY when we actually created a pending action.
			if ( isset( $built['mode'] ) && $built['mode'] === 'execute' ) {
				if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
					APAI_Brain_Memory_Store::patch( array( self::FOLLOWUP_KEY => null ) );
				}
			}
			return $built;
		}

        return null;
    }

    private static function parse_intent( $msg_raw, $msg_norm ) {
        $selector = self::parse_selector( $msg_raw, $msg_norm );
        if ( ! is_array( $selector ) ) {
            return null;
        }

        // CATEGORIES (implicit): allow natural phrases without the word "categoría".
        // Examples:
        // - "Sumale Categoria1 al #151"
        // - "Sacale Categoria1 al 151"
        // - "Dejá al #151 solo en Categoria1"
        // Be resilient to accents/normalization: inference checks both raw and normalized forms.
        $implicit_cat = self::infer_category_intent_without_keyword( $msg_raw, $msg_norm, $selector );
        if ( is_array( $implicit_cat ) || is_wp_error( $implicit_cat ) ) {
            return $implicit_cat;
        }

        // NAME / TITLE
        if ( preg_match( '/\b(nombre|t[iÃ­]tulo)\b/iu', $msg_raw ) || preg_match( '/\brenombr[aÃ¡]\b/iu', $msg_norm ) ) {
            $val = self::extract_value_after_colon_or_a( $msg_raw );
            if ( $val === '' ) {
                return array(
                    'selector'  => $selector,
                    'field'     => 'name',
                    'ready'     => false,
                    'stage'     => 'need_value',
                    'question'  => '¿Cuál es el nuevo nombre/título que querés poner?',
                );
            }
            return array(
                'selector' => $selector,
                'field'    => 'name',
                'value'    => $val,
                'ready'    => true,
            );
        }

        // SHORT DESCRIPTION
        // Accept common natural variants (keep it conservative: must indicate short/brief description).
        // Examples: "descripción corta", "descripcion breve", "desc corta", "descripción breve".
        if (
            preg_match( '/descripci[oÃ³]n\s+corta/iu', $msg_raw ) ||
            preg_match( '/\bdescripcion\s+corta\b/iu', $msg_norm ) ||
            preg_match( '/descripci[oÃ³]n\s+breve/iu', $msg_raw ) ||
            preg_match( '/\bdescripcion\s+breve\b/iu', $msg_norm ) ||
            preg_match( '/\bdesc\s+corta\b/iu', $msg_norm ) ||
            preg_match( '/\bdesc\s+breve\b/iu', $msg_norm )
        ) {
            $val = self::extract_value_after_colon_or_a( $msg_raw );
            if ( $val === '' ) {
                return array(
                    'selector' => $selector,
                    'field'    => 'short_description',
                    'ready'    => false,
                    'stage'    => 'need_value',
                    'question' => '¿Cuál es la nueva descripción corta que deseas establecer?',
                );
            }
            return array(
                'selector' => $selector,
                'field'    => 'short_description',
                'value'    => $val,
                'ready'    => true,
            );
        }

        // CATEGORIES
        if ( preg_match( '/\bcategor[iÃ­]a(s)?\b/iu', $msg_raw ) || preg_match( '/\bcategoria(s)?\b/iu', $msg_norm ) ) {
            $mode = 'set';
            $mode_src = $msg_raw . ' ' . $msg_norm;
            if ( preg_match( '/\b(agreg|aÃ±ad|anad|sum|sumale|pon[eÃ©]le|ponele|ponelo|met[eÃ©]le|metelo|pasalo|pÃ¡salo|dejalo|dejÃ¡lo|manda|mandalo|mÃ¡ndalo)\w*/iu', $mode_src ) ) {
                $mode = 'add';
            } elseif ( preg_match( '/\b(quit|sac|sacalo|sacÃ¡lo|elim|borra|remov)\w*/iu', $mode_src ) ) {
                $mode = 'remove';
            } else {
                $mode = 'set';
            }

            $cats_text = '';

            // Remove selector tokens to isolate category text.
            $without_sel = $msg_raw;
            $without_sel = preg_replace( '/#\s*\d{1,10}/u', ' ', $without_sel );
            $without_sel = preg_replace( '/\b(?:del|al|de|id)\s+\d{1,10}\b/iu', ' ', $without_sel );
            $without_sel = preg_replace( '/\bsku\s*[:#]?\s*[a-z0-9\-_\.]{2,}\b/i', ' ', $without_sel );
            $without_sel = trim( preg_replace( '/\s+/', ' ', $without_sel ) );

            // For SET, prefer text after "a" (e.g., "... a Categoria1").
            if ( $mode === 'set' ) {
                $cats_text = self::extract_value_after_colon_or_a( $msg_raw );
                if ( $cats_text === '' && preg_match( '/\b(solo|solamente)\s+en\s+(.+)$/iu', $without_sel, $mx ) ) {
                    $cats_text = trim( $mx[2] );
                }
            }

            // Capture after the word "categoría" (works for "Agregá categoría X al #ID").
            if ( $cats_text === '' && preg_match( '/categor[iÃ­]a(?:s)?\s+(.+)$/iu', $without_sel, $m2 ) ) {
                $cats_text = trim( $m2[1] );
                // Strip trailing prepositions left over (e.g., "al", "de").
                $cats_text = preg_replace( '/\s+\b(?:al|a|del|de)\b\s*$/iu', '', $cats_text );
            }

            // Final fallback: after "a"
            if ( $cats_text === '' ) {
                $cats_text = self::extract_value_after_a( $msg_raw );
            }

            // Clean common trailing fragments like "al producto ..." that can sneak into captures.
            if ( $cats_text !== '' ) {
                $cats_text = preg_replace( '/\b(al|a|del|de)\s+producto\b.*$/iu', '', $cats_text );
                $cats_text = preg_replace( '/\bproducto\b.*$/iu', '', $cats_text );
                $cats_text = trim( preg_replace( '/\s+/', ' ', $cats_text ) );
            }

            $cats = self::split_categories( $cats_text );

            if ( empty( $cats ) ) {
                $q = ( $mode === 'add' )
                    ? '¿Qué categoría querés agregar?' 
                    : ( $mode === 'remove' ? '¿Qué categoría querés quitar?' : '¿Qué categoría querés asignar?' );
                return array(
                    'selector'  => $selector,
                    'field'     => 'category_ids',
                    'cat_mode'  => $mode,
                    'cat_inferred' => false,
                    'ready'     => false,
                    'stage'     => 'need_category',
                    'question'  => $q,
                );
            }

            return array(
                'selector'    => $selector,
                'field'       => 'category_ids',
                'cat_mode'    => $mode,
                'cat_inferred' => false,
                'categories'  => $cats,
                'ready'       => true,
            );
        }

        return null;
    }

    private static function build_pending_action_for_product( $product_id, $intent ) {
        $product_id = intval( $product_id );
        if ( $product_id <= 0 || ! is_array( $intent ) ) {
            return null;
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        if ( ! $product ) {
            return APAI_Brain_Response_Builder::make_response( 'chat', 'No encontré ese producto.' );
        }

        $changes = array();
        $field = isset( $intent['field'] ) ? (string) $intent['field'] : '';

        if ( $field === 'name' ) {
            $val = isset( $intent['value'] ) ? trim( (string) $intent['value'] ) : '';
            if ( $val === '' ) { return null; }
            $changes['name'] = $val;
        } elseif ( $field === 'short_description' ) {
            $val = isset( $intent['value'] ) ? trim( (string) $intent['value'] ) : '';
            if ( $val === '' ) { return null; }
            $changes['short_description'] = $val;
        } elseif ( $field === 'regular_price' ) {
            $raw = isset( $intent['value'] ) ? (string) $intent['value'] : '';
            $raw = trim( $raw );
            if ( $raw === '' ) { return null; }
            // Normalizer already handled common formats; we keep a conservative parse here.
            $num = is_numeric( $raw ) ? floatval( $raw ) : null;
            if ( $num === null ) { return null; }
            $changes['regular_price'] = number_format( $num, 2, '.', '' );
        } elseif ( $field === 'stock_quantity' ) {
            $raw = isset( $intent['value'] ) ? (string) $intent['value'] : '';
            $raw = trim( $raw );
            if ( $raw === '' ) { return null; }
            $num = is_numeric( $raw ) ? intval( $raw ) : null;
            if ( $num === null ) { return null; }
            $changes['manage_stock'] = true;
            $changes['stock_quantity'] = $num;
        } elseif ( $field === 'category_ids' ) {
            $mode = isset( $intent['cat_mode'] ) ? (string) $intent['cat_mode'] : 'set';
            $names = isset( $intent['categories'] ) && is_array( $intent['categories'] ) ? $intent['categories'] : array();
            $names = array_values( array_filter( array_map( 'trim', $names ) ) );
            if ( empty( $names ) ) { return null; }

            $term_ids = self::resolve_category_term_ids( $names );
            if ( is_wp_error( $term_ids ) ) {
                // UX: if category not found, ask in a human way and keep context for the next reply.
                if ( $term_ids->get_error_code() === 'category_not_found' ) {
                    $data = $term_ids->get_error_data();
                    $missing = ( is_array( $data ) && isset( $data['name'] ) ) ? (string) $data['name'] : (string) ( $names[0] ?? '' );
                    // Safety: clean the missing label so we don't show verbs like "Sumale ...".
                    $missing = self::normalize_category_label_candidate( (string) $missing );
                    $sugs = ( is_array( $data ) && isset( $data['suggestions'] ) && is_array( $data['suggestions'] ) ) ? $data['suggestions'] : array();

                    $msg = 'No encontré la categoría “' . esc_html( $missing ) . '”.';
                    if ( ! empty( $sugs ) ) {
                        $msg .= ' ¿Quisiste decir ';
                        $quoted = array();
                        foreach ( array_slice( $sugs, 0, 3 ) as $s ) {
                            $quoted[] = '“' . esc_html( $s ) . '”';
                        }
                        $msg .= implode( ' / ', $quoted ) . '?';
                    }
                    $msg .= ' Decime el nombre exacto de la categoría (podés responder solo con el nombre).';

                    // If the intent was inferred (no "categoría" keyword), allow the user to pivot.
                    $inferred = ! empty( $intent['cat_inferred'] );
                    if ( $inferred ) {
                        $msg .= ' Si en realidad no era una categoría, decime qué querías sumar/quitar.';
                    }

					// If we have good suggestions, show them as quick options (UI renders "Opciones" as clickable buttons).
					if ( ! empty( $sugs ) ) {
						$opts = array();
						foreach ( array_slice( $sugs, 0, 3 ) as $s ) {
							$opts[] = '• ' . (string) $s;
						}
						if ( ! empty( $opts ) ) {
							$msg .= "\n\nOpciones:\n" . implode( "\n", $opts );
						}
					}

                    // Persist follow-up so a plain "Categoria1" works next.
                    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                        APAI_Brain_Memory_Store::patch( array(
                            self::FOLLOWUP_KEY => array(
                                'product_id' => $product_id,
                                'field'      => 'category_ids',
                                'cat_mode'   => $mode,
                                'stage'      => 'need_category',
                            ),
                        ) );
                    }

                    // Pro UX: show 2–3 best suggestions as clickable options (clarify mode), while still allowing free text.
                    $clar = array(
                        'question'      => $msg,
                        'needed_fields' => array( 'category_name' ),
                        'choices'       => ! empty( $sugs ) ? array_slice( array_values( $sugs ), 0, 3 ) : null,
                    );

                    return APAI_Brain_Response_Builder::make_response(
                        'clarify',
                        $msg,
                        array(),
                        null,
                        $clar,
                        array( 'needs_clarification' => true )
                    );
                }

                return APAI_Brain_Response_Builder::make_response( 'chat', $term_ids->get_error_message() );
            }
            $term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

            $current = $product->get_category_ids();
            if ( ! is_array( $current ) ) { $current = array(); }
            $current = array_values( array_unique( array_map( 'intval', $current ) ) );

            $final = $current;
            if ( $mode === 'set' ) {
                $final = $term_ids;
            } elseif ( $mode === 'add' ) {
                $final = array_values( array_unique( array_merge( $current, $term_ids ) ) );
            } elseif ( $mode === 'remove' ) {
                $final = array_values( array_diff( $current, $term_ids ) );
            }

            if ( empty( $final ) ) {
                $default_cat = self::get_default_product_cat_id();
                if ( $default_cat > 0 ) {
                    $final = array( intval( $default_cat ) );
                }
            }

			// NOOP real: if categories would stay exactly the same, do not create a pending action.
			$cur_sorted = $current;
			$fin_sorted = $final;
			sort( $cur_sorted );
			sort( $fin_sorted );
			if ( $cur_sorted === $fin_sorted ) {
				$cats_txt = implode( ', ', $names );
				if ( $mode === 'add' ) {
					return APAI_Brain_Response_Builder::make_response( 'consult', 'Ese producto ya tiene la categoría ' . $cats_txt . '. No hice cambios.' );
				}
				if ( $mode === 'remove' ) {
					return APAI_Brain_Response_Builder::make_response( 'consult', 'Ese producto no tiene la categoría ' . $cats_txt . '. No hice cambios.' );
				}
				return APAI_Brain_Response_Builder::make_response( 'consult', 'Ese producto ya estaba con esas categorías. No hice cambios.' );
			}

            $changes['category_ids'] = $final;
        } else {
            return null;
        }

        // Invariant: no empty pending.
        if ( $product_id <= 0 || empty( $changes ) ) {
            return null;
        }

        $human_summary = self::human_summary( $product, $intent, $changes );

        $action = array(
            'type'          => 'update_product',
            'human_summary' => $human_summary,
            'product_id'    => $product_id,
            'changes'       => $changes,
        );

        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            APAI_Brain_Memory_Store::persist_pending_action( $action );
        }

        $confirmation = array(
            'required'      => true,
            'prompt'        => '',
            'ok'            => 'Confirmar y ejecutar acción',
            'cancel'        => 'Cancelar',
            'cancel_tokens' => array( 'no', 'cancelar', 'cancelÃ¡', 'cancela' ),
        );

        return APAI_Brain_Response_Builder::make_response( 'execute', 'Dale, preparé el cambio. 😊', array( $action ), $confirmation );
    }

    private static function human_summary( $product, $intent, $changes ) {
        $id   = is_object( $product ) ? $product->get_id() : 0;
        $name = is_object( $product ) ? $product->get_name() : '';

        $field = isset( $intent['field'] ) ? (string) $intent['field'] : '';
        if ( $field === 'name' ) {
            return 'Cambiar el nombre de #' . $id . ' (' . $name . ') a “' . (string) $changes['name'] . '”';
        }
        if ( $field === 'short_description' ) {
            $snip = self::snippet( isset( $changes['short_description'] ) ? (string) $changes['short_description'] : '', 80 );
            return 'Cambiar la descripción corta de #' . $id . ' (' . $name . ') a “' . $snip . '”';
        }
        if ( $field === 'category_ids' ) {
            $mode = isset( $intent['cat_mode'] ) ? (string) $intent['cat_mode'] : 'set';
            $cats = isset( $intent['categories'] ) && is_array( $intent['categories'] ) ? $intent['categories'] : array();
            $cats = array_values( array_filter( array_map( 'trim', $cats ) ) );
            $cats_txt = implode( ', ', $cats );
            if ( $mode === 'add' ) {
                return 'Agregar categoría(s) ' . $cats_txt . ' a #' . $id . ' (' . $name . ') (sin borrar las demás)';
            }
            if ( $mode === 'remove' ) {
                return 'Quitar categoría(s) ' . $cats_txt . ' de #' . $id . ' (' . $name . ') (sin tocar las otras)';
            }
            return 'Cambiar categorías de #' . $id . ' (' . $name . ') a: ' . $cats_txt;
        }
        return 'Actualizar producto #' . $id . ' (' . $name . ')';
    }

    private static function snippet( $text, $max = 80 ) {
        $t = trim( wp_strip_all_tags( (string) $text ) );
        if ( $t === '' ) { return ''; }
        if ( function_exists( 'mb_substr' ) ) {
            $s = mb_substr( $t, 0, $max, 'UTF-8' );
        } else {
            $s = substr( $t, 0, $max );
        }
        if ( strlen( $t ) > strlen( $s ) ) { $s .= '…'; }
        return $s;
    }

    private static function get_default_product_cat_id() {
        $id = intval( get_option( 'default_product_cat', 0 ) );
        if ( $id > 0 ) { return $id; }
        $term = get_term_by( 'slug', 'uncategorized', 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            return intval( $term->term_id );
        }
        return 0;
    }

    private static function parse_selector( $msg_raw, $msg_norm ) {
        // ID: #152
        if ( preg_match( '/#\s*(\d{1,10})/u', $msg_raw, $m ) ) {
            return array( 'type' => 'id', 'value' => intval( $m[1] ) );
        }
        // ID without '#': del 152 / al 152 / id 152
        if ( preg_match( '/\b(?:del|al|de|id)\s+(\d{1,10})\b/iu', $msg_raw, $m ) ) {
            return array( 'type' => 'id', 'value' => intval( $m[1] ) );
        }
        // Producto 152 / producto #152
        if ( preg_match( '/\b(?:producto|prod)\s+#?\s*(\d{1,10})\b/iu', $msg_raw, $m ) ) {
            return array( 'type' => 'id', 'value' => intval( $m[1] ) );
        }
        // SKU
        if ( preg_match( '/\bsku\s*[:#]?\s*([a-z0-9\-_\.]{2,})\b/i', $msg_raw, $m ) ) {
            return array( 'type' => 'sku', 'value' => sanitize_text_field( $m[1] ) );
        }
        // Contextual selector: "este" / "ese" / "el mismo" (uses last_target_product_id).
        if ( preg_match( '/\b(este|ese|el\s+mismo|la\s+misma)\b/iu', $msg_raw ) ) {
            return array( 'type' => 'context_last', 'value' => null );
        }
        return null;
    }

    private static function resolve_product_id( $intent, $store_state = null ) {
        if ( ! is_array( $intent ) || ! isset( $intent['selector'] ) || ! is_array( $intent['selector'] ) ) {
            return 0;
        }
        $sel = $intent['selector'];
        $type = isset( $sel['type'] ) ? (string) $sel['type'] : '';
        $val  = isset( $sel['value'] ) ? $sel['value'] : null;

        if ( $type === 'context_last' ) {
            $pid = 0;
            if ( is_array( $store_state ) ) {
                if ( isset( $store_state['last_target_product_id'] ) ) {
                    $pid = intval( $store_state['last_target_product_id'] );
                }
                if ( $pid <= 0 && isset( $store_state['last_product'] ) && is_array( $store_state['last_product'] ) && isset( $store_state['last_product']['id'] ) ) {
                    $pid = intval( $store_state['last_product']['id'] );
                }
            }
            return $pid > 0 ? $pid : 0;
        }

        if ( $type === 'id' ) {
            $pid = intval( $val );
            return $pid > 0 ? $pid : 0;
        }
        if ( $type === 'sku' ) {
            $sku = is_string( $val ) ? sanitize_text_field( $val ) : '';
            if ( $sku === '' || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
                return 0;
            }
            $pid = wc_get_product_id_by_sku( $sku );
            return $pid ? intval( $pid ) : 0;
        }
        return 0;
    }

    private static function extract_value_after_a( $msg ) {
        // Capture after " a " (last occurrence).
        if ( preg_match( '/\s+a\s+(.+)$/iu', $msg, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    /**
     * Capture a value after common separators used in natural phrasing.
     * Examples:
     * - "Poné de descripción corta al #152: 100% algodón"
     * - "Nombre del #152: Remera Azul"
     */
    private static function extract_value_after_colon_or_a( $msg ) {
        $msg = (string) $msg;
        // Prefer explicit separator ":" first.
        if ( preg_match( '/:\s*(.+)$/u', $msg, $m ) ) {
            return trim( $m[1] );
        }
        // Then allow common dashes (— – -).
        if ( preg_match( '/[—–\-]\s*(.+)$/u', $msg, $m ) ) {
            return trim( $m[1] );
        }
        return self::extract_value_after_a( $msg );
    }

    /**
     * Infer category intent when user doesn't type the word "categoría".
     * We keep it conservative: only when there's a clear verb + a non-numeric label.
     */
    private static function infer_category_intent_without_keyword( $msg_raw, $msg_norm, $selector ) {
		// A1.5.18: Disabled.
		// This heuristic caused unacceptable false positives, e.g.:
		// - "ponelo más barato" -> interpreted as category change
		// - "poné más stock del producto 386 a 9" -> interpreted as category change
		// Category updates must be explicit (mention "categoría" / "categoria").
		return null;

		// IMPORTANT: this is a best-effort fallback.
		// It must NOT hijack common update intents like price/stock changes.
		if ( preg_match( '/\b(precio|stock|barat|caro|cuesta|vale|cantidad|unidades)\b/ui', $msg_norm ) ) {
			return null;
		}

		// If there's an explicit "a <n>" pattern plus an update-like verb, it's almost certainly not a category.
		if ( preg_match( '/\b(a)\s*\d+\b/ui', $msg_norm ) && preg_match( '/\b(pon[ée]|pone|ponelo|ponela|cambi[áa]|ajust[áa])\b/ui', $msg_norm ) ) {
			return null;
		}

        // If the user already wrote "categoría", let the explicit parser handle it.
        if ( preg_match( '/\bcategor[iÃ­]a(s)?\b/iu', $msg_raw ) || preg_match( '/\bcategoria(s)?\b/iu', $msg_norm ) ) {
            return null;
        }

        $mode = '';
        $cats_text = '';

        // SET: "solo en X" / "solamente en X".
        // Try raw first (keeps original accents), then normalized as fallback.
        if ( preg_match( '/\b(?:solo|solamente)\s+en\s+(.+)$/iu', $msg_raw, $m ) || preg_match( '/\b(?:solo|solamente)\s+en\s+(.+)$/iu', $msg_norm, $m ) ) {
            $mode = 'set';
            $cats_text = trim( $m[1] );
            $cats_text = self::normalize_category_label_candidate( $cats_text );
        }

        // ADD / REMOVE via verbs.
        // ADD / REMOVE via verbs.
        if ( $mode === '' ) {
            // Natural verbs (Spanish) without requiring the word "categoría".
            // We keep this list short; we validate the "value" against real WooCommerce categories later.
            if ( preg_match( '/\b(sumale|sumá|suma|agregale|agregá|agrega|añadí|añade|añadir|metele|metelo|metélo|mete|meter|ponele|ponéle|pone|ponelo|poner|pasalo|pásalo|mandalo|mándalo|manda|deja|dejá|dejalo|dejálo|dejar)\b/iu', $msg_norm ) ) {
                $mode = 'add';
            } elseif ( preg_match( '/\b(sacale|sacalo|sacálo|sacá|saca|quitale|quitá|quita|quitar|eliminale|eliminá|elimina|borrá|borra|remové|remove|remover)\b/iu', $msg_norm ) ) {
                $mode = 'remove';
            }
        }

	        if ( $mode === 'add' || $mode === 'remove' ) {
	            $tmp = $msg_raw;
	            // Remove selector tokens to isolate the label.
	            $tmp = preg_replace( '/#\s*\d{1,10}/u', ' ', $tmp );
	            $tmp = preg_replace( '/\b(?:del|al|de|id|producto|prod)\s+#?\s*\d{1,10}\b/iu', ' ', $tmp );
	            $tmp = preg_replace( '/\bsku\s*[:#]?\s*[a-z0-9\-_\.]{2,}\b/i', ' ', $tmp );
	            $tmp = trim( preg_replace( '/\s+/', ' ', $tmp ) );

	            // IMPORTANT: Extract the label *after* the verb, not the verb itself.
	            $verb_re = ( $mode === 'add' )
	                ? '(sumale|sumá|suma|agregale|agregá|agrega|añadí|añade|añadir|metele|metelo|metélo|mete|meter|ponele|ponéle|pone|ponelo|poner|pasalo|pásalo|mandalo|mándalo|manda|deja|dejá|dejalo|dejálo|dejar)'
	                : '(sacale|sacalo|sacálo|sacá|saca|quitale|quitá|quita|quitar|eliminale|eliminá|elimina|borrá|borra|remové|remove|remover)';

	            // 1) Remove the leading verb.
	            $tmp2 = preg_replace( '/^\s*' . $verb_re . '\b\s*/iu', '', $tmp );
	            // 2) Remove leading articles ("la", "el", "una", ...).
	            $tmp2 = preg_replace( '/^\b(?:la|el|las|los|un|una|unos|unas)\b\s+/iu', '', $tmp2 );
	            // 2.5) Remove leading prepositions that appear in natural phrasing ("en", "a", "al", ...).
	            $tmp2 = preg_replace( '/^\b(?:en|a|al|de|del|para|hacia|dentro\s+de)\b\s+/iu', '', $tmp2 );
	            // 3) Strip trailing prepositions ("al", "a", "del", ...).
	            $tmp2 = preg_replace( '/\s+\b(?:al|a|para|en|del|de)\b\s*$/iu', '', $tmp2 );
	            $cats_text = trim( (string) $tmp2 );
	        }

		// Extra safety: normalize the candidate again to avoid accidentally keeping the verb
		// (we have seen cases like "Sumale Ropa22222" being treated as the category label).
		$cats_text = self::normalize_category_label_candidate( $cats_text );
		$cats_text = preg_replace(
			'/^\s*(?:sumale|sumÃ¡|suma|agregale|agregÃ¡|agrega|aÃ±adÃ­|aÃ±ade|aÃ±adir|metele|metelo|metÃ©lo|ponele|ponÃ©le|pone|ponelo|pasalo|pÃ¡salo|mandalo|mÃ¡ndalo|manda|sacale|sacalo|sacÃ¡lo|sacÃ¡|saca|quitale|quitÃ¡|quita|eliminale|eliminÃ¡|elimina|borra|borrÃ¡|removÃ©|remover|remove)\b\s+/iu',
			'',
			(string) $cats_text
		);

        $cats_text = trim( (string) $cats_text );
        $cats_text = trim( $cats_text, " \t\n\r\0\x0B\"'«»“”" );

        // Conservative: ignore purely numeric "values" (likely stock/precio).
        if ( $cats_text === '' || preg_match( '/^\d+(?:[\.,]\d+)?$/u', $cats_text ) ) {
            return null;
        }
        // Must contain at least one letter.
        if ( ! preg_match( '/\p{L}/u' , $cats_text ) ) {
            return null;
        }

        $cats = self::split_categories( $cats_text );
        if ( empty( $cats ) ) {
            return null;
        }

        // If we inferred SET without an explicit "solo en", default is ADD/REMOVE only.
        if ( $mode === '' ) {
            return null;
        }

        return array(
            'selector'   => $selector,
            'field'      => 'category_ids',
            'cat_mode'   => $mode,
            'categories' => $cats,
            'ready'      => true,
            // Flag: inferred category intent without using the word "categoría".
            'cat_inferred' => true,
        );
    }


    /**
     * Clean a category label candidate by removing selector fragments and trailing stop-words.
     * This prevents capturing extra words like "el producto 151" or "al #151".
     */
    private static function normalize_category_label_candidate( $text ) {
        $t = trim( (string) $text );
        if ( $t === '' ) { return ''; }

        // Remove common selector fragments.
        $t = preg_replace( '/#\s*\d{1,10}/u', ' ', $t );
        $t = preg_replace( '/\b(?:del|al|de|id)\s+#?\s*\d{1,10}\b/iu', ' ', $t );
        $t = preg_replace( '/\b(?:el\s+)?producto\s+#?\s*\d{1,10}\b/iu', ' ', $t );
        $t = preg_replace( '/\bsku\s*[:#]?\s*[a-z0-9\-_\.]{2,}\b/i', ' ', $t );

        $t = trim( preg_replace( '/\s+/', ' ', $t ) );
        $t = trim( $t, " \t\n\r\0\x0B\"'«»“”" );

        // Remove trailing prepositions/articles that often remain after selector removal.
        for ( $i = 0; $i < 3; $i++ ) {
            $t2 = preg_replace( '/\s+\b(?:al|a|en|del|de|para|por|el|la|los|las|un|una|unos|unas)\b\s*$/iu', '', $t );
            $t2 = trim( $t2 );
            if ( $t2 === $t ) { break; }
            $t = $t2;
        }

        return trim( $t );
    }

    /**
     * Find real WooCommerce category names mentioned inside a free-text sentence.
     * Used for follow-ups like "quise decir que la categoría es ropa".
     */
    private static function find_category_names_in_text( $text ) {
        $text = trim( (string) $text );
        if ( $text === '' ) { return array(); }
        if ( ! function_exists( 'get_terms' ) ) { return array(); }

        $norm = function( $s ) {
            $s = (string) $s;
            if ( function_exists( 'remove_accents' ) ) { $s = remove_accents( $s ); }
            $s = strtolower( $s );
            $s = preg_replace( '/\s+/', ' ', $s );
            return trim( $s );
        };

        $hay = $norm( $text );
        if ( $hay === '' ) { return array(); }

        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'number'     => 0,
        ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return array(); }

        $found = array();
        foreach ( $terms as $t ) {
            if ( ! is_object( $t ) || ! isset( $t->name ) ) { continue; }
            $cand = (string) $t->name;
            $cand_norm = $norm( $cand );
            if ( $cand_norm === '' ) { continue; }

            $re = '/(?:^|\b)' . preg_quote( $cand_norm, '/' ) . '(?:\b|$)/u';
            if ( preg_match( $re, $hay ) ) {
                $found[] = $cand;
            }
        }

        usort( $found, function( $a, $b ) {
            return strlen( (string) $b ) <=> strlen( (string) $a );
        } );

        return array_values( array_unique( $found ) );
    }

    /**
     * Extract category names from a follow-up sentence.
     * Accepts either a plain name ("Categoria1") or a sentence ("la categoría es ropa").
     */
    private static function extract_categories_from_followup_message( $msg_raw ) {
        $raw = trim( (string) $msg_raw );
        if ( $raw === '' ) { return array(); }

        if ( preg_match( '/\bcategor(?:ia|ía)(?:s)?\b[^\p{L}\p{N}]*(?:es|era|seria|sería|:)\s*(.+)$/iu', $raw, $m ) ) {
            $candidate = self::normalize_category_label_candidate( $m[1] );
            $out = self::split_categories( $candidate );
            if ( ! empty( $out ) ) { return $out; }
        }

        $found = self::find_category_names_in_text( $raw );
        if ( ! empty( $found ) ) {
            return array_slice( $found, 0, 3 );
        }

        return array();
    }

    private static function split_categories( $text ) {
        $text = trim( (string) $text );
        if ( $text === '' ) { return array(); }

        // Normalize connector "y" to comma.
        $t = preg_replace( '/\s+y\s+/iu', ',', $text );
        $parts = array_map( 'trim', explode( ',', $t ) );
        $parts = array_values( array_filter( $parts ) );

        $out = array();
        foreach ( $parts as $p ) {
            $p2 = self::normalize_category_label_candidate( $p );
            if ( $p2 !== '' ) { $out[] = $p2; }
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * Return up to 3 category name suggestions for a miss.
     * Conservative: only suggest when similarity is reasonably high.
     */
    private static function suggest_category_names( $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) { return array(); }

        if ( ! function_exists( 'get_terms' ) ) { return array(); }

        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'number'     => 0,
        ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return array(); }

        $norm = function( $s ) {
            $s = (string) $s;
            if ( function_exists( 'remove_accents' ) ) { $s = remove_accents( $s ); }
            $s = strtolower( $s );
            $s = preg_replace( '/\s+/', ' ', $s );
            return trim( $s );
        };

        $needle = $norm( $name );
        if ( $needle === '' ) { return array(); }

        $scores = array();
        foreach ( $terms as $t ) {
            if ( ! is_object( $t ) || ! isset( $t->name ) ) { continue; }
            $cand_norm = $norm( $t->name );
            if ( $cand_norm === '' ) { continue; }
			// Prefix/containment heuristic: helps with cases like "ropa222" -> "ropa".
			$dist = 999;
			if ( strpos( $needle, $cand_norm ) === 0 || strpos( $cand_norm, $needle ) === 0 ) {
				$dist = 0;
			}
			if ( $dist === 999 ) {
				$dist = function_exists( 'levenshtein' ) ? levenshtein( $needle, $cand_norm ) : 999;
			}
            // Fallback to similar_text when levenshtein isn't available (rare).
            if ( $dist === 999 && function_exists( 'similar_text' ) ) {
                $pct = 0;
                similar_text( $needle, $cand_norm, $pct );
                $dist = (int) round( 100 - $pct );
            }
            $scores[] = array( 'name' => (string) $t->name, 'dist' => (int) $dist );
        }

        if ( empty( $scores ) ) { return array(); }
        usort( $scores, function( $a, $b ) {
            return $a['dist'] <=> $b['dist'];
        } );

        $max_dist = max( 2, (int) floor( strlen( $needle ) / 3 ) );
        $out = array();
        foreach ( $scores as $row ) {
            if ( count( $out ) >= 3 ) { break; }
            if ( $row['dist'] > $max_dist ) { continue; }
            if ( $norm( $row['name'] ) === $needle ) { continue; }
            $out[] = $row['name'];
        }

        return array_values( array_unique( $out ) );
    }

    private static function resolve_category_term_ids( $names ) {
        if ( ! is_array( $names ) ) { $names = array(); }
        $names = array_values( array_filter( array_map( 'trim', $names ) ) );
        if ( empty( $names ) ) {
            return new WP_Error( 'no_categories', 'No me pasaste ninguna categoría.' );
        }

        $ids = array();
        foreach ( $names as $name ) {
            $term = get_term_by( 'name', $name, 'product_cat' );
            if ( ! $term ) {
                // Try case-insensitive match
                $terms = get_terms( array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'name__like' => $name,
                    'number'     => 5,
                ) );
                if ( is_array( $terms ) ) {
                    foreach ( $terms as $t ) {
                        if ( is_object( $t ) && isset( $t->name ) && strtolower( $t->name ) === strtolower( $name ) ) {
                            $term = $t;
                            break;
                        }
                    }
                }
            }

            if ( ! $term || is_wp_error( $term ) ) {
                $sug = self::suggest_category_names( $name );
                $err = new WP_Error( 'category_not_found', 'No encontré la categoría “' . esc_html( $name ) . '”.' );
                $err->add_data( array( 'name' => (string) $name, 'suggestions' => $sug ) );
                return $err;
            }
            $ids[] = intval( $term->term_id );
        }

        return $ids;
    }
}
