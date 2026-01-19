<?php
/**
 * ChitChat redactor (A1.5.27)
 *
 * LLM is allowed to *draft* text, but never to create pending actions.
 * Deterministic flows decide execution.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APAI_Brain_ChitChat_Redactor {

  public static function reply( $message_raw, $context_lite_json, $context_hint = '' ) {
    $msg = (string) $message_raw;
    $ctx = (string) $context_lite_json;
    $hint = trim( (string) $context_hint );

    $system = implode( "\n", array(
      'Sos AutoProduct AI, un asistente humano y amable para una tienda WooCommerce.',
      'IMPORTANTE: en este modo SOLO redactás conversación. NO ejecutás acciones, NO creás pending, NO inventás IDs/SKUs.',
      'HONESTIDAD: no digas "entiendo" si no estás seguro. Si el mensaje es ambiguo, decilo y hacé UNA pregunta aclaratoria.',
      'NO inventes categorías, productos, marcas, precios ni promociones. Si querés mencionar categorías, hacelo en general ("puedo mostrarte las categorías reales"), SIN enumerar nombres.',
      'Si el usuario pide una acción (precio/stock/categoría/etc), respondé pidiendo el ID/SKU/nombre y el valor, de forma breve y clara.',
      'Si el usuario habla de dinero/presupuesto/"billetera vacía", respondé empático y ofrecé 1-2 ideas de e-commerce (promos, alternativas, bundles), sin vender humo.',
      'Si el usuario saluda o pregunta qué podés hacer, explicá capacidades de catálogo (ver producto, precio, stock, categorías) y cómo pedir cambios.',
      'No uses listas largas. No uses tono robótico. Máximo 1 emoji.'
    ) );

    $user = "Mensaje del usuario: \n" . $msg . "\n\n";
    if ( $hint !== '' ) {
      $user .= "Pista de contexto (segura):\n" . $hint . "\n\n";
    }
    $user .= "Contexto Lite (JSON):\n" . $ctx;

    $res = APAI_Core::llm_inference( array(
      'feature' => 'brain',
      'action'  => 'chitchat',
      'messages' => array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => $user),
      ),
      'max_tokens' => 220,
      'temperature' => 0.6,
    ) );

    if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
      return array( 'ok' => false, 'text' => '' );
    }

    $text = '';
    if ( isset( $res['text'] ) ) {
      $text = (string) $res['text'];
    } elseif ( isset( $res['data']['text'] ) ) {
      $text = (string) $res['data']['text'];
    } elseif ( isset( $res['data']['output'] ) ) {
      $text = (string) $res['data']['output'];
    }

    $text = trim( $text );
    if ( $text === '' ) {
      return array( 'ok' => false, 'text' => '' );
    }

    // A1.5.28c — Hard guardrails for "no bot / no hallucinations":
    // If we don't have enough context, we must ask what the user means, not pretend.
    // Also never enumerate "categories" unless they are explicitly provided.
    $starts_with_entend = ( preg_match( '/^\s*(entiendo|perfecto|ok(ay)?)[\s\pP]+/iu', $text ) === 1 );
    $has_numbered_list = ( preg_match( '/\n\s*\d+\s*[\.)]/u', $text ) === 1 );
    $mentions_cats = ( stripos( $text, 'categor' ) !== false );

    if ( $starts_with_entend || $has_numbered_list || $mentions_cats ) {
      // Replace with an honest, concrete clarification.
      $quoted = trim( $input );
      if ( mb_strlen( $quoted ) > 140 ) {
        $quoted = mb_substr( $quoted, 0, 140 ) . '…';
      }
      $text = "No estoy seguro a qué te referís con \"{$quoted}\".\n\n¿Podés decirme qué querés hacer en la tienda? Por ejemplo:\n• Consultar **precio/stock** de un producto (ID/SKU/nombre)\n• Buscar un producto\n• Cambiar **precio/stock/categoría** (te lo preparo con botones)";
    }

    return array( 'ok' => true, 'text' => $text );
  }
}
