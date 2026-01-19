<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST Observability glue.
 *
 * @FLOW Observability
 * @INVARIANT Must never change REST payload contracts. Only adds optional headers
 *            (and only for our namespaces) as a best-effort signal.
 *
 * Why this exists:
 * - Some hosting stacks / proxies can behave inconsistently with custom headers.
 * - We already add X-APAI-* headers in handlers; this is a safety net to ensure
 *   they are present at the very end of the REST lifecycle.
 */
class APAI_Brain_REST_Observability {

  public static function init() {
    // Make custom headers readable to JS in cross-origin scenarios (harmless for same-origin).
    add_filter( 'rest_exposed_cors_headers', array( __CLASS__, 'expose_headers' ), 10, 1 );

    // Last-chance header injection for our routes.
    add_filter( 'rest_post_dispatch', array( __CLASS__, 'post_dispatch' ), 10, 3 );
  }

  /**
   * Add our headers to the CORS exposed list.
   */
  public static function expose_headers( $headers ) {
    if ( ! is_array( $headers ) ) { $headers = array(); }
    $add = array( 'X-APAI-Trace-Id', 'X-APAI-Route', 'X-APAI-Feature', 'X-APAI-Action' );
    foreach ( $add as $h ) {
      if ( ! in_array( $h, $headers, true ) ) {
        $headers[] = $h;
      }
    }
    return $headers;
  }

  private static function header_get_ci( $headers, $name ) {
    if ( ! is_array( $headers ) ) { return null; }
    $n = strtolower( (string) $name );
    foreach ( $headers as $k => $v ) {
      if ( strtolower( (string) $k ) === $n ) {
        return $v;
      }
    }
    return null;
  }

  private static function is_our_route( $route ) {
    $route = (string) $route;
    return ( strpos( $route, '/apai-brain/v1/' ) === 0 || strpos( $route, '/autoproduct-ai/v1/' ) === 0 );
  }

  /**
   * Infer a stable (human) action label from the route.
   * This is only used as a fallback when handler-level headers didn't attach.
   */
  private static function infer_meta_from_route( $route ) {
    $route = (string) $route;

    $meta = array(
      'route'   => $route,
      'feature' => 'brain',
      'action'  => '',
    );

    if ( strpos( $route, '/chat' ) !== false ) {
      $meta['action'] = 'chat';
    } elseif ( strpos( $route, '/debug' ) !== false ) {
      $meta['action'] = 'debug';
    } elseif ( strpos( $route, '/qa/run' ) !== false ) {
      $meta['action'] = 'qa_run';
    } elseif ( strpos( $route, '/products/search' ) !== false ) {
      $meta['action'] = 'product_search';
    } elseif ( strpos( $route, '/products/summary' ) !== false ) {
      $meta['action'] = 'product_summary';
    } elseif ( strpos( $route, '/trace/excerpt' ) !== false ) {
      $meta['action'] = 'trace_excerpt';
    } elseif ( strpos( $route, '/pending/clear' ) !== false ) {
      $meta['action'] = 'pending_clear';
    } elseif ( strpos( $route, '/reset' ) !== false ) {
      $meta['action'] = 'reset';
    }

    return $meta;
  }

  /**
   * Final REST hook: guarantee headers for our endpoints (best effort).
   *
   * @INVARIANT Must never change $result payload/shape.
   */
  public static function post_dispatch( $result, $server, $request ) {
    try {
      if ( ! ( $result instanceof WP_REST_Response ) ) { return $result; }
      if ( ! ( $request instanceof WP_REST_Request ) ) { return $result; }

      $route = (string) $request->get_route();
      if ( $route === '' || ! self::is_our_route( $route ) ) {
        return $result;
      }

      $headers = $result->get_headers();
      $meta = self::infer_meta_from_route( $route );

      // Trace ID fallback: use response payload meta.trace_id if available.
      $trace_id = (string) self::header_get_ci( $headers, 'X-APAI-Trace-Id' );
      if ( $trace_id === '' ) {
        $payload = $result->get_data();
        if ( is_array( $payload ) && isset( $payload['meta'] ) && is_array( $payload['meta'] ) && isset( $payload['meta']['trace_id'] ) ) {
          $trace_id = (string) $payload['meta']['trace_id'];
        }
      }
      if ( $trace_id === '' && class_exists( 'APAI_Brain_Trace' ) ) {
        // Best-effort only: do not emit extra events here.
        $trace_id = (string) APAI_Brain_Trace::new_trace_id();
      }

      if ( self::header_get_ci( $headers, 'X-APAI-Trace-Id' ) === null && $trace_id !== '' ) {
        $result->header( 'X-APAI-Trace-Id', $trace_id );
      }
      if ( self::header_get_ci( $headers, 'X-APAI-Route' ) === null && $meta['route'] !== '' ) {
        $result->header( 'X-APAI-Route', $meta['route'] );
      }
      if ( self::header_get_ci( $headers, 'X-APAI-Feature' ) === null ) {
        $result->header( 'X-APAI-Feature', $meta['feature'] );
      }
      if ( self::header_get_ci( $headers, 'X-APAI-Action' ) === null && $meta['action'] !== '' ) {
        $result->header( 'X-APAI-Action', $meta['action'] );
      }
    } catch ( \Throwable $e ) {
      // Silent: observability must never break REST.
    }

    return $result;
  }
}
