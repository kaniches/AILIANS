<?php
/**
 * TargetedUpdateFlow — update price/stock for an explicit product (ID / SKU / name)
 *
 * @FLOW TargetedUpdate
 * Purpose:
 * - Enable safe updates beyond "primer/último producto" WITHOUT using the model.
 * - Resolve a specific published product deterministically (ID/SKU/name).
 * - If ambiguous, open a target-selection step (no pending_action) and accept ID/SKU.
 *
 * @INVARIANTS
 * - This flow MUST NEVER execute actions.
 * - This flow MUST NEVER create pending_action unless there is exactly ONE resolved product.
 * - This flow MUST NEVER show action buttons unless pending_action is created server-side.
 * - This flow MUST be conservative: if unsure/ambiguous, ask for clarification.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Targeted_Update_Flow {

    /**
     * Try handle a targeted update.
     *
     * Examples:
     * - "cambiá el precio del producto Remera Roja a 9999"
     * - "poné el precio del SKU REM-001 a 5000"
     * - "cambia el stock del #123 a 3"
     */
    public static function try_handle( $message, $m_norm, $store_state = null ) {
        $m_norm = is_string( $m_norm ) ? $m_norm : (string) $message;
        $norm_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $m_norm, 'UTF-8' ) : strtolower( $m_norm );

        // ------------------------------------------------------
// Follow-up mode: we previously asked the user for ID/SKU OR offered a target selector (2–5)
// ------------------------------------------------------
// WHY: If the user replies with "ID 23" (or selects a candidate) we should continue without
// requiring them to repeat the whole "cambiá el precio ..." sentence.
$pending_follow = ( is_array( $store_state ) && isset( $store_state['pending_targeted_update'] ) && is_array( $store_state['pending_targeted_update'] ) )
    ? $store_state['pending_targeted_update']
    : null;
$pending_sel = ( is_array( $store_state ) && isset( $store_state['pending_target_selection'] ) && is_array( $store_state['pending_target_selection'] ) )
    ? $store_state['pending_target_selection']
    : null;

