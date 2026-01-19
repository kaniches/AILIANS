<?php
/**
 * @FLOW Brain
 * @INVARIANT Memoria operativa 100% server-side (por tienda) es fuente de verdad.
 * WHY: Extraer store_state de la REST God Class sin cambiar comportamiento.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Memory_Store {

    /**
     * Key del transient/option que almacena store_state.
     */
    public static function store_state_key() {
        // Mantener compatibilidad con lógica previa: default blog_id=1
        $blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
        return 'apai_agent_store_state_' . intval( $blog_id );
    }

    /**
     * Obtiene store_state actual (array).
     */
    public static function get() {
        $key = self::store_state_key();
        $state = get_transient( $key );
        if ( ! is_array( $state ) ) {
            // Fallback persistente (misma estrategia que el legacy)
            $state = get_option( $key, array() );
            if ( ! is_array( $state ) ) {
                $state = array();
            }
        }
        if ( ! isset( $state['pending_action'] ) ) { $state['pending_action'] = null; }
        if ( ! isset( $state['pending_target_selection'] ) ) { $state['pending_target_selection'] = null; }
        if ( ! isset( $state['pending_targeted_update'] ) ) { $state['pending_targeted_update'] = null; }
        // A2 targeted followup context (name/short_description/categories)
        if ( ! isset( $state['pending_targeted_update_a2'] ) ) { $state['pending_targeted_update_a2'] = null; }
        // Followup context for vague/clarified actions (e.g. "ponelo más barato" -> then user replies "2000").
        // Shape: { expect: 'value', intent: 'set_price'|'set_stock', selector: {type,value}, product_id?: int|null, ts: int }
        if ( ! isset( $state['pending_followup_action'] ) ) { $state['pending_followup_action'] = null; }
        if ( ! isset( $state['last_product'] ) ) { $state['last_product'] = null; }
        if ( ! isset( $state['last_target_product_id'] ) ) { $state['last_target_product_id'] = null; }
        if ( ! isset( $state['last_action_executed_at'] ) ) { $state['last_action_executed_at'] = null; }
        if ( ! isset( $state['last_action_executed_summary'] ) ) { $state['last_action_executed_summary'] = null; }
        if ( ! isset( $state['last_action_executed_kind'] ) ) { $state['last_action_executed_kind'] = null; }
        if ( ! isset( $state['last_event'] ) ) { $state['last_event'] = null; }
        if ( ! isset( $state['last_action_kind'] ) ) { $state['last_action_kind'] = null; }
        if ( ! isset( $state['updated_at'] ) ) { $state['updated_at'] = time(); }

        // Consume last_event once so it doesn't re-appear on a later unrelated message
        // (e.g., user says "hola" and sees a stale cancellation toast).
        if ( ! empty( $state['last_event'] ) && is_array( $state['last_event'] ) ) {
            $key = self::store_state_key();
            $state_to_store = $state;
            $state_to_store['last_event'] = null;
            // keep updated_at untouched to avoid affecting pending card keying
            set_transient( $key, $state_to_store, 2 * HOUR_IN_SECONDS );
            update_option( $key, $state_to_store, false );
        }
        return $state;
    }

    /**
     * @FLOW Pending
     * Extrae la acción pendiente desde store_state.
     *
     * Formatos soportados (compat):
     * - Envelope preferido: { type, action, created_at }
     * - Legacy: action directo (array con 'type')
     *
     * @return array|null Envelope normalizado o null.
     */
    public static function extract_pending_action_from_store( $store_state ) {
        if ( ! is_array( $store_state ) ) { return null; }
        if ( ! isset( $store_state['pending_action'] ) ) { return null; }

        $p = $store_state['pending_action'];
        if ( empty( $p ) || ! is_array( $p ) ) {
            return null;
        }

        // Preferred shape: wrapper { type, action, created_at }
        if ( isset( $p['action'] ) && is_array( $p['action'] ) ) {
            if ( empty( $p['type'] ) && isset( $p['action']['type'] ) ) {
                $p['type'] = sanitize_text_field( (string) $p['action']['type'] );
            }
            if ( empty( $p['created_at'] ) ) {
                $p['created_at'] = time();
            }
            return $p;
        }

        // Legacy shape: action stored directly.
        if ( isset( $p['type'] ) ) {
            return array(
                'type'       => sanitize_text_field( (string) $p['type'] ),
                'action'     => $p,
                'created_at' => isset( $p['created_at'] ) ? intval( $p['created_at'] ) : time(),
            );
        }

        return null;
    }

    /**
     * Aplica patch superficial (array merge) y persiste.
     */
    public static function patch( $patch ) {
        $patch = is_array( $patch ) ? $patch : array();
        $key   = self::store_state_key();
        $state = self::get();

        foreach ( $patch as $k => $v ) {
            $state[ $k ] = $v;
        }
        $state['updated_at'] = time();

        // TTL: 2 horas (memoria operativa) + fallback persistente.
        set_transient( $key, $state, 2 * HOUR_IN_SECONDS );
        update_option( $key, $state, false );
        return $state;
    }

    // ======================================================
    // Back-compat helpers (legacy callers / older flows)
    // ======================================================

    /**
     * Back-compat alias used by some flows in older zips.
     * Keep as thin wrapper.
     */
    public static function update_state( $patch ) {
        return self::patch( $patch );
    }

    /**
     * Back-compat alias: legacy callers used store_get_state().
     */
    public static function store_get_state() {
        return self::get();
    }

    /**
     * Limpia pending_action.
     */
    public static function clear_pending( $reason = '' ) {
        // OBS: emit an audit event before clearing (best-effort, no behaviour changes).
        try {
            if ( class_exists( 'APAI_Brain_Trace' ) ) {
                $state = self::get();
                $penv  = self::extract_pending_action_from_store( $state );
                $action = ( is_array( $penv ) && isset( $penv['action'] ) && is_array( $penv['action'] ) ) ? $penv['action'] : null;

                $pid = 0;
                if ( is_array( $action ) ) {
                    if ( isset( $action['product_id'] ) ) {
                        $pid = intval( $action['product_id'] );
                    } elseif ( isset( $action['target'] ) && is_array( $action['target'] ) && isset( $action['target']['product_id'] ) ) {
                        $pid = intval( $action['target']['product_id'] );
                    } elseif ( isset( $action['product'] ) && is_array( $action['product'] ) && isset( $action['product']['id'] ) ) {
                        $pid = intval( $action['product']['id'] );
                    }
                }

                APAI_Brain_Trace::emit_current( 'pending_clear', array(
                    'reason'     => is_string( $reason ) ? $reason : '',
                    'had_pending'=> ( $penv !== null ),
                    'type'       => ( is_array( $penv ) && isset( $penv['type'] ) ) ? (string) $penv['type'] : '',
                    'product_id' => ( $pid > 0 ? $pid : null ),
                ) );
            }
        } catch ( \Throwable $e ) {
            // swallow
        }

        return self::patch( array( 'pending_action' => null ) );
    }



