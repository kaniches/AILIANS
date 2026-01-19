<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal, safe trace logger.
 * - Writes JSON Lines to uploads/autoproduct-ai/trace.log
 * - Never throws or breaks requests.
 */
class APAI_Brain_Trace {

    /** @var string|null */
    private static $current_trace_id = null;

    /**
     * In-memory buffer (per request) for telemetry.
     * Key: trace_id, Value: list of events.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private static $buffer = array();

    /** @var int */
    private static $buffer_limit = 60;

    public static function enabled() {
        if ( defined( 'APAI_TRACE' ) && APAI_TRACE ) {
            return true;
        }
        return ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    }

    private static function telemetry_enabled() {
        try {
            if ( function_exists( 'get_option' ) ) {
                $v = get_option( 'apai_brain_telemetry_enabled', '' );
                return ( $v === '1' || $v === 1 || $v === true );
            }
        } catch ( \Throwable $e ) {
            return false;
        }
        return false;
    }

    private static function should_buffer() {
        return ( self::enabled() || self::telemetry_enabled() );
    }

    public static function new_trace_id() {
        try {
            if ( function_exists( 'wp_generate_password' ) ) {
                $rand = wp_generate_password( 10, false, false );
            } else {
                $rand = bin2hex( random_bytes( 5 ) );
            }
        } catch ( \Throwable $e ) {
            $rand = strval( mt_rand( 100000, 999999 ) );
        }
        return 'apai_' . gmdate( 'Ymd_His' ) . '_' . $rand;
    }

    public static function set_current_trace_id( $trace_id ) {
        self::$current_trace_id = ( $trace_id !== null ) ? strval( $trace_id ) : null;
    }

    public static function current_trace_id() {
        return self::$current_trace_id;
    }

    private static function log_path() {
        $base = '';
        if ( function_exists( 'wp_upload_dir' ) ) {
            $u = wp_upload_dir();
            if ( is_array( $u ) && isset( $u['basedir'] ) ) {
                $base = strval( $u['basedir'] );
            }
        }

        // Fallback if uploads dir is unavailable or not writable in this hosting.
        if ( $base === '' || ( function_exists( 'is_writable' ) && @is_dir( $base ) && ! @is_writable( $base ) ) ) {
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $base = trailingslashit( WP_CONTENT_DIR ) . 'uploads';
            }
        }

        if ( $base === '' ) {
            return array( '', '' );
        }

