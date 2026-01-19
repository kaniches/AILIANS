<?php
/**
 * QA Harness (Regression) — Brain
 *
 * @FLOW QA
 * @INVARIANT Read-only: must not execute WooCommerce actions or mutate store_state.
 * WHY: Provide a deterministic, auditable way to validate critical invariants after updates.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_QA_Harness {
	/**
	 * Extract user-facing text from a response payload.
	 * ResponseBuilder uses "message_to_user" (legacy variants might use "message").
	 */
	private static function response_text( $resp ) {
		if ( ! is_array( $resp ) ) {
			return '';
		}
		if ( isset( $resp['message_to_user'] ) ) {
			return (string) $resp['message_to_user'];
		}
		if ( isset( $resp['message'] ) ) {
			return (string) $resp['message'];
		}
		return '';
	}

  /**
   * Run regression checks.
   *
   * @param array $opts { quick?:bool, verbose?:bool }
   * @return array Report payload
   */
  public static function run( $opts = array() ) {
    $t0 = microtime( true );

    $quick = isset( $opts['quick'] ) ? (bool) $opts['quick'] : true;
    $verbose = isset( $opts['verbose'] ) ? (bool) $opts['verbose'] : false;

    $checks = array();

    $root = dirname( __FILE__, 3 ); // plugin root

    // Helper: add check result.
    $add = function( $id, $ok, $details = '', $meta = array() ) use ( &$checks ) {
      $row = array(
        'id'      => (string) $id,
        'ok'      => (bool) $ok,
        'details' => (string) $details,
      );
      if ( is_array( $meta ) && ! empty( $meta ) ) {
        $row['meta'] = $meta;
      }
      $checks[] = $row;
    };

    // Helper: sanitize store_state to compare (avoid false diffs).
    $sanitize_state = function( $state ) {
      if ( ! is_array( $state ) ) { return array(); }
      $copy = $state;
      // updated_at can change due to unrelated writes; ignore for regression comparisons.
      if ( array_key_exists( 'updated_at', $copy ) ) { unset( $copy['updated_at'] ); }
      return $copy;
    };

    // -----------------------------------------------------------------------
    // Check 1: ResponseBuilder must always include store_state (server-side truth).
    // -----------------------------------------------------------------------
    $ok = false;
    $details = '';
    try {
      if ( class_exists( 'APAI_Brain_Response_Builder' ) ) {
        $payload = APAI_Brain_Response_Builder::make_response(
          'message',
          'qa ping',
          array(),
          null,
          null,
          array( 'qa' => true )
        );
        $ok = ( is_array( $payload ) && isset( $payload['store_state'] ) && is_array( $payload['store_state'] ) );
        $details = $ok ? 'store_state present' : 'store_state missing';
      } else {
        $details = 'ResponseBuilder class missing';
      }
    } catch ( \Throwable $e ) {
      $details = 'Exception: ' . $e->getMessage();
    }
    $add( 'rb_store_state', $ok, $details );

    // -----------------------------------------------------------------------
    // Check 2: ModelFlow must not reference Context Full tokens (static scan).
    // -----------------------------------------------------------------------
    $ok = false;
    $details = '';
    try {
      $file = $root . '/includes/flows/class-apai-brain-model-flow.php';
      if ( file_exists( $file ) ) {
        $src = (string) file_get_contents( $file );
        $src_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $src, 'UTF-8' ) : strtolower( $src );

        $forbidden = array(
          'context_full',
          'catalog_health',
          'catalog_products',
          'recent_orders',
        );

        $bad = array();
        foreach ( $forbidden as $tok ) {
          if ( strpos( $src_lc, $tok ) !== false ) {
            $bad[] = $tok;
          }
        }

        $ok = empty( $bad );
        $details = $ok ? 'no forbidden tokens' : ( 'forbidden tokens found: ' . implode( ', ', $bad ) );
      } else {
        $details = 'ModelFlow file missing';
      }
    } catch ( \Throwable $e ) {
      $details = 'Exception: ' . $e->getMessage();
    }
    $add( 'model_no_full_context', $ok, $details );

    // -----------------------------------------------------------------------
    // Check 3: Context Lite size guard (best-effort).
    // -----------------------------------------------------------------------
    $ok = false;
    $details = '';
    $meta = array();
    try {
      if ( class_exists( 'APAI_Brain_Context_Lite' ) ) {
        $lite = APAI_Brain_Context_Lite::build();
        $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $lite ) : json_encode( $lite );
        $len = is_string( $json ) ? strlen( $json ) : 0;

        $threshold = 2000; // safe default; lite must remain small/cheap
        $ok = ( $len > 0 && $len <= $threshold );

        $meta = array( 'chars' => $len, 'threshold' => $threshold );
        $details = $ok ? 'within threshold' : 'lite too large (or empty)';
      } else {
        $details = 'ContextLite class missing';
      }
    } catch ( \Throwable $e ) {
      $details = 'Exception: ' . $e->getMessage();
    }
    $add( 'context_lite_size', $ok, $details, $meta );



    // -----------------------------------------------------------------------
    // Check 4: While a target selection is pending, smalltalk must be blocked.
    // Pure check (no writes): call PendingFlow directly with a fake store_state.
    // -----------------------------------------------------------------------
    if ( class_exists( 'APAI_Brain_Pending_Flow' ) ) {
        $fake_state = array(
            'pending_action' => null,
            'pending_target_selection' => array(
                'action' => array(
                    'intent' => 'set_price',
                    'field' => 'price',
                    'raw_value_text' => '3333',
                    'selector' => array( 'type' => 'name', 'value' => 'remera azul' ),
                ),
                'candidates' => array(),
            ),
        );

        // Signature: (message, message_norm, store_state, pending)
		$r_sel   = APAI_Brain_Pending_Flow::try_handle( 'hola', 'hola', $fake_state, null );
		$txt_sel = self::response_text( $r_sel );
		$ok_sel  = ( ! empty( $txt_sel ) && false !== stripos( $txt_sel, 'selecci' ) );

        $checks[] = array(
            'id'      => 'target_selection_blocks_smalltalk',
            'ok'      => $ok_sel,
            'details' => $ok_sel ? 'blocked by PendingFlow' : 'did not block smalltalk while selection pending',
        );
    } else {
        $checks[] = array(
            'id'      => 'target_selection_blocks_smalltalk',
            'ok'      => false,
            'details' => 'PendingFlow class not available',
        );
    }

    // -----------------------------------------------------------------------
    // Check 5: With a pending_action, smalltalk must be blocked by PendingFlow.
    // This is a pure unit check (no DB writes).
    // -----------------------------------------------------------------------
    if ( class_exists( 'APAI_Brain_Pending_Flow' ) ) {
        $fake_pending = array(
            'type'         => 'update_product',
            'kind'         => 'price',
            'product_id'   => 21,
            'change_keys'  => array( 'regular_price' ),
            'before'       => array( 'regular_price' => '100.00' ),
            'after'        => array( 'regular_price' => '200.00' ),
            'summary'      => 'Actualizar producto: precio 200.00',
            'preview'      => array(
                'id'   => 21,
                'name' => 'Producto1 con imagen',
            ),
        );

        $fake_state2 = array(
            'pending_action'            => $fake_pending,
            'updated_at'                => time(),
            'last_product'              => null,
            'last_action_executed_at'   => null,
            'last_action_executed_summary' => null,
            'last_event'                => null,
            'pending_targeted_update'   => null,
            'pending_target_selection'  => null,
            'last_action_kind'          => 'price',
            'last_target_product_id'    => null,
            'last_action_executed_kind' => null,
        );

        // Signature: (message, message_norm, store_state, pending)
		$r_pend   = APAI_Brain_Pending_Flow::try_handle( 'hola', 'hola', $fake_state2, $fake_pending );
		$txt_pend = self::response_text( $r_pend );
		$ok_pend  = ( is_array( $r_pend ) && ! empty( $txt_pend ) && false !== stripos( $txt_pend, 'acci' ) );

        $checks[] = array(
            'id'      => 'pending_action_blocks_smalltalk',
            'ok'      => $ok_pend,
            'details' => $ok_pend ? 'blocked by PendingFlow' : 'did not block smalltalk while action pending',
        );
    } else {
        $checks[] = array(
            'id'      => 'pending_action_blocks_smalltalk',
            'ok'      => false,
            'details' => 'PendingFlow class not available',
        );
    }

    // -----------------------------------------------------------------------


    // Check 5.1: with pending_action, catalog queries must still be routable (QueryFlow before PendingFlow).
    $checks[] = array(
        'id' => 'pending_action_allows_queries',
        'ok' => (function() {
            if ( ! class_exists( 'APAI_Brain_Query_Flow' ) ) { return false; }
            $raw = 'productos sin precio';
            $norm = APAI_Brain_Normalizer::normalize_intent_text( $raw );

            // Part A: QueryFlow recognizes the query even if a pending_action exists.
            $fake_state = array(
                'pending_action' => array(
                    'type'      => 'update_product',
                    'product_id' => 123,
                    'updates'    => array( 'price' => '9.99' ),
                ),
                'pending_target_selection' => null,
            );

            $resp = APAI_Brain_Query_Flow::try_handle( $raw, $norm, null, $fake_state );
            $query_ok = ( is_array( $resp ) && isset( $resp['ok'] ) && $resp['ok'] === true );

            // Part B: Structural guard — Pipeline order must keep QueryFlow before PendingFlow.
            $pipeline_file = APAI_BRAIN_PATH . 'includes/class-apai-brain-pipeline.php';
            if ( ! file_exists( $pipeline_file ) ) { return false; }
            $src = file_get_contents( $pipeline_file );
            if ( ! is_string( $src ) || $src === '' ) { return false; }
            $pos_q = strpos( $src, 'APAI_Brain_Query_Flow::try_handle' );
            $pos_p = strpos( $src, 'APAI_Brain_Pending_Flow::try_handle' );
            $order_ok = ( $pos_q !== false && $pos_p !== false && $pos_q < $pos_p );

            return ( $query_ok && $order_ok );
        })(),
    );

    // Check 5.2: pending_action must block NEW actions and ask the user what to do.
    $checks[] = array(
        'id' => 'pending_action_blocks_new_action',
        'ok' => (function() {
            if ( ! class_exists( 'APAI_Brain_Pending_Flow' ) ) { return false; }
            $pending = array(
                'type'      => 'update_product',
                'product_id' => 123,
                'updates'    => array( 'price' => '9.99' ),
                'summary'    => 'Actualizar producto: precio 9.99',
                'preview'    => array( 'id' => 123, 'name' => 'Producto 123' ),
            );
            // Provide a full-ish state envelope to avoid undefined-index notices.
            $state = array(
                'pending_action'               => $pending,
                'updated_at'                   => time(),
                'last_product'                 => null,
                'last_action_kind'             => null,
                'last_target_product_id'       => null,
                'pending_targeted_update'      => null,
                'pending_target_selection'     => null,
                'last_action_executed_at'      => null,
                'last_action_executed_summary' => null,
                'last_event'                   => null,
                'last_action_executed_kind'    => null,
            );

            $raw  = 'cambiá el stock del producto 999 a 4';
            $norm = APAI_Brain_Normalizer::normalize_intent_text( $raw );

            $resp = APAI_Brain_Pending_Flow::try_handle( $raw, $norm, $state, $pending );
            if ( ! is_array( $resp ) ) { return false; }
            if ( ! isset( $resp['meta'] ) || ! is_array( $resp['meta'] ) ) { return false; }
            if ( ! isset( $resp['meta']['pending_choice'] ) || $resp['meta']['pending_choice'] !== 'swap_to_deferred' ) { return false; }
            if ( ! isset( $resp['meta']['deferred_message'] ) || trim( (string) $resp['meta']['deferred_message'] ) === '' ) { return false; }
            return true;
        })(),
    );

    // Check 5.3: while a target-selection is pending, QueryFlow must not hijack the follow-up answer.
    $checks[] = array(
        'id' => 'target_selection_blocks_query_hijack',
        'ok' => (function() {
            if ( ! class_exists( 'APAI_Brain_Query_Flow' ) ) { return false; }

            // Candidate title contains query-like tokens ('sin imagen') that could match QueryFlow heuristics.
            $title = 'Producto 2 sin imagen';
            $fake_state = array(
                'pending_targeted_update'  => null,
                'pending_target_selection' => array(
                    'candidates' => array(
                        array( 'id' => 2, 'title' => $title ),
                    ),
                ),
            );

            $raw  = $title;
            $norm = APAI_Brain_Normalizer::normalize_intent_text( $raw );

            // Expect: QueryFlow returns null so TargetSelection flow can handle the answer.
            $resp = APAI_Brain_Query_Flow::try_handle( $raw, $norm, null, $fake_state );
            return ( $resp === null );
        })(),
    );
    // Check 6: QueryFlow (A1–A8) must be read-only and must not propose actions.
    // This check is safe: it only runs one read-only query and compares store_state.
    // -----------------------------------------------------------------------
    $ok = false;
    $details = '';
    $meta = array();
    try {
      if ( class_exists( 'APAI_Brain_Query_Flow' ) && class_exists( 'APAI_Brain_Normalizer' ) && class_exists( 'APAI_Brain_Memory_Store' ) ) {
        $before = APAI_Brain_Memory_Store::get();
        $before_s = $sanitize_state( $before );

        $raw = 'productos sin precio';
        $norm = APAI_Brain_Normalizer::normalize_intent_text( $raw );

        $resp = APAI_Brain_Query_Flow::try_handle( $raw, $norm, null, $before );

        $after = APAI_Brain_Memory_Store::get();
        $after_s = $sanitize_state( $after );

        $same_state = ( wp_json_encode( $before_s ) === wp_json_encode( $after_s ) );

        $has_actions = ( is_array( $resp ) && isset( $resp['actions'] ) && is_array( $resp['actions'] ) && count( $resp['actions'] ) > 0 );
        $has_confirmation = ( is_array( $resp ) && isset( $resp['confirmation'] ) && is_array( $resp['confirmation'] ) );

        $ok = ( is_array( $resp ) && ! $has_actions && ! $has_confirmation && $same_state );

        $meta = array(
          'same_store_state' => $same_state,
          'has_actions'      => $has_actions,
          'has_confirmation' => $has_confirmation,
          'mode'             => is_array( $resp ) && isset( $resp['mode'] ) ? (string) $resp['mode'] : '',
        );

        $details = $ok ? 'query read-only ok' : 'query invariant failed';
      } else {
        $details = 'Required classes missing';
      }
    } catch ( \Throwable $e ) {
      $details = 'Exception: ' . $e->getMessage();
    }
    $add( 'query_read_only', $ok, $details, $meta );

    // -----------------------------------------------------------------------
    // Summary
    // -----------------------------------------------------------------------
    $all_ok = true;
    foreach ( $checks as $c ) {
      if ( empty( $c['ok'] ) ) { $all_ok = false; break; }
    }

    $dt_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

    $report = array(
      'ok'      => $all_ok,
      'meta'    => array(
        'quick'   => $quick,
        'verbose' => $verbose,
        'ms'      => $dt_ms,
        'time'    => gmdate( 'c' ),
      ),
      'checks'  => $checks,
    );

    if ( $verbose ) {
      $report['env'] = array(
        'wp'      => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
        'php'     => function_exists( 'phpversion' ) ? (string) phpversion() : '',
        'plugin'  => defined( 'APAI_BRAIN_VERSION' ) ? (string) APAI_BRAIN_VERSION : '',
      );
    }

    return $report;
  }
}