if ( $pending_follow || $pending_sel ) {
    // Allow cancelling this sub-flow.
    $lc_raw = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $message, 'UTF-8' ) : strtolower( (string) $message );
    if ( preg_match( '/\b(cancelar|cancelá|cancela|dejalo|dejála|dejarlo|dejarla)\b/iu', $lc_raw ) ) {
        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            APAI_Brain_Memory_Store::patch( array(
                'pending_targeted_update'   => null,
                'pending_target_selection' => null,
            ) );
        }
        $msg = 'Listo, lo dejé de lado. Si querés, decime qué cambio querés hacer y sobre qué producto.';
        return APAI_Brain_Response_Builder::make_response( 'consult', $msg, array(), null, null, array( 'targeted_update' => true, 'status' => 'followup_cancelled' ) );
    }

    // Prefer the selector state if present (it includes the candidate list).
    $pt = is_array( $pending_sel ) ? $pending_sel : $pending_follow;

    // 1) Strong explicit hint (ID/SKU/quoted name)
    $hint = self::extract_target_hint_followup( (string) $message );
    if ( ! is_array( $hint ) && is_array( $pending_sel ) ) {
        // 2) Selector answers: "1", "el segundo", or exact title
        $picked = self::pick_candidate_from_selection_followup( (string) $message, $pending_sel );
        if ( is_array( $picked ) && isset( $picked['id'] ) ) {
            $hint = array( 'kind' => 'id', 'value' => (string) intval( $picked['id'] ) );
        }
    }

    if ( is_array( $hint ) ) {
        $matches = self::resolve_product_candidates( $hint );
        $matches_total = isset( $matches['total'] ) ? (int) $matches['total'] : 0;
        $matches_items = ( isset( $matches['items'] ) && is_array( $matches['items'] ) ) ? $matches['items'] : array();
        if ( $matches_total === 1 && isset( $matches_items[0] ) && is_array( $matches_items[0] ) ) {
            $product_id = intval( $matches_items[0]['id'] );
            $title = isset( $matches_items[0]['title'] ) ? $matches_items[0]['title'] : ( 'Producto #' . $product_id );

            $field   = isset( $pt['field'] ) ? (string) $pt['field'] : '';

            // Resolve value for follow-up mode.
            $display_value = '';
            $value_num = null;
            $value_str = null;
            $value_i   = null;

            if ( $field === 'price' ) {
                if ( isset( $pt['value_num'] ) && is_numeric( $pt['value_num'] ) ) {
                    $value_num = (float) $pt['value_num'];
                } elseif ( isset( $pt['value'] ) && is_numeric( $pt['value'] ) ) {
                    $value_num = (float) $pt['value'];
                } elseif ( isset( $pt['value_str'] ) && is_string( $pt['value_str'] ) ) {
                    $value_num = class_exists( 'APAI_Brain_Normalizer' ) ? (float) APAI_Brain_Normalizer::parse_price_number( $pt['value_str'] ) : null;
                }
                if ( $value_num !== null && $value_num > 0 ) {
                    $value_str = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::format_price_for_wc( $value_num ) : null;
                }
                if ( is_string( $value_str ) && $value_str !== '' ) {
                    $display_value = $value_str;
                }
            } elseif ( $field === 'stock' ) {
                if ( isset( $pt['value_i'] ) && is_numeric( $pt['value_i'] ) ) {
                    $value_i = intval( $pt['value_i'] );
                } elseif ( isset( $pt['value'] ) && is_numeric( $pt['value'] ) ) {
                    $value_i = intval( $pt['value'] );
                }
                if ( $value_i !== null && $value_i >= 0 ) {
                    $display_value = (string) $value_i;
                }
            }

            if ( $display_value !== '' && ( $field === 'price' || $field === 'stock' ) ) {
                $action_changes = array();
                $human_what = '';
                if ( $field === 'price' ) {
                    // WooCommerce expects price as a string aligned with wc_get_price_decimals().
                    $action_changes = array( 'regular_price' => $display_value );
                    $human_what = 'Cambiar precio';
                } else {
                    $action_changes = array( 'manage_stock' => true, 'stock_quantity' => intval( $display_value ) );
                    $human_what = 'Actualizar el stock';
                }

                $action = array(
                    'type'          => 'update_product',
                    'human_summary' => $human_what . ' de #' . $product_id . ' (' . $title . ') a ' . $display_value . '.',
                    'product_id'    => $product_id,
                    'changes'       => $action_changes,
                );

                $confirmation = array(
                    'required'      => true,
                    'prompt'        => '',
                    'ok'            => 'Confirmar y ejecutar acción',
                    'cancel'        => 'Cancelar',
                    'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
                );

                if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            APAI_Brain_Memory_Store::persist_pending_action( $action );
            APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => ( $field === 'price' ? 'price' : 'stock' ) ) );
                    APAI_Brain_Memory_Store::patch( array(
                        'pending_targeted_update'   => null,
                        'pending_target_selection' => null,
                    ) );
                }

                $msg = ( $field === 'price' )
                    ? 'Dale, preparé el cambio de precio de **' . $title . '** a ' . $display_value . '.'
                    : 'Dale, preparé el cambio de stock de **' . $title . '** a ' . $display_value . '.';

                return APAI_Brain_Response_Builder::make_response(
                    'execute',
                    $msg,
                    array( $action ),
                    $confirmation,
                    null,
                    array( 'targeted_update' => true, 'status' => 'pending_created', 'resolved_by' => $hint['kind'], 'followup' => true )
                );
            }
        }
    }
    // If we are in follow-up mode but didn't understand the target yet, fall through
    // to normal parsing below (the user may repeat the full sentence).
}
        // If there is a pending product selection and the user is NOT sending a new action request,
        // keep the selection flow in control (do not fall back to ModelFlow).
        if ( is_array( $pending_sel ) && ! preg_match( APAI_Patterns::ACTION_VERB_STRICT, $norm_lc ) ) {
            return APAI_Brain_Response_Builder::make_response(
                'chat',
                'Tengo una selección de producto pendiente. Elegí una opción de la lista (o decime el ID/SKU), o escribí "cancelar" para dejarla de lado.',
                array()
            );
        }

        // Only handle explicit update intents.
        if ( ! preg_match( APAI_Patterns::ACTION_VERB_STRICT, $norm_lc ) ) {
            return null;
        }

        $is_price = (bool) preg_match( APAI_Patterns::PRICE_WORD_STRICT, $norm_lc );
        $is_stock = (bool) preg_match( APAI_Patterns::STOCK_WORD_STRICT, $norm_lc );
        if ( ! $is_price && ! $is_stock ) {
            return null;
        }

        // Avoid clashing with DeterministicFlow: it already handles first/last.
        if ( preg_match( APAI_Patterns::LAST_WORD_STRICT, $norm_lc ) || preg_match( APAI_Patterns::FIRST_WORD_STRICT, $norm_lc ) ) {
            return null;
        }

        // Parse number (price or stock quantity)
        // Prefer an explicit "a <numero>" value when present.
        $raw_num = null;

        $explicit = null;
        if ( preg_match_all( '/\ba\s*([0-9][0-9.,]*)/u', $norm_lc, $mval ) && ! empty( $mval[1] ) ) {
            $explicit = end( $mval[1] );
        }
        if ( is_string( $explicit ) && trim( $explicit ) !== '' ) {
            $raw_num = $explicit;
        } else {
            // If message ends in "... producto 386 a" with no value, don't guess.
            if ( preg_match( '/\bproducto\s*#?\s*(\d+)\s+a\s*$/u', $norm_lc, $mprod ) ) {
                $pid = intval( $mprod[1] );

                // Context hint: si el usuario dejó la instrucción incompleta (falta el número),
                // guardamos un followup muy simple para que el próximo mensaje numérico
                // se pueda interpretar sin volver a preguntar "precio o stock".
                // (Conservador: solo se usa si el usuario efectivamente responde con un número.)
				if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
					APAI_Brain_Memory_Store::patch(
						array(
							'pending_followup_action' => array(
								'expect'     => 'number',
								'product_id' => $pid,
								'field'      => $is_stock ? 'stock' : 'price',
								'created_at' => time(),
							),
							'pending_last_update'     => array(
								'product_id' => $pid,
								'field'      => $is_stock ? 'stock' : 'price',
								'value'      => null,
								'created_at' => time(),
							),
						)
					);
				}

                $q = $is_stock
                    ? ( 'Me falta el número de stock. ¿A qué cantidad querés dejar el stock del producto #' . $pid . '?' )
                    : ( 'Me falta el precio. ¿A qué precio querés dejar el producto #' . $pid . '?' );
                $ex = $is_stock
                    ? ( 'Ejemplo: "stock del producto ' . $pid . ' a 9"' )
                    : ( 'Ejemplo: "precio del producto ' . $pid . ' a 999"' );
                return APAI_Brain_Response_Builder::make_response(
                    'clarify',
                    $q . "\n" . $ex,
                    array(),
                    null,
                    null,
                    array(
                        'route' => 'TargetedUpdateFlow',
                    )
                );
            }

            if ( preg_match_all( APAI_Patterns::NUMBER_CAPTURE_STRICT, $message, $mm ) && ! empty( $mm[1] ) ) {
                $raw_num = end( $mm[1] );
            }
        }
        if ( ! is_string( $raw_num ) || trim( $raw_num ) === '' ) {
            return null;
        }

        $value_num = null;
        $value_str = null;
        $display_value = '';
        $value_i = 0;

        if ( $is_price ) {
            $value_num = class_exists( 'APAI_Brain_Normalizer' ) ? (float) APAI_Brain_Normalizer::parse_price_number( $raw_num ) : null;
            if ( $value_num === null || $value_num <= 0 ) {
                return null;
            }
            $value_str = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::format_price_for_wc( $value_num ) : null;
            if ( ! is_string( $value_str ) || $value_str === '' ) {
                return null;
            }
            $display_value = $value_str;
            // Keep an integer mirror for backward-compat (messages/examples), but do NOT use it for saving price.
            $value_i = (int) round( $value_num );
        } else {
            $parsed_n = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::parse_number( $raw_num ) : null;
            if ( $parsed_n === null ) {
                return null;
            }
            $value_i = (int) round( (float) $parsed_n );
            if ( $value_i < 0 ) {
                return null;
            }
            $value_num = (float) $value_i;
            $value_str = (string) $value_i;
            $display_value = $value_str;
        }

        // Extract explicit target hints (ID / SKU / name)
        $target = self::extract_target_hint( $message, $norm_lc, $raw_num );
        $used_context_last_target = false;
        if ( empty( $target ) || ! is_array( $target ) ) {
            // Context hint (micro-memoria segura): si NO se indicó producto pero
            // veníamos trabajando sobre uno, usamos ese como sugerencia.
            // Esto NO ejecuta nada por sí solo: igual queda como acción propuesta con botones.
            if ( isset( $store_state['last_target_product_id'] ) ) {
                $last_pid = intval( $store_state['last_target_product_id'] );
                if ( $last_pid > 0 ) {
                    $target = array(
                        'kind'         => 'id',
                        'value'        => (string) $last_pid,
                        'from_context' => true,
                    );
                    $used_context_last_target = true;
                }
            }

            if ( empty( $target ) || ! is_array( $target ) ) {
                return null;
            }
        }

        $matches = self::resolve_product_candidates( $target );
        $matches_total = isset( $matches['total'] ) ? (int) $matches['total'] : 0;
        $matches_items = ( isset( $matches['items'] ) && is_array( $matches['items'] ) ) ? $matches['items'] : array();

        if ( $matches_total === 0 ) {
            $msg = 'Dale. Para hacerlo bien, decime el **ID** del producto (por ejemplo #123) o el **SKU** (por ejemplo "SKU REM-001"). Si querés, también podés copiarme el nombre exacto.';

            // Persist follow-up context (no pending_action) so the user can reply with just "ID 123".
            if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                APAI_Brain_Memory_Store::patch( array( 'pending_targeted_update' => array(
                    'field'     => ( $is_price ? 'price' : 'stock' ),
                    'value'     => $display_value,
                    'value_i'   => $value_i,
                    'value_num' => $value_num,
                    'value_str' => $value_str,
                    'raw_num'   => (string) $raw_num,
                    'asked_at'  => time(),
                    'candidates'=> array(),
                ), 'pending_target_selection' => null ) );
            }
            return APAI_Brain_Response_Builder::make_response(
                'consult',
                $msg,
                array(),
                null,
                null,
                array( 'targeted_update' => true, 'status' => 'no_match' )
            );
        }
        if ( $matches_total > 1 ) {
            // UI-first selector: create a server-side selection state and let the admin UI browse
            // the full candidate set via paginated search (no hard max).
            $q_hint = isset( $target['value'] ) ? (string) $target['value'] : '';
            $q_hint = sanitize_text_field( $q_hint );

            $sel_candidates = array();
            foreach ( $matches_items as $m ) {
                if ( ! is_array( $m ) || ! isset( $m['id'] ) ) { continue; }
                $pid = (int) $m['id'];
                if ( $pid <= 0 ) { continue; }
                $ptitle = isset( $m['title'] ) ? (string) $m['title'] : ( 'Producto #' . $pid );
                $sel_candidates[] = array(
                    'id'    => $pid,
                    'title' => $ptitle,
                    'sku'   => isset( $m['sku'] ) ? (string) $m['sku'] : '',
                    'price' => isset( $m['price'] ) ? (string) $m['price'] : '',
                );
            }

            $lines = array();
            $lines[] = 'Encontré **' . intval( $matches_total ) . ' producto(s)** que podrían ser.';
            $lines[] = 'Marcá uno en la lista (selector) o decime el **ID** / **SKU**.';
            if ( $matches_total > count( $sel_candidates ) ) {
                $lines[] = 'En el selector podés tocar **"Cargar más"** para seguir trayendo resultados.';
            }
            $lines[] = '';

            // Fallback: show a small sample in the text in case the client UI doesn't render the selector.
            $shown = 0;
            foreach ( $sel_candidates as $c ) {
                if ( $shown >= 3 ) { break; }
                $lines[] = '• #' . intval( $c['id'] ) . ' — ' . $c['title'];
                $shown++;
            }
            $lines[] = '';
            $lines[] = 'Por ejemplo: "cambiá el precio del #123 a ' . $display_value . '".';
            $msg = implode( "\n", $lines );

            if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
                $candidate_ids = array();
                foreach ( $sel_candidates as $c ) {
                    $candidate_ids[] = (int) $c['id'];
                    if ( count( $candidate_ids ) >= 50 ) { break; }
                }

                APAI_Brain_Memory_Store::patch( array(
                    'pending_targeted_update' => array(
                        'field'      => ( $is_price ? 'price' : 'stock' ),
                        'value'      => $display_value,
                        'value_i'    => $value_i,
                        'value_num'  => $value_num,
                        'value_str'  => $value_str,
                        'raw_num'    => (string) $raw_num,
                        'asked_at'   => time(),
                        'query'      => $q_hint,
                        'candidates' => $candidate_ids,
                    ),
                    'pending_target_selection' => array(
                        'kind'       => 'targeted_update',
                        'field'      => ( $is_price ? 'price' : 'stock' ),
                        'value'      => $display_value,
                        'value_i'    => $value_i,
                        'value_num'  => $value_num,
                        'value_str'  => $value_str,
                        'raw_num'    => (string) $raw_num,
                        'asked_at'   => time(),
                        'query'      => $q_hint,
                        'total'      => (int) $matches_total,
                        'limit'      => 20,
                        'offset'     => 0,
                        'candidates' => $sel_candidates,
                    ),
                ) );
            }

            return APAI_Brain_Response_Builder::make_response(
                'consult',
                $msg,
                array(),
                null,
                null,
                array( 'targeted_update' => true, 'status' => 'need_target_selection', 'candidates' => $matches_total )
            );
        }

        // Exactly one product
        $product_id = isset( $matches_items[0]['id'] ) ? (int) $matches_items[0]['id'] : 0;
        $title = isset( $matches_items[0]['title'] ) ? (string) $matches_items[0]['title'] : ( 'Producto #' . $product_id );

        $action_changes = array();
        $human_what = '';
        if ( $is_price ) {
            $action_changes = array( 'regular_price' => $display_value );
            $human_what = 'Cambiar precio';
        } else {
            $action_changes = array( 'manage_stock' => true, 'stock_quantity' => intval( $display_value ) );
            $human_what = 'Actualizar el stock';
        }

        // No-op guard: if the change is already applied, do not create a pending action.
        if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
            $wc_product = wc_get_product( $product_id );
            if ( $wc_product ) {
                if ( $is_price ) {
                    $cur_price = (string) $wc_product->get_regular_price();
                    if ( $cur_price !== '' && (string) $display_value === (string) $cur_price ) {
                        return APAI_Brain_Response_Builder::make_response(
                            'consult',
                            'Listo ✅ No había cambios para aplicar (ya estaba así).',
                            array(),
                            null,
                            null,
                            array( 'targeted_update' => true, 'status' => 'noop_already_applied' )
                        );
                    }
                } else {
                    $cur_manage = (bool) $wc_product->managing_stock();
                    $cur_qty    = $wc_product->get_stock_quantity();
                    $want_qty   = intval( $display_value );

                    if ( $cur_manage === true && intval( $cur_qty ) === $want_qty ) {
                        return APAI_Brain_Response_Builder::make_response(
                            'consult',
                            'Listo ✅ No había cambios para aplicar (ya estaba así).',
                            array(),
                            null,
                            null,
                            array( 'targeted_update' => true, 'status' => 'noop_already_applied' )
                        );
                    }
                }
            }
        }

        $action = array(
            'type'          => 'update_product',
            'human_summary' => $human_what . ' de #' . $product_id . ' (' . $title . ') a ' . $display_value . '.',
            'product_id'    => $product_id,
            'changes'       => $action_changes,
        );

        $confirmation = array(
            'required'      => true,
            'prompt'        => '',
            'ok'            => 'Confirmar y ejecutar acción',
            'cancel'        => 'Cancelar',
            'cancel_tokens' => array( 'no', 'cancelar', 'cancelá', 'cancela' ),
        );

        $msg = $is_price
            ? 'Dale, preparé el cambio de precio de **' . $title . '** a ' . $display_value . '.'
            : 'Dale, preparé el cambio de stock de **' . $title . '** a ' . $display_value . '.';

        // Si elegimos el producto "por contexto" (último target), lo explicitamos.
		if ( $used_context_last_target ) {
			$msg .= "\n\n*(Tomo el producto #" . intval( $product['id'] ) . " porque fue el último con el que venías trabajando. Si era otro, decime ID/SKU/nombre.)*";
		}

        if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
            APAI_Brain_Memory_Store::persist_pending_action( $action );
            APAI_Brain_Memory_Store::patch( array( 'last_action_kind' => ( $is_price ? 'price' : 'stock' ) ) );
            APAI_Brain_Memory_Store::patch( array( 'pending_targeted_update' => null, 'pending_target_selection' => null ) );
        }

        return APAI_Brain_Response_Builder::make_response(
            'execute',
            $msg,
            array( $action ),
            $confirmation,
            null,
            array( 'targeted_update' => true, 'status' => 'pending_created', 'resolved_by' => $target['kind'] )
        );
    }

    /**
     * Extract a target hint from the message.
     * Returns array(kind => 'id'|'sku'|'name', value => string)
     */