        $dir = trailingslashit( $base ) . 'autoproduct-ai';
        $file = trailingslashit( $dir ) . 'trace.log';
        return array( $dir, $file );
    }

    private static function ensure_dir( $dir ) {
        if ( $dir === '' ) {
            return false;
        }
        if ( @is_dir( $dir ) ) {
            return true;
        }
        if ( function_exists( 'wp_mkdir_p' ) ) {
            return @wp_mkdir_p( $dir );
        }
        return @mkdir( $dir, 0755, true );
    }

    public static function emit( $trace_id, $event, $data = array() ) {
        try {
            if ( ! self::should_buffer() ) {
                return;
            }

            $line = array(
                'ts'       => time(),
                'trace_id' => strval( $trace_id ),
                'event'    => strval( $event ),
                'data'     => is_array( $data ) ? $data : array( 'value' => $data ),
            );

            // Keep an in-memory copy for this request (telemetry aggregator).
            $tid = (string) $trace_id;
            if ( ! isset( self::$buffer[ $tid ] ) || ! is_array( self::$buffer[ $tid ] ) ) {
                self::$buffer[ $tid ] = array();
            }
            self::$buffer[ $tid ][] = $line;
            if ( count( self::$buffer[ $tid ] ) > self::$buffer_limit ) {
                self::$buffer[ $tid ] = array_slice( self::$buffer[ $tid ], -self::$buffer_limit );
            }

            // Only persist trace.log when tracing is enabled.
            if ( ! self::enabled() ) {
                return;
            }

            list( $dir, $file ) = self::log_path();
            if ( $file === '' ) {
                return;
            }
            self::ensure_dir( $dir );
            $json = @json_encode( $line, JSON_UNESCAPED_UNICODE );
            if ( ! is_string( $json ) ) {
                return;
            }
            @file_put_contents( $file, $json . "\n", FILE_APPEND | LOCK_EX );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                @error_log( '[APAI_TRACE] ' . $json );
            }
        } catch ( \Throwable $e ) {
            // swallow
        }
    }

    /**
     * Emit an event using the current request trace id (if any).
     * Safe no-op when trace is unavailable.
     *
     * @param string $event
     * @param array  $data
     * @return void
     */
    public static function emit_current( $event, $data = array() ) {
        $tid = self::current_trace_id();
        if ( ! is_string( $tid ) || $tid === '' ) {
            return;
        }
        self::emit( $tid, $event, $data );
    }

    /**
     * Backward compat helper.
     * Some iterations called add_event(); we keep it as an alias.
     */
    public static function add_event( $trace_id, $event, $data = array() ) {
        self::emit( $trace_id, $event, $data );
    }

    /**
     * Return buffered events for a trace_id.
     *
     * @param string $trace_id
     * @return array<int,array<string,mixed>>
     */
    public static function buffer_get( $trace_id ) {
        $tid = (string) $trace_id;
        if ( isset( self::$buffer[ $tid ] ) && is_array( self::$buffer[ $tid ] ) ) {
            return self::$buffer[ $tid ];
        }
        return array();
    }

    /**
     * Clear buffered events for a trace_id.
     *
     * @param string $trace_id
     * @return void
     */
    public static function buffer_clear( $trace_id ) {
        $tid = (string) $trace_id;
        if ( isset( self::$buffer[ $tid ] ) ) {
            unset( self::$buffer[ $tid ] );
        }
    }

	/**
	 * Return an excerpt of the trace log filtered by trace ids.
	 *
	 * @param array $trace_ids List of trace ids.
	 * @param int   $max_lines Max number of lines to return.
	 * @param int|null $max_bytes Max bytes to scan from the end of the file (tail-mode).
	 *                          This keeps the endpoint fast when trace.log grows large.
	 * @return array{ok:bool, file:string, lines:array, meta?:array}
	 */
	public static function excerpt_by_trace_ids( $trace_ids, $max_lines = 400, $max_bytes = null ) {
		$trace_ids = is_array( $trace_ids ) ? $trace_ids : array();
		$trace_ids = array_values( array_unique( array_filter( array_map( 'strval', $trace_ids ) ) ) );
		$max_lines = max( 1, (int) $max_lines );
		$max_lines = min( 1000, $max_lines );

		// Default: scan up to 512KB from the end (fast tail). Hard cap: 2MB.
		if ( $max_bytes === null ) {
			$max_bytes = 512 * 1024;
		}
		$max_bytes = (int) $max_bytes;
		$max_bytes = max( 16 * 1024, min( 2 * 1024 * 1024, $max_bytes ) );

		$set   = array();
		$lines = array();
		$path  = '';
		$meta  = array(
			'mode'        => 'tail',
			'max_bytes'   => (int) $max_bytes,
			'bytes_read'  => 0,
			'file_size'   => 0,
			'truncated'   => false,
			'lines_found' => 0,
		);

		foreach ( $trace_ids as $tid ) {
			$set[ $tid ] = true;
		}

		try {
			list( $dir, $file ) = self::log_path();
			// log_path() already returns the full file path as the 2nd value.
			$path = $file;
			if ( ! file_exists( $path ) ) {
				return array( 'ok' => true, 'file' => $path, 'lines' => array(), 'meta' => $meta );
			}

			$meta['file_size'] = (int) @filesize( $path );

			$fh = fopen( $path, 'rb' );
			if ( ! $fh ) {
				return array( 'ok' => false, 'file' => $path, 'lines' => array(), 'meta' => $meta, 'error' => 'cannot_open' );
			}

			// Tail-mode: scan from the end to avoid reading huge logs.
			$size = $meta['file_size'];
			$start = 0;
			if ( $size > $max_bytes ) {
				$start = $size - $max_bytes;
				$meta['truncated'] = true;
			}
			if ( $start < 0 ) { $start = 0; }
			$meta['bytes_read'] = (int) ( $size - $start );

			// Best-effort seek.
			if ( $start > 0 ) {
				@fseek( $fh, $start, SEEK_SET );
				// Discard partial line (we might be in the middle of a JSONL row).
				@fgets( $fh );
			}

			$raw = '';
			// Read remainder.
			while ( ! feof( $fh ) ) {
				$chunk = fread( $fh, 8192 );
				if ( $chunk === false || $chunk === '' ) {
					break;
				}
				$raw .= $chunk;
				// Hard safety: don't let $raw explode in memory.
				if ( strlen( $raw ) > ( $max_bytes + 64 * 1024 ) ) {
					$raw = substr( $raw, - ( $max_bytes + 64 * 1024 ) );
					$meta['truncated'] = true;
					break;
				}
			}

			// Split into lines and scan backwards (most relevant events are at the end).
			$parts = preg_split( "/\r\n|\n|\r/", $raw );
			if ( is_array( $parts ) ) {
				for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
					$trim = trim( (string) $parts[ $i ] );
					if ( $trim === '' ) {
						continue;
					}
					$obj = json_decode( $trim, true );
					if ( ! is_array( $obj ) || empty( $obj['trace_id'] ) ) {
						continue;
					}
					$tid = (string) $obj['trace_id'];
					if ( isset( $set[ $tid ] ) ) {
						$lines[] = $trim;
						if ( count( $lines ) >= $max_lines ) {
							break;
						}
					}
				}
			}

			// We built it backwards; return chronological order.
			$lines = array_reverse( $lines );
			$meta['lines_found'] = (int) count( $lines );

			fclose( $fh );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'file' => $path, 'lines' => $lines, 'meta' => $meta, 'error' => $e->getMessage() );
		}

		return array( 'ok' => true, 'file' => $path, 'lines' => $lines, 'meta' => $meta );
	}
}
