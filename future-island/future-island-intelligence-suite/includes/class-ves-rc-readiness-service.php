<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_RC_Readiness_Service — v0.1 Release Candidate whole-product readiness.
 *
 * READ-ONLY: inspects schema, loaded services, safety gates, feature flags and
 * the recorded live-validation state, and derives an overall RC status. It never
 * mutates data, never calls a provider, and never prints secrets. "Ready" here
 * always means ready FOR STAGING REVIEW — this service can never report a
 * production-ready state: production_ready is hard-coded false until a human
 * operator runs the live staging checklist and signs off outside this tool.
 * PHP 7.4 compatible.
 */
final class VES_RC_Readiness_Service {

    const STATUS_READY   = 'ready_for_staging';
    const STATUS_WARN    = 'ready_with_warnings';
    const STATUS_BLOCKED = 'blocked';

    /**
     * Operators may record a completed live staging run via
     * `wp option update ves_rc_live_validation '{"status":"passed",...}' --format=json`
     * AFTER actually completing LIVE-STAGING-VALIDATION-CHECKLIST.md. This service
     * only ever READS the option.
     */
    const OPTION_LIVE_VALIDATION = 'ves_rc_live_validation';

    /** Services the v0.1 core loop cannot function without. */
    const REQUIRED_SERVICES = [
        'VES_Intelligence_Store'                  => 'Intelligence data contract store',
        'VES_Insight_Evidence_Validator'          => 'Evidence gate validator',
        'VES_Insight_Lifecycle_Service'           => 'Insight review lifecycle',
        'VES_Brand_Context_Service'               => 'Memory / brand context trust boundary',
        'VES_Generation_Context_Resolver'         => 'Generation context resolver',
        'VES_Generation_Prompt_Package_Builder'   => 'Prompt package builder',
        'VES_Operator_Queue_Service'              => 'Operator queue',
        'VES_Signal_Room'                         => 'Signal Room UI service',
        'VES_Workbench'                           => 'Brief / Draft workbench',
        'VES_Review_State'                        => 'Canonical review states',
        'VES_AI_Usage_Tracker'                    => 'AI usage telemetry',
        'VES_Usage_Billing'                       => 'Usage / credits ledger',
        'VES_Apify_Client'                        => 'Provider client (Apify)',
        'VES_Apify_Actor_Registry'                => 'Actor registry / allowlist',
        'VES_Trend_Observation_Store'             => 'Trend observation store',
        'VES_Staging_Validation_Service'          => 'Staging validation service',
        'VES_Workspace_Guard'                     => 'Workspace/tenant isolation guard',
        'VES_Review_Decision_Ledger'              => 'Review decision ledger',
        'VES_Usage_Settlement'                    => 'Usage settlement semantics',
        'VES_Security_Event_Log'                  => 'Security event log',
        'VES_Job_Rails'                           => 'Queue retry/dead-letter rails',
        'VES_RC_Evidence_Pack'                    => 'Release evidence pack',
    ];

    /** WP-CLI commands an operator needs for the staging checklist. */
    const REQUIRED_CLI = [
        'ves verify-schema', 'ves validate-staging', 'ves readiness-check',
        'ves rc-readiness-check', 'ves memory-summary', 'ves memory-context-preview',
        'ves generation-context-preview', 'ves generation-prompt-preview',
        'ves operator-queue', 'ves memory-expire', 'ves trend-evaluate',
        'ves trend-summary', 'ves trend-obs-backfill',
    ];

    const STATUS_PILOT = 'ready_for_pilot_review';

