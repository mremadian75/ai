<?php
if (!defined('ABSPATH')) { exit; }

/** Deterministic operator diagnostics for canonical Runs and provider trials. */
final class VES_Run_Diagnostics_Service {
    public static function diagnose(int $workspace_id, int $run_id): array {
        $logs = class_exists('VES_Run_Log_Service') ? VES_Run_Log_Service::list_for_run($workspace_id, $run_id) : [];
        $ingestions = class_exists('VES_Provider_Ingestion_Store') ? VES_Provider_Ingestion_Store::list(['workspace_id' => $workspace_id, 'run_id' => $run_id, 'limit' => 50]) : [];
        $run = class_exists('VES_Run_Service') ? VES_Run_Service::get_run($run_id) : [];
        $events = self::events($logs);
        $status = self::run_status($run);
        $code = 'failed_unknown';
        $summary = 'Run state could not be classified. Inspect the safe run log and provider ingestion ledger.';
        $next = 'Open the Run Timeline and Provider Ingestions ledger for this run.';

        if (in_array('provider_auth_blocked', $events, true)) {
            $code = 'provider_auth_failed';
            $summary = 'Provider callback authentication failed or was blocked.';
            $next = 'Check provider key, environment, HMAC secret, timestamp tolerance, idempotency key, and allowlist.';
        } elseif (self::has_rejected_rows($ingestions) || in_array('provider_result_rejected', $events, true)) {
            $code = self::has_accepted_rows($ingestions) ? 'partial_provider_result' : 'provider_rows_rejected';
            $summary = self::has_accepted_rows($ingestions) ? 'Provider callback produced a partial result with rejected rows.' : 'Provider rows were rejected and were not promoted into evidence.';
            $next = 'Review rejected row reasons, fix provider output contract, and retry with a new idempotency key.';
        } elseif ($status === 'queued' || in_array('provider_requested', $events, true) && !in_array('provider_result_received', $events, true)) {
            $code = 'waiting_for_provider';
            $summary = 'Run is waiting for a signed provider callback.';
            $next = 'Use the signed callback simulator or an approved external orchestrator to send a valid callback. The n8n template is optional only if that route is chosen.';
        } elseif (in_array('usage_reserved', $events, true) && !in_array('usage_settled', $events, true) && !in_array('usage_voided', $events, true)) {
            $code = 'usage_settlement_required';
            $summary = 'Usage was reserved but no settlement or void event is visible yet.';
            $next = 'Settle usage after value delivery or void the provider ingestion if no value was created.';
        } elseif (self::review_pending($logs, $ingestions)) {
            $code = 'review_pending';
            $summary = 'Provider rows were normalized and now need human review.';
            $next = 'Open the Review Queue, approve/revise insights, then create a brief and draft.';
        } elseif ($status === 'completed' || in_array('run_completed', $events, true)) {
            $code = 'ok';
            $summary = 'Run completed with no obvious provider callback blocker.';
            $next = 'Continue with insight review, brief creation, draft review, outcome capture, and report export.';
        }

        return [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'diagnosis' => $code,
            'summary' => $summary,
            'next_recommended_action' => $next,
            'run_status' => $status,
            'event_count' => count($logs),
            'ingestion_count' => count($ingestions),
            'has_rejected_rows' => self::has_rejected_rows($ingestions),
            'raw_provider_payloads_exposed' => false,
        ];
    }

    public static function copy_safe_summary(int $workspace_id, int $run_id): string {
        $d = self::diagnose($workspace_id, $run_id);
        return 'Run #' . $run_id . ': ' . $d['diagnosis'] . ' — ' . $d['summary'] . ' Next: ' . $d['next_recommended_action'];
    }

    private static function events(array $logs): array { $out = []; foreach ($logs as $log) { $out[] = (string) ($log['event_type'] ?? ''); } return array_values(array_unique($out)); }
    private static function run_status($run): string { return is_array($run) ? (string) ($run['status'] ?? '') : ''; }
    private static function has_rejected_rows(array $ingestions): bool { foreach ($ingestions as $row) { if ((int) ($row['rejected_count'] ?? 0) > 0) { return true; } } return false; }
    private static function has_accepted_rows(array $ingestions): bool { foreach ($ingestions as $row) { if ((int) ($row['accepted_count'] ?? 0) > 0) { return true; } } return false; }
    private static function review_pending(array $logs, array $ingestions): bool {
        if (!self::has_accepted_rows($ingestions)) { return false; }
        foreach ($logs as $log) { if (($log['event_type'] ?? '') === 'review_recorded') { return false; } }
        return true;
    }
}
