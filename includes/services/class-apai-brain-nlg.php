<?php
/**
 * NLG controlado (F6.6)
 *
 * @FLOW NLG
 * @INVARIANT No decide lógica. Solo copy / templates deterministas.
 * WHY: Evitar textos divergentes entre flows y mantener UX consistente.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_NLG {

    /**
     * Build a compact human summary for update_product changes.
     * Example: "Actualizar producto: precio 9999 + stock 4"
     */
    public static function summarize_update_product_changes( $changes ) {
        if ( ! is_array( $changes ) ) {
            return 'Cambiar un producto en el catálogo.';
        }

        $parts = array();

        if ( isset( $changes['regular_price'] ) ) {
            $price = (string) $changes['regular_price'];
            // Display-friendly: drop trailing .00
            $price_disp = preg_replace( '/\.00$/', '', $price );
            $price_disp = trim( $price_disp );
            if ( '' !== $price_disp ) {
                $parts[] = 'precio ' . $price_disp;
            }
        }

        if ( isset( $changes['stock_quantity'] ) ) {
            $qty = intval( $changes['stock_quantity'] );
            $parts[] = 'stock ' . $qty;
        }

        if ( empty( $parts ) ) {
            return 'Cambiar un producto en el catálogo.';
        }

        return 'Actualizar producto: ' . implode( ' + ', $parts );
    }

    /** Message shown when a new action is prepared (proposal). */
    public static function msg_action_prepared_default() {
        return 'Dale, preparé la acción.';
    }

    /** Message shown when we merged changes into the existing pending action. */
    public static function msg_pending_merge_added() {
        return 'Listo 😊 sumé ese cambio a la acción propuesta.';
    }

    /** Message shown when the user tries to add a change that is already included or has no effect. */
    public static function msg_pending_merge_noop() {
        return 'Ese cambio ya estaba contemplado, así que no hace falta sumarlo 😊';
    }

	/**
	 * Mensaje cuando el usuario ajusta/corrige el valor de una acción pendiente
	 * (por ej: "mejor a 12").
	 *
	 * Nota: PendingFlow usa este nombre.
	 */
	public static function msg_pending_adjusted() {
		// Por ahora usamos el mismo copy que cuando "sumamos" un cambio.
		return self::msg_pending_merge_added();
	}

    /** Pending guard message (when user tries a different action). */
    public static function msg_pending_guard_choice() {
        return 'Un segundo 😊 hay una acción pendiente. ¿Querés seguir con la pendiente o dejarla de lado?';
    }

    /**
     * Mensaje cuando el usuario intenta charlar (hola/gracias/etc.) con un pending activo.
     * Importante: mantenemos el texto estable para no cambiar UX.
     */
    public static function msg_pending_block(): string {
        return 'Antes de seguir, tenés una **acción pendiente**. Confirmá o cancelá la acción propuesta (o decime "mejor a ..." para corregir).';
    }

    /**
     * Mensaje de cancelación (con cierre "Listo.").
     */
    public static function msg_pending_cancelled(string $human_summary): string {
        $human_summary = trim($human_summary);
        if ($human_summary === '') {
            $human_summary = 'la acción propuesta';
        }
        // Nota: algunos summaries ya terminan con punto; normalizamos a uno solo.
        $suffix = (substr($human_summary, -1) === '.') ? '' : '.';
        return '❌ Acción cancelada: ' . $human_summary . $suffix . "\n\nListo.";
    }

    /**
     * Mensaje corto al ejecutar correctamente.
     */
    public static function msg_action_executed_ok(string $human_summary): string {
        $human_summary = trim($human_summary);
        if ($human_summary === '') {
            $human_summary = 'Acción ejecutada.';
        }
        // Mantener el texto que el usuario ya está viendo en chat.
        return '✅ Acción ejecutada correctamente: ' . $human_summary;
    }

    public function msg_pending_corrected() {
        return "Listo, sumé ese cambio a la acción propuesta.";
    }

	/**
	 * Hint shown when user types "confirmar" in chat while an action is pending.
	 * We do NOT execute here; execution is done via UI button (executor side-effects).
	 */
	public static function msg_pending_confirm_hint() {
		return 'Para ejecutar la acción pendiente, usá el botón **Confirmar y ejecutar acción**. Si querés descartarla, escribí **cancelar**.';
	}

}