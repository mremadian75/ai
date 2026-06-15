<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Command Room action service: safe, nonce-capable mutations for the review ->
 * brief -> draft -> memory -> outcome loop. Core behavior is exposed through
 * perform() so REST, admin-post and tests share one permission/workspace path.
 */
final class VES_Command_Room_Action_Service {
    const ACTION = 'fi_command_room_action';

    const INSIGHT_ACTIONS = ['approve_insight','reject_insight','request_insight_revision','archive_insight','create_brief_from_insight'];
    const BRIEF_ACTIONS = ['mark_brief_ready','approve_brief','request_brief_revision','generate_draft_from_brief'];
    const DRAFT_ACTIONS = ['approve_draft','reject_draft','request_draft_revision','mark_draft_primary','save_memory_candidate','record_outcome'];
    const MEMORY_ACTIONS = ['activate_memory','reject_memory','pin_memory','unpin_memory','archive_memory','mark_memory_stale','expire_memory'];

    public static function register(): void {
        if (function_exists('add_action')) { add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_admin_post']); }
    }

    public static function handle_admin_post(): void {
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION); }
        $res = self::perform(is_array($_POST) ? $_POST : []);
        $notice = self::is_err($res) ? 'command_action_failed' : 'command_action_completed';
        $err = self::is_err($res) ? $res->get_error_code() : '';
        $run_id = is_array($res) ? (int) ($res['run_id'] ?? 0) : 0;
        $url = function_exists('admin_url') ? admin_url('admin.php?page=fi-command-room') : 'admin.php?page=fi-command-room';
        if (function_exists('add_query_arg')) { $url = add_query_arg(array_filter(['fi_notice' => $notice, 'fi_err' => $err, 'run_id' => $run_id]), $url); }
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); exit; }
        if (self::is_err($res) && function_exists('wp_die')) { wp_die($res->get_error_message()); }
    }

    /** @return array|WP_Error */
    public static function perform(array $args) {
        $action = self::key((string) ($args['command_action'] ?? ($args['action_name'] ?? $args['fi_action'] ?? '')), 80);
        if ($action === '' || $action === self::ACTION) { $action = self::key((string) ($args['do'] ?? ''), 80); }
        if ($action === '') { return self::err('fi_action_missing', 'Command action is required.'); }
        $workspace_id = self::workspace_id();
        $cap = self::capability_for_action($action);
        if ($cap === '') { return self::err('fi_action_unknown', 'Unsupported Command Room action.'); }
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can($cap, $workspace_id)) { return self::err('fi_action_forbidden', 'Not allowed for this workspace.'); }

        $target_type = self::target_type_for_action($action, (string) ($args['target_type'] ?? ''));
        $target_id = max(0, (int) ($args['target_id'] ?? ($args['id'] ?? 0)));
        if ($target_type === '' || $target_id <= 0) { return self::err('fi_action_bad_target', 'Action target is required.'); }
        $target = self::load_target($target_type, $target_id, $workspace_id);
        if (self::is_err($target)) { return $target; }

        $run_ctx = self::action_run($workspace_id, $target_type, $target_id, (array) $target, $action, $args);
        if (self::is_err($run_ctx)) { return $run_ctx; }
        $run_id = (int) ($run_ctx['run_id'] ?? 0);
        $result = null;
        try {
            $result = self::execute($action, $target_type, $target_id, (array) $target, $workspace_id, $run_id, $args);
        } catch (\Throwable $e) {
            $result = self::err('fi_action_exception', 'Command action failed.');
        }
        if (self::is_err($result)) {
            if (!empty($run_ctx['created']) && $run_id > 0 && class_exists('VES_Run_Service')) { VES_Run_Service::mark_failed($run_id, $result->get_error_message(), ['action' => $action]); }
            return $result;
        }
        if (!empty($run_ctx['created']) && $run_id > 0 && class_exists('VES_Run_Service')) {
            VES_Run_Service::mark_completed($run_id, ['action' => $action, 'target_type' => $target_type, 'target_id' => $target_id]);
        }
        return ['action' => $action, 'target_type' => $target_type, 'target_id' => $target_id, 'run_id' => $run_id, 'result' => $result];
    }

    private static function execute(string $action, string $target_type, int $target_id, array $target, int $workspace_id, int $run_id, array $args) {
        $reason_code = self::key((string) ($args['reason_code'] ?? ''), 40);
        $notes = self::textarea((string) ($args['notes'] ?? ($args['reason'] ?? '')), 1500);
        switch ($action) {
            case 'approve_insight': return self::review('insight', $target_id, 'approve', $workspace_id, $reason_code, $notes);
            case 'reject_insight': return self::review('insight', $target_id, 'reject', $workspace_id, $reason_code, $notes);
            case 'request_insight_revision': return self::review('insight', $target_id, 'revise', $workspace_id, $reason_code, $notes);
            case 'archive_insight': return self::review('insight', $target_id, 'archive', $workspace_id, $reason_code, $notes);
            case 'create_brief_from_insight': return self::create_brief($target_id, $workspace_id, $args);

            case 'mark_brief_ready': return self::update_brief($target_id, 'ready', $workspace_id, $reason_code, $notes);
            case 'approve_brief': return self::review('brief', $target_id, 'approve', $workspace_id, $reason_code, $notes);
            case 'request_brief_revision': return self::review('brief', $target_id, 'revise', $workspace_id, $reason_code, $notes);
            case 'generate_draft_from_brief': return self::generate_draft($target_id, $workspace_id, $args);

            case 'approve_draft': return self::review('draft', $target_id, 'approve', $workspace_id, $reason_code, $notes);
            case 'reject_draft': return self::review('draft', $target_id, 'reject', $workspace_id, $reason_code, $notes);
            case 'request_draft_revision': return self::review('draft', $target_id, 'revise', $workspace_id, $reason_code, $notes);
            case 'mark_draft_primary': return self::mark_primary($target_id, $workspace_id, $run_id);
            case 'save_memory_candidate': return self::save_memory_from_draft($target_id, $workspace_id, $run_id, $args);
            case 'record_outcome': return self::record_outcome($target_type, $target_id, $workspace_id, $run_id, $args);

            case 'activate_memory': return self::memory_status($target_id, 'active', $workspace_id, $run_id, $reason_code, $notes);
            case 'reject_memory': return self::memory_status($target_id, 'rejected', $workspace_id, $run_id, $reason_code, $notes);
            case 'pin_memory': return self::memory_status($target_id, 'pinned', $workspace_id, $run_id, $reason_code, $notes, ['is_pinned' => true]);
            case 'unpin_memory': return self::memory_status($target_id, 'active', $workspace_id, $run_id, $reason_code, $notes, ['is_pinned' => false]);
            case 'archive_memory': return self::memory_status($target_id, 'archived', $workspace_id, $run_id, $reason_code, $notes);
            case 'mark_memory_stale':
            case 'expire_memory': return self::memory_status($target_id, 'expired', $workspace_id, $run_id, $reason_code, $notes);
        }
        return self::err('fi_action_unknown', 'Unsupported Command Room action.');
    }

    private static function review(string $type, int $id, string $decision, int $workspace_id, string $reason_code, string $notes) {
        if (!class_exists('VES_Review_Service')) { return self::err('fi_review_unavailable', 'Review service unavailable.'); }
        $res = VES_Review_Service::review($type, $id, $decision, ['workspace_id' => $workspace_id, 'reason_code' => $reason_code, 'notes' => $notes]);
        if (!self::is_err($res)) { self::record_review_pattern($type, $id, $workspace_id, $res); }
        return $res;
    }

    private static function create_brief(int $insight_id, int $workspace_id, array $args) {
        $insight = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::get_insight($insight_id) : null;
        if (!is_array($insight) || (int) ($insight['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_insight_missing', 'Insight not found.'); }
        $status = (string) ($insight['status'] ?? 'draft');
        $allow = false;
        if ($status !== 'approved' && !empty($args['admin_override']) && function_exists('current_user_can') && current_user_can('manage_options')) { $allow = true; }
        if ($status !== 'approved' && !$allow) { return self::err('fi_insight_not_approved', 'Rejected or unapproved insights cannot create briefs without an audited admin override.'); }
        $brief_id = VES_Review_Service::create_brief_from_insight($insight_id, array_merge($args, ['workspace_id' => $workspace_id, 'allow_unapproved' => $allow]));
        if (!self::is_err($brief_id) && $allow && class_exists('VES_Decision_Edge_Service')) {
            VES_Decision_Edge_Service::add_edge($workspace_id, (int) ($insight['run_id'] ?? 0), 'insight', $insight_id, 'brief', (int) $brief_id, 'generated_with_override', ['metadata' => ['admin_override' => true]]);
        }
        return $brief_id;
    }

    private static function update_brief(int $brief_id, string $status, int $workspace_id, string $reason_code, string $notes) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('fi_store_missing', 'Intelligence store unavailable.'); }
        $brief = VES_Intelligence_Store::get_brief($brief_id);
        if (!is_array($brief) || (int) ($brief['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_brief_missing', 'Brief not found.'); }
        $res = VES_Intelligence_Store::update_brief_status($brief_id, $status, ['reason_code' => $reason_code, 'reason' => $notes, 'action_source' => 'command_room']);
        return self::is_err($res) ? $res : VES_Intelligence_Store::get_brief($brief_id);
    }

    private static function generate_draft(int $brief_id, int $workspace_id, array $args) {
        if (!class_exists('VES_Draft_Service') || !class_exists('VES_Intelligence_Store')) { return self::err('fi_draft_unavailable', 'Draft service unavailable.'); }
        $brief = VES_Intelligence_Store::get_brief($brief_id);
        if (!is_array($brief) || (int) ($brief['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_brief_missing', 'Brief not found.'); }
        if (!in_array((string) ($brief['status'] ?? ''), ['ready','approved','reviewed'], true)) { return self::err('fi_brief_not_ready', 'Brief must be ready or approved before generating a draft.'); }
        return VES_Draft_Service::create_draft_from_brief($brief_id, $args);
    }

    private static function mark_primary(int $draft_id, int $workspace_id, int $run_id) {
        if (!class_exists('VES_Draft_Service') || !class_exists('VES_Intelligence_Store')) { return self::err('fi_draft_unavailable', 'Draft service unavailable.'); }
        $draft = VES_Intelligence_Store::get_draft($draft_id);
        if (!is_array($draft) || (int) ($draft['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_draft_missing', 'Draft not found.'); }
        $res = VES_Draft_Service::mark_primary($draft_id);
        if (!self::is_err($res) && class_exists('VES_Decision_Edge_Service')) { VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'draft', $draft_id, 'draft', $draft_id, 'marked_primary'); }
        return $res;
    }

    private static function save_memory_from_draft(int $draft_id, int $workspace_id, int $run_id, array $args) {
        if (!class_exists('VES_Intelligence_Store')) { return self::err('fi_store_missing', 'Intelligence store unavailable.'); }
        $draft = VES_Intelligence_Store::get_draft($draft_id);
        if (!is_array($draft) || (int) ($draft['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_draft_missing', 'Draft not found.'); }
        $text = trim((string) ($args['memory_text'] ?? ($draft['body'] ?? '')));
        if ($text === '') { return self::err('fi_memory_empty', 'Memory candidate requires draft content or a summary.'); }
        $id = VES_Intelligence_Store::create_memory_record([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'memory_type' => 'draft_summary',
            'title' => 'Memory candidate from draft #' . $draft_id,
            'text' => self::textarea($text, 2000),
            'source_entity_type' => 'draft',
            'source_entity_id' => (string) $draft_id,
            'status' => 'candidate',
            'importance_score' => max(0, min(100, (float) ($args['importance_score'] ?? 50))),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
            'metadata' => ['created_via' => 'command_room', 'memory_is_context_not_evidence' => true],
        ]);
        if (!self::is_err($id) && class_exists('VES_Decision_Edge_Service')) { VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'draft', $draft_id, 'memory_record', (int) $id, 'created_memory', ['metadata' => ['status' => 'candidate']]); }
        return $id;
    }

    private static function record_outcome(string $target_type, int $target_id, int $workspace_id, int $run_id, array $args) {
        if (!class_exists('VES_Outcome_Service')) { return self::err('fi_outcome_unavailable', 'Outcome service unavailable.'); }
        $metrics = [];
        $metric_name = self::key((string) ($args['metric_name'] ?? ''), 80);
        if ($metric_name !== '' && isset($args['metric_value'])) { $metrics[$metric_name] = is_numeric($args['metric_value']) ? (float) $args['metric_value'] : (string) $args['metric_value']; }
        $outcome_id = VES_Outcome_Service::record_outcome([
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'channel' => self::key((string) ($args['channel'] ?? ''), 80),
            'outcome_type' => self::key((string) ($args['outcome_type'] ?? 'manual_note'), 60),
            'result_label' => self::key((string) ($args['result_label'] ?? 'inconclusive'), 40),
            'qualitative_note' => self::textarea((string) ($args['qualitative_note'] ?? ($args['notes'] ?? '')), 5000),
            'metrics' => $metrics,
            'observed_at' => (string) ($args['observed_at'] ?? ''),
            'confidence_score' => isset($args['confidence_score']) ? (float) $args['confidence_score'] : null,
            'source_ref' => self::text((string) ($args['source_ref'] ?? ''), 255),
            'metadata' => ['created_via' => 'command_room'],
        ]);
        return $outcome_id;
    }

    private static function memory_status(int $memory_id, string $status, int $workspace_id, int $run_id, string $reason_code, string $notes, array $extra = []) {
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'update_memory_status')) { return self::err('fi_memory_unavailable', 'Memory update unavailable.'); }
        $mem = VES_Intelligence_Store::get_memory_record($memory_id);
        if (!is_array($mem) || (int) ($mem['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_memory_missing', 'Memory record not found.'); }
        $res = VES_Intelligence_Store::update_memory_status($memory_id, $status, array_merge($extra, ['reason_code' => $reason_code, 'reason' => $notes, 'memory_is_context_not_evidence' => true]));
        if (!self::is_err($res) && class_exists('VES_Decision_Edge_Service')) { VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'memory_record', $memory_id, 'review_decision', $memory_id, 'reviewed_by', ['metadata' => ['status' => $status, 'reason_code' => $reason_code]]); }
        return $res;
    }

    private static function record_review_pattern(string $type, int $id, int $workspace_id, array $review): void {
        if (!class_exists('VES_Pattern_Observation_Service') || !class_exists('VES_Intelligence_Store')) { return; }
        $row = self::get_intel_target($type, $id);
        if (!is_array($row)) { return; }
        $refs = [];
        foreach ((array) ($row['evidence_ids'] ?? []) as $ev) { $refs[] = ['type' => 'evidence', 'id' => (int) $ev]; }
        if (empty($refs)) { $refs[] = ['type' => $type, 'id' => $id]; }
        $decision = (string) ($review['decision'] ?? '');
        VES_Pattern_Observation_Service::record_pattern([
            'workspace_id' => $workspace_id,
            'run_id' => (int) ($row['run_id'] ?? 0),
            'pattern_key' => $type . '_review_' . $decision,
            'platform' => (string) ($row['channel'] ?? 'generic'),
            'content_type' => $type,
            'message_angle' => (string) ($row['angle'] ?? ($row['insight_type'] ?? '')),
            'success_count' => $decision === 'approve' ? 1 : 0,
            'trial_count' => 1,
            'confidence_score' => 0.2,
            'source_refs' => $refs,
            'metadata' => ['review_decision' => $decision, 'reason_code' => (string) ($review['reason_code'] ?? ''), 'low_sample_size' => true],
        ]);
    }

    private static function action_run(int $workspace_id, string $target_type, int $target_id, array $target, string $action, array $args) {
        $run_id = max(0, (int) ($args['run_id'] ?? ($target['run_id'] ?? 0)));
        if ($run_id > 0 && class_exists('VES_Run_Service')) {
            $check = VES_Run_Service::assert_workspace($run_id, $workspace_id);
            if (self::is_err($check)) { return self::err('fi_run_forbidden', 'Run belongs to a different workspace.'); }
            return ['run_id' => $run_id, 'created' => false];
        }
        if (!class_exists('VES_Run_Service')) { return ['run_id' => 0, 'created' => false]; }
        $created = VES_Run_Service::create_run([
            'workspace_id' => $workspace_id,
            'run_type' => 'tool_action',
            'trigger_type' => 'manual',
            'status' => 'running',
            'input_context' => ['action' => $action, 'target_type' => $target_type, 'target_id' => $target_id],
            'initiated_by_user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'idempotency_key' => self::idempotency_key($workspace_id, $target_type, $target_id, $action, $args),
        ]);
        if (self::is_err($created)) { return $created; }
        if (class_exists('VES_Run_Service')) { VES_Run_Service::mark_running((int) $created); }
        return ['run_id' => (int) $created, 'created' => true];
    }

    private static function load_target(string $target_type, int $target_id, int $workspace_id) {
        if ($target_type === 'run') {
            $row = class_exists('VES_Run_Service') ? VES_Run_Service::get_run($target_id) : null;
        } elseif ($target_type === 'memory_record') {
            $row = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::get_memory_record($target_id) : null;
        } else {
            $row = self::get_intel_target($target_type, $target_id);
        }
        if (!is_array($row)) { return self::err('fi_target_not_found', 'Target not found.'); }
        if ((int) ($row['workspace_id'] ?? 0) !== $workspace_id) { return self::err('fi_target_workspace_mismatch', 'Target belongs to a different workspace.'); }
        return $row;
    }

    private static function get_intel_target(string $type, int $id) {
        if (!class_exists('VES_Intelligence_Store')) { return null; }
        $method = 'get_' . $type;
        return method_exists('VES_Intelligence_Store', $method) ? VES_Intelligence_Store::$method($id) : null;
    }

    private static function workspace_id(): int { return class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : (function_exists('get_current_user_id') ? max(1, (int) get_current_user_id()) : 1); }

    private static function capability_for_action(string $action): string {
        if (in_array($action, ['approve_insight','reject_insight','request_insight_revision','archive_insight','approve_brief','request_brief_revision','approve_draft','reject_draft','request_draft_revision','mark_brief_ready'], true)) { return 'can_review_outputs'; }
        if (in_array($action, ['create_brief_from_insight','generate_draft_from_brief','mark_draft_primary'], true)) { return 'can_run_playbook'; }
        if (in_array($action, ['save_memory_candidate','activate_memory','reject_memory','pin_memory','unpin_memory','archive_memory','mark_memory_stale','expire_memory'], true)) { return 'can_manage_memory'; }
        if ($action === 'record_outcome') { return 'can_record_outcome'; }
        return '';
    }

    private static function target_type_for_action(string $action, string $provided): string {
        if (in_array($action, self::INSIGHT_ACTIONS, true)) { return 'insight'; }
        if (in_array($action, self::BRIEF_ACTIONS, true)) { return 'brief'; }
        if (in_array($action, self::DRAFT_ACTIONS, true)) { return self::key($provided, 60) ?: 'draft'; }
        if (in_array($action, self::MEMORY_ACTIONS, true)) { return 'memory_record'; }
        return self::key($provided, 60);
    }

    private static function idempotency_key(int $workspace_id, string $target_type, int $target_id, string $action, array $args): string {
        $raw = (string) ($args['idempotency_key'] ?? '');
        if ($raw !== '') { return $raw; }
        return 'fi-action:' . $workspace_id . ':' . $target_type . ':' . $target_id . ':' . $action . ':' . (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
    }

    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
    private static function key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
}
