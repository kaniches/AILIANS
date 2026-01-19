<?php

/**
 * F6.7 — Evaluación de regresión (Harness extendido)
 *
 * Lightweight, read-only regression runner over Telemetry JSONL.
 * - Does NOT call WooCommerce write endpoints.
 * - Does NOT mutate store_state.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Regression_Harness {

  /**
   * Run regression analysis over the latest telemetry file.
   *
   * @param array $args { limit?: int, include_failures?: bool }
   * @return array
   */
  public static function run( $args = array() ) {
    $limit            = isset( $args['limit'] ) ? max( 1, intval( $args['limit'] ) ) : 200;
    $limit            = min( 2000, $limit );
    $since_ts         = isset( $args['since_ts'] ) ? intval( $args['since_ts'] ) : 0;
    $include_failures = isset( $args['include_failures'] ) ? (bool) $args['include_failures'] : true;

    $telemetry_path = APAI_Brain_Telemetry::latest_file_path();
    if ( ! $telemetry_path || ! file_exists( $telemetry_path ) ) {
      return array(
        'ok'      => true,
        'meta'    => array(
          'limit' => $limit,
          'note'  => 'No telemetry file found. Enable telemetry or generate traffic first.',
        ),
        'metrics' => array(
          'total' => 0,
        ),
        'failures' => array(),
      );
    }

    $records = self::read_last_jsonl_records( $telemetry_path, $limit );

    if ( $since_ts > 0 ) {
      $records = array_values( array_filter( $records, function( $r ) use ( $since_ts ) {
        $ts = isset( $r['ts'] ) ? intval( $r['ts'] ) : 0;
        return $ts >= $since_ts;
      } ) );
    }

    $metrics = array(
      'total' => count( $records ),
      'by_route' => array(),
      'actions_proposed' => 0,
      'selections_started' => 0,
      'model_flow' => 0,
      'query_flow' => 0,
      'pending_flow' => 0,
      'deterministic_flow' => 0,
      'intent_parse_flow' => 0,
      'targeted_update_flow' => 0,
    );

    $failures = array();

    foreach ( $records as $rec ) {
      $route = isset( $rec['route'] ) ? (string) $rec['route'] : '';

      // Common fields for failure reports.
      $ts       = isset( $rec['ts'] ) ? intval( $rec['ts'] ) : 0;
      $trace_id = isset( $rec['trace_id'] ) ? (string) $rec['trace_id'] : '';
      $msg      = isset( $rec['message_raw'] ) ? (string) $rec['message_raw'] : ( isset( $rec['message'] ) ? (string) $rec['message'] : '' );
      if ( ! isset( $metrics['by_route'][ $route ] ) ) { $metrics['by_route'][ $route ] = 0; }
      $metrics['by_route'][ $route ]++;

      switch ( $route ) {
        case 'ModelFlow': $metrics['model_flow']++; break;
        case 'QueryFlow': $metrics['query_flow']++; break;
        case 'PendingFlow': $metrics['pending_flow']++; break;
        case 'DeterministicFlow': $metrics['deterministic_flow']++; break;
        case 'IntentParseFlow': $metrics['intent_parse_flow']++; break;
        case 'TargetedUpdateFlow': $metrics['targeted_update_flow']++; break;
      }

      $resp = isset( $rec['response'] ) && is_array( $rec['response'] ) ? $rec['response'] : array();
      $ss   = isset( $resp['store_state'] ) && is_array( $resp['store_state'] ) ? $resp['store_state'] : array();
      $flags = isset( $resp['context_lite']['flags'] ) && is_array( $resp['context_lite']['flags'] ) ? $resp['context_lite']['flags'] : array();

      $has_pending_action = isset( $flags['has_pending_action'] ) ? (bool) $flags['has_pending_action'] : ( isset( $ss['pending_action'] ) && ! empty( $ss['pending_action'] ) );
      $has_pending_sel    = isset( $flags['has_pending_target_selection'] ) ? (bool) $flags['has_pending_target_selection'] : ( isset( $ss['pending_target_selection'] ) && ! empty( $ss['pending_target_selection'] ) );

      if ( $has_pending_action ) { $metrics['actions_proposed']++; }
      if ( $has_pending_sel ) { $metrics['selections_started']++; }

      // --- Regression invariants (read-only) ---

      // (R1) No ModelFlow response may create pending action/selection.
      if ( $route === 'ModelFlow' && ( $has_pending_action || $has_pending_sel ) ) {
        $failures[] = self::mk_failure( $rec, 'model_flow_created_pending', 'ModelFlow must not create pending_action/selection.' );
      }

      // (R2) If this request CREATED a pending_action, it must come with confirmation UI.
      // Note: We allow read-only QueryFlow to run while a pending already exists (it does not execute changes).
      $trace = isset( $rec['trace'] ) && is_array( $rec['trace'] ) ? $rec['trace'] : array();
      $created_pending = self::trace_has_event( $trace, 'pending_set' );

      if ( $has_pending_action && $created_pending && $route !== 'QueryFlow' ) {
        $actions = isset( $resp['actions'] ) && is_array( $resp['actions'] ) ? $resp['actions'] : array();

        // Historically the UI renders from `actions` (preferred) and older builds used `cards`.
        $cards_count = 0;
        if ( isset( $resp['cards_count'] ) ) {
          $cards_count = (int) $resp['cards_count'];
        } elseif ( isset( $resp['cards'] ) && is_array( $resp['cards'] ) ) {
          $cards_count = count( $resp['cards'] );
        }

        if ( empty( $actions ) && $cards_count <= 0 ) {
          $failures[] = array(
            'id' => 'pending_without_confirmation',
            'details' => 'pending_action created but no confirmation/card provided.',
            'trace_id' => $trace_id,
            'ts' => $ts,
            'route' => $route,
            'message_raw' => $msg,
          );
        }
      }

      // (R3) QueryFlow must be read-only: never sets pending.
      if ( $route === 'QueryFlow' && ( $has_pending_action || $has_pending_sel ) ) {
        // QueryFlow may run while a pending already exists (it should not CREATE one).
        // Heuristic: if trace contains a pending_set event, it's a hard fail.
        if ( self::trace_has_event( $trace, 'pending_set' ) ) {
          $failures[] = self::mk_failure( $rec, 'query_flow_set_pending', 'QueryFlow created pending_set event.' );
        }
      }

      // (R4) Forbidden tokens check (same as QA, but over telemetry): context_full must not appear.
      $serialized = wp_json_encode( $resp );
      if ( is_string( $serialized ) && strpos( $serialized, 'context_full' ) !== false ) {
        $failures[] = self::mk_failure( $rec, 'forbidden_token_context_full', 'Response contains forbidden token "context_full".' );
      }


      // (R5) Invariants: every response must include store_state and meta.trace_id.
      if ( ! isset( $resp['store_state'] ) || ! is_array( $resp['store_state'] ) ) {
        $failures[] = self::mk_failure( $rec, 'missing_store_state', 'Response missing store_state (invariant: always return store_state).' );
      }
      // (R5b) Telemetry records store trace_id at the top-level. Response excerpt may not include meta.
      if ( ! isset( $rec['trace_id'] ) || (string) $rec['trace_id'] === '' ) {
        $failures[] = self::mk_failure( $rec, 'missing_trace_id', 'Telemetry record missing trace_id.' );
      }
// (R6) Queries must not show confirmation UI (no confirm/cancel buttons).
      // Heuristic: QueryFlow should not return action_proposed UI with confirm/cancel controls.
      if ( $route === 'QueryFlow' ) {
        $actions = isset( $resp['actions'] ) && is_array( $resp['actions'] ) ? $resp['actions'] : array();
        foreach ( $actions as $a ) {
          if ( is_array( $a ) && isset( $a['type'] ) && (string) $a['type'] === 'action_proposed' ) {
            $failures[] = self::mk_failure( $rec, 'query_flow_buttons', 'QueryFlow returned action_proposed UI (queries must not show confirm/cancel buttons).' );
            break;
          }
        }
      }

    }

    return array(
      'ok'   => true,
      'meta' => array(
        'limit' => $limit,
        'telemetry_file' => basename( $telemetry_path ),
        'telemetry_path' => $telemetry_path,
      ),
      'metrics' => $metrics,
      'failures' => $include_failures ? $failures : array(),
    );
  }

  /**
   * Read the last N JSONL records from a file efficiently.
   *
   * @param string $file
   * @param int $limit
   * @return array<int,array>
   */
  private static function read_last_jsonl_records( $file, $limit ) {
    $lines = self::tail_lines( $file, max( 20, $limit * 2 ) );
    $out = array();

    // We want the last "limit" valid JSON lines.
    for ( $i = count( $lines ) - 1; $i >= 0 && count( $out ) < $limit; $i-- ) {
      $line = trim( (string) $lines[ $i ] );
      if ( $line === '' ) { continue; }
      $json = json_decode( $line, true );
      if ( is_array( $json ) ) {
        array_unshift( $out, $json );
      }
    }
    return $out;
  }

  /**
   * Tail file lines without loading full file into memory.
   * @param string $filename
   * @param int $num_lines
   * @return array<int,string>
   */
  private static function tail_lines( $filename, $num_lines = 200 ) {
    $num_lines = max( 1, intval( $num_lines ) );
    $fh = @fopen( $filename, 'rb' );
    if ( ! $fh ) { return array(); }

    $buffer = '';
    $chunk_size = 8192;
    fseek( $fh, 0, SEEK_END );
    $pos = ftell( $fh );

    while ( $pos > 0 && substr_count( $buffer, "\n" ) <= $num_lines ) {
      $read = min( $chunk_size, $pos );
      $pos -= $read;
      fseek( $fh, $pos );
      $buffer = fread( $fh, $read ) . $buffer;
      if ( $pos === 0 ) { break; }
    }
    fclose( $fh );

    $lines = preg_split( "/\r\n|\n|\r/", $buffer );
    if ( ! is_array( $lines ) ) { return array(); }
    // Keep last num_lines-ish
    if ( count( $lines ) > $num_lines ) {
      $lines = array_slice( $lines, -1 * $num_lines );
    }
    return $lines;
  }

  private static function trace_has_event( $trace, $event_name ) {
    if ( ! is_array( $trace ) ) { return false; }
    foreach ( $trace as $e ) {
      if ( is_array( $e ) && isset( $e['event'] ) && $e['event'] === $event_name ) {
        return true;
      }
    }
    return false;
  }

  private static function mk_failure( $rec, $id, $details ) {
    return array(
      'id' => $id,
      'details' => $details,
      'trace_id' => isset( $rec['trace_id'] ) ? $rec['trace_id'] : null,
      'ts' => isset( $rec['ts'] ) ? $rec['ts'] : null,
      'route' => isset( $rec['route'] ) ? $rec['route'] : null,
      'message_raw' => isset( $rec['message_raw'] ) ? $rec['message_raw'] : null,
    );
  }
}
