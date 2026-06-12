<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_OpenAI_Provider implements FICI_AI_Provider {
    private FICI_Settings $settings;
    private FICI_AI_Schema_Builder $schema_builder;
    private FICI_Error_Mapper $error_mapper;

    public function __construct( FICI_Settings $settings, FICI_AI_Schema_Builder $schema_builder, FICI_Error_Mapper $error_mapper ) {
        $this->settings       = $settings;
        $this->schema_builder = $schema_builder;
        $this->error_mapper   = $error_mapper;
    }

    public function get_name(): string {
        return 'openai';
    }

    public function supports( string $task_type ): bool {
        return in_array( $task_type, array( 'image_analysis', 'video_frame_analysis', 'creative_review', 'brief_generation' ), true );
    }

    public function analyze_asset( array $payload ) {
        $settings = $this->settings->get_settings();
        $api_key  = (string) $settings['openai_api_key'];

        if ( '' === $api_key ) {
            return new WP_Error( 'fici_missing_api_key', __( 'OpenAI API key is not configured.', FICI_TEXT_DOMAIN ), array( 'status' => 500 ) );
        }

        // v0.9.30.21: resolve output language once; use apply_filters so the site
        // owner can override via add_filter('fici_output_language', ...).
        $output_language = function_exists( 'apply_filters' )
            ? sanitize_key( (string) apply_filters( 'fici_output_language', 'es' ) )
            : 'es';
        if ( '' === $output_language ) {
            $output_language = 'es';
        }

        $input_content = array(
            array(
                'type' => 'input_text',
                'text' => $this->build_user_text( $payload, $output_language ),
            ),
        );

        $image_data_urls = array();
        if ( ! empty( $payload['image_data_urls'] ) && is_array( $payload['image_data_urls'] ) ) {
            $image_data_urls = array_slice( array_values( $payload['image_data_urls'] ), 0, 4 );
        } elseif ( ! empty( $payload['image_data_url'] ) && is_string( $payload['image_data_url'] ) ) {
            $image_data_urls = array( (string) $payload['image_data_url'] );
        }

        foreach ( $image_data_urls as $image_data_url ) {
            if ( ! is_string( $image_data_url ) || '' === $image_data_url ) {
                continue;
            }
            $input_content[] = array(
                'type'      => 'input_image',
                'image_url' => $image_data_url,
            );
        }

        $request_body = array(
            'model'             => (string) $settings['openai_model'],
            'instructions'      => $this->instructions( $output_language ),
            'input'             => array(
                array(
                    'role'    => 'user',
                    'content' => $input_content,
                ),
            ),
            'text'              => array(
                'format' => array(
                    'type'   => 'json_schema',
                    'name'   => 'creative_asset_analysis',
                    'strict' => true,
                    'schema' => $this->schema_builder->creative_analysis_schema(),
                ),
            ),
            'max_output_tokens' => (int) $settings['max_output_tokens'],
            'store'             => false,
        );

        $fici_t0 = microtime( true ); // Phase 1 cost-spine timing.
        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            array(
                'timeout' => (int) $settings['timeout_seconds'],
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $request_body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'VES_AI_Usage_Tracker' ) ) {
                VES_AI_Usage_Tracker::record_openai_call( array(
                    'module' => 'creative_intelligence', 'operation_type' => 'asset_analysis',
                    'model' => (string) ( $request_body['model'] ?? '' ), 'started_at' => $fici_t0,
                    'response' => $response, 'user_id' => (int) ( $payload['user_id'] ?? 0 ),
                ) );
            }
            return new WP_Error( 'fici_request_timeout', $response->get_error_message(), array( 'status' => 502 ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $raw_body, true );

        if ( class_exists( 'VES_AI_Usage_Tracker' ) ) {
            $fici_ok = ( $status_code >= 200 && $status_code < 300 && is_array( $decoded ) );
            VES_AI_Usage_Tracker::record_openai_call( array(
                'module' => 'creative_intelligence', 'operation_type' => 'asset_analysis',
                'model' => (string) ( $request_body['model'] ?? '' ), 'started_at' => $fici_t0,
                'response' => is_array( $decoded ) ? $decoded : null,
                'status' => $fici_ok ? 'completed' : 'failed',
                'error_code' => $fici_ok ? '' : ( 'http_' . (int) $status_code ),
                'user_id' => (int) ( $payload['user_id'] ?? 0 ),
            ) );
        }

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'fici_invalid_response', __( 'AI provider returned invalid JSON.', FICI_TEXT_DOMAIN ), array( 'status' => 502 ) );
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            return $this->error_mapper->from_http_response( $status_code, is_array( $decoded ) ? $decoded : array() );
        }

        $text = $this->extract_output_text( is_array( $decoded ) ? $decoded : array() );
        if ( '' === $text ) {
            return new WP_Error( 'fici_empty_output', __( 'AI provider returned an empty structured response.', FICI_TEXT_DOMAIN ), array( 'status' => 502 ) );
        }

        $result = json_decode( $text, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $result ) ) {
            return new WP_Error( 'fici_schema_mismatch', __( 'AI provider output did not match the expected schema.', FICI_TEXT_DOMAIN ), array( 'status' => 502 ) );
        }

        return $result;
    }

    private function instructions( string $output_language = 'es' ): string {
        $lang_directive = $this->language_directive( $output_language );
        if ( class_exists( 'VES_AI_Prompt_Templates' ) && method_exists( 'VES_AI_Prompt_Templates', 'get' ) ) {
            $template = VES_AI_Prompt_Templates::get( 'creative_intelligence' );
            if ( is_array( $template ) && ! empty( $template['system'] ) ) {
                return (string) $template['system'] . ' Return only the requested structured JSON schema used by the Creative Intelligence module.' . $lang_directive;
            }
        }
        return 'You are a senior creative intelligence analyst for a marketing intelligence SaaS. Analyze uploaded creative assets and extracted video keyframes using evidence only. Separate observed features from interpretation. Extract hooks, formats, creative patterns, platform fit, weaknesses, campaign opportunities, limitations, and next actions. Return only the requested structured JSON.' . $lang_directive;
    }

    private function build_user_text( array $payload, string $output_language = 'es' ): string {
        return sprintf(
            "Asset type: %s\nTarget platform: %s\nAnalysis mode: %s\nFrame count: %d\nGoal: %s\nContext: %s\nSource URL: %s\nKnown limitations: %s\nOutput language: %s",
            sanitize_text_field( (string) ( $payload['asset_type'] ?? 'unknown' ) ),
            sanitize_text_field( (string) ( $payload['target_platform'] ?? 'generic' ) ),
            sanitize_text_field( (string) ( $payload['analysis_mode'] ?? 'standard' ) ),
            absint( $payload['frame_count'] ?? 0 ),
            sanitize_textarea_field( (string) ( $payload['goal'] ?? '' ) ),
            sanitize_textarea_field( (string) ( $payload['context'] ?? '' ) ),
            esc_url_raw( (string) ( $payload['source_url'] ?? '' ) ),
            sanitize_textarea_field( (string) ( $payload['known_limitations'] ?? 'Visual analysis depends on uploaded image/video frames and extracted metadata.' ) ),
            sanitize_key( $output_language )
        );
    }

    private function language_directive( string $output_language ): string {
        $language_names = array(
            'es' => 'Spanish',
            'en' => 'English',
            'fr' => 'French',
            'pt' => 'Portuguese',
            'de' => 'German',
            'it' => 'Italian',
        );
        $name = $language_names[ $output_language ] ?? '';
        if ( '' === $name ) {
            return '';
        }
        return ' Respond entirely in ' . $name . '. All text fields in the JSON output must be written in ' . $name . '.';
    }

    private function extract_output_text( array $decoded ): string {
        if ( isset( $decoded['output_text'] ) && is_string( $decoded['output_text'] ) ) {
            return $decoded['output_text'];
        }

        if ( empty( $decoded['output'] ) || ! is_array( $decoded['output'] ) ) {
            return '';
        }

        foreach ( $decoded['output'] as $output_item ) {
            if ( empty( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
                continue;
            }
            foreach ( $output_item['content'] as $content_item ) {
                if ( isset( $content_item['text'] ) && is_string( $content_item['text'] ) ) {
                    return $content_item['text'];
                }
            }
        }

        return '';
    }
}
