<?php
/**
 * Best-effort target correction for info queries.
 * Example: "Perdón, era el #149" after "El producto #3 ¿qué stock tiene?" (not found)
 *
 * Behavior:
 * - If the previous message was an InfoQuery that failed (not_found=true), this flow will:
 *   1) update store_state to the corrected product_id
 *   2) immediately answer the *same* info query kind for the corrected product
 * - If there is no prior failed InfoQuery, it only updates store_state best-effort.
 *
 * This is conservative: no pending is created and it only runs when the user explicitly
 * corrects an ID.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Target_Correction_Flow {

    /**
     * @param string $message
     * @param string $message_norm
     * @param array  $store_state (by reference)
     * @return array|null response (ResponseBuilder envelope) or null if not handled
     */
    public static function try_handle( $message, $message_norm, &$store_state ) {
        $m = is_string( $message_norm ) ? $message_norm : '';
        if ( $m === '' ) {
            return null;
        }

        // Very conservative: only trigger on explicit "perdón" + "era/es" + an ID.
        // Examples:
        // - "perdón, era el 149"
        // - "perdon era #150"
        // - "perdon, es el 145"
        if ( ! preg_match( '/\bperd[oó]n\b.{0,40}\b(era|es)\b/su', $m ) ) {
            return null;
        }

        if ( ! preg_match( '/#?\b(\d{1,9})\b/', $m, $mm ) ) {
            return null;
        }

        $correct_id = intval( $mm[1] );
        if ( $correct_id <= 0 ) {
            return null;
        }

        // Best-effort: remember this corrected target for follow-ups.
        $store_state['last_target_product_id'] = $correct_id;

		// If we have a recent info query in memory, patch it.
		$li = isset( $store_state['last_info_query'] ) && is_array( $store_state['last_info_query'] ) ? $store_state['last_info_query'] : null;
		$prev_kind = null;
		$prev_not_found = false;
		if ( $li ) {
			$prev_kind = isset( $li['kind'] ) ? $li['kind'] : null;
			$prev_not_found = isset( $li['not_found'] ) && $li['not_found'] === true;
			// Patch the target only. Keep the previous not_found flag until we re-run the query.
			$li['product_id'] = $correct_id;
			$li['explicit']   = true;
			$li['ts']         = time();
			$store_state['last_info_query'] = $li;
		}

        // Persist patched store_state.
        if ( class_exists( 'APAI_Brain_Memory_Store' ) && method_exists( 'APAI_Brain_Memory_Store', 'update_state' ) ) {
            APAI_Brain_Memory_Store::update_state( $store_state );
        }

        // Auto-answer only when the previous info query was a "not found" for an explicit ID.
        // That matches the UX expectation: user corrects the ID, we respond with the same answer.
		if ( $prev_not_found && $prev_kind ) {
			$kind = $prev_kind;
            $synthetic = self::build_synthetic_infoquery( $kind, $correct_id );
            if ( $synthetic ) {
                $synthetic_norm = class_exists( 'APAI_Brain_Normalizer' ) ? APAI_Brain_Normalizer::normalize_intent_text( $synthetic ) : strtolower( $synthetic );
                $resp = APAI_Brain_Info_Query_Flow::try_handle( $synthetic, $synthetic_norm, $store_state );
                if ( $resp ) {
                    return $resp;
                }
            }
        }

        // If we couldn't auto-answer, at least acknowledge the correction.
		return APAI_Brain_Response_Builder::make_response(
			'chat',
			"Perfecto, ahora nos referimos al producto #{$correct_id}. ¿Qué querés saber o hacer con ese producto?",
			array(),
			null,
			null,
			array( 'route' => 'TargetCorrectionFlow' )
		);
    }

    private static function build_synthetic_infoquery( $kind, $product_id ) {
        $pid = intval( $product_id );
        if ( $pid <= 0 ) {
            return null;
        }

        $k = is_string( $kind ) ? strtolower( trim( $kind ) ) : '';
        switch ( $k ) {
            case 'stock':
                return "¿Qué stock tiene el producto #{$pid}?";
            case 'price':
                return "¿Qué precio tiene el producto #{$pid}?";
            case 'snapshot':
                return "Mostrame cómo está ahora el producto #{$pid}";
            default:
                return null;
        }
    }
}
