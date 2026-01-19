<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST adapter (thin)
 *
 * @FLOW RESTAdapter
 * @INVARIANT No business logic here: this file only registers routes, checks permissions
 *            and delegates request handling to the Brain Kernel.
 *
 * This file intentionally stays small: it only registers routes and delegates
 * request handling to the Brain Kernel.
 */
class APAI_Brain_REST {

  /**
   * Attach standard observability headers to a REST response.
   *
   * @INVARIANT These headers are optional and must never break the contract.
   */
  private static function add_common_headers( $res, $route, $feature, $action, $trace_id = '' ) {
    try {
      if ( ! ( $res instanceof WP_REST_Response ) ) {
        return $res;
      }

      $route   = is_string( $route ) ? $route : '';
      $feature = is_string( $feature ) ? $feature : '';
      $action  = is_string( $action ) ? $action : '';
      $trace_id = is_string( $trace_id ) ? $trace_id : '';

      if ( $route !== '' ) { $res->header( 'X-APAI-Route', $route ); }
      if ( $feature !== '' ) { $res->header( 'X-APAI-Feature', $feature ); }
      if ( $action !== '' ) { $res->header( 'X-APAI-Action', $action ); }
      if ( $trace_id !== '' ) { $res->header( 'X-APAI-Trace-Id', $trace_id ); }
    } catch ( \Throwable $e ) {
      // Silent: never break REST.
    }
    return $res;
  }

  /**
   * Best-effort: create a trace_id and emit a single 'rest' trace event.
   */
  private static function start_rest_trace( $route, $feature, $action, $data = array() ) {
    $route   = is_string( $route ) ? $route : '';
    $feature = is_string( $feature ) ? $feature : '';
    $action  = is_string( $action ) ? $action : '';
    $data    = is_array( $data ) ? $data : array();

    if ( ! class_exists( 'APAI_Brain_Trace' ) || ! APAI_Brain_Trace::enabled() ) {
      return '';
    }

    $tid = APAI_Brain_Trace::new_trace_id();
    try {
      APAI_Brain_Trace::emit( $tid, 'rest', array_merge( array(
        'route'   => $route,
        'feature' => $feature,
        'action'  => $action,
      ), $data ) );
    } catch ( \Throwable $e ) {
      // Silent
    }
    return (string) $tid;
  }

  public static function init() {
    add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
  }

