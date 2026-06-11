<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Generation_Prompt_Package_Builder — Phase 6B-B (+ 6C completion).
 * Structured, bounded, redacted prompt-package CONTRACT for future generation.
 * No AI/provider call, no raw prompt storage, no production behavior change.
 * Evidence and memory strictly separated; memory cannot unblock missing evidence.
 * PHP 7.4 compatible.
 */
final class VES_Generation_Prompt_Package_Builder {

    const PACKAGE_VERSION = '1.1';
    const USE_CASES = ['brief_generation', 'draft_generation', 'qa_check'];
    const TARGET_TYPES = ['insight', 'brief', 'draft'];
    const EXECUTION_OPTION = 'ves_generation_execution_enabled';
    const DEFAULT_MAX_CONTEXT_ITEMS = 12;
    const DEFAULT_MAX_CONTEXT_CHARS = 4000;

    const OUTPUT_CONTRACTS = [
        'brief_generation' => [
            'schema_key' => 'brief_generation_v1',
            'required_fields' => ['objective', 'key_message', 'audience', 'constraints', 'evidence_refs'],
            'optional_fields' => ['secondary_messages', 'tone_notes', 'channel'],
            'field_descriptions' => ['objective' => 'One-sentence goal grounded in source evidence.', 'key_message' => 'Primary evidence-backed message.', 'audience' => 'Intended audience.', 'constraints' => 'Forbidden claims / preferred / avoided terms honored.', 'evidence_refs' => 'evidence_id values actually used.'],
            'validation_hints' => ['evidence_refs must be a non-empty subset of provided evidence ids', 'no market claim without an evidence_ref'],
            'review_requirements' => ['human_review_required' => true, 'no_auto_approval' => true],
            'evidence_requirements' => ['evidence_required' => true, 'memory_is_not_evidence' => true],
            'forbidden_output_behavior' => ['no_fabricated_metrics', 'no_unsupported_market_claims', 'no_raw_prompt_echo', 'no_secrets'],
        ],
        'draft_generation' => [
            'schema_key' => 'draft_generation_v1',
            'required_fields' => ['headline', 'body', 'cta', 'channel', 'tone', 'evidence_refs'],
            'optional_fields' => ['variants', 'hashtags'],
            'field_descriptions' => ['headline' => 'Hook derived from the approved brief, not memory alone.', 'body' => 'Body grounded in the brief + its evidence.', 'cta' => 'Call to action consistent with objective.', 'channel' => 'Target channel.', 'tone' => 'May use trusted voice_rules/preferred_terms; never factual source.', 'evidence_refs' => 'Evidence ids carried from the brief.'],
            'validation_hints' => ['draft must trace to an approved/ready brief', 'memory guides tone only'],
            'review_requirements' => ['human_review_required' => true, 'no_auto_approval' => true],
            'evidence_requirements' => ['source_brief_required' => true, 'memory_is_not_evidence' => true],
            'forbidden_output_behavior' => ['no_fabricated_metrics', 'no_unsupported_market_claims', 'no_raw_prompt_echo', 'no_secrets'],
        ],
        'qa_check' => [
            'schema_key' => 'qa_check_v1',
            'required_fields' => ['checks', 'issues', 'evidence_alignment', 'verdict'],
            'optional_fields' => ['severity', 'notes'],
            'field_descriptions' => ['checks' => 'Deterministic checks performed.', 'issues' => 'Problems found.', 'evidence_alignment' => 'Whether claims map to evidence_refs.', 'verdict' => 'pass / needs_review / fail — never auto-approve.'],
            'validation_hints' => ['flag any claim lacking an evidence_ref', 'flag forbidden_claims usage'],
            'review_requirements' => ['human_review_required' => true, 'no_auto_approval' => true],
            'evidence_requirements' => ['evidence_optional_but_alignment_checked' => true, 'memory_is_not_evidence' => true],
            'forbidden_output_behavior' => ['no_auto_approval', 'no_secrets', 'no_raw_prompt_echo'],
        ],
    ];

    public static function execution_enabled() {
        $on = false;
        if (function_exists('get_option')) { $on = (bool) get_option(self::EXECUTION_OPTION, false); }
        if (function_exists('apply_filters')) { $on = (bool) apply_filters(self::EXECUTION_OPTION, $on); }
        return $on;
    }

    public static function is_use_case($u) { return in_array((string) $u, self::USE_CASES, true); }
    public static function is_target_type($t) { return in_array((string) $t, self::TARGET_TYPES, true); }

    private static function redact($text, $max = 600) {
        $text = (string) $text;
        if (class_exists('VES_Brand_Context_Service') && method_exists('VES_Brand_Context_Service', 'redact_sensitive_text')) { $text = VES_Brand_Context_Service::redact_sensitive_text($text); }
        if (function_exists('wp_strip_all_tags')) { $text = wp_strip_all_tags($text); }
        return (strlen($text) > $max) ? (substr($text, 0, $max - 1) . '…') : $text;
    }

