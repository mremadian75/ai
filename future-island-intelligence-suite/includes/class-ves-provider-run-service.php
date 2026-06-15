<?php
if (!defined('ABSPATH')) { exit; }

/** Orchestrates provider-row ingestion into canonical Future Island records. */
final class VES_Provider_Run_Service {
    public static function ingest(string $family, array $args) {
        $family = VES_Provider_Contract_Service::clean_key($family, 60);
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $run_id = max(0, (int) ($args['run_id'] ?? 0));
        $provider_key = VES_Provider_Contract_Service::clean_key((string) ($args['provider_key'] ?? ''), 80);
        $provider_run_id = self::clean_text((string) ($args['provider_run_id'] ?? ''), 191);
        $idempotency_key = self::clean_text((string) ($args['idempotency_key'] ?? ''), 191);
        $rows = is_array($args['rows'] ?? null) ? $args['rows'] : [];
        $usage_mode = self::clean_text((string) ($args['usage_mode'] ?? 'zero_cost_beta'), 40);
        if ($workspace_id <= 0) { return self::err('ves_provider_missing_workspace', 'workspace_id is required.'); }
        if ($run_id <= 0) { return self::err('ves_provider_missing_run', 'run_id is required.'); }
        if ($provider_key === '') { return self::err('ves_provider_missing_provider', 'provider_key is required.'); }
        if ($idempotency_key === '') { $idempotency_key = 'fi-provider:' . md5($workspace_id . '|' . $run_id . '|' . $family . '|' . $provider_key . '|' . $provider_run_id . '|' . self::json($rows)); }

        $contract = VES_Provider_Contract_Service::assert_contract($family, $provider_key);
        if (self::is_err($contract)) { self::log($workspace_id, $run_id, 'warn', 'provider_contract', 'provider_result_rejected', 'Provider contract rejected.', ['provider_key' => $provider_key, 'reason' => $contract->get_error_code()]); return $contract; }
        if (class_exists('VES_Run_Service')) {
            $check = VES_Run_Service::assert_workspace($run_id, $workspace_id);
            if (self::is_err($check)) { return self::err('ves_provider_run_forbidden', 'Run belongs to a different workspace.'); }
            $run = VES_Run_Service::get_run($run_id);
            if (is_array($run) && ($run['status'] ?? '') === 'queued') { VES_Run_Service::mark_running($run_id); }
        }
        if (class_exists('VES_Provider_Ingestion_Store')) {
            $existing = VES_Provider_Ingestion_Store::find($idempotency_key);
            if (is_array($existing) && (int) ($existing['workspace_id'] ?? 0) === $workspace_id) {
                self::log($workspace_id, $run_id, 'info', 'provider_ingestion', 'provider_result_received', 'Duplicate provider ingestion ignored by idempotency.', ['idempotency_key' => $idempotency_key]);
                return ['already_processed' => true, 'ingestion_id' => (int) $existing['id'], 'result' => $existing['result'] ?? []];
            }
        }

        self::log($workspace_id, $run_id, 'info', 'provider_ingestion', 'provider_result_received', 'Provider result received for validation.', ['provider_family' => $family, 'provider_key' => $provider_key, 'provider_run_id' => $provider_run_id, 'row_count' => count($rows), 'usage_mode' => $usage_mode]);
        if (class_exists('VES_Usage_Service')) {
            $reserve = VES_Usage_Service::reserve_provider_ingestion([
                'workspace_id' => $workspace_id, 'run_id' => $run_id, 'target_type' => 'run', 'target_id' => $run_id,
                'idempotency_key' => 'reserve:' . $idempotency_key, 'module' => 'provider_contract',
                'message' => 'Provider ingestion beta usage reserved',
                'context' => ['provider_family' => $family, 'provider_key' => $provider_key, 'usage_mode' => $usage_mode, 'row_count' => count($rows)],
            ]);
            $reserve_id = self::is_err($reserve) ? 0 : (int) $reserve;
            self::log($workspace_id, $run_id, 'info', 'usage', 'usage_reserved', 'Provider ingestion beta usage reserved.', ['usage_event_id' => $reserve_id, 'usage_mode' => $usage_mode]);
        }
        $validated = VES_Provider_Result_Validator::validate_rows($family, $contract, $rows);
        foreach ($validated['rejected'] as $rej) { self::log($workspace_id, $run_id, 'warn', 'provider_validation', 'provider_result_rejected', 'Provider row rejected.', $rej); }
        $created = ['sources' => [], 'signals' => [], 'evidence' => [], 'insights' => [], 'briefs' => [], 'drafts' => []];
        foreach ($validated['accepted'] as $row) {
            $mapped = VES_Provider_Result_Mapper::map_row($family, $row, ['workspace_id' => $workspace_id, 'run_id' => $run_id, 'provider_family' => $family, 'provider_key' => $provider_key]);
            foreach (['source_id'=>'sources','signal_id'=>'signals','evidence_id'=>'evidence','insight_id'=>'insights','brief_id'=>'briefs','draft_id'=>'drafts'] as $k => $bucket) {
                $id = (int) ($mapped[$k] ?? 0); if ($id > 0) { $created[$bucket][] = $id; }
            }
            self::log($workspace_id, $run_id, 'info', 'provider_mapping', 'normalized', 'Provider row normalized into canonical records.', ['ids' => $mapped]);
        }
        $usage_id = 0;
        if (class_exists('VES_Usage_Service')) {
            $usage = VES_Usage_Service::record_zero_cost_event([
                'workspace_id' => $workspace_id, 'run_id' => $run_id, 'target_type' => 'run', 'target_id' => $run_id,
                'event_type' => 'provider_ingest_zero_cost', 'idempotency_key' => 'settle:' . $idempotency_key,
                'module' => 'provider_contract', 'message' => 'Provider ingestion beta usage event',
                'context' => ['provider_family' => $family, 'provider_key' => $provider_key, 'accepted_count' => count($validated['accepted']), 'rejected_count' => count($validated['rejected']), 'usage_mode' => $usage_mode],
            ]);
            $usage_id = self::is_err($usage) ? 0 : (int) $usage;
            self::log($workspace_id, $run_id, 'info', 'usage', 'usage_settled', 'Zero-cost provider ingestion usage recorded.', ['usage_event_id' => $usage_id]);
        }
        $result = [
            'workspace_id' => $workspace_id, 'run_id' => $run_id, 'provider_family' => $family, 'provider_key' => $provider_key,
            'schema_version' => (string) ($contract['schema_version'] ?? 'v1'), 'accepted_count' => count($validated['accepted']), 'rejected_count' => count($validated['rejected']), 'created' => $created, 'usage_event_id' => $usage_id,
            'rejected' => $validated['rejected'], 'raw_payload_exposed' => false, 'provider_called_by_wordpress' => false,
        ];
        $status = count($validated['rejected']) > 0 ? (count($validated['accepted']) > 0 ? 'partial' : 'failed') : 'completed';
        $ingestion_id = class_exists('VES_Provider_Ingestion_Store') ? VES_Provider_Ingestion_Store::record(['workspace_id'=>$workspace_id,'run_id'=>$run_id,'provider_family'=>$family,'provider_key'=>$provider_key,'provider_run_id'=>$provider_run_id,'idempotency_key'=>$idempotency_key,'status'=>$status,'row_count'=>count($rows),'accepted_count'=>count($validated['accepted']),'rejected_count'=>count($validated['rejected']),'result'=>$result]) : 0;
        $result['ingestion_id'] = (int) $ingestion_id;
        if (class_exists('VES_Run_Service')) { $run = VES_Run_Service::get_run($run_id); if (is_array($run) && ($run['status'] ?? '') === 'running') { VES_Run_Service::mark_completed($run_id, ['provider_ingestion' => $result]); } }
        self::log($workspace_id, $run_id, 'info', 'provider_ingestion', 'run_completed', 'Provider ingestion completed.', ['accepted_count' => $result['accepted_count'], 'rejected_count' => $result['rejected_count']]);
        return $result;
    }

    private static function json($v): string { $j = function_exists('wp_json_encode') ? wp_json_encode($v, JSON_UNESCAPED_UNICODE) : json_encode($v); return is_string($j) ? $j : '{}'; }
    private static function log(int $workspace_id, int $run_id, string $level, string $component, string $event_type, string $message, array $context = []): void {
        if (class_exists('VES_Run_Log_Service')) { VES_Run_Log_Service::append($workspace_id, $run_id, $level, $component, $event_type, $message, $context); }
    }
    private static function clean_text(string $s, int $max): string { $s=function_exists('sanitize_text_field')?sanitize_text_field($s):trim(strip_tags($s)); return substr($s,0,$max); }
    private static function is_err($v): bool { return function_exists('is_wp_error') ? is_wp_error($v) : ($v instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code'=>$code,'message'=>$message]; }
}
