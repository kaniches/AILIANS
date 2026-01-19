<?php
/**
 * Plugin Name: AutoProduct AI Brain
 * Description: Cerebro conversacional. Dueño del "Chat de Agentes".
 * Version: A1.5.29
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'APAI_BRAIN_PATH' ) ) {
    define( 'APAI_BRAIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'APAI_BRAIN_URL' ) ) {
    define( 'APAI_BRAIN_URL', plugin_dir_url( __FILE__ ) );
}
// Bump this whenever we ship UI changes to force cache-busting for admin assets.
if ( ! defined( 'APAI_BRAIN_VERSION' ) ) {
define( 'APAI_BRAIN_VERSION', '1.2.80' );
}

// Bootstrap (centralizes includes; no behavior change)
require_once APAI_BRAIN_PATH . 'includes/bootstrap.php';

// F6.OBS: REST Observability glue (optional headers / CORS expose). Never breaks contract.
if ( class_exists( 'APAI_Brain_REST_Observability' ) ) {
    APAI_Brain_REST_Observability::init();
}

// Aviso si falta Core
add_action( 'init', function () {
    if ( ! class_exists( 'APAI_Core' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>AutoProduct AI Brain</strong> requiere que el plugin <strong>AutoProduct AI Core</strong> esté activo.</p></div>';
        } );
    }
} );

// Admin menu (submenú dentro de AutoProduct AI Core)
add_action( 'admin_menu', array( 'APAI_Brain_Admin', 'register_menu' ), 30 );

// F6.5 Telemetría / Dataset (settings + admin-post actions).
add_action( 'admin_init', array( 'APAI_Brain_Admin', 'register_telemetry_settings' ) );
add_action( 'admin_post_apai_brain_telemetry_download', array( 'APAI_Brain_Admin', 'handle_telemetry_download' ) );
add_action( 'admin_post_apai_brain_telemetry_clear', array( 'APAI_Brain_Admin', 'handle_telemetry_clear' ) );

// REST routes
add_action( 'rest_api_init', array( 'APAI_Brain_REST', 'register_routes' ) );