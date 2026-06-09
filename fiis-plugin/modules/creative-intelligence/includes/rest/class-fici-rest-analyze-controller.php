<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_REST_Analyze_Controller {
    private FICI_Analysis_Service $analysis_service;
    private FICI_Settings $settings;
    private FICI_Permissions $permissions;
    private FICI_Rate_Limiter $rate_limiter;

    public function __construct( FICI_Analysis_Service $analysis_service, FICI_Settings $settings, FICI_Permissions $permissions, FICI_Rate_Limiter $rate_limiter ) {
        $this->analysis_service = $analysis_service;
        $this->settings         = $settings;
        $this->permissions      = $permissions;
        $this->rate_limiter     = $rate_limiter;
    }

    public function register_routes(): void {
        register_rest_route(
            FICI_REST_NAMESPACE,
            '/workspace',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_workspace' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/start',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'start_analysis' ),
                'permission_callback' => array( $this, 'permissions_check' ),
                'args'                => $this->analysis_args(),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/recent',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_recent' ),
                'permission_callback' => array( $this, 'permissions_check' ),
                'args'                => array(
                    'limit' => array(
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/(?P<id>\d+)/status',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_status' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/(?P<id>\d+)/result',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_result' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/(?P<id>\d+)/write-memory',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'write_memory' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/(?P<id>\d+)/create-insight',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_insight' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/(?P<id>\d+)/create-brief',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_brief' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );

        register_rest_route(
            FICI_REST_NAMESPACE,
            '/analysis/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_analysis' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );
    }

    public function permissions_check( WP_REST_Request $request ) {
        if ( defined( 'VES_PRODUCTION_MVP' ) && VES_PRODUCTION_MVP ) {
            return $this->access_error( 'fici_mvp_module_disabled', 'Este módulo no está habilitado en el MVP de producción.', 'creative_intelligence', 'modules' );
        }

        if ( ! $this->permissions->can_use_addon() ) {
            return $this->access_error( 'fici_permission_denied', __( 'No tienes permiso para usar Creative Intelligence.', FICI_TEXT_DOMAIN ) );
        }

        $user_id = get_current_user_id();
        if ( class_exists( 'VES_Access_Control' ) && ! VES_Access_Control::user_can_access_module( $user_id, 'creative_intelligence' ) ) {
            return $this->access_error( 'fici_creative_module_locked', __( 'Creative Intelligence no está disponible en tu plan.', FICI_TEXT_DOMAIN ), 'creative_intelligence', 'modules' );
        }

        foreach ( $this->required_features_for_request( $request ) as $feature_key ) {
            if ( class_exists( 'VES_Access_Control' ) && ! VES_Access_Control::user_can_access_feature( $user_id, $feature_key ) ) {
                return $this->access_error( 'fici_feature_locked', __( 'Esta función de Creative Intelligence no está disponible en tu plan.', FICI_TEXT_DOMAIN ), $feature_key, 'features' );
            }
        }

        return true;
    }

    private function required_features_for_request( WP_REST_Request $request ): array {
        $route = (string) $request->get_route();
        if ( strpos( $route, '/analysis/start' ) !== false ) {
            return array( 'image_video_analysis' );
        }
        if ( strpos( $route, '/write-memory' ) !== false ) {
            return array( 'workspace_memory' );
        }
        if ( strpos( $route, '/create-insight' ) !== false ) {
            return array( 'creative_generation' );
        }
        if ( strpos( $route, '/create-brief' ) !== false ) {
            return array( 'creative_generation', 'brief_generation' );
        }
        return array();
    }

    private function access_error( string $code, string $message, string $key = 'creative_intelligence', string $section = 'modules' ): WP_Error {
        return new WP_Error(
            $code,
            $message,
            array(
                'status'                    => 403,
                'charge'                    => 0,
                'credits_refunded'          => true,
                'gated'                     => true,
                'mvp_disabled'              => 'fici_mvp_module_disabled' === $code,
                'run_id'                    => null,
                'provider_call_attempted'   => false,
                'provider_run_created'      => false,
                'credits_reserved'          => false,
                'credit_settlement_attempted' => false,
                'section'                   => $section,
                'key'                       => $key,
            )
        );
    }

    public function get_workspace( WP_REST_Request $request ) {
        return new WP_REST_Response( $this->analysis_service->get_workspace_context(), 200 );
    }

    public function start_analysis( WP_REST_Request $request ) {
        $rate = $this->rate_limiter->hit( get_current_user_id(), 'analysis_start' );
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }

        $result = $this->analysis_service->start_analysis( $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( $result, 200 );
    }

    public function list_recent( WP_REST_Request $request ) {
        $limit  = max( 1, min( 30, absint( $request->get_param( 'limit' ) ?: 12 ) ) );
        $result = $this->analysis_service->list_recent( $limit );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( array( 'items' => $result ), 200 );
    }

    public function get_status( WP_REST_Request $request ) {
        $result = $this->analysis_service->get_status( absint( $request['id'] ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result, 200 );
    }

    public function get_result( WP_REST_Request $request ) {
        $result = $this->analysis_service->get_result( absint( $request['id'] ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result, 200 );
    }

    public function write_memory( WP_REST_Request $request ) {
        return $this->action_response( $this->analysis_service->write_memory_from_run( absint( $request['id'] ) ) );
    }

    public function create_insight( WP_REST_Request $request ) {
        return $this->action_response( $this->analysis_service->create_insight_from_run( absint( $request['id'] ) ) );
    }

    public function create_brief( WP_REST_Request $request ) {
        return $this->action_response( $this->analysis_service->create_brief_from_run( absint( $request['id'] ) ) );
    }

    public function delete_analysis( WP_REST_Request $request ) {
        $result = $this->analysis_service->delete_run( absint( $request['id'] ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result, 200 );
    }

    private function action_response( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result, 200 );
    }

    private function analysis_args(): array {
        return array(
            'workspace_id' => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'asset_url' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ),
            'goal' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'context' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'analysis_mode' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ),
            'target_platform' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ),
            'video_frames_json' => array(
                'required' => false,
                'type'     => 'string',
            ),
        );
    }
}
