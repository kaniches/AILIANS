<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * F6.5 Telemetría / Dataset (JSONL)
 *
 * Objetivo: guardar por interacción un registro auditable en uploads,
 * sin afectar el comportamiento del chat.
 *
 * - Se habilita/deshabilita desde Admin (AutoProduct AI → Brain Telemetry)
 * - "Best effort": nunca debe romper requests.
 * - NO se envía Context Full al modelo. Este log existe solo para auditoría humana.
 */
class APAI_Brain_Telemetry {

    const OPT_ENABLED = 'apai_brain_telemetry_enabled';

    /**
     * @return bool
     */
    public static function enabled() {
        try {
            if ( defined( 'APAI_BRAIN_TELEMETRY' ) ) {
                return (bool) APAI_BRAIN_TELEMETRY;
            }
            if ( function_exists( 'get_option' ) ) {
                return (bool) get_option( self::OPT_ENABLED, false );
            }
        } catch ( \Throwable $e ) {
            return false;
        }
        return false;
    }

    /**
     * @return array{dir:string,file:string}
     */
    private static function paths_for_today() {
        $base = '';
        if ( function_exists( 'wp_upload_dir' ) ) {
            $u = wp_upload_dir();
            if ( is_array( $u ) && ! empty( $u['basedir'] ) ) {
                $base = (string) $u['basedir'];
            }
        }
        if ( $base === '' && defined( 'WP_CONTENT_DIR' ) ) {
            $base = trailingslashit( WP_CONTENT_DIR ) . 'uploads';
        }

        if ( $base === '' ) {
            return array( 'dir' => '', 'file' => '' );
        }

        $dir  = trailingslashit( $base ) . 'autoproduct-ai' . DIRECTORY_SEPARATOR . 'telemetry';
        $file = trailingslashit( $dir ) . 'brain-telemetry-' . gmdate( 'Y-m-d' ) . '.jsonl';
        return array( 'dir' => $dir, 'file' => $file );
    }

    /**
     * @return string
     */
    public static function dir_path() {
        $p = self::paths_for_today();
        return (string) $p['dir'];
    }

    /**
     * @return string
     */
    public static function todays_file_path() {
        $p = self::paths_for_today();
        return (string) $p['file'];
    }

    /**
     * @return string Last modified telemetry file (or today's path if none)
     */
    public static function latest_file_path() {
        try {
            $dir = self::dir_path();
            if ( $dir === '' || ! @is_dir( $dir ) ) {
                return self::todays_file_path();
            }
            $files = @glob( trailingslashit( $dir ) . 'brain-telemetry-*.jsonl' );
            if ( ! is_array( $files ) || empty( $files ) ) {
                return self::todays_file_path();
            }
            $best = '';
            $best_m = 0;
            foreach ( $files as $f ) {
                $m = @filemtime( $f );
                if ( $m && $m >= $best_m ) {
                    $best_m = (int) $m;
                    $best   = (string) $f;
                }
            }
            return $best !== '' ? $best : self::todays_file_path();
        } catch ( \Throwable $e ) {
            return self::todays_file_path();
        }
    }

    /**
     * Best effort: ensure dir exists.
     *
     * @param string $dir
     * @return bool
     */
    private static function ensure_dir( $dir ) {
        if ( $dir === '' ) {
            return false;
        }
        if ( @is_dir( $dir ) ) {
            return true;
        }
        if ( function_exists( 'wp_mkdir_p' ) ) {
            return (bool) @wp_mkdir_p( $dir );
        }
        return (bool) @mkdir( $dir, 0755, true );
    }

    /**
     * Write one JSONL line.
     *
     * @param array $record
     * @return void
     */
    private static function write_line( $record ) {
        try {
            $paths = self::paths_for_today();
            if ( empty( $paths['file'] ) ) {
                return;
            }
            self::ensure_dir( $paths['dir'] );

            $json = @json_encode( $record, JSON_UNESCAPED_UNICODE );
            if ( ! is_string( $json ) ) {
                return;
            }
            @file_put_contents( $paths['file'], $json . "\n", FILE_APPEND | LOCK_EX );
        } catch ( \Throwable $e ) {
            // swallow
        }
    }