/**
 * Extract a target hint from the message.
 * Returns array(kind => 'id'|'sku'|'name', value => string)
 */
private static function extract_target_hint_followup( $message_raw ) {
    $message_raw = (string) $message_raw;

    // 1) ID patterns
    if ( preg_match( '/(?:^|\s)#\s*(\d{1,10})(?:\s|$)/u', $message_raw, $m ) ) {
        return array( 'kind' => 'id', 'value' => $m[1] );
    }
    if ( preg_match( '/(?:^|\s)id\s*[:#]?\s*(\d{1,10})(?:\s|$)/iu', $message_raw, $m ) ) {
        return array( 'kind' => 'id', 'value' => $m[1] );
    }

    // 2) SKU patterns
    if ( preg_match( '/(?:^|\s)sku\s*[:#]?\s*([A-Za-z0-9_\-\.]{2,64})(?:\s|$)/iu', $message_raw, $m ) ) {
        return array( 'kind' => 'sku', 'value' => $m[1] );
    }

    // 3) Quoted name
    if ( preg_match( '/["“”\']([^"“”\']{3,120})["“”\']/u', $message_raw, $m ) ) {
        $v = trim( $m[1] );
        if ( $v !== '' ) {
            return array( 'kind' => 'name', 'value' => $v );
        }
    }

    return null;
}

