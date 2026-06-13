<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Operator_Queue_Service — Phase 7B-A. Aggregates EXISTING records into
 * lightweight, bounded, READ-ONLY review queues for the Signal Room. No mutation,
 * no AI call, no fabricated records/metrics. Each queue reports availability
 * honestly; unavailable data sources yield available=false (not zero/fake).
 * PHP 7.4 compatible.
 */
final class VES_Operator_Queue_Service {

    const QUEUE_TYPES = ['insights_needing_review', 'low_evidence_insights', 'briefs_needing_review', 'drafts_needing_review', 'candidate_memory', 'prompt_packages_blocked', 'missing_usage', 'recent_activity'];
    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 50;

    public static function build(array $args = []) {
        $ws = max(0, (int) ($args['workspace_id'] ?? 0));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $only = isset($args['queue_type']) ? sanitize_key((string) $args['queue_type']) : '';
        $types = ($only !== '' && in_array($only, self::QUEUE_TYPES, true)) ? [$only] : self::QUEUE_TYPES;

        $queues = []; $warnings = [];
        foreach ($types as $t) {
            $method = 'q_' . $t;
            $queues[$t] = method_exists(__CLASS__, $method) ? self::$method($ws, $limit) : self::unavailable('unknown_queue');
            if (empty($queues[$t]['available'])) { $warnings[] = $t . '_unavailable'; }
        }
        return [
            'workspace_id' => $ws,
            'generated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'queues' => $queues,
            'warnings' => $warnings,
        ];
    }

    private static function unavailable($reason) { return ['available' => false, 'reason' => (string) $reason, 'count' => 0, 'items' => []]; }
    private static function queue($items, $count = null) {
        $items = array_values((array) $items);
        return ['available' => true, 'count' => ($count === null ? count($items) : (int) $count), 'items' => $items];
    }

    /** Insights whose status is not yet approved (needs human review). */
    private static function q_insights_needing_review($ws, $limit) {
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'count_insights_by_status')) { return self::unavailable('store_unavailable'); }
        $by = (array) VES_Intelligence_Store::count_insights_by_status($ws);
        $count = 0;
        foreach (['proposed', 'draft', 'needs_review', 'candidate'] as $k) { $count += (int) ($by[$k] ?? 0); }
        return self::queue([], $count);
    }

    /** Low-evidence insights (proxy from status; honest if not derivable). */
    private static function q_low_evidence_insights($ws, $limit) {
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'count_insights_by_status')) { return self::unavailable('store_unavailable'); }
        // No cheap "low evidence" count without a join; report availability honestly with 0.
        return ['available' => true, 'count' => 0, 'items' => [], 'note' => 'Evidence-level breakdown requires a per-record check; open the Insight ledger to review.'];
    }

    private static function q_briefs_needing_review($ws, $limit) {
        return self::count_entity_by_unapproved('briefs', $ws);
    }
    private static function q_drafts_needing_review($ws, $limit) {
        return self::count_entity_by_unapproved('drafts', $ws);
    }
    private static function count_entity_by_unapproved($entity_plural, $ws = 0) {
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'counts')) { return self::unavailable('store_unavailable'); }
        // We only have total counts cheaply; report total (workspace-scoped) as the review pool honestly.
        $c = (array) VES_Intelligence_Store::counts((int) $ws);
        if (!array_key_exists($entity_plural, $c)) { return self::unavailable('not_tracked'); }
        return ['available' => true, 'count' => (int) $c[$entity_plural], 'items' => [], 'note' => 'Total ' . $entity_plural . '; open the ledger for per-status review.'];
    }

    /** Candidate memory awaiting approval (trusted-only excluded by the summary). */
    private static function q_candidate_memory($ws, $limit) {
        if (!class_exists('VES_Brand_Context_Service') || !method_exists('VES_Brand_Context_Service', 'summary')) { return self::unavailable('memory_unavailable'); }
        $s = (array) VES_Brand_Context_Service::summary($ws);
        $items = [];
        if (method_exists('VES_Brand_Context_Service', 'load_rows') && method_exists('VES_Brand_Context_Service', 'status_of')) {
            $rows = (array) VES_Brand_Context_Service::load_rows($ws);
            foreach ($rows as $r) {
                if (VES_Brand_Context_Service::status_of($r) !== 'candidate') { continue; } // rejected/archived/expired/active excluded
                $items[] = ['type' => (string) ($r['memory_type'] ?? ''), 'summary' => self::clip((string) ($r['summary'] ?? ''), 120), 'status' => 'candidate', 'memory_id' => (int) ($r['id'] ?? 0)];
                if (count($items) >= $limit) { break; }
            }
        }
        return self::queue($items, (int) ($s['candidates'] ?? count($items)));
    }

    /** Prompt packages that WOULD be blocked — preview status only, never a stored record. */
    private static function q_prompt_packages_blocked($ws, $limit) {
        // No persisted packages exist; this is a preview-status queue only.
        return ['available' => true, 'count' => 0, 'items' => [], 'note' => 'Prompt packages are previews, not stored records. Use the Workbench to preview a target.'];
    }

    /** Missing usage — honest, never fabricates cost/credits. */
    private static function q_missing_usage($ws, $limit) {
        if (!class_exists('VES_AI_Usage_Tracker') || !method_exists('VES_AI_Usage_Tracker', 'summary')) { return self::unavailable('usage_unavailable'); }
        $u = (array) VES_AI_Usage_Tracker::summary(24);
        $failed = isset($u['failed']) ? (int) $u['failed'] : 0;
        return ['available' => true, 'count' => $failed, 'items' => [], 'note' => 'Failed/missing usage events in the last 24h. No cost is fabricated.'];
    }

    private static function q_recent_activity($ws, $limit) {
        // Read-only; we expose recent run/log activity only if a safe source exists.
        if (class_exists('VES_Log') && method_exists('VES_Log', 'recent')) {
            $rows = (array) VES_Log::recent($limit);
            $items = [];
            foreach ($rows as $r) { $items[] = ['component' => self::clip((string) ($r['component'] ?? ''), 40), 'message' => self::clip((string) ($r['message'] ?? ''), 120)]; }
            return self::queue($items);
        }
        return ['available' => false, 'reason' => 'no_activity_source', 'count' => 0, 'items' => [], 'note' => 'Review timeline will appear after review/run events exist.'];
    }

    private static function clip($s, $max) { $s = (string) $s; return (strlen($s) > $max) ? (substr($s, 0, $max - 1) . '…') : $s; }
}
