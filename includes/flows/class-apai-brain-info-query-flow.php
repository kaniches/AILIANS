<?php
/**
 * @FLOW InfoQueryFlow
 *
 * Read-only informational questions that must NOT be hijacked by A2 follow-ups.
 *
 * This flow is intentionally conservative:
 * - Never creates pending_action
 * - Never mutates store_state
 * - Uses WooCommerce read-only getters only
 *
 * It runs BEFORE PendingFlow so that pending blocks actions, but not information.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Info_Query_Flow {

    /**
     * @return array|null Brain response array or null if not handled.
     */
    public static function try_handle( $message, $m_norm, $store_state ) {
        $raw = is_string( $message ) ? $message : '';
        if ( $raw === '' ) { return null; }

        $norm = class_exists( 'APAI_Brain_Normalizer' )
            ? APAI_Brain_Normalizer::normalize_intent_text( $raw )
            : strtolower( trim( $raw ) );
        $norm = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $norm ) ) );

        // 1) Detect informational intent (very conservative).
        $kind = self::detect_kind( $norm );
        if ( $kind === '' ) {
            return null;
        }

        // 2) Resolve product context.
        // Keep a copy of any explicit name mention so we can reply conservatively
        // when name matching fails (avoid wrong fallback to last_target).
		// Keep signature consistent: extractor needs both raw and normalized text.
		$name_candidate = self::extract_product_name_candidate( $raw, $norm );
		$pid = self::resolve_product_id( $raw, $norm, $store_state, $kind );

        // Special case: "¿qué cambiamos recién?" can be answered from pending_action even without pid.
        if ( $kind === 'last_change' ) {
            $txt = self::describe_last_change( $store_state );
            if ( $txt !== '' ) {
                return APAI_Brain_Response_Builder::make_response( 'chat', $txt );
            }
            return APAI_Brain_Response_Builder::make_response( 'chat', 'Ahora mismo no tengo un cambio reciente en preparación. Si me decís el **#ID** del producto, puedo mostrarte cómo está.' );
        }

        if ( $pid <= 0 ) {
            if ( $name_candidate !== '' ) {
                return APAI_Brain_Response_Builder::make_response( 'chat', 'No pude encontrar un producto llamado “' . esc_html( $name_candidate ) . '”. ¿Me pasás el **#ID** (por ejemplo: “del #150”) o el nombre exacto tal como está en WooCommerce?' );
            }
            return APAI_Brain_Response_Builder::make_response( 'chat', '¿De qué producto querés que te muestre eso? Pasame el **#ID** (por ejemplo: “del #150”).' );
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return APAI_Brain_Response_Builder::make_response( 'chat', 'No puedo consultar WooCommerce en este momento.' );
        }

        // Remember last info query so we can support quick corrections like: "Perdón, era el #149".
        $explicit_pid = null;
		if ( preg_match( '/\bproducto\s*#?\s*(\d+)\b/i', $norm, $mm ) ) {
            $explicit_pid = (int) $mm[1];
		} elseif ( preg_match( '/\b#\s*(\d+)\b/', $norm, $mm ) ) {
            $explicit_pid = (int) $mm[1];
        }

        APAI_Brain_Memory_Store::update_state( array(
            'last_info_query' => array(
                'kind'      => $kind,
                'product_id' => $pid,
                'explicit'  => ( null !== $explicit_pid ),
                'not_found' => false,
                'ts'        => time(),
            ),
        ) );

        $p = wc_get_product( $pid );
        if ( ! $p ) {
            // Mark last info query as not-found, so a correction can re-run it.
            APAI_Brain_Memory_Store::update_state( array(
                'last_info_query' => array(
                    'kind'      => $kind,
                    'product_id' => $pid,
                    'explicit'  => true,
                    'not_found' => true,
                    'ts'        => time(),
                ),
            ) );

            return APAI_Brain_Response_Builder::make_response( 'chat', 'No encontré el producto #' . intval( $pid ) . '.' );
        }

		// UX memory: if the user referenced an explicit product, remember it as the
		// last target so follow-ups like "¿Qué stock tiene?" work as expected.
		// This is a safe navigation state update (read-only with respect to catalog).
		if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
			APAI_Brain_Memory_Store::patch( array( 'last_target_product_id' => intval( $pid ) ) );
		}

        // 3) Build read-only answer.
        // Update follow-up context for future messages like: "Mostrame cómo está ahora" / "¿Qué stock tiene?"
        APAI_Brain_Memory_Store::update_state( array(
            'last_target_product_id' => $pid,
            'last_info_query'        => array(
                'kind'      => $kind,
                'product_id' => $pid,
                'explicit'  => ( null !== $explicit_pid ),
                'not_found' => false,
                'ts'        => time(),
            ),
        ) );

        switch ( $kind ) {
            case 'categories':
                return APAI_Brain_Response_Builder::make_response( 'chat', self::fmt_categories( $pid, $p ) );
            case 'price':
                return APAI_Brain_Response_Builder::make_response( 'chat', self::fmt_price( $pid, $p ) );
            case 'stock':
                return APAI_Brain_Response_Builder::make_response( 'chat', self::fmt_stock( $pid, $p ) );
            case 'title':
                return APAI_Brain_Response_Builder::make_response( 'chat', self::fmt_title( $pid, $p ) );
            case 'shortdesc':
                return APAI_Brain_Response_Builder::make_response( 'chat', self::fmt_shortdesc( $pid, $p ) );
            case 'snapshot':
                return APAI_Brain_Response_Builder::make_response( 'chat', self::fmt_snapshot( $pid, $p ) );
        }

        return null;
    }

    private static function detect_kind( $norm ) {
        // Allow leading filler words/punctuation in short followups (e.g. "ok, y el stock?")
        $norm = preg_replace( '/^\s*(?:ok|bueno|dale|listo|perfecto|genial|bien)\b\s*,?\s*/iu', '', $norm );

        // Users often ask with "quiero saber / necesito saber / quiero ver".
        // This is still an info query (not an action) as long as they are asking for data.
        $wants_info = ( strpos( $norm, 'quiero saber' ) !== false
            || strpos( $norm, 'necesito saber' ) !== false
            || strpos( $norm, 'me podes decir' ) !== false
            || strpos( $norm, 'me podés decir' ) !== false
            || strpos( $norm, 'quisiera saber' ) !== false
            || strpos( $norm, 'quiero ver' ) !== false );

        // Snapshot / "mostrame" / "cómo está".
        // Goal: when the user references a specific product (name / id / sku) we want a per-product snapshot,
        // not a global dataset query like "productos sin precio".
        // NOTE: $norm is already normalized (lowercase, no diacritics in our Normalizer).

        // Avoid stealing A1/A5-style dataset queries like "mostrame productos sin precio/imagen".
        $has_dataset_hint = ( preg_match( '/\bsin\s+(precio|imagen|categoria|categor|stock|sku)\b/iu', $norm )
            || preg_match( '/\bproductos?\b/iu', $norm ) );

		// "cómo está X" (even without "mostrame") => snapshot.
		// Be accent-tolerant because some normalizers keep diacritics.
		if ( preg_match( '/\bc[oó]mo\s+est[aá]\b/iu', $norm ) && ! $has_dataset_hint ) {
            return 'snapshot';
        }

        // "mostrame/ver/mirar X" => snapshot (even without "actual/ahora").
        if ( preg_match( '/\b(mostra(me)?|mostrame|mostrar|ver|mirar)\b/iu', $norm ) && ! $has_dataset_hint ) {
            return 'snapshot';
        }
        if ( strpos( $norm, 'que cambiamos recien' ) !== false || strpos( $norm, 'que hicimos recien' ) !== false ) {
            return 'last_change';
        }

        // Categories.
        // Aceptamos también follow-ups cortos tipo "y las categorías?" / "categorías de X".
        if ( strpos( $norm, 'categor' ) !== false ) {
            if ( $wants_info || strpos( $norm, 'que' ) !== false || strpos( $norm, 'cual' ) !== false || strpos( $norm, 'cuales' ) !== false ) {
                return 'categories';
            }
            // "las categorías" | "categorías" | "categorías de ..."
            if ( preg_match( '/^(y\s+)?(las?\s+)?categor/i', $norm ) ) {
                return 'categories';
            }
        }

        // Price.
        // Incluye follow-ups como "y el precio?" o "precio del #150".
        if ( strpos( $norm, 'precio' ) !== false ) {
            if ( $wants_info || strpos( $norm, 'que' ) !== false || strpos( $norm, 'cuanto' ) !== false || strpos( $norm, 'cuÃ¡nto' ) !== false || strpos( $norm, 'tiene' ) !== false ) {
                return 'price';
            }
            // "el precio" | "precio" | "precio de ..."
            if ( preg_match( '/^(y\s+)?(el\s+)?precio\b/i', $norm ) ) {
                return 'price';
            }
        }

        // Stock.
        // Incluye follow-ups como "y el stock?" o "stock del #150".
        if ( strpos( $norm, 'stock' ) !== false ) {
            if ( $wants_info || strpos( $norm, 'que' ) !== false || strpos( $norm, 'cuanto' ) !== false || strpos( $norm, 'cuÃ¡nto' ) !== false || strpos( $norm, 'tiene' ) !== false ) {
                return 'stock';
            }
            // "el stock" | "stock" | "stock de ..."
            if ( preg_match( '/^(y\s+)?(el\s+)?stock\b/i', $norm ) ) {
                return 'stock';
            }
        }

        // Title.
        if ( ( strpos( $norm, 'titulo' ) !== false || strpos( $norm, 'tÃ­tulo' ) !== false || strpos( $norm, 'nombre' ) !== false ) ) {
            if ( strpos( $norm, 'que' ) !== false || strpos( $norm, 'cual' ) !== false || strpos( $norm, 'cuÃ¡l' ) !== false || strpos( $norm, 'tiene' ) !== false ) {
                return 'title';
            }
            if ( preg_match( '/^(y\s+)?(el\s+)?(titulo|tÃ­tulo|nombre)\b/i', $norm ) ) {
                return 'title';
            }
        }

        // Short description.
        if ( ( strpos( $norm, 'descripcion corta' ) !== false || strpos( $norm, 'descripciÃ³n corta' ) !== false || strpos( $norm, 'desc corta' ) !== false ) ) {
            if ( strpos( $norm, 'que' ) !== false || strpos( $norm, 'cual' ) !== false || strpos( $norm, 'cuÃ¡l' ) !== false || strpos( $norm, 'tiene' ) !== false ) {
                return 'shortdesc';
            }
            if ( preg_match( '/^(y\s+)?(la\s+)?(descripcion\s+corta|descripciÃ³n\s+corta|desc\s+corta)\b/i', $norm ) ) {
                return 'shortdesc';
            }
        }

        return '';
    }

	private static function resolve_product_id( $raw, $norm, $store_state, $kind ) {
        // 1) Explicit #ID.
        if ( preg_match( '/#\s*(\d{1,10})/u', $raw, $m ) ) {
            return intval( $m[1] );
        }
        if ( preg_match( '/\bproducto\s*#?\s*(\d{1,10})\b/iu', $raw, $m2 ) ) {
            return intval( $m2[1] );
        }

		// 1b) Explicit by name (best-effort, conservative).
		// Examples:
		// - "stock de Remera Azul 2026"
		// - "precio del producto Remera Azul 2026"
		// - "\"Remera Azul 2026\" que precio tiene"
		$name = self::extract_product_name_candidate( $raw, $norm );
		if ( $name !== '' ) {
			$pid_by_name = self::lookup_product_id_by_name( $name );
			if ( $pid_by_name > 0 ) {
				return $pid_by_name;
			}
			// If the user explicitly named a product but we couldn't resolve it,
			// do NOT fall back to last_target_product_id (that creates wrong answers).
			return 0;
		}

        // 2) Follow-up context (A2).
        if ( is_array( $store_state ) && isset( $store_state['pending_targeted_update_a2'] ) && is_array( $store_state['pending_targeted_update_a2'] ) ) {
            $fu = $store_state['pending_targeted_update_a2'];
            if ( isset( $fu['product_id'] ) ) {
                $pid = intval( $fu['product_id'] );
                if ( $pid > 0 ) { return $pid; }
            }
        }

        // 3) Pending action (if it targets a product).
        if ( is_array( $store_state ) && ! empty( $store_state['pending_action'] ) ) {
            $pa = $store_state['pending_action'];
            // Envelope or direct.
            if ( is_array( $pa ) && isset( $pa['action'] ) && is_array( $pa['action'] ) ) {
                $pa = $pa['action'];
            }
            if ( is_array( $pa ) ) {
                foreach ( array( 'product_id', 'id' ) as $k ) {
                    if ( isset( $pa[ $k ] ) ) {
                        $pid = intval( $pa[ $k ] );
                        if ( $pid > 0 ) { return $pid; }
                    }
                }
                // Sometimes nested under args.
                if ( isset( $pa['args'] ) && is_array( $pa['args'] ) && isset( $pa['args']['product_id'] ) ) {
                    $pid = intval( $pa['args']['product_id'] );
                    if ( $pid > 0 ) { return $pid; }
                }
            }
        }

		// 4) last_info_query (follow-up de consultas: "¿y el stock?", "¿y el precio?", etc.)
		if ( is_array( $store_state ) && isset( $store_state['last_info_query'] ) && is_array( $store_state['last_info_query'] ) ) {
			$liq_kind = isset( $store_state['last_info_query']['kind'] ) ? (string) $store_state['last_info_query']['kind'] : '';
			$liq_nf   = isset( $store_state['last_info_query']['not_found'] ) ? (bool) $store_state['last_info_query']['not_found'] : false;
			$liq_pid  = isset( $store_state['last_info_query']['product_id'] ) ? intval( $store_state['last_info_query']['product_id'] ) : 0;
			// Allow cross-kind follow-ups when the user is clearly continuing the same thread.
			$followup = self::is_followup_question( $norm );
			if ( $liq_pid > 0 && ! $liq_nf && ( $liq_kind === (string) $kind || $followup ) ) {
				return $liq_pid;
			}
		}

		// 5) Last target (si parece un followup).
        $followup = self::is_followup_question( $norm );
        if ( $followup && is_array( $store_state ) && isset( $store_state['last_target_product_id'] ) ) {
            $pid = intval( $store_state['last_target_product_id'] );
            if ( $pid > 0 ) { return $pid; }
        }
        if ( is_array( $store_state ) && isset( $store_state['last_product_id'] ) ) {
            $pid = intval( $store_state['last_product_id'] );
            if ( $pid > 0 ) { return $pid; }
        }

        return 0;
    }

	private static function is_followup_question( $norm ) {
		$norm = (string) $norm;
		$norm = trim( $norm );
		if ( $norm === '' ) { return false; }
		// Typical Spanish follow-ups: "y el ...", "y las ...", "ok, y ...", "bueno y ...".
		if ( preg_match( '/^(y\b|ok\b|bueno\b|ah\b|dale\b)/iu', $norm ) ) { return true; }
		if ( strpos( $norm, 'y el ' ) === 0 || strpos( $norm, 'y la ' ) === 0 || strpos( $norm, 'y los ' ) === 0 || strpos( $norm, 'y las ' ) === 0 ) { return true; }
		return false;
	}

	private static function extract_product_name_candidate( $raw, $norm ) {
		$raw = (string) $raw;
		$norm = (string) $norm;

		// Snapshot phrasing: "Mostrame cómo está <nombre>" / "como esta <nombre>"
		// Important: if we miss this, the flow can incorrectly fall back to last_target_product_id.
		$m = array();
		if ( preg_match( '/\b(?:mostra(?:me|r)|mostrame)\b[^\n\r]*\b(?:como|c[oó]mo)\s+est[aá]\b\s*(.+)$/iu', $raw, $m ) ) {
			$maybe = trim( (string) $m[1] );
			$maybe = preg_replace( '/[\?\!\.,;:\"\'\)\]\}]+$/u', '', $maybe );
			$maybe = trim( $maybe );
			if ( $maybe !== '' ) {
				return $maybe;
			}
		}
		$m = array();
		if ( preg_match( '/\b(?:como|c[oó]mo)\s+est[aá]\b\s*(.+)$/iu', $raw, $m ) ) {
			$maybe = trim( (string) $m[1] );
			$maybe = preg_replace( '/[\?\!\.,;:\"\'\)\]\}]+$/u', '', $maybe );
			$maybe = trim( $maybe );
			if ( $maybe !== '' ) {
				return $maybe;
			}
		}

		// Quotes first.
		if ( preg_match( '/[\"“”\']([^\"“”\']{3,120})[\"“”\']/u', $raw, $m ) ) {
			$c = trim( $m[1] );
			if ( $c !== '' && ! preg_match( '/^\d+$/', $c ) ) {
				return $c;
			}
		}

		// "stock/precio/categorias ... de/del <nombre>"
		if ( preg_match( '/\b(?:stock|precio|categor(?:i|í)as?)\b\s*(?:del|de)\s+([^\?\!\.]*)/iu', $raw, $m2 ) ) {
			$c = trim( $m2[1] );
			$c = preg_replace( '/\b(producto)\b/iu', '', $c );
			$c = trim( $c );
			if ( $c !== '' && mb_strlen( $c ) >= 3 ) {
				return $c;
			}
		}

		// "producto <nombre>" (sin ID)
		if ( strpos( $norm, 'producto' ) !== false && ! preg_match( '/\d{1,10}/', $norm ) ) {
			$pos = stripos( $raw, 'producto' );
			if ( $pos !== false ) {
				$after = trim( mb_substr( $raw, $pos + mb_strlen( 'producto' ) ) );
				$after = preg_replace( '/[\?\!\.]\s*$/u', '', $after );
				$after = trim( $after );
				// remove common trailing query words.
				$after = preg_replace( '/\b(que|cual|cuanto|tiene|precio|stock|categor(?:i|í)as?)\b/iu', '', $after );
				$after = trim( $after );
				if ( $after !== '' && mb_strlen( $after ) >= 3 ) {
					return $after;
				}
			}
		}

		// "qué precio tiene <nombre>" / "qué stock tiene <nombre>" / "categorías de <nombre>" (sin "de" intermedio)
		if ( preg_match( '/\b(?:que|cuanto|cuÃ¡nto)\s+precio\s+tiene\s+([^#\d][^\?\!\.]*)$/iu', $raw, $m ) ) {
			$name = trim( (string) $m[1] );
			if ( $name !== '' && mb_strlen( $name ) >= 3 ) {
				return $name;
			}
		}
		if ( preg_match( '/\b(?:que|cuanto|cuÃ¡nto)\s+stock\s+tiene\s+([^#\d][^\?\!\.]*)$/iu', $raw, $m ) ) {
			$name = trim( (string) $m[1] );
			if ( $name !== '' && mb_strlen( $name ) >= 3 ) {
				return $name;
			}
		}
		if ( preg_match( '/\b(?:categor(?:i|í)as?)\s+tiene\s+([^#\d][^\?\!\.]*)$/iu', $raw, $m ) ) {
			$name = trim( (string) $m[1] );
			if ( $name !== '' && mb_strlen( $name ) >= 3 ) {
				return $name;
			}
		}

		// "qué precio tiene <nombre>" / "qué stock tiene <nombre>" (sin "de").
		if ( preg_match( '/\b(?:que|cuanto|cuÃ¡nto)\s+precio\s+tiene\s+([^#\d][^\?\!\.]*)$/iu', $raw, $m ) ) {
			$name = trim( (string) $m[1] );
			if ( $name !== '' && mb_strlen( $name ) >= 3 ) {
				return $name;
			}
		}
		if ( preg_match( '/\bstock\s+(?:de|del)?\s*([^#\d][^\?\!\.]*)$/iu', $raw, $m ) ) {
			$name = trim( (string) $m[1] );
			// Avoid matching "stock" alone.
			if ( $name !== '' && mb_strlen( $name ) >= 3 && strpos( $name, 'producto' ) === false ) {
				return $name;
			}
		}

		return '';
	}

	private static function lookup_product_id_by_name( $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) { return 0; }
		global $wpdb;
		// Conservative: accept only clear matches.
		$norm_name = strtolower( remove_accents( $name ) );
		$norm_name = preg_replace( '/[^a-z0-9]+/i', ' ', $norm_name );
		$norm_name = trim( preg_replace( '/\s+/', ' ', $norm_name ) );

		// 1) Exact title match (case-insensitive).
		$sql_exact = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND LOWER(post_title)=LOWER(%s) ORDER BY ID DESC LIMIT 2",
			$name
		);
		$exact_ids = $wpdb->get_col( $sql_exact );
		if ( is_array( $exact_ids ) ) {
			$exact_ids = array_values( array_unique( array_map( 'intval', $exact_ids ) ) );
			if ( count( $exact_ids ) === 1 ) { return intval( $exact_ids[0] ); }
		}

		// 2) LIKE candidates, then normalized exact compare.
		$like = '%' . $wpdb->esc_like( $name ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_title LIKE %s ORDER BY ID DESC LIMIT 10",
			$like
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) { return 0; }

		$matched = array();
		foreach ( $rows as $r ) {
			if ( empty( $r['ID'] ) || ! isset( $r['post_title'] ) ) { continue; }
			$title = (string) $r['post_title'];
			$norm_title = strtolower( remove_accents( $title ) );
			$norm_title = preg_replace( '/[^a-z0-9]+/i', ' ', $norm_title );
			$norm_title = trim( preg_replace( '/\s+/', ' ', $norm_title ) );
			if ( $norm_title === $norm_name ) {
				$matched[] = intval( $r['ID'] );
			}
		}
		$matched = array_values( array_unique( array_map( 'intval', $matched ) ) );
		if ( count( $matched ) === 1 ) { return intval( $matched[0] ); }

		// If there's a single LIKE hit, accept it.
		$ids = array_values( array_unique( array_map( 'intval', array_column( $rows, 'ID' ) ) ) );
		if ( count( $ids ) === 1 ) { return intval( $ids[0] ); }
		return 0;
	}

    private static function fmt_categories( $pid, $p ) {
        $ids = $p->get_category_ids();
        if ( ! is_array( $ids ) ) { $ids = array(); }
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        $names = array();
        foreach ( $ids as $tid ) {
            $t = get_term( $tid, 'product_cat' );
            if ( $t && ! is_wp_error( $t ) && ! empty( $t->name ) ) { $names[] = (string) $t->name; }
        }
        if ( empty( $names ) ) { $names[] = 'Uncategorized'; }
        return 'El producto #' . intval( $pid ) . ' tiene estas categorías: ' . implode( ', ', $names ) . '.';
    }
    private static function plain_price( $price ) {
        $price = is_numeric( $price ) ? (float) $price : 0.0;
        // Format without HTML (wc_price returns spans), to keep chat text clean.
        if ( function_exists( 'wc_get_price_decimals' ) ) {
            $decimals = (int) wc_get_price_decimals();
            $dec_sep  = (string) wc_get_price_decimal_separator();
            $th_sep   = (string) wc_get_price_thousand_separator();
        } else {
            $decimals = 2;
            $dec_sep  = '.';
            $th_sep   = ',';
        }
        $sym = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
        $sym = html_entity_decode( (string) $sym, ENT_QUOTES, 'UTF-8' );
        $formatted = number_format( $price, $decimals, $dec_sep, $th_sep );
        return $sym . $formatted;
    }


    private static function fmt_price( $pid, $p ) {
        $price = $p->get_price();
        if ( $price === '' || $price === null ) {
            return 'El producto #' . intval( $pid ) . ' no tiene precio configurado.';
        }
        return 'El precio actual del producto #' . intval( $pid ) . ' es ' . self::plain_price( $price ) . '.';
    }

    private static function fmt_stock( $pid, $p ) {
        if ( ! $p->managing_stock() ) {
            $status = $p->get_stock_status();
            return 'El producto #' . intval( $pid ) . ' no tiene stock por cantidad. Estado: ' . ( $status ? $status : '—' ) . '.';
        }
        $qty = $p->get_stock_quantity();
        if ( $qty === null ) { $qty = 0; }
        return 'El stock actual del producto #' . intval( $pid ) . ' es ' . intval( $qty ) . '.';
    }

    private static function fmt_title( $pid, $p ) {
        $t = $p->get_name();
        return 'El título actual del producto #' . intval( $pid ) . ' es: “' . (string) $t . '”.';
    }

    private static function fmt_shortdesc( $pid, $p ) {
        $d = $p->get_short_description();
        $d = wp_strip_all_tags( (string) $d );
        $d = trim( preg_replace( '/\s+/', ' ', $d ) );
        if ( $d === '' ) {
            return 'El producto #' . intval( $pid ) . ' no tiene descripción corta.';
        }
        return 'La descripción corta del producto #' . intval( $pid ) . ' es: “' . $d . '”.';
    }

    private static function fmt_snapshot( $pid, $p ) {
        $parts = array();
        $parts[] = 'Producto #' . intval( $pid ) . ': ' . (string) $p->get_name();

        // Price.
        $price = $p->get_price();
        $parts[] = 'Precio: ' . ( ( $price === '' || $price === null ) ? '—' : self::plain_price( $price ) );

        // Stock.
        if ( $p->managing_stock() ) {
            $qty = $p->get_stock_quantity();
            if ( $qty === null ) { $qty = 0; }
            $parts[] = 'Stock: ' . intval( $qty );
        } else {
            $parts[] = 'Stock: ' . (string) $p->get_stock_status();
        }

        // Categories.
        $ids = $p->get_category_ids();
        if ( ! is_array( $ids ) ) { $ids = array(); }
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        $names = array();
        foreach ( $ids as $tid ) {
            $t = get_term( $tid, 'product_cat' );
            if ( $t && ! is_wp_error( $t ) && ! empty( $t->name ) ) { $names[] = (string) $t->name; }
        }
        if ( empty( $names ) ) { $names[] = 'Uncategorized'; }
        $parts[] = 'Categorías: ' . implode( ', ', $names );

        return implode( "\n", $parts );
    }

    private static function describe_last_change( $store_state ) {
        if ( ! is_array( $store_state ) || empty( $store_state['pending_action'] ) ) {
            return '';
        }
        $pa = $store_state['pending_action'];
        if ( is_array( $pa ) && isset( $pa['action'] ) && is_array( $pa['action'] ) ) {
            $pa = $pa['action'];
        }
        if ( ! is_array( $pa ) ) { return ''; }

        $type = isset( $pa['type'] ) ? (string) $pa['type'] : '';
        $pid  = 0;
        foreach ( array( 'product_id', 'id' ) as $k ) {
            if ( isset( $pa[ $k ] ) ) { $pid = intval( $pa[ $k ] ); break; }
        }

        // Best-effort human summary.
        $txt = 'Tengo un cambio preparado';
        if ( $pid > 0 ) { $txt .= ' para el producto #' . $pid; }
        if ( $type !== '' ) { $txt .= ' (' . $type . ')'; }
        $txt .= '. Si querés, puedo mostrarte cómo está ahora o lo que va a quedar.';
        return $txt;
    }
}