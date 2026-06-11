<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Plugin {
    private FICI_Settings $settings;
    private FICI_Core_Bridge $core_bridge;
    private FICI_AI_Provider_Registry $provider_registry;
    private FICI_File_Service $file_service;
    private FICI_Asset_Service $asset_service;
    private FICI_Analysis_Service $analysis_service;
    private FICI_REST_Analyze_Controller $rest_analyze_controller;

    public function __construct() {
        $this->settings          = new FICI_Settings();
        $this->core_bridge       = new FICI_Core_Bridge();
        $this->provider_registry = new FICI_AI_Provider_Registry();
        $this->file_service      = new FICI_File_Service( $this->settings );
        $this->asset_service     = new FICI_Asset_Service();

        $openai_provider = new FICI_OpenAI_Provider( $this->settings, new FICI_AI_Schema_Builder(), new FICI_Error_Mapper() );
        $this->provider_registry->register_provider( $openai_provider );

        $this->analysis_service = new FICI_Analysis_Service(
            $this->settings,
            $this->core_bridge,
            $this->provider_registry,
            $this->file_service,
            $this->asset_service,
            new FICI_Result_Normalizer()
        );

        $this->rest_analyze_controller = new FICI_REST_Analyze_Controller(
            $this->analysis_service,
            $this->settings,
            new FICI_Permissions(),
            new FICI_Rate_Limiter()
        );
    }

    public function init(): void {
        add_action( 'admin_init', array( 'FICI_Activator', 'maybe_upgrade' ), 5 );
        add_action( 'admin_init', array( $this->settings, 'register' ) );
        add_action( 'admin_menu', array( $this->settings, 'add_menu' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_core_notice' ) );
        add_action( 'rest_api_init', array( $this->rest_analyze_controller, 'register_routes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_shortcode( 'fici_creative_analyzer', array( $this, 'render_shortcode' ) );
    }

    public function maybe_show_core_notice(): void {
        if ( $this->core_bridge->is_core_available() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $fici_product_label = class_exists('VES_Branding') ? VES_Branding::product_name() : 'Intelligence Suite';
        echo '<div class="notice notice-warning"><p>' . esc_html(sprintf( /* translators: %s product name */ __( '%s Creative Intelligence is running in standalone mode. Connect it to the core SaaS plugin to enable real workspace credits, memory, runs, and insight creation.', FICI_TEXT_DOMAIN ), $fici_product_label )) . '</p></div>';
    }

    public function register_assets(): void {
        wp_register_style(
            'fici-frontend',
            FICI_PLUGIN_URL . 'assets/css/fici-frontend.css',
            array(),
            FICI_VERSION
        );

        wp_register_script(
            'fici-frontend',
            FICI_PLUGIN_URL . 'assets/js/fici-frontend.js',
            array(),
            FICI_VERSION,
            true
        );
    }

    public function render_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="fici-box">' . esc_html__( 'Please log in to use Creative Intelligence.', FICI_TEXT_DOMAIN ) . '</div>';
        }

        $settings          = $this->settings->get_settings();
        $workspace_context = $this->core_bridge->get_workspace_context();

        wp_enqueue_style( 'fici-frontend' );
        wp_enqueue_script( 'fici-frontend' );
        wp_localize_script(
            'fici-frontend',
            'FICI_FRONTEND',
            array(
                'restUrl'        => esc_url_raw( rest_url( FICI_REST_NAMESPACE ) ),
                'nonce'          => wp_create_nonce( 'wp_rest' ),
                'costs'          => array(
                    'image' => absint( $settings['cost_image_standard'] ),
                    'video' => absint( $settings['cost_video_standard'] ),
                    'text'  => max( 1, (int) ceil( absint( $settings['cost_image_standard'] ) / 2 ) ),
                    'url'   => absint( $settings['cost_image_standard'] ),
                ),
                'multipliers'    => array(
                    'quick'    => 0.65,
                    'standard' => 1,
                    'deep'     => 1.75,
                ),
                'maxVideoFrames' => 4,
                'maxVideoSeconds' => 60,
                'maxFileSizeMb'  => max( 1, min( 50, absint( $settings['max_file_size_mb'] ?? 20 ) ) ),
                'acceptedFormats' => 'JPG, PNG, WebP, GIF, MP4, MOV, WebM',
                'workspace'      => $workspace_context,
                'coreAvailable'  => $this->core_bridge->is_core_available(),
                // v0.9.30.21: expose admin flag so renderError can show diagnostics.
                'isAdmin'        => current_user_can( 'manage_options' ),
                'outputLanguage' => apply_filters( 'fici_output_language', 'es' ),
            )
        );

        ob_start();
        include FICI_PLUGIN_DIR . 'templates/panel-creative-analyzer.php';
        return (string) ob_get_clean();
    }
}
