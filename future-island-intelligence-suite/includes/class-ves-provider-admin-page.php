<?php
if (!defined('ABSPATH')) { exit; }

/** Internal private-beta provider controls and ingestion ledger UI. */
final class VES_Provider_Admin_Page {
    const PAGE_CONTRACTS = 'fi-provider-contracts';
    const PAGE_INGESTIONS = 'fi-provider-ingestions';
    const PAGE_BETA_QA = 'fi-beta-qa';
    const PAGE_BROWSER_VALIDATION = 'fi-staging-browser-validation';
    const ACTION_TOGGLE = 'fi_provider_toggle';
    const ACTION_ROTATE = 'fi_provider_rotate_secret';
    const ACTION_INGESTION = 'fi_provider_ingestion_action';

    public static function register(): void {
        if (function_exists('add_action')) {
            add_action('admin_menu', [__CLASS__, 'admin_menu'], 62);
            add_action('admin_post_' . self::ACTION_TOGGLE, [__CLASS__, 'handle_toggle']);
            add_action('admin_post_' . self::ACTION_ROTATE, [__CLASS__, 'handle_rotate']);
            add_action('admin_post_' . self::ACTION_INGESTION, [__CLASS__, 'handle_ingestion_action']);
        }
    }

    public static function admin_menu(): void {
        if (!function_exists('add_submenu_page')) { return; }
        $parent = 'fi-command-room';
        add_submenu_page($parent, 'Provider Contracts', 'Provider Contracts', 'manage_options', self::PAGE_CONTRACTS, [__CLASS__, 'render_contracts_page']);
        add_submenu_page($parent, 'Provider Ingestions', 'Provider Ingestions', 'manage_options', self::PAGE_INGESTIONS, [__CLASS__, 'render_ingestions_page']);
        add_submenu_page($parent, 'Beta QA', 'Beta QA', 'manage_options', self::PAGE_BETA_QA, [__CLASS__, 'render_beta_qa_page']);
        add_submenu_page($parent, 'Browser Validation', 'Browser Validation', 'manage_options', self::PAGE_BROWSER_VALIDATION, [__CLASS__, 'render_browser_validation_page']);
    }

    public static function render_contracts_page(): void {
        if (!self::can_manage()) { self::deny(); return; }
        $e = [__CLASS__, 'e']; $u = [__CLASS__, 'u'];
        echo '<div class="wrap fi-provider-admin"><h1>' . $e('Future Island Provider Contracts') . '</h1>';
        echo '<p>' . $e('Private-beta provider/orchestrator controls. Providers, simulators, cron jobs, Apify wrappers, Make/Zapier-style workflows, optional n8n examples, or future managed workers return rows through authenticated WordPress callbacks; they never write canonical tables directly.') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . $e('Family') . '</th><th>' . $e('Provider') . '</th><th>' . $e('Module / use case') . '</th><th>' . $e('Schema') . '</th><th>' . $e('Enabled') . '</th><th>' . $e('Auth mode') . '</th><th>' . $e('Token') . '</th><th>' . $e('Rate limit') . '</th><th>' . $e('Last seen') . '</th><th>' . $e('Last error') . '</th><th>' . $e('Actions') . '</th></tr></thead><tbody>';
        $rows = class_exists('VES_Provider_Settings_Service') ? VES_Provider_Settings_Service::public_statuses() : [];
        foreach ($rows as $row) {
            $family = (string) ($row['provider_family'] ?? '');
            $key = (string) ($row['provider_key'] ?? '');
            $enabled = !empty($row['enabled']);
            echo '<tr><td><strong>' . $e(self::provider_family_label($family)) . '</strong><br><small>key: <code>' . $e($family) . '</code></small></td><td><strong>' . $e(self::provider_key_label($key)) . '</strong><br><small>key: <code>' . $e($key) . '</code></small></td><td>' . $e(self::provider_use_case($family, $key)) . '</td><td>' . $e((string) ($row['schema_version'] ?? '')) . '</td><td>' . $e($enabled ? 'enabled' : 'disabled') . '</td><td>' . $e((string) ($row['auth_mode'] ?? '')) . '</td><td>' . $e(!empty($row['secret_configured']) ? 'configured' : 'missing') . '</td><td>' . $e((string) ($row['max_callbacks_per_hour'] ?? '') . '/hour · ' . (string) ($row['max_rows_per_callback'] ?? '') . ' rows') . '</td><td>' . $e((string) ($row['last_seen'] ?? '')) . '</td><td>' . $e((string) ($row['last_error'] ?? '')) . '</td><td>';
            echo self::admin_button(self::ACTION_TOGGLE, $enabled ? 'Disable' : 'Enable', ['provider_family' => $family, 'provider_key' => $key, 'enabled' => $enabled ? '0' : '1']);
            echo ' ' . self::admin_button(self::ACTION_ROTATE, 'Rotate secret', ['provider_family' => $family, 'provider_key' => $key]);
            echo '</td></tr>';
        }
        if (empty($rows)) { echo '<tr><td colspan="11">' . $e('No provider contracts registered.') . '</td></tr>'; }
        echo '</tbody></table><p><a class="button" href="' . $u(self::admin_url(self::PAGE_INGESTIONS)) . '">' . $e('View ingestion ledger') . '</a></p></div>';
    }

