<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Error_Mapper {
    public function from_http_response( int $status_code, array $decoded = array() ): WP_Error {
        if ( 401 === $status_code || 403 === $status_code ) {
            return new WP_Error( 'fici_invalid_api_key', __( 'AI provider authentication failed.', FICI_TEXT_DOMAIN ), array( 'status' => $status_code ) );
        }

        if ( 429 === $status_code ) {
            return new WP_Error( 'fici_rate_limited_upstream', __( 'AI provider rate limit reached.', FICI_TEXT_DOMAIN ), array( 'status' => 429 ) );
        }

        if ( $status_code >= 500 ) {
            return new WP_Error( 'fici_upstream_unavailable', __( 'AI provider is temporarily unavailable.', FICI_TEXT_DOMAIN ), array( 'status' => 502 ) );
        }

        $message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : __( 'AI provider request failed.', FICI_TEXT_DOMAIN );
        return new WP_Error( 'fici_upstream_error', $message, array( 'status' => $status_code ?: 500 ) );
    }
}
