<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_AI_Usage_Tracker — Phase 1 cost-spine telemetry.
 *
 * Records one row per AI/LLM operation (estimate, completion, or failure) into a
 * dedicated, ADDITIVE table `{prefix}ves_ai_usage_events`. This is deliberately
 * SEPARATE from the existing billing ledger `{prefix}ves_usage_events`
 * (VES_Billing): the ledger is the source of truth for credit balance; this table
 * is observability for "what did every AI call cost in tokens / provider USD".
 *
 * Guarantees (so it can be called from any production AI path safely):
 *  - record() NEVER throws and NEVER blocks the AI call (fully try/caught).
 *  - It does NOT charge credits. Wallet deduction remains in VES_Billing. When a
 *    credit charge already happened elsewhere, callers may pass `credits_charged`
 *    and `credit_event_id` so the telemetry row links back to the ledger row.
 *  - It NEVER stores prompts, model inputs, or raw model responses. Only token
 *    counts, costs, status, latency, and a bounded, secret-scrubbed metadata blob.
 *
 * @see VES_Cost_Estimator for the pricing/credit math.
 */
final class VES_AI_Usage_Tracker {

    const TABLE_SLUG  = 'ves_ai_usage_events';
    const DB_VERSION  = '1.0.0';
    const VERSION_OPT = 'ves_ai_usage_events_db_version';

    const STATUS_ESTIMATED = 'estimated';
    const STATUS_STARTED   = 'started';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    /** Metadata keys that must never be persisted (defence-in-depth against prompt/secret leakage). */
    const FORBIDDEN_META_KEYS = [
        'prompt', 'prompts', 'input', 'inputs', 'instructions', 'messages', 'message',
        'response', 'responses', 'output_text', 'raw', 'body', 'content', 'text',
        'api_key', 'apikey', 'authorization', 'auth', 'key', 'secret', 'token', 'bearer',
    ];

    /**
     * Request-scoped ambient context (Phase 1 completion / A2).
     *
     * A flow can call set_context([... workspace_id/user_id/run_id/module/operation_type])
     * once at entry; every AI call made during that request then inherits those
     * fields in record() unless an explicit value is passed. This is the small,
     * backward-compatible mechanism that lets workspace_id be populated without
     * threading it through every OpenAI method signature. Explicit args and a
     * per-call `_ves_meta` always win over ambient.
     */
    private static $ambient = [];

    /** Part B: request-local pointers to the most recent inserted usage event id. */
    private static $last_event_id = 0;
    private static $last_event_id_by_provider = [];

    /**
     * Id of the most recent usage event recorded in this request (optionally filtered
     * by provider, e.g. 'openai' or 'apify'). 0 if none. Lets integrations backlink
     * the intelligence records they create to the usage event that produced them.
     */
    public static function last_event_id(string $provider = ''): int {
        if ($provider !== '') { return (int) (self::$last_event_id_by_provider[$provider] ?? 0); }
        return (int) self::$last_event_id;
    }

    public static function set_context(array $context): void {
        foreach (['workspace_id', 'user_id', 'run_id', 'module', 'operation_type'] as $k) {
            if (array_key_exists($k, $context) && $context[$k] !== '' && $context[$k] !== null) {
                self::$ambient[$k] = $context[$k];
            }
        }
    }

    public static function get_context(): array { return self::$ambient; }

    public static function clear_context(): void { self::$ambient = []; }

