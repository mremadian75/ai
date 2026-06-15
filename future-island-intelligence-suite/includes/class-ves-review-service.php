<?php
if (!defined('ABSPATH')) { exit; }

/** Safe review actions for Insight/Brief/Draft workbenches and REST. */
final class VES_Review_Service {
    const REASONS = ['too_generic','wrong_audience','weak_evidence','unsupported_claim','off_brand','platform_mismatch','good_angle','strong_hook','needs_specificity','client_approved','client_rejected','market_changed','stale_memory','duplicate','useful_pattern','not_actionable'];

    public static function reason_codes(): array { return self::REASONS; }

    public static function review(string $target_type, int $target_id, string $decision, array $args = []) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('ves_review_store_missing', 'Intelligence store unavailable.'); }
        $target_type = self::key($target_type, 40);
        $decision = self::key($decision, 40);
        if (!in_array($target_type, ['insight','brief','draft'], true)) { return self::err('ves_review_bad_target', 'Unsupported review target.'); }
        if (!in_array($decision, ['approve','reject','revise','archive'], true)) { return self::err('ves_review_bad_decision', 'Unsupported review decision.'); }
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? (class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : 0)));
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_review_outputs($workspace_id)) { return self::err('ves_review_forbidden', 'You cannot review outputs in this workspace.'); }
        $row = self::get_target($target_type, $target_id);
        if (!is_array($row)) { return self::err('ves_review_not_found', 'Review target not found.'); }
        if ($workspace_id > 0 && (int) ($row['workspace_id'] ?? 0) !== $workspace_id) { return self::err('ves_review_workspace_mismatch', 'Target belongs to a different workspace.'); }

        $reason_code = self::key((string) ($args['reason_code'] ?? ''), 40);
        if ($reason_code !== '' && !in_array($reason_code, self::REASONS, true)) { return self::err('ves_review_bad_reason', 'Unknown reason code.'); }
        $notes = self::textarea((string) ($args['notes'] ?? ($args['reason'] ?? '')), 1000);
        $to_status = self::status_for($target_type, $decision);
        $metadata = ['reason_code' => $reason_code, 'reason' => $notes, 'review_decision' => $decision, 'reviewed_by_user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0];

        if ($target_type === 'insight') { $res = VES_Intelligence_Store::update_insight_status($target_id, $to_status, $metadata); }
        elseif ($target_type === 'brief') { $res = VES_Intelligence_Store::update_brief_status($target_id, $to_status, $metadata); }
        else { $res = VES_Intelligence_Store::update_draft_status($target_id, $to_status, $metadata); }
        if (self::is_err($res)) { return $res; }

        $updated = self::get_target($target_type, $target_id);
        $run_id = max(0, (int) ($row['run_id'] ?? 0));
        if (class_exists('VES_Decision_Edge_Service')) {
            $review_id = self::latest_review_id($workspace_id, $target_type, $target_id, $reason_code);
            if ($review_id > 0) { VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, $target_type, $target_id, 'review_decision', $review_id, 'reviewed_by', ['metadata' => ['decision' => $decision, 'reason_code' => $reason_code]]); }
        }
        return ['target_type' => $target_type, 'target_id' => $target_id, 'decision' => $decision, 'status' => is_array($updated) ? (string) ($updated['status'] ?? $to_status) : $to_status, 'reason_code' => $reason_code, 'notes' => $notes];
    }

    public static function create_brief_from_insight(int $insight_id, array $args = []) {
        if (!class_exists('VES_Intelligence_Store') || !class_exists('VES_Brief_OS_Service')) { return self::err('ves_review_brief_unavailable', 'Brief creation unavailable.'); }
        $insight = VES_Intelligence_Store::get_insight($insight_id);
        if (!is_array($insight)) { return self::err('ves_review_insight_missing', 'Insight not found.'); }
        $status = (string) ($insight['status'] ?? 'draft');
        if ($status !== 'approved' && empty($args['allow_unapproved'])) { return self::err('ves_review_insight_not_approved', 'Insight must be approved before creating a brief.'); }
        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? $insight['evidence_ids'] : [];
        $objective = trim((string) ($args['objective'] ?? ''));
        if ($objective === '') { $objective = trim((string) ($insight['recommendation'] ?? '')); }
        if ($objective === '') { $objective = trim((string) ($insight['summary'] ?? '')); }
        if ($objective === '') { $objective = 'Turn the approved insight into an execution brief.'; }
        $angle = trim((string) ($args['angle'] ?? ''));
        if ($angle === '') { $angle = trim((string) ($insight['title'] ?? '')); }
        return VES_Brief_OS_Service::create_manual_brief([
            'workspace_id' => (int) ($insight['workspace_id'] ?? 0),
            'run_id' => (int) ($insight['run_id'] ?? 0),
            'origin_type' => 'insight',
            'origin_id' => $insight_id,
            'insight_id' => $insight_id,
            'brief_type' => 'campaign',
            'channel' => (string) ($args['channel'] ?? 'generic'),
            'format' => (string) ($args['format'] ?? 'campaign_angle'),
            'title' => (string) ($args['title'] ?? ('Brief from insight #' . $insight_id)),
            'objective' => $objective,
            'target_audience' => (string) ($args['target_audience'] ?? ''),
            'angle' => $angle,
            'key_points' => [(string) ($insight['summary'] ?? '')],
            'source_evidence' => $evidence_ids,
            'evidence_ids' => $evidence_ids,
            'metadata' => ['created_via' => 'review_service', 'from_approved_insight' => $status === 'approved'],
        ]);
    }

    public static function queue(int $workspace_id, int $limit = 20): array {
        if (!class_exists('VES_Intelligence_Store')) { return []; }
        return [
            'insights' => VES_Intelligence_Store::list_insights(['workspace_id' => $workspace_id, 'status' => 'draft', 'limit' => $limit]),
            'briefs' => VES_Intelligence_Store::list_briefs(['workspace_id' => $workspace_id, 'status' => 'draft', 'limit' => $limit]),
            'drafts' => VES_Intelligence_Store::list_drafts(['workspace_id' => $workspace_id, 'status' => 'generated', 'limit' => $limit]),
        ];
    }

    private static function get_target(string $type, int $id) { $method = 'get_' . $type; return method_exists('VES_Intelligence_Store', $method) ? VES_Intelligence_Store::$method($id) : null; }
    private static function status_for(string $type, string $decision): string { if ($decision === 'approve') { return 'approved'; } if ($decision === 'reject') { return 'rejected'; } if ($decision === 'archive') { return 'archived'; } if ($decision === 'revise' && $type === 'brief') { return 'needs_revision'; } return $type === 'draft' ? 'edited' : 'reviewed'; }
    private static function latest_review_id(int $workspace_id, string $target_type, int $target_id, string $reason_code): int { if (!class_exists('VES_Review_Decision_Ledger')) { return 0; } $rows = VES_Review_Decision_Ledger::recent(['workspace_id' => $workspace_id, 'object_type' => $target_type, 'limit' => 10]); foreach ((array) $rows as $r) { if ((int) ($r['object_id'] ?? 0) === $target_id) { return (int) ($r['id'] ?? 0); } } return 0; }
    private static function key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
