<?php
/**
 * @FLOW Brain
 * @INVARIANT No introducir heurísticas nuevas: solo mover lógica existente.
 * WHY: Centralizar normalización semántica y parseo de números/precios.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Normalizer {

    /**
     * Normalize user input for intent detection (cheap, deterministic).
     * - lowercase
     * - strip accents
     * - remove punctuation/emojis (keep letters/numbers/spaces)
     * - collapse whitespace
     *
     * Copiado 1:1 de APAI_Brain_REST::normalize_intent_text.
     */
    public static function normalize_intent_text( $message ) {
        $m = strtolower( trim( (string) $message ) );
        // Remove accents
        if ( function_exists( 'remove_accents' ) ) {
            $m = remove_accents( $m );
        } else {
            $m = iconv( 'UTF-8', 'ASCII//TRANSLIT', $m );
        }
        // Replace punctuation with spaces.
        // IMPORTANT: keep numeric separators and the minus sign so we don't lose meaning.
        // Examples to preserve: "-5", "$1.000", "1,000", "10k".
        $m = preg_replace( '/[^a-z0-9\s\-\.,]+/u', ' ', $m );
        $m = preg_replace( '/\s+/u', ' ', trim( $m ) );

        // Strip common fillers at the end (cheap UX robustness)
        $fillers = array(
            'bro','amigo','che','jaja','jeje','porfa','por favor','pls','please','okey','okeyy','daleee','gracias','graciass',
        );
        foreach ( $fillers as $f ) {
            $f = trim( $f );
            if ( $f === '' ) { continue; }
            $m = preg_replace( '/\b' . preg_quote( $f, '/' ) . '\b$/u', '', trim( $m ) );
            $m = preg_replace( '/\s+/u', ' ', trim( $m ) );
        }
        return $m;
    }

    /**
     * ASCII-only normalization helper used by some classifiers.
     * Copiado 1:1 de APAI_Brain_REST::normalize_ascii.
     */
    public static function normalize_ascii( $message ) {
        $m = trim( (string) $message );
        if ( $m === "" ) { return ""; }
        if ( function_exists( "remove_accents" ) ) {
            $m = remove_accents( $m );
        }
        // Best-effort transliteration
        if ( function_exists( "iconv" ) ) {
            $t = @iconv( "UTF-8", "ASCII//TRANSLIT//IGNORE", $m );
            if ( $t !== false && $t !== null ) { $m = $t; }
        }
        $m = strtolower( $m );
        // Normalize whitespace
		$m = preg_replace( "/\s+/", " ", $m );
        return trim( $m );
    }

    /**
     * Parse a localized number string into float.
     * Copiado 1:1 de APAI_Brain_REST::parse_number.
     */
    public static function parse_number( $raw ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( $raw === '' ) {
            return null;
        }

        // Remove spaces (incl. non-breaking).
        $raw = str_replace( array( ' ', "\xC2\xA0" ), '', $raw );

        $has_comma = strpos( $raw, ',' ) !== false;
        $has_dot   = strpos( $raw, '.' ) !== false;

        if ( $has_comma && $has_dot ) {
            // If comma appears after dot, assume comma is decimal separator (pt-AR style): 1.234,56
            if ( strrpos( $raw, ',' ) > strrpos( $raw, '.' ) ) {
                $raw = str_replace( '.', '', $raw );
                $raw = str_replace( ',', '.', $raw );
            } else {
                // Otherwise assume dot is decimal, commas are thousands: 1,234.56
                $raw = str_replace( ',', '', $raw );
            }
        } elseif ( $has_comma ) {
            // If ends with ,dd treat as decimal; otherwise thousands separator.
            if ( preg_match( '/,\d{1,2}$/', $raw ) ) {
                $raw = str_replace( ',', '.', $raw );
            } else {
                $raw = str_replace( ',', '', $raw );
            }
        } elseif ( $has_dot ) {
            // Dot-only inputs are ambiguous. If it looks like a thousands separator pattern (1.000 / 1.000.000),
            // strip dots; otherwise keep dot as decimal separator.
            if ( preg_match( '/^-?\d{1,3}(?:\.\d{3})+$/', $raw ) ) {
                $raw = str_replace( '.', '', $raw );
            }
        }

        // Keep only digits, dot and minus.
        $raw = preg_replace( '/[^0-9\.\-]/', '', $raw );
        if ( $raw === '' || $raw === '-' || $raw === '.' ) {
            return null;
        }

        $num = floatval( $raw );
        if ( ! is_finite( $num ) ) {
            return null;
        }

        return $num;
    }

    /**
     * Parse simple price strings like "$2000", "2.000", "2,000.50".
     * Copiado 1:1 de APAI_Brain_REST::parse_price_number.
     */
    public static function parse_price_number( $raw ) {
		// OJO: en PHP, is_numeric("1.000") es TRUE y lo interpreta como 1.0 (decimal),
		// pero en AR/ES suele significar miles. Solo fast-trackeamos números sin separadores.
		if ( is_numeric( $raw ) && is_string( $raw ) ) {
			$raw_trim = trim( $raw );
			if ( $raw_trim !== '' && false === strpos( $raw_trim, '.' ) && false === strpos( $raw_trim, ',' ) ) {
				return (float) $raw_trim;
			}
		} elseif ( is_numeric( $raw ) ) {
			// int/float reales
			return (float) $raw;
		}
        if ( ! is_string( $raw ) ) {
            return 0.0;
        }
        $s = trim( $raw );
        if ( '' === $s ) {
            return 0.0;
        }

        // --- Hotfix follow-up price v2 (lucas / mil / k) ---
        // @INVARIANT: No se introducen heurísticas nuevas. Esto replica el comportamiento ya cerrado
        // del hotfix (interpretación humana de cantidades en miles) antes de sanitizar.
        // WHY: Evita que "10 lucas" se degrade a "10" por el stripping de caracteres.
        $lower = strtolower( $s );
        $lower = function_exists( 'remove_accents' ) ? remove_accents( $lower ) : $lower;
        // Match: "10k", "10 k", "10 lucas", "10 luca", "10 mil", "10 miles".
        if ( preg_match( '/\b(-?\d+(?:[\.,]\d+)?)\s*(k|mil|miles|luca|lucas)\b/u', $lower, $m ) ) {
            $base = self::parse_number( $m[1] );
            if ( $base !== null ) {
                return (float) $base * 1000.0;
            }
        }
	    // keep digits, comma, dot
	    // NOTE: usamos flag /u para que \x{00A0} (nbsp) se trate correctamente.
	    $s = preg_replace( '/[^0-9,\.\-\s\x{00A0}]/u', '', $s );
        if ( '' === trim( $s ) ) {
            return 0.0;
        }

	    // Hotfix: "1.000" (miles) sin símbolo de moneda estaba degradando a 1.0 en algunos contextos.
	    // Normalizamos separadores de miles ANTES de parsear.
	    $compact = str_replace( array( "\xC2\xA0", ' ' ), '', $s );
	    if ( preg_match( '/^-?\d{1,3}(?:\.\d{3})+$/', $compact ) ) {
	        $s = str_replace( '.', '', $compact );
	    } elseif ( preg_match( '/^-?\d{1,3}(?:,\d{3})+$/', $compact ) ) {
	        $s = str_replace( ',', '', $compact );
	    }

        $n = self::parse_number( $s );
        return $n === null ? 0.0 : (float) $n;
    }


    /**
     * Parse stock/quantity numbers.
     *
     * @INVARIANT No introducir heurísticas nuevas.
     * WHY: PendingFlow (merge UX) necesita un parser único y estable para cantidades.
     *
     * Returns an integer quantity (rounded) or null if it cannot be parsed.
     */
    public static function parse_stock_number( $raw ) {
        $n = self::parse_number( $raw );
        if ( $n === null ) {
            return null;
        }

        return (int) round( (float) $n );
    }


    /**
     * Parse integer numbers (alias for stock/qty parsing).
     *
     * @INVARIANT No introducir heurísticas nuevas.
     * WHY: Compatibilidad con código existente que espera parse_int().
     */
    public static function parse_int( $raw ) {
        return self::parse_stock_number( $raw );
    }

    /**
     * Format a numeric price according to the store's WooCommerce decimals.
     * - rounds with wc_get_price_decimals()
     * - returns a string without thousands separators
     * - keeps fixed decimals when dp>0 (e.g. 1234.50)
     */
    public static function format_price_for_wc( $num ) {
        if ( ! is_numeric( $num ) ) {
            return null;
        }
        $n  = (float) $num;
        $dp = function_exists( 'wc_get_price_decimals' ) ? intval( wc_get_price_decimals() ) : 2;
        if ( $dp < 0 ) { $dp = 0; }
        if ( $dp > 6 ) { $dp = 6; }

        $rounded = round( $n, $dp );

        // Avoid "-0" / "-0.00" edge cases
        if ( abs( $rounded ) < 0.0000001 ) {
            $rounded = 0.0;
        }

        if ( $dp === 0 ) {
            return (string) intval( round( $rounded ) );
        }
        return number_format( $rounded, $dp, '.', '' );
    }
}
