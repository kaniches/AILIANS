<?php
/**
 * Pending UI Service
 *
 * @FLOW PendingUI
 * Centraliza el render/UX de pending_action.
 *
 * @INVARIANT Move-only refactor: no cambia comportamiento externo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_Pending_UI {

    /**
     * Mensaje determinista para pedir decisión sobre una acción pendiente.
     */
    public static function choice_message( $pending_action, $kind = 'switch' ) {
	    // UX: mensaje único y 100% consistente (lo acompaña el card con botones).
	    return APAI_Brain_NLG::msg_pending_guard_choice();
    }

    /**
     * Payload canónico para la pantalla de decisión de pending.
     */
    public static function build_choice_payload( $pending_action, $kind, $deferred_message, $labels, $intent_conf = 0.9, $source = 'pending_choice', $reply_override = null ) {
        $action_for_ui = ( is_array( $pending_action ) && isset( $pending_action['action'] ) && is_array( $pending_action['action'] ) )
            ? $pending_action['action']
            : $pending_action;

        $reply = ( $reply_override !== null ) ? (string) $reply_override : self::choice_message( $pending_action, $kind );

	    $confirm_label = isset( $labels['confirm'] ) ? (string) $labels['confirm'] : 'Seguir con la pendiente';
	    $cancel_label  = isset( $labels['cancel'] ) ? (string) $labels['cancel'] : 'Dejarla de lado';

        $meta = array(
            'intent_confidence' => floatval( $intent_conf ),
            'risk_level'        => 'low',
            'source'            => (string) $source,
            'pending_choice'    => 'swap_to_deferred',
            'deferred_message'  => (string) $deferred_message,
            'ui_labels'         => array(
                'confirm' => $confirm_label,
                'cancel'  => $cancel_label,
            ),
        );

        return APAI_Brain_Response_Builder::make_response(
            'execute',
            $reply,
            $action_for_ui ? array( $action_for_ui ) : array(),
            null,
            null,
            $meta
        );
    }

    /**
     * Helper legacy: agrega aviso no-bloqueante sobre pending.
     */
    public static function append_pending_notice( $payload, $pending_action ) {
        if ( ! is_array( $payload ) || empty( $payload['ok'] ) ) { return $payload; }
        if ( empty( $pending_action ) || ! is_array( $pending_action ) ) { return $payload; }

        $summary = '';
        if ( isset( $pending_action['human_summary'] ) ) {
            $summary = (string) $pending_action['human_summary'];
        } elseif ( isset( $pending_action['type'] ) ) {
            $summary = (string) $pending_action['type'];
        }

        $extra = "\n\n⚠️ Aviso: tenés una acción pendiente";
        if ( $summary !== '' ) {
            $extra .= " (**{$summary}**)";
        }
	    $extra .= ". ¿Querés seguir con la pendiente o dejarla de lado?";

        if ( isset( $payload['message_to_user'] ) ) {
            $payload['message_to_user'] = (string) $payload['message_to_user'] . $extra;
        }
        if ( isset( $payload['reply'] ) ) {
            $payload['reply'] = (string) $payload['reply'] . $extra;
        }
        return $payload;
    }
}