    private static function memory_policy() { return ['use_for_tone_constraints_and_preferences' => true, 'do_not_use_for_factual_market_claims' => true]; }

    public static function build(array $args = []) {
        $use_case = is_scalar($args['use_case'] ?? null) ? (string) $args['use_case'] : '';
        $target_type = is_scalar($args['target_type'] ?? null) ? (string) $args['target_type'] : '';
        $target_id = (int) ($args['target_id'] ?? 0);
        $workspace_id = (int) ($args['workspace_id'] ?? 0);
        $channel = self::redact($args['channel'] ?? '', 80);
        $audience = self::redact($args['audience'] ?? '', 240);
        $objective = self::redact($args['objective'] ?? '', 400);
        $max_items = isset($args['max_context_items']) && (int) $args['max_context_items'] > 0 ? (int) $args['max_context_items'] : self::DEFAULT_MAX_CONTEXT_ITEMS;
        $max_chars = isset($args['max_context_chars']) && (int) $args['max_context_chars'] > 0 ? (int) $args['max_context_chars'] : self::DEFAULT_MAX_CONTEXT_CHARS;
        $include_debug = !empty($args['include_debug']);

        $pkg = [
            'package_version' => self::PACKAGE_VERSION, 'workspace_id' => $workspace_id, 'use_case' => $use_case,
            'target' => ['type' => $target_type, 'id' => $target_id], 'build_status' => 'blocked', 'reason' => '', 'blocking_reason' => 'none',
            'task' => ['goal' => '', 'channel' => $channel, 'audience' => $audience, 'objective' => $objective],
            'evidence' => ['required' => true, 'items' => [], 'count' => 0, 'policy' => ['evidence_required_for_market_claims' => true, 'memory_is_not_evidence' => true]],
            'brand_context' => ['enabled' => false, 'applied' => false, 'reason' => 'feature_disabled', 'items' => [], 'items_count' => 0, 'policy' => self::memory_policy()],
            'constraints' => ['forbidden_claims' => [], 'preferred_terms' => [], 'avoided_terms' => [], 'format_rules' => []],
            'output_contract' => self::output_contract($use_case),
            'safety' => ['no_raw_prompt_storage' => true, 'no_provider_call' => true, 'requires_human_review' => true, 'provider_execution_allowed' => false],
            'diagnostics' => ['generated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'), 'limits' => ['max_context_items' => $max_items, 'max_context_chars' => $max_chars], 'include_debug' => $include_debug, 'warnings' => []],
        ];

        if (!self::is_use_case($use_case)) { $pkg['reason'] = 'unsupported_use_case'; return self::finish($pkg, $include_debug); }
        if (!self::is_target_type($target_type)) { $pkg['reason'] = 'invalid_target'; return self::finish($pkg, $include_debug); }
        if ($target_id <= 0) { $pkg['reason'] = 'invalid_target'; return self::finish($pkg, $include_debug); }
        if ($workspace_id <= 0) { $pkg['reason'] = 'no_workspace'; return self::finish($pkg, $include_debug); }
        if (!class_exists('VES_Intelligence_Store')) { $pkg['reason'] = 'store_unavailable'; return self::finish($pkg, $include_debug); }

        $pkg['brand_context'] = self::brand_context_block($workspace_id, $use_case, $max_items, $max_chars);
        $pkg['brand_context']['items_count'] = count($pkg['brand_context']['items']);
        if ((string) $pkg['brand_context']['reason'] === 'feature_disabled') { $pkg['diagnostics']['warnings'][] = 'memory_feature_disabled'; }
        if ((string) $pkg['brand_context']['reason'] === 'no_trusted_context') { $pkg['diagnostics']['warnings'][] = 'no_trusted_context'; }
        $pkg['constraints'] = self::constraints_from_brand_context($pkg['brand_context'], $pkg['constraints']);

