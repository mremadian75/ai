<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Core_Bridge {
    public function is_core_available(): bool {
        return defined( 'FI_CORE_VERSION' ) || defined( 'AIAP_CORE_VERSION' ) || has_filter( 'fici_core_workspace_id' ) || has_filter( 'fici_core_workspace_context' );
    }

    public function get_current_workspace_id(): int {
        $workspace_id = (int) apply_filters( 'fici_core_workspace_id', 0, get_current_user_id() );
        return max( 0, $workspace_id );
    }

    public function get_workspace_context(): array {
        $default = array(
            'workspace_id'   => $this->get_current_workspace_id(),
            'workspace_name' => __( 'Standalone workspace', FICI_TEXT_DOMAIN ),
            'mode'           => $this->is_core_available() ? 'core_connected' : 'standalone',
            'core_available' => $this->is_core_available(),
            'credits_label'  => __( 'Local estimate only', FICI_TEXT_DOMAIN ),
        );

        $context = apply_filters( 'fici_core_workspace_context', $default, get_current_user_id() );
        if ( ! is_array( $context ) ) {
            return $default;
        }

        $context = wp_parse_args( $context, $default );
        return array(
            'workspace_id'   => absint( $context['workspace_id'] ),
            'workspace_name' => sanitize_text_field( (string) $context['workspace_name'] ),
            'mode'           => sanitize_key( (string) $context['mode'] ),
            'core_available' => ! empty( $context['core_available'] ),
            'credits_label'  => sanitize_text_field( (string) $context['credits_label'] ),
        );
    }

    public function current_user_can_use_addon(): bool {
        return (bool) apply_filters( 'fici_core_user_can_use_addon', current_user_can( 'read' ), get_current_user_id(), $this->get_current_workspace_id() );
    }

    public function reserve_credits( int $user_id, int $workspace_id, string $operation, int $estimated_cost, array $context = array() ): string {
        $estimated_cost = (int) apply_filters( 'fici_estimated_credit_cost', $estimated_cost, $operation, $context );

        $reservation = array(
            'id'             => 'fici_' . wp_generate_uuid4(),
            'user_id'        => $user_id,
            'workspace_id'   => $workspace_id,
            'operation'      => $operation,
            'estimated_cost' => $estimated_cost,
            'context'        => $context,
            'created_at'     => current_time( 'mysql', true ),
        );

        $reservation = apply_filters( 'fici_core_reserve_credits_payload', $reservation );
        do_action( 'fici_core_reserve_credits', $reservation );

        return sanitize_text_field( (string) ( $reservation['id'] ?? '' ) );
    }

    public function finalize_usage( string $reservation_id, array $actual_usage ): void {
        $actual_usage['finalized_at'] = current_time( 'mysql', true );
        do_action( 'fici_core_finalize_usage', $reservation_id, $actual_usage );
    }

    public function refund_usage( string $reservation_id, string $reason ): void {
        do_action( 'fici_core_refund_usage', $reservation_id, $reason, current_time( 'mysql', true ) );
    }

    public function create_run( array $data ): int {
        return max( 0, (int) apply_filters( 'fici_core_create_run', 0, $data ) );
    }

    public function update_run_status( int $core_run_id, string $status, array $meta = array() ): void {
        do_action( 'fici_core_update_run_status', $core_run_id, $status, $meta );
    }

    public function create_memory_record( int $workspace_id, array $summary ): int {
        return max( 0, (int) apply_filters( 'fici_core_create_memory_record', 0, $workspace_id, $summary ) );
    }

    public function create_insight_from_result( int $workspace_id, int $run_id, array $result ): int {
        return max( 0, (int) apply_filters( 'fici_core_create_insight_from_result', 0, $workspace_id, $run_id, $result ) );
    }

    public function create_brief_from_result( int $workspace_id, int $run_id, array $result ): int {
        return max( 0, (int) apply_filters( 'fici_core_create_brief_from_result', 0, $workspace_id, $run_id, $result ) );
    }
}