    public static function render_ingestions_page(): void {
        if (!self::can_manage()) { self::deny(); return; }
        $e = [__CLASS__, 'e']; $u = [__CLASS__, 'u'];
        $filters = [
            'provider_family' => self::clean_key((string) ($_GET['provider_family'] ?? ''), 60),
            'provider_key' => self::clean_key((string) ($_GET['provider_key'] ?? ''), 80),
            'status' => self::clean_key((string) ($_GET['status'] ?? ''), 40),
            'workspace_id' => max(0, (int) ($_GET['workspace_id'] ?? 0)),
            'run_id' => max(0, (int) ($_GET['run_id'] ?? 0)),
            'reviewed' => self::clean_key((string) ($_GET['reviewed'] ?? ''), 20),
            'has_rejected' => isset($_GET['has_rejected']) && $_GET['has_rejected'] !== '' ? (int) $_GET['has_rejected'] : '',
            'date_from' => self::clean_date((string) ($_GET['date_from'] ?? '')),
            'date_to' => self::clean_date((string) ($_GET['date_to'] ?? '')),
            'limit' => max(1, min(100, (int) ($_GET['limit'] ?? 50))),
            'offset' => max(0, (int) ($_GET['offset'] ?? 0)),
        ];
        $rows = class_exists('VES_Provider_Ingestion_Store') && method_exists('VES_Provider_Ingestion_Store', 'list') ? VES_Provider_Ingestion_Store::list($filters) : [];
        $total = class_exists('VES_Provider_Ingestion_Store') && method_exists('VES_Provider_Ingestion_Store', 'count') ? VES_Provider_Ingestion_Store::count($filters) : count($rows);
        echo '<div class="wrap fi-provider-admin"><h1>' . $e('Future Island Provider Ingestions Ledger') . '</h1>';
        echo '<p>' . $e('Redacted provider ingestion ledger for private-beta operators. Works with signed callback simulator and approved external orchestrators; optional n8n is only one example. Raw payloads and tokens are not displayed.') . '</p>';
        echo '<form method="get" class="fi-provider-filters"><input type="hidden" name="page" value="' . self::ea(self::PAGE_INGESTIONS) . '">';
        foreach (['provider_family'=>'Provider family','provider_key'=>'Provider key','status'=>'Status','workspace_id'=>'Workspace','run_id'=>'Run','date_from'=>'Date from','date_to'=>'Date to'] as $k => $label) { echo '<label style="margin-right:8px">' . $e($label) . ' <input name="' . self::ea($k) . '" value="' . self::ea((string) ($filters[$k] ?? '')) . '"></label>'; }
        echo '<label>Reviewed <select name="reviewed"><option value="">Any</option><option value="reviewed"' . ((($filters['reviewed'] ?? '') === 'reviewed') ? ' selected' : '') . '>Reviewed</option><option value="unreviewed"' . ((($filters['reviewed'] ?? '') === 'unreviewed') ? ' selected' : '') . '>Unreviewed</option></select></label> ';
        echo '<label>Rejected rows <select name="has_rejected"><option value="">Any</option><option value="1"' . (($filters['has_rejected'] === 1) ? ' selected' : '') . '>Has rejected</option><option value="0"' . (($filters['has_rejected'] === 0) ? ' selected' : '') . '>None</option></select></label> ';
        echo '<button class="button">Filter</button> <span>' . $e('Total: ' . (string) $total) . '</span></form>';
        echo '<table class="widefat striped"><thead><tr><th>' . $e('ID') . '</th><th>' . $e('Workspace') . '</th><th>' . $e('Run') . '</th><th>' . $e('Provider') . '</th><th>' . $e('Provider run') . '</th><th>' . $e('Status') . '</th><th>' . $e('Rows') . '</th><th>' . $e('Accepted') . '</th><th>' . $e('Rejected') . '</th><th>' . $e('Created') . '</th><th>' . $e('Links / actions') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0); $run_id = (int) ($row['run_id'] ?? 0); $ws = (int) ($row['workspace_id'] ?? 0);
            $timeline = self::admin_url('fi-command-room', ['run_id' => $run_id]);
            $report = self::admin_url('fi-command-room', ['run_id' => $run_id]);
            $status = (string) ($row['status'] ?? 'unknown');
            echo '<tr><td>#' . $e((string) $id) . '</td><td>#' . $e((string) $ws) . '</td><td><a href="' . $u($timeline) . '">#' . $e((string) $run_id) . '</a></td><td>' . $e((string) ($row['provider_family'] ?? '') . ' / ' . (string) ($row['provider_key'] ?? '')) . '</td><td>' . $e((string) ($row['provider_run_id'] ?? '')) . '</td><td><span class="fi-status-chip fi-ledger-status-' . self::ea($status) . '">' . $e($status) . '</span></td><td>' . $e((string) ($row['row_count'] ?? 0)) . '</td><td>' . $e((string) ($row['accepted_count'] ?? 0)) . '</td><td>' . $e((string) ($row['rejected_count'] ?? 0)) . '</td><td>' . $e((string) ($row['created_at'] ?? '')) . '</td><td>';
            echo '<a href="' . $u($timeline) . '">' . $e('Run timeline') . '</a> · <a href="' . $u($report) . '">' . $e('Decision Report') . '</a><br><details class="fi-redacted-detail"><summary>' . $e('Redacted details') . '</summary><p>' . $e('Raw callback bodies, signatures, cookies, and provider tokens are never shown. Why rejected? Provider contract validation rejected one or more rows; rejected rows stay in the ledger and are not promoted into evidence.') . '</p></details>';
            echo self::admin_button(self::ACTION_INGESTION, 'Mark reviewed', ['ingestion_id' => $id, 'ledger_action' => 'reviewed']);
            echo ' ' . self::admin_button(self::ACTION_INGESTION, 'Void usage', ['ingestion_id' => $id, 'ledger_action' => 'void_usage']);
            echo ' ' . self::admin_button(self::ACTION_INGESTION, 'Archive', ['ingestion_id' => $id, 'ledger_action' => 'archive']);
            echo '</td></tr>';
        }
        if (empty($rows)) { echo '<tr><td colspan="11">' . $e('No provider ingestions recorded yet.') . '</td></tr>'; }
        echo '</tbody></table></div>';
    }

    public static function render_beta_qa_page(): void {
        if (!self::can_manage()) { self::deny(); return; }
        if (class_exists('VES_Operator_QA_Service')) { VES_Operator_QA_Service::render_admin_page(); return; }
        echo '<div class="wrap"><h1>Future Island Beta QA</h1><p>QA service unavailable.</p></div>';
    }

    public static function render_browser_validation_page(): void {
        if (!self::can_manage()) { self::deny(); return; }
        if (class_exists('VES_Operator_QA_Service') && method_exists('VES_Operator_QA_Service', 'render_browser_validation_page')) { VES_Operator_QA_Service::render_browser_validation_page(); return; }
        echo '<div class="wrap"><h1>Future Island Staging Browser Validation</h1><p>Browser validation service unavailable.</p></div>';
    }

    public static function handle_toggle(): void {
        if (!self::can_manage()) { self::deny(); return; }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION_TOGGLE); }
        $family = self::clean_key((string) ($_POST['provider_family'] ?? ''), 60);
        $key = self::clean_key((string) ($_POST['provider_key'] ?? ''), 80);
        $enabled = !empty($_POST['enabled']);
        if (class_exists('VES_Provider_Settings_Service')) { VES_Provider_Settings_Service::set($family, $key, ['enabled' => $enabled]); }
        self::redirect(self::PAGE_CONTRACTS);
    }

    public static function handle_rotate(): void {
        if (!self::can_manage()) { self::deny(); return; }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION_ROTATE); }
        $family = self::clean_key((string) ($_POST['provider_family'] ?? ''), 60);
        $key = self::clean_key((string) ($_POST['provider_key'] ?? ''), 80);
        if (class_exists('VES_Provider_Settings_Service')) { VES_Provider_Settings_Service::rotate_secret($family, $key); }
        self::redirect(self::PAGE_CONTRACTS, ['fi_notice' => 'secret_rotated']);
    }

    public static function handle_ingestion_action(): void {
        if (!self::can_manage()) { self::deny(); return; }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION_INGESTION); }
        $id = max(0, (int) ($_POST['ingestion_id'] ?? 0));
        $action = self::clean_key((string) ($_POST['ledger_action'] ?? ''), 40);
        if (class_exists('VES_Provider_Ingestion_Store')) {
            if ($action === 'reviewed') { VES_Provider_Ingestion_Store::mark_reviewed($id); }
            elseif ($action === 'archive') { VES_Provider_Ingestion_Store::archive($id); }
            elseif ($action === 'void_usage') { method_exists('VES_Provider_Ingestion_Store', 'void_usage_if_no_value') ? VES_Provider_Ingestion_Store::void_usage_if_no_value($id) : VES_Provider_Ingestion_Store::void_usage($id); }
        }
        self::redirect(self::PAGE_INGESTIONS);
    }

    private static function provider_family_label(string $family): string {
        $map = ['social' => 'Social Signal Intelligence', 'aeo' => 'AEO / GEO Capture', 'creative' => 'Creative Intelligence', 'search' => 'Search Intelligence'];
        return $map[$family] ?? ucwords(str_replace(['_', '-'], ' ', $family));
    }

    private static function provider_key_label(string $key): string {
        $map = [
            'google_search' => 'Google Search', 'google_trends' => 'Google Trends', 'google_news' => 'Google News',
            'meta_ads' => 'Meta Ads', 'semrush' => 'Semrush', 'approved_social_provider' => 'Approved Social Provider',
            'manual_provider_row' => 'Manual Provider Row', 'approved_aeo_capture' => 'Approved AEO Capture',
            'manual_creative_analysis' => 'Manual Creative Analysis', 'signed_callback_simulator' => 'Signed Callback Simulator',
        ];
        return $map[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private static function provider_use_case(string $family, string $key): string {
        if ($family === 'social') { return 'Public social/provider rows normalized into Signal Items and evidence candidates'; }
        if ($family === 'aeo') { return 'AI visibility answer capture, parsing, and corrective insight workflow'; }
        if ($family === 'creative') { return 'Creative analysis provider rows and evidence-backed draft/brief support'; }
        if (strpos($key, 'google_') === 0) { return 'Google/Search/Trend intelligence fallback'; }
        return 'Provider callback contract for staging/private-beta operations';
    }

    private static function admin_button(string $action, string $label, array $fields): string {
        $url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $h = '<form method="post" action="' . self::u($url) . '" style="display:inline">';
        if (function_exists('wp_nonce_field')) { $h .= wp_nonce_field($action, '_wpnonce', true, false); }
        $h .= '<input type="hidden" name="action" value="' . self::ea($action) . '">';
        foreach ($fields as $k => $v) { $h .= '<input type="hidden" name="' . self::ea($k) . '" value="' . self::ea((string) $v) . '">'; }
        return $h . '<button type="submit" class="button button-small">' . self::e($label) . '</button></form>';
    }

    private static function admin_url(string $page, array $args = []): string {
        $base = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        $args = array_merge(['page' => $page], $args);
        return function_exists('add_query_arg') ? add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }
    private static function redirect(string $page, array $args = []): void { $url = self::admin_url($page, $args); if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); exit; } }
    private static function can_manage(): bool { return !function_exists('current_user_can') || current_user_can('manage_options'); }
    private static function deny(): void { if (function_exists('wp_die')) { wp_die('Insufficient capability'); } }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_date(string $s): string { $s = trim($s); return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $s) ? $s : ''; }
    private static function e($s): string { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    private static function ea($s): string { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    private static function u($s): string { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
