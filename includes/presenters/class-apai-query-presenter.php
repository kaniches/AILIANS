<?php

/**
 * Query Presenter
 *
 * @FLOW QueryFlow
 *
 * WHY: Keep formatting/copy for A1–A8 queries in one place.
 * @INVARIANT: Must not introduce pending/action cards.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Query_Presenter {

    /**
     * Format a simple "Encontré X ..." count sentence.
     */
    public static function count_sentence( $total, $label ) {
        $total_i = (int) $total;
        if ( $total_i <= 0 ) {
            // Mantener estilo legacy cuando no hay resultados.
            return self::empty_sentence( $label );
        }
        return 'Encontré ' . $total_i . ' producto(s) ' . $label . '.';
    }

    /**
     * Format "No encontré... ✅" with the exact style used in existing flows.
     */
    public static function empty_sentence( $label ) {
        return 'No encontré productos ' . $label . ' ✅';
    }

    /**
     * Format a list response with ID markers.
     *
     * @param int $total
     * @param array $rows Each item has ID/post_title or id/title
     * @param int $limit
     * @param string $labelShort Example: 'sin precio'
     */
    public static function list_with_ids( $total, $rows, $limit, $labelShort ) {
        $limit = (int) $limit;
        if ( $limit <= 0 ) { $limit = 10; }

        // UX/compat: si no hay resultados, evitamos "Encontré 0... Te muestro...".
        // En su lugar devolvemos "No encontré productos ... ✅".
        if ( (int) $total <= 0 ) {
            return self::empty_sentence( $labelShort );
        }

        $names = array();
        $shown = 0;

        foreach ( (array) $rows as $r ) {
            $id = 0;
            $title = '(sin título)';
            if ( is_array( $r ) ) {
                if ( isset( $r['ID'] ) ) { $id = (int) $r['ID']; }
                elseif ( isset( $r['id'] ) ) { $id = (int) $r['id']; }
                if ( isset( $r['post_title'] ) ) { $title = (string) $r['post_title']; }
                elseif ( isset( $r['title'] ) ) { $title = (string) $r['title']; }
            }
            $names[] = '• ' . $title . ' (ID ' . $id . ')';
            $shown++;
            if ( $shown >= $limit ) { break; }
        }

        return 'Encontré ' . (int) $total . ' producto(s) ' . $labelShort . '. Te muestro hasta ' . $limit . ":\n" . implode( "\n", $names );
    }

    // --- A1–A3/A5/A7 wrappers (compat + consistent copy) ---

    public static function a1_no_price_message_count( $total ) {
        return 'Encontré ' . (int) $total . ' producto(s) sin precio.';
    }

    public static function a1_no_price_message( $total, $rows, $limit = 10 ) {
        return self::list_with_ids( $total, $rows, $limit, 'sin precio' );
    }

    public static function a2_no_description_message_count( $total ) {
        return 'Encontré ' . (int) $total . ' producto(s) sin descripción.';
    }

    public static function a2_no_description_message( $total, $rows, $limit = 10 ) {
        return self::list_with_ids( $total, $rows, $limit, 'sin descripción' );
    }

    public static function a3_no_sku_message_count( $total ) {
        return 'Encontré ' . (int) $total . ' producto(s) sin SKU.';
    }

    public static function a3_no_sku_message( $total, $rows, $limit = 10 ) {
        return self::list_with_ids( $total, $rows, $limit, 'sin SKU' );
    }

    public static function a5_no_image_message_count( $total ) {
        return 'Encontré ' . (int) $total . ' producto(s) sin imagen destacada.';
    }

    public static function a5_no_image_message( $total, $rows, $limit = 10 ) {
        return self::list_with_ids( $total, $rows, $limit, 'sin imagen destacada' );
    }

    public static function a7_no_stock_message_count( $total ) {
        return 'Encontré ' . (int) $total . ' producto(s) sin stock.';
    }

    public static function a7_no_stock_message( $total, $rows, $limit = 10 ) {
        return self::list_with_ids( $total, $rows, $limit, 'sin stock' );
    }

	public static function a6_incomplete_products_message_count( $total ) {
		return 'Encontré ' . (int) $total . ' producto(s) incompleto(s).';
	}

	public static function a6_incomplete_products_message( $total, $items, $limit = 10 ) {
		$total = (int) $total;
		if ( $total <= 0 ) {
			return 'No encontré productos incompletos ✅';
		}
		$limit = (int) $limit;
		if ( $limit <= 0 ) { $limit = 10; }
		$lines = array();
		$shown = 0;
		foreach ( (array) $items as $it ) {
			$id   = isset( $it['id'] ) ? (int) $it['id'] : 0;
			$name = isset( $it['name'] ) ? (string) $it['name'] : '(sin título)';
			$reasons = array();
			if ( ! empty( $it['missing_price'] ) ) { $reasons[] = 'precio'; }
			if ( ! empty( $it['missing_description'] ) ) { $reasons[] = 'descripción'; }
			if ( ! empty( $it['missing_sku'] ) ) { $reasons[] = 'SKU'; }
			if ( ! empty( $it['missing_category'] ) ) { $reasons[] = 'categoría'; }
			if ( ! empty( $it['missing_featured_image'] ) ) { $reasons[] = 'imagen destacada'; }
			$why = empty( $reasons ) ? '' : ' — faltan: ' . implode( ', ', $reasons );
			$lines[] = '• ' . $name . ' (ID ' . $id . ')' . $why;
			$shown++;
			if ( $shown >= $limit ) { break; }
		}
		return "📦 **Productos incompletos (A6)**\n" .
			'Encontré ' . $total . ' producto(s) incompleto(s). Te muestro hasta ' . $limit . ":\n" .
			implode( "\n", $lines );
	}

    /**
     * A4 — "sin categoría" message (legacy copy includes "Sin categorizar").
     */
    public static function a4_no_category_message( $total, $rows = array(), $limit = 10, $only_count = false ) {
        $total = (int) $total;
        if ( $only_count ) {
            return 'Encontré ' . $total . ' producto(s) sin categoría (incluye "Sin categorizar").';
        }
        if ( $total <= 0 ) {
            return 'No encontré productos sin categoría ✅';
        }
        $limit = (int) $limit;
        if ( $limit <= 0 ) { $limit = 10; }

        $names = array();
        $shown = 0;
        foreach ( (array) $rows as $r ) {
            $id = isset( $r['ID'] ) ? (int) $r['ID'] : ( isset( $r['id'] ) ? (int) $r['id'] : 0 );
            $title = isset( $r['post_title'] ) ? (string) $r['post_title'] : ( isset( $r['title'] ) ? (string) $r['title'] : '(sin título)' );
            $names[] = '• ' . $title . ' (ID ' . $id . ')';
            $shown++;
            if ( $shown >= $limit ) { break; }
        }

        return 'Encontré ' . $total . ' producto(s) sin categoría (incluye "Sin categorizar"). Te muestro hasta ' . $limit . ":\n" . implode( "\n", $names );
    }

    /**
     * A6 — Incomplete products message (legacy-compatible).
     */
    public static function a6_incomplete_message( $total, $items = array(), $list_limit = 50, $only_count = false ) {
        $total = (int) $total;
        $list_limit = (int) $list_limit;
        if ( $list_limit <= 0 ) { $list_limit = 50; }

        if ( $only_count ) {
            return 'Encontré ' . $total . ' producto(s) incompleto(s).';
        }
        if ( $total <= 0 ) {
            return 'No encontré productos incompletos ✅';
        }

        $msg = 'Encontré ' . $total . ' producto(s) incompleto(s). Te muestro hasta ' . $list_limit . ":\n";
        $lines = array();
        foreach ( (array) $items as $it ) {
            $name = '';
            if ( is_array( $it ) ) {
                $name = isset( $it['name'] ) ? (string) $it['name'] : ( isset( $it['title'] ) ? (string) $it['title'] : '' );
            }
            if ( $name === '' ) { continue; }
            $reasons = ( is_array( $it ) && isset( $it['reasons'] ) && is_array( $it['reasons'] ) ) ? $it['reasons'] : array();
            if ( empty( $reasons ) ) {
                $lines[] = '- ' . $name;
            } else {
                $lines[] = '- ' . $name . ' (faltan: ' . implode( ', ', $reasons ) . ')';
            }
        }
        return $msg . implode( "\n", $lines );
    }

    /**
     * Render A8 summary/full message.
     */
    public static function a8_catalog_health_message( $data, $want_full = false ) {
        $total = isset( $data['total_products'] ) ? (int) $data['total_products'] : 0;
        $score = isset( $data['score'] ) ? (int) $data['score'] : 100;
        $thr   = isset( $data['low_threshold'] ) ? (int) $data['low_threshold'] : 2;
        $c     = isset( $data['counts'] ) && is_array( $data['counts'] ) ? $data['counts'] : array();

        $get = function( $k ) use ( $c ) {
            return isset( $c[ $k ] ) ? (int) $c[ $k ] : 0;
        };

        $lines = array();
        $lines[] = "📊 **Salud del catálogo (A8)**";
        $lines[] = "• Productos publicados: **{$total}**";
        $lines[] = "• Score (0–100): **{$score}**";

        $lines[] = "";
        $lines[] = "**Datos (A1–A5)**";
        $lines[] = "• Sin precio: **" . $get('no_price') . "**";
        $lines[] = "• Sin descripción: **" . $get('no_description') . "**";
        $lines[] = "• Sin SKU: **" . $get('no_sku') . "**";
        $lines[] = "• Sin categoría: **" . $get('no_category') . "**";
        $lines[] = "• Sin imagen destacada: **" . $get('no_featured_image') . "**";

        $lines[] = "";
        $lines[] = "**Stock (A7)**";
        $lines[] = "• Sin stock: **" . $get('out_of_stock') . "**";
        $lines[] = "• Bajo stock (≤ {$thr}): **" . $get('low_stock') . "**";
        $lines[] = "• Backorder: **" . $get('backorder') . "**";

        if ( $want_full ) {
            $lines[] = "";
            $lines[] = "**Top 5 ejemplos (si aplica)**";

            $top = isset( $data['top'] ) && is_array( $data['top'] ) ? $data['top'] : array();
            $fmt_list = function( $label, $rows ) {
                $out = array();
                if ( empty( $rows ) ) {
                    $out[] = "• {$label}: —";
                    return $out;
                }
                $out[] = "• {$label}:";
                foreach ( $rows as $r ) {
                    $title = '';
                    if ( is_array( $r ) ) {
                        $title = isset( $r['post_title'] ) ? $r['post_title'] : ( isset( $r['name'] ) ? $r['name'] : '' );
                    }
                    $title = function_exists('wp_strip_all_tags') ? wp_strip_all_tags( $title ) : (string) $title;
                    if ( isset( $r['stock_qty'] ) ) {
                        $out[] = "  - {$title} (stock: {$r['stock_qty']})";
                    } else {
                        $out[] = "  - {$title}";
                    }
                }
                return $out;
            };

            $lines = array_merge( $lines, $fmt_list( 'Sin precio', isset($top['no_price']) ? $top['no_price'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Sin descripción', isset($top['no_description']) ? $top['no_description'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Sin SKU', isset($top['no_sku']) ? $top['no_sku'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Sin categoría', isset($top['no_category']) ? $top['no_category'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Sin imagen destacada', isset($top['no_featured_image']) ? $top['no_featured_image'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Sin stock', isset($top['out_of_stock']) ? $top['out_of_stock'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Bajo stock', isset($top['low_stock']) ? $top['low_stock'] : array() ) );
            $lines = array_merge( $lines, $fmt_list( 'Backorder', isset($top['backorder']) ? $top['backorder'] : array() ) );
        } else {
            $lines[] = "";
            $lines[] = "Tip: escribí **“salud del catálogo full”** para ver top 5 ejemplos por cada alerta.";
        }

        return implode( "\n", $lines );
    }
}