/**
 * @FLOW TargetSelection
 * Persiste un estado de selección de producto (2–5 candidatos).
 * NO es pending_action: no ejecuta nada, solo habilita UX de selección.
 */
    public static function persist_target_selection( $selection ) {
    $selection = is_array( $selection ) ? $selection : array();
    // OBS: trace selector state (best-effort)
    try {
        if ( class_exists( 'APAI_Brain_Trace' ) ) {
            $n = ( is_array( $selection ) && isset( $selection['candidates'] ) && is_array( $selection['candidates'] ) ) ? count( $selection['candidates'] ) : null;
            APAI_Brain_Trace::emit_current( 'selection_set', array( 'count' => $n ) );
        }
    } catch ( \Throwable $e ) {}
    return self::patch( array( 'pending_target_selection' => $selection ) );
}

/**
 * Limpia pending_target_selection.
 */
    public static function clear_target_selection() {
    // OBS: trace selector cleared (best-effort)
    try {
        if ( class_exists( 'APAI_Brain_Trace' ) ) {
            APAI_Brain_Trace::emit_current( 'selection_clear', array() );
        }
    } catch ( \Throwable $e ) {}
    return self::patch( array( 'pending_target_selection' => null ) );
}


    /**
     * @FLOW Pending
     * Persiste una acción como pending_action server-side (envelope compatible).
     *
     * @param array $action
     * @return array store_state actualizado
     */
    public static function persist_pending_action( $action ) {
        if ( ! is_array( $action ) ) {
            return self::get();
        }

        // Back-compat: some callers pass a pending_action envelope
        // like: { type, action: {...}, created_at }. Normalize to the inner action.
        if ( isset( $action['action'] ) && is_array( $action['action'] ) ) {
            $action = $action['action'];
        }

        $type = isset( $action['type'] ) ? sanitize_text_field( (string) $action['type'] ) : '';

        // Track the "last product we targeted" so ambiguous follow-ups (e.g. "2000") apply to the correct product.
        $pid = 0;
        if ( isset( $action['product_id'] ) ) {
            $pid = intval( $action['product_id'] );
        } elseif ( isset( $action['target'] ) && is_array( $action['target'] ) && isset( $action['target']['product_id'] ) ) {
            $pid = intval( $action['target']['product_id'] );
        } elseif ( isset( $action['product'] ) && is_array( $action['product'] ) && isset( $action['product']['id'] ) ) {
            $pid = intval( $action['product']['id'] );
        }

        $patch = array(
            'pending_action' => array(
                'type'       => $type,
                'action'     => $action,
                'created_at' => time(),
            ),
            // Clear any A2 follow-up context once a real pending action is created.
            // This prevents stale "need_category" from hijacking subsequent messages.
            'pending_targeted_update_a2' => null,
        );

        if ( $pid > 0 ) {
            $patch['last_target_product_id'] = $pid;
        }

        // OBS: trace pending creation/update (best-effort, no behaviour changes).
        try {
            if ( class_exists( 'APAI_Brain_Trace' ) ) {
                $kind = '';
                if ( isset( $action['changes'] ) && is_array( $action['changes'] ) ) {
                    if ( array_key_exists( 'regular_price', $action['changes'] ) ) {
                        $kind = 'price';
                    } elseif ( array_key_exists( 'stock_quantity', $action['changes'] ) ) {
                        $kind = 'stock';
                    }
                }

                $change_keys = array();
                if ( isset( $action['changes'] ) && is_array( $action['changes'] ) ) {
                    $change_keys = array_values( array_map( 'strval', array_keys( $action['changes'] ) ) );
                }

                APAI_Brain_Trace::emit_current( 'pending_set', array(
                    'type'        => $type,
                    'kind'        => $kind,
                    'product_id'  => ( $pid > 0 ? $pid : null ),
                    'change_keys' => $change_keys,
                ) );
            }
        } catch ( \Throwable $e ) {
            // swallow
        }

        return self::patch( $patch );
    }

    /**
     * Extrae el envelope de pending_action del store_state.
     * Devuelve null si no existe.
     */
    public static function get_pending_envelope( $store_state ) {
        if ( ! is_array( $store_state ) ) { return null; }
        if ( empty( $store_state['pending_action'] ) ) { return null; }
        return $store_state['pending_action'];
    }

    /**
     * Admin/debug helper: clears ALL store_state for this site (transient + option).
     * @INVARIANT Must NOT affect normal chat behavior; only used by /reset route.
     */
    public static function clear_all() {
        $key = self::store_state_key();
        // Remove both transient and option fallback.
        delete_transient( $key );
        delete_option( $key );
        return array(
            'pending_action'             => null,
            'pending_target_selection'   => null,
            'pending_targeted_update'    => null,
            'pending_targeted_update_a2' => null,
            'last_product'               => null,
            'last_target_product_id'     => null,
            'last_event'                 => null,
            'last_action_kind'           => null,
            'last_action_executed_at'    => null,
            'last_action_executed_summary' => null,
            'last_action_executed_kind'  => null,
            'updated_at'                 => time(),
        );
    }

}
