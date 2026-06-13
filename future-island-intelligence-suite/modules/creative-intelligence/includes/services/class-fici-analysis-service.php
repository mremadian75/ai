<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Analysis_Service {
    private FICI_Settings $settings;
    private FICI_Core_Bridge $core_bridge;
    private FICI_AI_Provider_Registry $provider_registry;
    private FICI_File_Service $file_service;
    private FICI_Asset_Service $asset_service;
    private FICI_Result_Normalizer $normalizer;

    public function __construct(
        FICI_Settings $settings,
        FICI_Core_Bridge $core_bridge,
        FICI_AI_Provider_Registry $provider_registry,
        FICI_File_Service $file_service,
        FICI_Asset_Service $asset_service,
        FICI_Result_Normalizer $normalizer
    ) {
        $this->settings          = $settings;
        $this->core_bridge       = $core_bridge;
        $this->provider_registry = $provider_registry;
        $this->file_service      = $file_service;
        $this->asset_service     = $asset_service;
        $this->normalizer        = $normalizer;
    }

    public function get_workspace_context(): array {
        return $this->core_bridge->get_workspace_context();
    }

    public function start_analysis( WP_REST_Request $request ) {
        $user_id         = get_current_user_id();
        $workspace_id    = absint( $request->get_param( 'workspace_id' ) ?: $this->core_bridge->get_current_workspace_id() );
        $analysis_mode   = $this->sanitize_analysis_mode( (string) ( $request->get_param( 'analysis_mode' ) ?: 'standard' ) );
        $target_platform = sanitize_key( (string) ( $request->get_param( 'target_platform' ) ?: 'generic' ) );
        $goal            = sanitize_textarea_field( (string) ( $request->get_param( 'goal' ) ?: '' ) );
        $context         = sanitize_textarea_field( (string) ( $request->get_param( 'context' ) ?: '' ) );
        $source_url      = esc_url_raw( (string) ( $request->get_param( 'asset_url' ) ?: '' ) );
        $settings        = $this->settings->get_settings();
        $video_frames    = $this->parse_video_frames_json( $request->get_param( 'video_frames_json' ) );

        if ( is_wp_error( $video_frames ) ) {
            return $video_frames;
        }

        $upload = $this->file_service->handle_upload( 'asset_file' );
        if ( is_wp_error( $upload ) ) {
            return $upload;
        }

        if ( null === $upload && '' === $source_url && '' === $context ) {
            return new WP_Error( 'fici_missing_input', __( 'Upload an asset, provide an asset URL, or paste context text.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        $asset_type = $upload['asset_type'] ?? 'text';
        if ( '' !== $source_url && null === $upload ) {
            $asset_type = 'url';
        }

        $estimated_cost = $this->estimate_cost( $asset_type, $analysis_mode, $settings );
        $reservation_id = $this->core_bridge->reserve_credits(
            $user_id,
            $workspace_id,
            'creative_asset_analysis',
            $estimated_cost,
            array(
                'asset_type'      => $asset_type,
                'analysis_mode'   => $analysis_mode,
                'target_platform' => $target_platform,
                'frame_count'     => is_array( $video_frames ) ? count( $video_frames ) : 0,
            )
        );

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( (int) $settings['retention_hours'] * HOUR_IN_SECONDS ) );
        $asset_data = array(
            'workspace_id' => $workspace_id,
            'user_id'      => $user_id,
            'asset_type'   => $asset_type,
            'source_type'  => null !== $upload ? 'upload' : ( '' !== $source_url ? 'url' : 'manual' ),
            'source_url'   => $source_url,
            'expires_at'   => $expires_at,
            'metadata'     => array(
                'goal'              => $goal,
                'context'           => $context,
                'analysis_mode'     => $analysis_mode,
                'target_platform'   => $target_platform,
                'estimated_credits' => $estimated_cost,
                'frame_count'       => is_array( $video_frames ) ? count( $video_frames ) : 0,
            ),
        );

        if ( null !== $upload ) {
            $asset_data = array_merge( $asset_data, $upload );
        }

        $asset_id = $this->asset_service->create_asset( $asset_data );
        if ( is_wp_error( $asset_id ) ) {
            $this->core_bridge->refund_usage( $reservation_id, 'asset_insert_failed' );
            return $asset_id;
        }

        $core_run_id = $this->core_bridge->create_run(
            array(
                'workspace_id'  => $workspace_id,
                'user_id'       => $user_id,
                'run_type'      => 'asset_analysis',
                'trigger_type'  => 'manual',
                'asset_id'      => $asset_id,
                'analysis_mode' => $analysis_mode,
                'input_context' => array( 'goal' => $goal, 'context' => $context ),
            )
        );

        $run_id = $this->insert_run(
            array(
                'core_run_id'           => $core_run_id,
                'workspace_id'          => $workspace_id,
                'user_id'               => $user_id,
                'asset_id'              => $asset_id,
                'status'                => 'processing',
                'analysis_mode'         => $analysis_mode,
                'target_platform'       => $target_platform,
                'provider'              => 'openai',
                'model'                 => $settings['openai_model'],
                'credit_reservation_id' => $reservation_id,
                'input_json'            => wp_json_encode(
                    array(
                        'goal'              => $goal,
                        'context'           => $context,
                        'source_url'        => $source_url,
                        'asset_type'        => $asset_type,
                        'target_platform'   => $target_platform,
                        'analysis_mode'     => $analysis_mode,
                        'estimated_credits' => $estimated_cost,
                        'frame_count'       => is_array( $video_frames ) ? count( $video_frames ) : 0,
                    )
                ),
                'core_links_json'       => wp_json_encode( array() ),
            )
        );

        if ( is_wp_error( $run_id ) ) {
            $this->core_bridge->refund_usage( $reservation_id, 'run_insert_failed' );
            return $run_id;
        }

        $provider = $this->provider_registry->get_provider( 'openai' );
        if ( is_wp_error( $provider ) ) {
            $this->mark_failed( $run_id, $provider, $reservation_id, $core_run_id );
            return $provider;
        }

        $image_data_urls = array();
        if ( isset( $upload['file_path'], $upload['mime_type'] ) && 'image' === $asset_type ) {
            $image_data_url = $this->file_service->image_to_data_url( $upload['file_path'], $upload['mime_type'] );
            if ( is_wp_error( $image_data_url ) ) {
                $this->mark_failed( $run_id, $image_data_url, $reservation_id, $core_run_id );
                return $image_data_url;
            }
            $image_data_urls[] = $image_data_url;
        }

        if ( 'video' === $asset_type ) {
            if ( empty( $video_frames ) ) {
                $video_error = new WP_Error(
                    'fici_video_frames_missing',
                    __( 'Video was uploaded, but no key frames were received. Your browser may not support frame extraction for this file. Try MP4/WebM, use a shorter video, or upload screenshots.', FICI_TEXT_DOMAIN ),
                    array( 'status' => 400, 'run_id' => $run_id, 'asset_id' => $asset_id )
                );
                $this->mark_failed( $run_id, $video_error, $reservation_id, $core_run_id );
                return $video_error;
            }
            $image_data_urls = $video_frames;
        }

        // Phase 1 A2/A3: bind request context for usage attribution + advisory preflight.
        // The provider call and its post-processing run inside try/finally so the
        // ambient context can never leak on an exception path (Fix 4).
        $has_tracker = class_exists( 'VES_AI_Usage_Tracker' );
        if ( $has_tracker ) {
            VES_AI_Usage_Tracker::set_context( array(
                'module' => 'creative_intelligence', 'operation_type' => 'creative_generation',
                'workspace_id' => (int) $workspace_id, 'user_id' => (int) $user_id, 'run_id' => (string) $run_id,
            ) );
            if ( method_exists( 'VES_AI_Usage_Tracker', 'preflight' ) ) {
                try {
                    VES_AI_Usage_Tracker::preflight( array(
                        'module' => 'creative_intelligence', 'operation_type' => 'creative_generation',
                        'workspace_id' => (int) $workspace_id, 'user_id' => (int) $user_id, 'run_id' => (string) $run_id,
                        'model' => (string) ( $settings['openai_model'] ?? 'gpt-4.1-mini' ),
                        'input_text' => (string) $goal . ' ' . (string) $context, 'max_output_tokens' => (int) ( $settings['max_output_tokens'] ?? 2000 ),
                        'record_estimate' => true, // Fix 2: persist the estimate as a usage row.
                    ) );
                } catch ( \Throwable $e ) { /* advisory only */ }
            }
        }

        try {
            $result = $provider->analyze_asset(
                array(
                    'asset_type'      => $asset_type,
                    'image_data_urls' => $image_data_urls,
                    'source_url'      => $source_url,
                    'goal'            => $goal,
                    'context'         => $context,
                    'analysis_mode'   => $analysis_mode,
                    'target_platform' => $target_platform,
                    'frame_count'     => count( $image_data_urls ),
                    'user_id'         => (int) $user_id,
                    'workspace_id'    => (int) $workspace_id,
                )
            );

            if ( is_wp_error( $result ) ) {
                $this->mark_failed( $run_id, $result, $reservation_id, $core_run_id );
                return $result;
            }

            $normalized = $this->normalizer->normalize( $result );
            $this->mark_completed( $run_id, $normalized, $reservation_id, $estimated_cost, $core_run_id );

            // Phase 2 (B6): additively project the creative analysis into the canonical
            // intelligence spine (Source = asset, Insight + Draft = analysis). Isolated;
            // never changes the response shape.
            $this->write_intelligence_records( (int) $workspace_id, (int) $user_id, (int) $run_id, $asset_type, $source_url, $target_platform, (string) ( $settings['openai_model'] ?? '' ), $normalized );

            if ( ! empty( $settings['enable_memory_write'] ) ) {
                $this->sync_memory_record( $run_id, $workspace_id, $normalized );
            }

            $links = $this->get_core_links( $run_id );

            return array(
                'run_id'            => $run_id,
                'core_run_id'       => $core_run_id,
                'asset_id'          => $asset_id,
                'status'            => 'completed',
                'estimated_credits' => $estimated_cost,
                'credits_used'      => $estimated_cost,
                'core_links'        => $links,
                'workspace'         => $this->core_bridge->get_workspace_context(),
                'result'            => $normalized,
            );
        } finally {
            if ( $has_tracker ) { VES_AI_Usage_Tracker::clear_context(); }
        }
    }

    /**
     * Phase 2 (B6) — project a completed creative analysis into the canonical
     * intelligence spine. Additive + isolated; never throws into the caller.
     */
    private function write_intelligence_records( int $workspace_id, int $user_id, int $run_id, string $asset_type, string $source_url, string $target_platform, string $model, $normalized ): void {
        if ( ! class_exists( 'VES_Intelligence_Store' ) || $workspace_id <= 0 ) { return; }
        try {
            $summary = '';
            if ( is_array( $normalized ) ) {
                foreach ( array( 'summary', 'executive_summary', 'overview', 'analysis' ) as $k ) {
                    if ( ! empty( $normalized[ $k ] ) && is_string( $normalized[ $k ] ) ) { $summary = (string) $normalized[ $k ]; break; }
                }
                if ( $summary === '' ) { $summary = (string) wp_json_encode( array_slice( (array) $normalized, 0, 6 ) ); }
            }
            $summary = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $summary ) : strip_tags( $summary );
            $summary = substr( $summary, 0, 4000 );

            // Phase 3B Part B: backlink to the OpenAI usage event from THIS analysis.
            $usage_event_id = ( class_exists( 'VES_AI_Usage_Tracker' ) && method_exists( 'VES_AI_Usage_Tracker', 'last_event_id' ) )
                ? (int) VES_AI_Usage_Tracker::last_event_id( 'openai' ) : 0;

            $bundle = array(
                'workspace_id' => $workspace_id,
                'user_id'      => $user_id,
                'usage_event_id' => $usage_event_id,
                'source'       => array(
                    'source_type'  => $source_url !== '' ? 'web' : 'file',
                    'provider'     => 'manual',
                    'source_url'   => $source_url,
                    'source_title' => 'Creative asset: ' . substr( $asset_type, 0, 60 ),
                    // Phase 3 provenance: retrieval method + dedup hashing (auto in normalize_source).
                    'retrieval_method' => 'creative_upload',
                    'metadata'     => array( 'run_id' => $run_id, 'target_platform' => $target_platform, 'extractor_model' => $model ),
                ),
                'insight'      => array(
                    'insight_type'     => 'content',
                    'title'            => 'Creative analysis: ' . substr( $target_platform !== '' ? $target_platform : $asset_type, 0, 80 ),
                    'summary'          => $summary,
                    'status'           => 'draft',
                ),
            );
            if ( $summary !== '' ) {
                $bundle['draft'] = array( 'draft_type' => 'report_section', 'body' => $summary, 'channel' => $target_platform, 'model' => $model );
            }
            VES_Intelligence_Store::ingest_bundle( $bundle );
            if ( class_exists( 'VES_Log' ) ) {
                VES_Log::info( 'creative_intelligence', 'intelligence_records_written', array( 'run_id' => $run_id, 'workspace_id' => $workspace_id, 'operation_type' => 'creative_generation' ) );
            }
        } catch ( \Throwable $e ) {
            if ( class_exists( 'VES_Log' ) ) { VES_Log::warn( 'creative_intelligence', 'intelligence_records_failed', array( 'run_id' => $run_id, 'error_code' => 'intel_write_failed' ) ); }
        }
    }

    public function list_recent( int $limit = 12 ) {
        global $wpdb;

        $runs_table   = $wpdb->prefix . 'fici_analysis_runs';
        $assets_table = $wpdb->prefix . 'fici_assets';
        $user_id      = get_current_user_id();
        $limit        = max( 1, min( 30, $limit ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.id, r.core_run_id, r.asset_id, r.workspace_id, r.status, r.analysis_mode, r.target_platform, r.provider, r.model, r.input_json, r.result_json, r.usage_json, r.core_links_json, r.error_code, r.error_message, r.created_at, r.completed_at, a.asset_type, a.source_type, a.file_url, a.source_url, a.mime_type FROM {$runs_table} r LEFT JOIN {$assets_table} a ON a.id = r.asset_id WHERE r.user_id = %d ORDER BY r.created_at DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_map( array( $this, 'shape_history_row' ), $rows );
    }

    public function get_status( int $run_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'fici_analysis_runs';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, status, error_code, error_message, created_at, updated_at, completed_at FROM {$table} WHERE id = %d", $run_id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'fici_run_not_found', __( 'Analysis run not found.', FICI_TEXT_DOMAIN ), array( 'status' => 404 ) );
        }
        if ( ! $this->current_user_can_access_row( $row ) ) {
            return new WP_Error( 'fici_run_access_denied', __( 'You cannot access this analysis run.', FICI_TEXT_DOMAIN ), array( 'status' => 403 ) );
        }
        unset( $row['user_id'] );
        return $row;
    }

    public function get_result( int $run_id ) {
        $row = $this->get_full_run_row( $run_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $shaped = $this->shape_history_row( $row );
        if ( 'completed' === $row['status'] ) {
            $shaped['result'] = json_decode( (string) $row['result_json'], true );
            if ( ! is_array( $shaped['result'] ) ) {
                $shaped['result'] = array();
            }
        }
        $shaped['workspace'] = $this->core_bridge->get_workspace_context();
        return $shaped;
    }

    public function delete_run( int $run_id ) {
        global $wpdb;
        $runs_table   = $wpdb->prefix . 'fici_analysis_runs';
        $assets_table = $wpdb->prefix . 'fici_assets';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT r.id, r.user_id, r.asset_id, a.file_path FROM {$runs_table} r LEFT JOIN {$assets_table} a ON a.id = r.asset_id WHERE r.id = %d", $run_id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'fici_run_not_found', __( 'Analysis run not found.', FICI_TEXT_DOMAIN ), array( 'status' => 404 ) );
        }
        if ( ! $this->current_user_can_access_row( $row ) ) {
            return new WP_Error( 'fici_run_access_denied', __( 'You cannot delete this analysis run.', FICI_TEXT_DOMAIN ), array( 'status' => 403 ) );
        }

        if ( ! empty( $row['file_path'] ) ) {
            $this->delete_uploaded_file_safely( (string) $row['file_path'] );
        }

        $wpdb->delete( $runs_table, array( 'id' => $run_id ), array( '%d' ) );
        if ( ! empty( $row['asset_id'] ) ) {
            $wpdb->delete( $assets_table, array( 'id' => absint( $row['asset_id'] ) ), array( '%d' ) );
        }

        return array( 'deleted' => true, 'id' => $run_id );
    }

    public function write_memory_from_run( int $run_id ) {
        $row = $this->get_completed_run_row( $run_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $result = $this->decoded_result_from_row( $row );
        $memory = $this->sync_memory_record( $run_id, absint( $row['workspace_id'] ), $result );
        return $this->action_response( $run_id, 'memory', $memory );
    }

    public function create_insight_from_run( int $run_id ) {
        $row = $this->get_completed_run_row( $run_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $links = $this->get_core_links( $run_id );
        if ( ! empty( $links['insight']['created'] ) ) {
            return $this->action_response( $run_id, 'insight', $links['insight'] );
        }

        $result  = $this->decoded_result_from_row( $row );
        $core_id = $this->core_bridge->create_insight_from_result( absint( $row['workspace_id'] ), $run_id, $result );
        $link    = $this->record_core_link( $run_id, 'insight', $core_id, array( 'summary' => $result['creative_summary'] ?? '' ) );

        return $this->action_response( $run_id, 'insight', $link );
    }

    public function create_brief_from_run( int $run_id ) {
        $row = $this->get_completed_run_row( $run_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $links = $this->get_core_links( $run_id );
        if ( ! empty( $links['brief']['created'] ) ) {
            return $this->action_response( $run_id, 'brief', $links['brief'] );
        }

        $result = $this->decoded_result_from_row( $row );
        if ( empty( $result['suggested_brief'] ) || ! is_array( $result['suggested_brief'] ) ) {
            return new WP_Error( 'fici_no_suggested_brief', __( 'This analysis does not contain a suggested brief.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        $core_id = $this->core_bridge->create_brief_from_result( absint( $row['workspace_id'] ), $run_id, $result );
        $link    = $this->record_core_link( $run_id, 'brief', $core_id, array( 'brief' => $result['suggested_brief'] ) );

        return $this->action_response( $run_id, 'brief', $link );
    }

    private function sync_memory_record( int $run_id, int $workspace_id, array $result ): array {
        $links = $this->get_core_links( $run_id );
        if ( ! empty( $links['memory']['created'] ) ) {
            return $links['memory'];
        }

        $core_id = $this->core_bridge->create_memory_record(
            $workspace_id,
            array(
                'type'       => 'creative_analysis',
                'summary'    => $result['creative_summary'] ?? '',
                'result'     => $result,
                'created_at' => current_time( 'mysql', true ),
            )
        );

        return $this->record_core_link( $run_id, 'memory', $core_id, array( 'summary' => $result['creative_summary'] ?? '' ) );
    }

    private function action_response( int $run_id, string $type, array $link ): array {
        return array(
            'success'    => true,
            'run_id'     => $run_id,
            'type'       => $type,
            'link'       => $link,
            'core_links' => $this->get_core_links( $run_id ),
        );
    }

    private function get_completed_run_row( int $run_id ) {
        $row = $this->get_full_run_row( $run_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( 'completed' !== (string) $row['status'] ) {
            return new WP_Error( 'fici_run_not_completed', __( 'Only completed analyses can be converted into memory, insights, or briefs.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }
        return $row;
    }

    private function get_full_run_row( int $run_id ) {
        global $wpdb;
        $runs_table   = $wpdb->prefix . 'fici_analysis_runs';
        $assets_table = $wpdb->prefix . 'fici_assets';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT r.*, a.asset_type, a.source_type, a.file_url, a.source_url, a.mime_type, a.file_path FROM {$runs_table} r LEFT JOIN {$assets_table} a ON a.id = r.asset_id WHERE r.id = %d", $run_id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'fici_run_not_found', __( 'Analysis run not found.', FICI_TEXT_DOMAIN ), array( 'status' => 404 ) );
        }
        if ( ! $this->current_user_can_access_row( $row ) ) {
            return new WP_Error( 'fici_run_access_denied', __( 'You cannot access this analysis run.', FICI_TEXT_DOMAIN ), array( 'status' => 403 ) );
        }
        return $row;
    }

    private function decoded_result_from_row( array $row ): array {
        $result = json_decode( (string) ( $row['result_json'] ?? '' ), true );
        return is_array( $result ) ? $result : array();
    }

    private function get_core_links( int $run_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'fici_analysis_runs';
        $raw   = $wpdb->get_var( $wpdb->prepare( "SELECT core_links_json FROM {$table} WHERE id = %d", $run_id ) );
        $links = json_decode( (string) $raw, true );
        return is_array( $links ) ? $links : array();
    }

    private function record_core_link( int $run_id, string $type, int $core_id, array $payload = array() ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'fici_analysis_runs';
        $links = $this->get_core_links( $run_id );

        $link = array(
            'created'    => true,
            'core_id'    => $core_id,
            'local_only' => 0 === $core_id,
            'created_at' => current_time( 'mysql', true ),
            'payload'    => $payload,
        );

        $links[ sanitize_key( $type ) ] = $link;
        $wpdb->update(
            $table,
            array(
                'core_links_json' => wp_json_encode( $links ),
                'updated_at'      => current_time( 'mysql', true ),
            ),
            array( 'id' => $run_id )
        );

        return $link;
    }

    private function shape_history_row( array $row ): array {
        $input  = json_decode( (string) ( $row['input_json'] ?? '' ), true );
        $result = json_decode( (string) ( $row['result_json'] ?? '' ), true );
        $usage  = json_decode( (string) ( $row['usage_json'] ?? '' ), true );
        $links  = json_decode( (string) ( $row['core_links_json'] ?? '' ), true );

        $input  = is_array( $input ) ? $input : array();
        $result = is_array( $result ) ? $result : array();
        $usage  = is_array( $usage ) ? $usage : array();
        $links  = is_array( $links ) ? $links : array();

        return array(
            'id'                => absint( $row['id'] ?? 0 ),
            'core_run_id'       => absint( $row['core_run_id'] ?? 0 ),
            'workspace_id'      => absint( $row['workspace_id'] ?? 0 ),
            'asset_id'          => absint( $row['asset_id'] ?? 0 ),
            'asset_type'        => sanitize_key( (string) ( $row['asset_type'] ?? $input['asset_type'] ?? 'unknown' ) ),
            'source_type'       => sanitize_key( (string) ( $row['source_type'] ?? 'unknown' ) ),
            'file_url'          => esc_url_raw( (string) ( $row['file_url'] ?? '' ) ),
            'source_url'        => esc_url_raw( (string) ( $row['source_url'] ?? '' ) ),
            'status'            => sanitize_key( (string) ( $row['status'] ?? 'unknown' ) ),
            'analysis_mode'     => sanitize_key( (string) ( $row['analysis_mode'] ?? '' ) ),
            'target_platform'   => sanitize_key( (string) ( $row['target_platform'] ?? '' ) ),
            'summary'           => sanitize_textarea_field( (string) ( $result['creative_summary'] ?? '' ) ),
            'hook_score'        => absint( $result['hook_analysis']['score'] ?? 0 ),
            'brand_score'       => absint( $result['brand_presence']['score'] ?? 0 ),
            'confidence'        => sanitize_key( (string) ( $result['confidence'] ?? '' ) ),
            'credits_used'      => absint( $usage['credits_used'] ?? $input['estimated_credits'] ?? 0 ),
            'core_links'        => $links,
            'has_memory'        => ! empty( $links['memory']['created'] ),
            'has_insight'       => ! empty( $links['insight']['created'] ),
            'has_brief'         => ! empty( $links['brief']['created'] ),
            'error_code'        => sanitize_key( (string) ( $row['error_code'] ?? '' ) ),
            'error_message'     => sanitize_text_field( (string) ( $row['error_message'] ?? '' ) ),
            'created_at'        => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
            'completed_at'      => sanitize_text_field( (string) ( $row['completed_at'] ?? '' ) ),
        );
    }

    private function parse_video_frames_json( $raw ) {
        if ( null === $raw || '' === $raw ) {
            return array();
        }

        if ( ! is_string( $raw ) ) {
            return new WP_Error( 'fici_invalid_video_frames', __( 'Invalid video frame payload.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        $raw = wp_unslash( $raw );
        if ( strlen( $raw ) > 9000000 ) {
            return new WP_Error( 'fici_video_frames_too_large', __( 'Video frame payload is too large.', FICI_TEXT_DOMAIN ), array( 'status' => 413 ) );
        }

        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return new WP_Error( 'fici_invalid_video_frames_json', __( 'Video frame payload is not valid JSON.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        if ( count( $decoded ) > 12 ) {
            return new WP_Error( 'fici_too_many_video_frames', __( 'Too many video frames were submitted.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        $frames = array();
        foreach ( $decoded as $frame ) {
            if ( ! is_string( $frame ) ) {
                continue;
            }
            $frame = trim( $frame );
            if ( strlen( $frame ) > 2200000 ) {
                continue;
            }
            if ( preg_match( '#^data:image/(jpeg|jpg|png|webp);base64,[A-Za-z0-9+/=]+$#', $frame ) ) {
                $frames[] = $frame;
            }
            if ( count( $frames ) >= 4 ) {
                break;
            }
        }

        if ( empty( $frames ) && ! empty( $decoded ) ) {
            return new WP_Error( 'fici_no_valid_video_frames', __( 'No valid image frames were found in the video frame payload.', FICI_TEXT_DOMAIN ), array( 'status' => 400 ) );
        }

        return $frames;
    }

    private function insert_run( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'fici_analysis_runs';
        $now = current_time( 'mysql', true );
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $wpdb->insert( $table, $data );
        if ( false === $inserted ) {
            return new WP_Error( 'fici_run_insert_failed', __( 'Could not save analysis run.', FICI_TEXT_DOMAIN ), array( 'status' => 500 ) );
        }
        return (int) $wpdb->insert_id;
    }

    private function mark_failed( int $run_id, WP_Error $error, string $reservation_id, int $core_run_id = 0 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fici_analysis_runs';
        $wpdb->update(
            $table,
            array(
                'status'        => 'failed',
                'error_code'    => $error->get_error_code(),
                'error_message' => $error->get_error_message(),
                'updated_at'    => current_time( 'mysql', true ),
                'completed_at'  => current_time( 'mysql', true ),
            ),
            array( 'id' => $run_id )
        );
        $this->core_bridge->refund_usage( $reservation_id, $error->get_error_code() );
        if ( $core_run_id > 0 ) {
            $this->core_bridge->update_run_status( $core_run_id, 'failed', array( 'error_code' => $error->get_error_code(), 'message' => $error->get_error_message() ) );
        }
    }

    private function mark_completed( int $run_id, array $result, string $reservation_id, int $credits_used, int $core_run_id = 0 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'fici_analysis_runs';
        $wpdb->update(
            $table,
            array(
                'status'       => 'completed',
                'result_json'  => wp_json_encode( $result ),
                'usage_json'   => wp_json_encode( array( 'credits_used' => $credits_used ) ),
                'updated_at'   => current_time( 'mysql', true ),
                'completed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $run_id )
        );
        $this->core_bridge->finalize_usage( $reservation_id, array( 'credits_used' => $credits_used, 'run_id' => $run_id ) );
        if ( $core_run_id > 0 ) {
            $this->core_bridge->update_run_status( $core_run_id, 'completed', array( 'credits_used' => $credits_used, 'local_run_id' => $run_id ) );
        }
    }

    private function estimate_cost( string $asset_type, string $analysis_mode, array $settings ): int {
        $asset_type = sanitize_key( $asset_type );
        $base       = 'video' === $asset_type ? absint( $settings['cost_video_standard'] ?? 20 ) : absint( $settings['cost_image_standard'] ?? 6 );

        if ( 'text' === $asset_type ) {
            $base = max( 1, (int) ceil( $base / 2 ) );
        }

        $multipliers = array(
            'quick'    => 0.65,
            'standard' => 1,
            'deep'     => 1.75,
        );

        $multiplier = $multipliers[ $analysis_mode ] ?? 1;
        return max( 1, (int) ceil( $base * $multiplier ) );
    }

    private function sanitize_analysis_mode( string $mode ): string {
        $mode = sanitize_key( $mode );
        return in_array( $mode, array( 'quick', 'standard', 'deep' ), true ) ? $mode : 'standard';
    }

    private function current_user_can_access_row( array $row ): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        return isset( $row['user_id'] ) && absint( $row['user_id'] ) === get_current_user_id();
    }

    private function delete_uploaded_file_safely( string $file_path ): void {
        $uploads = wp_upload_dir();
        $base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        $target  = wp_normalize_path( $file_path );

        if ( '' === $base || '' === $target || 0 !== strpos( $target, $base ) ) {
            return;
        }

        if ( file_exists( $target ) && is_file( $target ) ) {
            wp_delete_file( $target );
        }
    }
}
