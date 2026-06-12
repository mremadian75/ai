<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Signal_Room — Phase 7A overview + Phase 7B operator workflow.
 * READ-ONLY operator console: workflow spine, readiness cards, operator review
 * queues (via VES_Operator_Queue_Service), and a safety/status strip. No fake
 * metrics, no mutation, no AI call. All output escaped. PHP 7.4 compatible.
 */
final class VES_Signal_Room {

    const SPINE = ['source' => 'Source', 'signal' => 'Signal', 'insight' => 'Insight', 'brief' => 'Brief', 'draft' => 'Draft', 'memory' => 'Memory', 'usage' => 'Usage'];

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    /**
     * Single source of truth for the AI-execution flag (Phase 0 alignment):
     * builder-first (option + filter), throw-safe, raw option as the fallback
     * when the builder is unavailable. Identical across Console / Signal Room /
     * RC page so the three surfaces can never disagree.
     */
    private static function execution_enabled_truth() {
        $on = function_exists('get_option') ? (bool) get_option('ves_generation_execution_enabled', false) : false;
        if (class_exists('VES_Generation_Prompt_Package_Builder') && method_exists('VES_Generation_Prompt_Package_Builder', 'execution_enabled')) {
            try { $on = (bool) VES_Generation_Prompt_Package_Builder::execution_enabled(); } catch (\Throwable $e) { /* keep raw option value */ }
        }
        return $on;
    }

