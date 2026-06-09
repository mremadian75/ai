<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Admin_Console — unified, ADDITIVE Intelligence Suite admin dashboard.
 *
 * Hardened rebuild after a production incident. The earlier approach re-parented
 * other plugins' menus (cross-class edits + load-order/class_exists timing +
 * remove_submenu_page) which white-screened wp-admin. This version eliminates
 * that entire risk surface:
 *
 *   - PURELY ADDITIVE: it registers ONE new "Dashboard" page under the existing
 *     Intelligence Suite menu (or creates the top-level only if absent). It does
 *     NOT move, remove, rename, or edit any other admin page or its registration.
 *   - FAIL-SAFE: every hooked handler is wrapped in try/catch (catches Error +
 *     Exception). A failure here can never take down wp-admin — at worst the
 *     Dashboard page is unavailable while every existing page keeps working.
 *   - GUARDED: every external symbol is checked with function_exists/method_exists/
 *     class_exists before use. Links point to each page's REAL current location
 *     via menu_page_url(); missing pages are shown as "not installed", never linked.
 *   - It does NOT change settings, option keys, capabilities, billing/credits, DB,
 *     REST, shortcodes, prompts, sources, or the customer frontend.
 */
final class VES_Admin_Console {

    const PARENT_SLUG = 'ves-intelligence-suite';
    const PAGE_SLUG   = 'ves-fiis-console';

    /** @var string captured page hook suffix for scoped enqueue */
    private static $hook = '';

