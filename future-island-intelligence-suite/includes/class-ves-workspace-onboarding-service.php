<?php
if (!defined('ABSPATH')) { exit; }

/** Guided private-beta workspace onboarding state and profile capture. */
final class VES_Workspace_Onboarding_Service {
    const ACTION = 'fi_workspace_onboarding_save';

    public static function register(): void {
        if (function_exists('add_action')) {
            add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_admin_post']);
        }
    }

    public static function handle_admin_post(): void {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { self::die_or_redirect('forbidden'); }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION); }
        $workspace_id = class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : 0;
        $input = is_array($_POST ?? null) ? $_POST : [];
        $res = self::save_profile($input, $workspace_id);
        $ok = is_array($res) && !isset($res['code']);
        $url = function_exists('admin_url') ? admin_url('admin.php?page=fi-command-room') : 'admin.php?page=fi-command-room';
        if (function_exists('add_query_arg')) { $url = add_query_arg(['fi_notice' => $ok ? 'onboarding_saved' : 'onboarding_failed'], $url); }
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); exit; }
    }

    public static function state(int $workspace_id = 0): array {
        $workspace_id = $workspace_id > 0 ? $workspace_id : (class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : 0);
        $workspace = class_exists('VES_Workspace_Membership_Store') ? VES_Workspace_Membership_Store::get_workspace($workspace_id) : null;
        $profile = is_array($workspace) && is_array($workspace['brand_profile'] ?? null) ? $workspace['brand_profile'] : [];
        $steps = [
            'workspace_basics' => $workspace_id > 0,
            'brand_profile' => !empty($profile['brand_name']) || !empty($profile['brand_url']) || !empty($profile['brand_description']),
            'market_language' => !empty($workspace['market']) || !empty($workspace['default_language']),
            'competitors' => !empty($profile['competitors']),
            'first_playbook' => !empty($profile['first_playbook']) || true,
            'provider_mode' => !empty($profile['provider_mode']),
            'usage_mode' => !empty($profile['usage_mode']),
            'review_defaults' => !empty($profile['review_defaults']),
            // Backward-compatible step keys from the private-beta RC sprint.
            'workspace_created' => $workspace_id > 0,
            'brand_context_added' => !empty($profile['brand_name']) || !empty($profile['brand_url']) || !empty($profile['brand_description']),
            'market_language_added' => !empty($workspace['market']) || !empty($workspace['default_language']),
            'competitors_added' => !empty($profile['competitors']),
            'first_playbook_recommended' => true,
        ];
        $onboarding_state = self::derive_onboarding_state($steps, $profile);
        return ['workspace_id' => $workspace_id, 'workspace' => $workspace ?: [], 'brand_profile' => $profile, 'steps' => $steps, 'onboarding_state' => $onboarding_state, 'recommended_first_playbook' => 'brand_market_xray', 'provider_modes' => ['manual_only','signed_callback_simulator','approved_external_orchestrator','optional_n8n_example','provider_disabled'], 'provider_mode_labels' => self::provider_mode_labels(), 'usage_modes' => ['zero_cost_beta','estimate_only','reserve_settle'], 'self_serve_status' => 'private_beta_controlled'];
    }

    /** @return array|WP_Error */
    public static function save_profile(array $input, int $workspace_id = 0) {
        $workspace_id = $workspace_id > 0 ? $workspace_id : (class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : 0);
        if ($workspace_id <= 0) { return self::err('ves_onboarding_no_workspace', 'Workspace is required.'); }
        if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_manage_sources($workspace_id)) { return self::err('ves_onboarding_forbidden', 'Not allowed to update workspace onboarding.'); }
        $profile = [
            'brand_url' => self::url((string) ($input['brand_url'] ?? '')),
            'brand_name' => self::text((string) ($input['brand_name'] ?? ''), 191),
            'brand_description' => self::textarea((string) ($input['brand_description'] ?? ''), 3000),
            'audience' => self::text((string) ($input['audience'] ?? ''), 240),
            'competitors' => self::list_text($input['competitors'] ?? []),
            'keywords' => self::list_text($input['keywords'] ?? []),
            'social_handles' => self::list_text($input['social_handles'] ?? []),
            'first_playbook' => self::playbook((string) ($input['first_playbook'] ?? 'brand_market_xray')),
            'provider_mode' => self::provider_mode((string) ($input['provider_mode'] ?? 'manual_only')),
            'usage_mode' => self::usage_mode((string) ($input['usage_mode'] ?? 'zero_cost_beta')),
            'review_defaults' => [
                'require_human_review' => true,
                'memory_is_context_not_evidence' => true,
                'default_reason_code' => self::text((string) ($input['default_reason_code'] ?? 'needs_specificity'), 80),
            ],
            'onboarding_state' => self::text((string) ($input['onboarding_state'] ?? 'in_progress'), 40),
            'updated_via' => 'workspace_onboarding_orchestration_agnostic_v3',
        ];
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !class_exists('VES_Workspace_Membership_Store')) { return self::err('ves_onboarding_no_db', 'Workspace store unavailable.'); }
        $row = [
            'default_language' => self::text((string) ($input['language'] ?? ''), 20),
            'market' => self::text((string) ($input['market'] ?? ''), 80),
            'timezone' => self::text((string) ($input['timezone'] ?? ''), 80),
            'brand_profile_json' => self::json($profile),
            'updated_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
        $ok = $wpdb->update(VES_Workspace_Membership_Store::workspace_table(), $row, ['id' => $workspace_id], ['%s','%s','%s','%s','%s'], ['%d']);
        if ($ok === false) { return self::err('ves_onboarding_save_failed', 'Workspace onboarding could not be saved.'); }
        $state = self::state($workspace_id);
        if (!empty($input['create_first_run'])) { $state['first_run_id'] = self::create_first_run($workspace_id, $profile); }
        return $state;
    }

    public static function render_panel(array $state = []): string {
        $state = $state ?: self::state();
        $e = static function($s){ return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $steps = [
            'workspace_basics' => 'Workspace',
            'brand_profile' => 'Brand profile',
            'market_language' => 'Market / language',
            'competitors' => 'Competitors',
            'first_playbook' => 'First playbook',
            'provider_mode' => 'Provider mode',
            'usage_mode' => 'Usage mode',
            'review_defaults' => 'Review defaults',
        ];
        $done_count = 0;
        foreach ($steps as $key => $_label) { if (!empty($state['steps'][$key])) { $done_count++; } }
        $pct = count($steps) > 0 ? (int) floor(($done_count / count($steps)) * 100) : 0;
        $h = '<section class="fi-command-panel fi-onboarding-panel fi-onboarding-upgraded" aria-labelledby="fi-onboarding-title">';
        $h .= '<p class="fi-breadcrumb">' . $e('Private beta onboarding') . '</p><h2 id="fi-onboarding-title">' . $e('Workspace onboarding') . '</h2>';
        $h .= '<p>' . $e('Set brand context, choose a first playbook, and select an orchestration-agnostic provider mode. Future Island does not require n8n.') . '</p>';
        $h .= '<div class="fi-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $e((string) $pct) . '"><span style="width:' . $e((string) $pct) . '%"></span></div>';
        $h .= '<p class="fi-meta-line"><strong>' . $e('State') . ':</strong> ' . $e((string) ($state['onboarding_state'] ?? 'not_started')) . ' · ' . $e((string) $done_count . '/' . (string) count($steps) . ' steps ready') . '</p>';
        $h .= '<ol class="fi-onboarding-steps">';
        foreach ($steps as $step => $label) {
            $done = !empty($state['steps'][$step]);
            $h .= '<li class="' . ($done ? 'is-done' : 'is-pending') . '"><span class="fi-step-label">' . $e($label) . '</span><span class="fi-status-chip">' . $e($done ? 'ready' : 'missing') . '</span></li>';
        }
        $h .= '</ol><div class="fi-provider-mode-list"><h3>' . $e('Provider mode') . '</h3><ul>';
        foreach (self::provider_mode_labels() as $key => $label) { $h .= '<li><code>' . $e($key) . '</code> — ' . $e($label) . '</li>'; }
        $h .= '</ul></div>';
        $h .= '<p class="fi-next-action"><strong>' . $e('What happens next:') . '</strong> ' . $e('Run Brand & Market X-Ray, then use the signed callback simulator or an approved external orchestrator to return provider rows through WordPress. Optional n8n is only an example route.') . '</p>';
        $h .= self::render_form($state);
        $h .= '<p><strong>' . $e('Recommended next route') . ':</strong> ' . $e('Brand & Market X-Ray') . '</p></section>';
        return $h;
    }

    private static function render_form(array $state): string {
        $e = static function($s){ return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $a = static function($s){ return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $profile = is_array($state['brand_profile'] ?? null) ? $state['brand_profile'] : [];
        $workspace = is_array($state['workspace'] ?? null) ? $state['workspace'] : [];
        $action = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field(self::ACTION, '_wpnonce', true, false) : '';
        $h = '<details class="fi-onboarding-editor"><summary>' . $e('Edit onboarding profile') . '</summary>';
        $h .= '<form method="post" action="' . $a($action) . '" class="fi-form-grid fi-onboarding-form">' . $nonce . '<input type="hidden" name="action" value="' . $a(self::ACTION) . '">';
        $fields = [
            ['brand_name','Brand name','text',(string) ($profile['brand_name'] ?? '')],
            ['brand_url','Brand URL','url',(string) ($profile['brand_url'] ?? '')],
            ['market','Market','text',(string) ($workspace['market'] ?? '')],
            ['language','Language','text',(string) ($workspace['default_language'] ?? '')],
            ['audience','Audience','text',(string) ($profile['audience'] ?? '')],
            ['competitors','Competitors','text',implode(', ', (array) ($profile['competitors'] ?? []))],
        ];
        foreach ($fields as $f) { $h .= '<label>' . $e($f[1]) . '<input type="' . $a($f[2]) . '" name="' . $a($f[0]) . '" value="' . $a($f[3]) . '"></label>'; }
        $h .= '<label class="fi-field-wide">' . $e('Brand description / notes') . '<textarea name="brand_description" rows="3">' . $e((string) ($profile['brand_description'] ?? '')) . '</textarea></label>';
        $h .= '<label>' . $e('Provider mode') . '<select name="provider_mode">';
        $current_provider = (string) ($profile['provider_mode'] ?? 'manual_only');
        foreach (self::provider_mode_labels() as $key => $label) { $h .= '<option value="' . $a($key) . '"' . ($current_provider === $key ? ' selected' : '') . '>' . $e($key . ' — ' . $label) . '</option>'; }
        $h .= '</select></label>';
        $h .= '<label>' . $e('Usage mode') . '<select name="usage_mode">';
        $current_usage = (string) ($profile['usage_mode'] ?? 'zero_cost_beta');
        foreach (['zero_cost_beta'=>'Zero-cost beta','estimate_only'=>'Estimate only','reserve_settle'=>'Reserve and settle'] as $key => $label) { $h .= '<option value="' . $a($key) . '"' . ($current_usage === $key ? ' selected' : '') . '>' . $e($label) . '</option>'; }
        $h .= '</select></label>';
        $h .= '<label>' . $e('Review default reason') . '<input name="default_reason_code" value="' . $a((string) ($profile['review_defaults']['default_reason_code'] ?? 'needs_specificity')) . '"></label>';
        $h .= '<label class="fi-checkbox-field"><input type="checkbox" name="create_first_run" value="1"> ' . $e('Create first private-beta Run after saving') . '</label>';
        $h .= '<p class="fi-field-wide fi-evidence-note">' . $e('Safe defaults: human review required, memory is context not evidence, provider rows must return through signed WordPress endpoints.') . '</p>';
        $h .= '<p class="fi-field-wide"><button type="submit" class="button button-primary">' . $e('Save onboarding') . '</button></p></form></details>';
        return $h;
    }

    private static function derive_onboarding_state(array $steps, array $profile): string {
        if (empty($steps['workspace_basics'])) { return 'not_started'; }
        if (!empty($profile['first_run_id'])) { return 'first_run_created'; }
        $ready = !empty($steps['brand_profile']) && !empty($steps['market_language']) && !empty($steps['provider_mode']) && !empty($steps['usage_mode']);
        if ($ready && !empty($profile['onboarding_state']) && $profile['onboarding_state'] === 'completed') { return 'completed'; }
        return $ready ? 'ready_for_first_run' : 'in_progress';
    }

    private static function create_first_run(int $workspace_id, array $profile): int {
        if (!class_exists('VES_Run_Service')) { return 0; }
        $run = VES_Run_Service::create_run([
            'workspace_id' => $workspace_id,
            'run_type' => 'brand_xray',
            'trigger_type' => 'workspace_onboarding',
            'input_context' => ['brand_profile' => $profile, 'provider_called' => false],
            'idempotency_key' => 'fi-onboarding-first-run-' . $workspace_id,
        ]);
        return is_numeric($run) ? (int) $run : 0;
    }

    public static function provider_mode_labels(): array {
        return [
            'manual_only' => 'Manual only — no external provider callback required.',
            'signed_callback_simulator' => 'Signed callback simulator — staging-safe local/operator callback trial.',
            'approved_external_orchestrator' => 'Approved external orchestrator — custom worker, cron job, Apify wrapper, Make/Zapier-style workflow, or future managed worker.',
            'optional_n8n_example' => 'Optional n8n example — sample workflow only; Future Island does not require n8n.',
            'provider_disabled' => 'Provider disabled — keep runs manual until provider settings are reviewed.',
        ];
    }

    private static function provider_mode(string $mode): string {
        $mode = self::key($mode, 60);
        if ($mode === 'n8n_callback') { $mode = 'optional_n8n_example'; }
        return in_array($mode, array_keys(self::provider_mode_labels()), true) ? $mode : 'manual_only';
    }
    private static function usage_mode(string $mode): string { $mode = self::key($mode, 40); return in_array($mode, ['zero_cost_beta','estimate_only','reserve_settle'], true) ? $mode : 'zero_cost_beta'; }
    private static function playbook(string $playbook): string { $playbook = self::key($playbook, 80); return in_array($playbook, ['brand_market_xray','social_signal_scan','aeo_prompt_set'], true) ? $playbook : 'brand_market_xray'; }
    private static function key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }

    private static function list_text($value): array { $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value); $out = []; foreach ((array) $items as $item) { $item = self::text((string) $item, 160); if ($item !== '') { $out[] = $item; } } return array_values(array_unique($out)); }
    private static function url(string $s): string { $s = trim($s); if ($s === '') { return ''; } return function_exists('esc_url_raw') ? esc_url_raw($s) : $s; }
    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function json(array $v): string { $j = function_exists('wp_json_encode') ? wp_json_encode($v, JSON_UNESCAPED_UNICODE) : json_encode($v); return is_string($j) ? $j : '{}'; }
    private static function die_or_redirect(string $code): void {
        if (function_exists('wp_die')) { wp_die($code); }
        exit;
    }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code' => $code, 'message' => $message]; }
}
