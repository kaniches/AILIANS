<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * F6.OBS — Health / Observability summary (read-only)
 *
 * @FLOW Observability
 * @INVARIANT Must never mutate store_state or execute WooCommerce actions.
 * WHY: Provide a stable, auditable definition of "Brain health" for admins.
 *
 * Health model (conservative):
 * - failure: response.ok === false OR HTTP status >= 400 OR missing invariants (store_state/meta.trace_id)
 * - warning: clarification asked (best-effort)
 *
 * Data sources:
 * - Prefer Telemetry JSONL (if enabled) because it contains full response payload.
 * - Fallback to Trace log (if telemetry is disabled) with limited insights.
 */
class APAI_Brain_Health {

  public static function compute( $opts = array() ) {
    $limit = isset( $opts['limit'] ) ? (int) $opts['limit'] : 200;
    if ( $limit < 20 ) { $limit = 20; }
    if ( $limit > 2000 ) { $limit = 2000; }

    $out = array(
      'ok' => true,
      'meta' => array(
        'limit' => $limit,
        'time_utc' => gmdate( 'c' ),
        'source' => 'none',
      ),
      'health' => array(
        'total' => 0,
        'failures' => 0,
        'warnings' => 0,
      ),
      'signals' => array(
        'clarifications' => 0,
        'pending_created' => 0,
        'routes' => array(),
      ),
      'examples' => array(
        'failures' => array(),
        'warnings' => array(),
      ),
    );

    // Prefer telemetry when available.
    if ( class_exists( 'APAI_Brain_Telemetry' ) && APAI_Brain_Telemetry::enabled() ) {
      $file = APAI_Brain_Telemetry::latest_file();
      if ( is_string( $file ) && $file !== '' && @is_file( $file ) ) {
        $recs = self::read_last_jsonl_records( $file, $limit );
        $out['meta']['source'] = 'telemetry';
        return self::compute_from_telemetry( $out, $recs );
      }
    }

    // Fallback: trace (limited).
    if ( class_exists( 'APAI_Brain_Trace' ) ) {
      $file = APAI_Brain_Trace::log_path();
      if ( is_string( $file ) && $file !== '' && @is_file( $file ) ) {
        $recs = self::read_last_jsonl_records( $file, $limit );
        $out['meta']['source'] = 'trace';
        return self::compute_from_trace( $out, $recs );
      }
    }

    return $out;
  }

  private static function compute_from_telemetry( $out, $recs ) {
    foreach ( $recs as $rec ) {
      if ( ! is_array( $rec ) ) { continue; }
      $out['health']['total']++;

      $route = isset( $rec['route'] ) ? (string) $rec['route'] : '';
      if ( $route !== '' ) {
        if ( ! isset( $out['signals']['routes'][ $route ] ) ) { $out['signals']['routes'][ $route ] = 0; }
        $out['signals']['routes'][ $route ]++;
      }

      $resp = isset( $rec['response'] ) && is_array( $rec['response'] ) ? $rec['response'] : array();
      $http_status = isset( $resp['meta']['http_status'] ) ? (int) $resp['meta']['http_status'] : 200;
      $ok_flag = isset( $resp['ok'] ) ? (bool) $resp['ok'] : true;

      // Invariants
      $has_store_state = isset( $resp['store_state'] ) && is_array( $resp['store_state'] );
      $has_trace_id = isset( $resp['meta']['trace_id'] ) && is_string( $resp['meta']['trace_id'] ) && $resp['meta']['trace_id'] !== '';

      $is_failure = ( $ok_flag === false ) || ( $http_status >= 400 ) || ( ! $has_store_state ) || ( ! $has_trace_id );

      if ( $is_failure ) {
        $out['ok'] = false;
        $out['health']['failures']++;
        if ( count( $out['examples']['failures'] ) < 5 ) {
          $out['examples']['failures'][] = self::mk_example( $rec, 'failure', array(
            'ok' => $ok_flag,
            'http_status' => $http_status,
            'has_store_state' => $has_store_state,
            'has_trace_id' => $has_trace_id,
          ) );
        }
        continue;
      }

      // Warnings (best effort, non-fatal)
      $warn_reasons = array();

      if ( isset( $resp['meta']['needs_clarification'] ) && $resp['meta']['needs_clarification'] ) {
        $warn_reasons[] = 'needs_clarification';
        $out['signals']['clarifications']++;
      }

      // Pending created signal from trace events embedded in telemetry record.
      $trace = isset( $rec['trace'] ) && is_array( $rec['trace'] ) ? $rec['trace'] : array();
      if ( self::trace_has_event( $trace, 'pending_set' ) ) {
        $out['signals']['pending_created']++;
      }

      if ( ! empty( $warn_reasons ) ) {
        $out['health']['warnings']++;
        if ( count( $out['examples']['warnings'] ) < 5 ) {
          $out['examples']['warnings'][] = self::mk_example( $rec, 'warning', array( 'reasons' => $warn_reasons ) );
        }
      }
    }

    return $out;
  }

