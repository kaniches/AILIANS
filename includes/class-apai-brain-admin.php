<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APAI_Brain_Admin {

    /** @var string|null */
    private static $page_hook = null;

    /** @var string|null */
    private static $telemetry_hook = null;

    public static function register_menu() {
        if ( ! class_exists( 'APAI_Core' ) ) {
            return;
        }

        self::$page_hook = add_submenu_page(
            'autoproduct-ai',
            'Chat de Agentes',
            'Chat de Agentes',
            'manage_woocommerce',
            'autoproduct-ai-agents-chat',
            array( __CLASS__, 'render_page' )
        );

        self::$telemetry_hook = add_submenu_page(
            'autoproduct-ai',
            'Brain Telemetry',
            'Brain Telemetry',
            'manage_woocommerce',
            'apai-brain-telemetry',
            array( __CLASS__, 'render_telemetry_page' )
        );

        // Enqueue assets only for this screen.
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Enqueue UI assets in the correct WP lifecycle (head), only on our screen.
     * This prevents CSS/JS loading inconsistencies and cache weirdness.
     */
    public static function enqueue_assets( $hook_suffix ) {
        if ( empty( self::$page_hook ) || $hook_suffix !== self::$page_hook ) {
            return;
        }

        // Cache busting: base on plugin version plus each file's mtime.
        // Some hosts/browsers are aggressive with caching; this makes updates deterministic.
        $base_ver = defined( 'APAI_BRAIN_VERSION' ) ? (string) APAI_BRAIN_VERSION : 'dev';

        // Ensure WP dashicons are available (menu icons + clipboard icon).
        wp_enqueue_style( 'dashicons' );

        // CSS
        wp_enqueue_style(
            'apai-brain-chat-css-base',
            APAI_BRAIN_URL . 'assets/css/admin-agent/base.css',
            array(),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/css/admin-agent/base.css' )
        );

        wp_enqueue_style(
            'apai-brain-chat-css-shell',
            APAI_BRAIN_URL . 'assets/css/admin-agent/shell.css',
            array( 'apai-brain-chat-css-base' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/css/admin-agent/shell.css' )
        );

        wp_enqueue_style(
            'apai-brain-chat-css-cards',
            APAI_BRAIN_URL . 'assets/css/admin-agent/cards.css',
            array( 'apai-brain-chat-css-base' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/css/admin-agent/cards.css' )
        );

        wp_enqueue_style(
            'apai-brain-chat-css-selector',
            APAI_BRAIN_URL . 'assets/css/admin-agent/selector.css',
            array( 'apai-brain-chat-css-cards' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/css/admin-agent/selector.css' )
        );

        wp_enqueue_style(
            'apai-brain-chat-css-menu',
            APAI_BRAIN_URL . 'assets/css/admin-agent/menu.css',
            array( 'apai-brain-chat-css-base' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/css/admin-agent/menu.css' )
        );

        // JS
        wp_enqueue_script(
            'apai-brain-chat-utils',
            APAI_BRAIN_URL . 'assets/js/admin-agent/utils.js',
            array(),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/utils.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-layout',
            APAI_BRAIN_URL . 'assets/js/admin-agent/layout.js',
            array( 'apai-brain-chat-utils' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/layout.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-shell',
            APAI_BRAIN_URL . 'assets/js/admin-agent/shell.js',
            array( 'apai-brain-chat-layout' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/shell.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-typing',
            APAI_BRAIN_URL . 'assets/js/admin-agent/typing.js',
            array( 'apai-brain-chat-utils' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/typing.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-trace',
            APAI_BRAIN_URL . 'assets/js/admin-agent/trace.js',
            array( 'apai-brain-chat-utils' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/trace.js' ),
            true
        );

        // F6.UI — Split the former God-file `core.js` into smaller modules.
        // Load these modules BEFORE core.js so core.js can stay thin.
        wp_enqueue_script(
            'apai-brain-chat-core-ui',
            APAI_BRAIN_URL . 'assets/js/admin-agent/core-ui.js',
            array( 'apai-brain-chat-utils', 'apai-brain-chat-layout', 'apai-brain-chat-shell', 'apai-brain-chat-typing', 'apai-brain-chat-trace' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/core-ui.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-core-cards',
            APAI_BRAIN_URL . 'assets/js/admin-agent/core-cards.js',
            array( 'apai-brain-chat-core-ui', 'apai-brain-chat-utils', 'apai-brain-chat-layout', 'apai-brain-chat-shell', 'apai-brain-chat-typing', 'apai-brain-chat-trace' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/core-cards.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-core',
            APAI_BRAIN_URL . 'assets/js/admin-agent/core.js',
            array( 'apai-brain-chat-utils', 'apai-brain-chat-layout', 'apai-brain-chat-shell', 'apai-brain-chat-typing', 'apai-brain-chat-trace', 'apai-brain-chat-core-ui', 'apai-brain-chat-core-cards' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/core.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-menu',
            APAI_BRAIN_URL . 'assets/js/admin-agent/menu.js',
            array( 'apai-brain-chat-utils' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/menu.js' ),
            true
        );

        wp_enqueue_script(
            'apai-brain-chat-copy',
            APAI_BRAIN_URL . 'assets/js/admin-agent/copy.js',
            array( 'apai-brain-chat-utils', 'apai-brain-chat-core', 'apai-brain-chat-trace' ),
            $base_ver . '-' . filemtime( APAI_BRAIN_PATH . 'assets/js/admin-agent/copy.js' ),
            true
        );

        // Localize (REST endpoints + nonce)
        $core_ok  = class_exists( 'APAI_Core' );
        $has_cat_agent = class_exists( 'APAI_Agent_REST' ) || defined( 'APAI_AGENT_VERSION' );
        $rest_nonce = wp_create_nonce( 'wp_rest' );

        wp_localize_script(
            'apai-brain-chat-core',
            'APAI_AGENT_DATA',
            array(
                'rest_url'      => esc_url_raw( rest_url( 'apai-brain/v1/chat' ) ),
                'product_search_url' => esc_url_raw( rest_url( 'apai-brain/v1/products/search' ) ),
                'product_summary_url' => esc_url_raw( rest_url( 'apai-brain/v1/products/summary' ) ),
                'trace_excerpt_url' => esc_url_raw( rest_url( 'apai-brain/v1/trace/excerpt' ) ),
                'execute_url'   => esc_url_raw( rest_url( 'apai-agent/v1/execute' ) ),
                // F6.OBS: Brain debug payload (Context Lite/Full) for humans.
                // Keep the old agent debug URL too, in case it's needed.
                'debug_url'        => esc_url_raw( rest_url( 'apai-brain/v1/debug' ) ),
                'brain_debug_url'  => esc_url_raw( rest_url( 'apai-brain/v1/debug' ) ),
                'agent_debug_url'  => esc_url_raw( rest_url( 'apai-agent/v1/debug' ) ),
                // Convenience links for opening Debug in a tab (cookie-auth REST needs a nonce).
                // Note: we keep `debug_url` as the base URL because the UI uses X-WP-Nonce headers.
                'debug_url_lite'   => esc_url_raw( add_query_arg( array(
                    'level'    => 'lite',
                    '_wpnonce' => $rest_nonce,
                ), rest_url( 'apai-brain/v1/debug' ) ) ),
                'debug_url_full'   => esc_url_raw( add_query_arg( array(
                    'level'    => 'full',
                    '_wpnonce' => $rest_nonce,
                ), rest_url( 'apai-brain/v1/debug' ) ) ),
                // F6.7: QA harness (regression checks). Note: cookie-auth REST requires a nonce.
                // We provide convenient links with `_wpnonce` so admins can open them directly in a tab.
                'qa_url'           => esc_url_raw( add_query_arg( array(
                    'verbose'  => 1,
                    '_wpnonce' => $rest_nonce,
                ), rest_url( 'apai-brain/v1/qa/run' ) ) ),
                'qa_url_quick'     => esc_url_raw( add_query_arg( array(
                    'quick'    => 1,
                    '_wpnonce' => $rest_nonce,
                ), rest_url( 'apai-brain/v1/qa/run' ) ) ),
                'clear_pending_url' => esc_url_raw( rest_url( 'apai-brain/v1/pending/clear' ) ),
                'nonce'         => $rest_nonce,
                'has_cat_agent' => (bool) $has_cat_agent,
                'core_ok'       => (bool) $core_ok,
            )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $core_ok  = class_exists( 'APAI_Core' );
        $llm_mode = ( $core_ok && method_exists( 'APAI_Core', 'get_option' ) ) ? APAI_Core::get_option( 'llm_mode', 'saas' ) : 'saas';

        // NOTE: Core keeps a legacy method name (get_openai_client). We treat it as an abstract LLM client.
        $llm_connected = false;
        if ( $core_ok && method_exists( 'APAI_Core', 'get_openai_client' ) ) {
            $llm_client = APAI_Core::get_openai_client();
            $llm_connected = ( $llm_client && method_exists( $llm_client, 'has_key' ) ) ? (bool) $llm_client->has_key() : false;
        }
        $has_cat_agent = class_exists( 'APAI_Agent_REST' ) || defined( 'APAI_AGENT_VERSION' );

        $url_products = admin_url( 'edit.php?post_type=product' );
        $url_settings = admin_url( 'options-general.php' );
        ?>
        <div class="wrap apai-agent-wrap">
            <div id="apai_shell" class="apai-shell apai-shell--collapsed" aria-label="AutoProduct AI">
                <aside class="apai-side" aria-label="Navegación">
                    <div class="apai-side-top">
                        <button type="button" id="apai_side_toggle" class="apai-side-toggle" aria-label="Expandir/colapsar menú" title="Menú">
                            <span class="dashicons dashicons-menu"></span>
                        </button>
                        <div class="apai-brand" aria-label="AI LIANS">
                            <span class="apai-brand-title">AI LIANS</span>
                            <span class="apai-brand-sub">Panel de agentes</span>
                        </div>
                    </div>

                    <nav class="apai-nav" aria-label="Accesos">
                        <a class="apai-nav-item is-active" href="<?php echo esc_url( admin_url( 'admin.php?page=autoproduct-ai-agents-chat' ) ); ?>" aria-label="Chat">
                            <span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
                            <span class="apai-nav-text">Chat</span>
                        </a>

                        <a class="apai-nav-item" href="<?php echo esc_url( $url_products ); ?>" aria-label="Productos">
                            <span class="dashicons dashicons-products" aria-hidden="true"></span>
                            <span class="apai-nav-text">Productos</span>
                        </a>

                        <a class="apai-nav-item" href="#" aria-label="Acciones (pronto)">
                            <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                            <span class="apai-nav-text">Acciones</span>
                        </a>

                        <a class="apai-nav-item" href="<?php echo esc_url( $url_settings ); ?>" aria-label="Ajustes">
                            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                            <span class="apai-nav-text">Ajustes</span>
                        </a>
                    </nav>

                    <div class="apai-side-bottom" aria-label="Usuario">
                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                        <span class="apai-nav-text">Admin</span>
                    </div>
                </aside>

                <main class="apai-main" aria-label="Chat">

                    <?php if ( ! $core_ok ) : ?>
                        <div class="notice notice-error"><p><strong>Falta AutoProduct AI Core.</strong> Activá el plugin Core.</p></div>
                    <?php elseif ( $llm_mode !== 'saas' ) : ?>
                        <div class="notice notice-warning"><p><strong>La IA está en modo no-SaaS.</strong> Entrá a <strong>AutoProduct AI → Core</strong> y cambiá el modo LLM a <strong>SaaS</strong>.</p></div>
                    <?php elseif ( ! $llm_connected ) : ?>
                        <div class="notice notice-warning"><p><strong>La tienda no está conectada al servicio AutoProduct AI.</strong> Revisá la conexión en <strong>AutoProduct AI → Core</strong> (Pairing/URL + API key) para habilitar IA.</p></div>
                    <?php endif; ?>

                    <div class="apai-main-wordmark" aria-hidden="true">AI LIANS</div>

                    <div id="apai_agent_chat" class="apai-agent-chat">
                        <div id="apai_agent_messages" class="apai-agent-messages"></div>

                        <div id="apai_agent_debug_wrap" class="apai-agent-debug" style="display:none;">
                            <pre id="apai_agent_debug_pre"></pre>
                        </div>

                        <div class="apai-agent-bottom">
                            <div class="apai-composer-card">
                            <div class="apai-agent-inputbar">
                                <button type="button" class="apai-btn-plus" id="apai_agent_plus" aria-label="Más opciones" title="Más opciones">+</button>
                                <textarea id="apai_agent_input" rows="1" placeholder="Pregunta lo que quieras"></textarea>
                                <button id="apai_agent_send" class="apai-btn-send" type="button">Enviar</button>
                            </div>

                            <div class="apai-agent-footerbar">
                                <div class="apai-footer-left">
                                    <span class="apai-footer-label">Agente ejecutor:</span>
                                    <select id="apai_agent_selector">
                                        <option value="catalog">Agente de Catálogo</option>
                                    </select>
                                    <?php if ( ! $has_cat_agent ) : ?>
                                        <span class="apai-footer-warn">(No activo: no se podrán ejecutar acciones)</span>
                                    <?php endif; ?>
                                </div>

                                <div class="apai-footer-right">
                                    <button type="button" class="apai-btn-copy" id="apai_agent_copy_all" title="Copiar chat + debug full">
                                        <span class="dashicons dashicons-clipboard"></span>
                                        <span class="apai-btn-copy-label">Copiar</span>
                                    </button>
                                    <button type="button" class="apai-btn-debug apai-btn-qa" id="apai_agent_qa_quick" title="QA rápido (quick)">QA</button>
                                    <button type="button" class="apai-btn-debug apai-btn-qa" id="apai_agent_qa_verbose" title="QA completo (verbose)">QA+</button>
        <button type="button" id="apai_agent_qa_regression" class="apai-pill" data-href="<?php echo esc_url( $agent_data['qa_regression_url'] ?? '' ); ?>">REG</button>
                                    <button type="button" class="apai-btn-debug" id="apai_agent_debug_toggle">Mostrar Debug</button>
                                    <select id="apai_agent_debug_level">
                                        <option value="lite" selected>Lite</option>
                                        <option value="full">Full</option>
                                    </select>
                                </div>
                            </div>
                            </div>
                        </div>
                    </div>

                </main>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // F6.5 Telemetría / Dataset (JSONL en uploads)
    // ---------------------------------------------------------------------

    public static function register_telemetry_settings() {
        // We keep it simple: one boolean option.
        register_setting(
            'apai_brain_telemetry',
            'apai_brain_telemetry_enabled',
            array(
                'type'              => 'string',
                'sanitize_callback' => function ( $value ) {
                    return ( $value === '1' ) ? '1' : '0';
                },
                'default'           => '0',
            )
        );
    }

    public static function render_telemetry_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tenés permisos para ver esto.', 'autoproduct-ai-brain' ) );
        }

        $enabled = function_exists( 'get_option' ) ? get_option( 'apai_brain_telemetry_enabled', '0' ) : '0';
        $enabled = ( $enabled === '1' );

        $dir  = class_exists( 'APAI_Brain_Telemetry' ) ? APAI_Brain_Telemetry::dir_path() : '';
        $file = class_exists( 'APAI_Brain_Telemetry' ) ? APAI_Brain_Telemetry::latest_file_path() : '';
        $size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
        $mtime = ( $file && file_exists( $file ) ) ? filemtime( $file ) : 0;

        $download_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=apai_brain_telemetry_download' ),
            'apai_brain_telemetry_download'
        );
        $clear_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=apai_brain_telemetry_clear' ),
            'apai_brain_telemetry_clear'
        );

        ?>
        <div class="wrap">
            <h1>Brain Telemetry</h1>
            <p>Guarda un dataset <strong>JSONL</strong> por interacción del chat (F6.5) en <code>wp-content/uploads</code>.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'apai_brain_telemetry' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">Telemetría</th>
                        <td>
                            <label>
                                <input type="checkbox" name="apai_brain_telemetry_enabled" value="1" <?php checked( $enabled ); ?> />
                                Habilitar dataset JSONL
                            </label>
                            <p class="description">Cuando está habilitado, el Brain agrega un registro por request. No cambia el comportamiento del agente.</p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button( 'Guardar' ); ?>
            </form>

            <h2>Estado</h2>
            <table class="widefat" style="max-width: 900px;">
                <tbody>
                <tr>
                    <th style="width: 220px;">Directorio</th>
                    <td><code><?php echo esc_html( $dir ? $dir : '(no disponible)' ); ?></code></td>
                </tr>
                <tr>
                    <th>Archivo más reciente</th>
                    <td><code><?php echo esc_html( $file ? basename( $file ) : '(no hay)' ); ?></code></td>
                </tr>
                <tr>
                    <th>Tamaño</th>
                    <td><?php echo esc_html( number_format_i18n( (int) $size ) ); ?> bytes</td>
                </tr>
                <tr>
                    <th>Última modificación</th>
                    <td><?php echo esc_html( $mtime ? gmdate( 'Y-m-d H:i:s', (int) $mtime ) . ' UTC' : '-' ); ?></td>
                </tr>
                </tbody>
            </table>

            <p style="margin-top: 16px;">
                <a href="<?php echo esc_url( $download_url ); ?>" class="button button-primary">Descargar JSONL (último)</a>
                <a href="<?php echo esc_url( $clear_url ); ?>" class="button" onclick="return confirm('¿Borrar todos los JSONL de telemetría?');">Borrar dataset</a>
            </p>
        </div>
        <?php
    }

    public static function handle_telemetry_download() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'apai_brain_telemetry_download' );

        if ( ! class_exists( 'APAI_Brain_Telemetry' ) ) {
            wp_die( 'Telemetry unavailable', 500 );
        }

        $file = APAI_Brain_Telemetry::latest_file_path();
        if ( ! $file || ! file_exists( $file ) ) {
            wp_die( 'No dataset found', 404 );
        }

        // Download headers.
        nocache_headers();
        header( 'Content-Type: application/x-ndjson; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
        header( 'Content-Length: ' . filesize( $file ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        readfile( $file );
        exit;
    }

    public static function handle_telemetry_clear() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'apai_brain_telemetry_clear' );

        if ( class_exists( 'APAI_Brain_Telemetry' ) ) {
            APAI_Brain_Telemetry::clear_all();
        }

        wp_safe_redirect( admin_url( 'admin.php?page=apai-brain-telemetry' ) );
        exit;
    }
}