    public static function init() {
        // Priority 20 so the existing Intelligence Suite top-level (registered at
        // the default priority 10 by VES_Access_Control_Admin) already exists.
        add_action('admin_menu', [__CLASS__, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    // ── Menu registration (fail-safe) ─────────────────────────────────────────

    public static function register_menu() {
        try {
            $parent = self::resolve_parent();
            self::$hook = (string) add_submenu_page(
                $parent,
                'Intelligence Suite — Dashboard',
                'Dashboard',
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render']
            );
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    /**
     * Use the existing Intelligence Suite top-level when present; only create our
     * own as a last resort. Never duplicates an existing top-level menu.
     */
    private static function resolve_parent(): string {
        global $admin_page_hooks;
        if (is_array($admin_page_hooks) && isset($admin_page_hooks[self::PARENT_SLUG])) {
            return self::PARENT_SLUG;
        }
        if (function_exists('add_menu_page')) {
            add_menu_page('Intelligence Suite', 'Intelligence Suite', 'manage_options', self::PARENT_SLUG, [__CLASS__, 'render'], 'dashicons-chart-line', 58);
        }
        return self::PARENT_SLUG;
    }

    public static function enqueue($hook) {
        try {
            if (self::$hook === '' || $hook !== self::$hook) { return; }
            $ver = defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '1.0.0';
            $base = defined('VES_PLUGIN_URL') ? VES_PLUGIN_URL : (function_exists('plugin_dir_url') ? plugin_dir_url(dirname(__DIR__) . '/x.php') : '');
            if (function_exists('wp_enqueue_style')) {
                wp_enqueue_style('fiis-admin-console', $base . 'assets/css/fiis-admin-console.css', [], $ver);
            }
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    // ── Page registry (grouped links to EXISTING pages) ───────────────────────

    /** group => [label, [ [slug,label,blurb], ... ] ]. Slugs are linked at their real location. */
    public static function groups(): array {
        return [
            'ai' => ['AI & Models', [
                ['ves-social-scraper', 'General & AI Settings', 'Core API keys, model/provider defaults and global options.'],
                ['ves-provider-settings', 'Provider Settings', 'Per-provider configuration and availability.'],
                ['ves-prompt-templates', 'Prompt Templates', 'Inspect the AI prompt templates used across modules.'],
            ]],
            'sources' => ['Sources & Connectors', [
                ['ves-actor-registry', 'Actor Registry', 'Apify actors and source adapter registry.'],
                ['ves-adapter-diagnostics', 'Adapter Diagnostics', 'Source adapter / provider connectivity diagnostics.'],
            ]],
            'access' => ['Access & Credits', [
                ['ves-plans-access', 'Plans & Access', 'Role/plan × module access matrix editor.'],
                ['ves-credits-usage', 'Credits & Limits', 'Per-plan credit limits and usage policies.'],
                ['ves-billing-requests', 'Billing Requests', 'Pending top-up / plan change requests from users.'],
                ['ves-billing-ledger', 'Billing Ledger', 'Credit ledger and usage event history.'],
            ]],
            'modules' => ['Modules', [
                ['future-island-deep-trend-finder', 'Deep Trend Finder', 'Deep Trend Finder module settings.'],
                ['future-island-creative-intelligence', 'Creative Intelligence', 'Creative Intelligence module settings.'],
                ['ves-market-signal-commercial', 'MarketSignal Commercial', 'MarketSignal commercial settings.'],
                ['ves-market-signal-interface', 'MarketSignal Interface', 'MarketSignal frontend interface settings.'],
                ['ves-intelligence-map', 'Intelligence Map', 'Read-only relationship graph of runs, insights, briefs and memory.'],
            ]],
            'reports' => ['Reports & Intelligence', [
                ['ves-memory-knowledge', 'Memory / Knowledge', 'Workspace memory and knowledge records.'],
                ['ves-projects', 'Projects', 'Project records overview.'],
                ['ves-project-manager', 'Project Manager', 'Manage project context and assignments.'],
                ['ves-operations', 'Operations', 'Operational dashboard for runs and queues.'],
            ]],
            'advanced' => ['Advanced', [
                ['ves-diagnostics', 'Diagnostics', 'Low-level diagnostics and internal state.'],
                ['ves-audit-log', 'Audit Log', 'Access/credit audit trail.'],
            ]],
        ];
    }

    public static function tabs(): array {
        return [
            'overview' => 'Overview',
            'ai'       => 'AI & Models',
            'sources'  => 'Sources & Connectors',
            'access'   => 'Access & Credits',
            'modules'  => 'Modules',
            'reports'  => 'Reports & Intelligence',
            'advanced' => 'Advanced',
        ];
    }

    // ── Render (fail-safe) ────────────────────────────────────────────────────

    public static function render() {
        try {
            if (function_exists('current_user_can') && !current_user_can('manage_options')) {
                if (function_exists('wp_die')) { wp_die(esc_html__('Insufficient capability', 'ves')); }
                return;
            }
            $tabs = self::tabs();
            $tab = self::current_tab();
            echo '<div class="wrap fiis-console">';
            echo '<div class="fiis-console-head"><h1>' . self::e('Intelligence Suite') . '</h1>';
            echo '<p class="fiis-console-sub">' . self::e('One place to reach every FIIS admin area. This dashboard links to your existing settings pages — nothing here is moved or changed.') . '</p></div>';

            echo '<nav class="fiis-console-tabs">';
            foreach ($tabs as $key => $label) {
                $cls = ($key === $tab) ? 'fiis-tab is-active' : 'fiis-tab';
                echo '<a class="' . self::ea($cls) . '" href="' . self::eu(self::tab_url($key)) . '">' . self::e($label) . '</a>';
            }
            echo '</nav>';

            echo '<div class="fiis-console-body">';
            if ($tab === 'overview') { self::render_overview(); }
            elseif ($tab === 'access') { self::render_access(); }
            elseif ($tab === 'advanced') { self::render_health(); self::render_diagnostics(); self::render_intelligence(); self::render_provenance(); self::render_telemetry_health(); self::render_trend_engine(); self::render_insight_quality(); self::render_readiness(); self::render_stripe(); self::render_group('advanced'); }
            else { self::render_group($tab); }
            echo '</div></div>';
        } catch (\Throwable $e) {
            self::log($e);
            echo '<div class="wrap"><h1>Intelligence Suite</h1><div class="notice notice-error"><p>'
               . self::e('The dashboard could not render fully, but your settings pages are unaffected. Use the standard admin menus to reach them.')
               . '</p></div></div>';
        }
    }

    private static function render_overview() {
        echo '<div class="fiis-console-cards">';
        $modules = [
            ['Deep Trend Finder', class_exists('FIDTF_Plugin') || class_exists('FIDTF_Admin')],
            ['Creative Intelligence', class_exists('VES_Creative_Intelligence') || class_exists('FICI_Plugin')],
            ['MarketSignal', class_exists('VES_Market_Signal_Store') || class_exists('VES_Market_Signal_Commercial')],
            ['Intelligence Map', class_exists('VES_Intelligence_Map')],
        ];
        foreach ($modules as $m) {
            echo '<div class="fiis-card"><h3>' . self::e($m[0]) . '</h3><p class="fiis-status">'
               . ($m[1] ? '<span class="fiis-badge fiis-badge-ok">' . self::e('Active') . '</span>'
                        : '<span class="fiis-badge fiis-badge-muted">' . self::e('Inactive') . '</span>')
               . '</p></div>';
        }
        echo '</div>';
        echo '<h2 class="fiis-console-h2">' . self::e('Jump to a section') . '</h2>';
        echo '<div class="fiis-console-cards">';
        foreach (self::tabs() as $key => $label) {
            if ($key === 'overview') { continue; }
            echo '<div class="fiis-card"><h3>' . self::e($label) . '</h3>'
               . '<a class="button button-secondary" href="' . self::eu(self::tab_url($key)) . '">' . self::e('Open') . '</a></div>';
        }
        echo '</div>';
    }

    /** System Health: schema status, tables, recent reservation failures + a repair button. */
    private static function render_health() {
        if (!class_exists('VES_Migrations')) { return; }
        try {
            $h = VES_Migrations::health();
            $repaired = isset($_GET['ves_repaired']) ? max(0, (int) $_GET['ves_repaired']) : -1;
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('System Health') . '</h3>';
            if ($repaired >= 0) { echo '<div class="notice notice-success inline"><p>' . self::e('Repaired/verified ' . $repaired . ' table(s).') . '</p></div>'; }
            $sync = !empty($h['schema_in_sync']);
            echo '<p>';
            echo self::e('Plugin') . ' <strong>' . self::e((string) ($h['plugin_version'] ?? '')) . '</strong> · ';
            echo self::e('Schema') . ' <span class="fiis-badge ' . ($sync ? 'fiis-badge-ok">' . self::e('in sync') : 'fiis-badge-warn">' . self::e('out of sync')) . '</span> ';
            echo '<span class="fiis-muted">(' . self::e((string) ($h['schema_version_db'] ?? '') . ' / ' . (string) ($h['schema_version_code'] ?? '')) . ')</span><br>';
            echo self::e('Cache') . ': <span class="fiis-muted">' . self::e((string) ($h['object_cache'] ?? '')) . '</span>';
            if ($h['reservation_failures_7d'] !== null) {
                echo '<br>' . self::e('Reservation denials (7d)') . ': <strong>' . (int) $h['reservation_failures_7d'] . '</strong> · ';
                echo self::e('Pending reservations') . ': <strong>' . (int) $h['pending_reservations'] . '</strong>';
            }
            echo '</p>';
            if (!empty($h['tables'])) {
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Table') . '</th><th>' . self::e('Rows') . '</th></tr></thead><tbody>';
                foreach ($h['tables'] as $t) {
                    echo '<tr><th scope="row" style="text-align:left">' . self::e((string) ($t['name'] ?? '')) . '</th><td>' . self::e((string) ($t['rows'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            if (!$sync && function_exists('admin_url')) {
                echo '<form method="post" action="' . self::eu(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . self::ea(VES_Migrations::REPAIR) . '">';
                if (function_exists('wp_nonce_field')) { wp_nonce_field(VES_Migrations::REPAIR); }
                echo '<button type="submit" class="button button-primary">' . self::e('Repair / migrate tables now') . '</button>';
                echo '</form>';
            } elseif (function_exists('admin_url')) {
                echo '<form method="post" action="' . self::eu(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . self::ea(VES_Migrations::REPAIR) . '">';
                if (function_exists('wp_nonce_field')) { wp_nonce_field(VES_Migrations::REPAIR); }
                echo '<button type="submit" class="button button-secondary">' . self::e('Re-verify tables') . '</button>';
                echo '</form>';
            }
            echo '</div>';
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    /**
     * Phase 0/1 diagnostics: health-check results + recent AI usage telemetry.
     * Admin-only (the whole console render() already gates on manage_options).
     * Shows no secrets, no prompts, and no raw model responses.
     */
    private static function render_diagnostics() {
        // Health check (Phase 0.5).
        if (class_exists('VES_Health_Check')) {
            try {
                $health = VES_Health_Check::run();
                $overall = (string) ($health['status'] ?? 'ok');
                $badge = $overall === 'ok' ? 'fiis-badge-ok' : ($overall === 'error' ? 'fiis-badge-warn' : 'fiis-badge-warn');
                echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Diagnostics — Health Check') . ' ';
                echo '<span class="fiis-badge ' . self::ea($badge) . '">' . self::e(strtoupper($overall)) . '</span></h3>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Check') . '</th><th>' . self::e('Status') . '</th><th>' . self::e('Detail') . '</th></tr></thead><tbody>';
                foreach ((array) ($health['checks'] ?? []) as $check) {
                    $st = (string) ($check['status'] ?? 'ok');
                    $cls = $st === 'ok' ? 'fiis-badge-ok' : 'fiis-badge-warn';
                    echo '<tr><th scope="row" style="text-align:left">' . self::e((string) ($check['key'] ?? '')) . '</th>'
                       . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e($st) . '</span></td>'
                       . '<td class="fiis-muted">' . self::e((string) ($check['message'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table></div></div>';
            } catch (\Throwable $e) { self::log($e); }
        }

        // Recent AI usage telemetry (Phase 1.7).
        if (class_exists('VES_AI_Usage_Tracker')) {
            try {
                if (!VES_AI_Usage_Tracker::table_exists()) {
                    echo '<div class="fiis-card"><h3>' . self::e('AI Usage (recent)') . '</h3><p class="fiis-muted">'
                       . self::e('No AI usage table yet. Use “Repair / migrate tables” above, then run an AI operation.') . '</p></div>';
                    return;
                }
                $rows = VES_AI_Usage_Tracker::recent(25);
                echo '<div class="fiis-card"><h3>' . self::e('AI Usage (recent 25)') . '</h3>';
                if (empty($rows)) {
                    echo '<p class="fiis-muted">' . self::e('No AI operations recorded yet.') . '</p></div>';
                    return;
                }
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr>'
                   . '<th>' . self::e('When (UTC)') . '</th><th>' . self::e('Module') . '</th><th>' . self::e('Operation') . '</th>'
                   . '<th>' . self::e('Model') . '</th><th>' . self::e('Status') . '</th><th>' . self::e('Tokens') . '</th>'
                   . '<th>' . self::e('Est. credits') . '</th><th>' . self::e('Charged') . '</th><th>' . self::e('Error') . '</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $st = (string) ($r['status'] ?? '');
                    $cls = $st === 'completed' ? 'fiis-badge-ok' : ($st === 'failed' ? 'fiis-badge-warn' : 'fiis-badge-muted');
                    echo '<tr>'
                       . '<td class="fiis-muted">' . self::e((string) ($r['created_at'] ?? '')) . '</td>'
                       . '<td>' . self::e((string) ($r['module'] ?? '')) . '</td>'
                       . '<td>' . self::e((string) ($r['operation_type'] ?? '')) . '</td>'
                       . '<td>' . self::e((string) ($r['model'] ?? '')) . '</td>'
                       . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e($st) . '</span></td>'
                       . '<td>' . self::e((string) ((int) ($r['total_tokens'] ?? 0))) . '</td>'
                       . '<td>' . self::e((string) (float) ($r['estimated_credits'] ?? 0)) . '</td>'
                       . '<td>' . self::e((string) (float) ($r['credits_charged'] ?? 0)) . '</td>'
                       . '<td class="fiis-muted">' . self::e((string) ($r['error_code'] ?? '')) . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
                echo '<p class="fiis-muted">' . self::e('Estimated credits are advisory cost-spine telemetry; actual wallet charges are managed by the billing ledger.') . '</p>';
                echo '</div>';
            } catch (\Throwable $e) { self::log($e); }
        }
    }

    /**
     * Phase 2 diagnostics: canonical intelligence-contract counts + recent insights.
     * Admin-only (console render() gates on manage_options). No prompts/responses/secrets.
     */
    /**
     * Phase 3 diagnostics: source/signal provenance health + recent Apify scrape
     * telemetry. Admin-only (console render() gates on manage_options). Shows only
     * counts + actor/dataset/status — no URLs, payloads, tokens, or secrets.
     */
    private static function render_provenance() {
        if (!class_exists('VES_Intelligence_Store')) { return; }
        try {
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Source / Signal Provenance (Phase 3)') . '</h3>';

            // Provider + signal-type breakdowns.
            $providers = VES_Intelligence_Store::provider_breakdown(0);
            $sig_types = VES_Intelligence_Store::signal_type_breakdown(0);
            echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Sources by provider') . '</th><th>' . self::e('Count') . '</th></tr></thead><tbody>';
            if (empty($providers)) { echo '<tr><td class="fiis-muted">' . self::e('No sources yet') . '</td><td>0</td></tr>'; }
            foreach ($providers as $prov => $c) {
                echo '<tr><th scope="row" style="text-align:left">' . self::e($prov !== '' ? $prov : '(none)') . '</th><td>' . self::e((string) (int) $c) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Signals by type') . '</th><th>' . self::e('Count') . '</th></tr></thead><tbody>';
            if (empty($sig_types)) { echo '<tr><td class="fiis-muted">' . self::e('No signals yet') . '</td><td>0</td></tr>'; }
            foreach ($sig_types as $t => $c) {
                echo '<tr><th scope="row" style="text-align:left">' . self::e($t !== '' ? $t : '(none)') . '</th><td>' . self::e((string) (int) $c) . '</td></tr>';
            }
            echo '</tbody></table></div>';

            // Provenance health (missing-field counters).
            $h = VES_Intelligence_Store::provenance_health(0);
            echo '<h4>' . self::e('Provenance health') . '</h4>';
            echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Check') . '</th><th>' . self::e('Records') . '</th></tr></thead><tbody>';
            $hlabels = [
                'sources_missing_provider' => 'Sources missing provider',
                'sources_missing_hash' => 'Sources missing canonical hash',
                'signals_missing_source' => 'Signals missing source_id',
                'evidence_missing_source' => 'Evidence missing source_id',
                'insights_missing_evidence' => 'Insights missing evidence',
            ];
            foreach ($hlabels as $k => $label) {
                $v = (int) ($h[$k] ?? 0);
                $cls = $v > 0 ? 'fiis-badge-warn' : 'fiis-badge-ok';
                echo '<tr><th scope="row" style="text-align:left">' . self::e($label) . '</th><td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e((string) $v) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';

            // Recent Apify / scrape telemetry.
            if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'recent')) {
                $rows = VES_AI_Usage_Tracker::recent(10, ['provider' => 'apify']);
                echo '<h4>' . self::e('Recent scrape telemetry (Apify)') . '</h4>';
                if (empty($rows)) {
                    echo '<p class="fiis-muted">' . self::e('No scrape usage events recorded yet.') . '</p>';
                } else {
                    echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr>'
                       . '<th>' . self::e('When (UTC)') . '</th><th>' . self::e('Operation') . '</th><th>' . self::e('Status') . '</th>'
                       . '<th>' . self::e('Items') . '</th><th>' . self::e('Actor') . '</th><th>' . self::e('Dataset') . '</th>'
                       . '<th>' . self::e('Actual $') . '</th><th>' . self::e('Est. $') . '</th><th>' . self::e('Error') . '</th></tr></thead><tbody>';
                    foreach ($rows as $r) {
                        $meta = is_array($r['metadata'] ?? null) ? $r['metadata'] : (is_string($r['metadata'] ?? null) ? json_decode($r['metadata'], true) : []);
                        $meta = is_array($meta) ? $meta : [];
                        $st = (string) ($r['status'] ?? '');
                        $cls = $st === 'completed' ? 'fiis-badge-ok' : ($st === 'failed' ? 'fiis-badge-warn' : 'fiis-badge-muted');
                        echo '<tr>'
                           . '<td class="fiis-muted">' . self::e((string) ($r['created_at'] ?? '')) . '</td>'
                           . '<td>' . self::e((string) ($r['operation_type'] ?? '')) . '</td>'
                           . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e($st) . '</span></td>'
                           . '<td>' . self::e((string) (int) ($meta['item_count'] ?? 0)) . '</td>'
                           . '<td class="fiis-muted">' . self::e(substr((string) ($meta['actor_id'] ?? ''), 0, 40)) . '</td>'
                           . '<td class="fiis-muted">' . self::e(substr((string) ($meta['dataset_id'] ?? ''), 0, 30)) . '</td>'
                           . '<td>' . self::e((string) (float) ($r['actual_provider_cost'] ?? 0)) . '</td>'
                           . '<td>' . self::e((string) (float) ($r['estimated_provider_cost'] ?? 0)) . '</td>'
                           . '<td class="fiis-muted">' . self::e((string) ($r['error_code'] ?? '')) . '</td>'
                           . '</tr>';
                    }
                    echo '</tbody></table></div>';
                    echo '<p class="fiis-muted">' . self::e('Apify run/dataset endpoints do not return cost; item counts are recorded with cost marked unavailable.') . '</p>';
                }
            }
            echo '</div>';
        } catch (\Throwable $e) { self::log($e); }
    }

    /**
     * Phase 3B Part G — telemetry backlink health, Apify coverage, and live schema
     * verification. Admin-only (render() gates manage_options). Counts only; no URLs,
     * payloads, tokens or secrets.
     */
    private static function render_telemetry_health() {
        try {
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Telemetry & Schema Health (Phase 3B)') . '</h3>';

            // Telemetry backlink health.
            if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'backlink_health')) {
                $b = VES_Intelligence_Store::backlink_health(0);
                echo '<h4>' . self::e('Telemetry backlink health (usage_event_id)') . '</h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Entity') . '</th><th>' . self::e('With') . '</th><th>' . self::e('Missing') . '</th><th>' . self::e('Total') . '</th></tr></thead><tbody>';
                foreach (['sources', 'signals', 'evidence'] as $ent) {
                    $with = (int) ($b[$ent . '_with_usage_event'] ?? 0);
                    $missing = (int) ($b[$ent . '_missing_usage_event'] ?? 0);
                    $cls = $with > 0 ? 'fiis-badge-ok' : 'fiis-badge-muted';
                    echo '<tr><th scope="row" style="text-align:left">' . self::e($ent) . '</th>'
                       . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e((string) $with) . '</span></td>'
                       . '<td>' . self::e((string) $missing) . '</td>'
                       . '<td class="fiis-muted">' . self::e((string) (int) ($b[$ent . '_total'] ?? 0)) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            // Apify coverage health.
            if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'scrape_coverage')) {
                $c = VES_AI_Usage_Tracker::scrape_coverage(168);
                echo '<h4>' . self::e('Apify coverage (last 7 days)') . '</h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><tbody>';
                $rows = [
                    'Apify scrape rows' => (int) ($c['apify_total'] ?? 0),
                    'Deep Trend Finder' => (int) ($c['dtf'] ?? 0),
                    'Non-DTF (e.g. MarketSignal)' => (int) ($c['non_dtf'] ?? 0),
                    'Cost unavailable (honest)' => (int) ($c['pricing_unavailable'] ?? 0),
                    'Missing actor_id' => (int) ($c['missing_actor'] ?? 0),
                    'Failed runs' => (int) ($c['failed'] ?? 0),
                ];
                foreach ($rows as $label => $val) {
                    echo '<tr><th scope="row" style="text-align:left">' . self::e($label) . '</th><td>' . self::e((string) $val) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            // Live schema verification.
            if (class_exists('VES_Health_Check') && method_exists('VES_Health_Check', 'verify_schema')) {
                $s = VES_Health_Check::verify_schema();
                $overall = (string) ($s['status'] ?? 'ok');
                $obadge = $overall === 'ok' ? 'fiis-badge-ok' : 'fiis-badge-warn';
                echo '<h4>' . self::e('Schema verification') . ' <span class="fiis-badge ' . self::ea($obadge) . '">' . self::e(strtoupper($overall)) . '</span></h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Table') . '</th><th>' . self::e('Status') . '</th><th>' . self::e('Missing columns') . '</th></tr></thead><tbody>';
                foreach ((array) ($s['tables'] ?? []) as $t) {
                    $st = (string) ($t['status'] ?? 'ok');
                    $cls = $st === 'ok' ? 'fiis-badge-ok' : 'fiis-badge-warn';
                    $missing = !empty($t['missing_columns']) ? implode(', ', array_map('strval', (array) $t['missing_columns'])) : (empty($t['exists']) ? '(table missing)' : '—');
                    echo '<tr><th scope="row" style="text-align:left">' . self::e((string) ($t['table'] ?? '')) . '</th>'
                       . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e($st) . '</span></td>'
                       . '<td class="fiis-muted">' . self::e($missing) . '</td></tr>';
                }
                echo '</tbody></table></div>';
                echo '<p class="fiis-muted">' . self::e('Read-only check. Run “Repair / migrate tables” above (or wp ves verify-schema --repair) to apply missing columns.') . '</p>';
            }
            echo '</div>';
        } catch (\Throwable $e) { self::log($e); }
    }

    /**
     * Phase 4 (Part G) — Deep Trend Finder V2 engine health. Admin-only (render()
     * gates manage_options). Counts + classifications + top trends only; no URLs,
     * payloads, tokens, prompts, or secrets.
     */
    private static function render_trend_engine() {
        if (!class_exists('VES_Trend_Observation_Store') && !class_exists('VES_Trend_Record_Store')) { return; }
        try {
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Trend Engine (Phase 4 / DTF V2)') . '</h3>';

            if (class_exists('VES_Trend_Observation_Store')) {
                $obs_total = VES_Trend_Observation_Store::total(0);
                $by_type = VES_Trend_Observation_Store::count_by('observation_type', 0);
                echo '<p>' . self::e('Observations') . ': <strong>' . self::e((string) $obs_total) . '</strong></p>';
                if (!empty($by_type)) {
                    echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Observation type') . '</th><th>' . self::e('Count') . '</th></tr></thead><tbody>';
                    foreach ($by_type as $t => $c) { echo '<tr><th scope="row" style="text-align:left">' . self::e($t !== '' ? $t : '(none)') . '</th><td>' . self::e((string) (int) $c) . '</td></tr>'; }
                    echo '</tbody></table></div>';
                }
            }

            if (class_exists('VES_Trend_Record_Store')) {
                $rec_total = VES_Trend_Record_Store::total(0);
                $by_class = VES_Trend_Record_Store::count_by_classification(0);
                $missing_evi = VES_Trend_Record_Store::count_missing_evidence(0);
                echo '<p>' . self::e('Trend records') . ': <strong>' . self::e((string) $rec_total) . '</strong> · '
                   . self::e('insufficient_data') . ': <strong>' . self::e((string) (int) ($by_class['insufficient_data'] ?? 0)) . '</strong> · '
                   . self::e('missing evidence') . ': <strong>' . self::e((string) $missing_evi) . '</strong></p>';
                if (!empty($by_class)) {
                    echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Classification') . '</th><th>' . self::e('Count') . '</th></tr></thead><tbody>';
                    foreach ($by_class as $cl => $c) { echo '<tr><th scope="row" style="text-align:left">' . self::e($cl) . '</th><td>' . self::e((string) (int) $c) . '</td></tr>'; }
                    echo '</tbody></table></div>';
                }
                $top = VES_Trend_Record_Store::list_trend_records(['limit' => 10]);
                if (!empty($top)) {
                    echo '<h4>' . self::e('Top trends by score') . '</h4>';
                    echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr>'
                       . '<th>' . self::e('Term') . '</th><th>' . self::e('Type') . '</th><th>' . self::e('Classification') . '</th>'
                       . '<th>' . self::e('Trend') . '</th><th>' . self::e('Confidence') . '</th><th>' . self::e('Points') . '</th><th>' . self::e('Evidence') . '</th></tr></thead><tbody>';
                    foreach ($top as $r) {
                        $cls = in_array(($r['classification'] ?? ''), ['emerging', 'sustained', 'peaking'], true) ? 'fiis-badge-ok' : 'fiis-badge-muted';
                        echo '<tr>'
                           . '<td>' . self::e((string) ($r['display_term'] ?? $r['normalized_term'] ?? '')) . '</td>'
                           . '<td class="fiis-muted">' . self::e((string) ($r['observation_type'] ?? '')) . '</td>'
                           . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e((string) ($r['classification'] ?? '')) . '</span></td>'
                           . '<td>' . self::e((string) (float) ($r['trend_score'] ?? 0)) . '</td>'
                           . '<td>' . self::e((string) (float) ($r['confidence_score'] ?? 0)) . '</td>'
                           . '<td>' . self::e((string) (int) ($r['point_count'] ?? 0)) . '</td>'
                           . '<td>' . self::e((string) count((array) ($r['evidence_ids'] ?? []))) . '</td>'
                           . '</tr>';
                    }
                    echo '</tbody></table></div>';
                }
                echo '<p class="fiis-muted">' . self::e('Deterministic scoring (no LLM). Insights are created only for high-confidence, evidence-backed trends.') . '</p>';
            }

            // Phase 4B — backfill readiness.
            if (class_exists('VES_Trend_Backfill_Service')) {
                $p = VES_Trend_Backfill_Service::preview_backfill(['limit' => 500]);
                $last = VES_Trend_Backfill_Service::last_backfill();
                echo '<h4>' . self::e('Backfill readiness (historical DTF reports)') . '</h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><tbody>';
                foreach ([
                    'Candidate reports' => (int) ($p['candidates'] ?? 0),
                    'Eligible' => (int) ($p['eligible'] ?? 0),
                    'Already backfilled' => (int) ($p['already_backfilled'] ?? 0),
                    'Missing payload' => (int) ($p['missing_payload'] ?? 0),
                    'Unsupported format' => (int) ($p['unsupported_format'] ?? 0),
                    'Missing workspace' => (int) ($p['missing_workspace'] ?? 0),
                    'Estimated observations' => (int) ($p['estimated_observations'] ?? 0),
                ] as $label => $val) {
                    echo '<tr><th scope="row" style="text-align:left">' . self::e($label) . '</th><td>' . self::e((string) $val) . '</td></tr>';
                }
                echo '</tbody></table></div>';
                if (!empty($last)) {
                    echo '<p class="fiis-muted">' . self::e('Last backfill: ' . (string) ($last['when'] ?? '') . ' — ' . (int) ($last['reports_processed'] ?? 0) . ' reports, ' . (int) ($last['observations_created'] ?? 0) . ' observations.') . '</p>';
                }
                echo '<p class="fiis-muted">' . self::e('Run via WP-CLI: wp ves trend-obs-backfill --dry-run, then --apply, then wp ves trend-rebuild.') . '</p>';
            }

            // Phase 4B — calibration thresholds + golden-set validation.
            if (class_exists('VES_Trend_Scorer') && method_exists('VES_Trend_Scorer', 'get_thresholds')) {
                $th = VES_Trend_Scorer::get_thresholds();
                echo '<h4>' . self::e('Calibration thresholds') . '</h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Threshold') . '</th><th>' . self::e('Value') . '</th></tr></thead><tbody>';
                foreach ($th as $k => $v) { echo '<tr><th scope="row" style="text-align:left">' . self::e((string) $k) . '</th><td>' . self::e((string) $v) . '</td></tr>'; }
                echo '</tbody></table></div>';
                echo '<p class="fiis-muted">' . self::e('Override via the ves_trend_scorer_thresholds filter (validated + clamped).') . '</p>';
            }
            if (class_exists('VES_Trend_Golden_Set')) {
                $g = VES_Trend_Golden_Set::evaluate();
                $badge = empty($g['failed']) ? 'fiis-badge-ok' : 'fiis-badge-warn';
                echo '<h4>' . self::e('Golden-set validation') . ' <span class="fiis-badge ' . self::ea($badge) . '">' . self::e($g['passed'] . '/' . $g['total']) . '</span></h4>';
                if (!empty($g['failures'])) {
                    echo '<div class="notice notice-warning inline"><p>' . self::e('Classifier golden set has failures — review thresholds:') . '</p><ul>';
                    foreach ($g['failures'] as $f) { echo '<li>' . self::e((string) ($f['name'] ?? '') . ': expected ' . (string) ($f['expected'] ?? '') . ', got ' . (string) ($f['actual'] ?? '')) . '</li>'; }
                    echo '</ul></div>';
                } else {
                    echo '<p class="fiis-muted">' . self::e('All ' . (int) $g['total'] . ' deterministic classifier examples pass.') . '</p>';
                }
            }

            echo '</div>';
        } catch (\Throwable $e) { self::log($e); }
    }

    /**
     * Phase 5 (Part H) — evidence-first insight quality. Admin-only (render() gates
     * manage_options). Counts + a reassessed sample; no prompts/responses/URLs/secrets.
     */
    private static function render_insight_quality() {
        if (!class_exists('VES_Insight_Lifecycle_Service')) { return; }
        try {
            $o = VES_Insight_Lifecycle_Service::quality_overview(0, ['sample' => 10]);
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Insight Quality (Phase 5 — evidence-first)') . '</h3>';

            // Status totals.
            echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Insight status') . '</th><th>' . self::e('Count') . '</th></tr></thead><tbody>';
            $totals = is_array($o['totals'] ?? null) ? $o['totals'] : [];
            if (empty($totals)) { echo '<tr><td class="fiis-muted">' . self::e('No insights yet') . '</td><td>0</td></tr>'; }
            foreach (['draft', 'reviewed', 'approved', 'rejected', 'archived'] as $st) {
                if (!isset($totals[$st])) { continue; }
                echo '<tr><th scope="row" style="text-align:left">' . self::e($st) . '</th><td>' . self::e((string) (int) $totals[$st]) . '</td></tr>';
            }
            echo '</tbody></table></div>';

            echo '<p>' . self::e('Missing evidence') . ': <strong>' . self::e((string) (int) ($o['missing_evidence'] ?? 0)) . '</strong> · '
               . self::e('Sample reassessed') . ': <strong>' . self::e((string) (int) ($o['sample_size'] ?? 0)) . '</strong> · '
               . self::e('Eligible review') . ': <strong>' . self::e((string) (int) ($o['eligible_review'] ?? 0)) . '</strong> · '
               . self::e('Eligible approval') . ': <strong>' . self::e((string) (int) ($o['eligible_approval'] ?? 0)) . '</strong> · '
               . self::e('Below quality') . ': <strong>' . self::e((string) (int) ($o['below_quality'] ?? 0)) . '</strong> · '
               . self::e('Avg quality') . ': <strong>' . self::e((string) (float) ($o['avg_quality'] ?? 0)) . '</strong></p>';
            echo '<p class="fiis-muted">' . self::e('High-score trends without an insight: ') . '<strong>' . self::e((string) (int) ($o['trends_without_insight'] ?? 0)) . '</strong></p>';

            if (!empty($o['recent'])) {
                echo '<h4>' . self::e('Recent insights (reassessed)') . '</h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr>'
                   . '<th>' . self::e('Title') . '</th><th>' . self::e('Status') . '</th><th>' . self::e('Quality') . '</th>'
                   . '<th>' . self::e('Evidence') . '</th><th>' . self::e('Review-eligible') . '</th></tr></thead><tbody>';
                foreach ($o['recent'] as $r) {
                    $evc = (int) ($r['evidence_count'] ?? 0);
                    $evcls = $evc > 0 ? 'fiis-badge-ok' : 'fiis-badge-warn';
                    $elig = !empty($r['eligible_for_review']);
                    echo '<tr>'
                       . '<td>' . self::e((string) ($r['title'] ?? '')) . '</td>'
                       . '<td class="fiis-muted">' . self::e((string) ($r['status'] ?? '')) . '</td>'
                       . '<td>' . self::e((string) (float) ($r['quality_score'] ?? 0)) . '</td>'
                       . '<td><span class="fiis-badge ' . self::ea($evcls) . '">' . self::e((string) $evc) . '</span></td>'
                       . '<td><span class="fiis-badge ' . self::ea($elig ? 'fiis-badge-ok' : 'fiis-badge-muted') . '">' . self::e($elig ? 'yes' : 'no') . '</span></td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '<p class="fiis-muted">' . self::e('Deterministic, evidence-first (no LLM). Reviewed/approved insights always require evidence.') . '</p>';
            echo '</div>';
        } catch (\Throwable $e) { self::log($e); }
    }

    /** Phase 5B — read-only Production Readiness / Staging Validation panel. */
    private static function render_readiness() {
        // Explicit (never blank) when the validation service is not loaded.
        if (!class_exists('VES_Staging_Validation_Service')) {
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Production Readiness (Phase 5B — staging validation)') . '</h3>'
               . '<p><span class="fiis-badge fiis-badge-muted">' . self::e('UNAVAILABLE') . '</span> '
               . self::e('The staging-validation service class is not loaded in this build. Reinstall the plugin ZIP to restore readiness reporting.') . '</p></div>';
            return;
        }
        try {
            $r = VES_Staging_Validation_Service::run_validation(['workspace_id' => 0]);
            // The service builds escaped HTML and always returns non-empty output,
            // including explicit not_run/unavailable states (no silent blank panel).
            echo VES_Staging_Validation_Service::render_admin_html($r);
        } catch (\Throwable $e) {
            self::log($e);
            // Show an explicit NOT-RUN card with a safe (class-name only) detail —
            // never a blank section, never a raw payload.
            echo VES_Staging_Validation_Service::unavailable_html(
                'not_run',
                'Readiness could not be computed in this environment (database or a required service was unavailable). Detail: ' . get_class($e)
            );
        }
    }

    private static function render_intelligence() {
        if (!class_exists('VES_Intelligence_Store')) { return; }
        try {
            $counts = VES_Intelligence_Store::counts(0);
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Intelligence Contract (Phase 2)') . '</h3>';
            echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Entity') . '</th><th>' . self::e('Records') . '</th></tr></thead><tbody>';
            $labels = [
                'sources' => 'Sources', 'signals' => 'Signals', 'evidences' => 'Evidence',
                'insights' => 'Insights', 'briefs' => 'Briefs', 'drafts' => 'Drafts', 'memory_records' => 'Memory records',
            ];
            foreach ($labels as $key => $label) {
                echo '<tr><th scope="row" style="text-align:left">' . self::e($label) . '</th><td>' . self::e((string) ((int) ($counts[$key] ?? 0))) . '</td></tr>';
            }
            echo '</tbody></table></div>';

            $recent = VES_Intelligence_Store::recent_insights(10, 0);
            if (!empty($recent)) {
                echo '<h4>' . self::e('Recent insights') . '</h4>';
                echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr>'
                   . '<th>' . self::e('When (UTC)') . '</th><th>' . self::e('Type') . '</th><th>' . self::e('Title') . '</th>'
                   . '<th>' . self::e('Status') . '</th><th>' . self::e('Evidence') . '</th><th>' . self::e('Confidence') . '</th></tr></thead><tbody>';
                foreach ($recent as $r) {
                    $st = (string) ($r['status'] ?? '');
                    $cls = in_array($st, ['approved', 'reviewed'], true) ? 'fiis-badge-ok' : 'fiis-badge-muted';
                    $evc = (int) ($r['evidence_count'] ?? 0);
                    $evcls = $evc > 0 ? 'fiis-badge-ok' : 'fiis-badge-warn';
                    echo '<tr>'
                       . '<td class="fiis-muted">' . self::e((string) ($r['created_at'] ?? '')) . '</td>'
                       . '<td>' . self::e((string) ($r['insight_type'] ?? '')) . '</td>'
                       . '<td>' . self::e((string) ($r['title'] ?? '')) . '</td>'
                       . '<td><span class="fiis-badge ' . self::ea($cls) . '">' . self::e($st) . '</span></td>'
                       . '<td><span class="fiis-badge ' . self::ea($evcls) . '">' . self::e((string) $evc) . '</span></td>'
                       . '<td>' . self::e((string) (float) ($r['confidence'] ?? 0)) . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table></div>';
                echo '<p class="fiis-muted">' . self::e('Evidence enforcement: reviewed/approved insights require at least one evidence record.') . '</p>';
            }
            echo '</div>';
        } catch (\Throwable $e) { self::log($e); }
    }

    /** Stripe monetization settings (delegated to the billing layer). */
    private static function render_stripe() {
        if (!class_exists('VES_Stripe_Billing')) { return; }
        try {
            echo VES_Stripe_Billing::render_admin_settings_html();
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    private static function render_group(string $group) {
        $groups = self::groups();
        if (!isset($groups[$group])) { self::render_overview(); return; }
        echo '<div class="fiis-console-cards">';
        foreach ($groups[$group][1] as $page) {
            [$slug, $label, $blurb] = $page;
            $url = self::page_url($slug);
            echo '<div class="fiis-card' . ($url === '' ? ' is-muted' : '') . '">';
            echo '<h3>' . self::e($label) . '</h3><p>' . self::e($blurb) . '</p>';
            if ($url !== '') {
                echo '<a class="button button-secondary" href="' . self::eu($url) . '">' . self::e('Open') . '</a>';
            } else {
                echo '<span class="fiis-badge fiis-badge-muted">' . self::e('Not installed') . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private static function render_access() {
        self::render_promo();
        self::render_migrate();
        self::render_demo_setting();
        self::render_leads_export();
        echo self::access_matrix_html();
        self::render_group('access');
    }

    /** One-click: move existing non-admin users onto the restricted free tier. */
    private static function render_migrate() {
        if (!class_exists('VES_Lead_Gate') || !function_exists('admin_url')) { return; }
        try {
            $pending = VES_Lead_Gate::count_non_free_customers();
            $label = $pending > 500 ? '500+' : (string) $pending;
            $migrated = isset($_GET['ves_migrated']) ? max(0, (int) $_GET['ves_migrated']) : -1;
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Fix & apply the free tier (recommended)') . '</h3>';
            echo '<p>' . self::e('One click: (1) writes correct free-tier limits to Credits & Limits — removes the daily-run cap that blocks free users and sets 10 runs/month; (2) moves existing non-admin users onto the restricted FREE plan (Social + TikTok/YouTube/Instagram, 10 requests). Admins are never touched.') . '</p>';
            if ($migrated >= 0) { echo '<div class="notice notice-success inline"><p>' . self::e('Free-tier limits applied. Migrated ' . $migrated . ' user(s) to the free tier.') . '</p></div>'; }
            echo '<p class="fiis-muted">' . self::e($pending > 0 ? ($label . ' non-admin user(s) are not on the free tier yet.') : 'All non-admin users are already on the free tier.') . '</p>';
            echo '<form method="post" action="' . self::eu(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . self::ea(VES_Lead_Gate::MIGRATE) . '">';
            if (function_exists('wp_nonce_field')) { wp_nonce_field(VES_Lead_Gate::MIGRATE); }
            echo '<button type="submit" class="button button-primary">' . self::e('Apply recommended free tier') . '</button>';
            echo '</form>';
            echo '</div>';
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    /** Export captured public leads (name, corp email, phone, LinkedIn, position) as CSV. */
    private static function render_leads_export() {
        if (!class_exists('VES_Lead_Gate') || !function_exists('admin_url')) { return; }
        try {
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Captured leads') . '</h3>';
            echo '<p>' . self::e('Public sign-ups (name, corporate email, phone, LinkedIn, position) are stored on each user and shown on the Users list. Export them as CSV for your CRM.') . '</p>';
            echo '<form method="post" action="' . self::eu(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . self::ea(VES_Lead_Gate::EXPORT) . '">';
            if (function_exists('wp_nonce_field')) { wp_nonce_field(VES_Lead_Gate::EXPORT); }
            echo '<button type="submit" class="button button-secondary">' . self::e('Export leads (CSV)') . '</button>';
            echo '</form></div>';
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    /** Demo booking link used by the "credits exhausted" CTA shown to free users. */
    private static function render_demo_setting() {
        if (!class_exists('VES_Lead_Gate')) { return; }
        try {
            $current = VES_Lead_Gate::booking_url();
            $saved = isset($_GET['ves_demo_saved']);
            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Demo booking link') . '</h3>';
            echo '<p>' . self::e('Shown to free users when their 10 requests run out ("book a free 30-min session"). Paste your Calendly/Cal.com/Meetings URL.') . '</p>';
            if ($saved) { echo '<div class="notice notice-success inline"><p>' . self::e('Saved.') . '</p></div>'; }
            if (function_exists('admin_url')) {
                echo '<form method="post" action="' . self::eu(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . self::ea(VES_Lead_Gate::SAVE_DEMO) . '">';
                if (function_exists('wp_nonce_field')) { wp_nonce_field(VES_Lead_Gate::SAVE_DEMO); }
                echo '<input type="url" name="ves_demo_booking_url" class="regular-text" placeholder="https://calendly.com/your-team/30min" value="' . self::ea($current) . '" style="max-width:420px;width:100%;margin-bottom:8px;">';
                echo '<br><button type="submit" class="button button-primary">' . self::e('Save booking link') . '</button>';
                echo '</form>';
            }
            echo '</div>';
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    /**
     * Operator-triggered "10 free social requests" grant. Nothing happens to live
     * balances until the admin clicks the button (idempotent, batched).
     */
    private static function render_promo() {
        if (!class_exists('VES_Promo_Social_Credits')) { return; }
        try {
            $granted = isset($_GET['ves_promo_granted']) ? max(0, (int) $_GET['ves_promo_granted']) : -1;
            $left = isset($_GET['ves_promo_left']) ? max(0, (int) $_GET['ves_promo_left']) : -1;
            $pending = VES_Promo_Social_Credits::count_pending();
            $pending_label = ($pending > VES_Promo_Social_Credits::BATCH_MAX) ? (VES_Promo_Social_Credits::BATCH_MAX . '+') : (string) $pending;
            $amount = VES_Promo_Social_Credits::credits();

            echo '<div class="fiis-card fiis-promo"><h3>' . self::e('Promotion · 10 free social requests') . '</h3>';
            echo '<p>' . self::e('Grant every subscriber a one-time credit top-up (' . rtrim(rtrim(number_format($amount, 2), '0'), '.') . ' credits ≈ 10 TikTok/YouTube/Instagram analyses). Idempotent — each subscriber is granted only once.') . '</p>';
            if ($granted >= 0) {
                echo '<div class="notice notice-success inline"><p>' . self::e('Granted to ' . $granted . ' subscriber(s).' . ($left > 0 ? ' ' . $left . ' still pending — click again to continue.' : ' All caught up.')) . '</p></div>';
            }
            echo '<p class="fiis-muted">' . self::e($pending > 0 ? ($pending_label . ' subscriber(s) have not been granted yet.') : 'All current subscribers have been granted.') . '</p>';

            if ($pending > 0 && function_exists('admin_url')) {
                echo '<form method="post" action="' . self::eu(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . self::ea(VES_Promo_Social_Credits::ADMIN_ACTION) . '">';
                if (function_exists('wp_nonce_field')) { wp_nonce_field(VES_Promo_Social_Credits::ADMIN_ACTION); }
                echo '<button type="submit" class="button button-primary">' . self::e('Grant to all subscribers') . '</button>';
                echo '</form>';
            }
            echo '</div>';
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    /**
     * Read-only plan/role × module access matrix from the EXISTING access map.
     * Never writes, never exposes raw option keys. Fully guarded. Public for tests.
     */
    public static function access_matrix_html(): string {
        try {
            if (!class_exists('VES_Access_Control')
                || !method_exists('VES_Access_Control', 'get_access_map')
                || !defined('VES_Access_Control::MODULES')) {
                return '<p class="fiis-muted">' . self::e('Access control is not available.') . '</p>';
            }
            $map = VES_Access_Control::get_access_map();
            $modules = VES_Access_Control::MODULES;
            if (!is_array($map) || empty($map) || !is_array($modules) || empty($modules)) {
                return '<p class="fiis-muted">' . self::e('No plan access configuration found yet.') . '</p>';
            }
            $labels = [
                'enabled' => ['On', 'ok'], 'admin_only' => ['Admin', 'warn'],
                'disabled_locked_visible' => ['Locked', 'muted'], 'disabled_hidden' => ['Hidden', 'muted'],
                'coming_soon' => ['Soon', 'muted'],
            ];
            $h = '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . self::e('Plan / Role') . '</th>';
            foreach ($modules as $mod) { $h .= '<th>' . self::e(ucwords(str_replace('-', ' ', (string) $mod))) . '</th>'; }
            $h .= '</tr></thead><tbody>';
            foreach ($map as $plan => $sections) {
                if (!is_array($sections)) { continue; }
                $mstates = isset($sections['modules']) && is_array($sections['modules']) ? $sections['modules'] : [];
                $h .= '<tr><th scope="row">' . self::e(ucwords(str_replace(['-', '_'], ' ', (string) $plan))) . '</th>';
                foreach ($modules as $mod) {
                    $state = isset($mstates[$mod]) ? (string) $mstates[$mod] : 'enabled';
                    $meta = $labels[$state] ?? [ucfirst($state), 'muted'];
                    $h .= '<td><span class="fiis-badge fiis-badge-' . self::ea($meta[1]) . '">' . self::e($meta[0]) . '</span></td>';
                }
                $h .= '</tr>';
            }
            $h .= '</tbody></table></div><p class="fiis-muted">' . self::e('Read-only. Edit access in “Plans & Access”, credit limits in “Credits & Limits”.') . '</p>';
            return $h;
        } catch (\Throwable $e) {
            self::log($e);
            return '<p class="fiis-muted">' . self::e('Access summary unavailable.') . '</p>';
        }
    }

    // ── Helpers (all guarded) ─────────────────────────────────────────────────

    private static function current_tab(): string {
        $raw = isset($_GET['tab']) ? (string) $_GET['tab'] : 'overview';
        $raw = function_exists('sanitize_key') ? sanitize_key($raw) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $raw));
        return array_key_exists($raw, self::tabs()) ? $raw : 'overview';
    }

    private static function tab_url(string $tab): string {
        $base = function_exists('admin_url') ? admin_url('admin.php?page=' . self::PAGE_SLUG) : ('admin.php?page=' . self::PAGE_SLUG);
        return $base . '&tab=' . rawurlencode($tab);
    }

    /** Real URL of an existing page, or '' if it is not registered/installed. */
    private static function page_url(string $slug): string {
        if (function_exists('menu_page_url')) {
            $url = menu_page_url($slug, false);
            if (is_string($url) && $url !== '') { return $url; }
        }
        return '';
    }

    private static function e($v): string { return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES); }
    private static function ea($v): string { return function_exists('esc_attr') ? esc_attr((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES); }
    private static function eu($v): string { return function_exists('esc_url') ? esc_url((string) $v) : (string) $v; }

    private static function log(\Throwable $e): void {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('[VES_Admin_Console] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}
