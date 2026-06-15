<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Usage_Reconciliation_Service {
    public static function reserve_for_bundle($bundle_id, $user_id, $event_type, $context = []) {
        if ($user_id <= 0 || !class_exists('VES_Usage_Billing')) {
            return true;
        }
        $usage_key = 'trend_run:' . sanitize_text_field((string) $bundle_id);
        $context = array_merge((array) $context, [
            'usage_key' => $usage_key,
            'target_type' => 'trend_run',
            'target_id' => sanitize_text_field((string) $bundle_id),
        ]);

        // P3.1 live-QA hotfix: admin/unlimited users must not be blocked before
        // Trend Finder reaches provider dispatch just because the optional usage
        // event could not be written. The live failure was misreported as a busy
        // source, but it happened at create_run/reserve_usage before any source ran.
        if (method_exists('VES_Usage_Billing', 'is_unlimited_user') && VES_Usage_Billing::is_unlimited_user((int) $user_id)) {
            return [
                'charged' => 0.0,
                'balance_after' => null,
                'user_id' => (int) $user_id,
                'usage_key' => $usage_key,
                'status' => 'virtual_unlimited',
                'event_id' => 0,
                'unlimited' => true,
                'credits_reserved' => false,
                'virtual_reservation' => true,
            ];
        }

        return VES_Usage_Billing::reserve_usage((int) $user_id, $event_type, $usage_key, 'Reserva de ejecución', $context);
    }

    public static function finalize_for_bundle($bundle_id, $run_status, $message = '', $final_report = []) {
        if (!class_exists('VES_Usage_Billing')) {
            return;
        }
        $bundle_id = sanitize_text_field((string) $bundle_id);
        $usage_key = 'trend_run:' . $bundle_id;
        $run_status = sanitize_key((string) $run_status);
        $final_report = is_array($final_report) ? $final_report : [];
        if (empty($final_report) && function_exists('ves_trend_bundle_key')) {
            $bundle = get_transient(ves_trend_bundle_key($bundle_id));
            if (is_array($bundle) && is_array($bundle['final'] ?? null)) {
                $final_report = $bundle['final'];
            }
        }
        if (!in_array($run_status, ['completed', 'partial', 'fallback_completed', 'completed_low_confidence', 'needs_validation', 'completed_with_limitations'], true)) {
            VES_Usage_Billing::void_reserved_usage($usage_key, $message !== '' ? $message : 'Uso cancelado');
            return;
        }

        $reserved = method_exists('VES_Usage_Billing', 'get_cost') ? (float) VES_Usage_Billing::get_cost('trend_run') : 2.0;
        $usable_sources = (int) ($final_report['usable_sources'] ?? $final_report['successful_sources'] ?? 0);
        $failed_sources = (int) ($final_report['failed_sources'] ?? 0);
        $total_sources = (int) ($final_report['total_sources'] ?? max($usable_sources + $failed_sources, 0));
        $confidence_mode = sanitize_key((string) ($final_report['confidence_mode'] ?? ''));
        $report_status = sanitize_key((string) ($final_report['report_status'] ?? ''));
        $insights = is_array($final_report['brief_ready_insights'] ?? null) ? $final_report['brief_ready_insights'] : (is_array($final_report['insights'] ?? null) ? $final_report['insights'] : []);
        $useful_trends = 0;
        foreach ($insights as $insight) {
            if (!is_array($insight)) { continue; }
            $confidence = isset($insight['confidence_score']) ? (float) $insight['confidence_score'] : 0.0;
            if ($confidence_mode === 'validated' || $confidence >= 6.0) { $useful_trends++; }
        }
        if ($report_status === 'validation_report' || $confidence_mode !== 'validated') {
            $useful_trends = 0;
        }
        $provider_cost = is_array($final_report['provider_cost'] ?? null) ? $final_report['provider_cost'] : [];
        $provider_actual = (float) ($provider_cost['actual_usd'] ?? $provider_cost['actual_provider_cost_usd'] ?? $final_report['provider_actual_cost_usd'] ?? 0);
        $provider_estimated = (float) ($provider_cost['estimated_usd'] ?? $final_report['provider_estimated_cost_usd'] ?? 0);
        $base_fee = ($usable_sources > 0 || $provider_actual > 0 || $provider_estimated > 0) ? min($reserved, max(0.25, round($reserved * 0.25, 2))) : 0.0;
        if ($failed_sources > $usable_sources) {
            $base_fee = min($base_fee, round($reserved * 0.15, 2));
        }
        $per_trend = max(0.0, ($reserved - $base_fee) / 4.0);
        $final_cost = min($reserved, round($base_fee + ($useful_trends * $per_trend), 2));
        if ($usable_sources <= 0 && $useful_trends <= 0) { $final_cost = 0.0; }
        $context = [
            'module' => 'trend_finder',
            'provider' => 'apify',
            'run_id' => $bundle_id,
            'bundle_id' => $bundle_id,
            'requested_results' => (int) ($final_report['requested']['desired_final_results'] ?? 0),
            'raw_provider_rows' => (int) ($final_report['raw_provider_rows'] ?? $final_report['evidence_coverage_summary']['usable_records'] ?? 0),
            'normalized_rows' => (int) ($final_report['normalized_rows'] ?? $final_report['evidence_coverage_summary']['usable_records'] ?? 0),
            'displayed_rows' => max(0, $useful_trends),
            'useful_delivered_rows' => max(0, $useful_trends),
            'discarded_rows' => (int) ($final_report['evidence_coverage_summary']['discarded_or_noise_count'] ?? 0),
            'provider_estimated_cost_usd' => $provider_estimated,
            'provider_actual_cost_usd' => $provider_actual,
            'run_status' => $run_status,
            'failure_reason' => $report_status === 'validation_report' ? 'validation_report_low_confidence' : '',
            'usable_sources' => $usable_sources,
            'failed_sources' => $failed_sources,
            'total_sources' => $total_sources,
            'confidence_mode' => $confidence_mode,
            'report_status' => $report_status,
            'settlement_reason' => 'trend_useful_sources_and_validated_trends',
        ];
        if (method_exists('VES_Usage_Billing', 'settle_reserved_usage')) {
            VES_Usage_Billing::settle_reserved_usage($usage_key, $final_cost, $message !== '' ? $message : 'Trend Finder settled by useful delivered output', $context);
            return;
        }
        if ($final_cost > 0) { VES_Usage_Billing::post_reserved_usage($usage_key, $message !== '' ? $message : 'Uso confirmado'); }
        else { VES_Usage_Billing::void_reserved_usage($usage_key, $message !== '' ? $message : 'Uso cancelado'); }
    }

    public static function reconcile_pending() {
        if (!class_exists('VES_Usage_Billing')) {
            return 0;
        }
        global $wpdb;
        $table = VES_Usage_Billing::table_name();
        // Refund stale 'pending' reservations whose run never finalized (crash/timeout).
        // Runs time out in ~20 min, so the old 24h window left a user's credits stuck
        // for a day. Refund after 2h (filterable) — well past any legitimate run.
        $stale_seconds = (int) apply_filters('ves_stale_reservation_seconds', 2 * HOUR_IN_SECONDS);
        if ($stale_seconds < 1800) { $stale_seconds = 1800; }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $stale_seconds);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT usage_key FROM {$table} WHERE status = %s AND usage_key IS NOT NULL AND created_at < %s ORDER BY id ASC LIMIT 100",
            'pending',
            $cutoff
        ), ARRAY_A);
        $count = 0;
        foreach ((array) $rows as $row) {
            $usage_key = sanitize_text_field((string) ($row['usage_key'] ?? ''));
            if ($usage_key === '') {
                continue;
            }
            $voided = VES_Usage_Billing::void_reserved_usage($usage_key, 'Reserva pendiente expirada y reconciliada automáticamente.');
            if ($voided) {
                $count++;
            }
        }
        return $count;
    }
}