/**
 * Selector follow-up: pick a candidate by index ("1", "el segundo") or exact title.
 * Returns [id,title] or null.
 */
private static function pick_candidate_from_selection_followup( $message_raw, $selection ) {
    if ( ! is_array( $selection ) || empty( $selection['candidates'] ) || ! is_array( $selection['candidates'] ) ) {
        return null;
    }
    $cands = $selection['candidates'];

    $raw = trim( (string) $message_raw );
    if ( $raw === '' ) { return null; }

    // Users sometimes prefix the title with "#" (thinking it's a hashtag).
    $raw = preg_replace( '/^\s*#\s*/u', '', $raw );
    $raw = is_string( $raw ) ? trim( $raw ) : trim( (string) $message_raw );

    $lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );
    $lc = trim( $lc );

    // Index patterns: "1", "2", "el segundo", "segundo", etc.
    $idx = null;
    if ( preg_match( '/^(?:el\s+)?(\d{1})$/u', $lc, $m ) ) {
        $idx = intval( $m[1] );
    } elseif ( preg_match( '/\b(primero|primer)\b/iu', $lc ) ) {
        $idx = 1;
    } elseif ( preg_match( '/\b(segundo)\b/iu', $lc ) ) {
        $idx = 2;
    } elseif ( preg_match( '/\b(tercero)\b/iu', $lc ) ) {
        $idx = 3;
    } elseif ( preg_match( '/\b(cuarto)\b/iu', $lc ) ) {
        $idx = 4;
    } elseif ( preg_match( '/\b(quinto)\b/iu', $lc ) ) {
        $idx = 5;
    }

    if ( $idx !== null ) {
        $pos = $idx - 1;
        if ( isset( $cands[ $pos ] ) && is_array( $cands[ $pos ] ) && isset( $cands[ $pos ]['id'] ) ) {
            return $cands[ $pos ];
        }
    }

    // Exact/partial title match (case-insensitive), allowing optional quotes.
    $needle = preg_replace( '/^[\"“”\']+|[\"“”\']+$/u', '', $raw );
    $needle = trim( $needle );
    if ( $needle !== '' ) {
        $needle_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $needle, 'UTF-8' ) : strtolower( $needle );

        // Normalize keys to tolerate truncated titles (e.g. "remera azul b (copia)")
        $norm_key = function( $s ) {
            $s = (string) $s;
            $s = trim( $s );
            $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
            $s = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $s );
            $s = preg_replace( '/\s+/u', ' ', $s );
            return trim( $s );
        };

        $needle_k = $norm_key( $needle_lc );
        if ( $needle_k !== '' ) {
            // 1) Exact normalized match.
            foreach ( $cands as $c ) {
                if ( ! is_array( $c ) ) { continue; }
                $t = isset( $c['title'] ) ? (string) $c['title'] : '';
                $t_k = $norm_key( $t );
                if ( $t_k !== '' && $t_k === $needle_k ) {
                    return $c;
                }
            }

            // 2) Unambiguous partial match (substring) — conservative.
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $needle_k, 'UTF-8' ) : strlen( $needle_k );
            if ( $len >= 6 ) {
                $matches = array();
                foreach ( $cands as $c ) {
                    if ( ! is_array( $c ) ) { continue; }
                    $t = isset( $c['title'] ) ? (string) $c['title'] : '';
                    $t_k = $norm_key( $t );
                    if ( $t_k === '' ) { continue; }
                    if ( false !== strpos( $t_k, $needle_k ) ) {
                        $matches[] = $c;
                    }
                }
                if ( count( $matches ) === 1 ) {
                    return $matches[0];
                }
            }
        }
    }

    return null;
}

    private static function extract_target_hint( $message_raw, $norm_lc, $raw_num ) {
        // 1) ID patterns: "#123", "id 123", "producto 123"
        if ( preg_match( '/(?:^|\s)#\s*(\d{1,10})(?:\s|$)/u', $message_raw, $m ) ) {
            return array( 'kind' => 'id', 'value' => $m[1] );
        }
        if ( preg_match( '/(?:^|\s)id\s*[:#]?\s*(\d{1,10})(?:\s|$)/iu', $message_raw, $m ) ) {
            return array( 'kind' => 'id', 'value' => $m[1] );
        }
        if ( preg_match( '/(?:^|\s)producto\s*[:#]?\s*(\d{1,10})(?:\s|$)/iu', $message_raw, $m ) ) {
            return array( 'kind' => 'id', 'value' => $m[1] );
        }

        // 2) SKU patterns
        if ( preg_match( '/(?:^|\s)sku\s*[:#]?\s*([A-Za-z0-9_\-\.]{2,64})(?:\s|$)/iu', $message_raw, $m ) ) {
            return array( 'kind' => 'sku', 'value' => $m[1] );
        }

        // 3) Quoted name ("...")
        if ( preg_match( '/["“”\']([^"“”\']{3,120})["“”\']/u', $message_raw, $m ) ) {
            $v = trim( $m[1] );
            if ( $v !== '' ) {
                return array( 'kind' => 'name', 'value' => $v );
            }
        }

        // 4) Heuristic: take substring between a target preposition and the value connector ("a/en")
        // We cut the raw string before the number token occurrence.
        $pos = strpos( $message_raw, (string) $raw_num );
        $prefix = ( $pos !== false ) ? substr( $message_raw, 0, $pos ) : $message_raw;
        $prefix = trim( $prefix );

        // Remove trailing connectors like " a " or " en " if present.
        $prefix = preg_replace( '/\s+(a|en)\s*$/iu', '', $prefix );

        // Try to find last "del/de la/de el/de" and take what follows.
        $cut = null;
        $candidates = array( ' del ', ' de la ', ' de el ', ' de ', ' para ', ' al ', ' a la ', ' a el ' );
        foreach ( $candidates as $needle ) {
            $p = function_exists( 'mb_strripos' ) ? mb_strripos( $prefix, $needle, 0, 'UTF-8' ) : strripos( $prefix, $needle );
            if ( $p !== false ) {
                $cut = $p + strlen( $needle );
                break;
            }
        }
        if ( $cut === null ) {
            return null;
        }

        $name = trim( substr( $prefix, $cut ) );
        if ( $name === '' ) {
            return null;
        }

        // Clean common lead words.
        $name = preg_replace( '/^(producto|el producto|la producto|el|la)\s+/iu', '', $name );
        $name = trim( $name );

        // Extra safety: remove trailing connectors that may remain after heuristics.
        $name = preg_replace( '/\s+(a|en|para)\s*$/iu', '', $name );
        $name = trim( $name );

        // Remove trailing connectors that may remain after the cut.
        $name = preg_replace( '/\s+(a|en|para)\s*$/iu', '', $name );
        $name = trim( $name );

        if ( strlen( $name ) < 3 ) {
            return null;
        }

        return array( 'kind' => 'name', 'value' => $name );
    }

    /**
     * Resolve candidates for a published product.
     * Returns a structure: { total:int, items:[{id,title,sku?,price?}, ...] }
     */
    private static function resolve_product_candidates( $target, $limit = 20, $offset = 0 ) {
        if ( ! is_array( $target ) || empty( $target['kind'] ) || empty( $target['value'] ) ) {
            return array( 'total' => 0, 'items' => array() );
        }

        $kind = (string) $target['kind'];
        $value = trim( (string) $target['value'] );
        if ( $value === '' ) {
            return array( 'total' => 0, 'items' => array() );
        }

        $limit = (int) $limit;
        $offset = (int) $offset;
        if ( $limit <= 0 ) { $limit = 20; }
        if ( $limit > 100 ) { $limit = 100; }
        if ( $offset < 0 ) { $offset = 0; }

        // ID
        if ( $kind === 'id' ) {
            $id = intval( $value );
            if ( $id > 0 && function_exists( 'get_post' ) ) {
                $p = get_post( $id );
                if ( $p && isset( $p->post_type ) && $p->post_type === 'product' && isset( $p->post_status ) && $p->post_status === 'publish' ) {
                    $t = function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';
                    $t = trim( wp_strip_all_tags( $t ) );
                    return array(
                        'total' => 1,
                        'items' => array( array( 'id' => $id, 'title' => ( $t !== '' ? $t : ( 'Producto #' . $id ) ) ) ),
                    );
                }
            }
            return array( 'total' => 0, 'items' => array() );
        }

        // SKU (exact match)
        if ( $kind === 'sku' ) {
            global $wpdb;
            $sku = sanitize_text_field( $value );
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND pm.meta_key = '_sku'
                   AND pm.meta_value = %s
                 ORDER BY p.ID DESC
                 LIMIT 1",
                $sku
            ) );
            if ( $id > 0 ) {
                $t = function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';
                $t = trim( wp_strip_all_tags( $t ) );
                return array(
                    'total' => 1,
                    'items' => array( array( 'id' => $id, 'title' => ( $t !== '' ? $t : ( 'Producto #' . $id ) ), 'sku' => $sku ) ),
                );
            }
            return array( 'total' => 0, 'items' => array() );
        }

        // NAME
        $q = sanitize_text_field( $value );
        if ( $q === '' ) {
            return array( 'total' => 0, 'items' => array() );
        }

        // Exact title match (case-insensitive). If more than one exact match exists, treat as ambiguous.
        global $wpdb;
        $rows_exact = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
               AND LOWER(post_title) = LOWER(%s)
             ORDER BY ID DESC
             LIMIT 2",
            $q
        ), ARRAY_A );

        if ( is_array( $rows_exact ) && count( $rows_exact ) === 1 ) {
            $id_exact = isset( $rows_exact[0]['ID'] ) ? (int) $rows_exact[0]['ID'] : 0;
            if ( $id_exact > 0 ) {
                $t = isset( $rows_exact[0]['post_title'] ) ? (string) $rows_exact[0]['post_title'] : '';
                $t = trim( wp_strip_all_tags( $t ) );
                return array(
                    'total' => 1,
                    'items' => array( array( 'id' => $id_exact, 'title' => ( $t !== '' ? $t : ( 'Producto #' . $id_exact ) ) ) ),
                );
            }
        }

        // Paginated LIKE search for large catalogs.
        if ( class_exists( 'APAI_Brain_Product_Search' ) ) {
            $data = APAI_Brain_Product_Search::search_by_title_like( $q, $limit, $offset );
            $total = isset( $data['total'] ) ? (int) $data['total'] : 0;
            $items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
            // Normalize keys we rely on (id/title)
            $norm = array();
            foreach ( $items as $it ) {
                if ( ! is_array( $it ) || ! isset( $it['id'] ) ) { continue; }
                $pid = (int) $it['id'];
                if ( $pid <= 0 ) { continue; }
                $ptitle = isset( $it['title'] ) ? (string) $it['title'] : ( 'Producto #' . $pid );
                $norm[] = array(
                    'id'    => $pid,
                    'title' => $ptitle,
                    'sku'   => isset( $it['sku'] ) ? (string) $it['sku'] : '',
                    'price' => isset( $it['price'] ) ? (string) $it['price'] : '',
                    // UI-only hints for the selector card (does not affect action logic)
                    'thumb_url'  => isset( $it['thumb_url'] ) ? (string) $it['thumb_url'] : '',
                    'categories' => ( isset( $it['categories'] ) && is_array( $it['categories'] ) ) ? $it['categories'] : array(),
                );
            }
            return array( 'total' => $total, 'items' => $norm );
        }

        // Fallback (shouldn't happen): conservative small LIKE search.
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
               AND post_title LIKE %s
             ORDER BY ID DESC
             LIMIT %d OFFSET %d",
            $like,
            $limit,
            $offset
        ), ARRAY_A );

        $results = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $id = isset( $r['ID'] ) ? (int) $r['ID'] : 0;
                if ( $id <= 0 ) { continue; }
                $t = isset( $r['post_title'] ) ? (string) $r['post_title'] : '';
                $t = trim( wp_strip_all_tags( $t ) );
                $results[] = array( 'id' => $id, 'title' => ( $t !== '' ? $t : ( 'Producto #' . $id ) ) );
            }
        }
        return array( 'total' => count( $results ), 'items' => $results );
    }
}
