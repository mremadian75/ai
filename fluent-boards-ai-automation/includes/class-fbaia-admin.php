<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI controller — the "AI Project Intelligence Command Center".
 *
 * The page is organised as server-rendered tabs. Each settings tab posts ONLY its own
 * section, so a partial/truncated POST can never wipe settings that belong to another
 * tab. Every settings form also carries a hidden completeness sentinel; if it is missing
 * on save the request was truncated (e.g. PHP max_input_vars) and the save is rejected
 * instead of persisting incomplete data.
 */
class FBAIA_Admin
{
    const MENU_SLUG = 'fluent-boards-ai-automation';

    public function register()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_init', [$this, 'maybe_dismiss_setup_notice']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_notices', [$this, 'dependency_notice']);
        add_action('admin_notices', [$this, 'setup_notice']);
    }

    public function menu()
    {
        // Top-level menu so the plugin is easy to find (it used to be tucked under Settings).
        add_menu_page(
            __('Fluent Boards AI Automation', 'fluent-boards-ai-automation'),
            __('Fluent Boards AI', 'fluent-boards-ai-automation'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'page'],
            'dashicons-superhero-alt',
            58
        );

        // Knowledge Library as a submenu (plain manage_options; the CPT itself stays out of
        // the core menu to avoid capability/menu interference).
        if (class_exists('FBAIA_Knowledge_Library') && post_type_exists(FBAIA_Knowledge_Library::CPT)) {
            add_submenu_page(
                self::MENU_SLUG,
                __('AI Company Knowledge', 'fluent-boards-ai-automation'),
                __('AI Company Knowledge', 'fluent-boards-ai-automation'),
                'manage_options',
                'edit.php?post_type=' . FBAIA_Knowledge_Library::CPT
            );
        }
    }

    public function assets($hook)
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }
        wp_enqueue_style('fbaia-admin', FBAIA_URL . 'assets/admin.css', [], FBAIA_VERSION);
        wp_enqueue_script('fbaia-admin', FBAIA_URL . 'assets/admin.js', ['jquery'], FBAIA_VERSION, true);
        wp_localize_script('fbaia-admin', 'FBAIAAdmin', [
            'templates' => $this->ui_templates(),
            'copied' => __('Copied', 'fluent-boards-ai-automation'),
            'unsavedWarning' => __('You have unsaved changes in this tab. Leave without saving?', 'fluent-boards-ai-automation'),
            'restRoot' => esc_url_raw(rest_url('fb-ai-automation/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'cmd' => [
                'thinking' => __('Thinking…', 'fluent-boards-ai-automation'),
                'confirm' => __('Confirm', 'fluent-boards-ai-automation'),
                'cancel' => __('Cancel', 'fluent-boards-ai-automation'),
                'listening' => __('Listening…', 'fluent-boards-ai-automation'),
                'micUnsupported' => __('Voice input is not supported in this browser.', 'fluent-boards-ai-automation'),
                'error' => __('Command failed.', 'fluent-boards-ai-automation'),
                'done' => __('Done', 'fluent-boards-ai-automation'),
            ],
        ]);
    }

    public function dependency_notice()
    {
        if (!current_user_can('activate_plugins') || !$this->is_our_page()) {
            return;
        }
        $adapter = new FBAIA_FluentBoards_Adapter();
        if ($adapter->is_available()) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Fluent Boards AI Automation:', 'fluent-boards-ai-automation') . '</strong> '
            . esc_html__('Fluent Boards was not detected yet. Install and activate the official Fluent Boards / Fluent Boards Pro plugin before relying on task hooks. The add-on stays safely inactive until then.', 'fluent-boards-ai-automation')
            . '</p></div>';
    }

    private function is_our_page()
    {
        return isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === self::MENU_SLUG;
    }

    /**
     * Site-wide setup banner so the plugin is obviously present and points to the next step.
     * Shows only while setup is incomplete (no API key or engine still off), is dismissible
     * per user, and never shows on the plugin's own page (which has its own guidance).
     */
    public function setup_notice()
    {
        if (!current_user_can('manage_options') || $this->is_our_page()) {
            return;
        }
        if (get_user_meta(get_current_user_id(), 'fbaia_setup_notice_dismissed', true)) {
            return;
        }
        $settings = FBAIA_Helpers::get_settings();
        $has_key = !empty($settings['api_key']);
        $enabled = (($settings['enabled'] ?? 'no') === 'yes');
        if ($has_key && $enabled) {
            return; // Fully connected — nothing to nag about.
        }

        $next = !$has_key
            ? __('add your AI provider key', 'fluent-boards-ai-automation')
            : __('turn the engine on', 'fluent-boards-ai-automation');
        $url = FBAIA_Helpers::admin_url();
        $dismiss = wp_nonce_url(add_query_arg('fbaia_dismiss_setup', '1'), 'fbaia_dismiss_setup');
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e('Fluent Boards AI is installed but not connected yet.', 'fluent-boards-ai-automation'); ?></strong>
                <?php echo esc_html(sprintf(__('To start, %s.', 'fluent-boards-ai-automation'), $next)); ?>
                <a href="<?php echo esc_url($url); ?>" class="button button-primary" style="margin-left:6px;"><?php esc_html_e('Open Fluent Boards AI', 'fluent-boards-ai-automation'); ?></a>
                <a href="<?php echo esc_url($dismiss); ?>" style="margin-left:6px;"><?php esc_html_e('Dismiss', 'fluent-boards-ai-automation'); ?></a>
            </p>
        </div>
        <?php
    }

    public function maybe_dismiss_setup_notice()
    {
        if (!isset($_GET['fbaia_dismiss_setup']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('fbaia_dismiss_setup');
        update_user_meta(get_current_user_id(), 'fbaia_setup_notice_dismissed', 1);
        wp_safe_redirect(remove_query_arg(['fbaia_dismiss_setup', '_wpnonce']));
        exit;
    }

    private function tabs()
    {
        return [
            'dashboard'    => __('Dashboard', 'fluent-boards-ai-automation'),
            'provider'     => __('AI Provider', 'fluent-boards-ai-automation'),
            'intelligence' => __('Task Intelligence', 'fluent-boards-ai-automation'),
            'automations'  => __('Automations', 'fluent-boards-ai-automation'),
            'memory'       => __('Company Memory', 'fluent-boards-ai-automation'),
            'review'       => __('Review Queue', 'fluent-boards-ai-automation'),
            'diagnostics'  => __('Logs & Health', 'fluent-boards-ai-automation'),
        ];
    }

    private function current_tab()
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
        return array_key_exists($tab, $this->tabs()) ? $tab : 'dashboard';
    }

    /**
     * URL to the plugin page for a given tab, with optional extra query args.
     */
    private function tab_url($tab, array $args = [])
    {
        $args = array_merge(['tab' => $tab], $args);
        return add_query_arg($args, FBAIA_Helpers::admin_url());
    }

    // ---------------------------------------------------------------------
    // Action handling
    // ---------------------------------------------------------------------

    public function handle_actions()
    {
        if (!isset($_POST['fbaia_save_settings'])
            && !isset($_POST['fbaia_save_recipes'])
            && !isset($_POST['fbaia_save_recipes_builder'])
            && !isset($_POST['fbaia_apply_recipe_action'])
            && !isset($_POST['fbaia_reject_recipe_action'])
            && !isset($_POST['fbaia_clear_logs'])
            && !isset($_POST['fbaia_clear_audit'])
            && !isset($_POST['fbaia_export_snapshot'])
            && !isset($_POST['fbaia_reset_usage'])
            && !isset($_POST['fbaia_triage_recent'])
            && !isset($_POST['fbaia_apply_suggestion'])
            && !isset($_POST['fbaia_reject_suggestion'])
            && !isset($_POST['fbaia_import_knowledge'])
            && !isset($_POST['fbaia_test_openai'])
            && !isset($_POST['fbaia_analyze_task'])
            && !isset($_POST['fbaia_run_overdue_scan'])
            && !isset($_POST['fbaia_send_digest'])
            && !isset($_POST['fbaia_run_health'])
            && !isset($_POST['fbaia_send_weekly_intelligence'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['fbaia_save_settings'])) {
            check_admin_referer('fbaia_save_settings');
            $this->handle_save_settings();
            // handle_save_settings() always redirects + exits.
        }

        if (isset($_POST['fbaia_save_recipes'])) {
            check_admin_referer('fbaia_save_recipes');
            $raw = isset($_POST['fbaia_recipes_json']) ? wp_unslash($_POST['fbaia_recipes_json']) : '';
            $result = class_exists('FBAIA_Recipes') ? FBAIA_Recipes::save_from_raw($raw) : ['saved' => 0];
            if (class_exists('FBAIA_Plugin')) {
                FBAIA_Plugin::reschedule_events();
            }
            $this->redirect('automations', ['fbaia_recipes_saved' => (string) absint($result['saved'] ?? 0)]);
        }

        if (isset($_POST['fbaia_save_recipes_builder'])) {
            check_admin_referer('fbaia_save_recipes_builder');
            $count = 0;
            if (class_exists('FBAIA_Recipes')) {
                $structured = (isset($_POST['fbaia_recipe']) && is_array($_POST['fbaia_recipe'])) ? wp_unslash($_POST['fbaia_recipe']) : [];
                $recipes = FBAIA_Recipes::from_structured($structured);
                update_option(FBAIA_Recipes::OPTION, $recipes, false);
                $count = count($recipes);
                if (class_exists('FBAIA_Plugin')) {
                    FBAIA_Plugin::reschedule_events();
                }
            }
            $this->redirect('automations', ['fbaia_recipes_saved' => (string) $count]);
        }

        if (isset($_POST['fbaia_apply_recipe_action'])) {
            check_admin_referer('fbaia_apply_recipe_action');
            $id = isset($_POST['fbaia_action_id']) ? sanitize_text_field(wp_unslash($_POST['fbaia_action_id'])) : '';
            $result = class_exists('FBAIA_Recipe_Queue') ? FBAIA_Recipe_Queue::approve($id) : new WP_Error('missing', 'Queue unavailable.');
            $this->redirect('review', ['fbaia_recipe_action' => is_wp_error($result) ? 'failed' : 'applied']);
        }

        if (isset($_POST['fbaia_reject_recipe_action'])) {
            check_admin_referer('fbaia_reject_recipe_action');
            $id = isset($_POST['fbaia_action_id']) ? sanitize_text_field(wp_unslash($_POST['fbaia_action_id'])) : '';
            $result = class_exists('FBAIA_Recipe_Queue') ? FBAIA_Recipe_Queue::reject($id) : new WP_Error('missing', 'Queue unavailable.');
            $this->redirect('review', ['fbaia_recipe_action' => is_wp_error($result) ? 'failed' : 'rejected']);
        }

        if (isset($_POST['fbaia_clear_logs'])) {
            check_admin_referer('fbaia_clear_logs');
            FBAIA_Logger::clear();
            $this->redirect('diagnostics', ['fbaia_logs_cleared' => '1']);
        }

        if (isset($_POST['fbaia_clear_audit'])) {
            check_admin_referer('fbaia_clear_audit');
            if (class_exists('FBAIA_Audit_Trail')) {
                FBAIA_Audit_Trail::clear();
            }
            $this->redirect('diagnostics', ['fbaia_audit_cleared' => '1']);
        }

        if (isset($_POST['fbaia_export_snapshot'])) {
            check_admin_referer('fbaia_export_snapshot');
            if (class_exists('FBAIA_Exporter')) {
                FBAIA_Exporter::stream_json();
            }
            $this->redirect('diagnostics', ['fbaia_export' => 'failed']);
        }

        if (isset($_POST['fbaia_reset_usage'])) {
            check_admin_referer('fbaia_reset_usage');
            if (class_exists('FBAIA_Usage_Tracker')) {
                FBAIA_Usage_Tracker::reset();
            }
            FBAIA_Logger::add('info', 'AI usage stats reset by admin', ['user_id' => get_current_user_id()]);
            $this->redirect('diagnostics', ['fbaia_usage_reset' => '1']);
        }

        if (isset($_POST['fbaia_triage_recent'])) {
            check_admin_referer('fbaia_triage_recent');
            $queued = $this->run_batch_triage();
            $this->redirect('dashboard', ['fbaia_triage_queued' => (string) $queued]);
        }

        if (isset($_POST['fbaia_apply_suggestion'])) {
            check_admin_referer('fbaia_apply_suggestion');
            $suggestion_id = isset($_POST['fbaia_suggestion_id']) ? sanitize_text_field(wp_unslash($_POST['fbaia_suggestion_id'])) : '';
            $result = class_exists('FBAIA_Suggestion_Store') ? FBAIA_Suggestion_Store::apply($suggestion_id) : new WP_Error('missing_store', 'Suggestion store is not available.');
            $this->redirect('review', ['fbaia_suggestion' => is_wp_error($result) ? 'failed' : 'applied']);
        }

        if (isset($_POST['fbaia_reject_suggestion'])) {
            check_admin_referer('fbaia_reject_suggestion');
            $suggestion_id = isset($_POST['fbaia_suggestion_id']) ? sanitize_text_field(wp_unslash($_POST['fbaia_suggestion_id'])) : '';
            $feedback = isset($_POST['fbaia_feedback']) ? sanitize_textarea_field(wp_unslash($_POST['fbaia_feedback'])) : '';
            $result = class_exists('FBAIA_Suggestion_Store') ? FBAIA_Suggestion_Store::reject($suggestion_id, $feedback) : new WP_Error('missing_store', 'Suggestion store is not available.');
            $this->redirect('review', ['fbaia_suggestion' => is_wp_error($result) ? 'failed' : 'rejected']);
        }

        if (isset($_POST['fbaia_import_knowledge'])) {
            check_admin_referer('fbaia_import_knowledge');
            $settings = FBAIA_Helpers::get_settings();
            $raw = (string) ($settings['knowledge_entries'] ?? '');
            $result = class_exists('FBAIA_Knowledge_Library') ? FBAIA_Knowledge_Library::import_legacy_entries($raw) : new WP_Error('missing_library', 'Knowledge library is not available.');
            if (is_wp_error($result)) {
                $this->redirect('memory', ['fbaia_import' => 'failed']);
            }
            $this->redirect('memory', ['fbaia_import' => (string) absint($result['created'] ?? 0)]);
        }

        if (isset($_POST['fbaia_test_openai'])) {
            check_admin_referer('fbaia_test_openai');
            $client = new FBAIA_OpenAI_Client();
            $result = $client->test();
            if (is_wp_error($result)) {
                FBAIA_Logger::add('error', 'OpenAI connection test failed', ['error' => $result->get_error_message()]);
                $this->redirect('provider', ['fbaia_test' => 'failed']);
            }
            FBAIA_Logger::add('success', 'OpenAI connection test passed', [
                'model' => $result['model'] ?? '',
                'usage' => $result['usage'] ?? [],
                'context_summary' => $result['context_summary'] ?? [],
            ]);
            $this->redirect('provider', ['fbaia_test' => 'ok']);
        }

        if (isset($_POST['fbaia_analyze_task'])) {
            check_admin_referer('fbaia_analyze_task');
            $task_id = isset($_POST['fbaia_manual_task_id']) ? absint($_POST['fbaia_manual_task_id']) : 0;
            $adapter = new FBAIA_FluentBoards_Adapter();
            $task = $task_id ? $adapter->get_task($task_id) : null;
            if (!$task) {
                FBAIA_Logger::add('error', 'Manual task analysis failed: task not found', ['task_id' => $task_id]);
                $this->redirect('dashboard', ['fbaia_manual' => 'failed']);
            }
            $runner = new FBAIA_Runner();
            $runner->queue('external_event', [
                'task_id' => $task_id,
                'task' => $adapter->task_to_array($task),
                'manual_analysis' => true,
                'requested_by' => get_current_user_id(),
            ], true);
            $this->redirect('dashboard', ['fbaia_manual' => 'queued']);
        }

        if (isset($_POST['fbaia_run_overdue_scan'])) {
            check_admin_referer('fbaia_run_overdue_scan');
            $actions = new FBAIA_Internal_Actions(FBAIA_Helpers::get_settings());
            $actions->process_overdue_scan();
            $this->redirect('diagnostics', ['fbaia_overdue_scan' => '1']);
        }

        if (isset($_POST['fbaia_send_digest'])) {
            check_admin_referer('fbaia_send_digest');
            $actions = new FBAIA_Internal_Actions(FBAIA_Helpers::get_settings());
            $actions->send_daily_digest();
            $this->redirect('diagnostics', ['fbaia_digest_sent' => '1']);
        }

        if (isset($_POST['fbaia_run_health'])) {
            check_admin_referer('fbaia_run_health');
            $health = class_exists('FBAIA_Health_Check') ? FBAIA_Health_Check::diagnostics() : [];
            FBAIA_Logger::add('info', 'Health diagnostics generated', ['checks' => $health['checks'] ?? []]);
            $this->redirect('diagnostics', ['fbaia_health' => '1']);
        }

        if (isset($_POST['fbaia_send_weekly_intelligence'])) {
            check_admin_referer('fbaia_send_weekly_intelligence');
            if (class_exists('FBAIA_Insights')) {
                FBAIA_Insights::send_weekly_digest();
            }
            $this->redirect('diagnostics', ['fbaia_weekly_sent' => '1']);
        }
    }

    private function handle_save_settings()
    {
        $section = isset($_POST['fbaia_section']) ? sanitize_key(wp_unslash($_POST['fbaia_section'])) : '';
        $raw = (isset($_POST['fbaia']) && is_array($_POST['fbaia'])) ? wp_unslash($_POST['fbaia']) : [];

        // Truncation guard. The sentinel is rendered as the LAST field of the form, so if
        // the request was clipped (max_input_vars / post_max_size) it disappears first.
        // Reject the save instead of persisting a half-empty payload over good data.
        if (!isset($raw[FBAIA_Helpers::FORM_SENTINEL])) {
            FBAIA_Logger::add('warning', 'Settings save rejected: incomplete/truncated request', [
                'section' => $section,
                'fields_received' => count($raw),
            ]);
            $this->redirect($section ?: 'provider', ['fbaia_saved' => 'truncated']);
        }
        unset($raw[FBAIA_Helpers::FORM_SENTINEL]);

        if ($section !== '' && FBAIA_Helpers::section_keys($section)) {
            FBAIA_Helpers::update_settings_section($section, $raw);
        } else {
            // Legacy/whole-form fallback (still non-destructive).
            FBAIA_Helpers::update_settings($raw);
            $section = 'provider';
        }

        $this->redirect($section, ['fbaia_saved' => '1']);
    }

    private function redirect($tab, array $args)
    {
        wp_safe_redirect($this->tab_url($this->tab_for_section($tab), $args));
        exit;
    }

    /**
     * Map a settings-section key to the tab that actually renders it. Most sections are
     * their own tab; "team" lives inside the Company Memory tab and "advanced" inside the
     * Logs & Health tab. Real tab names map to themselves. This keeps a save from landing
     * the user on the Dashboard after submitting the Team Directory or Advanced forms.
     */
    private function tab_for_section($section)
    {
        $map = [
            'team' => 'memory',
            'advanced' => 'diagnostics',
        ];
        return isset($map[$section]) ? $map[$section] : $section;
    }

    private function run_batch_triage()
    {
        $settings = FBAIA_Helpers::get_settings();
        $limit = max(1, min(100, (int) ($settings['triage_scan_limit'] ?? 20)));
        $days = max(1, min(90, (int) ($settings['triage_scan_window_days'] ?? 14)));
        $adapter = new FBAIA_FluentBoards_Adapter();
        $tasks = $adapter->get_recent_tasks(gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS)), $limit, $settings['board_allowlist'] ?? '');
        $queued = 0;
        if (!is_wp_error($tasks)) {
            $runner = new FBAIA_Runner();
            foreach ($tasks as $task) {
                $task_array = $adapter->task_to_array($task);
                $task_id = isset($task_array['id']) ? absint($task_array['id']) : 0;
                if (!$task_id) {
                    continue;
                }
                $result = $runner->queue('batch_triage', [
                    'task_id' => $task_id,
                    'task' => $task_array,
                    'batch_triage' => true,
                    'requested_by' => get_current_user_id(),
                ], true);
                if (!empty($result['queued'])) {
                    $queued++;
                }
            }
        }
        FBAIA_Logger::add(is_wp_error($tasks) ? 'error' : 'info', is_wp_error($tasks) ? 'Recent task triage scan failed' : 'Recent task triage queued', [
            'queued' => $queued,
            'limit' => $limit,
            'days' => $days,
            'error' => is_wp_error($tasks) ? $tasks->get_error_message() : '',
        ]);
        return $queued;
    }

    // ---------------------------------------------------------------------
    // Page rendering
    // ---------------------------------------------------------------------

    public function page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = FBAIA_Helpers::get_settings();
        $tab = $this->current_tab();
        ?>
        <div class="wrap fbaia-wrap fbaia-density-<?php echo esc_attr($settings['admin_ui_density'] ?? 'comfortable'); ?>">
            <div class="fbaia-hero">
                <div>
                    <p class="fbaia-eyebrow">Fluent Boards AI Automation · v<?php echo esc_html(FBAIA_VERSION); ?></p>
                    <h1><?php esc_html_e('AI Project Intelligence Command Center', 'fluent-boards-ai-automation'); ?></h1>
                    <p class="description"><?php esc_html_e('A safe, review-first AI layer for Fluent Boards: company memory, team context, task quality scoring, and human-approved recommendations — without n8n/Make/Zapier.', 'fluent-boards-ai-automation'); ?></p>
                </div>
                <div class="fbaia-hero-actions">
                    <?php echo $this->engine_badge($settings); ?>
                </div>
            </div>

            <?php $this->render_notices(); ?>
            <?php $this->tab_nav($tab); ?>

            <div class="fbaia-tab-panel" id="fbaia-tab-<?php echo esc_attr($tab); ?>">
                <?php $this->render_tab($tab, $settings); ?>
            </div>
        </div>
        <?php
    }

    private function engine_badge(array $settings)
    {
        $enabled = (($settings['enabled'] ?? 'no') === 'yes');
        $policy = $settings['action_execution_policy'] ?? 'review_first';
        $class = $enabled ? ($policy === 'review_first' ? 'fbaia-badge-ok' : 'fbaia-badge-warn') : 'fbaia-badge-off';
        $label = $enabled
            ? ($policy === 'review_first' ? __('Engine ON · Review-first', 'fluent-boards-ai-automation') : __('Engine ON · Safe-auto', 'fluent-boards-ai-automation'))
            : __('Engine OFF (safe mode)', 'fluent-boards-ai-automation');
        return '<span class="fbaia-engine-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    private function tab_nav($current)
    {
        echo '<nav class="fbaia-tabs" aria-label="' . esc_attr__('Fluent Boards AI sections', 'fluent-boards-ai-automation') . '">';
        foreach ($this->tabs() as $key => $label) {
            $classes = 'fbaia-tab' . ($key === $current ? ' is-active' : '');
            printf(
                '<a class="%s" href="%s"%s>%s</a>',
                esc_attr($classes),
                esc_url($this->tab_url($key)),
                $key === $current ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    private function render_tab($tab, array $settings)
    {
        switch ($tab) {
            case 'provider':
                $this->tab_provider($settings);
                break;
            case 'intelligence':
                $this->tab_intelligence($settings);
                break;
            case 'automations':
                $this->tab_automations($settings);
                break;
            case 'memory':
                $this->tab_memory($settings);
                break;
            case 'review':
                $this->tab_review();
                break;
            case 'diagnostics':
                $this->tab_diagnostics($settings);
                break;
            case 'dashboard':
            default:
                $this->tab_dashboard($settings);
                break;
        }
    }

    private function render_notices()
    {
        if (isset($_GET['fbaia_saved'])) {
            $saved = sanitize_key(wp_unslash($_GET['fbaia_saved']));
            if ($saved === 'truncated') {
                echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('Settings were NOT saved.', 'fluent-boards-ai-automation') . '</strong> '
                    . esc_html__('The form submission was incomplete — your server likely truncated the request (PHP max_input_vars / post_max_size). Nothing was changed. Save one tab at a time, or ask your host to raise max_input_vars.', 'fluent-boards-ai-automation')
                    . '</p></div>';
            } else {
                echo '<div class="notice notice-success inline"><p>' . esc_html__('Settings saved.', 'fluent-boards-ai-automation') . '</p></div>';
            }
        }

        $this->flash('fbaia_logs_cleared', __('Logs cleared.', 'fluent-boards-ai-automation'));
        $this->flash('fbaia_audit_cleared', __('Audit trail cleared.', 'fluent-boards-ai-automation'));
        $this->flash('fbaia_health', __('Health diagnostics generated.', 'fluent-boards-ai-automation'));
        $this->flash('fbaia_weekly_sent', __('Weekly intelligence digest ran. Check logs below.', 'fluent-boards-ai-automation'));
        $this->flash('fbaia_usage_reset', __('Usage stats reset.', 'fluent-boards-ai-automation'));
        $this->flash('fbaia_overdue_scan', __('Overdue scan ran. Check logs below.', 'fluent-boards-ai-automation'));
        $this->flash('fbaia_digest_sent', __('Daily digest job ran. Check logs below.', 'fluent-boards-ai-automation'));

        if (isset($_GET['fbaia_test'])) {
            $ok = sanitize_key(wp_unslash($_GET['fbaia_test'])) === 'ok';
            printf('<div class="notice %s inline"><p>%s</p></div>', $ok ? 'notice-success' : 'notice-error', esc_html($ok ? __('OpenAI test passed. Check logs below.', 'fluent-boards-ai-automation') : __('OpenAI test failed. Check logs below.', 'fluent-boards-ai-automation')));
        }
        if (isset($_GET['fbaia_manual'])) {
            $queued = sanitize_key(wp_unslash($_GET['fbaia_manual'])) === 'queued';
            printf('<div class="notice %s inline"><p>%s</p></div>', $queued ? 'notice-success' : 'notice-error', esc_html($queued ? __('Manual task analysis queued. Check the review queue.', 'fluent-boards-ai-automation') : __('Manual task analysis failed. Check logs below.', 'fluent-boards-ai-automation')));
        }
        if (isset($_GET['fbaia_suggestion'])) {
            $status = sanitize_key(wp_unslash($_GET['fbaia_suggestion']));
            $good = in_array($status, ['applied', 'rejected'], true);
            printf('<div class="notice %s inline"><p>%s</p></div>', $good ? 'notice-success' : 'notice-error', esc_html(sprintf(__('Suggestion %s.', 'fluent-boards-ai-automation'), $status)));
        }
        if (isset($_GET['fbaia_import'])) {
            $import = sanitize_text_field(wp_unslash($_GET['fbaia_import']));
            $failed = ($import === 'failed');
            printf('<div class="notice %s inline"><p>%s</p></div>', $failed ? 'notice-error' : 'notice-success', esc_html($failed ? __('Knowledge import failed.', 'fluent-boards-ai-automation') : sprintf(__('Knowledge import created %d entries.', 'fluent-boards-ai-automation'), absint($import))));
        }
        if (isset($_GET['fbaia_triage_queued'])) {
            printf('<div class="notice notice-success inline"><p>%s</p></div>', esc_html(sprintf(__('Batch triage queued %d task(s). Check logs and the review queue.', 'fluent-boards-ai-automation'), absint(wp_unslash($_GET['fbaia_triage_queued'])))));
        }
        if (isset($_GET['fbaia_export']) && sanitize_key(wp_unslash($_GET['fbaia_export'])) === 'failed') {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Export failed.', 'fluent-boards-ai-automation') . '</p></div>';
        }
        if (isset($_GET['fbaia_recipes_saved'])) {
            printf('<div class="notice notice-success inline"><p>%s</p></div>', esc_html(sprintf(__('Saved %d recipe(s).', 'fluent-boards-ai-automation'), absint(wp_unslash($_GET['fbaia_recipes_saved'])))));
        }
        if (isset($_GET['fbaia_recipe_action'])) {
            $st = sanitize_key(wp_unslash($_GET['fbaia_recipe_action']));
            $good = in_array($st, ['applied', 'rejected'], true);
            printf('<div class="notice %s inline"><p>%s</p></div>', $good ? 'notice-success' : 'notice-error', esc_html(sprintf(__('Held action %s.', 'fluent-boards-ai-automation'), $st)));
        }
    }

    private function flash($key, $message)
    {
        if (isset($_GET[$key])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html($message) . '</p></div>';
        }
    }

    // ---------------------------------------------------------------------
    // Tab: Dashboard / Command Center
    // ---------------------------------------------------------------------

    private function tab_dashboard(array $settings)
    {
        $this->command_console($settings);
        $this->dashboard_cards($settings);
        $this->quick_actions();
        if (($settings['admin_show_guidance'] ?? 'yes') === 'yes') {
            $this->onboarding_panel($settings);
        }
    }

    private function command_console(array $settings)
    {
        $has_key = !empty($settings['api_key']);
        ?>
        <div class="fbaia-card fbaia-full fbaia-cmd" id="fbaia-command">
            <h2><?php esc_html_e('AI Command Console', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php esc_html_e('Tell the assistant what to do in plain language — type or use the mic. Read-only commands run instantly; anything that changes a task asks you to confirm first.', 'fluent-boards-ai-automation'); ?></p>
            <?php if (!$has_key) : ?>
                <p class="fbaia-warn-text"><?php esc_html_e('Add an AI provider key on the AI Provider tab to use the console.', 'fluent-boards-ai-automation'); ?></p>
            <?php endif; ?>
            <div class="fbaia-cmd-bar">
                <button type="button" class="fbaia-cmd-mic" aria-label="<?php esc_attr_e('Voice command', 'fluent-boards-ai-automation'); ?>" title="<?php esc_attr_e('Voice command', 'fluent-boards-ai-automation'); ?>">&#127908;</button>
                <label class="screen-reader-text" for="fbaia-cmd-input"><?php esc_html_e('Command', 'fluent-boards-ai-automation'); ?></label>
                <input type="text" id="fbaia-cmd-input" class="fbaia-cmd-input regular-text" placeholder="<?php esc_attr_e('e.g. analyze task 42 · show overdue tasks · set task 42 to high', 'fluent-boards-ai-automation'); ?>" autocomplete="off">
                <button type="button" class="button button-primary fbaia-cmd-send"><?php esc_html_e('Run', 'fluent-boards-ai-automation'); ?></button>
            </div>
            <div class="fbaia-cmd-output" role="status" aria-live="polite"></div>
            <p class="description"><?php esc_html_e('Examples: "summarize task 17", "find stale tasks older than 5 days", "create subtasks on task 8: design, build, QA", "comment on task 8: please add a tracking plan".', 'fluent-boards-ai-automation'); ?></p>
        </div>
        <?php
    }

    private function dashboard_cards(array $settings)
    {
        $health = class_exists('FBAIA_Health_Check') ? FBAIA_Health_Check::diagnostics() : ['checks' => []];
        $checks = $health['checks'] ?? [];
        $ok = $warn = $err = 0;
        foreach ($checks as $check) {
            $severity = $check['severity'] ?? ($check['ok'] ?? true ? 'ok' : 'error');
            if ($severity === 'error') {
                $err++;
            } elseif ($severity === 'warning') {
                $warn++;
            } else {
                $ok++;
            }
        }
        $suggestions = class_exists('FBAIA_Suggestion_Store') ? FBAIA_Suggestion_Store::stats() : ['pending' => 0, 'applied' => 0, 'rejected' => 0, 'high_risk' => 0];
        $usage = class_exists('FBAIA_Usage_Tracker') ? FBAIA_Usage_Tracker::snapshot() : ['today' => ['calls' => 0], 'month' => ['total_tokens' => 0]];
        $adapter = new FBAIA_FluentBoards_Adapter();
        $fb_ok = $adapter->is_available();
        $provider_ok = !empty($settings['api_key']);
        $enabled = (($settings['enabled'] ?? 'no') === 'yes');
        $policy = $settings['action_execution_policy'] ?? 'review_first';
        ?>
        <div class="fbaia-dashboard" aria-label="<?php esc_attr_e('AI automation status', 'fluent-boards-ai-automation'); ?>">
            <?php
            $this->status_card(__('Plugin status', 'fluent-boards-ai-automation'), $enabled ? __('Enabled', 'fluent-boards-ai-automation') : __('Safe mode (off)', 'fluent-boards-ai-automation'), $enabled ? 'ok' : 'warn', $enabled ? __('Automations can run', 'fluent-boards-ai-automation') : __('Enable on the Automations tab', 'fluent-boards-ai-automation'));
            $this->status_card(__('Fluent Boards', 'fluent-boards-ai-automation'), $fb_ok ? __('Connected', 'fluent-boards-ai-automation') : __('Not detected', 'fluent-boards-ai-automation'), $fb_ok ? 'ok' : 'err', $fb_ok ? __('Task hooks active', 'fluent-boards-ai-automation') : __('Install Fluent Boards', 'fluent-boards-ai-automation'));
            $this->status_card(__('AI provider', 'fluent-boards-ai-automation'), $provider_ok ? __('Key set', 'fluent-boards-ai-automation') : __('No key', 'fluent-boards-ai-automation'), $provider_ok ? 'ok' : 'warn', $provider_ok ? esc_html($settings['model'] ?? '') : __('Add API key on AI Provider tab', 'fluent-boards-ai-automation'));
            $this->status_card(__('Safety mode', 'fluent-boards-ai-automation'), $policy === 'review_first' ? __('Review-first', 'fluent-boards-ai-automation') : __('Safe-auto', 'fluent-boards-ai-automation'), $policy === 'review_first' ? 'ok' : 'warn', $policy === 'review_first' ? __('Humans approve changes', 'fluent-boards-ai-automation') : __('AI may auto-apply', 'fluent-boards-ai-automation'));
            $this->status_card(__("Today's AI calls", 'fluent-boards-ai-automation'), (string) (int) ($usage['today']['calls'] ?? 0), 'info', sprintf(__('%d tokens this month', 'fluent-boards-ai-automation'), (int) ($usage['month']['total_tokens'] ?? 0)));
            $this->status_card(__('Pending suggestions', 'fluent-boards-ai-automation'), (string) (int) ($suggestions['pending'] ?? 0), ($suggestions['pending'] ?? 0) > 0 ? 'warn' : 'ok', sprintf(__('%1$d applied · %2$d rejected', 'fluent-boards-ai-automation'), (int) ($suggestions['applied'] ?? 0), (int) ($suggestions['rejected'] ?? 0)));
            $this->status_card(__('Recent risks', 'fluent-boards-ai-automation'), (string) (int) ($suggestions['high_risk'] ?? 0), ($suggestions['high_risk'] ?? 0) > 0 ? 'err' : 'ok', __('High-risk suggestions', 'fluent-boards-ai-automation'));
            $this->status_card(__('Health', 'fluent-boards-ai-automation'), sprintf('%d / %d', $ok, count($checks)), $err ? 'err' : ($warn ? 'warn' : 'ok'), sprintf(__('%1$d warnings · %2$d errors', 'fluent-boards-ai-automation'), $warn, $err));
            ?>
        </div>
        <?php
    }

    private function status_card($label, $value, $tone, $sub)
    {
        printf(
            '<div class="fbaia-stat-card fbaia-tone-%s"><span class="fbaia-stat-label">%s</span><strong>%s</strong><small>%s</small></div>',
            esc_attr($tone),
            esc_html($label),
            esc_html($value),
            esc_html($sub)
        );
    }

    private function quick_actions()
    {
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Quick actions', 'fluent-boards-ai-automation'); ?></h2>
            <div class="fbaia-actions">
                <form method="post"><?php wp_nonce_field('fbaia_test_openai'); ?><input type="hidden" name="fbaia_test_openai" value="1"><button class="button"><?php esc_html_e('Test AI connection', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post" class="fbaia-inline-form"><?php wp_nonce_field('fbaia_analyze_task'); ?><input type="hidden" name="fbaia_analyze_task" value="1"><label class="screen-reader-text" for="fbaia-manual-task-id"><?php esc_html_e('Task ID', 'fluent-boards-ai-automation'); ?></label><input id="fbaia-manual-task-id" type="number" min="1" name="fbaia_manual_task_id" placeholder="<?php esc_attr_e('Task ID', 'fluent-boards-ai-automation'); ?>"><button class="button"><?php esc_html_e('Analyze task', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_triage_recent'); ?><input type="hidden" name="fbaia_triage_recent" value="1"><button class="button"><?php esc_html_e('Queue recent task triage', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_run_health'); ?><input type="hidden" name="fbaia_run_health" value="1"><button class="button"><?php esc_html_e('Run health check', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_export_snapshot'); ?><input type="hidden" name="fbaia_export_snapshot" value="1"><button class="button"><?php esc_html_e('Export diagnostic snapshot', 'fluent-boards-ai-automation'); ?></button></form>
            </div>
        </div>
        <?php
    }

    private function onboarding_panel(array $settings)
    {
        $steps = [
            ['done' => !empty($settings['api_key']) || (defined('FBAIA_OPENAI_API_KEY') && FBAIA_OPENAI_API_KEY), 'title' => __('Connect AI provider', 'fluent-boards-ai-automation'), 'text' => __('Use wp-config.php for the API key when possible.', 'fluent-boards-ai-automation')],
            ['done' => (new FBAIA_FluentBoards_Adapter())->is_available(), 'title' => __('Confirm Fluent Boards connection', 'fluent-boards-ai-automation'), 'text' => __('Install/activate Fluent Boards so task hooks work.', 'fluent-boards-ai-automation')],
            ['done' => trim((string) ($settings['company_profile'] ?? '')) !== '', 'title' => __('Add company profile', 'fluent-boards-ai-automation'), 'text' => __('Tell the assistant what the company does and what matters.', 'fluent-boards-ai-automation')],
            ['done' => trim((string) ($settings['team_directory'] ?? '')) !== '', 'title' => __('Add team directory', 'fluent-boards-ai-automation'), 'text' => __('Add roles, capacity, boards, ownership, and reviewers.', 'fluent-boards-ai-automation')],
            ['done' => trim((string) ($settings['board_profiles'] ?? '')) !== '', 'title' => __('Add board profiles', 'fluent-boards-ai-automation'), 'text' => __('Give each board a goal, done-state, owner role, and risk rules.', 'fluent-boards-ai-automation')],
            ['done' => (($settings['action_execution_policy'] ?? 'review_first') === 'review_first'), 'title' => __('Keep review-first enabled', 'fluent-boards-ai-automation'), 'text' => __('Keep human approval on until suggestions are consistently good.', 'fluent-boards-ai-automation')],
            ['done' => (($settings['enabled'] ?? 'no') === 'yes'), 'title' => __('Run a test analysis', 'fluent-boards-ai-automation'), 'text' => __('Enable the engine, then analyze a real task and review it.', 'fluent-boards-ai-automation')],
        ];
        $done = 0;
        foreach ($steps as $step) {
            if ($step['done']) {
                $done++;
            }
        }
        ?>
        <div class="fbaia-card fbaia-guided fbaia-full">
            <div class="fbaia-guided-head">
                <div>
                    <h2><?php esc_html_e('Setup checklist', 'fluent-boards-ai-automation'); ?></h2>
                    <p class="description"><?php esc_html_e('The assistant gets smarter as company memory, team roles, and board rules are filled in. Complete these before enabling safe-auto.', 'fluent-boards-ai-automation'); ?></p>
                </div>
                <div class="fbaia-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr(round(($done / max(1, count($steps))) * 100)); ?>"><span style="width: <?php echo esc_attr(round(($done / max(1, count($steps))) * 100)); ?>%"></span></div>
            </div>
            <div class="fbaia-step-grid">
                <?php foreach ($steps as $step) : ?>
                    <div class="fbaia-step <?php echo $step['done'] ? 'is-done' : ''; ?>">
                        <strong><?php echo $step['done'] ? '&#10003; ' : '&#9675; '; ?><?php echo esc_html($step['title']); ?></strong>
                        <small><?php echo esc_html($step['text']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Section form scaffolding
    // ---------------------------------------------------------------------

    private function section_form_open($section)
    {
        echo '<form method="post" class="fbaia-section-form" data-fbaia-dirty-watch="1">';
        wp_nonce_field('fbaia_save_settings');
        echo '<input type="hidden" name="fbaia_save_settings" value="1">';
        echo '<input type="hidden" name="fbaia_section" value="' . esc_attr($section) . '">';
    }

    private function section_form_close($label = '')
    {
        $label = $label !== '' ? $label : __('Save changes', 'fluent-boards-ai-automation');
        // Completeness sentinel MUST be the final field so truncation is detectable.
        echo '<input type="hidden" name="fbaia[' . esc_attr(FBAIA_Helpers::FORM_SENTINEL) . ']" value="1">';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html($label) . '</button></p>';
        echo '</form>';
    }

    // ---------------------------------------------------------------------
    // Tab: AI Provider
    // ---------------------------------------------------------------------

    private function tab_provider(array $settings)
    {
        $stored = get_option(FBAIA_OPTION, []);
        $stored_key = is_array($stored) ? ($stored['api_key'] ?? '') : '';
        $using_constant_key = defined('FBAIA_OPENAI_API_KEY') && FBAIA_OPENAI_API_KEY;
        $using_constant_base = defined('FBAIA_OPENAI_API_BASE') && FBAIA_OPENAI_API_BASE;
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('AI Provider', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php esc_html_e('OpenAI-compatible chat completions provider. The API key is never displayed back. For best security, define it in wp-config.php.', 'fluent-boards-ai-automation'); ?></p>
            <?php $this->section_form_open('provider'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="fbaia-api-base"><?php esc_html_e('API base', 'fluent-boards-ai-automation'); ?></label></th>
                    <td>
                        <input id="fbaia-api-base" type="url" class="regular-text" name="fbaia[api_base]" value="<?php echo esc_attr($settings['api_base']); ?>" <?php disabled($using_constant_base); ?>>
                        <?php if ($using_constant_base) : ?><p class="description"><?php esc_html_e('Defined in wp-config.php (FBAIA_OPENAI_API_BASE).', 'fluent-boards-ai-automation'); ?></p><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fbaia-api-key"><?php esc_html_e('API key', 'fluent-boards-ai-automation'); ?></label></th>
                    <td>
                        <input id="fbaia-api-key" type="password" autocomplete="new-password" class="regular-text" name="fbaia[api_key]" value="" placeholder="<?php echo esc_attr($using_constant_key ? __('Defined in wp-config.php', 'fluent-boards-ai-automation') : ($stored_key ? __('Saved — paste a new key to replace', 'fluent-boards-ai-automation') : __('Paste key to save', 'fluent-boards-ai-automation'))); ?>" <?php disabled($using_constant_key); ?>>
                        <p class="description"><?php esc_html_e('Recommended: define', 'fluent-boards-ai-automation'); ?> <code>FBAIA_OPENAI_API_KEY</code> <?php esc_html_e('in wp-config.php. Leave blank to keep the stored key.', 'fluent-boards-ai-automation'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fbaia-model"><?php esc_html_e('Model', 'fluent-boards-ai-automation'); ?></label></th>
                    <td><input id="fbaia-model" type="text" class="regular-text" name="fbaia[model]" value="<?php echo esc_attr($settings['model']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Temperature / Max tokens', 'fluent-boards-ai-automation'); ?></th>
                    <td><input type="number" step="0.05" min="0" max="2" name="fbaia[temperature]" value="<?php echo esc_attr($settings['temperature']); ?>" aria-label="<?php esc_attr_e('Temperature', 'fluent-boards-ai-automation'); ?>"> <input type="number" min="100" max="8000" name="fbaia[max_tokens]" value="<?php echo esc_attr($settings['max_tokens']); ?>" aria-label="<?php esc_attr_e('Max tokens', 'fluent-boards-ai-automation'); ?>"></td>
                </tr>
                <?php $this->checkbox_row('force_json_response', __('JSON mode', 'fluent-boards-ai-automation'), __('Force JSON response when the provider supports it', 'fluent-boards-ai-automation'), $settings); ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Rate limit', 'fluent-boards-ai-automation'); ?></th>
                    <td><input type="number" min="1" max="500" name="fbaia[rate_limit_per_hour]" value="<?php echo esc_attr($settings['rate_limit_per_hour']); ?>" aria-label="<?php esc_attr_e('AI calls per hour', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('AI calls per hour', 'fluent-boards-ai-automation'); ?></td>
                </tr>
                <?php $this->checkbox_row('cost_guard_enabled', __('Cost guard', 'fluent-boards-ai-automation'), __('Stop AI calls when daily/monthly internal budgets are reached', 'fluent-boards-ai-automation'), $settings); ?>
                <tr>
                    <th scope="row"><?php esc_html_e('AI budgets', 'fluent-boards-ai-automation'); ?></th>
                    <td><input type="number" min="0" max="1000" name="fbaia[daily_ai_call_budget]" value="<?php echo esc_attr($settings['daily_ai_call_budget'] ?? 60); ?>" aria-label="<?php esc_attr_e('Calls per day', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('calls/day', 'fluent-boards-ai-automation'); ?> &nbsp; <input type="number" min="0" max="5000000" name="fbaia[monthly_token_budget]" value="<?php echo esc_attr($settings['monthly_token_budget'] ?? 250000); ?>" aria-label="<?php esc_attr_e('Tokens per month', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('tokens/month', 'fluent-boards-ai-automation'); ?></td>
                </tr>
            </table>
            <?php $this->section_form_close(__('Save AI Provider', 'fluent-boards-ai-automation')); ?>
            <div class="fbaia-actions">
                <form method="post"><?php wp_nonce_field('fbaia_test_openai'); ?><input type="hidden" name="fbaia_test_openai" value="1"><button class="button"><?php esc_html_e('Test OpenAI + Context', 'fluent-boards-ai-automation'); ?></button></form>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Tab: Task Intelligence
    // ---------------------------------------------------------------------

    private function tab_intelligence(array $settings)
    {
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Task Intelligence', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php esc_html_e('Controls how deeply the assistant reasons about each task before recommending actions. None of these mutate tasks on their own.', 'fluent-boards-ai-automation'); ?></p>
            <?php $this->section_form_open('intelligence'); ?>
            <table class="form-table" role="presentation">
                <?php $this->checkbox_row('knowledge_enabled', __('Company memory', 'fluent-boards-ai-automation'), __('Use company memory and team directory in AI recommendations', 'fluent-boards-ai-automation'), $settings); ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Recommendation depth', 'fluent-boards-ai-automation'); ?></th>
                    <td>
                        <select name="fbaia[recommendation_depth]" aria-label="<?php esc_attr_e('Recommendation depth', 'fluent-boards-ai-automation'); ?>">
                            <option value="simple" <?php selected($settings['recommendation_depth'], 'simple'); ?>><?php esc_html_e('Simple', 'fluent-boards-ai-automation'); ?></option>
                            <option value="standard" <?php selected($settings['recommendation_depth'], 'standard'); ?>><?php esc_html_e('Standard', 'fluent-boards-ai-automation'); ?></option>
                            <option value="strategic" <?php selected($settings['recommendation_depth'], 'strategic'); ?>><?php esc_html_e('Strategic', 'fluent-boards-ai-automation'); ?></option>
                        </select>
                        <select name="fbaia[recommendation_style]" aria-label="<?php esc_attr_e('Recommendation style', 'fluent-boards-ai-automation'); ?>">
                            <option value="direct" <?php selected($settings['recommendation_style'], 'direct'); ?>><?php esc_html_e('Direct', 'fluent-boards-ai-automation'); ?></option>
                            <option value="supportive" <?php selected($settings['recommendation_style'], 'supportive'); ?>><?php esc_html_e('Supportive', 'fluent-boards-ai-automation'); ?></option>
                            <option value="executive" <?php selected($settings['recommendation_style'], 'executive'); ?>><?php esc_html_e('Executive', 'fluent-boards-ai-automation'); ?></option>
                        </select>
                        <select name="fbaia[output_language]" aria-label="<?php esc_attr_e('Output language', 'fluent-boards-ai-automation'); ?>">
                            <option value="site_locale" <?php selected($settings['output_language'], 'site_locale'); ?>><?php esc_html_e('Site language', 'fluent-boards-ai-automation'); ?></option>
                            <option value="english" <?php selected($settings['output_language'], 'english'); ?>><?php esc_html_e('English', 'fluent-boards-ai-automation'); ?></option>
                            <option value="spanish" <?php selected($settings['output_language'], 'spanish'); ?>><?php esc_html_e('Spanish', 'fluent-boards-ai-automation'); ?></option>
                            <option value="persian" <?php selected($settings['output_language'], 'persian'); ?>><?php esc_html_e('Persian / Farsi', 'fluent-boards-ai-automation'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Context / confidence', 'fluent-boards-ai-automation'); ?></th>
                    <td>
                        <input type="number" min="2000" max="50000" name="fbaia[max_context_chars]" value="<?php echo esc_attr($settings['max_context_chars']); ?>" aria-label="<?php esc_attr_e('Max context chars', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('max context chars', 'fluent-boards-ai-automation'); ?>
                        &nbsp; <input type="number" min="0" max="1" step="0.05" name="fbaia[confidence_threshold]" value="<?php echo esc_attr($settings['confidence_threshold']); ?>" aria-label="<?php esc_attr_e('Auto-action confidence', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('auto-action confidence', 'fluent-boards-ai-automation'); ?>
                        &nbsp; <input type="number" min="0" max="1" step="0.05" name="fbaia[minimum_comment_confidence]" value="<?php echo esc_attr($settings['minimum_comment_confidence']); ?>" aria-label="<?php esc_attr_e('Minimum comment confidence', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('min comment confidence', 'fluent-boards-ai-automation'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Task enrichment', 'fluent-boards-ai-automation'); ?></th>
                    <td>
                        <label><input type="checkbox" name="fbaia[enable_task_enrichment]" value="yes" <?php checked($settings['enable_task_enrichment'] ?? 'yes', 'yes'); ?>> <?php esc_html_e('Read task snapshot, recent comments, and subtasks before asking AI', 'fluent-boards-ai-automation'); ?></label><br>
                        <input type="number" min="0" max="30" name="fbaia[context_comment_limit]" value="<?php echo esc_attr($settings['context_comment_limit']); ?>" aria-label="<?php esc_attr_e('Recent comments', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('recent comments', 'fluent-boards-ai-automation'); ?>
                        &nbsp; <input type="number" min="0" max="50" name="fbaia[context_subtask_limit]" value="<?php echo esc_attr($settings['context_subtask_limit']); ?>" aria-label="<?php esc_attr_e('Subtasks', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('subtasks', 'fluent-boards-ai-automation'); ?>
                    </td>
                </tr>
                <?php
                $this->checkbox_row('suggest_owner', __('Owner recommendation', 'fluent-boards-ai-automation'), __('Recommend the most relevant team member when possible', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('suggest_reviewer', __('Reviewer recommendation', 'fluent-boards-ai-automation'), __('Recommend a reviewer or approver when useful', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('suggest_acceptance_criteria', __('Acceptance criteria', 'fluent-boards-ai-automation'), __('Generate acceptance criteria / done-state', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('suggest_due_date', __('Due date', 'fluent-boards-ai-automation'), __('Suggest a due date when enough context exists', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('ask_clarifying_questions', __('Clarifying questions', 'fluent-boards-ai-automation'), __('Ask focused questions when context is missing', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('suppress_low_value_comments', __('Suppress low-value comments', 'fluent-boards-ai-automation'), __('Skip comments when AI marks the event as low-actionability', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('task_quality_score_enabled', __('Task quality score', 'fluent-boards-ai-automation'), __('Score clarity, ownership, measurability, risk, and readiness', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('ai_council_enabled', __('AI council lenses', 'fluent-boards-ai-automation'), __('Review important tasks from product, marketing, operations, risk, customer, and technical angles', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('raci_enabled', __('RACI reasoning', 'fluent-boards-ai-automation'), __('Suggest responsible/accountable/consulted/informed roles', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('meeting_recommendations_enabled', __('Meeting guidance', 'fluent-boards-ai-automation'), __('Recommend async vs quick sync vs decision meeting when coordination is unclear', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('store_ai_suggestions', __('Suggestion store', 'fluent-boards-ai-automation'), __('Save AI recommendations in the internal review queue', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('feedback_learning_enabled', __('Feedback learning', 'fluent-boards-ai-automation'), __('Use approved/rejected suggestions as lightweight learning context', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('use_knowledge_posts', __('Knowledge posts', 'fluent-boards-ai-automation'), __('Retrieve structured AI Company Knowledge posts in addition to pasted memory', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('memory_quality_guard', __('Memory quality guard', 'fluent-boards-ai-automation'), __('Ask for knowledge gaps instead of inventing missing internal context', 'fluent-boards-ai-automation'), $settings);
                $this->checkbox_row('playbooks_enabled', __('Automation playbooks', 'fluent-boards-ai-automation'), __('Retrieve and enforce internal playbooks/rules for safer recommendations', 'fluent-boards-ai-automation'), $settings);
                ?>
                <tr><th scope="row"><?php esc_html_e('Retrieval limits', 'fluent-boards-ai-automation'); ?></th><td>
                    <input type="number" min="0" max="12" name="fbaia[knowledge_post_limit]" value="<?php echo esc_attr($settings['knowledge_post_limit'] ?? 5); ?>" aria-label="<?php esc_attr_e('Knowledge posts per request', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('knowledge posts', 'fluent-boards-ai-automation'); ?>
                    &nbsp; <input type="number" min="0" max="15" name="fbaia[knowledge_entry_limit]" value="<?php echo esc_attr($settings['knowledge_entry_limit'] ?? 5); ?>" aria-label="<?php esc_attr_e('Pasted entries per request', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('pasted entries', 'fluent-boards-ai-automation'); ?>
                    &nbsp; <input type="number" min="0" max="15" name="fbaia[context_feedback_limit]" value="<?php echo esc_attr($settings['context_feedback_limit'] ?? 5); ?>" aria-label="<?php esc_attr_e('Feedback examples per request', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('feedback examples', 'fluent-boards-ai-automation'); ?>
                    &nbsp; <input type="number" min="1" max="30" name="fbaia[team_member_limit]" value="<?php echo esc_attr($settings['team_member_limit'] ?? 8); ?>" aria-label="<?php esc_attr_e('Team members per request', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('team members', 'fluent-boards-ai-automation'); ?>
                </td></tr>
                <tr><th scope="row"><?php esc_html_e('Playbook / cooldown', 'fluent-boards-ai-automation'); ?></th><td>
                    <input type="number" min="0" max="12" name="fbaia[playbook_limit]" value="<?php echo esc_attr($settings['playbook_limit'] ?? 5); ?>" aria-label="<?php esc_attr_e('Matched playbooks per request', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('matched playbooks', 'fluent-boards-ai-automation'); ?>
                    &nbsp; <input type="number" min="0" max="1440" name="fbaia[comment_cooldown_minutes]" value="<?php echo esc_attr($settings['comment_cooldown_minutes'] ?? 15); ?>" aria-label="<?php esc_attr_e('Comment cooldown minutes', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('min comment cooldown', 'fluent-boards-ai-automation'); ?>
                </td></tr>
            </table>
            <?php $this->section_form_close(__('Save Task Intelligence', 'fluent-boards-ai-automation')); ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Tab: Automations
    // ---------------------------------------------------------------------

    private function tab_automations(array $settings)
    {
        $triggers = FBAIA_Helpers::available_triggers();
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Automations', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php esc_html_e('Decide which events the assistant reacts to and what it is allowed to do. Task-changing actions are opt-in and held for review by default.', 'fluent-boards-ai-automation'); ?></p>
            <?php $this->section_form_open('automations'); ?>

            <table class="form-table" role="presentation">
                <?php $this->checkbox_row('enabled', __('Engine enabled', 'fluent-boards-ai-automation'), __('Master switch. While off (safe mode) the plugin never calls AI or touches a task.', 'fluent-boards-ai-automation'), $settings); ?>
                <tr>
                    <th scope="row"><label for="fbaia-mode"><?php esc_html_e('Mode', 'fluent-boards-ai-automation'); ?></label></th>
                    <td>
                        <select id="fbaia-mode" name="fbaia[mode]">
                            <option value="internal_ai_actions" <?php selected($settings['mode'], 'internal_ai_actions'); ?>><?php esc_html_e('AI + internal actions', 'fluent-boards-ai-automation'); ?></option>
                            <option value="internal_ai_comment" <?php selected($settings['mode'], 'internal_ai_comment'); ?>><?php esc_html_e('AI comment only', 'fluent-boards-ai-automation'); ?></option>
                            <option value="internal_log_only" <?php selected($settings['mode'], 'internal_log_only'); ?>><?php esc_html_e('Log only, no AI call', 'fluent-boards-ai-automation'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fbaia-board-allowlist"><?php esc_html_e('Board allowlist', 'fluent-boards-ai-automation'); ?></label></th>
                    <td><input id="fbaia-board-allowlist" type="text" class="regular-text" name="fbaia[board_allowlist]" value="<?php echo esc_attr($settings['board_allowlist']); ?>" placeholder="<?php esc_attr_e('Optional: 12, 18, 24', 'fluent-boards-ai-automation'); ?>"><p class="description"><?php esc_html_e('Leave empty to allow all boards.', 'fluent-boards-ai-automation'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="fbaia-policy"><?php esc_html_e('Action policy', 'fluent-boards-ai-automation'); ?></label></th>
                    <td>
                        <select id="fbaia-policy" name="fbaia[action_execution_policy]">
                            <option value="review_first" <?php selected($settings['action_execution_policy'] ?? 'review_first', 'review_first'); ?>><?php esc_html_e('Review-first: store suggestions, hold priority/subtask changes', 'fluent-boards-ai-automation'); ?></option>
                            <option value="safe_auto" <?php selected($settings['action_execution_policy'] ?? 'review_first', 'safe_auto'); ?>><?php esc_html_e('Safe-auto: execute when confidence + automation_safe pass', 'fluent-boards-ai-automation'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Recommended: review-first until you trust the assistant on real company tasks.', 'fluent-boards-ai-automation'); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Triggers', 'fluent-boards-ai-automation'); ?></h3>
            <div class="fbaia-check-grid">
                <?php foreach ($triggers as $key => $label) : ?>
                    <label><input type="checkbox" name="fbaia[triggers][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $settings['triggers'], true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </div>
            <p class="description"><?php esc_html_e('Start with task_created, stage_changed, priority_changed, date_changed, and comment_created. Add more only after logs look clean.', 'fluent-boards-ai-automation'); ?></p>

            <h3><?php esc_html_e('Action policy & toggles', 'fluent-boards-ai-automation'); ?></h3>
            <table class="form-table" role="presentation">
                <?php $this->checkbox_row('write_private_comment', __('Comment privacy', 'fluent-boards-ai-automation'), __('Write AI comments as private comments', 'fluent-boards-ai-automation'), $settings); ?>
                <?php $this->checkbox_row('action_ai_comment', __('Write AI comment', 'fluent-boards-ai-automation'), __('Write the AI analysis into the task thread', 'fluent-boards-ai-automation'), $settings); ?>
                <?php $this->checkbox_row('action_update_priority', __('Suggest / apply priority', 'fluent-boards-ai-automation'), __('Allow AI to update task priority — only under safe-auto when confidence + automation_safe pass', 'fluent-boards-ai-automation'), $settings, true); ?>
                <?php $this->checkbox_row('action_create_subtasks', __('Create subtasks', 'fluent-boards-ai-automation'), __('Allow AI to create subtasks — only under safe-auto when confidence + automation_safe pass', 'fluent-boards-ai-automation'), $settings, true); ?>
                <tr><th scope="row"><?php esc_html_e('Max subtasks', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="1" max="20" name="fbaia[max_subtasks_per_task]" value="<?php echo esc_attr($settings['max_subtasks_per_task']); ?>"></td></tr>
                <?php $this->checkbox_row('action_email_admin', __('Email alert', 'fluent-boards-ai-automation'), __('Email admin for risky / urgent tasks', 'fluent-boards-ai-automation'), $settings); ?>
                <tr><th scope="row"><label for="fbaia-notify-email"><?php esc_html_e('Notify email', 'fluent-boards-ai-automation'); ?></label></th><td><input id="fbaia-notify-email" type="email" class="regular-text" name="fbaia[notify_email]" value="<?php echo esc_attr($settings['notify_email']); ?>"></td></tr>
                <?php $this->checkbox_row('notify_only_risky', __('Risk only', 'fluent-boards-ai-automation'), __('Only notify for risky, urgent, blocked, or unclear work', 'fluent-boards-ai-automation'), $settings); ?>
            </table>
            <p class="description fbaia-warn-text"><?php esc_html_e('Priority changes and subtask creation modify your boards. Keep them off (or on review-first) until you have validated suggestions on staging.', 'fluent-boards-ai-automation'); ?></p>

            <div class="fbaia-grid">
                <div class="fbaia-subcard">
                    <h3><?php esc_html_e('Overdue scan', 'fluent-boards-ai-automation'); ?></h3>
                    <table class="form-table" role="presentation">
                        <?php $this->checkbox_row('overdue_scan_enabled', __('Enabled', 'fluent-boards-ai-automation'), __('Run hourly overdue scan', 'fluent-boards-ai-automation'), $settings); ?>
                        <?php $this->checkbox_row('overdue_comment_enabled', __('Comment', 'fluent-boards-ai-automation'), __('Add reminder comment to overdue tasks', 'fluent-boards-ai-automation'), $settings); ?>
                        <?php $this->checkbox_row('overdue_email_enabled', __('Email', 'fluent-boards-ai-automation'), __('Email after overdue scan', 'fluent-boards-ai-automation'), $settings); ?>
                        <tr><th scope="row"><?php esc_html_e('Limit', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="1" max="100" name="fbaia[overdue_scan_limit]" value="<?php echo esc_attr($settings['overdue_scan_limit']); ?>"></td></tr>
                    </table>
                </div>
                <div class="fbaia-subcard">
                    <h3><?php esc_html_e('Daily digest', 'fluent-boards-ai-automation'); ?></h3>
                    <table class="form-table" role="presentation">
                        <?php $this->checkbox_row('daily_digest_enabled', __('Enabled', 'fluent-boards-ai-automation'), __('Send daily email digest of recently updated tasks', 'fluent-boards-ai-automation'), $settings); ?>
                        <tr><th scope="row"><?php esc_html_e('Email', 'fluent-boards-ai-automation'); ?></th><td><input type="email" class="regular-text" name="fbaia[daily_digest_email]" value="<?php echo esc_attr($settings['daily_digest_email']); ?>"></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Hour', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="0" max="23" name="fbaia[daily_digest_hour]" value="<?php echo esc_attr($settings['daily_digest_hour']); ?>"> <?php esc_html_e('local hour, 0-23', 'fluent-boards-ai-automation'); ?></td></tr>
                    </table>
                </div>
                <div class="fbaia-subcard">
                    <h3><?php esc_html_e('Weekly intelligence', 'fluent-boards-ai-automation'); ?></h3>
                    <table class="form-table" role="presentation">
                        <?php $this->checkbox_row('weekly_intelligence_enabled', __('Enabled', 'fluent-boards-ai-automation'), __('Send a weekly operational intelligence email', 'fluent-boards-ai-automation'), $settings); ?>
                        <tr><th scope="row"><?php esc_html_e('Email', 'fluent-boards-ai-automation'); ?></th><td><input type="email" class="regular-text" name="fbaia[weekly_intelligence_email]" value="<?php echo esc_attr($settings['weekly_intelligence_email'] ?? get_option('admin_email')); ?>"></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Day / hour', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="1" max="7" name="fbaia[weekly_intelligence_day]" value="<?php echo esc_attr($settings['weekly_intelligence_day'] ?? 1); ?>" aria-label="<?php esc_attr_e('Day 1=Mon..7=Sun', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('day 1=Mon..7=Sun', 'fluent-boards-ai-automation'); ?> &nbsp; <input type="number" min="0" max="23" name="fbaia[weekly_intelligence_hour]" value="<?php echo esc_attr($settings['weekly_intelligence_hour'] ?? 9); ?>" aria-label="<?php esc_attr_e('Local hour', 'fluent-boards-ai-automation'); ?>"> <?php esc_html_e('local hour', 'fluent-boards-ai-automation'); ?></td></tr>
                    </table>
                </div>
            </div>

            <h3><?php esc_html_e('Automation recipes (monday-style)', 'fluent-boards-ai-automation'); ?></h3>
            <table class="form-table" role="presentation">
                <?php $this->checkbox_row('recipes_enabled', __('Enable recipes', 'fluent-boards-ai-automation'), __('Run deterministic "when → if → then" recipes (defined below) in addition to AI suggestions', 'fluent-boards-ai-automation'), $settings); ?>
                <?php $this->checkbox_row('recipe_allow_mutations', __('Allow recipe auto-changes', 'fluent-boards-ai-automation'), __('Permit recipes marked run_mode=auto to change tasks (priority/subtasks/comments). Off = those actions are held and only notify/flag run.', 'fluent-boards-ai-automation'), $settings, true); ?>
            </table>

            <?php $this->section_form_close(__('Save Automations', 'fluent-boards-ai-automation')); ?>
        </div>

        <div class="fbaia-card fbaia-full" id="fbaia-recipes">
            <h2><?php esc_html_e('Recipes — visual builder', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php esc_html_e('Build recipes with menus: pick a trigger, optionally add conditions, then add actions. Non-mutating actions (notify, flag) run when recipes are enabled; mutating actions (set priority / create subtasks / add comment) need run_mode "auto" plus the "Allow recipe auto-changes" toggle above — otherwise they are held in the Review queue for approval.', 'fluent-boards-ai-automation'); ?></p>

            <?php $recipes = class_exists('FBAIA_Recipes') ? FBAIA_Recipes::all() : []; ?>
            <form method="post" class="fbaia-section-form" data-fbaia-dirty-watch="1">
                <?php wp_nonce_field('fbaia_save_recipes_builder'); ?>
                <input type="hidden" name="fbaia_save_recipes_builder" value="1">
                <div id="fbaia-recipe-list">
                    <?php foreach ($recipes as $i => $recipe) {
                        echo $this->recipe_row((string) $i, $recipe);
                    } ?>
                </div>
                <p>
                    <button type="button" class="button fbaia-add-recipe">+ <?php esc_html_e('Add recipe', 'fluent-boards-ai-automation'); ?></button>
                </p>
                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Save recipes', 'fluent-boards-ai-automation'); ?></button></p>
            </form>

            <template id="fbaia-recipe-tpl"><?php echo $this->recipe_row('__RINDEX__', null); ?></template>
            <template id="fbaia-cond-tpl"><?php echo $this->condition_row('__RINDEX__', '__CINDEX__', null); ?></template>
            <template id="fbaia-action-tpl"><?php echo $this->action_row('__RINDEX__', '__AINDEX__', null); ?></template>

            <details class="fbaia-recipe-advanced">
                <summary><?php esc_html_e('Advanced: edit as JSON', 'fluent-boards-ai-automation'); ?></summary>
                <form method="post" class="fbaia-section-form" data-fbaia-dirty-watch="1">
                    <?php wp_nonce_field('fbaia_save_recipes'); ?>
                    <input type="hidden" name="fbaia_save_recipes" value="1">
                    <div class="fbaia-field-block">
                        <label for="fbaia-recipes-json"><strong><?php esc_html_e('Recipes (JSON)', 'fluent-boards-ai-automation'); ?></strong></label>
                        <textarea id="fbaia-recipes-json" name="fbaia_recipes_json" rows="12" class="large-text code"><?php echo esc_textarea($this->recipes_json()); ?></textarea>
                    </div>
                    <p class="submit"><button type="submit" class="button"><?php esc_html_e('Save JSON recipes', 'fluent-boards-ai-automation'); ?></button></p>
                </form>
                <p class="description"><?php esc_html_e('Saving here replaces all recipes. The visual builder above also replaces all recipes when saved — use one or the other per save.', 'fluent-boards-ai-automation'); ?></p>
                <pre><?php echo esc_html(class_exists('FBAIA_Recipes') ? FBAIA_Recipes::example_template() : ''); ?></pre>
            </details>
        </div>
        <?php
    }

    private function recipe_row($ri, $recipe)
    {
        $r = is_array($recipe) ? $recipe : [];
        $name = $r['name'] ?? '';
        $enabled = !empty($r['enabled']);
        $trigger = $r['trigger'] ?? 'task_created';
        $days = isset($r['days']) ? (int) $r['days'] : 3;
        $match = $r['match'] ?? 'all';
        $run_mode = $r['run_mode'] ?? 'review';
        $board = $r['board_allowlist'] ?? '';
        $base = 'fbaia_recipe[' . $ri . ']';

        ob_start();
        ?>
        <fieldset class="fbaia-recipe" data-ri="<?php echo esc_attr($ri); ?>">
            <div class="fbaia-recipe-head">
                <label><input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked($enabled); ?>> <?php esc_html_e('Enabled', 'fluent-boards-ai-automation'); ?></label>
                <input type="text" class="regular-text" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($name); ?>" placeholder="<?php esc_attr_e('Recipe name', 'fluent-boards-ai-automation'); ?>">
                <button type="button" class="button-link fbaia-remove-recipe" aria-label="<?php esc_attr_e('Remove recipe', 'fluent-boards-ai-automation'); ?>">&times;</button>
            </div>
            <div class="fbaia-recipe-grid">
                <label><?php esc_html_e('When (trigger)', 'fluent-boards-ai-automation'); ?>
                    <?php echo $this->select($base . '[trigger]', FBAIA_Recipes::selectable_triggers(), $trigger); ?>
                </label>
                <label><?php esc_html_e('Days (time triggers)', 'fluent-boards-ai-automation'); ?>
                    <input type="number" min="0" max="365" name="<?php echo esc_attr($base); ?>[days]" value="<?php echo esc_attr($days); ?>">
                </label>
                <label><?php esc_html_e('Match', 'fluent-boards-ai-automation'); ?>
                    <?php echo $this->select($base . '[match]', ['all' => __('All conditions', 'fluent-boards-ai-automation'), 'any' => __('Any condition', 'fluent-boards-ai-automation')], $match); ?>
                </label>
                <label><?php esc_html_e('Run mode', 'fluent-boards-ai-automation'); ?>
                    <?php echo $this->select($base . '[run_mode]', ['review' => __('Review (hold changes)', 'fluent-boards-ai-automation'), 'auto' => __('Auto (apply changes)', 'fluent-boards-ai-automation')], $run_mode); ?>
                </label>
                <label><?php esc_html_e('Board allowlist', 'fluent-boards-ai-automation'); ?>
                    <input type="text" name="<?php echo esc_attr($base); ?>[board_allowlist]" value="<?php echo esc_attr($board); ?>" placeholder="<?php esc_attr_e('e.g. 12, 18', 'fluent-boards-ai-automation'); ?>">
                </label>
            </div>

            <p class="fbaia-recipe-sub"><strong><?php esc_html_e('If (conditions)', 'fluent-boards-ai-automation'); ?></strong></p>
            <div class="fbaia-conditions">
                <?php foreach ((array) ($r['conditions'] ?? []) as $ci => $cond) {
                    echo $this->condition_row($ri, (string) $ci, $cond);
                } ?>
            </div>
            <p><button type="button" class="button button-small fbaia-add-condition">+ <?php esc_html_e('Add condition', 'fluent-boards-ai-automation'); ?></button></p>

            <p class="fbaia-recipe-sub"><strong><?php esc_html_e('Then (actions)', 'fluent-boards-ai-automation'); ?></strong></p>
            <div class="fbaia-actions-list">
                <?php foreach ((array) ($r['actions'] ?? []) as $ai => $action) {
                    echo $this->action_row($ri, (string) $ai, $action);
                } ?>
            </div>
            <p><button type="button" class="button button-small fbaia-add-action">+ <?php esc_html_e('Add action', 'fluent-boards-ai-automation'); ?></button></p>
        </fieldset>
        <?php
        return ob_get_clean();
    }

    private function condition_row($ri, $ci, $cond)
    {
        $c = is_array($cond) ? $cond : [];
        $base = 'fbaia_recipe[' . $ri . '][conditions][' . $ci . ']';
        $fields = ['priority', 'status', 'stage_id', 'board_id', 'due_at', 'title', 'description', 'label', 'comment', 'assignee', 'event'];
        $field_opts = array_combine($fields, $fields);
        $ops = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty', 'gt', 'lt', 'in'];
        $op_opts = array_combine($ops, $ops);
        ob_start();
        ?>
        <div class="fbaia-recipe-row fbaia-cond-row">
            <?php echo $this->select($base . '[field]', $field_opts, $c['field'] ?? 'priority'); ?>
            <?php echo $this->select($base . '[op]', $op_opts, $c['op'] ?? 'equals'); ?>
            <input type="text" name="<?php echo esc_attr($base); ?>[value]" value="<?php echo esc_attr($c['value'] ?? ''); ?>" placeholder="<?php esc_attr_e('value', 'fluent-boards-ai-automation'); ?>">
            <button type="button" class="button-link fbaia-remove-row" aria-label="<?php esc_attr_e('Remove', 'fluent-boards-ai-automation'); ?>">&times;</button>
        </div>
        <?php
        return ob_get_clean();
    }

    private function action_row($ri, $ai, $action)
    {
        $a = is_array($action) ? $action : [];
        $base = 'fbaia_recipe[' . $ri . '][actions][' . $ai . ']';
        $types = [
            'notify' => __('Notify (email)', 'fluent-boards-ai-automation'),
            'flag' => __('Flag (log + audit)', 'fluent-boards-ai-automation'),
            'set_priority' => __('Set priority', 'fluent-boards-ai-automation'),
            'create_subtasks' => __('Create subtasks', 'fluent-boards-ai-automation'),
            'add_comment' => __('Add comment', 'fluent-boards-ai-automation'),
        ];
        ob_start();
        ?>
        <div class="fbaia-recipe-row fbaia-action-row">
            <?php echo $this->select($base . '[type]', $types, $a['type'] ?? 'notify'); ?>
            <input type="text" name="<?php echo esc_attr($base); ?>[to]" value="<?php echo esc_attr($a['to'] ?? ''); ?>" placeholder="<?php esc_attr_e('email (notify)', 'fluent-boards-ai-automation'); ?>">
            <input type="text" name="<?php echo esc_attr($base); ?>[value]" value="<?php echo esc_attr($a['value'] ?? ''); ?>" placeholder="<?php esc_attr_e('value (priority / subtasks a|b|c)', 'fluent-boards-ai-automation'); ?>">
            <input type="text" name="<?php echo esc_attr($base); ?>[message]" value="<?php echo esc_attr($a['message'] ?? ''); ?>" placeholder="<?php esc_attr_e('message (notify / comment)', 'fluent-boards-ai-automation'); ?>">
            <button type="button" class="button-link fbaia-remove-row" aria-label="<?php esc_attr_e('Remove', 'fluent-boards-ai-automation'); ?>">&times;</button>
        </div>
        <?php
        return ob_get_clean();
    }

    private function select($name, array $options, $current)
    {
        $html = '<select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '" ' . selected((string) $current, (string) $value, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function recipes_json()
    {
        if (!class_exists('FBAIA_Recipes')) {
            return '';
        }
        $recipes = FBAIA_Recipes::all();
        if (empty($recipes)) {
            return '';
        }
        return wp_json_encode($recipes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------------
    // Tab: Company Memory + Team
    // ---------------------------------------------------------------------

    private function tab_memory(array $settings)
    {
        ?>
        <div class="fbaia-card fbaia-full" id="fbaia-memory">
            <h2><?php esc_html_e('Company Memory', 'fluent-boards-ai-automation'); ?></h2>
            <?php $this->template_buttons(['company_profile', 'board_profiles', 'automation_playbooks']); ?>
            <p class="description"><?php esc_html_e('Paste stable internal knowledge. The assistant uses only this, the task context, and prior feedback — it never invents company facts.', 'fluent-boards-ai-automation'); ?></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . FBAIA_Knowledge_Library::CPT)); ?>"><?php esc_html_e('Manage structured knowledge library', 'fluent-boards-ai-automation'); ?></a></p>
            <?php $this->section_form_open('memory'); ?>
            <?php
            $this->textarea('company_profile', __('Company profile', 'fluent-boards-ai-automation'), $settings, __('What is the company? Mission, business model, positioning.', 'fluent-boards-ai-automation'));
            $this->textarea('company_products', __('Products / services', 'fluent-boards-ai-automation'), $settings, __('Products, packages, features, active projects.', 'fluent-boards-ai-automation'));
            $this->textarea('company_goals', __('Current goals / strategy', 'fluent-boards-ai-automation'), $settings, __('Quarterly goals, launch priorities, marketing/sales/product focus.', 'fluent-boards-ai-automation'));
            $this->textarea('company_customers', __('Customers / audience', 'fluent-boards-ai-automation'), $settings, __('ICP, personas, customer pain points, important segments.', 'fluent-boards-ai-automation'));
            $this->textarea('company_constraints', __('Constraints / rules / process', 'fluent-boards-ai-automation'), $settings, __('Brand rules, legal constraints, internal processes, approval rules, do-not-do items.', 'fluent-boards-ai-automation'));
            $this->textarea('company_glossary', __('Glossary / codenames', 'fluent-boards-ai-automation'), $settings, __('Internal terms, project names, abbreviations, board meanings.', 'fluent-boards-ai-automation'));
            $this->textarea('business_metrics', __('Business metrics / KPIs', 'fluent-boards-ai-automation'), $settings, __('Define what matters: revenue, conversion, CAC, cycle time, SLA, quality.', 'fluent-boards-ai-automation'));
            $this->textarea('brand_voice', __('Brand voice / communication rules', 'fluent-boards-ai-automation'), $settings, __('Tone, language, claims to avoid, approval style, customer-facing rules.', 'fluent-boards-ai-automation'));
            $this->textarea('project_playbooks', __('Project playbooks / SOPs', 'fluent-boards-ai-automation'), $settings, __('Reusable processes: launch checklist, campaign workflow, bug triage, content approval.', 'fluent-boards-ai-automation'));
            $this->textarea('operating_principles', __('Operating principles', 'fluent-boards-ai-automation'), $settings, __('How to make tradeoffs: speed vs quality, approval style, risk appetite, ownership.', 'fluent-boards-ai-automation'));
            $this->textarea('definition_of_done', __('Definition of done', 'fluent-boards-ai-automation'), $settings, __('Global done-state rules unless a board profile overrides them.', 'fluent-boards-ai-automation'));
            $this->textarea('escalation_policy', __('Escalation policy', 'fluent-boards-ai-automation'), $settings, __('When to escalate, who should be involved, and what triggers manager/lead review.', 'fluent-boards-ai-automation'));
            $this->textarea('sla_policy', __('SLA / response-time policy', 'fluent-boards-ai-automation'), $settings, __('Expected response times, urgency classes, and escalation thresholds.', 'fluent-boards-ai-automation'));
            $this->textarea('raci_matrix', __('RACI / ownership matrix', 'fluent-boards-ai-automation'), $settings, __('Map role ownership for common task types.', 'fluent-boards-ai-automation'));
            $this->textarea('review_lenses', __('AI council lenses', 'fluent-boards-ai-automation'), $settings, __('Define the review lenses that matter: product, marketing, sales, compliance, customer success, technical, finance, operations.', 'fluent-boards-ai-automation'));
            $this->textarea('board_profiles', __('Board profiles', 'fluent-boards-ai-automation'), $settings, __('JSON array or entries separated by ---. Fields: board_id, name, goal, owner_role, default_reviewer, definition_of_done, risk_rules, notes.', 'fluent-boards-ai-automation'));
            $this->textarea('decision_rules', __('Decision rules', 'fluent-boards-ai-automation'), $settings, __('Practical if/then guidance for common situations.', 'fluent-boards-ai-automation'));
            $this->textarea('automation_playbooks', __('Automation playbooks / guardrails', 'fluent-boards-ai-automation'), $settings, __('Playbooks separated by ---. Fields: name, match, instructions, risk_rules, required_context, reviewer, tags, block_auto_actions, min_confidence.', 'fluent-boards-ai-automation'));
            $this->textarea('knowledge_entries', __('Knowledge entries', 'fluent-boards-ai-automation'), $settings, __('Longer notes separated by a line containing only ---. The plugin retrieves the most relevant entries per task.', 'fluent-boards-ai-automation'));
            ?>
            <?php $this->section_form_close(__('Save Company Memory', 'fluent-boards-ai-automation')); ?>
            <div class="fbaia-actions">
                <form method="post"><?php wp_nonce_field('fbaia_import_knowledge'); ?><input type="hidden" name="fbaia_import_knowledge" value="1"><button class="button"><?php esc_html_e('Import legacy knowledge into library', 'fluent-boards-ai-automation'); ?></button></form>
            </div>
        </div>

        <div class="fbaia-card fbaia-full" id="fbaia-team">
            <h2><?php esc_html_e('Team Directory', 'fluent-boards-ai-automation'); ?></h2>
            <?php $this->template_buttons(['team_directory']); ?>
            <p class="description"><code>Name | email | role | department | responsibilities | skills | boards/projects | capacity | timezone | seniority | manager | notes</code> — <?php esc_html_e('one member per line, or paste a JSON array.', 'fluent-boards-ai-automation'); ?></p>
            <?php $this->section_form_open('team'); ?>
            <?php $this->textarea('team_directory', __('Members', 'fluent-boards-ai-automation'), $settings, __('Example: Sara | sara@example.com | Marketing Manager | Growth | Campaign planning | SEO, Meta Ads | Marketing board | 60% | Europe/Madrid | Senior | CEO | Strong campaign reviewer', 'fluent-boards-ai-automation')); ?>
            <table class="form-table" role="presentation">
                <?php $this->checkbox_row('include_wp_users', __('WordPress users', 'fluent-boards-ai-automation'), __('Also include WordPress users as lightweight team context', 'fluent-boards-ai-automation'), $settings); ?>
            </table>
            <?php $this->section_form_close(__('Save Team Directory', 'fluent-boards-ai-automation')); ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Tab: Review Queue
    // ---------------------------------------------------------------------

    private function tab_review()
    {
        $this->held_recipe_actions_panel();
        $this->suggestions_table();
    }

    private function held_recipe_actions_panel()
    {
        if (!class_exists('FBAIA_Recipe_Queue')) {
            return;
        }
        $pending = FBAIA_Recipe_Queue::all('pending');
        $stats = FBAIA_Recipe_Queue::stats();
        ?>
        <div id="fbaia-recipe-actions" class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Held recipe actions', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('%1$d pending · %2$d applied · %3$d rejected. These are task-changing recipe actions waiting for your approval (recipes in review mode, or with auto-changes off).', 'fluent-boards-ai-automation'), (int) $stats['pending'], (int) $stats['applied'], (int) $stats['rejected'])); ?></p>
            <?php if (empty($pending)) : ?>
                <p><?php esc_html_e('No held actions waiting for review.', 'fluent-boards-ai-automation'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Created UTC', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Recipe', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Task', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Action', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Decision', 'fluent-boards-ai-automation'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($pending, 0, 60) as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item['created_at'] ?? ''); ?></td>
                            <td><?php echo esc_html($item['recipe'] ?? ''); ?></td>
                            <td>#<?php echo esc_html($item['task_id'] ?? 0); ?></td>
                            <td>
                                <strong><?php echo esc_html(str_replace('_', ' ', $item['action_type'] ?? '')); ?></strong>
                                <?php
                                $detail = '';
                                if (($item['action_type'] ?? '') === 'set_priority') {
                                    $detail = $item['value'] ?? '';
                                } elseif (($item['action_type'] ?? '') === 'create_subtasks') {
                                    $detail = implode(' · ', array_slice((array) ($item['items'] ?? []), 0, 5));
                                    if ($detail === '') { $detail = $item['value'] ?? ''; }
                                } elseif (($item['action_type'] ?? '') === 'add_comment') {
                                    $detail = $item['message'] ?: ($item['value'] ?? '');
                                }
                                if ($detail !== '') {
                                    echo '<br><small>' . esc_html($detail) . '</small>';
                                }
                                ?>
                            </td>
                            <td>
                                <form method="post" class="fbaia-inline-form">
                                    <?php wp_nonce_field('fbaia_apply_recipe_action'); ?>
                                    <input type="hidden" name="fbaia_apply_recipe_action" value="1">
                                    <input type="hidden" name="fbaia_action_id" value="<?php echo esc_attr($item['id'] ?? ''); ?>">
                                    <button class="button button-primary"><?php esc_html_e('Approve & apply', 'fluent-boards-ai-automation'); ?></button>
                                </form>
                                <form method="post" class="fbaia-inline-form">
                                    <?php wp_nonce_field('fbaia_reject_recipe_action'); ?>
                                    <input type="hidden" name="fbaia_reject_recipe_action" value="1">
                                    <input type="hidden" name="fbaia_action_id" value="<?php echo esc_attr($item['id'] ?? ''); ?>">
                                    <button class="button"><?php esc_html_e('Reject', 'fluent-boards-ai-automation'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Tab: Logs / Health / Diagnostics
    // ---------------------------------------------------------------------

    private function tab_diagnostics(array $settings)
    {
        $this->health_panel();
        $this->usage_panel();
        $this->insights_panel();
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Maintenance actions', 'fluent-boards-ai-automation'); ?></h2>
            <div class="fbaia-actions">
                <form method="post"><?php wp_nonce_field('fbaia_run_overdue_scan'); ?><input type="hidden" name="fbaia_run_overdue_scan" value="1"><button class="button"><?php esc_html_e('Run overdue scan', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_send_digest'); ?><input type="hidden" name="fbaia_send_digest" value="1"><button class="button"><?php esc_html_e('Send digest now', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_send_weekly_intelligence'); ?><input type="hidden" name="fbaia_send_weekly_intelligence" value="1"><button class="button"><?php esc_html_e('Send weekly intelligence', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_run_health'); ?><input type="hidden" name="fbaia_run_health" value="1"><button class="button"><?php esc_html_e('Run health check', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_export_snapshot'); ?><input type="hidden" name="fbaia_export_snapshot" value="1"><button class="button"><?php esc_html_e('Export snapshot JSON', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post"><?php wp_nonce_field('fbaia_reset_usage'); ?><input type="hidden" name="fbaia_reset_usage" value="1"><button class="button"><?php esc_html_e('Reset usage stats', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Clear the audit trail?', 'fluent-boards-ai-automation')); ?>');"><?php wp_nonce_field('fbaia_clear_audit'); ?><input type="hidden" name="fbaia_clear_audit" value="1"><button class="button button-link-delete"><?php esc_html_e('Clear audit', 'fluent-boards-ai-automation'); ?></button></form>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Clear all logs?', 'fluent-boards-ai-automation')); ?>');"><?php wp_nonce_field('fbaia_clear_logs'); ?><input type="hidden" name="fbaia_clear_logs" value="1"><button class="button button-link-delete"><?php esc_html_e('Clear logs', 'fluent-boards-ai-automation'); ?></button></form>
            </div>
        </div>

        <div class="fbaia-card fbaia-full" id="fbaia-advanced">
            <h2><?php esc_html_e('Advanced', 'fluent-boards-ai-automation'); ?></h2>
            <?php $this->section_form_open('advanced'); ?>
            <?php $this->textarea('system_prompt', __('System prompt', 'fluent-boards-ai-automation'), $settings, __('Edit only if you know what you are doing. The JSON schema must remain compatible with the plugin.', 'fluent-boards-ai-automation')); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><?php esc_html_e('Dedupe window', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="0" max="3600" name="fbaia[dedupe_window_seconds]" value="<?php echo esc_attr($settings['dedupe_window_seconds']); ?>"> <?php esc_html_e('seconds', 'fluent-boards-ai-automation'); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Log retention', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="20" max="1000" name="fbaia[log_retention]" value="<?php echo esc_attr($settings['log_retention']); ?>"></td></tr>
                <tr><th scope="row"><?php esc_html_e('Audit retention', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="50" max="2000" name="fbaia[audit_retention]" value="<?php echo esc_attr($settings['audit_retention'] ?? 500); ?>"></td></tr>
                <tr><th scope="row"><?php esc_html_e('Batch triage', 'fluent-boards-ai-automation'); ?></th><td><input type="number" min="1" max="100" name="fbaia[triage_scan_limit]" value="<?php echo esc_attr($settings['triage_scan_limit'] ?? 20); ?>"> <?php esc_html_e('tasks', 'fluent-boards-ai-automation'); ?> &nbsp; <input type="number" min="1" max="90" name="fbaia[triage_scan_window_days]" value="<?php echo esc_attr($settings['triage_scan_window_days'] ?? 14); ?>"> <?php esc_html_e('updated within days', 'fluent-boards-ai-automation'); ?></td></tr>
                <tr><th scope="row"><label for="fbaia-density"><?php esc_html_e('Admin UI density', 'fluent-boards-ai-automation'); ?></label></th><td><select id="fbaia-density" name="fbaia[admin_ui_density]"><option value="comfortable" <?php selected($settings['admin_ui_density'] ?? 'comfortable', 'comfortable'); ?>><?php esc_html_e('Comfortable', 'fluent-boards-ai-automation'); ?></option><option value="compact" <?php selected($settings['admin_ui_density'] ?? 'comfortable', 'compact'); ?>><?php esc_html_e('Compact', 'fluent-boards-ai-automation'); ?></option></select></td></tr>
                <?php $this->checkbox_row('admin_show_guidance', __('Guided setup panel', 'fluent-boards-ai-automation'), __('Show the onboarding checklist on the dashboard', 'fluent-boards-ai-automation'), $settings); ?>
                <?php $this->checkbox_row('debug', __('Debug', 'fluent-boards-ai-automation'), __('Write sanitized logs to PHP error_log', 'fluent-boards-ai-automation'), $settings); ?>
            </table>
            <?php $this->section_form_close(__('Save Advanced', 'fluent-boards-ai-automation')); ?>
        </div>

        <?php
        $this->audit_table();
        $this->logs_table();
    }

    // ---------------------------------------------------------------------
    // Shared field + panel helpers
    // ---------------------------------------------------------------------

    private function template_buttons(array $targets)
    {
        ?>
        <div class="fbaia-template-bar">
            <span><?php esc_html_e('Starter templates:', 'fluent-boards-ai-automation'); ?></span>
            <?php foreach ($targets as $target) : ?>
                <button type="button" class="button fbaia-template-button" data-template-target="<?php echo esc_attr($target); ?>"><?php echo esc_html(str_replace('_', ' ', $target)); ?></button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function count_playbooks(array $settings)
    {
        if (empty($settings['automation_playbooks'])) {
            return 0;
        }
        $items = preg_split('/\n\s*---+\s*\n/', (string) $settings['automation_playbooks']);
        return is_array($items) ? count(array_filter(array_map('trim', $items))) : 0;
    }

    private function ui_templates()
    {
        return [
            'company_profile' => "Company:\n- What we do:\n- Business model:\n- Current strategic focus:\n- Primary customers:\n- Main constraints:\n\nProducts / services:\n- Product 1:\n- Product 2:\n\nDecision principles:\n- Prefer:\n- Avoid:\n- Escalate when:",
            'team_directory' => "Name | email | role | department | responsibilities | skills | boards/projects | capacity | timezone | seniority | manager | notes\nSara | sara@example.com | Marketing Manager | Growth | Campaign planning, content calendar, reporting | SEO, Meta Ads, GA4 | Marketing board | 70% | Europe/Madrid | Senior | CEO | Reviewer for campaign strategy\nAli | ali@example.com | WordPress Developer | Product | Plugin work, integrations, bug fixes | PHP, JS, REST APIs | Product board | 60% | Europe/Madrid | Mid | CTO | Owner for WordPress technical tasks",
            'board_profiles' => "board_id: 12\nname: Marketing Campaigns\ngoal: Plan, launch, and measure campaigns with clear tracking and creative approval.\nowner_role: Marketing Manager\ndefault_reviewer: Head of Growth\ndefinition_of_done: Objective, audience, offer, assets, tracking plan, publish date, and post-launch review are documented.\nrisk_rules: Flag high risk when budget, tracking, legal claims, or launch date are unclear.\nnotes: Paid traffic tasks require GA4/GTM plan.\n---\nboard_id: 18\nname: Product / Website\ngoal: Deliver website and product changes safely.\nowner_role: Developer\ndefault_reviewer: Product Lead\ndefinition_of_done: Requirements, acceptance criteria, test notes, rollback plan, and owner sign-off are documented.\nrisk_rules: Flag high risk when checkout, payment, user data, SEO-critical pages, or production deployment are affected.",
            'automation_playbooks' => "name: Paid campaign launch guardrail\nmatch: campaign, Meta Ads, Google Ads, landing page, tracking, launch\ninstructions: Check objective, budget owner, channel, landing page, creative approval, tracking plan, publish date, and post-launch reporting.\nrisk_rules: Flag high risk if there is no GA4/GTM plan, unclear budget, unapproved claims, or missing launch owner.\nrequired_context: objective, budget, channel, landing page URL, tracking plan, reviewer\nreviewer: Head of Growth\nblock_auto_actions: yes\nmin_confidence: 0.75\n---\nname: Website production change guardrail\nmatch: WordPress, checkout, payment, SEO, production, plugin, theme\ninstructions: Require acceptance criteria, staging test, rollback plan, and reviewer.\nrisk_rules: High risk if it touches payment, login, SEO-critical pages, tracking, or customer data.\nrequired_context: affected URL, expected behavior, test plan, rollback plan, reviewer\nreviewer: Product/Technical Lead\nblock_auto_actions: yes\nmin_confidence: 0.80",
        ];
    }

    private function checkbox_row($key, $label, $description, array $settings, $warn = false)
    {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td><label class="<?php echo $warn ? 'fbaia-warn-label' : ''; ?>"><input type="checkbox" name="fbaia[<?php echo esc_attr($key); ?>]" value="yes" <?php checked($settings[$key] ?? 'no', 'yes'); ?>> <?php echo esc_html($description); ?><?php if ($warn) : ?> <span class="fbaia-warn-pill"><?php esc_html_e('changes tasks', 'fluent-boards-ai-automation'); ?></span><?php endif; ?></label></td>
        </tr>
        <?php
    }

    private function textarea($key, $label, array $settings, $description = '')
    {
        ?>
        <div class="fbaia-field-block">
            <label for="fbaia-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
            <?php if ($description) : ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
            <textarea id="fbaia-<?php echo esc_attr($key); ?>" name="fbaia[<?php echo esc_attr($key); ?>]" rows="6" class="large-text code"><?php echo esc_textarea($settings[$key] ?? ''); ?></textarea>
        </div>
        <?php
    }

    private function suggestions_table()
    {
        if (!class_exists('FBAIA_Suggestion_Store')) {
            echo '<div class="fbaia-card fbaia-full"><p>' . esc_html__('Suggestion store is not available.', 'fluent-boards-ai-automation') . '</p></div>';
            return;
        }
        $stats = FBAIA_Suggestion_Store::stats();
        $suggestions = FBAIA_Suggestion_Store::all();
        ?>
        <div id="fbaia-suggestions" class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('AI Suggestion Review Queue', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('%1$d pending · %2$d applied · %3$d rejected · avg confidence %4$d%%', 'fluent-boards-ai-automation'), (int) $stats['pending'], (int) $stats['applied'], (int) $stats['rejected'], (int) round((float) $stats['avg_confidence'] * 100))); ?></p>
            <p class="description"><?php esc_html_e('Suggestions are advisory until you approve them. Approving applies the recommended priority/subtasks; rejecting records feedback the assistant learns from.', 'fluent-boards-ai-automation'); ?></p>
            <?php if (empty($suggestions)) : ?>
                <p><?php esc_html_e('No suggestions stored yet.', 'fluent-boards-ai-automation'); ?></p>
            <?php else : ?>
                <table class="widefat striped fbaia-suggestions-table">
                    <thead><tr><th><?php esc_html_e('Created UTC', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Status', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Task', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Recommendation', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Scores', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Decision', 'fluent-boards-ai-automation'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($suggestions, 0, 60) as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item['created_at'] ?? ''); ?><br><code><?php echo esc_html(substr($item['id'] ?? '', 0, 8)); ?></code></td>
                            <td><span class="fbaia-level fbaia-level-<?php echo esc_attr($item['status'] ?? 'pending'); ?>"><?php echo esc_html($item['status'] ?? 'pending'); ?></span><br><small><?php echo (($item['status'] ?? 'pending') === 'pending') ? esc_html__('waiting for approval', 'fluent-boards-ai-automation') : esc_html__('resolved', 'fluent-boards-ai-automation'); ?></small></td>
                            <td>#<?php echo esc_html($item['task_id'] ?? 0); ?><br><?php echo esc_html($item['task_title'] ?? ''); ?><br><small><?php echo esc_html($item['event'] ?? ''); ?></small></td>
                            <td>
                                <strong><?php echo esc_html($item['summary'] ?? ''); ?></strong>
                                <?php if (!empty($item['recommended_priority'])) : ?><br><small><?php esc_html_e('Priority:', 'fluent-boards-ai-automation'); ?> <?php echo esc_html($item['recommended_priority']); ?></small><?php endif; ?>
                                <?php if (!empty($item['decision_brief'])) : ?><br><small><?php esc_html_e('Brief:', 'fluent-boards-ai-automation'); ?> <?php echo esc_html($item['decision_brief']); ?></small><?php endif; ?>
                                <?php if (!empty($item['matched_playbook_count'])) : ?><br><small><?php esc_html_e('Playbooks:', 'fluent-boards-ai-automation'); ?> <?php echo esc_html($item['matched_playbook_count']); ?></small><?php endif; ?>
                                <?php if (!empty($item['feedback'])) : ?><br><small><?php esc_html_e('Feedback:', 'fluent-boards-ai-automation'); ?> <?php echo esc_html($item['feedback']); ?></small><?php endif; ?>
                            </td>
                            <td><?php esc_html_e('Confidence', 'fluent-boards-ai-automation'); ?> <?php echo esc_html(round((float) ($item['confidence'] ?? 0) * 100)); ?>%<br><?php esc_html_e('Risk', 'fluent-boards-ai-automation'); ?> <?php echo esc_html(round((float) ($item['risk_score'] ?? 0) * 100)); ?>%</td>
                            <td>
                                <?php if (($item['status'] ?? 'pending') === 'pending') : ?>
                                    <form method="post" class="fbaia-inline-form">
                                        <?php wp_nonce_field('fbaia_apply_suggestion'); ?>
                                        <input type="hidden" name="fbaia_apply_suggestion" value="1">
                                        <input type="hidden" name="fbaia_suggestion_id" value="<?php echo esc_attr($item['id']); ?>">
                                        <button class="button button-primary"><?php esc_html_e('Approve & apply', 'fluent-boards-ai-automation'); ?></button>
                                    </form>
                                    <form method="post" class="fbaia-inline-form fbaia-reject-form">
                                        <?php wp_nonce_field('fbaia_reject_suggestion'); ?>
                                        <input type="hidden" name="fbaia_reject_suggestion" value="1">
                                        <input type="hidden" name="fbaia_suggestion_id" value="<?php echo esc_attr($item['id']); ?>">
                                        <label class="screen-reader-text" for="fbaia-reject-<?php echo esc_attr(substr($item['id'], 0, 8)); ?>"><?php esc_html_e('Rejection feedback', 'fluent-boards-ai-automation'); ?></label>
                                        <input id="fbaia-reject-<?php echo esc_attr(substr($item['id'], 0, 8)); ?>" type="text" name="fbaia_feedback" placeholder="<?php esc_attr_e('Why reject?', 'fluent-boards-ai-automation'); ?>" class="regular-text">
                                        <button class="button"><?php esc_html_e('Reject', 'fluent-boards-ai-automation'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <small><?php echo esc_html($item['status'] ?? ''); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function usage_panel()
    {
        if (!class_exists('FBAIA_Usage_Tracker')) {
            return;
        }
        $snapshot = FBAIA_Usage_Tracker::snapshot();
        $settings = FBAIA_Helpers::get_settings();
        ?>
        <div id="fbaia-usage" class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('AI Usage & Cost Guard', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('Today: %1$d / %2$d calls · Month: %3$d / %4$d tokens.', 'fluent-boards-ai-automation'), (int) ($snapshot['today']['calls'] ?? 0), (int) ($settings['daily_ai_call_budget'] ?? 0), (int) ($snapshot['month']['total_tokens'] ?? 0), (int) ($settings['monthly_token_budget'] ?? 0))); ?></p>
            <?php if (!empty($snapshot['recent'])) : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Time UTC', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Event', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Task', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Model', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Tokens', 'fluent-boards-ai-automation'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($snapshot['recent'], 0, 10) as $row) : ?>
                        <tr><td><?php echo esc_html($row['time'] ?? ''); ?></td><td><?php echo esc_html($row['event'] ?? ''); ?></td><td>#<?php echo esc_html($row['task_id'] ?? 0); ?></td><td><?php echo esc_html($row['model'] ?? ''); ?></td><td><?php echo esc_html($row['usage']['total_tokens'] ?? $row['estimated_tokens'] ?? 0); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function health_panel()
    {
        if (!class_exists('FBAIA_Health_Check')) {
            return;
        }
        $health = FBAIA_Health_Check::diagnostics();
        $checks = $health['checks'] ?? [];
        ?>
        <div id="fbaia-health" class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('System Health', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('Generated %1$s · Fluent Boards %2$s · Action Scheduler %3$s', 'fluent-boards-ai-automation'), $health['generated_at'] ?? '', !empty($health['fluent_boards_detected']) ? __('detected', 'fluent-boards-ai-automation') : __('not detected', 'fluent-boards-ai-automation'), !empty($health['action_scheduler']) ? __('available', 'fluent-boards-ai-automation') : __('not available', 'fluent-boards-ai-automation'))); ?></p>
            <div class="fbaia-check-grid">
                <?php foreach ($checks as $check) : ?>
                    <div class="fbaia-health-item fbaia-health-<?php echo esc_attr($check['severity'] ?? 'ok'); ?>">
                        <strong><?php echo esc_html($check['label'] ?? ''); ?></strong><br>
                        <small><?php echo esc_html($check['message'] ?? ''); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function insights_panel()
    {
        if (!class_exists('FBAIA_Insights')) {
            return;
        }
        $snapshot = FBAIA_Insights::snapshot();
        ?>
        <div id="fbaia-insights" class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Operational Intelligence', 'fluent-boards-ai-automation'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('%1$d suggestions · %2$d high risk · avg confidence %3$d%% · %4$d knowledge gaps', 'fluent-boards-ai-automation'), (int) ($snapshot['suggestions_total'] ?? 0), (int) ($snapshot['high_risk'] ?? 0), (int) round((float) ($snapshot['avg_confidence'] ?? 0) * 100), !empty($snapshot['knowledge_gaps']) ? array_sum($snapshot['knowledge_gaps']) : 0)); ?></p>
            <div class="fbaia-grid">
                <div><strong><?php esc_html_e('Top task types', 'fluent-boards-ai-automation'); ?></strong><pre><?php echo esc_html(wp_json_encode($snapshot['top_task_types'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
                <div><strong><?php esc_html_e('Recurring risks', 'fluent-boards-ai-automation'); ?></strong><pre><?php echo esc_html(wp_json_encode($snapshot['top_risks'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
                <div><strong><?php esc_html_e('Knowledge gaps', 'fluent-boards-ai-automation'); ?></strong><pre><?php echo esc_html(wp_json_encode($snapshot['knowledge_gaps'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
                <div><strong><?php esc_html_e('Top events', 'fluent-boards-ai-automation'); ?></strong><pre><?php echo esc_html(wp_json_encode($snapshot['top_events'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
            </div>
        </div>
        <?php
    }

    private function audit_table()
    {
        if (!class_exists('FBAIA_Audit_Trail')) {
            return;
        }
        $items = FBAIA_Audit_Trail::all(80);
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Audit Trail', 'fluent-boards-ai-automation'); ?></h2>
            <?php if (empty($items)) : ?>
                <p><?php esc_html_e('No audit records yet.', 'fluent-boards-ai-automation'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Time UTC', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Action', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Task', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Status', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Context', 'fluent-boards-ai-automation'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item['time'] ?? ''); ?></td>
                            <td><?php echo esc_html($item['action'] ?? ''); ?></td>
                            <td>#<?php echo esc_html($item['task_id'] ?? 0); ?></td>
                            <td><?php echo esc_html($item['status'] ?? ''); ?></td>
                            <td><pre><?php echo esc_html(wp_json_encode($item['context'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function logs_table()
    {
        $logs = FBAIA_Logger::all();
        ?>
        <div class="fbaia-card fbaia-full">
            <h2><?php esc_html_e('Logs', 'fluent-boards-ai-automation'); ?></h2>
            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No logs yet.', 'fluent-boards-ai-automation'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Time UTC', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Level', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Message', 'fluent-boards-ai-automation'); ?></th><th><?php esc_html_e('Context', 'fluent-boards-ai-automation'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($logs, 0, 80) as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log['time'] ?? ''); ?></td>
                            <td><span class="fbaia-level fbaia-level-<?php echo esc_attr($log['level'] ?? 'info'); ?>"><?php echo esc_html($log['level'] ?? 'info'); ?></span></td>
                            <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                            <td><pre><?php echo esc_html(wp_json_encode($log['context'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
