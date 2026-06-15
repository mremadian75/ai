<?php
if (!defined('ABSPATH')) { exit; }

/** Deterministic, reviewable draft creation facade. No provider call. */
final class VES_Draft_Service {
    const MODES = ['prompt_package_preview','deterministic_preview','ai_generated','human_edited','imported'];

    public static function create_draft_from_brief(int $brief_id, array $args = []) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_draft_store_missing', 'Intelligence store unavailable.'); }
        $brief = VES_Intelligence_Store::get_brief($brief_id);
        if (!is_array($brief) || empty($brief['id'])) { return self::err('ves_draft_brief_missing', 'Brief not found.'); }
        $workspace_id = max(0, (int) ($brief['workspace_id'] ?? 0));
        $run_id = max(0, (int) ($args['run_id'] ?? ($brief['run_id'] ?? 0)));
        $mode = self::key((string) ($args['mode'] ?? ($args['draft_mode'] ?? 'deterministic_preview')), 40);
        if (!in_array($mode, self::MODES, true)) { $mode = 'deterministic_preview'; }
        $body = trim((string) ($args['body'] ?? ''));
        if ($body === '') {
            $body = self::deterministic_body($brief, $mode);
        }
        $draft_id = VES_Intelligence_Store::create_draft([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'brief_id' => $brief_id,
            'draft_type' => self::key((string) ($args['draft_type'] ?? 'post'), 40) ?: 'post',
            'title' => self::text((string) ($args['title'] ?? ('Draft candidate for brief #' . $brief_id)), 255),
            'body' => $body,
            'channel' => self::text((string) ($args['channel'] ?? ($brief['channel'] ?? 'generic')), 80),
            'model' => $mode,
            'status' => self::key((string) ($args['status'] ?? 'generated'), 24) ?: 'generated',
            'evidence_ids' => is_array($brief['evidence_ids'] ?? null) ? $brief['evidence_ids'] : [],
            'metadata' => array_merge(is_array($args['metadata'] ?? null) ? $args['metadata'] : [], [
                'draft_mode' => $mode,
                'brief_origin' => (string) ($brief['origin_type'] ?? ''),
                'evidence_refs' => is_array($brief['evidence_ids'] ?? null) ? $brief['evidence_ids'] : [],
                'review_required' => true,
                'built_by' => 'draft_service',
            ]),
        ]);
        if (self::is_err($draft_id)) { return $draft_id; }
        $draft_id = (int) $draft_id;
        if ($workspace_id > 0 && $run_id > 0 && class_exists('VES_Decision_Edge_Service')) {
            VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'brief', $brief_id, 'draft', $draft_id, 'generated', ['metadata' => ['draft_mode' => $mode]]);
        }
        if ($workspace_id > 0 && $run_id > 0 && class_exists('VES_Usage_Service')) {
            VES_Usage_Service::record_zero_cost_event([
                'workspace_id' => $workspace_id,
                'run_id' => $run_id,
                'target_type' => 'draft',
                'target_id' => $draft_id,
                'event_type' => 'deterministic_draft_preview',
                'module' => 'draft_workbench',
                'idempotency_key' => 'fi:draft:' . $workspace_id . ':' . $run_id . ':' . $brief_id . ':' . $mode,
                'message' => 'Draft preview created without provider cost.',
            ]);
        }
        return $draft_id;
    }

    public static function mark_primary(int $draft_id) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_draft_store_missing', 'Intelligence store unavailable.'); }
        $draft = VES_Intelligence_Store::get_draft($draft_id);
        if (!is_array($draft)) { return self::err('ves_draft_missing', 'Draft not found.'); }
        $res = VES_Intelligence_Store::update_draft_status($draft_id, (string) ($draft['status'] ?? 'generated'), ['is_primary' => true]);
        return self::is_err($res) ? $res : VES_Intelligence_Store::get_draft($draft_id);
    }

    private static function deterministic_body(array $brief, string $mode): string {
        $objective = trim((string) ($brief['objective'] ?? ''));
        $angle = trim((string) ($brief['angle'] ?? ''));
        $audience = trim((string) ($brief['target_audience'] ?? ($brief['audience'] ?? '')));
        $body = "Hook: Start from the evidence, not a generic claim.\n";
        if ($angle !== '') { $body .= 'Angle: ' . $angle . "\n"; }
        if ($objective !== '') { $body .= 'Objective: ' . $objective . "\n"; }
        if ($audience !== '') { $body .= 'Audience: ' . $audience . "\n"; }
        $body .= "Evidence note: review source refs before publishing.\nMode: " . $mode;
        return $body;
    }

    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