    public static function snapshot($workspace_id = 0) {
        $ws = max(0, (int) $workspace_id);
        return [
            'workspace_id' => $ws,
            'counts' => (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'counts')) ? (array) VES_Intelligence_Store::counts($ws) : [],
            'usage' => (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'summary')) ? (array) VES_AI_Usage_Tracker::summary(24) : [],
            'flags' => [
                'memory_generation_enabled' => (class_exists('VES_Generation_Context_Resolver') && method_exists('VES_Generation_Context_Resolver', 'is_enabled')) ? VES_Generation_Context_Resolver::is_enabled() : false,
                'generation_execution_enabled' => self::execution_enabled_truth(),
            ],
            // UI/UX pass 2 — the strip reads the REAL evidence-pack classification
            // instead of a hardcoded 'pending'.
            'live_validation' => (class_exists('VES_RC_Evidence_Pack') && method_exists('VES_RC_Evidence_Pack', 'live_validation_state'))
                ? (array) VES_RC_Evidence_Pack::live_validation_state()
                : ['status' => 'unrun'],
        ];
    }

    public static function render_html($workspace_id = 0) {
        $s = self::snapshot($workspace_id);
        $c = $s['counts'];
        $h = '<div class="fi-signal-room fiis-signal-room">';
        $h .= '<a class="fi-skip-link" href="#fi-operator-queue">' . self::e('Skip to operator queue') . '</a>';
        $h .= '<div class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Signal Room') . '</div>';
        $h .= '<h1 class="fiis-sr-title">' . self::e('Signal Room') . '</h1>';
        $h .= '<p class="fiis-sr-sub">' . self::e('Operator console for the current workspace. Read-only. Evidence first, generation second.') . '</p>';

        // Workflow spine
        $h .= '<nav class="fi-workflow-spine fiis-sr-spine" aria-label="' . self::e('Workflow spine') . '"><ol>';
        $spine_keys = ['source' => 'sources', 'signal' => 'signals', 'insight' => 'insights', 'brief' => 'briefs', 'draft' => 'drafts', 'memory' => 'memory_records', 'usage' => null];
        foreach (self::SPINE as $key => $label) {
            $ck = $spine_keys[$key];
            $val = ($key === 'usage') ? (isset($s['usage']['total']) ? (int) $s['usage']['total'] : null) : ($ck !== null && array_key_exists($ck, $c) ? (int) $c[$ck] : null);
            $h .= '<li class="fiis-sr-step"><span class="fiis-sr-step-label">' . self::e($label) . '</span><span class="fiis-sr-step-count">' . ($val === null ? self::e('—') : self::e((string) $val)) . '</span></li>';
        }
        $h .= '</ol></nav>';

        // Readiness cards
        $cards = [
            'Sources' => array_key_exists('sources', $c) ? (int) $c['sources'] : null,
            'Signals' => array_key_exists('signals', $c) ? (int) $c['signals'] : null,
            'Insights' => array_key_exists('insights', $c) ? (int) $c['insights'] : null,
            'Briefs' => array_key_exists('briefs', $c) ? (int) $c['briefs'] : null,
            'Drafts' => array_key_exists('drafts', $c) ? (int) $c['drafts'] : null,
            'Memory' => array_key_exists('memory_records', $c) ? (int) $c['memory_records'] : null,
            'Usage (24h)' => isset($s['usage']['total']) ? (int) $s['usage']['total'] : null,
        ];
        $h .= '<div class="fiis-sr-cards">';
        foreach ($cards as $label => $val) {
            $h .= '<div class="fiis-sr-card fi-readiness-panel"><div class="fiis-sr-card-k">' . self::e($label) . '</div><div class="fiis-sr-card-v">' . ($val === null ? self::e('Not available') : self::e((string) $val)) . '</div></div>';
        }
        $h .= '</div>';

        // ── Operator queue (7B) ────────────────────────────────────────────────
        $h .= '<h2 id="fi-operator-queue" class="fiis-sr-h2">' . self::e('Operator queue') . '</h2>';
        $h .= '<div class="fi-operator-queue">';
        if (class_exists('VES_Operator_Queue_Service')) {
            $q = VES_Operator_Queue_Service::build(['workspace_id' => (int) $workspace_id, 'limit' => 8]);
            $labels = [
                'insights_needing_review' => 'Insights needing review',
                'low_evidence_insights' => 'Evidence-limited insights',
                'briefs_needing_review' => 'Briefs to review',
                'drafts_needing_review' => 'Drafts to review',
                'candidate_memory' => 'Candidate memory awaiting approval',
                'prompt_packages_blocked' => 'Blocked prompt packages',
                'missing_usage' => 'Usage missing (24h)',
                'recent_activity' => 'Recent activity',
            ];
            foreach ($labels as $key => $label) {
                $qd = isset($q['queues'][$key]) ? $q['queues'][$key] : ['available' => false];
                $h .= '<div class="fi-operator-queue-card">';
                $h .= '<div class="fi-queue-head"><span class="fi-queue-label">' . self::e($label) . '</span>';
                if (!empty($qd['available'])) {
                    $sev = ((int) ($qd['count'] ?? 0) > 0) ? 'needs_review' : 'ready';
                    $h .= ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($sev, (string) (int) ($qd['count'] ?? 0)) : self::e((string) ($qd['count'] ?? 0)));
                } else {
                    $h .= ' <span class="fi-status-badge fiis-badge-disabled">' . self::e('not available') . '</span>';
                }
                $h .= '</div>';
                if (!empty($qd['available']) && !empty($qd['items'])) {
                    $h .= '<ul class="fi-queue-items">';
                    foreach (array_slice((array) $qd['items'], 0, 5) as $it) {
                        $line = isset($it['summary']) ? (string) $it['summary'] : (isset($it['message']) ? (string) $it['message'] : (isset($it['type']) ? (string) $it['type'] : ''));
                        $h .= '<li><code>' . self::e((string) ($it['type'] ?? ($it['component'] ?? ''))) . '</code> ' . self::e($line) . '</li>';
                    }
                    $h .= '</ul>';
                } elseif (!empty($qd['available'])) {
                    $hints = [
                        'insights_needing_review' => 'Approved trend runs propose insights here.',
                        'low_evidence_insights' => 'Insights whose evidence weakened on reassessment surface here.',
                        'briefs_needing_review' => 'Approve an insight to stage its brief for review.',
                        'drafts_needing_review' => 'Approve a brief to stage its draft for review.',
                        'candidate_memory' => 'Signal reports propose memory candidates here — nothing enters trusted context without approval.',
                        'prompt_packages_blocked' => 'Blocked dry-run packages appear here with their blocking reason.',
                        'missing_usage' => 'Runs that produced no usage event in the last 24h surface here.',
                        'recent_activity' => 'Run log entries stream here as sources execute.',
                    ];
                    $hint = (int) ($qd['count'] ?? 0) > 0 ? '' : (isset($hints[$key]) ? ' <span class="fi-queue-hint">' . self::e($hints[$key]) . '</span>' : '');
                    $h .= '<p class="fi-empty-state">' . self::e((int) ($qd['count'] ?? 0) > 0 ? ((string) (int) $qd['count'] . ' item(s) — open the ledger to review.') : 'No records yet.') . $hint . '</p>';
                } else {
                    $h .= '<p class="fi-empty-state">' . self::e((string) ($qd['note'] ?? 'Not available.')) . '</p>';
                }
                $h .= '</div>';
            }
        } else {
            $h .= '<p class="fi-empty-state">' . self::e('Operator queue service unavailable.') . '</p>';
        }
        $h .= '</div>';

        // Safety / status strip
        $h .= '<div class="fi-readiness-panel fiis-sr-strip">';
        $h .= self::chip('Evidence Gate', 'active', 'ready');
        $h .= self::chip('Memory generation flag', $s['flags']['memory_generation_enabled'] ? 'enabled' : 'disabled', $s['flags']['memory_generation_enabled'] ? 'warning' : 'disabled');
        $exec_on = !empty($s['flags']['generation_execution_enabled']);
        $h .= self::chip('Generation execution', $exec_on ? 'ENABLED' : 'disabled', $exec_on ? 'warning' : 'disabled');
        $lv = is_array($s['live_validation'] ?? null) ? $s['live_validation'] : ['status' => 'unrun'];
        $lv_map = [
            'passed' => ['passed (file-backed)', 'recorded'],
            'failed' => ['FAILED', 'blocked'],
            'json_only_unverified' => ['json-only — unverified', 'warning'],
            'unverified_manual' => ['manual — unverified', 'warning'],
            'unrun' => ['UNRUN', 'warning'],
            'unknown_error' => ['unreadable record — treat as not validated', 'blocked'],
        ];
        $lv_chip = $lv_map[(string) ($lv['status'] ?? 'unrun')] ?? $lv_map['unrun'];
        $h .= self::chip('Live staging validation', $lv_chip[0], $lv_chip[1]);
        $h .= self::chip('Usage ledger (24h)', isset($s['usage']['total']) ? ((int) $s['usage']['total'] . ' events') : 'not available', isset($s['usage']['total']) ? 'recorded' : 'disabled');
        $h .= '</div>';

        $lv_status = (string) ($lv['status'] ?? 'unrun');
        $lv_note = $lv_status === 'passed'
            ? 'Live staging validation recorded (file-backed) — still not production-ready without operator approval and a monitored pilot.'
            : 'Live staging validation ' . ($lv_status === 'unrun' ? 'UNRUN' : ($lv_status === 'failed' ? 'FAILED' : 'unverified')) . ' — not production-ready.';
        $h .= '<p class="fi-memory-policy fiis-sr-note">' . self::e('Read-only console. No generation runs here. Memory is not evidence. ' . $lv_note) . '</p>';
        $h .= '</div>';
        return $h;
    }

    private static function chip($label, $value, $state) {
        $badge = class_exists('VES_Review_State') ? VES_Review_State::badge($state, $value) : self::e($value);
        return '<span class="fiis-sr-chip"><span class="fiis-chip-k">' . self::e($label) . '</span>' . $badge . '</span>';
    }
}