    /** Build the full readiness report. Read-only. $args: ['strict' => bool] */
    public static function report(array $args = []) {
        $strict = !empty($args['strict']);
        $checks = [];
        $checks[] = self::check_version();
        $checks[] = self::check_schema();
        $checks[] = self::check_core_services();
        $checks[] = self::check_evidence_gate();
        $checks[] = self::check_lifecycle_matrix();
        $checks[] = self::check_memory_trust_boundary();
        $checks[] = self::check_prompt_package();
        $checks[] = self::check_operator_surfaces();
        $checks[] = self::check_usage_ledger();
        $checks[] = self::check_provider_safety();
        $checks[] = self::check_workspace_guard();
        $checks[] = self::check_review_ledger();
        $checks[] = self::check_settlement();
        $checks[] = self::check_trend_idempotency();
        $checks[] = self::check_job_rails();
        $checks[] = self::check_security_log();
        $checks[] = self::check_feature_flags();
        $checks[] = self::check_live_validation();

        $blockers = []; $warnings = [];
        foreach ($checks as $c) {
            if (($c['status'] ?? '') === 'block') { $blockers[] = (string) $c['detail']; }
            if (($c['status'] ?? '') === 'warn')  { $warnings[] = (string) $c['detail']; }
        }
        $status = self::STATUS_READY;
        if (count($warnings) > 0) { $status = self::STATUS_WARN; }
        if (count($blockers) > 0) { $status = self::STATUS_BLOCKED; }

        // Phase 9C.4 — strict mode: blocked unless live validation passed through a
        // verified evidence pack AND every hard rail is active AND nothing needs
        // settlement. A strict pass is ready_for_pilot_review — NEVER production.
        if ($strict) {
            $live = self::live_validation_state();
            if (($live['status'] ?? '') !== 'passed' || empty($live['evidence_pack_hash'])) {
                $blockers[] = 'STRICT: live validation has not passed with a verified evidence pack (state: ' . (string) ($live['status'] ?? 'unrun') . ').';
            }
            foreach ($checks as $c) {
                if (in_array($c['id'], ['provider_safety', 'workspace_guard', 'review_ledger', 'lifecycle_matrix', 'trend_idempotency', 'job_rails'], true) && ($c['status'] ?? '') !== 'ok') {
                    $blockers[] = 'STRICT: hard rail not fully active: ' . (string) $c['label'] . ' — ' . (string) $c['detail'];
                }
            }
            if (class_exists('VES_Usage_Settlement') && method_exists('VES_Usage_Settlement', 'settlement_health')) {
                $sh = VES_Usage_Settlement::settlement_health();
                if (!empty($sh['settlement_required']) || !empty($sh['reserved_stale'])) {
                    $blockers[] = 'STRICT: ' . (int) $sh['settlement_required'] . ' settlement_required marker(s) and ' . (int) $sh['reserved_stale'] . ' stale reservation(s) must be resolved.';
                }
            }
            $status = count($blockers) > 0 ? self::STATUS_BLOCKED : self::STATUS_PILOT;
        }

        return [
            'status'           => $status,
            'strict'           => $strict,
            'production_ready' => false, // hard-coded: v0.1 RC can never self-certify production
            'production_note'  => 'Production readiness requires a passed live staging validation, operator approval and monitored pilot usage. This command cannot grant it.',
            'live_validation'  => self::live_validation_state(),
            'blockers'         => $blockers,
            'warnings'         => $warnings,
            'checks'           => $checks,
            'plugin_version'   => defined('FIS_VERSION') ? FIS_VERSION : 'unknown',
            'rc_label'         => defined('FIS_RC_LABEL') ? FIS_RC_LABEL : '',
            'required_cli'     => self::REQUIRED_CLI,
            'checked_at'       => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
    }

    // ── individual checks (each returns id/label/status ok|warn|block/detail) ──

    private static function check_version() {
        $ok = defined('FIS_VERSION') && FIS_VERSION !== '';
        return self::row('plugin_version', 'Plugin version constant', $ok ? 'ok' : 'block',
            $ok ? ('FIS_VERSION ' . FIS_VERSION . (defined('FIS_RC_LABEL') ? ' (' . FIS_RC_LABEL . ')' : '')) : 'FIS_VERSION is not defined — bootstrap did not run.');
    }

    private static function check_schema() {
        if (!class_exists('VES_Staging_Validation_Service')) {
            return self::row('schema', 'Database schema', 'block', 'VES_Staging_Validation_Service missing — schema state unknown.');
        }
        $s = VES_Staging_Validation_Service::schema_health();
        $status = (string) ($s['status'] ?? 'ok');
        if ($status === 'error') { return self::row('schema', 'Database schema', 'block', 'Schema is missing required tables. Run: wp ves verify-schema --repair'); }
        if ($status === 'warning' || empty($s['available'])) { return self::row('schema', 'Database schema', 'warn', 'Schema has missing columns or could not be fully verified. Run: wp ves verify-schema'); }
        return self::row('schema', 'Database schema', 'ok', 'All required tables/columns verified.');
    }

    private static function check_core_services() {
        $missing = [];
        foreach (self::REQUIRED_SERVICES as $class => $label) {
            if (!class_exists($class)) { $missing[] = $class; }
        }
        if (count($missing) > 0) {
            return self::row('core_services', 'Core services loaded', 'block', 'Missing core service(s): ' . implode(', ', $missing));
        }
        return self::row('core_services', 'Core services loaded', 'ok', count(self::REQUIRED_SERVICES) . ' core services present.');
    }

    private static function check_evidence_gate() {
        if (!class_exists('VES_Insight_Evidence_Validator') || !method_exists('VES_Insight_Evidence_Validator', 'validate_insight')) {
            return self::row('evidence_gate', 'Evidence gate', 'block', 'VES_Insight_Evidence_Validator unavailable.');
        }
        // Pure probe: an insight with no evidence must NOT meet minimum requirements.
        $verdict = null;
        try {
            $verdict = VES_Insight_Evidence_Validator::validate_insight(
                ['id' => 0, 'title' => 'probe', 'evidence_ids' => []],
                ['insight' => ['id' => 0, 'evidence_ids' => []], 'evidence' => [], 'sources' => [], 'trend_record' => []]
            );
        } catch (\Throwable $e) {
            return self::row('evidence_gate', 'Evidence gate', 'warn', 'Evidence validator probe failed to run: ' . $e->getMessage());
        }
        $met = is_array($verdict) ? !empty($verdict['minimum_requirements_met']) : true;
        if ($met) { return self::row('evidence_gate', 'Evidence gate', 'block', 'Evidence gate FAILED probe: zero-evidence insight passed minimum requirements.'); }
        return self::row('evidence_gate', 'Evidence gate', 'ok', 'Zero-evidence insight is blocked by the validator.');
    }

    private static function check_lifecycle_matrix() {
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'insight_transition_matrix_active')) {
            return self::row('lifecycle_matrix', 'Lifecycle transition matrix', 'block', 'Insight transition matrix is not compiled in.');
        }
        $active = false;
        try { $active = (bool) VES_Intelligence_Store::insight_transition_matrix_active(); } catch (\Throwable $e) { $active = false; }
        return $active
            ? self::row('lifecycle_matrix', 'Lifecycle transition matrix', 'ok', 'Terminal states protected; rejected/archived cannot silently become approved.')
            : self::row('lifecycle_matrix', 'Lifecycle transition matrix', 'block', 'Transition matrix probe failed — terminal states are not protected.');
    }

    private static function check_memory_trust_boundary() {
        if (!class_exists('VES_Brand_Context_Service') || !method_exists('VES_Brand_Context_Service', 'is_trusted')) {
            return self::row('memory_trust', 'Memory trust boundary', 'block', 'VES_Brand_Context_Service unavailable.');
        }
        $violations = [];
        try {
            $cases = [
                'candidate' => ['status' => 'candidate'],
                'rejected'  => ['status' => 'rejected'],
                'archived'  => ['status' => 'archived'],
                'expired'   => ['status' => 'active', 'expires_at' => '2000-01-01 00:00:00'],
                'pinned_rejected' => ['status' => 'rejected', 'is_pinned' => 1],
                'missing_status'  => [],
            ];
            foreach ($cases as $name => $row) {
                $row += ['id' => 0, 'workspace_id' => 0, 'record_type' => 'brand', 'content_json' => '{}'];
                if (VES_Brand_Context_Service::is_trusted($row)) { $violations[] = $name; }
            }
        } catch (\Throwable $e) {
            return self::row('memory_trust', 'Memory trust boundary', 'warn', 'Trust boundary probe failed to run: ' . $e->getMessage());
        }
        if (count($violations) > 0) {
            return self::row('memory_trust', 'Memory trust boundary', 'block', 'Untrusted memory treated as trusted: ' . implode(', ', $violations));
        }
        return self::row('memory_trust', 'Memory trust boundary', 'ok', 'candidate/rejected/archived/expired/pinned-rejected/missing-status all excluded.');
    }

    private static function check_prompt_package() {
        if (!class_exists('VES_Generation_Prompt_Package_Builder')) {
            return self::row('prompt_package', 'Prompt package builder', 'block', 'VES_Generation_Prompt_Package_Builder unavailable.');
        }
        $exec_on = false;
        try { $exec_on = (bool) VES_Generation_Prompt_Package_Builder::execution_enabled(); } catch (\Throwable $e) { $exec_on = false; }
        if ($exec_on) {
            return self::row('prompt_package', 'Prompt package builder', 'warn', 'Generation execution flag (ves_generation_execution_enabled) is ON — v0.1 RC expects it OFF.');
        }
        return self::row('prompt_package', 'Prompt package builder', 'ok', 'Builder present; provider execution disabled (default).');
    }

    private static function check_operator_surfaces() {
        $missing = [];
        if (!class_exists('VES_Signal_Room') || !method_exists('VES_Signal_Room', 'render_html')) { $missing[] = 'Signal Room'; }
        if (!class_exists('VES_Operator_Queue_Service') || !method_exists('VES_Operator_Queue_Service', 'build')) { $missing[] = 'Operator queue'; }
        if (!class_exists('VES_Workbench') || !method_exists('VES_Workbench', 'render_brief') || !method_exists('VES_Workbench', 'render_draft')) { $missing[] = 'Workbench'; }
        if (!class_exists('VES_Release_Candidate_Page')) { $missing[] = 'Release Candidate page'; }
        if (count($missing) > 0) {
            return self::row('operator_ui', 'Operator UI surfaces', 'block', 'Missing surface(s): ' . implode(', ', $missing));
        }
        return self::row('operator_ui', 'Operator UI surfaces', 'ok', 'Signal Room, operator queue, workbenches and RC page available.');
    }

    private static function check_usage_ledger() {
        $ok = class_exists('VES_Usage_Billing')
            && method_exists('VES_Usage_Billing', 'reserve_usage')
            && method_exists('VES_Usage_Billing', 'settle_reserved_usage')
            && method_exists('VES_Usage_Billing', 'void_reserved_usage');
        return $ok
            ? self::row('usage_ledger', 'Usage / credits ledger', 'ok', 'Reserve / settle / void ledger model present.')
            : self::row('usage_ledger', 'Usage / credits ledger', 'block', 'Ledger-style usage billing (reserve/settle/void) unavailable.');
    }

    private static function check_provider_safety() {
        $problems = []; $warns = [];
        if (!class_exists('VES_Apify_Actor_Registry') || !method_exists('VES_Apify_Actor_Registry', 'is_allowed_slug')) {
            $problems[] = 'actor allowlist gate missing';
        }
        if (class_exists('VES_Config') && method_exists('VES_Config', 'hard_max_charge_usd')) {
            $ceiling = (float) VES_Config::hard_max_charge_usd();
            if ($ceiling <= 0) { $problems[] = 'maxTotalChargeUsd ceiling disabled'; }
        } else {
            $problems[] = 'hard max charge config missing';
        }
        // Phase 9A.1/9A.2 — fail-closed + hard-ceiling enforcement must be compiled in.
        if (class_exists('VES_Apify_Client')) {
            if (!defined('VES_Apify_Client::MIN_CHARGE_CEILING_USD') || !defined('VES_Apify_Client::MAX_CHARGE_CEILING_USD')) {
                $problems[] = 'hard charge-ceiling enforcement not compiled in';
            }
        } else {
            $problems[] = 'provider client missing';
        }
        if (defined('VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY') && VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY) {
            $warns[] = 'UNSAFE local-tests-only dispatch bypass constant is defined TRUE on this install';
        }
        // Token presence is reported as a boolean only — never the value.
        $token_configured = class_exists('VES_Config') && method_exists('VES_Config', 'get_token') && trim((string) VES_Config::get_token()) !== '';
        if (!$token_configured) { $warns[] = 'provider token not configured (dispatch will fail fast; fine for a static RC)'; }
        if (count($problems) > 0) { return self::row('provider_safety', 'Provider safety', 'block', implode('; ', $problems)); }
        if (count($warns) > 0) { return self::row('provider_safety', 'Provider safety', 'warn', implode('; ', $warns)); }
        return self::row('provider_safety', 'Provider safety', 'ok', 'Fail-closed allowlist + hard charge ceiling active; token via Authorization header only.');
    }

    private static function check_workspace_guard() {
        if (!class_exists('VES_Workspace_Guard') || !method_exists('VES_Workspace_Guard', 'guard_active')) {
            return self::row('workspace_guard', 'Workspace isolation guard', 'block', 'VES_Workspace_Guard unavailable.');
        }
        $active = false;
        try { $active = (bool) VES_Workspace_Guard::guard_active(); } catch (\Throwable $e) { $active = false; }
        return $active
            ? self::row('workspace_guard', 'Workspace isolation guard', 'ok', 'Cross-workspace and unknown-workspace access refused.')
            : self::row('workspace_guard', 'Workspace isolation guard', 'block', 'Workspace guard probe FAILED — cross-workspace access is not being refused.');
    }

    private static function check_review_ledger() {
        if (!class_exists('VES_Review_Decision_Ledger') || !method_exists('VES_Review_Decision_Ledger', 'ledger_active')) {
            return self::row('review_ledger', 'Review decision ledger', 'block', 'VES_Review_Decision_Ledger unavailable.');
        }
        $active = false;
        try { $active = (bool) VES_Review_Decision_Ledger::ledger_active(); } catch (\Throwable $e) { $active = false; }
        return $active
            ? self::row('review_ledger', 'Review decision ledger', 'ok', 'Append-only decision ledger active (no update/delete API).')
            : self::row('review_ledger', 'Review decision ledger', 'block', 'Review ledger probe failed.');
    }

    private static function check_settlement() {
        if (!class_exists('VES_Usage_Settlement') || !method_exists('VES_Usage_Settlement', 'settlement_health')) {
            return self::row('usage_settlement', 'Usage settlement', 'block', 'VES_Usage_Settlement unavailable.');
        }
        $sem = false; $health = ['settlement_required' => 0, 'reserved_stale' => 0];
        try {
            $sem = (bool) VES_Usage_Settlement::semantics_active();
            $health = VES_Usage_Settlement::settlement_health();
        } catch (\Throwable $e) { $sem = false; }
        if (!$sem) { return self::row('usage_settlement', 'Usage settlement', 'block', 'Settlement state taxonomy probe failed.'); }
        $required = (int) ($health['settlement_required'] ?? 0);
        $stale = (int) ($health['reserved_stale'] ?? 0);
        if ($required > 0 || $stale > 0) {
            return self::row('usage_settlement', 'Usage settlement', 'warn', $required . ' settlement_required marker(s), ' . $stale . ' stale reservation(s) — resolve before pilot.');
        }
        return self::row('usage_settlement', 'Usage settlement', 'ok', 'Reserve/complete/fail/void semantics active; nothing awaiting settlement.');
    }

    private static function check_trend_idempotency() {
        if (!class_exists('VES_Trend_Observation_Store')) {
            return self::row('trend_idempotency', 'Trend idempotency', 'block', 'VES_Trend_Observation_Store unavailable.');
        }
        if (!method_exists('VES_Trend_Observation_Store', 'idempotency_migration_report')) {
            return self::row('trend_idempotency', 'Trend idempotency', 'block', 'DB-level idempotency (ws_canonical unique index + race-safe insert) is not compiled in.');
        }
        $report = ['unique_index_present' => false, 'duplicate_groups' => 0];
        try { $report = VES_Trend_Observation_Store::idempotency_migration_report(); } catch (\Throwable $e) { /* report stays pessimistic */ }
        if (!empty($report['unique_index_present'])) {
            return self::row('trend_idempotency', 'Trend idempotency', 'ok', 'ws_canonical unique index live; duplicate inserts resolve to the existing row.');
        }
        $dupes = (int) ($report['duplicate_groups'] ?? 0);
        return self::row('trend_idempotency', 'Trend idempotency', 'warn',
            $dupes > 0
                ? 'Unique index not yet live: ' . $dupes . ' duplicate group(s) must be consolidated first (application-level dedup remains active).'
                : 'Unique index not yet live (run migrations / wp ves verify-schema --repair); application-level dedup remains active.');
    }

    private static function check_job_rails() {
        if (!class_exists('VES_Job_Rails') || !method_exists('VES_Job_Rails', 'status')) {
            return self::row('job_rails', 'Queue dead-letter rails', 'block', 'VES_Job_Rails unavailable.');
        }
        $status = ['available' => false, 'dead_letters' => 0];
        try { $status = VES_Job_Rails::status(); } catch (\Throwable $e) { /* pessimistic */ }
        if (empty($status['available'])) { return self::row('job_rails', 'Queue dead-letter rails', 'block', 'Dead-letter status unavailable.'); }
        $dead = (int) ($status['dead_letters'] ?? 0);
        return $dead > 0
            ? self::row('job_rails', 'Queue dead-letter rails', 'warn', $dead . ' dead-lettered job(s) need operator review.')
            : self::row('job_rails', 'Queue dead-letter rails', 'ok', 'Retry caps active; no dead-lettered jobs.');
    }

    private static function check_security_log() {
        if (!class_exists('VES_Security_Event_Log') || !method_exists('VES_Security_Event_Log', 'summary')) {
            return self::row('security_log', 'Security event log', 'warn', 'VES_Security_Event_Log unavailable — guardrail trips are not being recorded.');
        }
        $summary = ['total' => 0];
        try { $summary = VES_Security_Event_Log::summary(); } catch (\Throwable $e) { /* pessimistic zero */ }
        return self::row('security_log', 'Security event log', 'ok', 'Append-only scrubbed log active (' . (int) ($summary['total'] ?? 0) . ' event(s) recorded).');
    }

    private static function check_feature_flags() {
        $on = [];
        if (function_exists('get_option') && (bool) get_option('ves_generation_execution_enabled', false)) { $on[] = 'ves_generation_execution_enabled'; }
        if (defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP) { $on[] = 'VES_PRODUCTION_MVP'; }
        if (defined('VES_ENABLE_DEEP_VIDEO_ANALYSIS') && VES_ENABLE_DEEP_VIDEO_ANALYSIS) { $on[] = 'VES_ENABLE_DEEP_VIDEO_ANALYSIS'; }
        if (defined('FI_DTF_ENABLE_DEEP_VIDEO') && FI_DTF_ENABLE_DEEP_VIDEO) { $on[] = 'FI_DTF_ENABLE_DEEP_VIDEO'; }
        if (count($on) > 0) {
            return self::row('feature_flags', 'Feature flags', 'warn', 'Flags ON that v0.1 RC expects OFF: ' . implode(', ', $on));
        }
        return self::row('feature_flags', 'Feature flags', 'ok', 'All gated features OFF by default (AI execution, MVP mode, deep video).');
    }

    private static function check_live_validation() {
        $state = self::live_validation_state();
        $status = (string) ($state['status'] ?? 'unrun');
        if ($status === 'passed' && !empty($state['evidence_pack_hash'])) {
            return self::row('live_validation', 'Live staging validation', 'ok',
                'Passed via verified evidence pack ' . substr((string) $state['evidence_pack_hash'], 0, 12) . '… at ' . (string) ($state['recorded_at'] ?? 'unknown time') . '.');
        }
        if ($status === 'unverified_manual') {
            return self::row('live_validation', 'Live staging validation', 'warn',
                'A manually-written validation option exists WITHOUT a verifiable evidence pack — treated as UNVERIFIED, not passed.');
        }
        return self::row('live_validation', 'Live staging validation', 'warn',
            'UNRUN / not recorded. The RC is statically verified only — production claims are forbidden until the live checklist passes.');
    }

    private static function live_validation_state() {
        // Phase 9B.3 — the evidence pack classifier is authoritative: only a pack-
        // verified pass is 'passed'; a bare option is 'unverified_manual'.
        if (class_exists('VES_RC_Evidence_Pack') && method_exists('VES_RC_Evidence_Pack', 'live_validation_state')) {
            return VES_RC_Evidence_Pack::live_validation_state();
        }
        $raw = function_exists('get_option') ? get_option(self::OPTION_LIVE_VALIDATION, []) : [];
        if (!is_array($raw) || empty($raw['status'])) {
            return ['status' => 'unrun', 'recorded_at' => '', 'evidence_pack_hash' => '', 'note' => 'No live staging validation has been recorded on this install.'];
        }
        // Without the pack classifier nothing manual can be trusted as passed.
        return [
            'status'      => 'unverified_manual',
            'recorded_at' => self::clean_text((string) ($raw['recorded_at'] ?? '')),
            'evidence_pack_hash' => '',
            'note'        => 'Evidence pack service unavailable; stored state cannot be verified.',
        ];
    }

    private static function clean_key($s) {
        return function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
    }

    private static function clean_text($s) {
        return function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags((string) $s));
    }

    private static function row($id, $label, $status, $detail) {
        return ['id' => (string) $id, 'label' => (string) $label, 'status' => (string) $status, 'detail' => (string) $detail];
    }
}
