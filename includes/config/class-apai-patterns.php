<?php
/**
 * AutoProduct AI — Central Regex Patterns
 *
 * @FLOW Config
 * @INVARIANT Do not change external behavior. Patterns are MOVED only.
 * WHY: Avoid regex duplication and keep intent parsing auditable and maintainable.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Patterns {

    // Pending: cancel / confirm
    public const CANCEL_MAIN           = '/\b(cancel|cancela|cancelar|anul|anular|stop|para|parar|deten|detener|dejalo|dejala|dejarlo|dejarla|descart|sacalo|sacala|sacar|olvida|olvidalo|olvidala)\b/u';
    public const CANCEL_DEJAR_DE_LADO  = '/\b(dejar(?:lo|la)?\s+de\s+lado)\b/u';
    public const CANCEL_MEJOR_NO       = '/\b(mejor\s+no)\b/u';
    public const CANCEL_ONLY_NO_SPACES = '/^\s*(no|nop|nah)\s*$/u';
    public const CANCEL_ONLY_NO        = '/^(no|nop|nah)$/u';

    public const CONFIRM_MAIN          = '/\b(confirm|confirmo|confirmar|confirmado|ok|dale|de una|ejecut|mandale|metele|listo|vamos)\b/u';

    // Generic parsing helpers
    public const NUMBER_TOKEN          = '/\b\d[\d\.,]*\b/u';
    public const HAS_A_EN_NUMBER       = '/\b(a|en)\b\s*\$?\s*\d/u';

    // Follow-up / tweak
    public const TWEAK_WORDS           = '/\b(mejor|cambia|cambialo|cambiar|ponelo|ponerlo|precio|nuevo|perdon|perd[oó]n|ups|equivoqu|corrig|ajust)\b/u';
    public const LOOKS_NEW_ACTION      = '/\b(crea|crear|agrega|agregar|elimina|eliminar|borra|borrar)\b/u';

    // Action intent detection
    public const CREATE_START          = '/^(crea|creá|crear|agrega|agregá|agregar|subi|subí|subir|carga|cargá|cargar|genera|generá|generar)\b/iu';
    public const ACTION_VERB           = '/\b(crea|crear|actualiza|actualiz[aá]|cambia|cambi[aá]|pon[eé]|poner|setea|sete[aá]|agrega|agreg[aá])\b/u';
    public const PRICE_WORD            = '/\b(precio|price|valor|importe)\b/u';

    // Stock query signals
    public const STOCK_ANY             = '/\b(stock|inventario|existenc\w*)\b/u';
    public const STOCK_OUT             = '/\b(agotad\w*|sin\s+stock|out\s+of\s+stock)\b/u';
    public const STOCK_LOW             = '/\b(bajo\s+stock|poco\s+stock|stock\s+bajo|low\s+stock)\b/u';
    public const STOCK_BACKORDER       = '/\b(back\s*order|backorder|on\s+backorder)\b/u';
    public const STOCK_STATUS_ANY      = '/\b(sin\s+stock|no\s+hay\s+stock|bajo\s+stock|back\s*order|backorder|en\s+backorder)\b/u';

    // List / detail requests
    public const WANT_FULL_OR_LIST     = '/^(full|completo|detalle|detallado|cuales\??|cu[aá]les\??|mostrame\??|mostrar\??|lista\??|ver\s+lista\??|solo\s+.+)$/u';
    public const WANT_LIST_ONLY        = '/^(cuales\??|cu[aá]les\??|mostrame\??|mostrar\??|lista\??|ver\s+lista\??)$/u';

    // Count words
    public const COUNT_WORDS           = '/\b(cu[aá]ntos?|cuantos|cantidad|n[uú]mero)\b/u';

    // STRICT patterns used by flows (move-only from inline regex; do not change behavior)
    public const PRICE_WORD_STRICT        = '/\b(precio|price)\b/u';
    // NOTE: Normalizer removes accents ("poné" -> "pone"). Include both forms.
    public const ACTION_VERB_STRICT       = '/\b(cambia|cambiá|cambiar|actualiza|actualizá|actualizar|modifica|modificá|modificar|poner|poné|pone)\b/u';

    /**
     * Wider but still conservative “set/change” verbs used by deterministic flows.
     *
     * @INVARIANT This does not execute anything; it only improves intent matching for actions
     * that are already supported (price/stock on ordinal targets).
     * WHY: Soportar español natural: "dejá", "bajale", "subile", "ponelo".
     */
    public const ACTION_VERB_WIDE_STRICT  = '/\b(cambia|cambi[aá]|cambi[oó]|cambiar|cambiame|actualiza|actualiz[aá]|actualizar|modifica|modific[aá]|modificar|pone|pon[eé]|poner|ponelo|ponerlo|dejalo|dejala|deja|dejar|bajale|baja|bajar|subile|sube|subir|ajusta|ajust[aá]|ajustar)\b/u';

    /**
     * “Set” word signals used by ModelFlow for safe clarification only.
     * Defined to avoid fatals when ModelFlow runs.
     */
    public const SET_WORD_STRICT         = '/\b(pone|pon[eé]|poner|ponelo|ponerlo|dejalo|dejala|deja|dejar|setea|sete[aá]|setear|bajale|baja|bajar|subile|sube|subir)\b/u';
    public const STOCK_WORD_STRICT        = '/\b(stock|unidades|unidad|cantidad)\b/u';
    public const FIRST_WORD_STRICT        = '/\b(primer|primero)\b/u';
    // NOTE: Normalizer may strip accents, but accept both forms for safety.
    public const LAST_WORD_STRICT         = '/\b(ultimo|último)\b/u';
    // Capture common numeric phrases, including separators ("1.000", "1,000", "1 000")
    // and common suffixes ("10k", "10 mil", "10 lucas").
    public const NUMBER_CAPTURE_STRICT    = '/(-?[0-9][0-9\.,\s]*(?:\s*(?:k|mil|miles|luca|lucas))?)/iu';

    public const PENDING_ACTIONLIKE_STRICT = '/\b(cambia|cambiar|actualiza|actualizar|modifica|modificar|pone|poner|sube|subir|baja|bajar|precio|stock)\b/u';
    public const PENDING_SMALLTALK_STRICT  = '/\b(hola|buenas|buenos|buenas\s+tardes|buenas\s+noches|gracias|okey|ok|dale|genial|perfecto|joya|listo)\b/u';
    public const PENDING_CANCEL_STRICT     = '/^\s*(cancelar|cancelo|cancelá|cancela|cancel|anular|abortar|dejala\s+de\s+lado|dejarla\s+de\s+lado|dejalo\s+de\s+lado|dejarlo\s+de\s+lado|dejar\s+de\s+lado)\s*$/iu';
    public const PENDING_CONFIRM_STRICT    = '/^\s*(si|sí|confirmar|confirmo|confirmá|ok|dale|listo)\s*$/u';
    public const PENDING_FOLLOWUP_STRICT   = '/^\s*(mejor|cambia|cambialo|cambiar|ponelo|ponerlo)\b/u';
    public const PENDING_SMALLTALK_GREETING_STRICT = '/\b(hola|buenas|buenos dias|buenas tardes|buenas noches|hey|que tal|gracias)\b/u';
    public const PENDING_CANCEL_TOKEN_STRICT       = '/^(cancelar|cancelo|dejala\s+de\s+lado|dejarla\s+de\s+lado|dejalo\s+de\s+lado|dejarlo\s+de\s+lado|dejar\s+de\s+lado)$/iu';
    public const PENDING_CONFIRM_TOKEN_STRICT      = '/^(confirmar|confirmo)$/';
    public const PENDING_LOOKS_CAMBIA_STRICT       = '/\bcambia\b|\bcambi[aá]\b/u';
    public const PENDING_LOOKS_PRECIO_STRICT       = '/\bprecio\b/u';
    public const PENDING_LOOKS_STOCK_STRICT        = '/\bstock\b|\bstock\s+del\b/u';
    public const PENDING_FOLLOWUP_PREFIX_STRICT    = '/^(mejor|mejor\s+a|a|ponelo\s+a|pone\s+a|dejalo\s+en)\b/u';
    public const PENDING_NUMERIC_ONLY_RAW_STRICT     = '/^[0-9]+(?:[\.,][0-9]+)?$/';
    public const PENDING_REWRITE_LAST_A_NUMBER_STRICT = '/\sa\s+[0-9]+(?:\.[0-9]+)?(?!.*\sa\s+[0-9]+(?:\.[0-9]+)?)/u';
    public const PENDING_LAST_NUMBER_STRICT        = '/([0-9]+(?:[\.,][0-9]+)?)(?!.*[0-9])/';
    public const PENDING_NUMERIC_ONLY_STRICT = '/^\s*\$?\s*\d[\d\.,]*\s*$/u';
    public const PENDING_REWRITE_VALUE_STRICT = '/(\ba\b|\ben\b)\s*\$?\s*\d[\d\.,]*/iu';

    public const MODEL_GREETING_STRICT     = '/^(hola|buenas|buenos|hello|hi)(\s+.+)?$/iu';

}
