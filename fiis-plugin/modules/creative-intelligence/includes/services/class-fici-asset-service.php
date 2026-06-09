<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Asset_Service {
    public function create_asset( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'fici_assets';
        $now   = current_time( 'mysql', true );

        $row = array(
            'workspace_id'     => absint( $data['workspace_id'] ?? 0 ),
            'user_id'          => absint( $data['user_id'] ?? get_current_user_id() ),
            'asset_type'       => sanitize_key( $data['asset_type'] ?? 'unknown' ),
            'source_type'      => sanitize_key( $data['source_type'] ?? 'upload' ),
            'file_path'        => isset( $data['file_path'] ) ? (string) $data['file_path'] : null,
            'file_url'         => isset( $data['file_url'] ) ? esc_url_raw( (string) $data['file_url'] ) : null,
            'source_url'       => isset( $data['source_url'] ) ? esc_url_raw( (string) $data['source_url'] ) : null,
            'mime_type'        => isset( $data['mime_type'] ) ? sanitize_text_field( (string) $data['mime_type'] ) : null,
            'file_size'        => absint( $data['file_size'] ?? 0 ),
            'duration_seconds' => isset( $data['duration_seconds'] ) ? absint( $data['duration_seconds'] ) : null,
            'metadata_json'    => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
            'status'           => sanitize_key( $data['status'] ?? 'uploaded' ),
            'expires_at'       => isset( $data['expires_at'] ) ? sanitize_text_field( (string) $data['expires_at'] ) : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        );

        $inserted = $wpdb->insert( $table, $row );
        if ( false === $inserted ) {
            return new WP_Error( 'fici_asset_insert_failed', __( 'Could not save asset record.', FICI_TEXT_DOMAIN ), array( 'status' => 500 ) );
        }

        return (int) $wpdb->insert_id;
    }
}
