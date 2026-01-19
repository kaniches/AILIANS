<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * QueryRegistry
 *
 * A thin dispatcher for A1–A8 read-only queries.
 *
 * INVARIANT: Read-only. Must not create pending_action or mutate store_state.
 */
class APAI_Query_Registry {

    /**
     * Direct handler entrypoint used by IntentParseFlow (brain_parse).
     *
     * @INVARIANT Queries are read-only: no pending, no memory mutations.
     * @return array|null Same response payload as try_handle(), or null if unknown.
     */
    public static function handle_by_code( $code, $mode = 'summary', $message_raw = '', $message_norm = '' ) {
        $code = strtoupper( trim( (string) $code ) );
        if ( $code === '' ) { return null; }

        $map = array(
            'A1' => 'APAI_Query_H_A1',
            'A2' => 'APAI_Query_H_A2',
            'A3' => 'APAI_Query_H_A3',
            'A4' => 'APAI_Query_H_A4',
            'A5' => 'APAI_Query_H_A5',
            'A6' => 'APAI_Query_H_A6',
            'A7' => 'APAI_Query_H_A7',
            'A8' => 'APAI_Query_H_A8',
        );
        if ( empty( $map[ $code ] ) ) { return null; }

        $h = $map[ $code ];
        if ( ! is_string( $h ) || ! class_exists( $h ) || ! method_exists( $h, 'handle' ) ) {
            return null;
        }

        $m_raw  = (string) $message_raw;
        $m_norm = (string) $message_norm;

        // Ensure A8 can reuse its existing parsing (full/top 5) without new logic.
        if ( $code === 'A8' ) {
            $mode = strtolower( trim( (string) $mode ) );
            if ( $mode === 'full' ) {
                $m_norm .= ' full';
            } elseif ( $mode === 'top5' || $mode === 'top 5' ) {
                $m_norm .= ' top 5';
            }
        }

        $limit = ( $code === 'A8' && ( strtolower( (string) $mode ) === 'top5' || strtolower( (string) $mode ) === 'top 5' ) ) ? 5 : 20;

        // Handlers accept ($message_raw, $m_norm, $context_full, $wants_count, $limit).
        return call_user_func( array( $h, 'handle' ), $m_raw, $m_norm, null, true, $limit );
    }

    public static function try_handle( $message_raw, $m_norm, $context_full, $wants_count, $limit ) {
        require_once APAI_BRAIN_PATH . 'includes/queries/class-apai-catalog-repository.php';
        require_once APAI_BRAIN_PATH . 'includes/presenters/class-apai-query-presenter.php';
        require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-response-builder.php';

        // Per-query handlers (move-only extraction from the former QueryFlow).
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-salud-clarify.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a1.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a2.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a3.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a4.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a5.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a6.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a7.php';
	    require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a8.php';

	    $handlers = array(
	        'APAI_Query_H_Salud_Clarify',
	        'APAI_Query_H_A1',
	        'APAI_Query_H_A2',
	        'APAI_Query_H_A3',
	        'APAI_Query_H_A4',
	        'APAI_Query_H_A5',
	        'APAI_Query_H_A6',
	        'APAI_Query_H_A7',
	        'APAI_Query_H_A8',
	    );

        foreach ( $handlers as $handler_class ) {
            if ( is_string( $handler_class ) && class_exists( $handler_class ) && $handler_class::matches( $m_norm, $wants_count ) ) {
                return $handler_class::handle( $message_raw, $m_norm, $context_full, $wants_count, $limit );
            }
        }

        return null;
    }
}