  public static function register_routes() {
    // IMPORTANT: keep the historical namespace used by the admin UI.
    // Some environments still call autoproduct-ai/v1, so we register both.
    $namespaces = array( 'apai-brain/v1', 'autoproduct-ai/v1' );
    foreach ( $namespaces as $ns ) {
      register_rest_route(
        $ns,
        '/chat',
        array(
          'methods'             => 'POST',
          'callback'            => array( __CLASS__, 'handle_chat' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

      // Admin-only: reset Brain optimized state (debug/testing helper)
      register_rest_route(
        $ns,
        '/reset',
        array(
          'methods'             => 'POST',
          'callback'            => array( __CLASS__, 'handle_reset' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

      // Admin-only: Brain debug payload (Context Lite / Full for humans).
      register_rest_route(
        $ns,
        '/debug',
        array(
          'methods'             => 'GET',
          'callback'            => array( __CLASS__, 'handle_debug' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

      // Admin-only: QA regression harness (read-only).
      register_rest_route(
        $ns,
        '/qa/run',
        array(
          'methods'             => 'GET',
          'callback'            => array( __CLASS__, 'handle_qa_run' ),
          // Cookie-auth REST calls require a nonce. If the user opens the URL directly in a tab
          // (without X-WP-Nonce / _wpnonce), WP treats the request as unauthenticated.
          // Provide a helpful error message for that common case.
          'permission_callback' => array( __CLASS__, 'permission_check_with_nonce_hint' ),
        )
      );

      // Admin-only: Regression summary over Telemetry JSONL (read-only).
      register_rest_route(
        $ns,
        '/qa/regression',
        array(
          'methods'             => 'GET',
          'callback'            => array( __CLASS__, 'handle_qa_regression' ),
          'permission_callback' => array( __CLASS__, 'permission_check_with_nonce_hint' ),
        )
      );

		
      // Admin-only: Health snapshot (read-only).
      register_rest_route(
        $ns,
        '/health',
        array(
          'methods'             => 'GET',
          'callback'            => array( __CLASS__, 'handle_health' ),
          'permission_callback' => array( __CLASS__, 'permission_check_with_nonce_hint' ),
        )
      );

// Admin-only: clear pending action (UI helper; keeps backwards compatibility with older admin builds).
      register_rest_route(
        $ns,
        '/pending/clear',
        array(
          'methods'             => 'POST',
          'callback'            => array( __CLASS__, 'handle_pending_clear' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

      // Admin-only: paginated product search for the target selector UI.
      register_rest_route(
        $ns,
        '/products/search',
        array(
          'methods'             => 'GET',
          'callback'            => array( __CLASS__, 'handle_product_search' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

      // Admin-only: product summary for action cards (thumbnail/title/price/categories).
      register_rest_route(
        $ns,
        '/products/summary',
        array(
          'methods'             => 'GET',
          'callback'            => array( __CLASS__, 'handle_product_summary' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

      // Admin-only: trace excerpt for the current conversation (copy button helper).
      register_rest_route(
        $ns,
        '/trace/excerpt',
        array(
          // "Copiar" uses fetch() without an explicit method in older builds (defaults to GET).
          // Newer builds post {trace_ids:[...]} to avoid spamming many requests.
          // Support both for backwards compatibility.
          'methods'             => array( 'GET', 'POST' ),
          'callback'            => array( __CLASS__, 'handle_trace_excerpt' ),
          'permission_callback' => array( __CLASS__, 'permission_check' ),
        )
      );

    }
  }

  public static function permission_check() {
    // Admin UI runs under an authenticated WP user.
    // Historical capability for WooCommerce managers is manage_woocommerce.
    // Keep manage_options as well for site admins.
    return ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
  }

  /**
   * Same as permission_check(), but returns a more helpful message when the request is
   * unauthenticated due to missing REST nonce.
   *
   * @INVARIANT Must never weaken auth: still requires admin capability.
   */
  public static function permission_check_with_nonce_hint() {
    if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
      return true;
    }

    // Common case: admin opens /wp-json/... directly in a tab. WP cookie-auth needs X-WP-Nonce
    // or ?_wpnonce=... so the request is treated as authenticated.
    return new WP_Error(
      'rest_forbidden',
      'Lo siento, no tenés permisos para hacer eso. Tip: abrilo desde el panel admin o usá un link con ?_wpnonce=... (REST nonce).',
      array( 'status' => 401 )
    );
  }

  public static function handle_chat( WP_REST_Request $request ) {
    // Kernel is the single entry-point for the Brain.
    if ( class_exists( 'APAI_Brain_Kernel' ) ) {
      return APAI_Brain_Kernel::handle_chat( $request );
    }

    // Defensive fallback (should never happen)
    return new WP_REST_Response(
      array(
        'ok'   => false,
        'text' => 'Error: Kernel no disponible.',
      ),
      500
    );
  }

  /**
   * Brain debug endpoint (admin-only).
   *
   * GET params:
   * - level=lite|full (default lite)
   */
  public static function handle_debug( WP_REST_Request $request ) {
    $level = (string) $request->get_param( 'level' );
    $level = strtolower( trim( $level ) );
    if ( $level !== 'full' && $level !== 'lite' ) {
      $level = 'lite';
    }

    $trace_id = self::start_rest_trace( 'debug', 'brain', 'debug', array( 'level' => $level ) );

    $store_state = array();
    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
      try {
        $store_state = APAI_Brain_Memory_Store::get();
      } catch ( \Throwable $e ) {
        $store_state = array( 'error' => 'store_state_exception', 'message' => $e->getMessage() );
      }
    }

    // Context Lite is the ONLY context the model ever sees.
    $context_lite = array();
    $context_lite_json = '';
    if ( class_exists( 'APAI_Brain_Context_Lite' ) ) {
      try {
        $context_lite = APAI_Brain_Context_Lite::build( $store_state );
        $context_lite_json = APAI_Brain_Context_Lite::to_json( $context_lite );
      } catch ( \Throwable $e ) {
        $context_lite = array( 'error' => 'context_lite_exception', 'message' => $e->getMessage() );
        $context_lite_json = '';
      }
    }

    // Context Full is for humans only.
    $context_full = null;
    $context_full_json = '';
    if ( $level === 'full' && class_exists( 'APAI_Brain_Context_Full' ) ) {
      try {
        $context_full = APAI_Brain_Context_Full::build( $store_state, array( 'top_limit' => 3 ) );
        $context_full_json = APAI_Brain_Context_Full::to_json( $context_full );
      } catch ( \Throwable $e ) {
        $context_full = array( 'error' => 'context_full_exception', 'message' => $e->getMessage() );
        $context_full_json = '';
      }
    }

    // Extra sanity info (best effort, read-only)
    $counts = array();
    try {
      global $wpdb;
      if ( isset( $wpdb ) && $wpdb ) {
        $sql = "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'";
        $counts['sql_products_publish'] = (int) $wpdb->get_var( $sql );
      }
    } catch ( \Throwable $e ) {}

    $resp_payload = array(
      'ok'    => true,
      'level' => $level,
      'meta'  => ( $trace_id !== '' ) ? array( 'trace_id' => $trace_id ) : array(),
      'sizes' => array(
        'context_lite_chars' => is_string( $context_lite_json ) ? strlen( $context_lite_json ) : 0,
        'context_full_chars' => is_string( $context_full_json ) ? strlen( $context_full_json ) : 0,
      ),
      'counts' => $counts,
      'store_state'  => $store_state,
      'context_lite' => $context_lite,
    );

    if ( $level === 'full' ) {
      $resp_payload['context_full'] = $context_full;
    }

    $res = new WP_REST_Response( $resp_payload, 200 );
    return self::add_common_headers( $res, 'debug', 'brain', 'debug', $trace_id );
  }

  

  /**
   * Brain QA regression harness (admin-only).
   *
   * GET params:
   * - verbose=1 (optional)
   * - quick=1 (optional)
   *
   * @INVARIANT Read-only: this endpoint must not execute WC actions or mutate store_state.
   */
  public static function handle_qa_run( WP_REST_Request $request ) {
    $verbose = (string) $request->get_param( 'verbose' );
    $quick   = (string) $request->get_param( 'quick' );

    $opts = array(
      'verbose' => ( $verbose === '1' || strtolower( $verbose ) === 'true' ),
      'quick'   => ! ( $quick === '0' || strtolower( $quick ) === 'false' ),
    );

    if ( class_exists( 'APAI_Brain_QA_Harness' ) ) {
      $report = APAI_Brain_QA_Harness::run( $opts );
    } else {
      $report = array(
        'ok'     => false,
        'meta'   => array( 'error' => 'qa_harness_missing' ),
        'checks' => array(),
      );
    }

    return new WP_REST_Response( $report, 200 );
  }

  /**
   * F6.7 — Regression harness summary (read-only).
   *
   * GET /autoproduct-ai/v1/qa/regression?limit=200
   */
  public static function handle_qa_regression( WP_REST_Request $request ) {
    $limit = absint( $request->get_param( 'limit' ) );
    if ( $limit <= 0 ) { $limit = 200; }
    if ( $limit > 2000 ) { $limit = 2000; }

    if ( ! class_exists( 'APAI_Brain_Regression_Harness' ) ) {
      return new WP_REST_Response(
        array(
          'ok'       => false,
          'error'    => 'regression_harness_missing',
          'meta'     => array(),
          'metrics'  => array(),
          'failures' => array(),
        ),
        200
      );
    }

    $since_ts = absint( $request->get_param( 'since_ts' ) );
    $minutes  = absint( $request->get_param( 'minutes' ) );
    if ( $minutes > 0 ) {
      $since_ts = time() - ( $minutes * 60 );
    }

    $report = APAI_Brain_Regression_Harness::run(
      array(
        'limit'    => $limit,
        'since_ts' => $since_ts,
      )
    );
    return new WP_REST_Response( $report, 200 );
  }

  /**
   * Admin-only: Health snapshot (read-only).
   *
   * @FLOW Observability
   */
  public static function handle_health( WP_REST_Request $request ) {
    $limit = (int) $request->get_param( 'limit' );
    if ( $limit <= 0 ) { $limit = 200; }

    $payload = array(
      'ok' => true,
      'meta' => array(
        'time' => gmdate( 'c' ),
        'http_status' => 200,
      ),
      'health' => array(),
    );

    if ( class_exists( 'APAI_Brain_Health' ) ) {
      $payload = APAI_Brain_Health::compute( array( 'limit' => $limit ) );
    } else {
      $payload['ok'] = false;
      $payload['health'] = array( 'error' => 'health_service_missing' );
    }

    $res = new WP_REST_Response( $payload, 200 );
    return self::add_common_headers( $res, 'health', 'brain', 'health' );
  }


public static function handle_reset( WP_REST_Request $request ) {
    // OBS: traceable reset endpoint (admin only).
    $trace_id = class_exists( 'APAI_Brain_Trace' ) ? APAI_Brain_Trace::new_trace_id() : '';
    if ( $trace_id !== '' && class_exists( 'APAI_Brain_Trace' ) ) {
      APAI_Brain_Trace::set_current_trace_id( $trace_id );
      APAI_Brain_Trace::emit_current( 'reset', array( 'route' => 'reset' ) );
    }

    if ( class_exists( 'APAI_Brain_Memory_Store' ) ) {
      APAI_Brain_Memory_Store::clear_all();
    }

    $res = new WP_REST_Response(
      array(
        'ok'   => true,
        'text' => 'Reset the optimized data successfully.',
      ),
      200
    );

    if ( $trace_id !== '' ) {
      $res->header( 'X-APAI-Trace-Id', $trace_id );
    }
    $res->header( 'X-APAI-Route', 'reset' );
    $res->header( 'X-APAI-Feature', 'brain' );
    $res->header( 'X-APAI-Action', 'reset' );

    return $res;
  }

  
  /**
   * Admin-only helper used by the admin UI to clear any pending action.
   * This endpoint exists mainly for compatibility with earlier UI builds.
   */
  public static function handle_pending_clear( WP_REST_Request $request ) {
    if ( ! class_exists( 'APAI_Brain_Memory_Store' ) ) {
      return new WP_REST_Response( array( 'ok' => false, 'error' => 'MemoryStore no disponible.' ), 500 );
    }

    // OBS: this endpoint is called by the UI after confirm/cancel; give it a trace_id too.
    $trace_id = class_exists( 'APAI_Brain_Trace' ) ? APAI_Brain_Trace::new_trace_id() : '';
    if ( $trace_id !== '' && class_exists( 'APAI_Brain_Trace' ) ) {
      APAI_Brain_Trace::set_current_trace_id( $trace_id );
      APAI_Brain_Trace::emit_current( 'route', array( 'route' => 'pending_clear' ) );
    }

    $state = APAI_Brain_Memory_Store::get();
    $penv  = APAI_Brain_Memory_Store::extract_pending_action_from_store( $state );
    $pending_action = ( is_array( $penv ) && isset( $penv['action'] ) && is_array( $penv['action'] ) ) ? $penv['action'] : null;

    // Persist last_action_executed_* when the executor confirms an action.
    // Expected body: { executed:true, summary:"...", ts:1234567890, kind:"price|stock", noop:true|false }
    $executed_param = $request->get_param( 'executed' );
    $executed = ( $executed_param === true || $executed_param === 1 || $executed_param === '1' || $executed_param === 'true' );

    $noop_param = $request->get_param( 'noop' );
    $noop = ( $noop_param === true || $noop_param === 1 || $noop_param === '1' || $noop_param === 'true' );

    if ( $executed ) {
      $summary = $request->get_param( 'summary' );
      $summary = is_string( $summary ) ? trim( $summary ) : '';
      if ( $summary === '' && is_array( $pending_action ) && isset( $pending_action['human_summary'] ) ) {
        $summary = (string) $pending_action['human_summary'];
      }

      $ts = $request->get_param( 'ts' );
      $ts = is_numeric( $ts ) ? intval( $ts ) : time();
      if ( $ts <= 0 ) { $ts = time(); }

      $kind = $request->get_param( 'kind' );
      $kind = is_string( $kind ) ? sanitize_text_field( $kind ) : '';
      if ( $kind !== 'price' && $kind !== 'stock' ) {
        $kind = '';
      }
      if ( $kind === '' && is_array( $pending_action ) && isset( $pending_action['changes'] ) && is_array( $pending_action['changes'] ) ) {
        if ( array_key_exists( 'regular_price', $pending_action['changes'] ) ) {
          $kind = 'price';
        } elseif ( array_key_exists( 'stock_quantity', $pending_action['changes'] ) ) {
          $kind = 'stock';
        }
      }

      // Extract product_id from pending (for last_target_product_id).
      $pid = 0;
      if ( is_array( $pending_action ) ) {
        if ( isset( $pending_action['product_id'] ) ) {
          $pid = intval( $pending_action['product_id'] );
        } elseif ( isset( $pending_action['target'] ) && is_array( $pending_action['target'] ) && isset( $pending_action['target']['product_id'] ) ) {
          $pid = intval( $pending_action['target']['product_id'] );
        } elseif ( isset( $pending_action['product'] ) && is_array( $pending_action['product'] ) && isset( $pending_action['product']['id'] ) ) {
          $pid = intval( $pending_action['product']['id'] );
        }
      }

      $patch = array(
        'last_action_executed_at'         => $ts,
        'last_action_executed_summary'    => ( $summary !== '' ? $summary : null ),
        'last_action_executed_kind'       => ( $kind !== '' ? $kind : null ),
        'last_target_product_id'          => ( $pid > 0 ? $pid : null ),
      );
      if ( $kind !== '' ) {
        // Keep legacy field updated too, per DoD.
        $patch['last_action_kind'] = $kind;
      }

      APAI_Brain_Memory_Store::patch( $patch );

      // OBS: semantic audit event
      if ( class_exists( 'APAI_Brain_Trace' ) ) {
        $s = $summary;
        if ( is_string( $s ) && strlen( $s ) > 160 ) { $s = substr( $s, 0, 160 ) . '…'; }
        APAI_Brain_Trace::emit_current( 'pending_executed', array(
          'noop'      => $noop,
          'kind'      => $kind,
          'product_id'=> ( $pid > 0 ? $pid : null ),
          'summary'   => $s,
        ) );
      }
    } else {
      // Cancel / clear without execution.
      if ( class_exists( 'APAI_Brain_Trace' ) ) {
        APAI_Brain_Trace::emit_current( 'pending_cancelled', array( 'reason' => 'ui_clear' ) );
      }
    }

    // Always clear pending_action.
    APAI_Brain_Memory_Store::clear_pending( $executed ? 'executed' : 'cancelled' );

    $res = new WP_REST_Response(
      array(
        'ok'   => true,
        'text' => 'Pending cleared.',
      ),
      200
    );

    if ( $trace_id !== '' ) {
      $res->header( 'X-APAI-Trace-Id', $trace_id );
    }
    $res->header( 'X-APAI-Route', 'pending_clear' );
    $res->header( 'X-APAI-Feature', 'brain' );
    $res->header( 'X-APAI-Action', 'pending_clear' );

    return $res;
  }



  public static function handle_product_search( WP_REST_Request $request ) {
    $q = (string) $request->get_param( 'q' );
    $limit = (int) $request->get_param( 'limit' );
    $offset = (int) $request->get_param( 'offset' );

    if ( $limit <= 0 ) { $limit = 20; }
    if ( $limit > 100 ) { $limit = 100; }
    if ( $offset < 0 ) { $offset = 0; }



    $trace_id = self::start_rest_trace( 'products_search', 'brain', 'product_search', array(
      'q'      => $q,
      'limit'  => $limit,
      'offset' => $offset,
      'mode'   => ( $offset > 0 ) ? 'more' : 'reset',
    ) );
    // Additional, selector-specific event (used by UI "Cargar más" / Buscar) so it appears in trace.log.
    if ( $trace_id !== '' && class_exists( 'APAI_Brain_Trace' ) ) {
      APAI_Brain_Trace::emit(
        $trace_id,
        'selector_search',
        array(
          'q'      => $q,
          'limit'  => $limit,
          'offset' => $offset,
          'mode'   => ( $offset > 0 ) ? 'more' : 'reset',
        )
      );
    }
    if ( ! class_exists( 'APAI_Brain_Product_Search' ) ) {
      $res = new WP_REST_Response(
        array( 'ok' => false, 'error' => 'ProductSearch no disponible.' ),
        500
      );
      return self::add_common_headers( $res, 'products_search', 'brain', 'product_search', $trace_id );
    }

    $data = APAI_Brain_Product_Search::search_by_title_like( $q, $limit, $offset );
    $res = new WP_REST_Response(
      array(
        'ok'    => true,
        'query' => $q,
        'total' => isset( $data['total'] ) ? (int) $data['total'] : 0,
        'items' => isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array(),
        'limit' => $limit,
        'offset' => $offset,
        'meta'  => ( $trace_id !== '' ) ? array( 'trace_id' => $trace_id ) : array(),
      ),
      200
    );
    return self::add_common_headers( $res, 'products_search', 'brain', 'product_search', $trace_id );
  }


  public static function handle_product_summary( WP_REST_Request $request ) {
    $trace_id = self::start_rest_trace( 'products_summary', 'brain', 'product_summary', array(
      'id' => (int) $request->get_param( 'id' ),
    ) );
    $id = (int) $request->get_param( 'id' );
    if ( $id <= 0 ) {
      $res = new WP_REST_Response( array( 'ok' => false, 'error' => 'ID inválido.' ), 400 );
      return self::add_common_headers( $res, 'products_summary', 'brain', 'product_summary', $trace_id );
    }

    if ( ! function_exists( 'wc_get_product' ) ) {
      $res = new WP_REST_Response( array( 'ok' => false, 'error' => 'WooCommerce no disponible.' ), 500 );
      return self::add_common_headers( $res, 'products_summary', 'brain', 'product_summary', $trace_id );
    }

    $p = wc_get_product( $id );
    if ( ! $p ) {
      $res = new WP_REST_Response( array( 'ok' => false, 'error' => 'Producto no encontrado.' ), 404 );
      return self::add_common_headers( $res, 'products_summary', 'brain', 'product_summary', $trace_id );
    }

    $thumb_url = '';
    try {
      $img_id = (int) $p->get_image_id();
      if ( $img_id > 0 ) {
        $thumb_url = (string) wp_get_attachment_image_url( $img_id, 'thumbnail' );
      }
    } catch ( Exception $e ) {}

    $cats = array();
    try {
      $names = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
      if ( is_array( $names ) ) {
        $cats = array_values( array_filter( array_map( 'strval', $names ) ) );
      }
    } catch ( Exception $e ) {}

    $price = '';
    try {
      $price = (string) $p->get_price();
    } catch ( Exception $e ) {}

    $title = '';
    try {
      $title = (string) $p->get_name();
    } catch ( Exception $e ) {}

    $res = new WP_REST_Response(
      array(
        'ok'      => true,
        'product' => array(
          'id'         => $id,
          'title'      => $title,
          'price'      => $price,
          'thumb_url'  => $thumb_url,
          'categories' => $cats,
        ),
      ),
      200
    );
    if ( $trace_id !== '' ) {
      $payload = $res->get_data();
      if ( is_array( $payload ) ) {
        $payload['meta'] = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
        $payload['meta']['trace_id'] = $trace_id;
        $res->set_data( $payload );
      }
    }
    return self::add_common_headers( $res, 'products_summary', 'brain', 'product_summary', $trace_id );
  }


  public static function handle_trace_excerpt( WP_REST_Request $request ) {
    // Accept both:
    // 1) POST JSON body: {trace_ids:[...], max_lines:n}
    // 2) GET querystring: ?trace_id=...&max_lines=n
    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
      $body = array();
    }

    $trace_ids = isset( $body['trace_ids'] ) ? $body['trace_ids'] : array();
    if ( empty( $trace_ids ) ) {
      // Backwards compatible single id.
      $single = $request->get_param( 'trace_id' );
      if ( is_string( $single ) && $single !== '' ) {
        $trace_ids = array( $single );
      }
    }

    if ( ! is_array( $trace_ids ) ) {
      $trace_ids = array( (string) $trace_ids );
    }
    $trace_ids = array_values( array_unique( array_filter( array_map( 'strval', $trace_ids ) ) ) );

    // Keep it safe: avoid huge payloads.
    if ( count( $trace_ids ) > 200 ) {
      $trace_ids = array_slice( $trace_ids, 0, 200 );
    }

    $max_lines = 400;
    $ml_body = isset( $body['max_lines'] ) ? $body['max_lines'] : null;
    $ml_qs   = $request->get_param( 'max_lines' );
    if ( $ml_body !== null ) {
      $max_lines = (int) $ml_body;
    } elseif ( $ml_qs !== null ) {
      $max_lines = (int) $ml_qs;
    }
    $max_lines = max( 1, min( 1000, $max_lines ) );

    // Optional: scan budget for tail-mode (bytes). Keeps excerpt fast on large logs.
    $max_bytes = null;
    $mb_body = isset( $body['max_bytes'] ) ? $body['max_bytes'] : null;
    $mb_qs   = $request->get_param( 'max_bytes' );
    if ( $mb_body !== null ) {
      $max_bytes = (int) $mb_body;
    } elseif ( $mb_qs !== null ) {
      $max_bytes = (int) $mb_qs;
    }

    $trace_id = self::start_rest_trace( 'trace_excerpt', 'brain', 'trace_excerpt', array(
      'trace_ids_count' => is_array( $trace_ids ) ? count( $trace_ids ) : 0,
      'max_lines'       => (int) $max_lines,
      'max_bytes'       => ( $max_bytes !== null ? (int) $max_bytes : null ),
    ) );

    if ( empty( $trace_ids ) ) {
      $r = new WP_REST_Response( array( 'ok' => false, 'error' => 'trace_id/trace_ids requerido.' ), 400 );
      return self::add_common_headers( $r, 'trace_excerpt', 'brain', 'trace_excerpt', $trace_id );
    }

    if ( ! class_exists( 'APAI_Brain_Trace' ) ) {
      $r = new WP_REST_Response( array( 'ok' => false, 'error' => 'Trace no disponible.' ), 500 );
      return self::add_common_headers( $r, 'trace_excerpt', 'brain', 'trace_excerpt', $trace_id );
    }

    $res = APAI_Brain_Trace::excerpt_by_trace_ids( $trace_ids, $max_lines, $max_bytes );

    // Convenience: also provide a single string excerpt for the admin UI.
    if ( isset( $res['lines'] ) && is_array( $res['lines'] ) ) {
      $res['excerpt'] = implode( "\n", $res['lines'] );
    } else {
      $res['excerpt'] = '';
    }
    $res['trace_ids_requested'] = $trace_ids;
    $res['meta'] = isset( $res['meta'] ) && is_array( $res['meta'] ) ? $res['meta'] : array();
    if ( $trace_id !== '' ) {
      $res['meta']['trace_id'] = $trace_id;
    }

    $r = new WP_REST_Response( $res, 200 );
    return self::add_common_headers( $r, 'trace_excerpt', 'brain', 'trace_excerpt', $trace_id );
  }
}

