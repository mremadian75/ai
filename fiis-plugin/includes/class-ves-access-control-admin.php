<?php
/**
 * VES_Access_Control_Admin — Admin UI for editing per-plan module/feature/provider
 * access. Registers a top-level menu page "Plans & Access" under the plugin's
 * settings. Keeps it minimal but functional.
 *
 * v0.9.26.0
 */

if (!defined('ABSPATH')) { exit; }

final class VES_Access_Control_Admin {

    const PAGE_SLUG = 'ves-plans-access';
    // v0.9.28.0 — Phase 1: top-level Intelligence Suite menu with sub-pages.
    const MENU_SLUG          = 'ves-intelligence-suite';
    const PAGE_OVERVIEW      = 'ves-overview';
    const PAGE_CREDITS       = 'ves-credits-usage';
    const PAGE_PROVIDERS     = 'ves-provider-settings';
    const PAGE_AUDIT_LOG     = 'ves-audit-log';
    const PAGE_DIAGNOSTICS   = 'ves-diagnostics';

    public static function register() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_ves_save_access_map', [__CLASS__, 'handle_save_access_map']);
        add_action('admin_post_ves_save_locked_messages', [__CLASS__, 'handle_save_locked_messages']);
        add_action('admin_post_ves_set_user_override', [__CLASS__, 'handle_set_user_override']);
        add_action('admin_post_ves_save_plan_limits', [__CLASS__, 'handle_save_plan_limits']);
    }

    public static function register_menu() {
        // Top-level Intelligence Suite menu.
        add_menu_page(
            'Intelligence Suite',
            'Intelligence Suite',
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_overview_page'],
            'dashicons-chart-line',
            58
        );
        add_submenu_page(self::MENU_SLUG, 'Overview',         'Overview',         'manage_options', self::MENU_SLUG,        [__CLASS__, 'render_overview_page']);
        add_submenu_page(self::MENU_SLUG, 'Plans & Access',   'Plans & Access',   'manage_options', self::PAGE_SLUG,        [__CLASS__, 'render_page']);
        add_submenu_page(self::MENU_SLUG, 'Credits & Limits', 'Credits & Limits', 'manage_options', self::PAGE_CREDITS,     [__CLASS__, 'render_credits_page']);
        add_submenu_page(self::MENU_SLUG, 'Provider Settings','Provider Settings','manage_options', self::PAGE_PROVIDERS,   [__CLASS__, 'render_providers_page']);
        add_submenu_page(self::MENU_SLUG, 'Audit Log',        'Audit Log',        'manage_options', self::PAGE_AUDIT_LOG,   [__CLASS__, 'render_audit_log_page']);
        add_submenu_page(self::MENU_SLUG, 'Diagnostics',      'Diagnostics',      'manage_options', self::PAGE_DIAGNOSTICS, [__CLASS__, 'render_diagnostics_page']);

        // Backward compatibility: keep Tools entry so deep-links from prior versions still work.
        add_management_page(
            'VE Plans & Access',
            'VE Plans & Access',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability', 'ves'));
        }
        $map  = VES_Access_Control::get_access_map();
        $msgs = VES_Access_Control::get_locked_messages();
        $audit = VES_Access_Control::get_audit_log();
        $modules   = VES_Access_Control::MODULES;
        $features  = VES_Access_Control::FEATURES;
        $providers = VES_Access_Control::PROVIDERS;
        $states = [
            VES_Access_Control::STATE_ENABLED      => 'Enabled',
            VES_Access_Control::STATE_LOCKED       => 'Locked (visible with upgrade)',
            VES_Access_Control::STATE_HIDDEN       => 'Hidden',
            VES_Access_Control::STATE_ADMIN_ONLY   => 'Admin only',
            VES_Access_Control::STATE_COMING_SOON  => 'Coming soon',
        ];
        $notice = isset($_GET['updated']) ? sanitize_key(wp_unslash($_GET['updated'])) : '';
        $error  = isset($_GET['error'])   ? sanitize_key(wp_unslash($_GET['error']))   : '';
        $error_detail = '';
        if ($error === 'validation') {
            $error_detail = (string) get_transient('ves_override_error_' . get_current_user_id());
            delete_transient('ves_override_error_' . get_current_user_id());
        }
        ?>
        <div class="wrap">
            <h1>VE Plans &amp; Access</h1>
            <p class="description">
                Configure per-plan module, feature and provider access. Locked items are shown with an upgrade CTA;
                hidden items disappear from the user UI. Server-side AJAX endpoints enforce these rules independently.
            </p>
            <?php if ($notice === 'access_map'): ?>
                <div class="notice notice-success is-dismissible"><p>Access map saved.</p></div>
            <?php elseif ($notice === 'locked_messages'): ?>
                <div class="notice notice-success is-dismissible"><p>Locked messages saved.</p></div>
            <?php elseif ($notice === 'user_override'): ?>
                <div class="notice notice-success is-dismissible"><p>User override saved.</p></div>
            <?php endif; ?>
            <?php if ($error === 'user_not_found'): ?>
                <div class="notice notice-error is-dismissible"><p>User not found. Check the ID or login.</p></div>
            <?php elseif ($error === 'invalid_json'): ?>
                <div class="notice notice-error is-dismissible"><p>Override JSON could not be parsed. Make sure it is valid JSON.</p></div>
            <?php elseif ($error === 'validation' && $error_detail !== ''): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Override rejected — validation errors:</strong></p>
                    <p style="font-family:monospace;font-size:12px"><?php echo esc_html($error_detail); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="title">Plan access matrix</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ves_save_access_map'); ?>
                <input type="hidden" name="action" value="ves_save_access_map">
                <?php foreach ($map as $plan_slug => $plan_access): ?>
                    <fieldset style="border:1px solid #ccd0d4;padding:14px 16px;margin:18px 0;background:#fff">
                        <legend style="padding:0 8px;font-weight:600;font-size:14px">Plan: <?php echo esc_html($plan_slug); ?></legend>
                        <?php foreach ([
                            'modules'   => ['title' => 'Modules',   'list' => $modules],
                            'features'  => ['title' => 'Features',  'list' => $features],
                            'providers' => ['title' => 'Providers', 'list' => $providers],
                        ] as $section_key => $section): ?>
                            <h4 style="margin:14px 0 8px"><?php echo esc_html($section['title']); ?></h4>
                            <table class="widefat striped" style="margin-bottom:8px">
                                <thead>
                                    <tr>
                                        <th style="width:30%">Key</th>
                                        <th>State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($section['list'] as $item_key):
                                    $current = isset($plan_access[$section_key][$item_key])
                                        ? $plan_access[$section_key][$item_key]
                                        : VES_Access_Control::STATE_ENABLED;
                                    $field = sprintf('access[%s][%s][%s]', esc_attr($plan_slug), esc_attr($section_key), esc_attr($item_key));
                                ?>
                                    <tr>
                                        <td><code><?php echo esc_html($item_key); ?></code></td>
                                        <td>
                                            <select name="<?php echo esc_attr($field); ?>">
                                                <?php foreach ($states as $val => $label): ?>
                                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($current, $val); ?>>
                                                        <?php echo esc_html($label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
                <?php submit_button('Save access matrix'); ?>
            </form>

            <hr style="margin:30px 0">

            <h2 class="title">Locked-state messages</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ves_save_locked_messages'); ?>
                <input type="hidden" name="action" value="ves_save_locked_messages">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="msg-default">Default upgrade message</label></th>
                        <td><input type="text" name="messages[default]" id="msg-default" class="regular-text"
                                   value="<?php echo esc_attr($msgs['default'] ?? ''); ?>"></td>
                    </tr>
                    <?php foreach ($msgs as $k => $v):
                        if ($k === 'default' || $k === '_cta_text' || $k === '_cta_url') continue; ?>
                        <tr>
                            <th scope="row"><label>Custom message for <code><?php echo esc_html($k); ?></code></label></th>
                            <td><input type="text" name="messages[<?php echo esc_attr($k); ?>]" class="regular-text"
                                       value="<?php echo esc_attr($v); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><label for="msg-cta-text">CTA text</label></th>
                        <td><input type="text" name="messages[_cta_text]" id="msg-cta-text" class="regular-text"
                                   value="<?php echo esc_attr($msgs['_cta_text'] ?? 'Ver planes'); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msg-cta-url">CTA URL</label></th>
                        <td><input type="url" name="messages[_cta_url]" id="msg-cta-url" class="regular-text"
                                   value="<?php echo esc_attr($msgs['_cta_url'] ?? '#pricing'); ?>"></td>
                    </tr>
                </table>
                <?php submit_button('Save locked messages'); ?>
            </form>

            <hr style="margin:30px 0">

            <h2 class="title">Per-user override</h2>
            <p class="description">
                Grant or revoke specific module/feature/provider access for a single user without changing their plan.
                Format: JSON. Sections: <code>modules</code>, <code>features</code>, <code>providers</code> (state values), <code>limits</code> (numeric caps), <code>policies</code> (charge/refund behavior), <code>expires_at</code>.<br>
                Example: <code>{"modules":{"ads":"enabled"},"limits":{"monthly_credits":5000},"policies":{"refund_failed_provider":"yes"},"expires_at":"2026-12-31"}</code>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ves_set_user_override'); ?>
                <input type="hidden" name="action" value="ves_set_user_override">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="override-user">User ID or username</label></th>
                        <td><input type="text" name="user_lookup" id="override-user" class="regular-text" placeholder="42 or user_login"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="override-json">Override JSON</label></th>
                        <td><textarea name="override_json" id="override-json" class="large-text code" rows="5" placeholder='{"modules":{"ads":"enabled"},"limits":{"monthly_credits":5000}}'></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="override-reason">Reason (audit log)</label></th>
                        <td><input type="text" name="reason" id="override-reason" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button('Save user override'); ?>
            </form>

            <hr style="margin:30px 0">

            <h2 class="title">Audit log (last <?php echo (int) VES_Access_Control::OPT_AUDIT_LIMIT; ?>)</h2>
            <?php if (empty($audit)): ?>
                <p>No audit entries yet.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Editor</th>
                            <th>Event</th>
                            <th>Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($audit) as $entry): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int) ($entry['timestamp'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ($entry['user_id'] ?? 0)); ?></td>
                            <td><code><?php echo esc_html((string) ($entry['event'] ?? '')); ?></code></td>
                            <td><pre style="margin:0;font-size:11px;max-height:120px;overflow:auto"><?php echo esc_html(wp_json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT)); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save_access_map() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        check_admin_referer('ves_save_access_map');
        $raw = isset($_POST['access']) && is_array($_POST['access']) ? wp_unslash($_POST['access']) : [];
        $result = VES_Access_Control::save_access_map($raw);
        $redirect = admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=access_map');
        if (is_wp_error($result)) {
            $redirect = add_query_arg('error', $result->get_error_code(), $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_save_locked_messages() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        check_admin_referer('ves_save_locked_messages');
        $raw = isset($_POST['messages']) && is_array($_POST['messages']) ? wp_unslash($_POST['messages']) : [];
        VES_Access_Control::save_locked_messages($raw);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=locked_messages'));
        exit;
    }

    public static function handle_set_user_override() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        check_admin_referer('ves_set_user_override');
        $lookup = isset($_POST['user_lookup']) ? sanitize_text_field(wp_unslash($_POST['user_lookup'])) : '';
        $json   = isset($_POST['override_json']) ? wp_unslash($_POST['override_json']) : '';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        $user = is_numeric($lookup) ? get_user_by('id', (int) $lookup) : get_user_by('login', $lookup);
        if (!$user) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&error=user_not_found'));
            exit;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&error=invalid_json'));
            exit;
        }
        // v0.9.27.0 — Phase 5: surface allowlist validation errors instead of
        // silently saving rejected fields.
        $result = VES_Access_Control::set_user_override($user->ID, $decoded, $reason);
        if (is_wp_error($result)) {
            $err_data = $result->get_error_data();
            $errors = isset($err_data['errors']) && is_array($err_data['errors']) ? implode(' | ', $err_data['errors']) : $result->get_error_message();
            set_transient('ves_override_error_' . get_current_user_id(), $errors, 60);
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&error=validation'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=user_override'));
        exit;
    }

    // ====================================================================
    // v0.9.28.0 — Phase 1: Sub-page renderers for the top-level menu.
    // Each page is minimal but real, with the data product owners need most.
    // ====================================================================

    public static function render_overview_page() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        $plugin_version = defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '(unknown)';
        $map  = VES_Access_Control::get_access_map();
        $plan_count = count($map);
        $audit = VES_Access_Control::get_audit_log();
        $audit_count = count($audit);
        $has_openai = (bool) get_option('ves_openai_key', '');
        $has_apify  = (bool) get_option('ves_apify_token', '');
        $debug_active = (bool) (current_user_can('manage_options') && isset($_GET['ves_debug']));

        $warnings = [];
        if (!$has_openai) { $warnings[] = ['OpenAI API key missing.', 'AI analysis features cannot run until an admin sets the OpenAI key.', 'error']; }
        if (!$has_apify)  { $warnings[] = ['Apify token missing.', 'Provider runs (TikTok, Instagram, YouTube, Reddit, X, Semrush) cannot dispatch until an admin sets the Apify token.', 'error']; }
        if (empty(get_option(VES_Access_Control::OPT_PLAN_ACCESS, []))) {
            $warnings[] = ['No plan matrix configured.', 'Liberal defaults are active — every module is enabled for every plan. Configure Plans & Access to lock down free-tier modules.', 'warning'];
        }
        if ($debug_active) { $warnings[] = ['Debug mode active for this admin session.', 'Raw provider fields and diagnostics are visible. Remove ?ves_debug=1 from the URL to return to normal admin UI.', 'info']; }
        ?>
        <div class="wrap">
            <h1>Intelligence Suite — Overview</h1>
            <p class="description">Plugin version <code><?php echo esc_html($plugin_version); ?></code></p>

            <?php foreach ($warnings as $w): ?>
                <div class="notice notice-<?php echo esc_attr($w[2]); ?>"><p><strong><?php echo esc_html($w[0]); ?></strong> <?php echo esc_html($w[1]); ?></p></div>
            <?php endforeach; ?>

            <h2 class="title">At a glance</h2>
            <table class="widefat striped" style="max-width:760px;margin-top:8px">
                <tbody>
                    <tr><th style="width:240px">Plugin version</th><td><code><?php echo esc_html($plugin_version); ?></code></td></tr>
                    <tr><th>Plans configured</th><td><?php echo esc_html($plan_count); ?></td></tr>
                    <tr><th>Audit log entries</th><td><?php echo esc_html($audit_count); ?> (last <?php echo (int) VES_Access_Control::OPT_AUDIT_LIMIT; ?> kept)</td></tr>
                    <tr><th>OpenAI configured</th><td><?php echo $has_openai ? '<span style="color:#047857">✓ yes</span>' : '<span style="color:#b91c1c">✗ no</span>'; ?></td></tr>
                    <tr><th>Apify configured</th><td><?php echo $has_apify ? '<span style="color:#047857">✓ yes</span>' : '<span style="color:#b91c1c">✗ no</span>'; ?></td></tr>
                </tbody>
            </table>

            <h2 class="title" style="margin-top:24px">Quick links</h2>
            <ul style="font-size:14px;line-height:1.8">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">Plans &amp; Access — module/feature/provider access per plan</a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_CREDITS)); ?>">Credits &amp; Limits — per-plan credit caps and run limits</a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_PROVIDERS)); ?>">Provider Settings — token status and provider availability</a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_AUDIT_LOG)); ?>">Audit Log — admin changes with previous/new diffs</a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_DIAGNOSTICS)); ?>">Diagnostics — recent failed runs and locked-access attempts</a></li>
            </ul>
        </div>
        <?php
    }

    public static function render_credits_page() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        $plans = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_available_plans() : [];
        $stored_limits = get_option(VES_Access_Control::OPT_PLAN_ACCESS, []);
        $notice = isset($_GET['updated']) ? sanitize_key(wp_unslash($_GET['updated'])) : '';
        // The editable per-plan credit/limit fields. Each plan stores limits
        // under ves_plan_access_map[$plan]['limits'] = [ key => value ].
        $LIMIT_FIELDS = [
            'monthly_credits'                       => ['Monthly credits',                    'int'],
            'signup_credits'                        => ['Sign-up bonus credits',              'int'],
            'max_credits_per_run'                   => ['Max credits per run',                'int'],
            'max_daily_runs'                        => ['Max runs per day',                   'int'],
            'max_monthly_runs'                      => ['Max runs per month',                 'int'],
            'max_concurrent_runs'                   => ['Max concurrent runs',                'int'],
            'max_ai_analyses_per_day'               => ['Max AI analyses per day',            'int'],
            'max_ai_analyses_per_month'             => ['Max AI analyses per month',          'int'],
            'max_selected_items_per_analysis'       => ['Max selected items / batch analysis','int'],
            'max_provider_cost_per_run_usd'         => ['Max provider $ per run',             'float'],
            'default_internal_provider_request_count'=>['Default internal request count',    'int'],
            'max_internal_provider_request_count'   => ['Max internal request count',         'int'],
            'base_scan_fee'                         => ['Base scan fee (credits)',            'int'],
            'per_usable_result_fee'                 => ['Per-usable-result fee (credits)',    'int'],
            'ai_analysis_fee'                       => ['AI analysis fee (credits)',          'int'],
            'batch_analysis_fee'                    => ['Batch analysis fee (credits)',       'int'],
            'creative_analysis_fee'                 => ['Creative analysis fee (credits)',    'int'],
        ];
        $POLICY_FIELDS = [
            'charge_on_success_empty'  => ['Charge on provider success-empty',  ['no'=>'No','yes'=>'Yes']],
            'charge_on_all_filtered'   => ['Charge on all-filtered',            ['no'=>'No','yes'=>'Yes']],
            'refund_failed_provider'   => ['Refund on provider failure',        ['yes'=>'Yes','no'=>'No']],
            'failed_run_policy'        => ['Failed-run policy',                 ['refund_full'=>'Refund full','keep_base_fee'=>'Keep base fee only','keep_all'=>'Keep all']],
        ];
        ?>
        <div class="wrap">
            <h1>Credits &amp; Limits</h1>
            <p class="description">
                Per-plan credit caps, fee structure, and refund policy. These values are stored in <code>ves_plan_access_map</code>
                under each plan's <code>limits</code> sub-key. Existing plans loaded from <code>VES_Usage_Billing::get_available_plans()</code> are
                shown here with their default credit values — overrides in this UI take effect immediately.
            </p>
            <?php if ($notice === 'plan_limits'): ?>
                <div class="notice notice-success is-dismissible"><p>Plan limits saved.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ves_save_plan_limits'); ?>
                <input type="hidden" name="action" value="ves_save_plan_limits">
                <?php foreach ($plans as $slug => $plan):
                    $slug_safe = sanitize_key((string) $slug);
                    $stored = isset($stored_limits[$slug_safe]['limits']) && is_array($stored_limits[$slug_safe]['limits'])
                        ? $stored_limits[$slug_safe]['limits'] : [];
                    $stored_policies = isset($stored_limits[$slug_safe]['policies']) && is_array($stored_limits[$slug_safe]['policies'])
                        ? $stored_limits[$slug_safe]['policies'] : [];
                ?>
                    <fieldset style="border:1px solid #ccd0d4;padding:14px 16px;margin:18px 0;background:#fff">
                        <legend style="padding:0 8px;font-weight:600;font-size:14px"><?php echo esc_html(isset($plan['label']) ? $plan['label'] . ' — ' . $slug_safe : $slug_safe); ?></legend>
                        <h4 style="margin:8px 0">Credit limits</h4>
                        <table class="form-table" role="presentation">
                            <?php foreach ($LIMIT_FIELDS as $key => $meta):
                                $current = isset($stored[$key]) ? $stored[$key] : ($plan[$key] ?? '');
                                $field_name = sprintf('limits[%s][%s]', esc_attr($slug_safe), esc_attr($key));
                            ?>
                                <tr>
                                    <th scope="row"><label><?php echo esc_html($meta[0]); ?></label></th>
                                    <td>
                                        <input type="number" step="<?php echo $meta[1] === 'float' ? '0.01' : '1'; ?>"
                                            name="<?php echo esc_attr($field_name); ?>"
                                            value="<?php echo esc_attr((string) $current); ?>"
                                            class="small-text">
                                        <small style="color:#6b7280;margin-left:8px"><code><?php echo esc_html($key); ?></code></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        <h4 style="margin:16px 0 8px">Policies</h4>
                        <table class="form-table" role="presentation">
                            <?php foreach ($POLICY_FIELDS as $key => $meta):
                                $current = $stored_policies[$key] ?? '';
                                $field_name = sprintf('policies[%s][%s]', esc_attr($slug_safe), esc_attr($key));
                            ?>
                                <tr>
                                    <th scope="row"><label><?php echo esc_html($meta[0]); ?></label></th>
                                    <td>
                                        <select name="<?php echo esc_attr($field_name); ?>">
                                            <option value="">— default —</option>
                                            <?php foreach ($meta[1] as $v => $l): ?>
                                                <option value="<?php echo esc_attr($v); ?>" <?php selected($current, $v); ?>><?php echo esc_html($l); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small style="color:#6b7280;margin-left:8px"><code><?php echo esc_html($key); ?></code></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </fieldset>
                <?php endforeach; ?>
                <?php submit_button('Save plan limits'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save_plan_limits() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        check_admin_referer('ves_save_plan_limits');
        $limits_in   = isset($_POST['limits'])   && is_array($_POST['limits'])   ? wp_unslash($_POST['limits'])   : [];
        $policies_in = isset($_POST['policies']) && is_array($_POST['policies']) ? wp_unslash($_POST['policies']) : [];
        $current = get_option(VES_Access_Control::OPT_PLAN_ACCESS, []);
        if (!is_array($current)) { $current = []; }

        foreach ($limits_in as $plan_slug => $kv) {
            $plan_slug = sanitize_key((string) $plan_slug);
            if ($plan_slug === '' || !is_array($kv)) { continue; }
            foreach ($kv as $key => $val) {
                $key = sanitize_key((string) $key);
                if ($key === '') { continue; }
                if ($val === '' || $val === null) {
                    if (isset($current[$plan_slug]['limits'][$key])) {
                        unset($current[$plan_slug]['limits'][$key]);
                    }
                } else {
                    $num = is_numeric($val) ? (float) $val : 0;
                    // v0.9.28.1 — Bug #2 fix. Clamp negative numbers to 0. There
                    // is no sane "negative monthly credits" interpretation, but
                    // the previous version stored whatever the admin typed.
                    if ($num < 0) { $num = 0; }
                    // Cast int-only fields to int.
                    $current[$plan_slug]['limits'][$key] = (in_array($key, ['max_provider_cost_per_run_usd'], true))
                        ? round($num, 2)
                        : (int) $num;
                }
            }
        }
        foreach ($policies_in as $plan_slug => $kv) {
            $plan_slug = sanitize_key((string) $plan_slug);
            if ($plan_slug === '' || !is_array($kv)) { continue; }
            foreach ($kv as $key => $val) {
                $key = sanitize_key((string) $key);
                $val = sanitize_key((string) $val);
                if ($key === '') { continue; }
                if ($val === '') {
                    if (isset($current[$plan_slug]['policies'][$key])) {
                        unset($current[$plan_slug]['policies'][$key]);
                    }
                } else {
                    $current[$plan_slug]['policies'][$key] = $val;
                }
            }
        }
        $prev_full = get_option(VES_Access_Control::OPT_PLAN_ACCESS, []);
        update_option(VES_Access_Control::OPT_PLAN_ACCESS, $current, false);
        // Audit.
        $diff = [];
        foreach (array_unique(array_merge(array_keys(is_array($prev_full) ? $prev_full : []), array_keys($current))) as $slug) {
            $before_l = isset($prev_full[$slug]['limits']) ? $prev_full[$slug]['limits'] : [];
            $after_l  = isset($current[$slug]['limits']) ? $current[$slug]['limits'] : [];
            $before_p = isset($prev_full[$slug]['policies']) ? $prev_full[$slug]['policies'] : [];
            $after_p  = isset($current[$slug]['policies']) ? $current[$slug]['policies'] : [];
            if ($before_l !== $after_l || $before_p !== $after_p) {
                $diff[$slug] = [
                    'limits'   => ['previous' => $before_l, 'new' => $after_l],
                    'policies' => ['previous' => $before_p, 'new' => $after_p],
                ];
            }
        }
        if (!empty($diff)) {
            $log = get_option(VES_Access_Control::OPT_AUDIT_LOG, []);
            if (!is_array($log)) { $log = []; }
            $log[] = [
                'event'     => 'plan_limits_saved',
                'payload'   => ['diff' => $diff, 'editor' => get_current_user_id()],
                'timestamp' => time(),
                'user_id'   => get_current_user_id(),
            ];
            $log = array_slice($log, -VES_Access_Control::OPT_AUDIT_LIMIT);
            update_option(VES_Access_Control::OPT_AUDIT_LOG, $log, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_CREDITS . '&updated=plan_limits'));
        exit;
    }

    public static function render_providers_page() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        $has_openai = (bool) get_option('ves_openai_key', '');
        $has_apify  = (bool) get_option('ves_apify_token', '');
        $providers = VES_Access_Control::PROVIDERS;
        ?>
        <div class="wrap">
            <h1>Provider Settings</h1>
            <p class="description">
                Token status (without revealing the tokens themselves) and the list of providers the access matrix knows about.
                Per-plan provider access is configured in <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">Plans &amp; Access</a>.
            </p>
            <h2 class="title">Tokens</h2>
            <table class="widefat striped" style="max-width:560px">
                <tbody>
                    <tr><th style="width:200px">OpenAI API key</th><td><?php echo $has_openai ? '<span style="color:#047857">✓ configured</span>' : '<span style="color:#b91c1c">✗ missing</span>'; ?></td></tr>
                    <tr><th>Apify token</th><td><?php echo $has_apify ? '<span style="color:#047857">✓ configured</span>' : '<span style="color:#b91c1c">✗ missing</span>'; ?></td></tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px">Tokens are managed in the existing plugin Settings page; this view is read-only to avoid surfacing secret values across screens.</p>

            <h2 class="title" style="margin-top:24px">Known providers</h2>
            <table class="widefat striped" style="max-width:560px">
                <thead><tr><th>Provider key</th><th>Used by</th></tr></thead>
                <tbody>
                <?php
                $provider_modules = [
                    'tiktok'        => 'Social, Trend',
                    'instagram'     => 'Social',
                    'youtube'       => 'Social, Trend',
                    'reddit'        => 'Social, Trend',
                    'twitter'       => 'Social, Trend',
                    'facebook'      => 'Social',
                    'pinterest'     => 'Social',
                    'linkedin'      => 'LinkedIn',
                    'google_search' => 'Google Intelligence, Trend fallback',
                    'google_trends' => 'Trend Finder',
                    'google_news'   => 'Trend Finder, Google Intelligence',
                    'google_ads'    => 'Ads Intelligence',
                    'meta_ads'      => 'Ads Intelligence',
                    'semrush'       => 'SEO',
                    'moz'           => 'SEO',
                    'ahrefs'        => 'SEO',
                ];
                foreach ($providers as $p):
                ?>
                    <tr><td><code><?php echo esc_html($p); ?></code></td><td><?php echo esc_html($provider_modules[$p] ?? '—'); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_audit_log_page() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        $audit = VES_Access_Control::get_audit_log();
        ?>
        <div class="wrap">
            <h1>Audit Log</h1>
            <p class="description">Last <?php echo (int) VES_Access_Control::OPT_AUDIT_LIMIT; ?> admin changes. Each entry includes previous and new values where applicable.</p>
            <?php if (empty($audit)): ?>
                <p>No audit entries yet.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr><th style="width:140px">Time</th><th style="width:60px">Editor</th><th style="width:160px">Event</th><th>Payload</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($audit) as $entry): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int) ($entry['timestamp'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) ($entry['user_id'] ?? 0)); ?></td>
                            <td><code><?php echo esc_html((string) ($entry['event'] ?? '')); ?></code></td>
                            <td><details><summary style="cursor:pointer">View diff</summary><pre style="margin:6px 0 0;font-size:11px;max-height:240px;overflow:auto;background:#f7f9fc;padding:8px;border-radius:4px;"><?php echo esc_html(wp_json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_diagnostics_page() {
        if (!current_user_can('manage_options')) { wp_die('No cap'); }
        ?>
        <div class="wrap">
            <h1>Diagnostics</h1>
            <p class="description">
                Operational diagnostics. To see raw provider payloads and the internal preflight panel on the frontend, append
                <code>?ves_debug=1</code> to a page URL while logged in as an admin.
            </p>
            <h2 class="title">Recent failed runs and locked-access attempts</h2>
            <p>
                The plugin records locked-access attempts and provider errors through the audit log and the run lifecycle in
                <code>VES_Usage_Billing</code>. A dedicated diagnostics table is on the roadmap; for now the
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_AUDIT_LOG)); ?>">Audit Log</a>
                contains admin changes, and per-run diagnostics surface in each module's result card when
                <code>?ves_debug=1</code> is active.
            </p>
            <h3 class="title" style="margin-top:18px">How to read provider outcomes</h3>
            <ul style="font-size:13px;line-height:1.7">
                <li><code>success_with_results</code> — provider returned usable rows.</li>
                <li><code>success_empty</code> — provider succeeded but returned zero rows. <strong>Not</strong> a failure.</li>
                <li><code>success_all_filtered</code> — provider returned rows, all dropped by local filters. User should relax filters.</li>
                <li><code>partial_success</code> — multi-source run; at least one source produced usable data.</li>
                <li><code>access_failed</code> — real auth/rental/approval problem. Only this maps to "access failure" copy.</li>
                <li><code>invalid_input</code> / <code>preflight_blocked</code> — blocked before the provider call; no credits charged.</li>
            </ul>
        </div>
        <?php
    }
}

// Register on plugin boot.
add_action('plugins_loaded', function () {
    if (class_exists('VES_Access_Control')) {
        VES_Access_Control_Admin::register();
    }
});
