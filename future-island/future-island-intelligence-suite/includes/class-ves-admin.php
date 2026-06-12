<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Admin {
    const OPTION_KEY     = 'ves_settings';
    const DIAG_KEY       = 'ves_last_diagnostic';
    const DIAG_LOG_KEY   = 'ves_diagnostic_log';
    const DIAG_LOG_LIMIT = 30;

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_fi_assets']);
        add_action('admin_post_ves_clear_diagnostics', [__CLASS__, 'handle_clear_diagnostics']);
        add_action('admin_post_ves_clear_runtime_locks', [__CLASS__, 'handle_clear_runtime_locks']);
        add_action('admin_post_ves_test_apify_connection', [__CLASS__, 'handle_test_apify_connection']);
        add_action('admin_post_ves_memory_admin_action', [__CLASS__, 'handle_memory_admin_action']);
        add_action('admin_post_ves_project_admin_action', [__CLASS__, 'handle_project_admin_action']);
    }

    // Intentionally suppressed in the unified Intelligence Suite —
    // Deep Trend Finder is always present as a built-in module.
    public static function addon_integration_notice() {}


    public static function admin_menu() {
        $suite_label = class_exists('VES_Branding') ? VES_Branding::product_name() : 'Intelligence Suite';
        add_options_page(
            $suite_label . ' — Settings',
            $suite_label,
            'manage_options',
            'ves-social-scraper',
            [__CLASS__, 'render_settings_page']
        );
        $ops_label = class_exists('VES_Branding')
            ? sprintf('%s Ops', VES_Branding::product_name())
            : 'Intelligence Suite Ops';
        add_management_page(
            $ops_label,
            $ops_label,
            'manage_options',
            'ves-operations',
            [__CLASS__, 'render_operations_page']
        );
        add_management_page(
            'VE Memory / Knowledge',
            'VE Memory / Knowledge',
            'manage_options',
            'ves-memory-knowledge',
            [__CLASS__, 'render_memory_knowledge_page']
        );
        add_management_page(
            'VE Actor Registry',
            'VE Actor Registry',
            'manage_options',
            'ves-actor-registry',
            [__CLASS__, 'render_actor_registry_page']
        );
        add_management_page(
            'VE Projects',
            'VE Projects',
            'manage_options',
            'ves-projects',
            [__CLASS__, 'render_projects_page']
        );
        add_management_page('VE Adapter Diagnostics', 'VE Adapter Diagnostics', 'manage_options', 'ves-adapter-diagnostics', [__CLASS__, 'render_adapter_diagnostics_page']);
        add_management_page('VE Billing Ledger', 'VE Billing Ledger', 'manage_options', 'ves-billing-ledger', [__CLASS__, 'render_billing_ledger_page']);
        add_management_page('VE Prompt Templates', 'VE Prompt Templates', 'manage_options', 'ves-prompt-templates', [__CLASS__, 'render_prompt_templates_page']);
        add_management_page('VE Project Manager', 'VE Project Manager', 'manage_options', 'ves-project-manager', [__CLASS__, 'render_project_manager_page']);
        // Phase 7A/7B/8A — Future Island operator console + workbenches (read-only).
        add_management_page('Future Island — Signal Room', 'FI Signal Room', 'manage_options', 'fi-signal-room', [__CLASS__, 'render_signal_room_page']);
        add_management_page('Future Island — Brief Workbench', 'FI Brief Workbench', 'manage_options', 'fi-brief-workbench', [__CLASS__, 'render_brief_workbench_page']);
        add_management_page('Future Island — Draft Workbench', 'FI Draft Workbench', 'manage_options', 'fi-draft-workbench', [__CLASS__, 'render_draft_workbench_page']);
    }

    /** Phase 7A — load the Future Island design layer on our pages. */
    public static function enqueue_fi_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if (!in_array($page, ['fi-signal-room', 'fi-brief-workbench', 'fi-draft-workbench', 'ves-memory-knowledge'], true)) { return; }
        $base = defined('VES_PLUGIN_URL') ? VES_PLUGIN_URL : '';
        $ver = defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '1.0.0';
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('fiis-signal-room', $base . 'assets/css/fiis-signal-room.css', [], $ver);
            wp_enqueue_style('fiis-ui-system', $base . 'assets/css/fiis-ui-system.css', ['fiis-signal-room'], $ver);
        }
        if (function_exists('wp_enqueue_script')) { wp_enqueue_script('fiis-signal-room', $base . 'assets/js/fiis-signal-room.js', [], $ver, true); }
    }

    private static function fi_workspace_id() {
        $ws = sanitize_text_field(wp_unslash((string) ($_GET['workspace_id'] ?? '')));
        if ($ws === '' && class_exists('VES_Memory_Records')) { $ws = VES_Memory_Records::workspace_id_for_user(get_current_user_id()); }
        return (int) $ws;
    }

    public static function render_signal_room_page() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap ves-wrap">';
        echo class_exists('VES_Signal_Room') ? VES_Signal_Room::render_html(self::fi_workspace_id())
            : '<div class="notice notice-error"><p>' . esc_html__('Signal Room unavailable.', 'ves') . '</p></div>';
        echo '</div>';
    }

    public static function render_brief_workbench_page() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap ves-wrap">';
        echo class_exists('VES_Workbench') ? VES_Workbench::render_brief(['workspace_id' => self::fi_workspace_id(), 'insight_id' => absint($_GET['insight_id'] ?? 0)])
            : '<div class="notice notice-error"><p>' . esc_html__('Workbench unavailable.', 'ves') . '</p></div>';
        echo '</div>';
    }

    public static function render_draft_workbench_page() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap ves-wrap">';
        echo class_exists('VES_Workbench') ? VES_Workbench::render_draft(['workspace_id' => self::fi_workspace_id(), 'brief_id' => absint($_GET['brief_id'] ?? 0)])
            : '<div class="notice notice-error"><p>' . esc_html__('Workbench unavailable.', 'ves') . '</p></div>';
        echo '</div>';
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::defaults(),
        ]);

        add_settings_section(
            'ves_api_section',
            'API y modelos',
            function () {
                echo '<p>Configura las credenciales del scraper y de OpenAI. Las constantes de <code>wp-config.php</code> tienen prioridad sobre lo guardado aquí.</p>';
            },
            self::OPTION_KEY
        );

        add_settings_field('backend_token', 'Token backend / Apify', [__CLASS__, 'render_password_field'], self::OPTION_KEY, 'ves_api_section', [
            'key' => 'backend_token',
            'placeholder' => 'apify_api_...',
            'description' => 'Se usa para lanzar los scrapers desde WordPress.',
        ]);

        add_settings_field('openai_api_key', 'OpenAI API Key', [__CLASS__, 'render_password_field'], self::OPTION_KEY, 'ves_api_section', [
            'key' => 'openai_api_key',
            'placeholder' => 'sk-...',
            'description' => 'Se usa para el botón de análisis AI.',
        ]);

        add_settings_field('openai_model', 'Modelo OpenAI legacy', [__CLASS__, 'render_model_field'], self::OPTION_KEY, 'ves_api_section');
        add_settings_field('fast_analysis_model', 'Modelo fast analysis', [__CLASS__, 'render_named_model_field'], self::OPTION_KEY, 'ves_api_section', ['key' => 'fast_analysis_model', 'description' => 'Default para Trend Finder sincrónico y análisis rápidos.']);
        add_settings_field('deep_analysis_model', 'Modelo deep analysis', [__CLASS__, 'render_named_model_field'], self::OPTION_KEY, 'ves_api_section', ['key' => 'deep_analysis_model', 'description' => 'Uso opcional/asíncrono para análisis profundos, no como default de UI.']);
        add_settings_field('brief_generation_model', 'Modelo brief generation', [__CLASS__, 'render_named_model_field'], self::OPTION_KEY, 'ves_api_section', ['key' => 'brief_generation_model', 'description' => 'Modelo usado para generación de briefs.']);
        add_settings_field('assistant_model', 'Modelo assistant', [__CLASS__, 'render_named_model_field'], self::OPTION_KEY, 'ves_api_section', ['key' => 'assistant_model', 'description' => 'Modelo usado por el asistente.']);
        add_settings_field('openai_reasoning_effort', 'GPT-5 reasoning effort', [__CLASS__, 'render_reasoning_field'], self::OPTION_KEY, 'ves_api_section');

        add_settings_section(
            'ves_runtime_section',
            'Límites y seguridad',
            function () {
                echo '<p>Ajustes operativos del plugin.</p>';
            },
            self::OPTION_KEY
        );

        add_settings_field('require_login', 'Requerir login', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', [
            'key' => 'require_login',
            'label' => 'Solo usuarios autenticados pueden usar el shortcode.',
        ]);

        add_settings_field('rate_limit_seconds', 'Rate limit (segundos)', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', [
            'key' => 'rate_limit_seconds',
            'min' => 5,
            'max' => 300,
            'step' => 1,
            'description' => 'Espera mínima entre ejecuciones por usuario/IP.',
        ]);

        add_settings_field('max_items', 'Máximo de resultados', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', [
            'key' => 'max_items',
            'min' => 1,
            'max' => 5000,
            'step' => 1,
            'description' => 'Límite duro de resultados por ejecución.',
        ]);

        add_settings_field('max_charge_usd', 'Máximo gasto por run (USD)', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', [
            'key' => 'max_charge_usd',
            'min' => 0.1,
            'max' => 100,
            'step' => 0.1,
            'description' => 'Tope de coste enviado al proveedor.',
        ]);

        add_settings_field('openai_fast_timeout_seconds', 'OpenAI fast timeout seconds', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'openai_fast_timeout_seconds', 'min' => 10, 'max' => 90, 'step' => 1, 'description' => 'Timeout para análisis rápidos sincrónicos.']);
        add_settings_field('openai_deep_timeout_seconds', 'OpenAI deep timeout seconds', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'openai_deep_timeout_seconds', 'min' => 10, 'max' => 90, 'step' => 1, 'description' => 'Timeout para análisis profundos/manuales.']);
        add_settings_field('openai_max_evidence_items', 'OpenAI max evidence items', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'openai_max_evidence_items', 'min' => 4, 'max' => 80, 'step' => 1, 'description' => 'Límite de items enviados al modelo para evitar timeouts.']);
        add_settings_field('openai_max_evidence_refs', 'OpenAI max evidence refs', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'openai_max_evidence_refs', 'min' => 4, 'max' => 80, 'step' => 1, 'description' => 'Límite de referencias/evidencias enviadas al modelo.']);
        add_settings_field('memory_retention_days', 'Memory retention days', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'memory_retention_days', 'min' => 1, 'max' => 90, 'step' => 1, 'description' => 'Operational memory retention. Default is 7 days; pinned memories can be retained longer.']);
        add_settings_field('ai_context_max_memory_records', 'AI context max memory records', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'ai_context_max_memory_records', 'min' => 0, 'max' => 20, 'step' => 1, 'description' => 'Caps memory records injected into AI prompts.']);
        add_settings_field('ai_context_max_approved_insights', 'AI context max approved insights', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'ai_context_max_approved_insights', 'min' => 0, 'max' => 20, 'step' => 1, 'description' => 'Only approved/accepted/saved insights are injected strongly.']);
        add_settings_field('ai_context_max_chars', 'AI context max characters', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'ai_context_max_chars', 'min' => 2000, 'max' => 40000, 'step' => 500, 'description' => 'Hard cap for cumulative AI memory context to control token usage.']);
        add_settings_field('semantic_retrieval_enabled', 'Enable semantic retrieval', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'semantic_retrieval_enabled', 'label' => 'Use semantic-lite memory retrieval before AI analysis. Uses local keyword scoring by default; optional embeddings can be injected via filters.']);
        add_settings_field('ai_context_max_semantic_matches', 'AI context max semantic matches', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'ai_context_max_semantic_matches', 'min' => 0, 'max' => 20, 'step' => 1, 'description' => 'Caps semantic matches injected into AI context.']);
        add_settings_field('semantic_min_score', 'Semantic retrieval min score', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'semantic_min_score', 'min' => 0, 'max' => 200, 'step' => 1, 'description' => 'Minimum semantic-lite score required before a memory item is injected.']);
        add_settings_field('knowledge_graph_enabled', 'Enable knowledge graph', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'knowledge_graph_enabled', 'label' => 'Use consolidated workspace entities and relationships in AI context.']);
        add_settings_field('knowledge_consolidation_enabled', 'Enable periodic consolidation', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'knowledge_consolidation_enabled', 'label' => 'Run daily lightweight memory consolidation into the knowledge graph.']);
        add_settings_field('ai_context_max_knowledge_graph_items', 'AI context max graph items', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'ai_context_max_knowledge_graph_items', 'min' => 0, 'max' => 30, 'step' => 1, 'description' => 'Caps knowledge graph nodes injected into AI context.']);
        add_settings_field('apify_max_active_runs', 'Apify max active runs', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'apify_max_active_runs', 'min' => 1, 'max' => 50, 'step' => 1, 'description' => 'Límite global local de dispatch concurrente Apify.']);
        add_settings_field('apify_max_active_runs_per_user', 'Apify max active runs per user', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'apify_max_active_runs_per_user', 'min' => 1, 'max' => 20, 'step' => 1, 'description' => 'Límite local de dispatch concurrente por usuario.']);
        add_settings_field('apify_max_heavy_runs', 'Apify max heavy actor runs', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'apify_max_heavy_runs', 'min' => 1, 'max' => 20, 'step' => 1, 'description' => 'Límite local para actores pesados.']);
        add_settings_field('apify_max_sources_per_run', 'Apify max sources per run', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'apify_max_sources_per_run', 'min' => 1, 'max' => 100, 'step' => 1, 'description' => 'Tope operativo de fuentes Apify por run antes de avisar o batchificar.']);
        add_settings_field('apify_max_estimated_cost_per_run', 'Apify max estimated provider cost/run', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'apify_max_estimated_cost_per_run', 'min' => 0, 'max' => 1000, 'step' => 0.01, 'description' => 'Límite interno de coste externo estimado Apify por run. No son créditos internos.']);
        add_settings_field('apify_cost_warning_threshold_usd', 'Apify provider cost warning threshold', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'apify_cost_warning_threshold_usd', 'min' => 0, 'max' => 1000, 'step' => 0.01, 'description' => 'Muestra aviso si un run puede superar este coste externo estimado.']);
        add_settings_field('max_external_provider_cost_per_run', 'Trend Finder max external provider cost/run', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'max_external_provider_cost_per_run', 'min' => 0, 'max' => 1000, 'step' => 0.01, 'description' => 'Guardrail específico de Trend Finder para coste externo Apify. Separado de créditos internos.']);
        add_settings_field('enable_youtube_trending_in_trend_finder', 'Trend Finder: enable YouTube Trending', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'enable_youtube_trending_in_trend_finder', 'label' => 'Ejecutar YouTube Trending como contexto de mercado opcional. Desactivado por defecto por coste y baja precisión temática.']);
        add_settings_field('enable_twitter_trends_in_trend_finder', 'Trend Finder: enable X/Twitter Trends', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'enable_twitter_trends_in_trend_finder', 'label' => 'Ejecutar X/Twitter Trends como fuente opcional y capada. Desactivado por defecto para evitar 800+ resultados genéricos.']);
        add_settings_field('max_twitter_trend_items', 'Trend Finder: max Twitter trend items', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'max_twitter_trend_items', 'min' => 5, 'max' => 200, 'step' => 1, 'description' => 'Tope de records X/Twitter almacenados/enviados a AI aunque el actor devuelva cientos.']);
        add_settings_field('max_youtube_trending_cost', 'Trend Finder: max YouTube Trending estimate', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'max_youtube_trending_cost', 'min' => 0, 'max' => 100, 'step' => 0.01, 'description' => 'Estimación máxima permitida para YouTube Trending; se trata como market_context salvo match directo.']);
        add_settings_field('brand_audit_batch_size', 'Brand Audit batch size', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'brand_audit_batch_size', 'min' => 1, 'max' => 20, 'step' => 1, 'description' => 'Tamaño del lote para dispatch de fuentes Brand Audit.']);
        add_settings_field('brand_audit_max_competitors_standard', 'Brand Audit max competitors - standard', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'brand_audit_max_competitors_standard', 'min' => 0, 'max' => 20, 'step' => 1, 'description' => 'Default cap for competitor expansion in standard Brand Audit runs.']);
        add_settings_field('brand_audit_max_competitors_deep', 'Brand Audit max competitors - deep', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'brand_audit_max_competitors_deep', 'min' => 0, 'max' => 30, 'step' => 1, 'description' => 'Default cap for competitor expansion in deep Brand Audit runs.']);
        add_settings_field('brand_audit_max_heavy_sources_per_phase', 'Brand Audit heavy sources per phase', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'brand_audit_max_heavy_sources_per_phase', 'min' => 1, 'max' => 10, 'step' => 1, 'description' => 'Maximum Apify-heavy sources dispatched at once inside each Brand Audit phase.']);
        add_settings_field('brand_audit_enable_competitor_social_expansion', 'Enable competitor social expansion', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'brand_audit_enable_competitor_social_expansion', 'label' => 'Allow Brand Audit to run direct competitor social scraping sources.']);
        add_settings_field('brand_audit_auto_expand_after_phase_1', 'Auto expand after phase 1', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', ['key' => 'brand_audit_auto_expand_after_phase_1', 'label' => 'Allow optional phase 5 expansion after foundation evidence is collected.']);

        add_settings_field('admin_error_details', 'Errores detallados para admin', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', [
            'key' => 'admin_error_details',
            'label' => 'Mostrar más detalle técnico solo a administradores conectados.',
        ]);

        add_settings_field('delete_data_on_uninstall', 'Borrar datos al desinstalar', [__CLASS__, 'render_checkbox_field'], self::OPTION_KEY, 'ves_runtime_section', [
            'key' => 'delete_data_on_uninstall',
            'label' => 'Eliminar tablas y opciones del plugin al desinstalar. Desactivado por defecto para proteger datos SaaS.',
        ]);


        add_settings_section(
            'ves_billing_section',
            'Créditos, roles y acceso',
            function () {
                echo '<p>Controla el saldo inicial de cada cuenta, los costes por acción y qué roles pueden acceder al panel del SaaS.</p>';
            },
            self::OPTION_KEY
        );

        add_settings_field('default_signup_credits', 'Créditos iniciales por usuario', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'default_signup_credits',
            'min' => 0,
            'max' => 100000,
            'step' => 1,
            'description' => 'Saldo que recibe un usuario nuevo al registrarse por el shortcode del plugin.',
        ]);
        add_settings_field('registration_role', 'Rol al registrarse', [__CLASS__, 'render_select_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'registration_role',
            'options' => [
                'ves_customer' => 'VE SaaS Customer',
                'subscriber' => 'Subscriber',
            ],
            'description' => 'Rol que se asigna automáticamente a los nuevos usuarios del panel.',
        ]);
        add_settings_field('default_plan_slug', 'Plan por defecto', [__CLASS__, 'render_select_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'default_plan_slug',
            'options' => class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_plan_options() : [
                'free' => 'Free',
                'starter' => 'Starter',
                'pro' => 'Pro',
                'scale' => 'Scale',
            ],
            'description' => 'Plan asignado por defecto cuando un usuario se registra desde el shortcode.',
        ]);
        add_settings_field('panel_allowed_roles', 'Roles con acceso al panel', [__CLASS__, 'render_multi_checkbox_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'panel_allowed_roles',
            'options' => [
                'administrator' => 'Administrator',
                'editor' => 'Editor',
                'author' => 'Author',
                'subscriber' => 'Subscriber',
                'ves_customer' => 'VE SaaS Customer',
            ],
            'description' => 'Si un usuario autenticado no pertenece a uno de estos roles, verá un mensaje de acceso restringido.',
        ]);
        add_settings_field('cost_manual_run', 'Coste: extracción manual', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_manual_run',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos que consume una ejecución estándar de scraping.',
        ]);
        add_settings_field('cost_ai_analysis', 'Coste: análisis AI de ítem', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_ai_analysis',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos por analizar un resultado individual con OpenAI.',
        ]);
        add_settings_field('cost_trend_run', 'Coste: Trend Finder manual', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_trend_run',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos por un Trend Finder lanzado desde el panel.',
        ]);
        add_settings_field('cost_trend_brief', 'Coste: crear brief', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_trend_brief',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos al convertir una tendencia en brief.',
        ]);
        add_settings_field('cost_background_monitor', 'Coste: monitor automático', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_background_monitor',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos que consume cada ejecución automática en background.',
        ]);
        add_settings_field('cost_brand_audit_light', 'Coste: Brand Audit Light', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_brand_audit_light',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos por un Brand Deep Audit ligero.',
        ]);
        add_settings_field('cost_brand_audit_standard', 'Coste: Brand Audit Standard', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_brand_audit_standard',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos por un Brand Deep Audit estándar.',
        ]);
        add_settings_field('cost_brand_audit_deep', 'Coste: Brand Audit Deep', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_brand_audit_deep',
            'min' => 0,
            'max' => 1000,
            'step' => 0.5,
            'description' => 'Créditos por un Brand Deep Audit profundo.',
        ]);

        add_settings_field('cost_creative_asset_analysis', 'Coste: Creative Intelligence', [__CLASS__, 'render_number_field'], self::OPTION_KEY, 'ves_billing_section', [
            'key' => 'cost_creative_asset_analysis',
            'min' => 0,
            'step' => '0.5',
            'description' => 'Créditos base para analizar una pieza creativa o asset.'
        ]);

        add_settings_section(
            'ves_actor_section',
            'Actores / scrapers por plataforma',
            function () {
                echo '<p>Si un actor deja de funcionar, puedes cambiarlo aquí sin tocar código.</p>';
            },
            self::OPTION_KEY
        );

        foreach ([
            'tiktok_slug' => 'TikTok Discovery actor slug',
            'tiktok_enrichment_slug' => 'TikTok Deep Analysis / Enrichment actor slug',
            'tiktok_comments_slug' => 'TikTok Comments fallback actor slug',
            'tiktok_fallback_slug' => 'TikTok Discovery fallback actor slug',
            'tiktok_backup_slug' => 'TikTok Backup actor slug',
            'youtube_slug' => 'YouTube actor slug',
            'facebook_slug' => 'Facebook Posts actor slug',
            'instagram_slug' => 'Instagram actor slug',
            'facebook_ads_slug' => 'Facebook Ads Library actor slug',
            'google_ads_slug' => 'Google Ads Transparency actor slug',
            'twitter_slug' => 'X / Twitter actor slug',
            'linkedin_slug' => 'LinkedIn Profiles actor slug',
            'reddit_slug' => 'Reddit actor slug',
            'pinterest_slug' => 'Pinterest actor slug',
            'semrush_slug' => 'SEO/SEM/AEO · Semrush Agencies actor slug (legacy)',
            'semrush_keywords_slug' => 'SEO/SEM/AEO · Semrush Keywords actor slug',
            'semrush_keyword_magic_slug' => 'SEO/SEM/AEO · Semrush Keyword Magic actor slug',
            'semrush_domain_slug' => 'SEO/SEM/AEO · Semrush Domain/SEO actor slug',
            'moz_slug' => 'SEO/SEM/AEO · MOZ actor slug',
            'ahrefs_slug' => 'SEO/SEM/AEO · Ahrefs actor slug',
            'google_trends_slug' => 'Trend Finder · Google Trends actor slug (legacy)',
            'youtube_trending_slug' => 'Trend Finder · YouTube Trending actor slug (legacy)',
            'tiktok_trends_slug' => 'Trend Finder · TikTok Trends actor slug (legacy)',
            'twitter_trends_slug' => 'Trend Finder · X / Twitter Trends actor slug (legacy)',
        ] as $key => $label) {
            add_settings_field($key, $label, [__CLASS__, 'render_text_field'], self::OPTION_KEY, 'ves_actor_section', [
                'key' => $key,
                'placeholder' => self::defaults()[$key] ?? '',
            ]);
        }

        add_settings_section(
            'ves_trend_source_slots_section',
            'Trend Finder Source Slots',
            function () {
                echo '<p>Configura slots internos de inteligencia. Los usuarios normales no ven ni eligen actores; solo administradores pueden ajustar actor slug, estado, coste y notas.</p>';
            },
            self::OPTION_KEY
        );
        add_settings_field('trend_source_slots_table', 'Slots de fuente', [__CLASS__, 'render_trend_source_slots_field'], self::OPTION_KEY, 'ves_trend_source_slots_section', []);
    }


    public static function trend_source_slots() {
        return [
            'google_trends_interest' => ['label' => 'Google Trends interest', 'family' => 'search_demand', 'role' => 'topic interest baseline', 'default_slug' => 'data_xplorer/google-trends-fast-scraper', 'cost' => 'low', 'enabled' => 1],
            'google_trends_trending_now' => ['label' => 'Google Trends Trending Now', 'family' => 'search_demand', 'role' => 'ambient search trend feed', 'default_slug' => 'data_xplorer/google-trends-trending-now', 'cost' => 'low', 'enabled' => 0],
            'google_search_freshness' => ['label' => 'Google Search freshness', 'family' => 'search_demand', 'role' => 'fresh SERP evidence', 'default_slug' => 'apify/google-search-scraper', 'cost' => 'low', 'enabled' => 1],
            'google_news_freshness' => ['label' => 'Google News freshness', 'family' => 'market_context', 'role' => 'news freshness evidence', 'default_slug' => 'apify/google-search-scraper', 'cost' => 'low', 'enabled' => 1],
            'semrush_keyword_context' => ['label' => 'Semrush keyword context', 'family' => 'search_demand', 'role' => 'SEO/keyword context optional', 'default_slug' => 'burbn/semrush-keyword-magic-tool', 'cost' => 'medium', 'enabled' => 0],
            'x_topic_posts' => ['label' => 'X topic posts', 'family' => 'social_conversation', 'role' => 'topic conversation', 'default_slug' => 'apidojo/tweet-scraper', 'cost' => 'medium', 'enabled' => 1],
            'x_trend_list' => ['label' => 'X trend list', 'family' => 'social_trend_feed', 'role' => 'ambient trend list', 'default_slug' => 'karamelo/twitter-trends-scraper', 'cost' => 'medium', 'enabled' => 0],
            'tiktok_topic_videos' => ['label' => 'TikTok topic videos', 'family' => 'content_format', 'role' => 'topic video evidence', 'default_slug' => 'clockworks/tiktok-scraper', 'cost' => 'high', 'enabled' => 1],
            'tiktok_hashtag_trends' => ['label' => 'TikTok hashtag trends', 'family' => 'social_trend_feed', 'role' => 'hashtag trend context', 'default_slug' => 'lexis-solutions/tiktok-trending-hashtags', 'cost' => 'medium', 'enabled' => 0],
            'tiktok_trending_videos' => ['label' => 'TikTok trending videos', 'family' => 'social_trend_feed', 'role' => 'ambient video trend feed', 'default_slug' => 'data_xplorer/tiktok-trends', 'cost' => 'high', 'enabled' => 0],
            'tiktok_trending_creators' => ['label' => 'TikTok trending creators optional', 'family' => 'creative_context', 'role' => 'creator context optional', 'default_slug' => 'lexis-solutions/tiktok-trending-creators', 'cost' => 'high', 'enabled' => 0],
            'tiktok_trending_sounds' => ['label' => 'TikTok trending sounds optional', 'family' => 'creative_context', 'role' => 'sound context optional', 'default_slug' => 'alien_force/tiktok-trending-sounds', 'cost' => 'medium', 'enabled' => 0],
            'youtube_topic_videos' => ['label' => 'YouTube topic videos', 'family' => 'content_format', 'role' => 'topic video/search evidence', 'default_slug' => 'streamers/youtube-scraper', 'cost' => 'medium', 'enabled' => 1],
            'youtube_trending_categories' => ['label' => 'YouTube trending categories optional', 'family' => 'social_trend_feed', 'role' => 'ambient video category context', 'default_slug' => 'eunit/youtube-trending-videos-by-categories', 'cost' => 'medium', 'enabled' => 0],
            'reddit_topic_posts' => ['label' => 'Reddit topic posts', 'family' => 'social_conversation', 'role' => 'discussion/pain-point evidence', 'default_slug' => 'trudax/reddit-scraper-lite', 'cost' => 'low', 'enabled' => 1],
            'reddit_trends' => ['label' => 'Reddit trends optional', 'family' => 'social_trend_feed', 'role' => 'ambient discussion trends', 'default_slug' => 'easyapi/reddit-trends-scraper', 'cost' => 'medium', 'enabled' => 0],
            'ad_creative_scraper' => ['label' => 'Ad creative scraper optional', 'family' => 'creative_context', 'role' => 'ad creative context', 'default_slug' => 'curious_coder/facebook-ads-library-scraper', 'cost' => 'medium', 'enabled' => 0],
            'competitor_creative_scraper' => ['label' => 'Competitor creative scraper optional', 'family' => 'creative_context', 'role' => 'competitor creative context', 'default_slug' => 'dz_omar/google-ads-scraper', 'cost' => 'medium', 'enabled' => 0],
        ];
    }

    public static function defaults() {
        return [
            'backend_token' => '',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4.1-mini',
            'fast_analysis_model' => 'gpt-4.1-mini',
            'deep_analysis_model' => 'gpt-5-mini',
            'brief_generation_model' => 'gpt-4.1-mini',
            'assistant_model' => 'gpt-4.1-mini',
            'openai_reasoning_effort' => 'auto',
            'openai_fast_timeout_seconds' => 30,
            'openai_deep_timeout_seconds' => 55,
            'openai_max_evidence_items' => 24,
            'openai_max_evidence_refs' => 40,
            'memory_retention_days' => 7,
            'ai_context_max_memory_records' => 6,
            'ai_context_max_approved_insights' => 6,
            'ai_context_max_chars' => 14000,
            'semantic_retrieval_enabled' => true,
            'ai_context_max_semantic_matches' => 5,
            'semantic_min_score' => 20,
            'knowledge_graph_enabled' => true,
            'knowledge_consolidation_enabled' => true,
            'ai_context_max_knowledge_graph_items' => 8,
            'apify_max_active_runs' => 6,
            'apify_max_active_runs_per_user' => 3,
            'apify_max_heavy_runs' => 2,
            'apify_max_sources_per_run' => 12,
            'apify_max_estimated_cost_per_run' => 2.50,
            'apify_cost_warning_threshold_usd' => 1.00,
            'max_external_provider_cost_per_run' => 1.50,
            'enable_youtube_trending_in_trend_finder' => 0,
            'enable_twitter_trends_in_trend_finder' => 0,
            'max_twitter_trend_items' => 40,
            'max_youtube_trending_cost' => 0.25,
            'brand_audit_batch_size' => 6,
            'brand_audit_max_competitors_standard' => 3,
            'brand_audit_max_competitors_deep' => 5,
            'brand_audit_max_heavy_sources_per_phase' => 2,
            'brand_audit_enable_competitor_social_expansion' => 0,
            'brand_audit_auto_expand_after_phase_1' => 0,
            'require_login' => 0,
            'rate_limit_seconds' => 12,
            'max_items' => 500,
            'max_charge_usd' => 3.0,
            'admin_error_details' => 1,
            'delete_data_on_uninstall' => 0,
            'default_signup_credits' => 100,
            'registration_role' => 'ves_customer',
            'default_plan_slug' => 'starter',
            'panel_allowed_roles' => ['administrator', 'editor', 'author', 'ves_customer'],
            'cost_manual_run' => 1.0,
            'cost_ai_analysis' => 1.0,
            'cost_trend_run' => 4.0,
            'cost_trend_brief' => 1.0,
            'cost_background_monitor' => 4.0,
            'cost_brand_audit_light' => 8.0,
            'cost_brand_audit_standard' => 18.0,
            'cost_brand_audit_deep' => 35.0,
            'cost_creative_asset_analysis' => 8.0,
            'tiktok_slug' => 'apidojo/tiktok-scraper',
            'tiktok_enrichment_slug' => 'scraptik/tiktok-api',
            'tiktok_comments_slug' => 'scraptik/tiktok-comments-scraper-api',
            'tiktok_fallback_slug' => 'clockworks/tiktok-scraper',
            'tiktok_backup_slug' => 'api-ninja/tiktok-data-scraper',
            'youtube_slug' => 'streamers/youtube-scraper',
            'facebook_slug' => 'apify/facebook-posts-scraper',
            'instagram_slug' => 'apify/instagram-scraper',
            'facebook_ads_slug' => 'curious_coder/facebook-ads-library-scraper',
            'google_ads_slug' => 'dz_omar/google-ads-scraper',
            'twitter_slug' => 'apidojo/tweet-scraper',
            'linkedin_slug' => 'harvestapi/linkedin-profile-search',
            'reddit_slug' => 'trudax/reddit-scraper-lite',
            'pinterest_slug' => 'epctex/pinterest-scraper',
            'semrush_slug' => 'saswave/semrush-agencies-partner-scraper',
            'semrush_keywords_slug' => 'marceli/semrush-keyworlds-scraper',
            'semrush_keyword_magic_slug' => 'burbn/semrush-keyword-magic-tool',
            'semrush_domain_slug' => 'radeance/semrush-scraper',
            'moz_slug' => 'radeance/moz-scraper',
            'ahrefs_slug' => 'pro100chok/ahrefs-seo-tools',
            'google_trends_slug' => 'apify/google-trends-scraper',
            'youtube_trending_slug' => 'eunit/youtube-trending-videos-by-categories',
            'tiktok_trends_slug' => 'clockworks/tiktok-trends-scraper',
            'twitter_trends_slug' => 'karamelo/twitter-trends-scraper',
            'google_trends_interest_slug' => 'data_xplorer/google-trends-fast-scraper',
            'google_trends_interest_enabled' => 1,
            'google_trends_interest_cost_level' => 'low',
            'google_trends_interest_reliability_note' => '',
            'google_trends_interest_last_status' => 'not_run',
            'google_trends_trending_now_slug' => 'data_xplorer/google-trends-trending-now',
            'google_trends_trending_now_enabled' => 0,
            'google_trends_trending_now_cost_level' => 'low',
            'google_trends_trending_now_reliability_note' => '',
            'google_trends_trending_now_last_status' => 'not_run',
            'google_search_freshness_slug' => 'apify/google-search-scraper',
            'google_search_freshness_enabled' => 1,
            'google_search_freshness_cost_level' => 'low',
            'google_search_freshness_reliability_note' => '',
            'google_search_freshness_last_status' => 'not_run',
            'google_news_freshness_slug' => 'apify/google-search-scraper',
            'google_news_freshness_enabled' => 1,
            'google_news_freshness_cost_level' => 'low',
            'google_news_freshness_reliability_note' => '',
            'google_news_freshness_last_status' => 'not_run',
            'semrush_keyword_context_slug' => 'burbn/semrush-keyword-magic-tool',
            'semrush_keyword_context_enabled' => 0,
            'semrush_keyword_context_cost_level' => 'medium',
            'semrush_keyword_context_reliability_note' => '',
            'semrush_keyword_context_last_status' => 'not_run',
            'x_topic_posts_slug' => 'apidojo/tweet-scraper',
            'x_topic_posts_enabled' => 1,
            'x_topic_posts_cost_level' => 'medium',
            'x_topic_posts_reliability_note' => '',
            'x_topic_posts_last_status' => 'not_run',
            'x_trend_list_slug' => 'karamelo/twitter-trends-scraper',
            'x_trend_list_enabled' => 0,
            'x_trend_list_cost_level' => 'medium',
            'x_trend_list_reliability_note' => '',
            'x_trend_list_last_status' => 'not_run',
            'tiktok_topic_videos_slug' => 'clockworks/tiktok-scraper',
            'tiktok_topic_videos_enabled' => 1,
            'tiktok_topic_videos_cost_level' => 'high',
            'tiktok_topic_videos_reliability_note' => '',
            'tiktok_topic_videos_last_status' => 'not_run',
            'tiktok_hashtag_trends_slug' => 'lexis-solutions/tiktok-trending-hashtags',
            'tiktok_hashtag_trends_enabled' => 0,
            'tiktok_hashtag_trends_cost_level' => 'medium',
            'tiktok_hashtag_trends_reliability_note' => '',
            'tiktok_hashtag_trends_last_status' => 'not_run',
            'tiktok_trending_videos_slug' => 'data_xplorer/tiktok-trends',
            'tiktok_trending_videos_enabled' => 0,
            'tiktok_trending_videos_cost_level' => 'high',
            'tiktok_trending_videos_reliability_note' => '',
            'tiktok_trending_videos_last_status' => 'not_run',
            'tiktok_trending_creators_slug' => 'lexis-solutions/tiktok-trending-creators',
            'tiktok_trending_creators_enabled' => 0,
            'tiktok_trending_creators_cost_level' => 'high',
            'tiktok_trending_creators_reliability_note' => '',
            'tiktok_trending_creators_last_status' => 'not_run',
            'tiktok_trending_sounds_slug' => 'alien_force/tiktok-trending-sounds',
            'tiktok_trending_sounds_enabled' => 0,
            'tiktok_trending_sounds_cost_level' => 'medium',
            'tiktok_trending_sounds_reliability_note' => '',
            'tiktok_trending_sounds_last_status' => 'not_run',
            'youtube_topic_videos_slug' => 'streamers/youtube-scraper',
            'youtube_topic_videos_enabled' => 1,
            'youtube_topic_videos_cost_level' => 'medium',
            'youtube_topic_videos_reliability_note' => '',
            'youtube_topic_videos_last_status' => 'not_run',
            'youtube_trending_categories_slug' => 'eunit/youtube-trending-videos-by-categories',
            'youtube_trending_categories_enabled' => 0,
            'youtube_trending_categories_cost_level' => 'medium',
            'youtube_trending_categories_reliability_note' => '',
            'youtube_trending_categories_last_status' => 'not_run',
            'reddit_topic_posts_slug' => 'trudax/reddit-scraper-lite',
            'reddit_topic_posts_enabled' => 1,
            'reddit_topic_posts_cost_level' => 'low',
            'reddit_topic_posts_reliability_note' => '',
            'reddit_topic_posts_last_status' => 'not_run',
            'reddit_trends_slug' => 'easyapi/reddit-trends-scraper',
            'reddit_trends_enabled' => 0,
            'reddit_trends_cost_level' => 'medium',
            'reddit_trends_reliability_note' => '',
            'reddit_trends_last_status' => 'not_run',
            'ad_creative_scraper_slug' => 'curious_coder/facebook-ads-library-scraper',
            'ad_creative_scraper_enabled' => 0,
            'ad_creative_scraper_cost_level' => 'medium',
            'ad_creative_scraper_reliability_note' => '',
            'ad_creative_scraper_last_status' => 'not_run',
            'competitor_creative_scraper_slug' => 'dz_omar/google-ads-scraper',
            'competitor_creative_scraper_enabled' => 0,
            'competitor_creative_scraper_cost_level' => 'medium',
            'competitor_creative_scraper_reliability_note' => '',
            'competitor_creative_scraper_last_status' => 'not_run',
        ];
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return wp_parse_args($saved, self::defaults());
    }

    public static function get($key, $default = null) {
        $settings = self::get_settings();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $output = [];

        $existing = self::get_settings();
        $incoming_backend = sanitize_text_field((string) ($input['backend_token'] ?? ''));
        $incoming_openai = sanitize_text_field((string) ($input['openai_api_key'] ?? ''));
        $output['backend_token'] = $incoming_backend !== '' ? $incoming_backend : sanitize_text_field((string) ($existing['backend_token'] ?? ''));
        $output['openai_api_key'] = $incoming_openai !== '' ? $incoming_openai : sanitize_text_field((string) ($existing['openai_api_key'] ?? ''));
        $output['openai_model'] = sanitize_text_field((string) ($input['openai_model'] ?? $defaults['openai_model']));
        if ($output['openai_model'] === '') {
            $output['openai_model'] = $defaults['openai_model'];
        }
        foreach (['fast_analysis_model','deep_analysis_model','brief_generation_model','assistant_model'] as $model_key) {
            $output[$model_key] = sanitize_text_field((string) ($input[$model_key] ?? $defaults[$model_key]));
            if ($output[$model_key] === '') {
                $output[$model_key] = $defaults[$model_key];
            }
        }
        $allowed_reasoning = ['auto', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'];
        $output['openai_reasoning_effort'] = sanitize_key((string) ($input['openai_reasoning_effort'] ?? $defaults['openai_reasoning_effort']));
        if (!in_array($output['openai_reasoning_effort'], $allowed_reasoning, true)) {
            $output['openai_reasoning_effort'] = $defaults['openai_reasoning_effort'];
        }

        $output['require_login'] = !empty($input['require_login']) ? 1 : 0;
        $output['admin_error_details'] = !empty($input['admin_error_details']) ? 1 : 0;
        $output['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']) ? 1 : 0;
        $output['default_signup_credits'] = max(0, min(100000, (float) ($input['default_signup_credits'] ?? $defaults['default_signup_credits'])));
        $output['registration_role'] = sanitize_key((string) ($input['registration_role'] ?? $defaults['registration_role']));
        $available_plans = class_exists('VES_Usage_Billing') ? array_keys(VES_Usage_Billing::get_available_plans()) : ['free','starter','pro','scale'];
        $output['default_plan_slug'] = sanitize_key((string) ($input['default_plan_slug'] ?? $defaults['default_plan_slug']));
        if (!in_array($output['default_plan_slug'], $available_plans, true)) {
            $output['default_plan_slug'] = $defaults['default_plan_slug'];
        }
        if (!in_array($output['registration_role'], ['ves_customer', 'subscriber'], true)) {
            $output['registration_role'] = $defaults['registration_role'];
        }
        $allowed_roles = (array) ($input['panel_allowed_roles'] ?? $defaults['panel_allowed_roles']);
        $allowed_roles = array_values(array_intersect(array_map('sanitize_key', $allowed_roles), ['administrator', 'editor', 'author', 'subscriber', 'ves_customer']));
        if (empty($allowed_roles)) {
            $allowed_roles = $defaults['panel_allowed_roles'];
        }
        $output['panel_allowed_roles'] = $allowed_roles;
        foreach (['cost_manual_run','cost_ai_analysis','cost_trend_run','cost_trend_brief','cost_background_monitor','cost_brand_audit_light','cost_brand_audit_standard','cost_brand_audit_deep','cost_creative_asset_analysis'] as $billing_key) {
            $output[$billing_key] = max(0, min(1000, (float) ($input[$billing_key] ?? $defaults[$billing_key])));
        }
        $output['rate_limit_seconds'] = max(5, min(300, (int) ($input['rate_limit_seconds'] ?? $defaults['rate_limit_seconds'])));
        $output['max_items'] = max(1, min(5000, (int) ($input['max_items'] ?? $defaults['max_items'])));
        $output['max_charge_usd'] = max(0.1, min(100, (float) ($input['max_charge_usd'] ?? $defaults['max_charge_usd'])));
        $output['openai_fast_timeout_seconds'] = max(10, min(90, (int) ($input['openai_fast_timeout_seconds'] ?? $defaults['openai_fast_timeout_seconds'])));
        $output['openai_deep_timeout_seconds'] = max(10, min(90, (int) ($input['openai_deep_timeout_seconds'] ?? $defaults['openai_deep_timeout_seconds'])));
        $output['openai_max_evidence_items'] = max(4, min(80, (int) ($input['openai_max_evidence_items'] ?? $defaults['openai_max_evidence_items'])));
        $output['openai_max_evidence_refs'] = max(4, min(80, (int) ($input['openai_max_evidence_refs'] ?? $defaults['openai_max_evidence_refs'])));
        $output['memory_retention_days'] = max(1, min(90, (int) ($input['memory_retention_days'] ?? $defaults['memory_retention_days'])));
        $output['ai_context_max_memory_records'] = max(0, min(20, (int) ($input['ai_context_max_memory_records'] ?? $defaults['ai_context_max_memory_records'])));
        $output['ai_context_max_approved_insights'] = max(0, min(20, (int) ($input['ai_context_max_approved_insights'] ?? $defaults['ai_context_max_approved_insights'])));
        $output['ai_context_max_chars'] = max(2000, min(40000, (int) ($input['ai_context_max_chars'] ?? $defaults['ai_context_max_chars'])));
        $output['semantic_retrieval_enabled'] = !empty($input['semantic_retrieval_enabled']);
        $output['ai_context_max_semantic_matches'] = max(0, min(20, (int) ($input['ai_context_max_semantic_matches'] ?? $defaults['ai_context_max_semantic_matches'])));
        $output['semantic_min_score'] = max(0, min(200, (int) ($input['semantic_min_score'] ?? $defaults['semantic_min_score'])));
        $output['knowledge_graph_enabled'] = !empty($input['knowledge_graph_enabled']);
        $output['knowledge_consolidation_enabled'] = !empty($input['knowledge_consolidation_enabled']);
        $output['ai_context_max_knowledge_graph_items'] = max(0, min(30, (int) ($input['ai_context_max_knowledge_graph_items'] ?? $defaults['ai_context_max_knowledge_graph_items'])));
        $output['apify_max_active_runs'] = max(1, min(50, (int) ($input['apify_max_active_runs'] ?? $defaults['apify_max_active_runs'])));
        $output['apify_max_active_runs_per_user'] = max(1, min(20, (int) ($input['apify_max_active_runs_per_user'] ?? $defaults['apify_max_active_runs_per_user'])));
        $output['apify_max_heavy_runs'] = max(1, min(20, (int) ($input['apify_max_heavy_runs'] ?? $defaults['apify_max_heavy_runs'])));
        $output['apify_max_sources_per_run'] = max(1, min(100, (int) ($input['apify_max_sources_per_run'] ?? $defaults['apify_max_sources_per_run'])));
        $output['apify_max_estimated_cost_per_run'] = max(0, min(1000, (float) ($input['apify_max_estimated_cost_per_run'] ?? $defaults['apify_max_estimated_cost_per_run'])));
        $output['apify_cost_warning_threshold_usd'] = max(0, min(1000, (float) ($input['apify_cost_warning_threshold_usd'] ?? $defaults['apify_cost_warning_threshold_usd'])));
        $output['max_external_provider_cost_per_run'] = max(0, min(1000, (float) ($input['max_external_provider_cost_per_run'] ?? $defaults['max_external_provider_cost_per_run'])));
        $output['enable_youtube_trending_in_trend_finder'] = !empty($input['enable_youtube_trending_in_trend_finder']) ? 1 : 0;
        $output['enable_twitter_trends_in_trend_finder'] = !empty($input['enable_twitter_trends_in_trend_finder']) ? 1 : 0;
        $output['max_twitter_trend_items'] = max(5, min(200, (int) ($input['max_twitter_trend_items'] ?? $defaults['max_twitter_trend_items'])));
        $output['max_youtube_trending_cost'] = max(0, min(100, (float) ($input['max_youtube_trending_cost'] ?? $defaults['max_youtube_trending_cost'])));
        $output['brand_audit_batch_size'] = max(1, min(20, (int) ($input['brand_audit_batch_size'] ?? $defaults['brand_audit_batch_size'])));
        $output['brand_audit_max_competitors_standard'] = max(0, min(20, (int) ($input['brand_audit_max_competitors_standard'] ?? $defaults['brand_audit_max_competitors_standard'])));
        $output['brand_audit_max_competitors_deep'] = max(0, min(30, (int) ($input['brand_audit_max_competitors_deep'] ?? $defaults['brand_audit_max_competitors_deep'])));
        $output['brand_audit_max_heavy_sources_per_phase'] = max(1, min(10, (int) ($input['brand_audit_max_heavy_sources_per_phase'] ?? $defaults['brand_audit_max_heavy_sources_per_phase'])));
        $output['brand_audit_enable_competitor_social_expansion'] = !empty($input['brand_audit_enable_competitor_social_expansion']) ? 1 : 0;
        $output['brand_audit_auto_expand_after_phase_1'] = !empty($input['brand_audit_auto_expand_after_phase_1']) ? 1 : 0;

        foreach (['tiktok_slug','tiktok_enrichment_slug','tiktok_comments_slug','tiktok_fallback_slug','tiktok_backup_slug','youtube_slug','facebook_slug','instagram_slug','facebook_ads_slug','google_ads_slug','twitter_slug','linkedin_slug','reddit_slug','pinterest_slug','semrush_slug','semrush_keywords_slug','semrush_keyword_magic_slug','semrush_domain_slug','moz_slug','ahrefs_slug','google_trends_slug','youtube_trending_slug','tiktok_trends_slug','twitter_trends_slug'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            $value = preg_replace('/[^A-Za-z0-9_\-\/\.~]/', '', $value);
            $output[$key] = $value !== '' ? $value : $defaults[$key];
        }

        foreach (self::trend_source_slots() as $slot_key => $slot) {
            $slug_key = $slot_key . '_slug';
            $value = trim((string) ($input[$slug_key] ?? ''));
            $value = preg_replace('/[^A-Za-z0-9_\-\/\.~]/', '', $value);
            $output[$slug_key] = $value !== '' ? $value : (string) ($defaults[$slug_key] ?? ($slot['default_slug'] ?? ''));
            $output[$slot_key . '_enabled'] = !empty($input[$slot_key . '_enabled']) ? 1 : 0;
            $cost = sanitize_key((string) ($input[$slot_key . '_cost_level'] ?? ($defaults[$slot_key . '_cost_level'] ?? ($slot['cost'] ?? 'medium'))));
            if (!in_array($cost, ['low', 'medium', 'high'], true)) {
                $cost = (string) ($defaults[$slot_key . '_cost_level'] ?? 'medium');
            }
            $output[$slot_key . '_cost_level'] = $cost;
            $output[$slot_key . '_reliability_note'] = sanitize_text_field((string) ($input[$slot_key . '_reliability_note'] ?? ($existing[$slot_key . '_reliability_note'] ?? '')));
            $output[$slot_key . '_last_status'] = sanitize_text_field((string) ($existing[$slot_key . '_last_status'] ?? ($defaults[$slot_key . '_last_status'] ?? 'not_run')));
        }

        add_settings_error(self::OPTION_KEY, 'settings_updated', 'Configuración guardada.', 'updated');
        return $output;
    }


    public static function handle_clear_runtime_locks() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'ves'));
        }
        check_admin_referer('ves_clear_runtime_locks');
        $result = self::clear_runtime_locks();
        self::record_diagnostic('runtime_locks_reset', 'Admin cleared runtime dispatch locks and duplicate-run guards.', $result);
        wp_safe_redirect(add_query_arg(['page' => 'ves-social-scraper', 'ves_runtime_locks_reset' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function handle_test_apify_connection() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'ves'));
        }
        check_admin_referer('ves_test_apify_connection');
        $token = class_exists('VES_Config') ? VES_Config::get_token() : '';
        $context = ['token_configured' => $token !== '', 'request_attempted' => false];
        if ($token === '') {
            self::record_diagnostic('apify_connection_test', 'Apify token is not configured. Save the Backend / Apify token first.', $context);
            wp_safe_redirect(add_query_arg(['page' => 'ves-social-scraper', 'ves_apify_test' => 'missing_token'], admin_url('options-general.php')));
            exit;
        }
        $response = wp_remote_get('https://api.apify.com/v2/users/me', [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'VietnamEstudio-Scraper-AdminTest/' . (defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : 'dev'),
            ],
        ]);
        $context['request_attempted'] = true;
        if (is_wp_error($response)) {
            $context['wp_error'] = $response->get_error_message();
            self::record_diagnostic('apify_connection_test', 'Apify connection test failed before HTTP response.', $context);
            wp_safe_redirect(add_query_arg(['page' => 'ves-social-scraper', 'ves_apify_test' => 'transport_failed'], admin_url('options-general.php')));
            exit;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $context['http_status'] = $code;
        $context['user_id_seen'] = is_array($body) ? sanitize_text_field((string) ($body['data']['id'] ?? '')) : '';
        $context['username_seen'] = is_array($body) ? sanitize_text_field((string) ($body['data']['username'] ?? '')) : '';
        self::record_diagnostic('apify_connection_test', $code >= 200 && $code < 300 ? 'Apify token connection test passed.' : 'Apify token connection test returned non-2xx status.', $context);
        wp_safe_redirect(add_query_arg(['page' => 'ves-social-scraper', 'ves_apify_test' => ($code >= 200 && $code < 300 ? 'ok' : 'http_' . $code)], admin_url('options-general.php')));
        exit;
    }

    public static function clear_runtime_locks() {
        global $wpdb;
        $deleted_transients = 0;
        if ($wpdb instanceof wpdb) {
            $like = $wpdb->esc_like('_transient_ves_start_gate_') . '%';
            $timeout_like = $wpdb->esc_like('_transient_timeout_ves_start_gate_') . '%';
            $deleted_transients += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like));
        }
        $slots_before = get_option('ves_apify_active_slots', []);
        $slots_before_count = is_array($slots_before) ? count($slots_before) : 0;
        update_option('ves_apify_active_slots', [], false);
        delete_transient('ves_apify_active_slots_lock');
        $source_index_before = get_option('ves_source_execution_index_v2', []);
        $source_index_before_count = is_array($source_index_before) ? count($source_index_before) : 0;
        update_option('ves_source_execution_index_v2', [], false);
        return [
            'start_gate_transients_deleted' => $deleted_transients,
            'dispatch_slots_cleared' => $slots_before_count,
            'source_execution_guards_cleared' => $source_index_before_count,
            'cleared_at' => gmdate('c'),
        ];
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $diag = get_option(self::DIAG_KEY, []);
        $diag_log = self::get_diagnostic_log();
        $settings = self::get_settings();
        settings_errors(self::OPTION_KEY);
        if (!empty($_GET['ves_runtime_locks_reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Runtime locks cleared. Try the scraper again; if it still fails, use the FI diagnostic code in Diagnostics.</p></div>';
        }
        if (!empty($_GET['ves_apify_test'])) {
            $test = sanitize_text_field((string) $_GET['ves_apify_test']);
            $class = $test === 'ok' ? 'notice-success' : 'notice-error';
            $msg = $test === 'ok' ? 'Apify connection test passed. The token can reach Apify.' : 'Apify connection test failed or returned a non-2xx status. Review the latest Diagnostics entry.';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>VE Social Scraper</h1>
            <p>Panel de configuración del plugin. Las credenciales ya no están hardcodeadas en el código.</p>
            <style>
                .ves-settings-actionbar{position:sticky;top:32px;z-index:50;display:flex;align-items:center;gap:10px;flex-wrap:wrap;max-width:1180px;margin:12px 0 18px;padding:12px 14px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;box-shadow:0 2px 8px rgba(0,0,0,.06)}
                .ves-settings-actionbar p.submit{margin:0;padding:0}.ves-settings-actionbar form{margin:0}.ves-settings-note{color:#646970;margin-left:auto;max-width:460px}.ves-runtime-health{max-width:1180px;margin:10px 0 18px}.ves-runtime-health code{font-size:12px}
            </style>
            <form id="ves-main-settings-form" method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <div class="ves-settings-actionbar">
                    <?php submit_button('Guardar cambios', 'primary', 'submit', false); ?>
                    <span class="ves-settings-note">Si cambias el token de Apify/OpenAI, pulsa aquí. Este botón guarda todos los campos aunque estén más abajo en la página.</span>
                </div>
                <?php
                do_settings_sections(self::OPTION_KEY);
                submit_button('Guardar cambios');
                ?>
            </form>
            <div class="ves-settings-actionbar" style="position:relative;top:auto;border-left-color:#d63638">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ves_clear_runtime_locks">
                    <?php wp_nonce_field('ves_clear_runtime_locks'); ?>
                    <?php submit_button('Reset runtime locks', 'secondary', 'submit', false, ['onclick' => "return confirm('Reset stuck dispatch locks and duplicate-run guards? Use this only when runs are stuck before reaching Apify.');"]); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ves_test_apify_connection">
                    <?php wp_nonce_field('ves_test_apify_connection'); ?>
                    <?php submit_button('Test Apify connection', 'secondary', 'submit', false); ?>
                </form>
                <span class="ves-settings-note">این دکمه‌ها فقط برای دیباگ هستند: اگر درخواست به Apify نمی‌رسد، اول تنظیمات را ذخیره کن، بعد connection test و در صورت گیر کردن runها reset locks را بزن.</span>
            </div>

            <hr>
            <h2>Diagnóstico rápido</h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><td style="width:220px"><strong>Token backend configurado</strong></td><td><?php echo !empty($settings['backend_token']) || (defined('VE_SOCIAL_BACKEND_TOKEN') && VE_SOCIAL_BACKEND_TOKEN) ? 'Sí' : 'No'; ?></td></tr>
                    <tr><td><strong>OpenAI key configurada</strong></td><td><?php echo !empty($settings['openai_api_key']) || (defined('VE_SOCIAL_OPENAI_API_KEY') && VE_SOCIAL_OPENAI_API_KEY) ? 'Sí' : 'No'; ?></td></tr>
                    <tr><td><strong>Modelo activo</strong></td><td><code><?php echo esc_html(VES_Config::get_openai_model()); ?></code></td></tr>
                    <tr><td><strong>Reasoning activo</strong></td><td><code><?php echo esc_html(VES_Config::get_openai_reasoning_effort()); ?></code></td></tr>
                    <tr><td><strong>TikTok Discovery actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('tiktok')); ?></code></td></tr>
                    <tr><td><strong>TikTok Enrichment actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('tiktok_enrichment')); ?></code></td></tr>
                    <tr><td><strong>TikTok fallback actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('tiktok_fallback')); ?></code></td></tr>
                    <tr><td><strong>Instagram actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('instagram')); ?></code></td></tr>
                    
                    <tr><td><strong>Google Ads actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('google_ads')); ?></code></td></tr>
                    <tr><td><strong>Twitter actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('twitter')); ?></code></td></tr>
                    <tr><td><strong>LinkedIn actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('linkedin')); ?></code></td></tr>
                    <tr><td><strong>Reddit actor activo</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('reddit')); ?></code></td></tr>
                    <tr><td><strong>Trend Finder · Google Trends</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('google_trends')); ?></code></td></tr>
                    <tr><td><strong>Trend Finder · YouTube Trending</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('youtube_trending')); ?></code></td></tr>
                    <tr><td><strong>Trend Finder · TikTok Trends</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('tiktok_trends')); ?></code></td></tr>
                    <tr><td><strong>Trend Finder · Twitter Trends</strong></td><td><code><?php echo esc_html(VES_Config::get_actor_slug('twitter_trends')); ?></code></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:24px">Actor routing matrix</h2>
            <p style="max-width:900px">Resumen operativo de qué actor usa cada módulo. Úsalo para detectar overrides antiguos, actores de rental pagado y errores de routing entre Semrush/Ahrefs/Pinterest.</p>
            <?php
            $actor_rows = [
                ['Social', 'TikTok', 'Discovery/Search', VES_Config::get_actor_slug('tiktok'), 'Primary discovery adapter: keyword/hashtag/topic candidate collection.'],
                ['Social', 'TikTok', 'Analyze selected video', VES_Config::get_actor_slug('tiktok_enrichment'), 'Deep enrichment adapter for selected post/profile/comments before AI analysis.'],
                ['Social', 'TikTok', 'Discovery fallback', VES_Config::get_actor_slug('tiktok_fallback'), 'Fallback only; not the primary discovery path.'],
                ['Social', 'TikTok', 'Backup', VES_Config::get_actor_slug('tiktok_backup'), 'Broad fallback/backup when primary and fallback actors fail or are unavailable.'],
                ['Social', 'YouTube', 'default', VES_Config::get_actor_slug('youtube'), 'OK: live-tested with small search.'],
                ['Social', 'Facebook', 'default', VES_Config::get_actor_slug('facebook'), 'OK: public pages only.'],
                ['Social', 'Instagram', 'default', VES_Config::get_actor_slug('instagram'), 'Profile/post routing depends on the selected actor schema.'],
                ['Social', 'X / Twitter', 'default', VES_Config::get_actor_slug('twitter'), 'OK: live-tested with small search.'],
                ['Social', 'Reddit', 'default', VES_Config::get_actor_slug('reddit'), 'Requires proxy object; URL mode defaults to posts.'],
                ['Social', 'Pinterest', 'default', VES_Config::get_actor_slug('pinterest'), 'May require active paid actor rental in Apify.'],
                ['LinkedIn', 'Profiles', 'default', VES_Config::get_actor_slug('linkedin'), 'Dedicated LinkedIn profile search flow.'],
                ['SEO/SEM/AEO', 'Semrush', 'Keywords Scraper', VES_Config::get_actor_slug('semrush', ['mode' => 'keyword_simple']), 'Often paid/rental actor.'],
                ['SEO/SEM/AEO', 'Semrush', 'Keyword Magic', VES_Config::get_actor_slug('semrush', ['mode' => 'keyword_magic']), 'Minimum maxResults is 25.'],
                ['SEO/SEM/AEO', 'Semrush', 'Domain / Top Websites', VES_Config::get_actor_slug('semrush', ['mode' => 'domain_seo']), 'Uses domain/SEO schema.'],
                ['SEO/SEM/AEO', 'MOZ', 'default', VES_Config::get_actor_slug('moz'), 'Live-tested with example.com.'],
                ['SEO/SEM/AEO', 'Ahrefs', 'default', VES_Config::get_actor_slug('ahrefs'), 'One searchType per run.'],
            ];
            ?>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>Family</th><th>Module</th><th>Mode</th><th>Actor slug</th><th>Operational note</th></tr></thead>
                <tbody>
                    <?php foreach ($actor_rows as $actor_row) : ?>
                        <tr>
                            <td><?php echo esc_html($actor_row[0]); ?></td>
                            <td><?php echo esc_html($actor_row[1]); ?></td>
                            <td><?php echo esc_html($actor_row[2]); ?></td>
                            <td><code><?php echo esc_html($actor_row[3]); ?></code></td>
                            <td><?php echo esc_html($actor_row[4]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:24px;max-width:1100px">
                <h2 style="margin:0">Último diagnóstico guardado</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0">
                    <input type="hidden" name="action" value="ves_clear_diagnostics">
                    <?php wp_nonce_field('ves_clear_diagnostics'); ?>
                    <?php submit_button('Vaciar diagnósticos', 'delete', 'submit', false, ['onclick' => "return confirm('¿Vaciar el histórico de diagnósticos?');"]); ?>
                </form>
            </div>

            <?php if (!empty($diag) && is_array($diag)) : ?>
                <table class="widefat striped" style="max-width:1100px">
                    <tbody>
                        <tr><td style="width:220px"><strong>Fecha</strong></td><td><?php echo esc_html($diag['timestamp'] ?? ''); ?></td></tr>
                        <tr><td><strong>Origen</strong></td><td><?php echo esc_html($diag['source'] ?? ''); ?></td></tr>
                        <tr><td><strong>Mensaje</strong></td><td><pre style="white-space:pre-wrap;margin:0"><?php echo esc_html($diag['message'] ?? ''); ?></pre></td></tr>
                        <?php if (!empty($diag['context'])) : ?>
                            <tr><td><strong>Contexto</strong></td><td><pre style="white-space:pre-wrap;margin:0"><?php echo esc_html(wp_json_encode($diag['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>No hay diagnósticos todavía.</p>
            <?php endif; ?>

            <h2 style="margin-top:24px">Histórico reciente (<?php echo (int) count($diag_log); ?>)</h2>
            <?php if (!empty($diag_log)) : ?>
                <table class="widefat striped" style="max-width:1100px">
                    <thead>
                        <tr>
                            <th style="width:160px">Fecha</th>
                            <th style="width:150px">Origen</th>
                            <th style="width:110px">Request ID</th>
                            <th>Mensaje</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($diag_log as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['timestamp'] ?? ''); ?></td>
                            <td><code><?php echo esc_html($row['source'] ?? ''); ?></code></td>
                            <td><code><?php echo esc_html($row['context']['request_id'] ?? ''); ?></code></td>
                            <td>
                                <div><?php echo esc_html($row['message'] ?? ''); ?></div>
                                <?php if (!empty($row['context'])) : ?>
                                    <details style="margin-top:6px">
                                        <summary>Ver contexto</summary>
                                        <pre style="white-space:pre-wrap;margin:8px 0 0"><?php echo esc_html(wp_json_encode($row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>No hay histórico todavía.</p>
            <?php endif; ?>
        </div>
        <?php
    }


    public static function render_actor_registry_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        $actors = class_exists('VES_Apify_Actor_Registry') ? VES_Apify_Actor_Registry::all_for_admin() : [];
        $schemas = class_exists('VES_Module_Field_Schemas') ? VES_Module_Field_Schemas::all() : [];
        $grouped = [];
        foreach ($actors as $actor) {
            $module = (string) ($actor['module'] ?? 'unassigned');
            $grouped[$module][] = $actor;
        }
        ?>
        <div class="wrap">
            <h1>VE Actor Registry</h1>
            <p>Code-backed Apify source registry used for routing, cost preflight and admin diagnostics. This pass intentionally keeps the registry read-only to avoid unsafe runtime edits; actor overrides can still be introduced through the existing registry override option/filter path.</p>
            <p><strong>Total actors:</strong> <?php echo (int) count($actors); ?> · <strong>Modules:</strong> <?php echo (int) count($grouped); ?></p>

            <h2>Module field schemas</h2>
            <table class="widefat striped" style="max-width:1200px">
                <thead><tr><th>Module</th><th>Modes</th><th>Required fields</th><th>Renderer</th><th>AI sections</th></tr></thead>
                <tbody>
                <?php foreach ($schemas as $key => $schema) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($schema['label'] ?? $key); ?></strong><br><code><?php echo esc_html($key); ?></code></td>
                        <td><?php echo esc_html(implode(', ', (array) ($schema['modes'] ?? []))); ?></td>
                        <td><?php echo esc_html(implode(', ', (array) ($schema['fields'] ?? []))); ?></td>
                        <td><code><?php echo esc_html($schema['renderer'] ?? ''); ?></code></td>
                        <td><?php echo esc_html(implode(', ', (array) (is_array($schema['ai_schema'] ?? null) ? $schema['ai_schema'] : [$schema['ai_schema'] ?? '']))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px">Apify actors</h2>
            <table class="widefat striped" style="max-width:1400px">
                <thead>
                    <tr>
                        <th>Key</th><th>Module</th><th>Actor</th><th>Source / Modes</th><th>Enabled</th><th>Default / Max</th><th>Candidate pool</th><th>Est. cost</th><th>Fallbacks</th><th>Health / notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($actors as $actor) : ?>
                    <tr>
                        <td><code><?php echo esc_html($actor['key'] ?? ''); ?></code></td>
                        <td><?php echo esc_html($actor['module'] ?? ''); ?></td>
                        <td><strong><?php echo esc_html($actor['display_name'] ?? ''); ?></strong><br><code><?php echo esc_html($actor['actor_id'] ?? ''); ?></code></td>
                        <td><?php echo esc_html($actor['source_type'] ?? ''); ?><br><small><?php echo esc_html(implode(', ', (array) ($actor['supported_modes'] ?? []))); ?></small></td>
                        <td><?php echo !empty($actor['enabled']) ? '<span style="color:#0a7f40;font-weight:700">Enabled</span>' : '<span style="color:#9a3412;font-weight:700">Disabled</span>'; ?></td>
                        <td><?php echo (int) ($actor['default_result_limit'] ?? 0); ?> / <?php echo (int) ($actor['max_result_limit'] ?? 0); ?></td>
                        <td><?php echo esc_html((string) ($actor['candidate_pool_multiplier'] ?? '')); ?>x</td>
                        <td>$<?php echo esc_html(number_format((float) ($actor['estimated_provider_cost'] ?? 0), 6)); ?><br><small><?php echo esc_html($actor['pricing_model'] ?? ''); ?></small></td>
                        <td><?php echo esc_html(implode(', ', (array) ($actor['fallback_actors'] ?? []))); ?></td>
                        <td><code><?php echo esc_html($actor['health_status'] ?? 'unknown'); ?></code> / <code><?php echo esc_html($actor['permission_status'] ?? 'not_checked'); ?></code><br><small><?php echo esc_html($actor['admin_notes'] ?? ''); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_projects_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        $health = class_exists('VES_Projects') ? VES_Projects::schema_health_check() : [];
        $projects = class_exists('VES_Projects') ? VES_Projects::list_for_admin(300) : [];
        ?>
        <div class="wrap">
            <h1>VE Projects</h1>
            <p>Minimal project isolation layer. This page is diagnostic/read-only in v0.9.24.77; full create/switch/archive UX remains a next-pass item.</p>
            <h2>Schema health</h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><td><strong>Table</strong></td><td><code><?php echo esc_html($health['table'] ?? ''); ?></code></td></tr>
                    <tr><td><strong>Exists</strong></td><td><?php echo !empty($health['exists']) ? 'yes' : 'no'; ?></td></tr>
                    <tr><td><strong>DB version</strong></td><td><code><?php echo esc_html($health['db_version'] ?? ''); ?></code> / expected <code><?php echo esc_html($health['expected_version'] ?? ''); ?></code></td></tr>
                    <tr><td><strong>Missing columns</strong></td><td><?php echo esc_html(implode(', ', (array) ($health['missing_columns'] ?? []))); ?></td></tr>
                    <tr><td><strong>Missing indexes</strong></td><td><?php echo esc_html(implode(', ', (array) ($health['missing_indexes'] ?? []))); ?></td></tr>
                    <tr><td><strong>Current</strong></td><td><?php echo !empty($health['is_current']) ? '<span style="color:#0a7f40;font-weight:700">yes</span>' : '<span style="color:#9a3412;font-weight:700">needs repair</span>'; ?></td></tr>
                </tbody>
            </table>
            <h2 style="margin-top:24px">Projects</h2>
            <table class="widefat striped" style="max-width:1200px">
                <thead><tr><th>ID</th><th>Workspace</th><th>User</th><th>Name</th><th>Brand</th><th>Market</th><th>Language</th><th>Status</th><th>Updated</th></tr></thead>
                <tbody>
                <?php if (empty($projects)) : ?>
                    <tr><td colspan="9">No projects found yet. The default project is created when authenticated users run or save project-scoped data.</td></tr>
                <?php else : foreach ($projects as $project) : ?>
                    <tr>
                        <td><code><?php echo (int) ($project['id'] ?? 0); ?></code></td>
                        <td><?php echo (int) ($project['workspace_id'] ?? 0); ?></td>
                        <td><?php echo (int) ($project['user_id'] ?? 0); ?></td>
                        <td><?php echo esc_html($project['name'] ?? ''); ?></td>
                        <td><?php echo esc_html($project['brand_name'] ?? ''); ?></td>
                        <td><?php echo esc_html($project['market'] ?? ''); ?></td>
                        <td><?php echo esc_html($project['language'] ?? ''); ?></td>
                        <td><?php echo esc_html($project['status'] ?? ''); ?></td>
                        <td><?php echo esc_html($project['updated_at'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_clear_diagnostics() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('ves_clear_diagnostics');
        delete_option(self::DIAG_KEY);
        delete_option(self::DIAG_LOG_KEY);
        wp_safe_redirect(add_query_arg(['page' => 'ves-social-scraper', 'ves_diag_cleared' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function render_password_field($args) {
        $key = $args['key'];
        $stored_value = (string) self::get($key, '');
        $has_value = $stored_value !== '';
        $placeholder = $args['placeholder'] ?? '';
        echo '<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="" placeholder="' . esc_attr($placeholder) . '">';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
        if ($has_value) {
            echo '<p class="description">Guardado. Déjalo en blanco para conservar el valor actual o escribe uno nuevo para reemplazarlo.</p>';
        }
        self::render_constant_notice($key);
    }

    public static function render_text_field($args) {
        $key = $args['key'];
        $value = (string) self::get($key, '');
        echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($args['placeholder'] ?? '') . '">';
        self::render_constant_notice($key);
    }


    public static function render_select_field($args) {
        $key = $args['key'];
        $value = self::get($key, '');
        $options = is_array($args['options'] ?? null) ? $args['options'] : [];
        echo '<select name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '">';
        foreach ($options as $option_value => $label) {
            echo '<option value="' . esc_attr((string) $option_value) . '" ' . selected((string) $value, (string) $option_value, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select>';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public static function render_multi_checkbox_field($args) {
        $key = $args['key'];
        $current = self::get($key, []);
        $current = is_array($current) ? $current : [];
        $options = is_array($args['options'] ?? null) ? $args['options'] : [];
        foreach ($options as $option_value => $label) {
            $checked = in_array((string) $option_value, array_map('strval', $current), true);
            echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="' . esc_attr(self::OPTION_KEY . '[' . $key . '][]') . '" value="' . esc_attr((string) $option_value) . '" ' . checked($checked, true, false) . '> ' . esc_html((string) $label) . '</label>';
        }
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public static function render_number_field($args) {
        $key = $args['key'];
        $value = self::get($key, '');
        echo '<input type="number" class="small-text" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) ($args['min'] ?? '')) . '" max="' . esc_attr((string) ($args['max'] ?? '')) . '" step="' . esc_attr((string) ($args['step'] ?? '1')) . '">';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public static function render_checkbox_field($args) {
        $key = $args['key'];
        $checked = !empty(self::get($key, 0));
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html($args['label'] ?? '') . '</label>';
        self::render_constant_notice($key);
    }

    public static function render_model_field() {
        $current = (string) self::get('openai_model', self::defaults()['openai_model']);
        $models = [
            'gpt-5.2' => 'gpt-5.2 — más capaz',
            'gpt-5.1' => 'gpt-5.1 — flagship previo',
            'gpt-5' => 'gpt-5 — generación anterior',
            'gpt-5-mini' => 'gpt-5-mini — balance coste/calidad',
            'gpt-5-nano' => 'gpt-5-nano — barato/rápido',
            'gpt-4.1' => 'gpt-4.1',
            'gpt-4.1-mini' => 'gpt-4.1-mini',
            'gpt-4o' => 'gpt-4o',
            'gpt-4o-mini' => 'gpt-4o-mini',
            'o4-mini' => 'o4-mini',
        ];
        echo '<input type="text" class="regular-text" list="ves-openai-models" name="' . esc_attr(self::OPTION_KEY . '[openai_model]') . '" value="' . esc_attr($current) . '" placeholder="gpt-5.2">';
        echo '<datalist id="ves-openai-models">';
        foreach ($models as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</datalist>';
        echo '<p class="description">Elige o escribe manualmente cualquier modelo compatible con la API. Para Trend Finder + history/dashboard, las opciones más sólidas son <code>gpt-5.2</code>, <code>gpt-5.1</code> y <code>gpt-5-mini</code>.</p>';
        self::render_constant_notice('openai_model');
    }

    public static function render_named_model_field($args) {
        $key = sanitize_key((string) ($args['key'] ?? 'fast_analysis_model'));
        $defaults = self::defaults();
        $current = (string) self::get($key, $defaults[$key] ?? 'gpt-4.1-mini');
        echo '<input type="text" class="regular-text" list="ves-openai-models" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr($current) . '" placeholder="gpt-4.1-mini">';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
        self::render_constant_notice($key);
    }

    public static function render_reasoning_field() {
        $current = (string) self::get('openai_reasoning_effort', self::defaults()['openai_reasoning_effort']);
        $options = [
            'auto' => 'auto',
            'none' => 'none',
            'minimal' => 'minimal',
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
        ];
        echo '<select name="' . esc_attr(self::OPTION_KEY . '[openai_reasoning_effort]') . '">';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Solo se aplica a la familia GPT-5. En <code>auto</code> el plugin usa una intensidad segura según el modelo elegido.</p>';
        self::render_constant_notice('openai_reasoning_effort');
    }

    private static function build_actionable_diagnostics() {
        $log = self::get_diagnostic_log();
        $seen = strtolower(wp_json_encode($log));
        $settings = self::get_settings();
        $slot_diag = class_exists('VES_Apify_Client') ? VES_Apify_Client::dispatch_slot_diagnostics(true) : [];
        $slot_count = is_array($slot_diag) ? (int) ($slot_diag['active_slots'] ?? 0) : 0;
        $stale_count = is_array($slot_diag) ? (int) ($slot_diag['stale_removed'] ?? 0) : 0;
        $max_sources = (int) ($settings['apify_max_sources_per_run'] ?? 12);
        $cost_limit = (float) ($settings['apify_max_estimated_cost_per_run'] ?? 2.50);
        $cost_warning = (float) ($settings['apify_cost_warning_threshold_usd'] ?? 1.00);
        return [
            ['title'=>'OpenAI health','status'=>(strpos($seen,'request_timeout')!==false || strpos($seen,'curl error 28')!==false)?'attention':'ok','message'=>(strpos($seen,'request_timeout')!==false || strpos($seen,'curl error 28')!==false)?'OpenAI timeout detected. Recommended: use fast_analysis_model for synchronous Trend Finder, reduce evidence payload and avoid high reasoning by default.':'No recent OpenAI timeout diagnostic.'],
            ['title'=>'Apify health','status'=>(strpos($seen,'actor-memory-limit-exceeded')!==false || strpos($seen,'memory limit')!==false || $stale_count > 0)?'attention':'ok','message'=>'Active dispatch slots: '.$slot_count.'. Stale slots cleaned now: '.$stale_count.'. If provider memory saturation appears, lower concurrency, reduce batch size, or let queued_retry_memory_limit retry.'],
            ['title'=>'Adapter/input health','status'=>(strpos($seen,'field input.keyword is required')!==false || strpos($seen,'skipped_invalid_input')!==false || strpos($seen,'insufficient_output')!==false)?'attention':'ok','message'=>'Adapter registry validates required input and output: Google Trends data_xplorer requires keyword; YouTube Trending is market_context unless direct match; generic TikTok Creative Center URLs are rejected as evidence.'],
            ['title'=>'Fallback/memory governance','status'=>(strpos($seen,'trend_local_fallback')!==false || strpos($seen,'fallback')!==false || strpos($seen,'corrupted_legacy')!==false)?'attention':'ok','message'=>'Fallback diagnostics and corrupted legacy trend memory are excluded from normal related-memory context. Use Repair Trend Memory Labels for old metric-only trend names.'],
            ['title'=>'Schema/frontend health','status'=>(strpos($seen,'metrics.slice')!==false || strpos($seen,'schema_mismatch')!==false)?'attention':'ok','message'=>'Frontend schema hardening uses safeArray/safeObject/safeString plus safeTrendLabel filters for history, comparison, dashboard, and brief candidates.'],
            ['title'=>'External provider cost awareness','status'=>$cost_limit > 0 ? 'ok' : 'attention','message'=>'Apify provider-cost guardrails: max sources/run '.$max_sources.', warning threshold $'.number_format($cost_warning, 2).', estimated cost limit/run $'.number_format($cost_limit, 2).'. These are external provider estimates, separate from internal credits.'],
            ['title'=>'Trend source cost controls','status'=>(empty($settings['enable_youtube_trending_in_trend_finder']) && empty($settings['enable_twitter_trends_in_trend_finder'])) ? 'safe_default' : 'watch_cost','message'=>'YouTube Trending default: '.(!empty($settings['enable_youtube_trending_in_trend_finder']) ? 'enabled as optional market_context' : 'disabled').'. X/Twitter Trends default: '.(!empty($settings['enable_twitter_trends_in_trend_finder']) ? 'enabled with cap '.(int)($settings['max_twitter_trend_items'] ?? 40) : 'disabled').'. External provider max/run $'.number_format((float)($settings['max_external_provider_cost_per_run'] ?? $cost_limit), 2).'.'],
        ];
    }

    public static function render_operations_page() {
        if (!current_user_can('manage_options')) { return; }
        $nonce = wp_create_nonce('ves_nonce');
        ?>
        <div class="wrap ves-admin-ops">
            <h1><?php echo esc_html(class_exists('VES_Branding') ? sprintf('%s Ops', VES_Branding::product_name()) : 'Intelligence Suite Ops'); ?></h1>
            <p>Admin-only operational tools for schema health, evidence repair, historical intelligence backfill, metering estimates, workflow activity, and brief library review.</p>
            <?php $ves_action_cards = self::build_actionable_diagnostics(); ?>
            <style>.ves-admin-ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;max-width:1280px}.ves-admin-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.ves-admin-card h2{margin-top:0}.ves-admin-card label{display:block;margin:10px 0 4px;font-weight:600}.ves-admin-card input,.ves-admin-card select{max-width:100%;width:100%}.ves-admin-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.ves-admin-output{background:#111827;color:#e5e7eb;border-radius:10px;padding:12px;max-height:360px;overflow:auto;white-space:pre-wrap;font-size:12px}</style>
            <div class="ves-admin-ops-grid">
                <section class="ves-admin-card"><h2>Actionable Diagnostics</h2><p>Operator-level recommendations generated from recent diagnostics.</p><?php foreach ($ves_action_cards as $card) : ?><div style="border:1px solid #dcdcde;border-radius:8px;padding:10px;margin:8px 0"><strong><?php echo esc_html($card['title']); ?></strong> <code><?php echo esc_html($card['status']); ?></code><p style="margin:6px 0 0"><?php echo esc_html($card['message']); ?></p></div><?php endforeach; ?></section>
                <section class="ves-admin-card"><h2>Schema Health</h2><p>Checks tables, columns, and critical indexes.</p><div class="ves-admin-actions"><button class="button button-primary" data-ves-admin-action="ves_schema_health_check">Run Schema Health Check</button><button class="button" data-ves-admin-action="ves_schema_repair">Repair Schema</button></div><pre class="ves-admin-output">No schema check yet.</pre></section>
                <section class="ves-admin-card"><h2>Evidence Repair</h2><label>Bundle / run ID optional</label><input type="text" data-ves-field="bundleId"><label>Repair limit</label><input type="number" data-ves-field="limit" value="500" min="1" max="2000"><div class="ves-admin-actions"><button class="button" data-ves-admin-action="ves_evidence_refs_count">Count Missing/Invalid Evidence Refs</button><button class="button button-primary" data-ves-admin-action="ves_evidence_refs_repair">Repair Evidence Refs</button></div><pre class="ves-admin-output">No evidence repair run yet.</pre></section>
                <section class="ves-admin-card"><h2>Repair Trend Memory Labels</h2><p>Detects and repairs legacy trend reports where metrics such as 748K, 177K, 1.1M, 382K or 3.3M were saved as trend names. Dry-run first; write mode marks unrecoverable rows as corrupted_legacy and strips unsafe labels.</p><label>Repair limit</label><input type="number" data-ves-field="limit" value="200" min="1" max="1000"><div class="ves-admin-actions"><button class="button" data-ves-admin-action="ves_trend_memory_repair_count">Count corrupted trend memory</button><button class="button" data-ves-admin-action="ves_trend_memory_repair_dry_run">Dry-run repair</button><button class="button button-primary" data-ves-admin-action="ves_trend_memory_repair_apply">Repair corrupted trend memory</button></div><pre class="ves-admin-output">No trend memory repair run yet.</pre></section>
                <section class="ves-admin-card"><h2>Apify Slot & Cost Diagnostics</h2><p>Shows active local dispatch slots, stale-slot cleanup, queued/retry states, and configured external provider-cost guardrails. This is separate from internal credit usage.</p><div class="ves-admin-actions"><button class="button button-primary" data-ves-admin-action="ves_apify_slot_diagnostics">Load active slot diagnostics</button></div><pre class="ves-admin-output">No Apify slot diagnostics yet.</pre></section>
                <section class="ves-admin-card"><h2>Historical Backfill</h2><label>Bundle / run ID</label><input type="text" data-ves-field="bundleId"><label>Mode</label><select data-ves-field="mode"><option value="single">single</option><option value="recent">recent</option></select><label>Limit</label><input type="number" data-ves-field="limit" value="5" min="1" max="25"><label><input type="checkbox" data-ves-field="dryRun" checked> Dry run</label><label><input type="checkbox" data-ves-field="rebuild"> Rebuild intelligence records</label><label><input type="checkbox" data-ves-field="forceUnlock"> Force unlock stale lock</label><div class="ves-admin-actions"><button class="button button-primary" data-ves-admin-action="ves_brand_audit_backfill_intelligence">Run Backfill</button></div><pre class="ves-admin-output">No backfill run yet.</pre></section>
                <section class="ves-admin-card"><h2>Usage / Metering</h2><label>Action type</label><select data-ves-field="action_type"><option value="brand_audit">brand_audit</option><option value="brief_generation">brief_generation</option><option value="assistant_question">assistant_question</option></select><label>Bundle / run ID optional</label><input type="text" data-ves-field="bundleId"><label>Depth / brief type</label><input type="text" data-ves-field="briefType" value="campaign"><label>Platforms</label><input type="text" data-ves-field="platforms" placeholder="instagram,tiktok,youtube"><label>Competitor count</label><input type="number" data-ves-field="competitorCount" value="0" min="0" max="20"><div class="ves-admin-actions"><button class="button button-primary" data-ves-admin-action="ves_usage_estimate">Estimate Usage</button></div><pre class="ves-admin-output">No estimate yet.</pre></section>
                <section class="ves-admin-card"><h2>Workflow Events</h2><label>Bundle / run ID</label><input type="text" data-ves-field="bundleId"><label>Event type optional</label><input type="text" data-ves-field="eventType"><label>Limit</label><input type="number" data-ves-field="limit" value="30" min="1" max="100"><div class="ves-admin-actions"><button class="button button-primary" data-ves-admin-action="ves_workflow_events_list">Load Activity</button></div><pre class="ves-admin-output">No activity loaded yet.</pre></section>
                <section class="ves-admin-card"><h2>Brief Library</h2><label>Status optional</label><select data-ves-field="status"><option value="">Any</option><option value="draft">draft</option><option value="reviewed">reviewed</option><option value="approved">approved</option><option value="archived">archived</option></select><label>Brief type optional</label><select data-ves-field="briefType"><option value="">Any</option><option value="campaign">campaign</option><option value="content">content</option><option value="social">social</option><option value="search">search</option><option value="creative">creative</option></select><div class="ves-admin-actions"><button class="button button-primary" data-ves-admin-action="ves_brief_library_list">Load Brief Library</button></div><pre class="ves-admin-output">No briefs loaded yet.</pre></section>
            </div>
        </div>
        <script>(function(){const nonce=<?php echo wp_json_encode($nonce); ?>;const ajaxurl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;const val=(el)=>el&&el.type==='checkbox'?(el.checked?'1':'0'):(el?el.value:'');const collect=(card)=>{const payload={nonce};card.querySelectorAll('[data-ves-field]').forEach((el)=>{payload[el.dataset.vesField]=val(el);});return payload;};document.querySelector('.ves-admin-ops')?.addEventListener('click',async(e)=>{const btn=e.target.closest('[data-ves-admin-action]');if(!btn)return;e.preventDefault();const card=btn.closest('.ves-admin-card');const out=card.querySelector('.ves-admin-output');const original=btn.textContent;btn.disabled=true;btn.textContent='Running…';if(out)out.textContent='Running '+btn.dataset.vesAdminAction+'…';try{const body=new URLSearchParams({action:btn.dataset.vesAdminAction,...collect(card)});const res=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});const json=await res.json();if(!json||json.success===false)throw new Error(json?.data?.message||'Request failed');if(out)out.textContent=JSON.stringify(json.data,null,2);}catch(err){if(out)out.textContent='ERROR: '+(err.message||err);}finally{btn.disabled=false;btn.textContent=original;}});})();</script>
        <?php
    }

    public static function handle_memory_admin_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'ves'));
        }
        check_admin_referer('ves_memory_admin_action');

        $action_type = sanitize_key((string) ($_POST['memory_action_type'] ?? ''));
        $workspace_id = sanitize_text_field(wp_unslash((string) ($_POST['workspace_id'] ?? '')));
        if ($workspace_id === '' && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::workspace_id_for_user(get_current_user_id());
        }
        $notice = 'memory_updated';

        if ($action_type === 'save_profile' && class_exists('VES_Workspace_Profile')) {
            $profile = [
                'workspace_id' => $workspace_id,
                'user_id' => get_current_user_id(),
                'company_name' => sanitize_text_field(wp_unslash((string) ($_POST['company_name'] ?? ''))),
                'market_country' => sanitize_text_field(wp_unslash((string) ($_POST['market_country'] ?? ''))),
                'industry' => sanitize_text_field(wp_unslash((string) ($_POST['industry'] ?? ''))),
                'brand_positioning' => sanitize_textarea_field(wp_unslash((string) ($_POST['brand_positioning'] ?? ''))),
                'tone_style_guidance' => sanitize_textarea_field(wp_unslash((string) ($_POST['tone_style_guidance'] ?? ''))),
                'products_services' => self::textarea_to_list($_POST['products_services'] ?? ''),
                'competitors' => self::textarea_to_list($_POST['competitors'] ?? ''),
                'audience_segments' => self::textarea_to_list($_POST['audience_segments'] ?? ''),
                'known_strengths' => self::textarea_to_list($_POST['known_strengths'] ?? ''),
                'known_risks' => self::textarea_to_list($_POST['known_risks'] ?? ''),
                'known_opportunities' => self::textarea_to_list($_POST['known_opportunities'] ?? ''),
                'approved_learnings' => self::textarea_to_list($_POST['approved_learnings'] ?? ''),
                'rejected_assumptions' => self::textarea_to_list($_POST['rejected_assumptions'] ?? ''),
                'source_refs' => [],
            ];
            $result = VES_Workspace_Profile::upsert_profile($workspace_id, get_current_user_id(), $profile);
            if (is_wp_error($result)) {
                $notice = 'profile_error';
            }
        } elseif ($action_type === 'mark_assumption_outdated' && class_exists('VES_Workspace_Profile')) {
            $assumption = sanitize_textarea_field(wp_unslash((string) ($_POST['assumption'] ?? '')));
            if ($assumption !== '') {
                VES_Workspace_Profile::add_rejected_assumption($workspace_id, get_current_user_id(), $assumption, 'admin_memory_panel');
            }
        } elseif (in_array($action_type, ['approve_insight','reject_insight','archive_insight','supersede_insight'], true) && class_exists('VES_Insight_Records')) {
            $id = absint($_POST['insight_id'] ?? 0);
            $status_map = [
                'approve_insight' => 'approved',
                'reject_insight' => 'rejected',
                'archive_insight' => 'archived',
                'supersede_insight' => 'superseded',
            ];
            // Legacy evidence gate: a legacy insight may only be manually APPROVED
            // when it carries supporting evidence. reject/archive/supersede are
            // unaffected. This mirrors the canonical evidence-first lifecycle and
            // never auto-approves anything.
            $approval_blocked = false;
            if ($id > 0 && $action_type === 'approve_insight'
                && method_exists('VES_Insight_Records', 'can_approve_legacy_insight')) {
                $gate = VES_Insight_Records::can_approve_legacy_insight($id);
                if (empty($gate['allowed'])) {
                    $approval_blocked = true;
                    $notice = 'Insight cannot be approved because no supporting evidence was found.';
                    if (class_exists('VES_Log') && method_exists('VES_Log', 'warn')) {
                        VES_Log::warn('insight_records', 'Legacy insight approval blocked: no supporting evidence', [
                            'insight_id' => $id,
                            'reason' => (string) ($gate['reason'] ?? ''),
                            'evidence_count' => (int) ($gate['evidence_count'] ?? 0),
                        ]);
                    }
                }
            }
            if ($id > 0 && !$approval_blocked) {
                $updated_record = VES_Insight_Records::update_status_by_id($id, $status_map[$action_type], get_current_user_id());
                if (class_exists('VES_Feedback_Learning')) {
                    $feedback_type_map = [
                        'approve_insight' => 'approved',
                        'reject_insight' => 'rejected',
                        'archive_insight' => 'ignored',
                        'supersede_insight' => 'outdated',
                    ];
                    VES_Feedback_Learning::record_feedback([
                        'workspace_id' => $workspace_id,
                        'user_id' => get_current_user_id(),
                        'object_type' => 'insight',
                        'object_id' => (string) $id,
                        'feedback_type' => $feedback_type_map[$action_type] ?? 'helpful',
                        'context' => ['source' => 'admin_memory_panel', 'status' => $status_map[$action_type]],
                    ]);
                }
                if (class_exists('VES_Semantic_Index') && is_array($updated_record)) {
                    VES_Semantic_Index::upsert_item([
                        'workspace_id' => $workspace_id,
                        'user_id' => get_current_user_id(),
                        'object_type' => 'insight',
                        'object_id' => (string) $id,
                        'status' => $status_map[$action_type],
                        'title' => $updated_record['title'] ?? 'Insight',
                        'summary' => $updated_record['summary'] ?? '',
                        'source_family' => 'insight_ledger',
                        'platform' => $updated_record['platform'] ?? '',
                        'evidence_refs' => $updated_record['evidence_refs'] ?? [],
                        'importance_score' => $status_map[$action_type] === 'approved' ? 90 : 30,
                    ]);
                }
            }
        } elseif ($action_type === 'run_kg_consolidation' && class_exists('VES_Knowledge_Consolidator')) {
            $result = VES_Knowledge_Consolidator::run([
                'workspace_id' => $workspace_id,
                'user_id' => get_current_user_id(),
                'scope' => 'admin_manual',
            ]);
            $notice = !empty($result['status']) && $result['status'] === 'completed' ? 'knowledge_graph_consolidated' : 'knowledge_graph_consolidation_skipped';
        } elseif (in_array($action_type, ['feedback_helpful','feedback_not_helpful','feedback_outdated'], true) && class_exists('VES_Feedback_Learning')) {
            $object_type = sanitize_key((string) ($_POST['object_type'] ?? 'memory'));
            $object_id = sanitize_text_field(wp_unslash((string) ($_POST['object_id'] ?? '')));
            $feedback_map = [
                'feedback_helpful' => 'helpful',
                'feedback_not_helpful' => 'not_helpful',
                'feedback_outdated' => 'outdated',
            ];
            if ($object_id !== '') {
                VES_Feedback_Learning::record_feedback([
                    'workspace_id' => $workspace_id,
                    'user_id' => get_current_user_id(),
                    'object_type' => $object_type,
                    'object_id' => $object_id,
                    'feedback_type' => $feedback_map[$action_type],
                    'comment' => sanitize_textarea_field(wp_unslash((string) ($_POST['feedback_comment'] ?? ''))),
                    'context' => ['source' => 'admin_memory_panel'],
                ]);
            }
        } elseif (in_array($action_type, ['pin_memory','unpin_memory','delete_memory'], true) && class_exists('VES_Memory_Records')) {
            $id = absint($_POST['memory_id'] ?? 0);
            if ($id > 0) {
                if ($action_type === 'delete_memory') {
                    VES_Memory_Records::delete_record($id);
                } else {
                    VES_Memory_Records::set_pinned($id, $action_type === 'pin_memory');
                }
            }
        } elseif ($action_type === 'clear_workspace_memory' && class_exists('VES_Memory_Records')) {
            VES_Memory_Records::clear_workspace($workspace_id);
        } elseif (in_array($action_type, ['brand_ctx_approve','brand_ctx_reject','brand_ctx_archive','brand_ctx_pin','brand_ctx_unpin'], true) && class_exists('VES_Brand_Context_Service')) {
            // Phase 6A — human review of Brand Context memory. Nonce + manage_options
            // already enforced above. Never auto-approves; only operator action here.
            $id = absint($_POST['memory_id'] ?? 0);
            $status_map = [
                'brand_ctx_approve' => VES_Brand_Context_Service::STATUS_ACTIVE,
                'brand_ctx_reject'  => VES_Brand_Context_Service::STATUS_REJECTED,
                'brand_ctx_archive' => VES_Brand_Context_Service::STATUS_ARCHIVED,
                'brand_ctx_pin'     => VES_Brand_Context_Service::STATUS_PINNED,
                'brand_ctx_unpin'   => VES_Brand_Context_Service::STATUS_ACTIVE,
            ];
            if ($id > 0) {
                // Issue 4: scope to the current workspace and only claim success when
                // the update actually applied (brand-context row, right workspace).
                $bc_ok = VES_Brand_Context_Service::set_status($id, $status_map[$action_type], get_current_user_id(), (int) $workspace_id);
                $notice = $bc_ok ? 'brand_context_updated' : 'brand_context_update_failed';
            }
        }

        $url = add_query_arg([
            'page' => 'ves-memory-knowledge',
            'workspace_id' => rawurlencode($workspace_id),
            'ves_notice' => $notice,
        ], admin_url('tools.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function render_memory_knowledge_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $workspace_id = sanitize_text_field(wp_unslash((string) ($_GET['workspace_id'] ?? '')));
        if ($workspace_id === '' && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::workspace_id_for_user(get_current_user_id());
        }
        $profile = class_exists('VES_Workspace_Profile') ? VES_Workspace_Profile::get_profile($workspace_id) : [];
        $insights = class_exists('VES_Insight_Records') ? VES_Insight_Records::list_for_admin(['workspace_id' => $workspace_id, 'limit' => 30]) : [];
        $memories = class_exists('VES_Memory_Records') ? VES_Memory_Records::list_for_admin(['workspace_id' => $workspace_id, 'limit' => 30]) : [];
        $context_pack = class_exists('VES_AI_Context_Builder') ? VES_AI_Context_Builder::build(['workspace_id' => $workspace_id, 'user_id' => get_current_user_id()]) : [];
        $diag = is_array($context_pack['diagnostics'] ?? null) ? $context_pack['diagnostics'] : [];
        $feedbacks = class_exists('VES_Feedback_Learning') ? VES_Feedback_Learning::list_for_admin(['workspace_id' => $workspace_id, 'limit' => 20]) : [];
        $kg_stats = class_exists('VES_Knowledge_Graph') ? VES_Knowledge_Graph::graph_stats((int) $workspace_id) : [];
        $kg_nodes = class_exists('VES_Knowledge_Graph') ? VES_Knowledge_Graph::list_nodes_for_admin(['workspace_id' => $workspace_id, 'limit' => 20]) : [];
        $kg_edges = class_exists('VES_Knowledge_Graph') ? VES_Knowledge_Graph::list_edges_for_admin(['workspace_id' => $workspace_id, 'limit' => 20]) : [];
        $kg_runs = class_exists('VES_Knowledge_Consolidator') ? VES_Knowledge_Consolidator::list_runs_for_admin(['workspace_id' => $workspace_id, 'limit' => 10]) : [];
        ?>
        <div class="wrap">
            <h1>VE Memory / Knowledge</h1>
            <p>Admin-only panel for workspace knowledge, approved insight review, operational memory, and AI context diagnostics.</p>
            <?php if (!empty($_GET['ves_notice'])) :
                $ves_notice_msg = sanitize_text_field(wp_unslash((string) $_GET['ves_notice']));
                $ves_notice_is_error = (bool) preg_match('/\b(cannot|error|fail|blocked|unsupported|skipped)\b/i', $ves_notice_msg);
                $ves_notice_class = $ves_notice_is_error ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible'; ?>
                <div class="<?php echo esc_attr($ves_notice_class); ?>"><p><?php echo esc_html($ves_notice_msg); ?></p></div>
            <?php endif; ?>
            <?php if (class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'legacy_approval_health')) :
                $ves_legacy_health = VES_Insight_Records::legacy_approval_health($workspace_id); ?>
                <p class="description">
                    <?php echo esc_html__('Legacy insights:', 'ves'); ?>
                    <strong><?php echo (int) $ves_legacy_health['legacy_total']; ?></strong> <?php echo esc_html__('total', 'ves'); ?> ·
                    <strong><?php echo (int) $ves_legacy_health['legacy_approved']; ?></strong> <?php echo esc_html__('approved', 'ves'); ?> ·
                    <strong><?php echo (int) $ves_legacy_health['legacy_approved_without_evidence']; ?></strong> <?php echo esc_html__('approved without evidence', 'ves'); ?>
                    (<?php echo esc_html__('manual approval requires supporting evidence', 'ves'); ?>).
                </p>
            <?php endif; ?>
            <?php if (class_exists('VES_Brand_Context_Service')) :
                // Phase 6A — Brand Context (Memory V2): read-only sections + per-status review actions.
                echo VES_Brand_Context_Service::render_admin_html($workspace_id, 'brief_generation');
                // Actionable rows = candidate / active / pinned. Rejected/archived/expired
                // are diagnostic-only (shown in the read-only tables above). Edit is out of scope.
                $bc_actionable = array_filter(VES_Brand_Context_Service::load_rows($workspace_id), function ($r) {
                    return in_array(VES_Brand_Context_Service::status_of($r), [
                        VES_Brand_Context_Service::STATUS_CANDIDATE,
                        VES_Brand_Context_Service::STATUS_ACTIVE,
                        VES_Brand_Context_Service::STATUS_PINNED,
                    ], true);
                });
                if (!empty($bc_actionable)) : ?>
                <h3><?php echo esc_html__('Review brand context memory', 'ves'); ?></h3>
                <p class="description"><?php echo esc_html__('Candidate memory is NOT used in generation until approved. Edit is out of scope in this phase.', 'ves'); ?></p>
                <?php foreach ($bc_actionable as $bc_row) :
                    $bc_id = (int) ($bc_row['id'] ?? 0); if ($bc_id <= 0) { continue; }
                    $bc_status = VES_Brand_Context_Service::status_of($bc_row);
                    // Status-appropriate actions.
                    if ($bc_status === VES_Brand_Context_Service::STATUS_CANDIDATE) {
                        $bc_actions = [['brand_ctx_approve', __('Approve', 'ves'), 'button-primary'], ['brand_ctx_reject', __('Reject', 'ves'), ''], ['brand_ctx_archive', __('Archive', 'ves'), '']];
                    } elseif ($bc_status === VES_Brand_Context_Service::STATUS_PINNED) {
                        $bc_actions = [['brand_ctx_unpin', __('Unpin', 'ves'), ''], ['brand_ctx_archive', __('Archive', 'ves'), '']];
                    } else { // active
                        $bc_actions = [['brand_ctx_pin', __('Pin', 'ves'), 'button-primary'], ['brand_ctx_archive', __('Archive', 'ves'), '']];
                    } ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:block;margin:4px 0;">
                        <?php wp_nonce_field('ves_memory_admin_action'); ?>
                        <input type="hidden" name="action" value="ves_memory_admin_action">
                        <input type="hidden" name="workspace_id" value="<?php echo esc_attr($workspace_id); ?>">
                        <input type="hidden" name="memory_id" value="<?php echo esc_attr($bc_id); ?>">
                        <code><?php echo esc_html((string) ($bc_row['memory_type'] ?? '')); ?></code>
                        <span class="description">[<?php echo esc_html($bc_status); ?>]</span>:
                        <?php echo esc_html(mb_substr((string) ($bc_row['summary'] ?? ''), 0, 90)); ?>
                        <?php foreach ($bc_actions as $bc_a) : ?>
                            <button type="submit" name="memory_action_type" value="<?php echo esc_attr($bc_a[0]); ?>" class="button <?php echo esc_attr($bc_a[2]); ?>"><?php echo esc_html($bc_a[1]); ?></button>
                        <?php endforeach; ?>
                    </form>
                <?php endforeach; ?>
            <?php endif; endif; ?>
            <form method="get" style="margin:16px 0;">
                <input type="hidden" name="page" value="ves-memory-knowledge">
                <label for="ves_workspace_id"><strong>Workspace ID</strong></label>
                <input id="ves_workspace_id" type="text" name="workspace_id" value="<?php echo esc_attr($workspace_id); ?>" class="regular-text">
                <?php submit_button('Load workspace', 'secondary', '', false); ?>
            </form>

            <h2>Company / Workspace Knowledge Profile</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px;">
                <?php wp_nonce_field('ves_memory_admin_action'); ?>
                <input type="hidden" name="action" value="ves_memory_admin_action">
                <input type="hidden" name="memory_action_type" value="save_profile">
                <input type="hidden" name="workspace_id" value="<?php echo esc_attr($workspace_id); ?>">
                <table class="form-table" role="presentation"><tbody>
                    <?php self::render_memory_profile_input('company_name', 'Company name', $profile['company_name'] ?? '', 'text'); ?>
                    <?php self::render_memory_profile_input('market_country', 'Market / country', $profile['market_country'] ?? '', 'text'); ?>
                    <?php self::render_memory_profile_input('industry', 'Industry', $profile['industry'] ?? '', 'text'); ?>
                    <?php self::render_memory_profile_input('brand_positioning', 'Brand positioning', $profile['brand_positioning'] ?? '', 'textarea'); ?>
                    <?php self::render_memory_profile_input('tone_style_guidance', 'Tone / style guidance', $profile['tone_style_guidance'] ?? '', 'textarea'); ?>
                    <?php self::render_memory_profile_input('products_services', 'Products / services', self::list_to_text($profile['known_products_services'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('competitors', 'Competitors', self::list_to_text($profile['competitors'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('audience_segments', 'Audience segments', self::list_to_text($profile['audience_segments'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('known_strengths', 'Known strengths', self::list_to_text($profile['known_strengths'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('known_risks', 'Known risks', self::list_to_text($profile['known_risks'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('known_opportunities', 'Known opportunities', self::list_to_text($profile['known_opportunities'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('approved_learnings', 'Approved strategic learnings', self::list_to_text($profile['approved_strategic_learnings'] ?? []), 'textarea'); ?>
                    <?php self::render_memory_profile_input('rejected_assumptions', 'Rejected / outdated assumptions', self::list_to_text($profile['rejected_outdated_assumptions'] ?? []), 'textarea'); ?>
                </tbody></table>
                <?php submit_button('Save knowledge profile'); ?>
            </form>

            <h2>AI Context Diagnostics</h2>
            <p><strong><?php echo esc_html($context_pack['used_context'] ?? 'Used context: current request only.'); ?></strong></p>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <?php foreach ($diag as $key => $value) : ?>
                    <tr><td style="width:260px"><code><?php echo esc_html((string) $key); ?></code></td><td><?php echo esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <p class="description">Semantic retrieval is local/semantic-lite unless a site-specific embedding provider is attached through filters. Feedback score changes retrieval weight; it does not train or fine-tune a model.</p>

            <h2>Knowledge Graph + Consolidation</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;">
                <?php wp_nonce_field('ves_memory_admin_action'); ?>
                <input type="hidden" name="action" value="ves_memory_admin_action">
                <input type="hidden" name="memory_action_type" value="run_kg_consolidation">
                <input type="hidden" name="workspace_id" value="<?php echo esc_attr($workspace_id); ?>">
                <?php submit_button('Run knowledge consolidation now', 'secondary', '', false); ?>
            </form>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <?php foreach ((array) $kg_stats as $key => $value) : ?>
                    <tr><td style="width:260px"><code><?php echo esc_html((string) $key); ?></code></td><td><?php echo esc_html((string) $value); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <p class="description">Knowledge graph consolidation converts approved/profile/memory signals into durable entities and relationships. It does not call AI providers or train a model.</p>

            <h3>Recent Graph Nodes</h3>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Type</th><th>Label</th><th>Status</th><th>Scores</th><th>Updated</th></tr></thead><tbody>
            <?php if (empty($kg_nodes)) : ?><tr><td colspan="5">No graph nodes yet.</td></tr><?php endif; ?>
            <?php foreach ($kg_nodes as $node) : ?>
                <tr><td><code><?php echo esc_html((string) ($node['node_type'] ?? '')); ?></code></td><td><strong><?php echo esc_html((string) ($node['label'] ?? '')); ?></strong><br><?php echo esc_html(mb_substr((string) ($node['summary'] ?? ''), 0, 180)); ?></td><td><?php echo esc_html((string) ($node['status'] ?? '')); ?></td><td>Confidence: <?php echo esc_html((string) ($node['confidence_score'] ?? '')); ?><br>Importance: <?php echo esc_html((string) ($node['importance_score'] ?? '')); ?></td><td><?php echo esc_html((string) ($node['updated_at'] ?? '')); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h3>Recent Graph Edges</h3>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Relation</th><th>From</th><th>To</th><th>Weight</th><th>Updated</th></tr></thead><tbody>
            <?php if (empty($kg_edges)) : ?><tr><td colspan="5">No graph edges yet.</td></tr><?php endif; ?>
            <?php foreach ($kg_edges as $edge) : ?>
                <tr><td><code><?php echo esc_html((string) ($edge['relation_type'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($edge['from_node_key'] ?? '')); ?></td><td><?php echo esc_html((string) ($edge['to_node_key'] ?? '')); ?></td><td><?php echo esc_html((string) ($edge['weight'] ?? '')); ?></td><td><?php echo esc_html((string) ($edge['updated_at'] ?? '')); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h3>Consolidation Runs</h3>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>ID</th><th>Status</th><th>Scope</th><th>Inputs</th><th>Created / Updated</th><th>Completed</th></tr></thead><tbody>
            <?php if (empty($kg_runs)) : ?><tr><td colspan="6">No consolidation runs yet.</td></tr><?php endif; ?>
            <?php foreach ($kg_runs as $run) : ?>
                <tr><td><?php echo esc_html((string) ($run['id'] ?? '')); ?></td><td><?php echo esc_html((string) ($run['status'] ?? '')); ?></td><td><?php echo esc_html((string) ($run['scope'] ?? '')); ?></td><td><?php echo esc_html(wp_json_encode($run['input_counts'] ?? [])); ?></td><td>N: <?php echo esc_html((string) (($run['created_nodes'] ?? 0) . '/' . ($run['updated_nodes'] ?? 0))); ?><br>E: <?php echo esc_html((string) (($run['created_edges'] ?? 0) . '/' . ($run['updated_edges'] ?? 0))); ?></td><td><?php echo esc_html((string) ($run['completed_at'] ?? '')); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Recent Feedback Signals</h2>
            <table class="widefat striped" style="max-width:1000px"><thead><tr><th>ID</th><th>Object</th><th>Feedback</th><th>Rating</th><th>Comment</th><th>Created</th></tr></thead><tbody>
            <?php if (empty($feedbacks)) : ?><tr><td colspan="6">No feedback signals yet.</td></tr><?php endif; ?>
            <?php foreach ($feedbacks as $feedback) : ?>
                <tr><td><?php echo esc_html((string) ($feedback['id'] ?? '')); ?></td><td><?php echo esc_html((string) ($feedback['object_type'] ?? '')); ?>: <code><?php echo esc_html((string) ($feedback['object_id'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($feedback['feedback_type'] ?? '')); ?></td><td><?php echo esc_html((string) ($feedback['rating'] ?? '')); ?></td><td><?php echo esc_html((string) ($feedback['comment'] ?? '')); ?></td><td><?php echo esc_html((string) ($feedback['created_at'] ?? '')); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Approved / Proposed Insight Ledger</h2>
            <table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Type</th><th>Title</th><th>Scores</th><th>Evidence</th><th>Actions</th></tr></thead><tbody>
            <?php if (empty($insights)) : ?><tr><td colspan="7">No insight records found for this workspace.</td></tr><?php endif; ?>
            <?php foreach ($insights as $insight) : ?>
                <tr>
                    <td><?php echo esc_html((string) ($insight['id'] ?? '')); ?></td>
                    <td><code><?php echo esc_html((string) ($insight['status'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($insight['insight_type'] ?? '')); ?></td>
                    <td><strong><?php echo esc_html((string) ($insight['title'] ?? '')); ?></strong><br><?php echo esc_html(mb_substr((string) ($insight['summary'] ?? ''), 0, 220)); ?></td>
                    <td>Confidence: <?php echo esc_html((string) ($insight['confidence_score'] ?? '')); ?><br>Actionability: <?php echo esc_html((string) ($insight['actionability_score'] ?? '')); ?></td>
                    <td><?php echo esc_html(wp_json_encode($insight['evidence_refs'] ?? [])); ?></td>
                    <td><?php self::render_insight_action_buttons((int) ($insight['id'] ?? 0), $workspace_id); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Recent Operational Memory</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;">
                <?php wp_nonce_field('ves_memory_admin_action'); ?>
                <input type="hidden" name="action" value="ves_memory_admin_action">
                <input type="hidden" name="memory_action_type" value="clear_workspace_memory">
                <input type="hidden" name="workspace_id" value="<?php echo esc_attr($workspace_id); ?>">
                <?php submit_button('Clear workspace memory', 'delete', '', false, ['onclick' => "return confirm('Clear operational memory for this workspace?');"]); ?>
            </form>
            <table class="widefat striped"><thead><tr><th>ID</th><th>Type</th><th>Pinned</th><th>Summary</th><th>Source</th><th>Expires</th><th>Actions</th></tr></thead><tbody>
            <?php if (empty($memories)) : ?><tr><td colspan="7">No memory records found for this workspace.</td></tr><?php endif; ?>
            <?php foreach ($memories as $memory) : ?>
                <tr>
                    <td><?php echo esc_html((string) ($memory['id'] ?? '')); ?></td>
                    <td><code><?php echo esc_html((string) ($memory['memory_type'] ?? '')); ?></code></td>
                    <td><?php echo !empty($memory['is_pinned']) ? 'yes' : 'no'; ?></td>
                    <td><?php echo esc_html((string) ($memory['summary'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($memory['source_family'] ?? '')); ?> / <?php echo esc_html((string) ($memory['platform'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($memory['expires_at'] ?? '')); ?></td>
                    <td><?php self::render_memory_action_buttons((int) ($memory['id'] ?? 0), !empty($memory['is_pinned']), $workspace_id); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Mark assumption outdated</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ves_memory_admin_action'); ?>
                <input type="hidden" name="action" value="ves_memory_admin_action">
                <input type="hidden" name="memory_action_type" value="mark_assumption_outdated">
                <input type="hidden" name="workspace_id" value="<?php echo esc_attr($workspace_id); ?>">
                <textarea name="assumption" rows="3" class="large-text" placeholder="Assumption to reject or mark outdated"></textarea>
                <?php submit_button('Mark outdated / rejected'); ?>
            </form>
        </div>
        <?php
    }

    private static function render_memory_profile_input($name, $label, $value, $type = 'text') {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        if ($type === 'textarea') {
            echo '<textarea id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" rows="3" class="large-text">' . esc_textarea((string) $value) . '</textarea>';
        } else {
            echo '<input id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="text" class="regular-text" value="' . esc_attr((string) $value) . '">';
        }
        echo '</td></tr>';
    }

    private static function render_insight_action_buttons($id, $workspace_id) {
        foreach (['approve_insight' => 'Approve', 'reject_insight' => 'Reject', 'archive_insight' => 'Archive', 'supersede_insight' => 'Supersede'] as $action => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0;">';
            wp_nonce_field('ves_memory_admin_action');
            echo '<input type="hidden" name="action" value="ves_memory_admin_action">';
            echo '<input type="hidden" name="memory_action_type" value="' . esc_attr($action) . '">';
            echo '<input type="hidden" name="workspace_id" value="' . esc_attr($workspace_id) . '">';
            echo '<input type="hidden" name="insight_id" value="' . esc_attr((string) $id) . '">';
            submit_button($label, 'secondary small', '', false);
            echo '</form>';
        }
    }

    private static function render_memory_action_buttons($id, $is_pinned, $workspace_id) {
        $actions = $is_pinned ? ['unpin_memory' => 'Unpin', 'delete_memory' => 'Delete'] : ['pin_memory' => 'Pin', 'delete_memory' => 'Delete'];
        foreach ($actions as $action => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0;">';
            wp_nonce_field('ves_memory_admin_action');
            echo '<input type="hidden" name="action" value="ves_memory_admin_action">';
            echo '<input type="hidden" name="memory_action_type" value="' . esc_attr($action) . '">';
            echo '<input type="hidden" name="workspace_id" value="' . esc_attr($workspace_id) . '">';
            echo '<input type="hidden" name="memory_id" value="' . esc_attr((string) $id) . '">';
            submit_button($label, $action === 'delete_memory' ? 'delete small' : 'secondary small', '', false);
            echo '</form>';
        }
        foreach (['feedback_helpful' => 'Helpful', 'feedback_not_helpful' => 'Not helpful', 'feedback_outdated' => 'Outdated'] as $action => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0;">';
            wp_nonce_field('ves_memory_admin_action');
            echo '<input type="hidden" name="action" value="ves_memory_admin_action">';
            echo '<input type="hidden" name="memory_action_type" value="' . esc_attr($action) . '">';
            echo '<input type="hidden" name="workspace_id" value="' . esc_attr($workspace_id) . '">';
            echo '<input type="hidden" name="object_type" value="memory">';
            echo '<input type="hidden" name="object_id" value="' . esc_attr((string) $id) . '">';
            submit_button($label, 'secondary small', '', false);
            echo '</form>';
        }
    }

    private static function textarea_to_list($raw) {
        $raw = is_array($raw) ? implode("\n", $raw) : (string) wp_unslash($raw);
        $lines = preg_split('/[\r\n,]+/', $raw);
        $out = [];
        foreach ($lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return array_values(array_unique(array_slice($out, 0, 50)));
    }

    private static function list_to_text($value) {
        if (!is_array($value)) {
            return (string) $value;
        }
        $lines = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['title'] ?? $item['summary'] ?? wp_json_encode($item);
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $lines[] = $item;
            }
        }
        return implode("\n", $lines);
    }

    public static function get_diagnostic_log() {
        global $wpdb;
        $max_bytes = defined('VE_SOCIAL_DIAGNOSTIC_LOG_MAX_BYTES') ? (int) VE_SOCIAL_DIAGNOSTIC_LOG_MAX_BYTES : 300000;
        $max_bytes = max(50000, min(2000000, $max_bytes));
        $size = null;
        if (isset($wpdb) && $wpdb instanceof wpdb) {
            $size = $wpdb->get_var($wpdb->prepare(
                "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::DIAG_LOG_KEY
            ));
        }
        if ($size !== null && (int) $size > $max_bytes) {
            delete_option(self::DIAG_LOG_KEY);
            return [];
        }
        $log = get_option(self::DIAG_LOG_KEY, []);
        return is_array($log) ? array_slice($log, 0, self::DIAG_LOG_LIMIT) : [];
    }

    public static function record_diagnostic($source, $message, $context = []) {
        $payload = [
            'timestamp' => current_time('mysql'),
            'source' => sanitize_text_field((string) $source),
            'message' => sanitize_textarea_field((string) $message),
            'context' => self::sanitize_context($context),
        ];

        update_option(self::DIAG_KEY, $payload, false);

        // AJAX requests must never fail just because the diagnostic history option
        // has grown too large. A corrupted or oversized diagnostic log can cause a
        // PHP memory fatal before the Apify request is even sent. During AJAX we
        // keep the latest diagnostic only; the admin page still has history when safe.
        if (function_exists('wp_doing_ajax') && wp_doing_ajax() && !defined('VE_SOCIAL_AJAX_DIAGNOSTIC_HISTORY')) {
            return;
        }

        $log = self::get_diagnostic_log();
        array_unshift($log, $payload);
        $log = array_slice($log, 0, self::DIAG_LOG_LIMIT);
        update_option(self::DIAG_LOG_KEY, $log, false);
    }

    private static function sanitize_context($context, $depth = 0) {
        if ($depth > 4) {
            return '[trimmed]';
        }
        if (is_array($context)) {
            $out = [];
            $count = 0;
            foreach ($context as $key => $value) {
                $count++;
                if ($count > 30) {
                    $out['__trimmed__'] = 'Too many context keys';
                    break;
                }
                $clean_key = is_string($key) ? sanitize_key($key) : (string) $key;
                $out[$clean_key] = self::sanitize_context($value, $depth + 1);
            }
            return $out;
        }
        if (is_object($context)) {
            return self::sanitize_context((array) $context, $depth + 1);
        }
        if (is_bool($context) || is_null($context) || is_int($context) || is_float($context)) {
            return $context;
        }
        $value = preg_replace('/\s+/', ' ', (string) $context);
        $value = sanitize_text_field($value);
        if (strlen($value) > 500) {
            $value = substr($value, 0, 500) . '…';
        }
        return $value;
    }

    public static function render_adapter_diagnostics_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Insufficient permissions.', 'ves')); }
        $platforms = ['tiktok','instagram','reddit','youtube','twitter','linkedin','facebook','facebook_ads','google_ads','semrush','google_search','google_news','google_trends','google_maps','pinterest'];
        echo '<div class="wrap ves-admin-page"><h1>VE Adapter Diagnostics</h1><p>Admin-only view of actor input contracts, expected fields and safe payload examples. No provider call is made here.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Source</th><th>Actor</th><th>Adapter</th><th>Required input</th><th>Renderer</th><th>Health</th></tr></thead><tbody>';
        foreach ($platforms as $platform) {
            $actor = function_exists('ves_get_actor_slug') ? ves_get_actor_slug($platform, []) : '';
            $adapter = class_exists('VES_Source_Adapter_Registry') ? VES_Source_Adapter_Registry::adapter_for($platform, $actor) : [];
            $required = array_merge((array) ($adapter['required_fields'] ?? []), (array) ($adapter['required_any'] ?? []));
            echo '<tr><td>' . esc_html($platform) . '</td><td><code>' . esc_html($actor ?: 'not configured') . '</code></td><td>' . esc_html($adapter['adapter_key'] ?? 'generic') . '</td><td><code>' . esc_html(implode(', ', $required) ?: 'none') . '</code></td><td>' . esc_html($adapter['renderer_type'] ?? 'generic') . '</td><td>' . esc_html(empty($actor) ? 'setup required' : 'configured') . '</td></tr>';
        }
        echo '</tbody></table><p class="description">Detailed raw provider rows are intentionally not shown here until a real run exists. Use the Operations log for last-run errors.</p></div>';
    }

    public static function render_billing_ledger_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Insufficient permissions.', 'ves')); }
        global $wpdb;
        $tables = [$wpdb->prefix . 'ves_usage_ledger', $wpdb->prefix . 'ves_credit_ledger', $wpdb->prefix . 'ves_usage_events'];
        echo '<div class="wrap ves-admin-page"><h1>VE Billing Ledger</h1><p>Reservation, charge, refund and provider-cost audit trail. If no ledger table exists yet, this page shows setup status.</p>';
        $found = false;
        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if (!$exists) { continue; }
            $found = true;
            echo '<h2>' . esc_html($table) . '</h2>';
            $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY 1 DESC LIMIT 50", ARRAY_A);
            if (empty($rows)) { echo '<p>No ledger rows yet.</p>'; continue; }
            echo '<table class="widefat striped"><thead><tr>';
            foreach (array_keys($rows[0]) as $col) { echo '<th>' . esc_html($col) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) { echo '<tr>'; foreach ($row as $value) { echo '<td><code>' . esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)) . '</code></td>'; } echo '</tr>'; }
            echo '</tbody></table>';
        }
        if (!$found) { echo '<div class="notice notice-warning inline"><p>No billing ledger table was found. Usage may still be stored in the current plugin billing option layer until migration.</p></div>'; }
        echo '</div>';
    }

    public static function render_prompt_templates_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Insufficient permissions.', 'ves')); }
        $modules = ['social','reddit','seo','trend','ads','creative','brand_audit'];
        echo '<div class="wrap ves-admin-page"><h1>VE Prompt Template Manager</h1><p>Read-only prompt inventory for this build. Module prompts are schema-first and should require evidence IDs. Full editing/versioning can be enabled in a later migration.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Required evidence rules</th><th>Schema additions</th><th>Status</th></tr></thead><tbody>';
        foreach ($modules as $module) {
            $additions = [
                'social' => 'content_patterns, hook_patterns, engagement_drivers, audience_signals, creative_angles',
                'reddit' => 'pain_points, objections, repeated_questions, community_context, discussion_quality',
                'seo' => 'keyword_clusters, intent_map, content_opportunities, SERP_gap, AEO_questions, priority_keywords',
                'trend' => 'channel_readouts, prioritized_trends, confidence_mode, action_board, what_to_ignore',
                'ads' => 'creative_patterns, offer_patterns, CTA_patterns, funnel_stage, competitor_angle',
                'creative' => 'hook_score, brand_score, platform_fit, visual_pattern, first_3_seconds, CTA_score, variants',
                'brand_audit' => 'evidence_quality, partial_evidence_warning, competitor_comparison, next_data_plan',
            ][$module];
            echo '<tr><td>' . esc_html($module) . '</td><td>confirmed findings, weak hypotheses, missing data, risks, next actions and source_evidence_ids</td><td>' . esc_html($additions) . '</td><td>active / code-managed</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function render_project_manager_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Insufficient permissions.', 'ves')); }
        echo '<div class="wrap ves-admin-page"><h1>VE Project Manager</h1><p>Project context, memory scope and active project isolation. Create/switch/archive actions reuse the existing VE Projects screen in this build.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('tools.php?page=ves-projects')) . '">Open VE Projects</a> <a class="button" href="' . esc_url(admin_url('tools.php?page=ves-memory-knowledge')) . '">Open Memory / Knowledge</a></p>';
        echo '<ul><li>Memory is scoped by workspace/user and brand where available.</li><li>Failed and zero-result runs should not be reused as strong AI context.</li><li>Recent memory remains operational by default, with 7-day retention unless configured.</li></ul></div>';
    }

    private static function render_constant_notice($key) {
        $map = [
            'backend_token' => 'VE_SOCIAL_BACKEND_TOKEN',
            'openai_api_key' => 'VE_SOCIAL_OPENAI_API_KEY',
            'openai_model' => 'VE_SOCIAL_OPENAI_MODEL',
            'openai_reasoning_effort' => 'VE_SOCIAL_OPENAI_REASONING_EFFORT',
            'require_login' => 'VE_SOCIAL_REQUIRE_LOGIN',
            'rate_limit_seconds' => 'VE_SOCIAL_RATE_LIMIT_SECONDS',
            'max_items' => 'VE_SOCIAL_MAX_ITEMS',
            'max_charge_usd' => 'VE_SOCIAL_MAX_CHARGE_USD',
            'memory_retention_days' => 'VE_SOCIAL_MEMORY_RETENTION_DAYS',
            'ai_context_max_memory_records' => 'VE_SOCIAL_AI_CONTEXT_MAX_MEMORY_RECORDS',
            'ai_context_max_approved_insights' => 'VE_SOCIAL_AI_CONTEXT_MAX_APPROVED_INSIGHTS',
            'ai_context_max_chars' => 'VE_SOCIAL_AI_CONTEXT_MAX_CHARS',
            'semantic_retrieval_enabled' => 'VE_SOCIAL_SEMANTIC_RETRIEVAL_ENABLED',
            'ai_context_max_semantic_matches' => 'VE_SOCIAL_AI_CONTEXT_MAX_SEMANTIC_MATCHES',
            'semantic_min_score' => 'VE_SOCIAL_SEMANTIC_MIN_SCORE',
            'knowledge_graph_enabled' => 'VE_SOCIAL_KNOWLEDGE_GRAPH_ENABLED',
            'knowledge_consolidation_enabled' => 'VE_SOCIAL_KNOWLEDGE_CONSOLIDATION_ENABLED',
            'ai_context_max_knowledge_graph_items' => 'VE_SOCIAL_AI_CONTEXT_MAX_KNOWLEDGE_GRAPH_ITEMS',
            'apify_max_estimated_cost_per_run' => 'VE_SOCIAL_APIFY_MAX_ESTIMATED_COST_PER_RUN',
            'apify_cost_warning_threshold_usd' => 'VE_SOCIAL_APIFY_COST_WARNING_THRESHOLD_USD',
            'max_external_provider_cost_per_run' => 'VE_SOCIAL_MAX_EXTERNAL_PROVIDER_COST_PER_RUN',
            'enable_youtube_trending_in_trend_finder' => 'VE_SOCIAL_ENABLE_YOUTUBE_TRENDING_IN_TREND_FINDER',
            'enable_twitter_trends_in_trend_finder' => 'VE_SOCIAL_ENABLE_TWITTER_TRENDS_IN_TREND_FINDER',
            'max_twitter_trend_items' => 'VE_SOCIAL_MAX_TWITTER_TREND_ITEMS',
            'max_youtube_trending_cost' => 'VE_SOCIAL_MAX_YOUTUBE_TRENDING_COST',
            'tiktok_slug' => 'VE_SOCIAL_TIKTOK_SLUG',
            'tiktok_enrichment_slug' => 'VE_SOCIAL_TIKTOK_ENRICHMENT_SLUG',
            'tiktok_comments_slug' => 'VE_SOCIAL_TIKTOK_COMMENTS_SLUG',
            'tiktok_fallback_slug' => 'VE_SOCIAL_TIKTOK_FALLBACK_SLUG',
            'tiktok_backup_slug' => 'VE_SOCIAL_TIKTOK_BACKUP_SLUG',
            'youtube_slug' => 'VE_SOCIAL_YOUTUBE_SLUG',
            'facebook_slug' => 'VE_SOCIAL_FACEBOOK_SLUG',
            'instagram_slug' => 'VE_SOCIAL_INSTAGRAM_SLUG',
            'facebook_ads_slug' => 'VE_SOCIAL_FACEBOOK_ADS_SLUG',
            'google_ads_slug' => 'VE_SOCIAL_GOOGLE_ADS_SLUG',
            'twitter_slug' => 'VE_SOCIAL_TWITTER_SLUG',
            'linkedin_slug' => 'VE_SOCIAL_LINKEDIN_SLUG',
            'reddit_slug' => 'VE_SOCIAL_REDDIT_SLUG',
            'pinterest_slug' => 'VE_SOCIAL_PINTEREST_SLUG',
            'semrush_slug' => 'VE_SEO_SEMRUSH_SLUG',
            'semrush_keywords_slug' => 'VE_SEO_SEMRUSH_KEYWORDS_SLUG',
            'semrush_keyword_magic_slug' => 'VE_SEO_SEMRUSH_KEYWORD_MAGIC_SLUG',
            'semrush_domain_slug' => 'VE_SEO_SEMRUSH_DOMAIN_SLUG',
            'moz_slug' => 'VE_SEO_MOZ_SLUG',
            'ahrefs_slug' => 'VE_SEO_AHREFS_SLUG',
            'google_trends_slug' => 'VE_SOCIAL_GOOGLE_TRENDS_SLUG',
            'youtube_trending_slug' => 'VE_SOCIAL_YOUTUBE_TRENDING_SLUG',
            'tiktok_trends_slug' => 'VE_SOCIAL_TIKTOK_TRENDS_SLUG',
            'twitter_trends_slug' => 'VE_SOCIAL_TWITTER_TRENDS_SLUG',
            'admin_error_details' => 'VE_SOCIAL_ADMIN_ERROR_DETAILS',
        ];
        $constant = $map[$key] ?? '';
        if ($constant && defined($constant)) {
            echo '<p class="description"><strong>Override activo:</strong> ' . esc_html($constant) . ' está definido en wp-config.php.</p>';
        }
    }
}