    /**
     * Record a single interaction (one request/one response).
     *
     * @param string $trace_id
     * @param string $message_raw
     * @param string $level
     * @param array  $response_data
     * @param array  $trace_events
     * @return void
     */
    public static function record_interaction( $trace_id, $message_raw, $level, $response_data, $trace_events = array() ) {
        if ( ! self::enabled() ) {
            return;
        }

        try {
            $trace_id    = (string) $trace_id;
            $message_raw = is_string( $message_raw ) ? $message_raw : '';
            $level       = is_string( $level ) ? $level : '';

            // Normalize/sanitize message (do not store HTML)
            $msg = wp_strip_all_tags( $message_raw );
            $msg = trim( preg_replace( '/\s+/', ' ', $msg ) );
            if ( strlen( $msg ) > 2000 ) {
                $msg = substr( $msg, 0, 2000 );
            }

            $route      = '';
            $message_nm = '';
            $intent     = null;

            if ( is_array( $trace_events ) ) {
                foreach ( array_reverse( $trace_events ) as $ev ) {
                    if ( ! is_array( $ev ) || empty( $ev['event'] ) ) {
                        continue;
                    }
                    if ( $route === '' && $ev['event'] === 'route' && ! empty( $ev['data'] ) && is_array( $ev['data'] ) ) {
                        $route      = isset( $ev['data']['route'] ) ? (string) $ev['data']['route'] : '';
                        $message_nm = isset( $ev['data']['message_norm'] ) ? (string) $ev['data']['message_norm'] : '';
                    }
                    if ( $intent === null && $ev['event'] === 'intent_parse' && ! empty( $ev['data'] ) && is_array( $ev['data'] ) ) {
                        $intent = $ev['data'];
                    }
                    if ( $route !== '' && $intent !== null ) {
                        break;
                    }
                }
            }

            if ( strlen( $message_nm ) > 2000 ) {
                $message_nm = substr( $message_nm, 0, 2000 );
            }

            // Keep a small response excerpt (do not dump Full context)
            $resp_excerpt = array();
            if ( is_array( $response_data ) ) {
                if ( isset( $response_data['message'] ) ) {
                    $resp_excerpt['message'] = wp_strip_all_tags( (string) $response_data['message'] );
                }
                // UI is primarily driven by `actions` (preferred). Some old UIs used `cards`.
                $actions_count = isset( $response_data['actions'] ) && is_array( $response_data['actions'] ) ? count( $response_data['actions'] ) : 0;
                $cards_count   = isset( $response_data['cards'] ) && is_array( $response_data['cards'] ) ? count( $response_data['cards'] ) : 0;
                $resp_excerpt['cards_count'] = max( $actions_count, $cards_count );
                if ( isset( $response_data['store_state'] ) && is_array( $response_data['store_state'] ) ) {
                    $ss = $response_data['store_state'];
                    $resp_excerpt['store_state'] = array(
                        'has_pending_action' => ! empty( $ss['pending_action'] ),
                        'pending_action'     => ! empty( $ss['pending_action'] ) ? self::pending_action_summary( $ss['pending_action'] ) : null,
                        'last_product_id'    => isset( $ss['last_product']['id'] ) ? (int) $ss['last_product']['id'] : null,
                        'updated_at'         => isset( $ss['updated_at'] ) ? (int) $ss['updated_at'] : null,
                    );
                }
                if ( isset( $response_data['debug_lite'] ) && is_array( $response_data['debug_lite'] ) && isset( $response_data['debug_lite']['context'] ) ) {
                    // This is already "lite" by design.
                    $resp_excerpt['context_lite'] = $response_data['debug_lite']['context'];
                }
            }

            $record = array(
                'v'           => '1.0',
                'ts'          => time(),
                'trace_id'    => $trace_id,
                'level'       => $level,
                'message_raw' => $msg,
                'message_norm'=> $message_nm,
                'route'       => $route,
                'intent_parse'=> $intent,
                'response'    => $resp_excerpt,
                'trace'       => self::clip_trace_events( $trace_events, 30 ),
            );

            self::write_line( $record );
        } catch ( \Throwable $e ) {
            // swallow
        }
    }

    /**
     * @param mixed $pending_action
     * @return array<string,mixed>
     */
    private static function pending_action_summary( $pending_action ) {
        if ( ! is_array( $pending_action ) ) {
            return array( 'type' => null );
        }
        $out = array(
            'type'       => isset( $pending_action['type'] ) ? (string) $pending_action['type'] : null,
            'created_at' => isset( $pending_action['created_at'] ) ? (int) $pending_action['created_at'] : null,
        );

        if ( isset( $pending_action['action'] ) && is_array( $pending_action['action'] ) ) {
            if ( isset( $pending_action['action']['human_summary'] ) ) {
                $out['summary'] = (string) $pending_action['action']['human_summary'];
            } elseif ( isset( $pending_action['action']['type'] ) ) {
                $out['summary'] = (string) $pending_action['action']['type'];
            }
            if ( isset( $pending_action['action']['product_id'] ) ) {
                $out['product_id'] = (int) $pending_action['action']['product_id'];
            }
        }
        return $out;
    }

    /**
     * Limit trace array size and payload.
     *
     * @param mixed $trace_events
     * @param int   $max
     * @return array
     */
    private static function clip_trace_events( $trace_events, $max ) {
        $max = max( 1, (int) $max );
        if ( ! is_array( $trace_events ) ) {
            return array();
        }
        $trace_events = array_slice( $trace_events, -$max );
        $out = array();
        foreach ( $trace_events as $ev ) {
            if ( ! is_array( $ev ) ) {
                continue;
            }
            $out[] = array(
                'ts'    => isset( $ev['ts'] ) ? (int) $ev['ts'] : null,
                'event' => isset( $ev['event'] ) ? (string) $ev['event'] : null,
                'data'  => isset( $ev['data'] ) ? $ev['data'] : null,
            );
        }
        return $out;
    }

    /**
     * Delete all telemetry files (best effort).
     *
     * @return int Number of files deleted
     */
    public static function clear_all() {
        $deleted = 0;
        try {
            $dir = self::dir_path();
            if ( ! is_string( $dir ) || $dir === '' || ! @is_dir( $dir ) ) {
                return 0;
            }

            $pattern = trailingslashit( $dir ) . 'brain-telemetry-*.jsonl';
            $files = @glob( $pattern );
            if ( ! is_array( $files ) || empty( $files ) ) {
                return 0;
            }

            foreach ( $files as $f ) {
                $f = (string) $f;
                if ( $f === '' ) { continue; }
                // Defensive: ensure we only delete within the telemetry dir.
                if ( strpos( $f, trailingslashit( $dir ) ) !== 0 ) { continue; }
                if ( @is_file( $f ) && @unlink( $f ) ) {
                    $deleted++;
                }
            }
        } catch ( \Throwable $e ) {
            return $deleted;
        }
        return $deleted;
    }
}