        if ($use_case === 'brief_generation') { return self::finish(self::assemble_brief_from_insight($pkg, $workspace_id, $target_type, $target_id), $include_debug); }
        if ($use_case === 'draft_generation') { return self::finish(self::assemble_draft_from_brief($pkg, $workspace_id, $target_type, $target_id), $include_debug); }
        return self::finish(self::assemble_qa($pkg, $workspace_id, $target_type, $target_id), $include_debug);
    }

    private static function finish(array $pkg, $include_debug = false) {
        $status = (string) $pkg['build_status'];
        $allowed_block = ['missing_evidence', 'invalid_target', 'unsupported_use_case', 'feature_disabled', 'target_not_ready', 'no_workspace', 'store_unavailable', 'target_not_found', 'workspace_mismatch', 'target_type_mismatch'];
        $pkg['blocking_reason'] = ($status === 'blocked' && in_array((string) $pkg['reason'], $allowed_block, true)) ? (string) $pkg['reason'] : 'none';
        $pkg['safety']['provider_execution_allowed'] = ($status === 'ready' && self::execution_enabled());
        if ($status === 'blocked' && !$include_debug && isset($pkg['brand_context']['items'])) {
            $pkg['brand_context']['items_count'] = count((array) $pkg['brand_context']['items']);
            $pkg['brand_context']['items'] = [];
        }
        unset($pkg['diagnostics']['include_debug']);
        $event = $status === 'blocked' ? 'package_build_blocked' : ($status === 'ready' ? 'package_build_success' : 'package_build_warning');
        if (class_exists('VES_Log') && method_exists('VES_Log', 'info')) {
            VES_Log::info('generation_prompt', 'Prompt package ' . $event, ['workspace_id' => (int) $pkg['workspace_id'], 'use_case' => (string) $pkg['use_case'], 'target_type' => (string) $pkg['target']['type'], 'target_id' => (int) $pkg['target']['id'], 'build_status' => $status, 'blocking_reason' => (string) $pkg['blocking_reason']]);
        }
        return $pkg;
    }

    private static function output_contract($use_case) {
        return isset(self::OUTPUT_CONTRACTS[$use_case]) ? self::OUTPUT_CONTRACTS[$use_case]
            : ['schema_key' => '', 'required_fields' => [], 'optional_fields' => [], 'field_descriptions' => [], 'validation_hints' => [], 'review_requirements' => ['human_review_required' => true], 'evidence_requirements' => [], 'forbidden_output_behavior' => []];
    }

    private static function brand_context_block($workspace_id, $use_case, $max_items = 0, $max_chars = 0) {
        $block = ['enabled' => false, 'applied' => false, 'reason' => 'feature_disabled', 'items' => [], 'policy' => self::memory_policy()];
        if (!class_exists('VES_Generation_Context_Resolver')) { $block['reason'] = 'service_unavailable'; return $block; }
        $resolve_args = ['workspace_id' => (int) $workspace_id, 'use_case' => (string) $use_case];
        if ((int) $max_items > 0) { $resolve_args['max_items'] = (int) $max_items; }
        if ((int) $max_chars > 0) { $resolve_args['max_chars'] = (int) $max_chars; }
        $env = VES_Generation_Context_Resolver::resolve($resolve_args);
        $block['enabled'] = !empty($env['enabled']);
        $block['applied'] = !empty($env['applied']);
        $block['reason'] = (string) ($env['reason'] ?? '');
        foreach ((array) ($env['context_package']['items'] ?? []) as $it) {
            $block['items'][] = ['memory_id' => (int) ($it['memory_id'] ?? 0), 'record_type' => (string) ($it['record_type'] ?? ''), 'summary' => (string) ($it['summary'] ?? ''), 'source_target_type' => (string) ($it['source_target_type'] ?? ''), 'source_target_id' => (int) ($it['source_target_id'] ?? 0)];
        }
        return $block;
    }

    private static function constraints_from_brand_context(array $bc, array $constraints) {
        if (empty($bc['applied'])) { return $constraints; }
        foreach ((array) $bc['items'] as $it) {
            $type = (string) ($it['record_type'] ?? ''); $sum = (string) ($it['summary'] ?? '');
            if ($sum === '') { continue; }
            if ($type === 'forbidden_claim') { $constraints['forbidden_claims'][] = $sum; }
            elseif ($type === 'preferred_term') { $constraints['preferred_terms'][] = $sum; }
            elseif ($type === 'avoided_term') { $constraints['avoided_terms'][] = $sum; }
        }
        return $constraints;
    }

    private static function assemble_brief_from_insight(array $pkg, $workspace_id, $target_type, $target_id) {
        if ($target_type !== 'insight') { $pkg['reason'] = 'target_type_mismatch'; return $pkg; }
        $insight = VES_Intelligence_Store::get_insight($target_id);
        if (!is_array($insight) || empty($insight['id'])) { $pkg['reason'] = 'target_not_found'; return $pkg; }
        if ((int) ($insight['workspace_id'] ?? 0) !== (int) $workspace_id) { $pkg['reason'] = 'workspace_mismatch'; return $pkg; }
        $evidence_ids = self::evidence_ids($insight);
        $pkg['evidence']['items'] = self::evidence_refs($evidence_ids);
        $pkg['evidence']['count'] = count($evidence_ids);
        if (empty($evidence_ids)) {
            $pkg['build_status'] = 'blocked'; $pkg['reason'] = 'missing_evidence';
            $pkg['diagnostics']['warnings'][] = 'limited_evidence';
            return $pkg;
        }
        $pkg['task']['goal'] = self::redact($insight['recommendation'] ?? ($insight['summary'] ?? 'Build an evidence-backed brief.'), 400);
        $pkg['build_status'] = 'ready'; $pkg['reason'] = 'ok';
        return $pkg;
    }

    private static function assemble_draft_from_brief(array $pkg, $workspace_id, $target_type, $target_id) {
        if ($target_type !== 'brief') { $pkg['reason'] = 'target_type_mismatch'; return $pkg; }
        $brief = VES_Intelligence_Store::get_brief($target_id);
        if (!is_array($brief) || empty($brief['id'])) { $pkg['reason'] = 'target_not_found'; return $pkg; }
        if ((int) ($brief['workspace_id'] ?? 0) !== (int) $workspace_id) { $pkg['reason'] = 'workspace_mismatch'; return $pkg; }
        $evidence_ids = self::evidence_ids($brief);
        $pkg['evidence']['items'] = self::evidence_refs($evidence_ids);
        $pkg['evidence']['count'] = count($evidence_ids);
        $pkg['task']['goal'] = self::redact($brief['objective'] ?? ($brief['summary'] ?? 'Draft from the approved brief.'), 400);
        $pkg['task']['source_brief_id'] = (int) $brief['id'];
        $status = strtolower((string) ($brief['status'] ?? 'draft'));
        if (!in_array($status, ['approved', 'ready', 'reviewed'], true)) {
            $pkg['build_status'] = 'warning'; $pkg['reason'] = 'target_not_ready';
            $pkg['diagnostics']['warnings'][] = 'non_ready_brief';
            return $pkg;
        }
        $pkg['build_status'] = 'ready'; $pkg['reason'] = 'ok';
        return $pkg;
    }

    private static function assemble_qa(array $pkg, $workspace_id, $target_type, $target_id) {
        $getter = ['insight' => 'get_insight', 'brief' => 'get_brief', 'draft' => 'get_draft'];
        $fn = $getter[$target_type] ?? '';
        if ($fn === '' || !method_exists('VES_Intelligence_Store', $fn)) { $pkg['reason'] = 'invalid_target'; return $pkg; }
        $row = VES_Intelligence_Store::$fn($target_id);
        if (!is_array($row) || empty($row['id'])) { $pkg['reason'] = 'target_not_found'; return $pkg; }
        if ((int) ($row['workspace_id'] ?? 0) !== (int) $workspace_id) { $pkg['reason'] = 'workspace_mismatch'; return $pkg; }
        $evidence_ids = self::evidence_ids($row);
        $pkg['evidence']['items'] = self::evidence_refs($evidence_ids);
        $pkg['evidence']['count'] = count($evidence_ids);
        $pkg['task']['goal'] = 'QA the ' . $target_type . ' against its evidence and constraints.';
        $pkg['build_status'] = 'ready'; $pkg['reason'] = 'ok';
        return $pkg;
    }

    private static function evidence_ids($row) {
        $ids = is_array($row['evidence_ids'] ?? null) ? $row['evidence_ids'] : [];
        return array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));
    }

    private static function evidence_refs($ids) {
        $out = []; foreach ($ids as $id) { $out[] = ['evidence_id' => (int) $id]; } return $out;
    }

    public static function export_to_file(array $pkg, $path, $force = false) {
        $path = (string) $path;
        if ($path === '') { return ['ok' => false, 'reason' => 'no_path', 'path' => '', 'bytes' => 0]; }
        if (file_exists($path) && !$force) { return ['ok' => false, 'reason' => 'exists_use_force', 'path' => $path, 'bytes' => 0]; }
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) { return ['ok' => false, 'reason' => 'dir_not_writable', 'path' => $path, 'bytes' => 0]; }
        $clean = self::strip_forbidden($pkg);
        $json = function_exists('wp_json_encode') ? wp_json_encode($clean, JSON_PRETTY_PRINT) : json_encode($clean, JSON_PRETTY_PRINT);
        $bytes = @file_put_contents($path, (string) $json);
        if ($bytes === false) { return ['ok' => false, 'reason' => 'write_failed', 'path' => $path, 'bytes' => 0]; }
        return ['ok' => true, 'reason' => 'written', 'path' => $path, 'bytes' => (int) $bytes];
    }

    private static function strip_forbidden($data, $depth = 0) {
        $forbidden = ['raw_prompt', 'prompt_text', 'raw_response', 'provider_response', 'api_key', 'authorization', 'bearer', 'token', 'secret', 'password'];
        if ($depth > 8 || !is_array($data)) { return $data; }
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $forbidden, true)) { continue; }
            $out[$k] = is_array($v) ? self::strip_forbidden($v, $depth + 1) : $v;
        }
        return $out;
    }
}
