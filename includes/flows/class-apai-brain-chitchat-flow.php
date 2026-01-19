<?php
/**
 * ChitChatFlow (A1.5.27)
 *
 * Runs BEFORE ModelFlow.
 * - Handles greetings / general questions / money/presupuesto, etc.
 * - Keeps product-management clarify separate.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_ChitChat_Flow {

  private static function is_greeting( $msg_norm ) {
    $s = trim( strtolower( (string) $msg_norm ) );
    return (bool) preg_match( '/^(hola|buenas|buenos dias|buen dia|buenas tardes|buenas noches|hey|hello)\b/u', $s );
  }

  private static function is_capabilities_question( $msg_norm ) {
    $s = trim( strtolower( (string) $msg_norm ) );
    if ( $s === '' ) { return false; }
    return (bool) preg_match( '/\b(que podes hacer|qué podés hacer|que haces|qué hacés|ayuda|help|funciones|para que servis)\b/u', $s );
  }

  private static function honest_clarify( $message_raw ) {
    $snippet = trim( (string) $message_raw );
    if ( mb_strlen( $snippet ) > 80 ) {
      $snippet = mb_substr( $snippet, 0, 77 ) . '…';
    }
    return "No estoy seguro a qué te referís con “{$snippet}”.\n\n¿Querés:\n• ver información de un producto (precio/stock/categorías),\n• cambiar algo (precio/stock/categoría),\n• o es otra cosa?\n\nDecime cuál y, si hay producto, pasame ID/SKU/nombre.";
  }

  public static function handle( $message_raw, $message_norm, $context_lite, $store_state ) {
    $msg_norm = (string) $message_norm;

    // 1) Physical location / furniture => deterministic explain.
    if ( APAI_Brain_OffDomain_Detector::is_physical_location( $msg_norm ) ) {
      $text = "Entiendo 🙂\n\nEn el catálogo de WooCommerce no existe ‘moverlo al lado del sillón’ (eso sería una **ubicación física**).\n\nSi lo que querés es cambiar algo del producto (por ejemplo **precio**, **stock** o **categoría**), decime cuál (ID/SKU/nombre) y qué cambio querés, y lo preparo con botones.";
      return APAI_Brain_Response_Builder::make_response(
        'chat',
        $text,
        array(),
        null,
        null,
        array( 'route' => 'ChitChatFlow' )
      );
    }

    // 2) Only handle chitchat here.
    if ( ! APAI_Brain_OffDomain_Detector::is_chitchat( $msg_norm ) ) {
      return null;
    }

    // 2.a) Deterministic greetings/capabilities (avoid "bot" clarifications)
    if ( self::is_greeting( $msg_norm ) ) {
      $text = "¡Hola! 😊\n\n¿Qué querés hacer con tu tienda? Por ejemplo:\n• ver precio/stock/categorías de un producto\n• cambiar precio o stock\n• buscar un producto por nombre o ID";
      return APAI_Brain_Response_Builder::make_response(
        'chat',
        $text,
        array(),
        null,
        null,
        array( 'route' => 'ChitChatFlow' )
      );
    }

    if ( self::is_capabilities_question( $msg_norm ) ) {
      $text = "Puedo ayudarte a gestionar el catálogo de WooCommerce por chat.\n\nPuedo:\n• mostrar precio/stock/categorías de un producto\n• cambiar precio o stock (te lo preparo con botones para confirmar/cancelar)\n• buscar productos por nombre/ID/SKU\n\nDecime qué querés hacer y, si es sobre un producto, pasame ID/SKU/nombre.";
      return APAI_Brain_Response_Builder::make_response(
        'chat',
        $text,
        array(),
        null,
        null,
        array( 'route' => 'ChitChatFlow' )
      );
    }

    $hint = self::build_context_hint( $store_state );
    $ctx_json = is_object( $context_lite ) && method_exists( $context_lite, 'to_json' ) ? $context_lite->to_json() : '';

    $draft = APAI_Brain_ChitChat_Redactor::reply( $message_raw, $ctx_json, $hint );
    if ( is_array( $draft ) && ! empty( $draft['ok'] ) && isset( $draft['text'] ) ) {
      $text = (string) $draft['text'];
      // Safety: avoid invented category lists (common hallucination here).
      if ( preg_match( '/\bcalzado\b|\bart[ií]culos de hogar\b/u', $text ) ) {
        $text = self::honest_clarify( $message_raw );
      }
      return APAI_Brain_Response_Builder::make_response(
        'chat',
        $text,
        array(),
        null,
        null,
        array( 'route' => 'ChitChatFlow' )
      );
    }

    // If the LLM can't draft safely, be honest and ask what they mean.
    return APAI_Brain_Response_Builder::make_response(
      'chat',
      self::honest_clarify( $message_raw ),
      array(),
      null,
      null,
      array( 'route' => 'ChitChatFlow' )
    );
  }

  private static function build_context_hint( $store_state ) {
    if ( ! is_array( $store_state ) ) { return ''; }

    $parts = array();
    if ( ! empty( $store_state['last_target_product_id'] ) ) {
      $parts[] = 'Último producto objetivo: #' . intval( $store_state['last_target_product_id'] );
    }
    if ( ! empty( $store_state['last_action_kind'] ) ) {
      $parts[] = 'Último tipo de cambio: ' . sanitize_text_field( $store_state['last_action_kind'] );
    }
    if ( ! empty( $store_state['last_info_query']['kind'] ) && ! empty( $store_state['last_info_query']['product_id'] ) ) {
      $parts[] = 'Última consulta: ' . sanitize_text_field( $store_state['last_info_query']['kind'] ) . ' de #' . intval( $store_state['last_info_query']['product_id'] );
    }

    return implode( "\n", $parts );
  }
}