  private static function compute_from_trace( $out, $recs ) {
    foreach ( $recs as $rec ) {
      if ( ! is_array( $rec ) ) { continue; }
      $out['health']['total']++;

      $event = isset( $rec['event'] ) ? (string) $rec['event'] : '';
      if ( $event !== '' ) {
        if ( ! isset( $out['signals']['routes'][ $event ] ) ) { $out['signals']['routes'][ $event ] = 0; }
        $out['signals']['routes'][ $event ]++;
      }
    }

    return $out;
  }

  private static function mk_example( $rec, $level, $extra = array() ) {
    $ts = isset( $rec['ts'] ) ? (int) $rec['ts'] : 0;
    $trace_id = isset( $rec['trace_id'] ) ? (string) $rec['trace_id'] : '';
    $msg = isset( $rec['message_raw'] ) ? (string) $rec['message_raw'] : ( isset( $rec['message'] ) ? (string) $rec['message'] : '' );
    if ( strlen( $msg ) > 140 ) { $msg = substr( $msg, 0, 140 ) . '…'; }
    return array(
      'ts' => $ts,
      'trace_id' => $trace_id,
      'route' => isset( $rec['route'] ) ? (string) $rec['route'] : '',
      'message' => $msg,
      'level' => (string) $level,
      'extra' => is_array( $extra ) ? $extra : array(),
    );
  }

  private static function trace_has_event( $trace, $event ) {
    if ( ! is_array( $trace ) ) { return false; }
    $event = (string) $event;
    foreach ( $trace as $ev ) {
      if ( is_array( $ev ) && isset( $ev['event'] ) && (string) $ev['event'] === $event ) {
        return true;
      }
    }
    return false;
  }

  private static function read_last_jsonl_records( $file, $limit ) {
    $lines = self::tail_lines( $file, max( 20, $limit ) );
    $lines = array_slice( $lines, -$limit );
    $recs = array();
    foreach ( $lines as $line ) {
      $line = trim( (string) $line );
      if ( $line === '' ) { continue; }
      $j = json_decode( $line, true );
      if ( is_array( $j ) ) { $recs[] = $j; }
    }
    return $recs;
  }

  private static function tail_lines( $file, $n ) {
    $n = (int) $n;
    if ( $n < 1 ) { return array(); }
    $fp = @fopen( $file, 'rb' );
    if ( ! $fp ) { return array(); }
    $pos = -1;
    $lines = array();
    $buf = '';
    $chunk = 4096;
    @fseek( $fp, 0, SEEK_END );
    $size = (int) @ftell( $fp );

    while ( count( $lines ) <= $n && $size + $pos >= 0 ) {
      $step = min( $chunk, $size + $pos + 1 );
      @fseek( $fp, -$step, SEEK_CUR );
      $data = @fread( $fp, $step );
      @fseek( $fp, -$step, SEEK_CUR );
      if ( $data === false || $data === '' ) { break; }
      $buf = $data . $buf;
      $parts = explode( "\n", $buf );
      $buf = array_shift( $parts );
      $lines = array_merge( $parts, $lines );
      $pos -= $step;
    }

    @fclose( $fp );
    if ( $buf !== '' ) { array_unshift( $lines, $buf ); }
    return $lines;
  }
}
