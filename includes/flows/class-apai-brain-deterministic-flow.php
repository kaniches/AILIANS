<?php
/**
 * DeterministicFlow — price/stock update for ordinal targets ("primer/segundo/..." y "último" producto)
 *
 * @FLOW Deterministic
 * Purpose:
 * - Detect a conservative, deterministic intent to update the price of the last product.
 * - Create a server-side pending_action (Step 2/2.5) without executing anything.
 *
 * @INVARIANTS
 * - This flow MUST NEVER execute actions.
 * - This flow MUST NEVER imply execution in language (no "voy a", "cambiaré", "actualizo").
 * - All user-facing copy for pending actions should be proposal-style.
 * - Source of truth for UI buttons is store_state.pending_action (server-side).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Deterministic_Flow {

    /**
     * Resolve a product ID by ordinal position in catalog creation order.
     *
     * UX definition (AutoProduct AI):
     * - "primer producto" = first uploaded/created product (oldest)
     * - "último producto" = last uploaded/created product (newest)
     * - "segundo producto" = second uploaded/created product, etc.
     *
     * @param int  $n 1-based position (1 = first/oldest)
     * @param bool $from_end When true, count from newest (1 = newest)
     * @return int Product ID or 0
     */
    private static function resolve_product_id_by_ordinal( $n, $from_end = false ) {
        $n = (int) $n;
        if ( $n <= 0 ) { return 0; }
        global $wpdb;
        $order = $from_end ? 'DESC' : 'ASC';
        $offset = $n - 1;

        // UX definition:
        // - "primer/segundo/... producto" refiere a la posición absoluta del catálogo.
        // - En WordPress/WooCommerce, el ID autoincremental es el proxy más estable de “orden de creación”.
        // - No usamos "post_modified" (porque cambia con ediciones) y evitamos depender de "post_date" (editable/imports).
        //
        // Incluimos estados comunes además de publish: muchos catálogos tienen productos en borrador/privados.
        // (No ejecuta nada: solo prepara pending_action.)
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','draft','private','pending','future') ORDER BY ID {$order} LIMIT 1 OFFSET %d",
            $offset
        );
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Parse ordinal reference for "producto" from normalized text.
     * Supports: primer/primero, segundo, tercero, cuarto, quinto, and numbers 1-10.
     * Returns 1-based index, or 0 if not found.
     */
    private static function extract_ordinal_index( $m_norm ) {
		$lc = mb_strtolower( (string) $m_norm, 'UTF-8' );

		// Spanish ordinal words.
		if ( preg_match( '/\bprimer(?:o|a)?\b/iu', $lc ) ) { return 1; }
		if ( preg_match( '/\bsegund(?:o|a)?\b/iu', $lc ) ) { return 2; }
		if ( preg_match( '/\btercer(?:o|a)?\b/iu', $lc ) ) { return 3; }
		if ( preg_match( '/\bcuart(?:o|a)?\b/iu', $lc ) ) { return 4; }
		if ( preg_match( '/\bquint(?:o|a)?\b/iu', $lc ) ) { return 5; }
		if ( preg_match( '/\bsext(?:o|a)?\b/iu', $lc ) ) { return 6; }
		if ( preg_match( '/\bs[eé]ptim(?:o|a)?\b/iu', $lc ) ) { return 7; }
		if ( preg_match( '/\boctav(?:o|a)?\b/iu', $lc ) ) { return 8; }
		if ( preg_match( '/\bnovena?\b/iu', $lc ) ) { return 9; }
		if ( preg_match( '/\bd[eé]cim(?:o|a)?\b/iu', $lc ) ) { return 10; }

		// Explicit numeric ordinals like "2º" / "3ro".
		if ( preg_match( '/\b(1|2|3|4|5|6|7|8|9|10)\s*(?:º|°|er|ro|do|to|mo|vo|no)\b/iu', $lc, $m ) ) {
			return intval( $m[1] );
		}

		// Explicit product position references: "producto #3" / "producto numero 3" / "3 producto".
		if ( preg_match( '/\bproducto\s*(?:n(?:ú|u)mero|num|n|#)?\s*(\d{1,2})\b/iu', $lc, $m ) ) {
			return intval( $m[1] );
		}
		if ( preg_match( '/\b(\d{1,2})\s*(?:º|°)?\s*producto\b/iu', $lc, $m ) ) {
			return intval( $m[1] );
		}

		return 0;
	}

	/** Human label for an ordinal index (1->\"1º\", 2->\"2º\", etc). */
	private static function ordinal_label( $n ) {
        $n = (int) $n;
        if ( $n === 1 ) { return 'primer producto'; }
        if ( $n === 2 ) { return 'segundo producto'; }
        if ( $n === 3 ) { return 'tercer producto'; }
        if ( $n === 4 ) { return 'cuarto producto'; }
        if ( $n === 5 ) { return 'quinto producto'; }
        return $n > 0 ? ( $n . 'º producto' ) : 'producto';
    }

	/**
	 * Extract a numeric "phrase" from a message.
	 *
	 * We purposely capture common human formats like:
	 * - "$1.000" / "1,000" / "1 000"
	 * - "10k" / "10 mil" / "10 lucas"
	 * - negative numbers ("-5")
	 */
	private static function extract_numeric_phrase( $message ) {
	    $message = (string) $message;

	    // Prefer numbers that come after common assignment/preposition tokens.
	    if ( preg_match( '/\b(?:a|en|=|:)\s*(-?[0-9][0-9\.,\s]*(?:\s*(?:k|mil|lucas?))?)/iu', $message, $m ) ) {
	        return trim( $m[1] );
	    }

	    // Fallback: first numeric-looking phrase.
	    if ( preg_match( '/(-?[0-9][0-9\.,\s]*(?:\s*(?:k|mil|lucas?))?)/iu', $message, $m ) ) {
	        return trim( $m[1] );
	    }

	    return null;
	}

	/**
	 * Evita summaries gigantes (por ejemplo títulos con "(copia)" repetido) que ensucian el chat.
	 */
	private static function truncate_title( $title, $max = 80 ) {
	    $title = trim( (string) $title );
	    $max   = (int) $max;
	    if ( $max < 10 ) { $max = 10; }
	    if ( $title === '' ) { return ''; }

	    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
	        if ( mb_strlen( $title, 'UTF-8' ) > $max ) {
	            return mb_substr( $title, 0, $max - 1, 'UTF-8' ) . '…';
	        }
	        return $title;
	    }

	    if ( strlen( $title ) > $max ) {
	        return substr( $title, 0, $max - 1 ) . '…';
	    }
	    return $title;
	}

    /**
     * Try handle a deterministic price update.
     *
     * @param string $message Raw user message
     * @param string $m_norm  Normalized message
     * @param array|null $store_state
     * @return array|null Brain response payload (APAI_Brain_REST::make_response) or null
     */
    public static function try_handle( $message, $m_norm, $store_state = null ) {
        $m_norm = is_string( $m_norm )
            ? $m_norm
            : ( class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::normalize_intent_text( $message ) : $message );



        // If there's a target selection pending (selector), we should not start a new deterministic action.
        // Let TargetedUpdateFlow handle selection replies and/or PendingFlow guard new intents.
        if ( is_array( $store_state ) ) {
            if ( ! empty( $store_state['pending_target_selection'] ) || ! empty( $store_state['pending_targeted_update'] ) ) {
                return null;
            }
        }

        // Deterministic cancel when there is NO pending action (avoid ModelFlow on "cancelar").
        if ( preg_match( '/^\s*(cancelar|cancelá|cancela|cancel|anular|abortar)\s*$/iu', $m_norm ) ) {
            return APAI_Brain_Response_Builder::make_response(
                'message',
                'No hay ninguna acción pendiente para cancelar 😊',
                array( 'route' => 'DeterministicFlow' )
            );
        }

        // -------------------------------------------------------------
        // Shorthand intents (no verb):
        //   "precio 100"  => update last product price
        //   "stock 5"     => update last product stock
        // -------------------------------------------------------------
        if ( preg_match( '/^\s*(precio|stock)\s*[:=]?\s*([0-9][0-9\.,]*)\s*$/iu', $m_norm, $m_short ) ) {

            $kind    = ( $m_short[1] === 'precio' ) ? 'price' : 'stock';
            $raw_num = isset( $m_short[2] ) ? $m_short[2] : null;
            if ( $raw_num !== null ) {
                if ( $kind === 'price' ) {
                    $parsed  = APAI_Brain_Normalizer::parse_price_number( $raw_num );
                    $price_n = is_numeric( $parsed ) ? floatval( $parsed ) : null;
                    if ( $price_n === null || $price_n <= 0 ) {
                        $msg = ( $price_n !== null && $price_n < 0 ) ? 'El precio no puede ser negativo.' : 'El precio debe ser mayor a 0.';
                        return APAI_Brain_Response_Builder::make_response( 'message', $msg, array() );
                    }
                    $price_str = self::format_price_string( $price_n );

                    // Prefer the last targeted product for ambiguous shorthand (e.g. "precio 2000").
                    $product_id = 0;
                    $used_last_target = false;
                    if ( is_array( $store_state ) && ! empty( $store_state['last_target_product_id'] ) ) {
                        $product_id = intval( $store_state['last_target_product_id'] );
                        if ( $product_id > 0 ) { $used_last_target = true; }
                    }
                    if ( ! $product_id ) {
                        $product_id = self::resolve_product_id_by_ordinal( 1, true );
                    }
                    if ( ! $product_id ) {
                        return APAI_Brain_Response_Builder::make_response( 'message', 'No encontré productos para actualizar.', array() );
                    }
                    $target_label = $used_last_target ? ( 'producto #' . $product_id ) : 'último producto';

                    $action = array(
                        'type'          => 'update_product',
                        'product_id'    => $product_id,
                        'changes'       => array( 'regular_price' => $price_str ),
                        'human_summary' => 'Cambiar precio del ' . $target_label . ' a ' . $price_str,
                    );

                    // A1.1 — No-op real: if the product already has this value, don't create pending.
                    $noop = APAI_Brain_NoOp::filter_product_changes( $product_id, $action['changes'] );
                    if ( ! empty( $noop['noop'] ) ) {
                        APAI_Brain_Trace::emit_current( 'noop', array(
                            'kind'       => 'price',
                            'product_id' => $product_id,
                            'dropped'    => $noop['dropped'],
                        ) );
                        APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'price', 'last_target_product_id' => $product_id ) );
                        return APAI_Brain_Response_Builder::make_response(
                            'message',
                            'Ya estaba en ' . $price_str . '. No hice cambios.',
                            array( 'route' => 'DeterministicFlow' )
                        );
                    }
                    $action['changes'] = $noop['changes'];
                    APAI_Brain_Memory_Store::persist_pending_action( $action );
                    APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'price' ) );
                    return APAI_Brain_Response_Builder::action_prepared( $action, (new APAI_Brain_NLG())->msg_action_prepared_default() );
                }

                // stock shorthand
                $qty_f = APAI_Brain_Normalizer::parse_number( $raw_num );
                $qty_i = is_numeric( $qty_f ) ? (int) round( $qty_f ) : null;
                if ( $qty_i === null || $qty_i < 0 ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', 'El stock no puede ser negativo.', array() );
                }
                // Prefer the last targeted product for ambiguous shorthand (e.g. "stock 5").
                $product_id = 0;
                $used_last_target = false;
                if ( is_array( $store_state ) && ! empty( $store_state['last_target_product_id'] ) ) {
                    $product_id = intval( $store_state['last_target_product_id'] );
                    if ( $product_id > 0 ) { $used_last_target = true; }
                }
                if ( ! $product_id ) {
                    $product_id = self::resolve_product_id_by_ordinal( 1, true );
                }
                if ( ! $product_id ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', 'No encontré productos para actualizar.', array() );
                }
                $target_label = $used_last_target ? ( 'producto #' . $product_id ) : 'último producto';

                $action = array(
                    'type'          => 'update_product',
                    'product_id'    => $product_id,
					'changes'       => array( 'manage_stock' => true, 'stock_quantity' => (int) $qty_i ),
                    'human_summary' => 'Cambiar stock del ' . $target_label . ' a ' . $qty_i,
                );

				$noop = APAI_Brain_NoOp::filter_product_changes( $product_id, $action['changes'] );
				if ( ! empty( $noop['noop'] ) ) {
					APAI_Brain_Trace::emit_current( 'noop', array(
						'kind'       => 'stock',
						'product_id'  => $product_id,
						'dropped'     => $noop['dropped'],
						'current'     => $noop['current'],
						'desired_qty' => (int) $qty_i,
					) );
					APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'stock', 'last_target_product_id' => $product_id ) );
					return APAI_Brain_Response_Builder::make_response( 'message', 'El producto ya tenía ese stock. No hice cambios.', array() );
				}
				$action['changes'] = $noop['changes'];
                APAI_Brain_Memory_Store::persist_pending_action( $action );
                APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'stock' ) );
                return APAI_Brain_Response_Builder::action_prepared( $action, (new APAI_Brain_NLG())->msg_action_prepared_default() );
            }
        }

        // -------------------------------------------------------------
        // Implicit: "el último a 100" / "poné el 2º a 50" (without saying price/stock)
        // We infer using last_action_kind when available; otherwise ask.
        // -------------------------------------------------------------
        if ( ( preg_match( APAI_Patterns::LAST_WORD_STRICT, $m_norm ) || self::extract_ordinal_index( $m_norm ) > 0 )
            && ! preg_match( APAI_Patterns::PRICE_WORD_STRICT, $m_norm )
            && ! preg_match( APAI_Patterns::STOCK_WORD_STRICT, $m_norm ) ) {

            $raw_num = self::extract_numeric_phrase( $message );
            if ( $raw_num !== null ) {
                $wants_last = (bool) preg_match( APAI_Patterns::LAST_WORD_STRICT, $m_norm );
                $ordinal_n  = self::extract_ordinal_index( $m_norm );

                // Decide kind.
                $kind = null;
                if ( is_array( $store_state ) && ! empty( $store_state['last_action_kind'] ) ) {
                    $k = strtolower( (string) $store_state['last_action_kind'] );
                    if ( in_array( $k, array( 'price', 'stock' ), true ) ) {
                        $kind = $k;
                    }
                }

                // If we can't infer, store it and ask.
                if ( $kind === null ) {
                    $parsed_any = APAI_Brain_Normalizer::parse_price_number( $raw_num );
                    $value_num  = is_numeric( $parsed_any ) ? (float) $parsed_any : null;
                    $value_i    = ( $value_num !== null ) ? (int) round( (float) $value_num ) : null;
                    $value_str  = ( $value_num !== null && class_exists( 'APAI_Brain_Normalizer' ) ) ? APAI_Brain_Normalizer::format_price_for_wc( $value_num ) : null;
                    if ( $value_i !== null ) {
                        // Resolve the intended product id now, so the follow-up can be deterministic.
                        $product_id_sel = null;
                        if ( $wants_last ) {
                            $product_id_sel = self::resolve_product_id_by_ordinal( 1, true );
                        } elseif ( $ordinal_n > 0 ) {
                            $product_id_sel = self::resolve_product_id_by_ordinal( $ordinal_n, false );
                        }

                        $pending_last_update = array(
                            'value'      => $value_i,
                            'value_num'  => $value_num,
                            'value_str'  => ( $value_str !== null ? (string) $value_str : (string) $value_i ),
                            'wants_last' => $wants_last,
                            'ordinal_n'  => (int) $ordinal_n,
                            'created_at' => time(),
                        );
                        if ( is_int( $product_id_sel ) && $product_id_sel > 0 ) {
                            $pending_last_update['product_id'] = $product_id_sel;
                        }

                        APAI_Brain_Memory_Store::patch( array(
                            'pending_last_update' => $pending_last_update,
                        ) );
                    }

                    return APAI_Brain_Response_Builder::make_response(
                        'message',
                        '¿Querés cambiar el **precio** o el **stock**? Respondé "precio" o "stock".',
                        array()
                    );
                }

                // Resolve target product id.
                $product_id = 0;
                if ( $wants_last ) {
                    $product_id = self::resolve_product_id_by_ordinal( 1, true );
                } else {
                    $product_id = self::resolve_product_id_by_ordinal( $ordinal_n, false );
                }

                if ( $product_id <= 0 ) {
                    return null;
                }

                $product_title = '';
                if ( function_exists( 'get_the_title' ) ) {
                    $product_title = (string) get_the_title( $product_id );
                    $product_title = trim( wp_strip_all_tags( $product_title ) );
                }
	                                if ( ! empty( $product_title ) ) {
					$product_title = self::truncate_title( $product_title, 80 );
				}
$target_label = $wants_last ? 'último producto' : self::ordinal_label( $ordinal_n );

                if ( $kind === 'price' ) {
$parsed  = APAI_Brain_Normalizer::parse_price_number( $raw_num );
                    $price_n = is_numeric( $parsed ) ? floatval( $parsed ) : null;
                    if ( $price_n === null || $price_n <= 0 ) {
                        $msg = ( $price_n !== null && $price_n < 0 ) ? 'El precio no puede ser negativo.' : 'El precio debe ser mayor a 0.';
                        return APAI_Brain_Response_Builder::make_response( 'message', $msg, array() );
                    }
                    $price_str = self::format_price_string( $price_n );
$action = array(
                        'type'          => 'update_product',
                        'human_summary' => 'Cambiar el precio del ' . $target_label . ' a ' . $price_str . ( $product_title ? ( ' (' . $product_title . ')' ) : '' ) . '.',
                        'product_id'    => $product_id,
                        'changes'       => array( 'regular_price' => $price_str ),
                    );

				$noop = APAI_Brain_NoOp::filter_product_changes( $product_id, $action['changes'] );
				if ( ! empty( $noop['noop'] ) ) {
					APAI_Brain_Trace::emit_current( 'noop', array(
						'kind'       => 'price',
						'product_id'  => $product_id,
						'dropped'     => $noop['dropped'],
						'current'     => isset( $noop['current']['regular_price'] ) ? $noop['current']['regular_price'] : null,
					) );
					$cur = isset( $noop['current']['regular_price'] ) ? $noop['current']['regular_price'] : $price_str;
					return APAI_Brain_Response_Builder::make_response( 'message', 'Ya estaba: el ' . $target_label . ' ya tiene precio ' . $cur . '. No hice cambios.', array() );
				}
				$action['changes'] = $noop['changes'];

                    $confirmation = array(
                        'required'      => true,
                        'prompt'        => '',
                        'ok'            => 'Confirmar y ejecutar acción',
                        'cancel'        => 'Cancelar',
                        'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
                    );

                    $msg = 'Dale, preparé el cambio de precio del ' . $target_label . ' a ' . $price_str . '.';

				APAI_Brain_Memory_Store::persist_pending_action( $action );
                    APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'price' ) );

                    return APAI_Brain_Response_Builder::action_prepared( $msg, $action, $confirmation );
                }

                // stock
                $qty_f = APAI_Brain_Normalizer::parse_number( $raw_num );
                $qty_i = is_numeric( $qty_f ) ? (int) round( (float) $qty_f ) : null;
                if ( $qty_i === null || $qty_i < 0 ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', 'El stock no puede ser negativo.', array() );
                }

                $action = array(
                    'type'          => 'update_product',
                    'human_summary' => 'Actualizar el stock del ' . $target_label . ' a ' . $qty_i . ( $product_title ? ( " (" . $product_title . ")" ) : '' ) . '.',
                    'product_id'    => $product_id,
                    'changes'       => array(
                        'manage_stock'   => true,
                        'stock_quantity' => $qty_i,
                    ),
                );

				$noop = APAI_Brain_NoOp::filter_product_changes( $product_id, $action['changes'] );
				if ( ! empty( $noop['noop'] ) ) {
					APAI_Brain_Trace::emit_current( 'noop', array(
						'kind'       => 'stock',
						'product_id'  => $product_id,
						'dropped'     => $noop['dropped'],
						'current'     => $noop['current'],
						'requested'   => array(
							'manage_stock'   => true,
							'stock_quantity' => $qty_i,
						),
					) );

					APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'stock' ) );

					return APAI_Brain_Response_Builder::make_response(
						'message',
						'El stock del ' . $target_label . ' ya está en ' . $qty_i . '. No hice cambios.',
						array()
					);
				}
				$action['changes'] = $noop['changes'];

                $confirmation = array(
                    'required'      => true,
                    'prompt'        => '',
                    'ok'            => 'Confirmar y ejecutar acción',
                    'cancel'        => 'Cancelar',
                    'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
                );

                $msg = 'Dale, preparé el cambio de stock del ' . $target_label . ' a ' . $qty_i . '.';

                APAI_Brain_Memory_Store::persist_pending_action( $action );
                APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'stock' ) );

                return APAI_Brain_Response_Builder::action_prepared( $msg, $action, $confirmation );
            }
        }
        // -------------------------------------------------------------
        // Deterministic: stock update ("... stock ... a 3")
        // -------------------------------------------------------------
        if ( preg_match( APAI_Patterns::STOCK_WORD_STRICT, $m_norm )
            && preg_match( APAI_Patterns::ACTION_VERB_WIDE_STRICT, $m_norm ) ) {

            $wants_last  = (bool) preg_match( APAI_Patterns::LAST_WORD_STRICT, $m_norm );
            $ordinal_n   = self::extract_ordinal_index( $m_norm );
            if ( $wants_last || $ordinal_n > 0 ) {
                $raw_num = self::extract_numeric_phrase( $message );
                if ( ! is_string( $raw_num ) || trim( $raw_num ) === '' ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', '¿Qué stock querés poner? Ej: "stock del último a 5".', array() );
                }

                $parsed = class_exists( 'APAI_Brain_Normalizer' )
                    ? APAI_Brain_Normalizer::parse_number( $raw_num )
                    : null;
                if ( $parsed === null ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', 'No pude entender el número de stock. Probá con un número, por ejemplo: 5.', array() );
                }

                $qty_i = (int) round( (float) $parsed );
                if ( $qty_i < 0 ) {
                    return APAI_Brain_Response_Builder::make_response( 'message', 'El stock no puede ser negativo.', array() );
                }

                {
                            // Resolve target product id.
                            $product_id = 0;
                            if ( $wants_last ) {
                                // "último producto" = newest by creation date (not last touched).
                                $product_id = self::resolve_product_id_by_ordinal( 1, true );
                } else {
                                // "primer/segundo/tercero ..." = nth oldest.
                                $product_id = self::resolve_product_id_by_ordinal( $ordinal_n, false );
                            }
                            if ( $product_id > 0 ) {
                                $product_title = '';
                                if ( function_exists( 'get_the_title' ) ) {
                                    $product_title = (string) get_the_title( $product_id );
                                    $product_title = trim( wp_strip_all_tags( $product_title ) );
                                }
                                if ( ! empty( $product_title ) ) {
                                    $product_title = self::truncate_title( $product_title, 80 );
                                }
                                $target_label = $wants_last ? 'último producto' : self::ordinal_label( $ordinal_n );

                                $action = array(
                                    'type'          => 'update_product',
                                    'human_summary' => 'Actualizar el stock del ' . $target_label . ' a ' . $qty_i . ( $product_title ? ( " (" . $product_title . ")" ) : '' ) . '.',
                                    'product_id'    => $product_id,
                                    'changes'       => array(
                                        'manage_stock'   => true,
                                        'stock_quantity' => $qty_i,
                                    ),
                                );

                                $noop = APAI_Brain_NoOp::filter_product_changes( $product_id, $action['changes'] );
                                if ( ! empty( $noop['noop'] ) ) {
                                    APAI_Brain_Trace::emit_current( 'noop', array(
                                        'kind'       => 'stock',
                                        'product_id' => $product_id,
                                        'dropped'    => $noop['dropped'],
                                    ) );
                                    return APAI_Brain_Response_Builder::make_response(
                                        'message',
                                        'Ese producto ya tenía stock ' . (int) $qty_i . ' (sin cambios).',
                                        array(),
                                        null,
                                        null,
                                        array( 'noop' => true, 'kind' => 'stock' )
                                    );
                                }

                                $action['changes'] = $noop['changes'];

                                $confirmation = array(
                                    'required'      => true,
                                    'prompt'        => '',
                                    'ok'            => 'Confirmar y ejecutar acción',
                                    'cancel'        => 'Cancelar',
                                    'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
                                );

                                $msg = 'Dale, preparé el cambio de stock del ' . $target_label . ' a ' . $qty_i . '.';

                                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                                    APAI_Brain_Memory_Store::persist_pending_action( $action );
			APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'stock' ) );
								}

                                return APAI_Brain_Response_Builder::make_response(
                                    'execute',
                                    $msg,
                                    array( $action ),
                                    $confirmation,
                                    null,
                                    array( 'deterministic_stock' => true, 'source' => ( $wants_last ? 'deterministic_stock_last_flow' : 'deterministic_stock_ordinal_flow' ) )
                                );
                            }
                }
            }
        }

        // Must talk about price + change intent
        // Accept both strict and broader “price words” to match natural Spanish.
        if ( ! preg_match( APAI_Patterns::PRICE_WORD_STRICT, $m_norm ) && ! preg_match( APAI_Patterns::PRICE_WORD, $m_norm ) ) {
            return null;
        }
        if ( ! preg_match( APAI_Patterns::ACTION_VERB_WIDE_STRICT, $m_norm ) ) {
            return null;
        }

        // Conservative: only handle explicit ordinal targets.
        $wants_last = (bool) preg_match( APAI_Patterns::LAST_WORD_STRICT, $m_norm );
        $ordinal_n  = self::extract_ordinal_index( $m_norm );
        if ( ! $wants_last && $ordinal_n <= 0 ) {
            return null;
        }

        // Extract numeric value (supports "$1.000", "1,000", "10k", "10 lucas", etc.).
        $raw_num = self::extract_numeric_phrase( $message );
        if ( $raw_num === null ) {
            return APAI_Brain_Response_Builder::make_response(
                'message',
                '¿Qué precio querés poner? (Ej: "precio del último a 2500")',
                array(),
                null,
                null,
                array( 'deterministic_price' => false, 'need_value' => true )
            );
        }

        $maybe_price = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_price_number( $raw_num ) : null;
        if ( ! is_numeric( $maybe_price ) ) {
            return null;
        }
        // For responses that include debug/store_state (avoid undefined vars).
        $store_state = array();
        if ( class_exists( "APAI_Brain_Memory_Store" ) && method_exists( "APAI_Brain_Memory_Store", "get_state" ) ) {
            $store_state = APAI_Brain_Memory_Store::get_state();
        }
        $context = null;

                $price_n = floatval( $maybe_price );
        if ( $price_n < 0 ) {
            return APAI_Brain_Response_Builder::make_response(
                'message',
                'El precio no puede ser negativo.',
                array(),
                null,
                $store_state,
                $context,
                'DeterministicFlow'
            );
        }
        if ( $price_n <= 0 ) {
            return APAI_Brain_Response_Builder::make_response(
                'message',
                'El precio debe ser mayor a 0.',
                array(),
                null,
                $store_state,
                $context,
                'DeterministicFlow'
            );
        }
        $price_str = self::format_price_string( $price_n );


        // Resolve target product id.
        // IMPORTANT: Ordinals are absolute catalog positions by creation date (not last referenced).
        $product_id = 0;
        if ( $wants_last ) {
            $product_id = self::resolve_product_id_by_ordinal( 1, true );
        } else {
            $product_id = self::resolve_product_id_by_ordinal( $ordinal_n, false );
        }
        if ( $product_id <= 0 ) {
            return null;
        }

        // Optional: title for better UX (safe).
        $product_title = '';
        if ( function_exists( 'get_the_title' ) ) {
            $product_title = (string) get_the_title( $product_id );
            $product_title = trim( wp_strip_all_tags( $product_title ) );
        }

        if ( ! empty( $product_title ) ) {
            $product_title = self::truncate_title( $product_title, 80 );
        }

        $target_label = $wants_last ? 'último producto' : self::ordinal_label( $ordinal_n );

        $action = array(
            'type'          => 'update_product',
            'human_summary' => 'Cambiar precio del ' . $target_label . ' a ' . $price_str . ( $product_title ? ( " (" . $product_title . ")" ) : '' ) . '.',
            'product_id'    => $product_id,
            'changes'       => array(
                'regular_price' => $price_str,
            ),
        );

        $noop = APAI_Brain_NoOp::filter_product_changes( $product_id, $action['changes'] );
        if ( ! empty( $noop['noop'] ) ) {
            APAI_Brain_Trace::emit_current( 'noop', array(
                'kind'       => 'price',
                'product_id'  => $product_id,
                'dropped'     => $noop['dropped'],
                'current'     => $noop['current'],
            ) );
            $msg = 'Ese ' . $target_label . ' ya tiene ese precio. No hice cambios.';
            if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'price' ) );
            }
			// No-op real: do not create/update pending_action.
			// This response does not need to include any context payload.
			return APAI_Brain_Response_Builder::make_response(
				'message',
				$msg,
				array(),
				null,
				$store_state,
				null,
				'DeterministicFlow'
			);
        }

        $confirmation = array(
            'required'      => true,
            'prompt'        => '',
            'ok'            => 'Confirmar y ejecutar acción',
            'cancel'        => 'Cancelar',
            'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
        );

        // Proposal-style copy (never implies execution) — UX human (B2).
        // IMPORTANT: Never imply auto-execution (no "voy a", "cambiaré", etc.).
        $msg = 'Dale, preparé el cambio de precio del ' . $target_label . ' a ' . $price_str . '.';

        // Create a server-side pending_action (Step 2/2.5).
        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            APAI_Brain_Memory_Store::persist_pending_action( $action );
            APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => 'price' ) );
        }

        return APAI_Brain_Response_Builder::make_response(
            'execute',
            $msg,
            array( $action ),
            $confirmation,
            null,
            array( 'deterministic_price' => true, 'source' => ( $wants_last ? 'deterministic_price_last_flow' : 'deterministic_price_ordinal_flow' ) )
        );
    }

    /**
     * Formats a price into a WooCommerce-safe string (respects wc_get_price_decimals()).
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

}
