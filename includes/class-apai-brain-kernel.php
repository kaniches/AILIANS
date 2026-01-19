<?php

/**
 * Brain Kernel — Chat Orchestrator (F5a)
 *
 * @FLOW BrainKernel
 * @INVARIANT No business logic here (no rules about pending, queries, actions).
 *            This is plumbing only: trace_id, telemetry best-effort, pipeline invocation.
 *
 * Goal: make the REST adapter thin.
 *
 * Kernel responsibilities (ONLY):
 * - Attach trace id + log the incoming message (no behaviour changes)
 * - Invoke the Brain Pipeline
 * - Return the pipeline response
 *
 * NOT allowed here:
 * - Business logic
 * - Pending rules
 * - Query implementations
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Brain_Kernel {

    /**
     * Handle the admin chat request.
     *
     * @INVARIANT: Must behave identically to the previous APAI_Brain_REST::handle_chat.
     */
    public static function handle_chat( WP_REST_Request $request ) {
        // TRACE (F4): log every request in a single place, without changing behaviour.
        require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-trace.php';
        $trace_id = APAI_Brain_Trace::new_trace_id();
        APAI_Brain_Trace::set_current_trace_id( $trace_id );

        // Store on the request so the pipeline can attach route events.
        if ( method_exists( $request, 'set_param' ) ) {
            $request->set_param( '_apai_trace_id', $trace_id );
        }

        $p = $request->get_json_params();
        $msg = ( is_array( $p ) && isset( $p['message'] ) ) ? sanitize_textarea_field( $p['message'] ) : '';
        APAI_Brain_Trace::emit( $trace_id, 'request', array(
            'message' => $msg,
            'level'   => ( is_array( $p ) && isset( $p['level'] ) ) ? sanitize_text_field( $p['level'] ) : '',
        ) );

        require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-pipeline.php';
        $pipeline = new APAI_Brain_Pipeline();
        // Legacy routing is disabled. All chat must be handled by pipeline flows.
        $resp = $pipeline->run( $request );

        // Ensure trace_id is always visible to the admin UI (copy trace feature).
        // We attach it both as a response header and inside the JSON payload.
        $tid = APAI_Brain_Trace::current_trace_id();
        if ( empty( $tid ) ) {
            $tid = $trace_id;
        }

        // F6.5 Telemetría / dataset (JSONL en uploads). No modifica la respuesta.
        // Importante: debe ser best-effort (nunca rompe la request si falla).
        $telemetry_level = ( is_array( $p ) && isset( $p['level'] ) ) ? sanitize_text_field( $p['level'] ) : '';
        $telemetry_events = array();
        if ( class_exists( 'APAI_Brain_Trace' ) && method_exists( 'APAI_Brain_Trace', 'buffer_get' ) ) {
            $telemetry_events = APAI_Brain_Trace::buffer_get( $tid );
        }

        if ( $resp instanceof WP_REST_Response ) {
            try {
                $data = $resp->get_data();
                if ( is_array( $data ) ) {
                    if ( ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
                        $data['meta'] = array();
                    }
                    $data['meta']['trace_id'] = $tid;
                    $data['trace_id'] = $tid;
                    $resp->set_data( $data );
                }
            } catch ( Exception $e ) {
                // ignore
            }

            if ( class_exists( 'APAI_Brain_Telemetry' ) ) {
                try {
                    $data = $resp->get_data();
                    if ( is_array( $data ) ) {
                        APAI_Brain_Telemetry::record_interaction( $tid, $msg, $telemetry_level, $data, $telemetry_events );
                    }
                } catch ( Exception $e ) {
                    // ignore
                }
            }
            if ( class_exists( 'APAI_Brain_Trace' ) && method_exists( 'APAI_Brain_Trace', 'buffer_clear' ) ) {
                APAI_Brain_Trace::buffer_clear( $tid );
            }

            $resp->header( 'X-APAI-Trace-Id', $tid );
            return $resp;
        }

        if ( is_array( $resp ) ) {
            if ( ! isset( $resp['meta'] ) || ! is_array( $resp['meta'] ) ) {
                $resp['meta'] = array();
            }
            $resp['meta']['trace_id'] = $tid;
            $resp['trace_id'] = $tid;

            if ( class_exists( 'APAI_Brain_Telemetry' ) ) {
                try {
                    APAI_Brain_Telemetry::record_interaction( $tid, $msg, $telemetry_level, $resp, $telemetry_events );
                } catch ( Exception $e ) {
                    // ignore
                }
            }
            if ( class_exists( 'APAI_Brain_Trace' ) && method_exists( 'APAI_Brain_Trace', 'buffer_clear' ) ) {
                APAI_Brain_Trace::buffer_clear( $tid );
            }
        }

        return $resp;
    }
}
