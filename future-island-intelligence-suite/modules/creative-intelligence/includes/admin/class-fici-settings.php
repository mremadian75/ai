<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Settings {
    public const OPTION_NAME = 'fici_settings';
    public const PAGE_SLUG   = 'future-island-creative-intelligence';

    public function register(): void {
        register_setting(
            self::PAGE_SLUG,
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => $this->get_defaults(),
            )
        );

        add_settings_section( 'fici_ai', __( 'AI provider', FICI_TEXT_DOMAIN ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'fici_limits', __( 'Upload and cost controls', FICI_TEXT_DOMAIN ), '__return_false', self::PAGE_SLUG );

        $fields = array(
            'openai_api_key'       => array( 'section' => 'fici_ai', 'label' => __( 'OpenAI API key', FICI_TEXT_DOMAIN ), 'callback' => 'render_password_field' ),
            'openai_model'         => array( 'section' => 'fici_ai', 'label' => __( 'OpenAI model', FICI_TEXT_DOMAIN ), 'callback' => 'render_text_field' ),
            'max_output_tokens'    => array( 'section' => 'fici_ai', 'label' => __( 'Max output tokens', FICI_TEXT_DOMAIN ), 'callback' => 'render_number_field' ),
            'timeout_seconds'      => array( 'section' => 'fici_ai', 'label' => __( 'API timeout seconds', FICI_TEXT_DOMAIN ), 'callback' => 'render_number_field' ),
            'max_file_size_mb'     => array( 'section' => 'fici_limits', 'label' => __( 'Max file size MB', FICI_TEXT_DOMAIN ), 'callback' => 'render_number_field' ),
            'retention_hours'      => array( 'section' => 'fici_limits', 'label' => __( 'Raw asset retention hours', FICI_TEXT_DOMAIN ), 'callback' => 'render_number_field' ),
            'cost_image_standard'  => array( 'section' => 'fici_limits', 'label' => __( 'Image analysis credits', FICI_TEXT_DOMAIN ), 'callback' => 'render_number_field' ),
            'cost_video_standard'  => array( 'section' => 'fici_limits', 'label' => __( 'Video analysis credits', FICI_TEXT_DOMAIN ), 'callback' => 'render_number_field' ),
            'enable_memory_write'  => array( 'section' => 'fici_limits', 'label' => __( 'Write memory record', FICI_TEXT_DOMAIN ), 'callback' => 'render_checkbox_field' ),
            'enable_brief_output'  => array( 'section' => 'fici_limits', 'label' => __( 'Allow brief output', FICI_TEXT_DOMAIN ), 'callback' => 'render_checkbox_field' ),
        );

        foreach ( $fields as $key => $field ) {
            add_settings_field(
                $key,
                $field['label'],
                array( $this, $field['callback'] ),
                self::PAGE_SLUG,
                $field['section'],
                array( 'key' => $key )
            );
        }
    }

    public function add_menu(): void {
        add_options_page(
            __( 'Creative Intelligence', FICI_TEXT_DOMAIN ),
            __( 'Creative Intelligence', FICI_TEXT_DOMAIN ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function get_defaults(): array {
        return array(
            'openai_api_key'      => '',
            'openai_model'        => 'gpt-4o-mini',
            'max_output_tokens'   => 1600,
            'timeout_seconds'     => 45,
            'max_file_size_mb'    => 20,
            'retention_hours'     => 24,
            'cost_image_standard' => 6,
            'cost_video_standard' => 20,
            'enable_memory_write' => true,
            'enable_brief_output' => true,
        );
    }

    public function get_settings(): array {
        $saved = get_option( self::OPTION_NAME, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $settings = wp_parse_args( $saved, $this->get_defaults() );

        if ( defined( 'FICI_OPENAI_API_KEY' ) && FICI_OPENAI_API_KEY ) {
            $settings['openai_api_key'] = FICI_OPENAI_API_KEY;
        } elseif ( empty( $settings['openai_api_key'] ) && class_exists( 'VES_Config' ) && method_exists( 'VES_Config', 'get_openai_api_key' ) ) {
            $settings['openai_api_key'] = (string) VES_Config::get_openai_api_key();
        }

        if ( ( empty( $settings['openai_model'] ) || 'gpt-4o-mini' === $settings['openai_model'] ) && class_exists( 'VES_Config' ) && method_exists( 'VES_Config', 'get_openai_model' ) ) {
            $core_model = (string) VES_Config::get_openai_model();
            if ( '' !== $core_model ) {
                $settings['openai_model'] = $core_model;
            }
        }

        return $settings;
    }

    public function sanitize_settings( array $input ): array {
        $defaults = $this->get_defaults();
        $existing = get_option( self::OPTION_NAME, array() );
        $existing = is_array( $existing ) ? $existing : array();
        $incoming_api_key = isset( $input['openai_api_key'] ) ? trim( (string) $input['openai_api_key'] ) : '';
        $stored_api_key = isset( $existing['openai_api_key'] ) ? (string) $existing['openai_api_key'] : '';
        $api_key = '' !== $incoming_api_key ? sanitize_text_field( $incoming_api_key ) : sanitize_text_field( $stored_api_key );

        return array(
            'openai_api_key'      => $api_key,
            'openai_model'        => isset( $input['openai_model'] ) ? sanitize_text_field( $input['openai_model'] ) : $defaults['openai_model'],
            'max_output_tokens'   => isset( $input['max_output_tokens'] ) ? max( 200, min( 8000, absint( $input['max_output_tokens'] ) ) ) : $defaults['max_output_tokens'],
            'timeout_seconds'     => isset( $input['timeout_seconds'] ) ? max( 10, min( 120, absint( $input['timeout_seconds'] ) ) ) : $defaults['timeout_seconds'],
            'max_file_size_mb'    => isset( $input['max_file_size_mb'] ) ? max( 1, min( 50, absint( $input['max_file_size_mb'] ) ) ) : $defaults['max_file_size_mb'],
            'retention_hours'     => isset( $input['retention_hours'] ) ? max( 1, min( 168, absint( $input['retention_hours'] ) ) ) : $defaults['retention_hours'],
            'cost_image_standard' => isset( $input['cost_image_standard'] ) ? max( 1, min( 1000, absint( $input['cost_image_standard'] ) ) ) : $defaults['cost_image_standard'],
            'cost_video_standard' => isset( $input['cost_video_standard'] ) ? max( 1, min( 2000, absint( $input['cost_video_standard'] ) ) ) : $defaults['cost_video_standard'],
            'enable_memory_write' => ! empty( $input['enable_memory_write'] ),
            'enable_brief_output' => ! empty( $input['enable_brief_output'] ),
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include FICI_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_text_field( array $args ): void {
        $key      = (string) $args['key'];
        $settings = $this->get_settings();
        printf(
            '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $key ),
            esc_attr( (string) $settings[ $key ] )
        );
    }

    public function render_password_field( array $args ): void {
        $key      = (string) $args['key'];
        $settings = $this->get_settings();
        $has_value = ! empty( $settings[ $key ] );
        printf(
            '<input type="password" class="regular-text" name="%1$s[%2$s]" value="" placeholder="%3$s" autocomplete="new-password" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $key ),
            esc_attr( $has_value ? __( 'Saved key configured — leave blank to keep it', FICI_TEXT_DOMAIN ) : '' )
        );
        echo '<p class="description">' . esc_html__( 'Leave blank to keep the existing key. Recommended: define FICI_OPENAI_API_KEY in wp-config.php instead of storing the key in the database.', FICI_TEXT_DOMAIN ) . '</p>';
    }

    public function render_number_field( array $args ): void {
        $key      = (string) $args['key'];
        $settings = $this->get_settings();
        printf(
            '<input type="number" min="1" step="1" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $key ),
            esc_attr( (string) $settings[ $key ] )
        );
    }

    public function render_checkbox_field( array $args ): void {
        $key      = (string) $args['key'];
        $settings = $this->get_settings();
        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $key ),
            checked( ! empty( $settings[ $key ] ), true, false ),
            esc_html__( 'Enabled', FICI_TEXT_DOMAIN )
        );
    }
}
