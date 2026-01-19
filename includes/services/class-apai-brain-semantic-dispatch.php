<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SemanticDispatch
 *
 * Takes a NormalizedIntent (from SemanticInterpreter) and delegates to existing
 * deterministic flows/services.
 *
 * A1.5.0 initial scope:
 * - action: set_price / set_stock with selector last/first/id
 * - clarify: return clarify response
 * - chitchat: delegate to ModelFlow (return null so pipeline continues)
 * - query: return null (QueryFlow already happens earlier in pipeline)
 */
class APAI_Brain_Semantic_Dispatch {

    public static function dispatch( $intent_json, $message_raw = '', $message_norm = '', $context_lite = null ) {
        if ( ! is_array( $intent_json ) ) { return null; }

        $kind = isset( $intent_json['kind'] ) ? strval( $intent_json['kind'] ) : '';
        if ( $kind === 'clarify' ) {
            $q = '';
            $opts = array();
            if ( isset( $intent_json['clarify'] ) && is_array( $intent_json['clarify'] ) ) {
                $q = isset( $intent_json['clarify']['question'] ) ? strval( $intent_json['clarify']['question'] ) : '';
                $opts = isset( $intent_json['clarify']['options'] ) && is_array( $intent_json['clarify']['options'] ) ? $intent_json['clarify']['options'] : array();
            }
            return APAI_Brain_Response_Builder::clarify( $q, $opts );
        }

        // Let pipeline route chitchat to ModelFlow.
        if ( $kind === 'chitchat' ) { return null; }

        // Queries are handled earlier by InfoQueryFlow / QueryRegistry.
        if ( $kind === 'query' ) { return null; }

        if ( $kind !== 'action' ) { return null; }

        // Grounding + confidence gating is enforced in SemanticInterpreter.
        // Still, keep dispatch conservative.
        if ( isset( $intent_json['needs_clarification'] ) && $intent_json['needs_clarification'] ) {
            $q = '';
            $opts = array();
            if ( isset( $intent_json['clarify'] ) && is_array( $intent_json['clarify'] ) ) {
                $q = isset( $intent_json['clarify']['question'] ) ? strval( $intent_json['clarify']['question'] ) : '';
                $opts = isset( $intent_json['clarify']['options'] ) && is_array( $intent_json['clarify']['options'] ) ? $intent_json['clarify']['options'] : array();
            }
            return APAI_Brain_Response_Builder::clarify( $q, $opts );
        }

        if ( ! isset( $intent_json['action'] ) || ! is_array( $intent_json['action'] ) ) { return null; }
        $action = $intent_json['action'];
        $intent = isset( $action['intent'] ) ? strval( $action['intent'] ) : '';
        $field  = isset( $action['field'] ) ? strval( $action['field'] ) : '';
        $raw_value_text = isset( $action['raw_value_text'] ) ? strval( $action['raw_value_text'] ) : '';

        // Resolve product id from selector using store_state/context_lite.
        $product_id = self::resolve_target_product_id( isset( $action['selector'] ) ? $action['selector'] : null, $context_lite );
        if ( $product_id <= 0 ) { return null; }

        if ( $intent === 'set_price' || $field === 'price' ) {
            $resp = APAI_Brain_Action_Preparer::prepare_set_price( $product_id, $raw_value_text, 'SemanticDispatch' );
            return $resp;
        }

        if ( $intent === 'set_stock' || $field === 'stock' ) {
            $resp = APAI_Brain_Action_Preparer::prepare_set_stock( $product_id, $raw_value_text, 'SemanticDispatch' );
            return $resp;
        }

        // Not supported yet in dispatch: let existing flows handle / fallback.
        return null;
    }

    private static function resolve_target_product_id( $selector, $context_lite ) {
        if ( ! is_array( $selector ) ) { return 0; }
        $type = isset( $selector['type'] ) ? strval( $selector['type'] ) : '';
        $val  = isset( $selector['value'] ) ? strval( $selector['value'] ) : '';

        // Direct id
        if ( $type === 'id' ) {
            $id = intval( $val );
            return $id > 0 ? $id : 0;
        }

        // last/first use store_state snapshot from context_lite.
        $store_state = null;
        if ( is_array( $context_lite ) ) {
            // Some callers pass the already-extracted store_state.
            if ( isset( $context_lite['last_target_product_id'] ) || isset( $context_lite['last_product'] ) ) {
                $store_state = $context_lite;
            } elseif ( isset( $context_lite['store_state'] ) && is_array( $context_lite['store_state'] ) ) {
                $store_state = $context_lite['store_state'];
            }
        }

        if ( $type === 'last' ) {
            if ( is_array( $store_state ) && isset( $store_state['last_target_product_id'] ) ) {
                $id = intval( $store_state['last_target_product_id'] );
                if ( $id > 0 ) { return $id; }
            }
        }
        if ( $type === 'first' ) {
            // Not yet available in store_state in this build; fallback to 0.
            return 0;
        }

        return 0;
    }
}
