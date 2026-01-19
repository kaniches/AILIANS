<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Query_H_Salud_Clarify {

    public static function matches( $m_norm, $wants_count ) {
        $m_norm = (string) $m_norm;
        return ( strpos( $m_norm, 'salud' ) !== false && strpos( $m_norm, 'catalog' ) === false );
    }

    public static function handle( $message_raw, $m_norm, $context_full, $wants_count, $limit ) {
        $text = '¿Te referís a **“salud del catálogo”** (A8)? Si querés, escribí: **salud del catálogo** o **salud del catálogo full**.';
        return APAI_Brain_Response_Builder::make_response( 'consult', $text );
    }
}