    /** Resolve a single field: explicit (non-empty) wins, else ambient, else default. */
    private static function resolve_field(array $args, string $key, $default) {
        if (array_key_exists($key, $args) && $args[$key] !== '' && $args[$key] !== null) {
            return $args[$key];
        }
        if (array_key_exists($key, self::$ambient) && self::$ambient[$key] !== '' && self::$ambient[$key] !== null) {
            return self::$ambient[$key];
        }
        return $default;
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * Idempotent installer. Safe to call repeatedly; dbDelta only adds columns/
     * indexes and never drops data. Version-gated like the other VES stores.
     */
    public static function create_table($force = false): void {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $table = self::table_name();
        $charset_collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            module varchar(80) NOT NULL DEFAULT '',
            operation_type varchar(80) NOT NULL DEFAULT '',
            run_id varchar(80) NOT NULL DEFAULT '',
            provider varchar(40) NOT NULL DEFAULT '',
            model varchar(100) NOT NULL DEFAULT '',
            input_tokens int unsigned NOT NULL DEFAULT 0,
            output_tokens int unsigned NOT NULL DEFAULT 0,
            total_tokens int unsigned NOT NULL DEFAULT 0,
            estimated_provider_cost decimal(12,6) NOT NULL DEFAULT 0,
            actual_provider_cost decimal(12,6) NOT NULL DEFAULT 0,
            estimated_credits decimal(12,2) NOT NULL DEFAULT 0,
            credits_charged decimal(12,2) NOT NULL DEFAULT 0,
            credit_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cache_hit tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'completed',
            error_code varchar(80) NOT NULL DEFAULT '',
            latency_ms int unsigned NOT NULL DEFAULT 0,
            pricing_source varchar(20) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY module (module),
            KEY operation_type (operation_type),
            KEY run_id (run_id),
            KEY provider (provider),
            KEY model (model),
            KEY status (status),
            KEY error_code (error_code),
            KEY created_at (created_at)
        ) {$charset_collate};";

        if (function_exists('dbDelta')) {
            dbDelta($sql);
        } elseif (method_exists($wpdb, 'query')) {
            $wpdb->query($sql);
        }
        if (function_exists('update_option')) {
            update_option(self::VERSION_OPT, self::DB_VERSION, false);
        }
    }

    // ── Recording ────────────────────────────────────────────────────────────

    /**
     * Record one AI operation. NEVER throws; returns inserted row id or 0.
     *
     * @param array $args {
     *   @type string       $provider        Default 'openai'.
     *   @type string       $model
     *   @type string       $module          e.g. 'social_scraper', 'deep_trend_finder'.
     *   @type string       $operation_type  e.g. 'analyze_item', 'trend_analysis'.
     *   @type int|string   $run_id
     *   @type int          $workspace_id
     *   @type int          $user_id         Defaults to current user.
     *   @type float        $started_at      microtime(true) before the call (-> latency_ms).
     *   @type int          $latency_ms      Explicit latency (overrides started_at).
     *   @type mixed        $response        OpenAI decoded array OR WP_Error (usage/status auto-derived).
     *   @type array        $usage           Explicit usage shape (overrides $response usage).
     *   @type int          $input_tokens    Explicit override.
     *   @type int          $output_tokens   Explicit override.
     *   @type string       $status          Override (else derived from $response).
     *   @type string       $error_code      Override (else derived from WP_Error).
     *   @type bool         $cache_hit
     *   @type float        $credits_charged Advisory: what the ledger already charged.
     *   @type int          $credit_event_id Link to {prefix}ves_usage_events.id.
     *   @type float        $estimated_provider_cost Override (else computed).
     *   @type float        $estimated_credits       Override (else computed).
     *   @type array        $metadata        Bounded extra context (scrubbed; no prompts/secrets).
     * }
     * @return int
     */
    public static function record(array $args): int {
        try {
            return self::record_unsafe($args);
        } catch (\Throwable $e) {
            if (class_exists('VES_Log')) {
                VES_Log::error('ai_usage_tracker', 'record failed', ['error_code' => 'tracker_record_failed']);
            } elseif (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[VES_AI_Usage_Tracker] record failed: ' . $e->getMessage());
            }
            return 0;
        }
    }

