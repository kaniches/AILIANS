<?php
/**
 * Off-domain detector (A1.5.27)
 *
 * Goal: keep "human chat" separate from product-management clarify.
 * - Physical location / furniture messages => explain it is not a catalog action.
 * - General conversation (hola, qué podés hacer, billetera vacía, etc.) => ChitChatFlow.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_OffDomain_Detector {

  /**
   * Returns true if the message is clearly about physical placement / furniture,
   * not a WooCommerce catalog operation.
   */
  public static function is_physical_location( $message_norm ) {
    $m = (string) $message_norm;

    // verbs that commonly indicate moving/placing something
    $has_move = (bool) preg_match( '/\b(mov(e|elo|ela|er)|mover|poner|ponelo|ponela|ubicar|colocar|dej(a|alo|ala))\b/u', $m );

    // furniture / rooms / physical cues
    $has_furn = (bool) preg_match( '/\b(sill(o|ó)n|sof(a|á)|sill(a|á)|mesa|living|comedor|cocina|habitaci(o|ó)n|cuarto|cama|ba(n|ñ)o|tele|tv|pared|esquina|al\s+lado|donde\s+estaba)\b/u', $m );

    return $has_move && $has_furn;
  }

  /**
   * Returns true if message is likely general conversation / help request.
   * This is intentionally conservative to avoid stealing real product ops.
   */
  public static function is_chitchat( $message_norm ) {
    $m = trim( (string) $message_norm );
    if ( $m === '' ) { return false; }

    // greetings
    if ( preg_match( '/^(hola+|buen(as|os)\s+(d(i|í)as|tardes|noches)|hey+|que\s+onda|buenas)\b/u', $m ) ) {
      return true;
    }

    // "what can you do" style
    if ( preg_match( '/\b(que\s+puedes\s+hacer|que\s+podes\s+hacer|que\s+hac(e|és)s|ayuda|help)\b/u', $m ) ) {
      return true;
    }

    // money / budget feelings
    if ( preg_match( '/\b(billetera|plata|dinero|presupuesto|caro|barato|no\s+tengo|est(o|á)y\s+sin)\b/u', $m ) ) {
      return true;
    }

    // short acknowledgements / thanks
    if ( preg_match( '/^(gracias+|ok(ey)?|dale|listo|joya)\b/u', $m ) ) {
      return true;
    }

    return false;
  }
}