    private static function record_unsafe(array $args): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return 0; }

        $response = $args['response'] ?? null;
        $is_error = self::is_wp_error($response);

        // Status: explicit override, else derive from response.
        $status = isset($args['status']) ? self::sanitize_status((string) $args['status'])
            : ($is_error ? self::STATUS_FAILED : self::STATUS_COMPLETED);

        // Error code: explicit override, else from WP_Error.
        $error_code = '';
        if (isset($args['error_code'])) {
            $error_code = self::sanitize_token((string) $args['error_code'], 80);
        } elseif ($is_error) {
            $error_code = self::sanitize_token((string) $response->get_error_code(), 80);
        }

        // Tokens: explicit overrides win, else from usage, else from response usage.
        $usage = is_array($args['usage'] ?? null) ? $args['usage'] : self::usage_from_response($response);
        $tokens = self::extract_usage_tokens($usage);
        $input_tokens  = isset($args['input_tokens'])  ? max(0, (int) $args['input_tokens'])  : $tokens['input'];
        $output_tokens = isset($args['output_tokens']) ? max(0, (int) $args['output_tokens']) : $tokens['output'];
        $total_tokens  = $tokens['total'] > 0 ? $tokens['total'] : ($input_tokens + $output_tokens);

        $provider = self::sanitize_token((string) ($args['provider'] ?? 'openai'), 40);
        $model    = self::sanitize_token((string) ($args['model'] ?? ''), 100, true);
        // A2: module/operation/run/user/workspace fall back to ambient request context.
        $module   = self::sanitize_token((string) self::resolve_field($args, 'module', ''), 80);
        $operation = self::sanitize_token((string) self::resolve_field($args, 'operation_type', ''), 80);
        $run_id   = self::sanitize_token((string) self::resolve_field($args, 'run_id', ''), 80, true);
        $user_id  = max(0, (int) self::resolve_field($args, 'user_id', function_exists('get_current_user_id') ? (int) get_current_user_id() : 0));
        $workspace_id = max(0, (int) self::resolve_field($args, 'workspace_id', 0));

        // A1: distinguish ACTUAL provider cost (computed from real response tokens on a
        // completed call) from ESTIMATED cost (preflight / explicit estimate). We never
        // fabricate an actual cost for failed calls or token-less events.
        $pricing_source = '';
        $computed_cost = 0.0;
        if (($input_tokens + $output_tokens) > 0 && class_exists('VES_Cost_Estimator')) {
            $computed_cost = VES_Cost_Estimator::estimate_provider_cost($model, $input_tokens, $output_tokens);
            $pricing = VES_Cost_Estimator::model_pricing($model);
            $pricing_source = (string) $pricing['source'];
        }
        // Part D: an explicit pricing_source (e.g. 'unavailable' for scrape rows) is
        // stored row-level, overriding the model-derived value. Never fabricates cost.
        if (isset($args['pricing_source']) && $args['pricing_source'] !== '') {
            $pricing_source = self::sanitize_token((string) $args['pricing_source'], 20);
        }

        $is_estimate = ($status === self::STATUS_ESTIMATED);
        $has_actual_usage = (!$is_estimate && $status === self::STATUS_COMPLETED && ($input_tokens + $output_tokens) > 0);

        // estimated_provider_cost: explicit estimate, else the computed figure on an
        // 'estimated' row, else 0 (do not mislabel an actual cost as an estimate).
        // Telemetry honesty: a model-derived figure is only an "actual" provider cost
        // when it came from a CONFIRMED price table ('known'). Approximate prices
        // (non-'known' pricing_source, e.g. 'prefix'/'fallback') are reported as an
        // ESTIMATE and never written to actual_provider_cost.
        $price_is_known = ($pricing_source === 'known');
        if (isset($args['estimated_provider_cost'])) {
            $estimated_cost = round(max(0.0, (float) $args['estimated_provider_cost']), 6);
        } elseif ($is_estimate) {
            $estimated_cost = round($computed_cost, 6);
        } elseif ($has_actual_usage && !$price_is_known) {
            $estimated_cost = round($computed_cost, 6); // completed but price unconfirmed → estimate, not actual
        } else {
            $estimated_cost = 0.0;
        }

        // actual_provider_cost: explicit actual; else computed-from-real-tokens on a
        // completed call ONLY when the price is confirmed ('known'); else 0
        // (failed / estimated / unconfirmed-price rows never fake an actual cost).
        if (isset($args['actual_provider_cost'])) {
            $actual_cost = round(max(0.0, (float) $args['actual_provider_cost']), 6);
        } elseif ($has_actual_usage && $price_is_known) {
            $actual_cost = round($computed_cost, 6);
        } else {
            $actual_cost = 0.0;
        }

        // Advisory credit estimate is derived from whichever cost we actually have.
        $credit_basis = $actual_cost > 0 ? $actual_cost : $estimated_cost;
        if (isset($args['estimated_credits'])) {
            $estimated_credits = round(max(0.0, (float) $args['estimated_credits']), 2);
        } elseif (class_exists('VES_Cost_Estimator')) {
            $estimated_credits = VES_Cost_Estimator::estimate_credits_from_usd($credit_basis);
        } else {
            $estimated_credits = 0.0;
        }

        $latency_ms = 0;
        if (isset($args['latency_ms'])) {
            $latency_ms = max(0, (int) $args['latency_ms']);
        } elseif (isset($args['started_at']) && is_numeric($args['started_at'])) {
            $latency_ms = (int) max(0, round((microtime(true) - (float) $args['started_at']) * 1000));
        }

        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $metadata = self::prepare_metadata($args['metadata'] ?? [], [
            'pricing_source' => $pricing_source,
        ]);

        $row = [
            'workspace_id'            => $workspace_id,
            'user_id'                 => $user_id,
            'module'                  => $module,
            'operation_type'          => $operation,
            'run_id'                  => $run_id,
            'provider'                => $provider,
            'model'                   => $model,
            'input_tokens'            => $input_tokens,
            'output_tokens'           => $output_tokens,
            'total_tokens'            => $total_tokens,
            'estimated_provider_cost' => $estimated_cost,
            'actual_provider_cost'    => $actual_cost,
            'estimated_credits'       => $estimated_credits,
            'credits_charged'         => round(max(0.0, (float) ($args['credits_charged'] ?? 0)), 2),
            'credit_event_id'         => max(0, (int) ($args['credit_event_id'] ?? 0)),
            'cache_hit'               => !empty($args['cache_hit']) ? 1 : 0,
            'status'                  => $status,
            'error_code'              => $error_code,
            'latency_ms'              => $latency_ms,
            'pricing_source'          => self::sanitize_token($pricing_source, 20),
            'metadata'                => $metadata,
            'created_at'              => $now,
            'updated_at'              => $now,
        ];
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%d','%d','%d','%f','%f','%f','%f','%d','%d','%s','%s','%d','%s','%s','%s','%s'];

        $ok = $wpdb->insert(self::table_name(), $row, $formats);
        $insert_id = $ok ? (int) $wpdb->insert_id : 0;

        // Part B: remember the last inserted event id (overall + per provider) for the
        // current request so integrations can backlink intelligence records to the
        // usage event that produced/fetched them, without changing any response shape.
        if ($insert_id > 0) {
            self::$last_event_id = $insert_id;
            self::$last_event_id_by_provider[$provider] = $insert_id;
        }

        if (class_exists('VES_Log')) {
            VES_Log::event($status === self::STATUS_FAILED ? 'error' : 'info', 'ai_usage', 'ai_operation_recorded', [
                'module'           => $module,
                'operation_type'   => $operation,
                'provider'         => $provider,
                'model'            => $model,
                'run_id'           => $run_id,
                'workspace_id'     => $workspace_id,
                'input_tokens'     => $input_tokens,
                'output_tokens'    => $output_tokens,
                'latency_ms'       => $latency_ms,
                'credits_estimated' => $estimated_credits,
                'credits_charged'  => $row['credits_charged'],
                'error_code'       => $error_code,
            ]);
        }

        return $insert_id;
    }

    /**
     * Convenience wrapper for an OpenAI Responses-API result. Sets provider=openai
     * and derives tokens/status from the decoded array or WP_Error.
     */
    public static function record_openai_call(array $args): int {
        $args['provider'] = 'openai';
        return self::record($args);
    }

    /**
     * Phase 1 completion / A5 — additive groundwork for scrape (Apify) telemetry.
     *
     * Records a scrape operation as a usage event (provider=apify, operation=scrape
     * by default). Apify responses do not carry token/cost data, so this stores
     * actor/dataset/item-count/compute-unit context in metadata and only sets
     * actual_provider_cost when a caller explicitly passes a known USD figure. It
     * does NOT charge credits and is NOT yet wired into Apify dispatch (deferred —
     * see report); it exists so scrape cost can flow through the same spine later.
     */
    public static function record_scrape(array $args): int {
        $args['provider'] = isset($args['provider']) ? $args['provider'] : 'apify';
        $args['operation_type'] = isset($args['operation_type']) ? $args['operation_type'] : 'scrape';
        // Part D: cost is unknown for Apify by default — store an honest row-level
        // pricing_source rather than fabricating cost (overridable by the caller).
        if (!isset($args['pricing_source'])) { $args['pricing_source'] = 'unavailable'; }

        // Part C: optional idempotency guard so the same actor run can't be recorded
        // twice if both a central client and a service-level emitter fire for it.
        $telemetry_key = isset($args['telemetry_key']) ? (string) $args['telemetry_key'] : '';
        if ($telemetry_key !== '' && function_exists('get_transient')) {
            $guard = 'ves_scrape_tel_' . md5($telemetry_key);
            if (get_transient($guard)) { return 0; }
            if (function_exists('set_transient')) { set_transient($guard, 1, 6 * 3600); }
        }
        unset($args['telemetry_key']);

        $meta = is_array($args['metadata'] ?? null) ? $args['metadata'] : [];
        foreach (['actor_id', 'dataset_id', 'item_count', 'compute_units', 'apify_run_id'] as $k) {
            if (isset($args[$k]) && !isset($meta[$k])) { $meta[$k] = $args[$k]; }
        }
        $args['metadata'] = $meta;
        return self::record($args);
    }

    // ── Preflight (Phase 1.4) ─────────────────────────────────────────────────

    /**
     * Pre-call estimate + advisory spend guard.
     *
     * Phase 1 default is NON-BLOCKING: it computes the estimate and reports whether
     * the user/workspace balance looks sufficient, but returns allowed=true so it
     * cannot break existing flows. A hard gate can be enabled per-call by passing
     * `enforce => true` (only enforces when a reliable VES_Billing balance exists),
     * or globally via the `ves_ai_preflight_enforce` filter.
     *
     * @return array{allowed:bool,sufficient:?bool,balance:?float,estimated_credits:float,
     *   estimated_provider_cost:float,operation:string,module:string,reason:string}
     */
    public static function preflight(array $args): array {
        $estimate = class_exists('VES_Cost_Estimator')
            ? VES_Cost_Estimator::estimate_operation($args)
            : ['estimated_provider_cost' => 0.0, 'estimated_credits' => 0.0];

        $operation = self::sanitize_token((string) ($args['operation_type'] ?? ''), 80);
        $module    = self::sanitize_token((string) ($args['module'] ?? ''), 80);
        $estimated_credits = (float) ($estimate['estimated_credits'] ?? 0.0);

        $balance = null;
        $sufficient = null;
        $user_id = isset($args['user_id']) ? (int) $args['user_id']
            : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        if ($user_id > 0 && class_exists('VES_Billing') && method_exists('VES_Billing', 'get_balance')) {
            try {
                $balance = (float) VES_Billing::get_balance($user_id);
                $sufficient = ($balance + 1e-9) >= $estimated_credits;
            } catch (\Throwable $e) {
                $balance = null; $sufficient = null;
            }
        }

        $enforce = !empty($args['enforce']);
        if (function_exists('apply_filters')) {
            $enforce = (bool) apply_filters('ves_ai_preflight_enforce', $enforce, $args);
        }

        // Only ever enforce when we actually have a reliable balance reading.
        $allowed = true;
        $reason = 'advisory_estimate_recorded';
        if ($enforce && $sufficient === false) {
            $allowed = false;
            $reason = 'insufficient_credits';
        }

        $result = [
            'allowed'                 => $allowed,
            'sufficient'              => $sufficient,
            'balance'                 => $balance,
            'estimated_credits'       => $estimated_credits,
            'estimated_provider_cost' => (float) ($estimate['estimated_provider_cost'] ?? 0.0),
            'operation'               => $operation,
            'module'                  => $module,
            'reason'                  => $reason,
        ];

        $result['usage_event_id'] = 0;
        if (!empty($args['record_estimate'])) {
            // Part B: surface the inserted estimate row id so callers can backlink it.
            $result['usage_event_id'] = self::record(array_merge($args, [
                'status'                  => self::STATUS_ESTIMATED,
                'estimated_provider_cost' => $result['estimated_provider_cost'],
                'estimated_credits'       => $result['estimated_credits'],
                'input_tokens'            => (int) ($estimate['input_tokens'] ?? 0),
                'output_tokens'           => (int) ($estimate['output_tokens'] ?? 0),
            ]));
        }

        return $result;
    }

    // ── Read API (admin diagnostics, Phase 1.7) ──────────────────────────────

    /** Recent rows for the admin diagnostics view. Returns sanitized assoc rows. */
    public static function recent(int $limit = 25, array $filters = []): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $limit = max(1, min(200, $limit));
        $table = self::table_name();
        $where = '1=1';
        $params = [];
        if (!empty($filters['module'])) {
            $where .= ' AND module = %s';
            $params[] = self::sanitize_token((string) $filters['module'], 80);
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = %s';
            $params[] = self::sanitize_status((string) $filters['status']);
        }
        if (!empty($filters['provider'])) {
            $where .= ' AND provider = %s';
            $params[] = self::sanitize_token((string) $filters['provider'], 40);
        }
        // v0.4.0: workspace/user/run columns included so ledger rows are
        // explainable (who, where, which trace) — additive columns only.
        $sql = "SELECT id, created_at, module, operation_type, provider, model, status,
                       workspace_id, user_id, run_id,
                       input_tokens, output_tokens, total_tokens, latency_ms,
                       actual_provider_cost, estimated_provider_cost, estimated_credits, credits_charged,
                       cache_hit, error_code, metadata
                FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d";
        $params[] = $limit;
        $prepared = $wpdb->prepare($sql, $params);
        $rows = $prepared ? $wpdb->get_results($prepared, ARRAY_A) : [];
        return is_array($rows) ? $rows : [];
    }

    /** Compact aggregate for health-check / overview. */
    public static function summary(int $window_hours = 24): array {
        global $wpdb;
        $out = [
            'table_exists' => self::table_exists(),
            'window_hours' => $window_hours,
            'total'        => 0,
            'failed'       => 0,
            'tokens'       => 0,
            'provider_cost' => 0.0,
            'last_error_code' => '',
            'last_created_at' => '',
        ];
        if (!$out['table_exists'] || !isset($wpdb) || !is_object($wpdb)) { return $out; }
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', time() - max(1, $window_hours) * 3600);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed,
                    COALESCE(SUM(total_tokens),0) AS tokens,
                    COALESCE(SUM(estimated_provider_cost),0) AS provider_cost
             FROM {$table} WHERE created_at >= %s",
            self::STATUS_FAILED, $since
        ), ARRAY_A);
        if (is_array($row)) {
            $out['total'] = (int) ($row['total'] ?? 0);
            $out['failed'] = (int) ($row['failed'] ?? 0);
            $out['tokens'] = (int) ($row['tokens'] ?? 0);
            $out['provider_cost'] = round((float) ($row['provider_cost'] ?? 0), 6);
        }
        $last = $wpdb->get_row("SELECT error_code, created_at FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A);
        if (is_array($last)) {
            $out['last_error_code'] = (string) ($last['error_code'] ?? '');
            $out['last_created_at'] = (string) ($last['created_at'] ?? '');
        }
        return $out;
    }

    /**
     * Phase 3B Part G — Apify/scrape coverage health: counts of provider=apify rows,
     * split DTF vs non-DTF, plus pricing-unavailable / missing-actor / failed counts.
     */
    public static function scrape_coverage(int $window_hours = 168): array {
        global $wpdb;
        $out = [
            'table_exists' => self::table_exists(), 'window_hours' => $window_hours,
            'apify_total' => 0, 'dtf' => 0, 'non_dtf' => 0,
            'pricing_unavailable' => 0, 'missing_actor' => 0, 'failed' => 0,
        ];
        if (empty($out['table_exists']) || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return $out; }
        $table = self::table_name();
        $since = gmdate('Y-m-d H:i:s', time() - max(1, $window_hours) * 3600);
        $base = $wpdb->prepare("FROM {$table} WHERE provider = %s AND created_at >= %s", 'apify', $since);
        $out['apify_total']         = (int) $wpdb->get_var("SELECT COUNT(*) {$base}");
        $out['dtf']                 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$base} AND module = %s", 'deep_trend_finder'));
        $out['non_dtf']             = max(0, $out['apify_total'] - $out['dtf']);
        $out['pricing_unavailable'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$base} AND pricing_source = %s", 'unavailable'));
        $out['missing_actor']       = (int) $wpdb->get_var("SELECT COUNT(*) {$base} AND (metadata IS NULL OR metadata NOT LIKE '%actor_id%')");
        $out['failed']              = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$base} AND status = %s", self::STATUS_FAILED));
        return $out;
    }

    public static function table_exists(): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return false; }
        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /** Pull an OpenAI usage array out of a decoded Responses payload. */
    private static function usage_from_response($response) {
        if (is_array($response) && isset($response['usage']) && is_array($response['usage'])) {
            return $response['usage'];
        }
        return [];
    }

    /**
     * Normalize OpenAI usage shapes to input/output/total.
     * Responses API: input_tokens / output_tokens / total_tokens.
     * Chat API: prompt_tokens / completion_tokens / total_tokens.
     */
    public static function extract_usage_tokens($usage): array {
        $usage = is_array($usage) ? $usage : [];
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? 0);
        if ($total <= 0) { $total = $input + $output; }
        return ['input' => max(0, $input), 'output' => max(0, $output), 'total' => max(0, $total)];
    }

    private static function is_wp_error($thing): bool {
        if (function_exists('is_wp_error')) { return is_wp_error($thing); }
        return $thing instanceof WP_Error;
    }

    private static function sanitize_status(string $status): string {
        $status = strtolower(trim($status));
        $allowed = [self::STATUS_ESTIMATED, self::STATUS_STARTED, self::STATUS_COMPLETED, self::STATUS_FAILED];
        return in_array($status, $allowed, true) ? $status : self::STATUS_COMPLETED;
    }

    /** Bounded sanitizer. $allow_dots keeps model ids / run ids intact. */
    private static function sanitize_token(string $value, int $max, bool $allow_dots = false): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        if (function_exists('sanitize_text_field')) {
            $value = sanitize_text_field($value);
        } else {
            $value = trim(strip_tags($value));
        }
        if (!$allow_dots) {
            // Keep readable but tame; model/run ids opt out so dots/colons survive.
            $value = preg_replace('/[^A-Za-z0-9 _.:\/-]/', '', $value);
        }
        return substr((string) $value, 0, $max);
    }

    /**
     * Build the stored metadata JSON: drop forbidden keys, scrub secrets, bound size.
     * Never persists prompts, inputs, responses, or credentials.
     */
    private static function prepare_metadata($metadata, array $extra = []): string {
        $metadata = is_array($metadata) ? $metadata : [];
        $clean = [];
        foreach ($metadata as $key => $value) {
            $key_l = strtolower((string) $key);
            if (in_array($key_l, self::FORBIDDEN_META_KEYS, true)) { continue; }
            // Only scalars and shallow arrays of scalars; no objects/closures.
            if (is_scalar($value) || $value === null) {
                $clean[$key_l] = is_string($value) ? self::scrub_secrets($value) : $value;
            } elseif (is_array($value)) {
                $flat = [];
                foreach ($value as $k2 => $v2) {
                    if (is_scalar($v2) || $v2 === null) {
                        $flat[strtolower((string) $k2)] = is_string($v2) ? self::scrub_secrets($v2) : $v2;
                    }
                }
                if (!empty($flat)) { $clean[$key_l] = $flat; }
            }
        }
        foreach ($extra as $k => $v) {
            if ($v !== '' && $v !== null) { $clean[strtolower((string) $k)] = $v; }
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($clean) : json_encode($clean);
        if (!is_string($json)) { $json = '{}'; }
        if (strlen($json) > 8000) { $json = '{"truncated":true}'; } // hard cap
        return $json;
    }

    /** Redact common provider secret formats from any string before storage/logging. */
    public static function scrub_secrets(string $value): string {
        $patterns = [
            '/sk-[A-Za-z0-9_\-]{8,}/',          // OpenAI
            '/sk_live_[A-Za-z0-9]{8,}/',        // Stripe secret
            '/whsec_[A-Za-z0-9]{8,}/',          // Stripe webhook secret
            '/apify_api_[A-Za-z0-9]{8,}/',      // Apify
            '/AIza[A-Za-z0-9_\-]{20,}/',        // Google
            '/Bearer\s+[A-Za-z0-9_\-.]+/i',     // Any bearer token
        ];
        return (string) preg_replace($patterns, '[redacted]', $value);
    }
}
