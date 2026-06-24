<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Helpers
{
    const GENERATED_MARKER = '<!-- fbaia-generated -->';
    const MAX_STRING_LENGTH = 3000;
    const MAX_ARRAY_ITEMS = 80;

    // Hidden marker rendered as the LAST field of every settings form. If a save arrives
    // without it, the POST body was truncated (e.g. PHP max_input_vars) and must be rejected
    // instead of persisted, otherwise later fields would be silently reset.
    const FORM_SENTINEL = '__fbaia_complete';

    public static function defaults()
    {
        return [
            'enabled'                        => 'yes',
            'api_base'                       => 'https://api.openai.com/v1',
            'api_key'                        => '',
            'model'                          => 'gpt-4o-mini',
            'temperature'                    => '0.15',
            'max_tokens'                     => '1800',
            'force_json_response'            => 'yes',
            'system_prompt'                  => self::default_system_prompt(),
            'output_language'                 => 'site_locale',
            'mode'                           => 'internal_ai_actions',
            'triggers'                       => [
                'task_created',
                'task_stage_changed',
                'task_priority_changed',
                'task_date_changed',
                'comment_created',
            ],
            'board_allowlist'                => '',
            'rate_limit_per_hour'            => '40',
            'dedupe_window_seconds'          => '60',
            'write_private_comment'          => 'yes',
            'action_ai_comment'              => 'yes',
            'action_update_priority'         => 'no',
            'action_create_subtasks'         => 'no',
            'max_subtasks_per_task'          => '5',
            'action_email_admin'             => 'no',
            'notify_email'                   => get_option('admin_email'),
            'notify_only_risky'              => 'yes',
            'overdue_scan_enabled'           => 'no',
            'overdue_comment_enabled'        => 'yes',
            'overdue_email_enabled'          => 'no',
            'overdue_scan_limit'             => '25',
            'daily_digest_enabled'           => 'no',
            'daily_digest_email'             => get_option('admin_email'),
            'daily_digest_hour'              => '9',
            'log_retention'                  => '200',
            'debug'                          => 'no',

            // v0.4 intelligence / company memory.
            'knowledge_enabled'              => 'yes',
            'company_profile'                => '',
            'company_products'               => '',
            'company_goals'                  => '',
            'company_customers'              => '',
            'company_constraints'            => '',
            'company_glossary'               => '',
            'knowledge_entries'              => '',
            'team_directory'                 => '',
            'include_wp_users'               => 'no',
            'max_context_chars'              => '12000',
            'recommendation_depth'           => 'strategic',
            'recommendation_style'           => 'direct',
            'confidence_threshold'           => '0.60',
            'minimum_comment_confidence'      => '0.35',
            'suppress_low_value_comments'     => 'yes',
            'action_execution_policy'        => 'review_first',
            'store_ai_suggestions'           => 'yes',
            'feedback_learning_enabled'      => 'yes',
            'use_knowledge_posts'            => 'yes',
            'knowledge_post_limit'           => '5',
            'approval_comment_note'          => 'yes',
            'enable_task_enrichment'          => 'yes',
            'context_comment_limit'           => '8',
            'context_subtask_limit'           => '15',
            'ask_clarifying_questions'       => 'yes',
            'suggest_owner'                  => 'yes',
            'suggest_acceptance_criteria'    => 'yes',
            'suggest_due_date'               => 'yes',
            'suggest_reviewer'                => 'yes',
            'decision_rules'                  => '',
            'business_metrics'                => '',
            'brand_voice'                     => '',
            'project_playbooks'               => '',
            'operating_principles'            => '',
            'definition_of_done'              => '',
            'escalation_policy'               => '',
            'board_profiles'                  => '',
            'context_feedback_limit'          => '5',
            'team_member_limit'               => '8',
            'knowledge_entry_limit'           => '5',
            'memory_quality_guard'            => 'yes',
            'weekly_intelligence_enabled'     => 'no',
            'weekly_intelligence_email'       => get_option('admin_email'),
            'weekly_intelligence_day'         => '1',
            'weekly_intelligence_hour'        => '9',
            'audit_retention'                 => '500',

            // v0.8 controls for cost safety, playbooks, and batch triage.
            'cost_guard_enabled'             => 'yes',
            'daily_ai_call_budget'           => '60',
            'monthly_token_budget'           => '250000',
            'playbooks_enabled'              => 'yes',
            'automation_playbooks'           => '',
            'playbook_limit'                 => '5',
            'comment_cooldown_minutes'       => '15',
            'triage_scan_limit'              => '20',
            'triage_scan_window_days'        => '14',

            // v0.9 smarter reasoning and admin UX controls.
            'ai_council_enabled'             => 'yes',
            'task_quality_score_enabled'     => 'yes',
            'raci_enabled'                   => 'yes',
            'meeting_recommendations_enabled'=> 'yes',
            'sla_policy'                     => '',
            'raci_matrix'                    => '',
            'review_lenses'                  => '',
            'admin_ui_density'               => 'comfortable',
            'admin_show_guidance'            => 'yes',

            // v0.10 monday-style automation recipes (deterministic When/If/Then).
            'recipes_enabled'                => 'no',
            'recipe_allow_mutations'         => 'no',

            // Legacy webhook settings are retained only so upgrades do not lose data.
            'webhook_url'                    => '',
            'webhook_secret'                 => '',
            'require_incoming_timestamp'     => 'yes',
        ];
    }

    public static function default_system_prompt()
    {
        return "You are an expert AI operating partner embedded inside WordPress for Fluent Boards. You help the company make better task-management decisions using only the provided Company Memory, Board Profiles, Team Directory, Knowledge Library, prior approved/rejected suggestions, audit signals, task snapshot, comments, and event payload. Be specific, operational, and evidence-based. Never invent internal facts. Return valid JSON only with this shape: {\"summary\":\"...\",\"why_this_matters\":\"...\",\"recommended_priority\":\"low|medium|high|urgent|null\",\"confidence\":0.0,\"impact\":\"low|medium|high\",\"effort\":\"low|medium|high\",\"decision_brief\":{\"headline\":\"...\",\"recommended_manager_action\":\"...\",\"do_next\":\"...\"},\"task_quality\":{\"score\":0.0,\"grade\":\"A|B|C|D|F\",\"reason\":\"...\",\"missing_fields\":[\"...\"],\"improvement_suggestions\":[\"...\"]},\"decision_quality\":{\"task_type\":\"...\",\"urgency_score\":0.0,\"impact_score\":0.0,\"effort_score\":0.0,\"risk_score\":0.0,\"confidence_reason\":\"...\"},\"ai_council\":[{\"lens\":\"Product|Marketing|Operations|Risk|Customer|Finance|Technical\",\"observation\":\"...\",\"recommendation\":\"...\",\"risk\":\"low|medium|high\"}],\"raci\":{\"responsible\":\"...\",\"accountable\":\"...\",\"consulted\":[\"...\"],\"informed\":[\"...\"],\"reason\":\"...\"},\"meeting_recommendation\":{\"needed\":false,\"type\":\"async_update|quick_sync|decision_meeting|none\",\"reason\":\"...\",\"agenda\":[\"...\"]},\"owner_recommendation\":{\"name\":\"...\",\"email\":\"...\",\"role\":\"...\",\"reason\":\"...\"},\"reviewer_recommendation\":{\"name\":\"...\",\"email\":\"...\",\"role\":\"...\",\"reason\":\"...\"},\"due_date_suggestion\":{\"date\":\"YYYY-MM-DD|null\",\"reason\":\"...\"},\"next_actions\":[\"...\"],\"work_breakdown\":[{\"title\":\"...\",\"owner_role\":\"...\",\"acceptance\":\"...\"}],\"acceptance_criteria\":[\"...\"],\"quality_gates\":[\"...\"],\"clarifying_questions\":[\"...\"],\"dependencies\":[\"...\"],\"blocked_by\":[\"...\"],\"risk_flags\":[\"...\"],\"knowledge_gaps\":[\"...\"],\"team_coordination\":[\"...\"],\"escalation\":{\"needed\":false,\"reason\":\"...\",\"role_or_person\":\"...\"},\"context_used\":[\"...\"],\"skip_comment\":false,\"automation_safe\":false,\"comment\":\"...\",\"learning_notes\":[\"...\"],\"manager_decision\":{\"approve_priority_change\":false,\"approve_subtasks\":false,\"reason\":\"...\"},\"internal_actions\":{\"create_subtasks\":[\"...\"],\"notify_admin\":false,\"priority_reason\":\"...\"}}. Use team roles to recommend owners/reviewers only when there is evidence. Use the RACI matrix, SLA policy, review lenses, board profile, and playbooks when available. AI council should summarize different business lenses without bloating the comment. Task quality score should judge whether the task is actionable, clear, owned, measurable, and safe. Meeting recommendation should prefer async unless a real decision, cross-functional conflict, blocker, risk, or ambiguity requires live discussion. Identify missing company knowledge as knowledge_gaps. Use prior approved/rejected suggestions as learning context, not as absolute truth. Obey matched automation playbooks and explicitly explain when a playbook blocks auto-action. If information is missing, lower confidence and ask focused questions. Do not expose secrets, credentials, tokens, or private personal data.";
    }


    public static function get_settings()
    {
        $saved = get_option(FBAIA_OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $settings = wp_parse_args($saved, self::defaults());

        if (!is_array($settings['triggers'])) {
            $settings['triggers'] = array_filter(array_map('trim', explode(',', (string) $settings['triggers'])));
        }

        if (defined('FBAIA_OPENAI_API_KEY') && FBAIA_OPENAI_API_KEY) {
            $settings['api_key'] = FBAIA_OPENAI_API_KEY;
        }

        if (defined('FBAIA_OPENAI_API_BASE') && FBAIA_OPENAI_API_BASE) {
            $settings['api_base'] = FBAIA_OPENAI_API_BASE;
        }

        return $settings;
    }

    /**
     * Field specification registry. Single source of truth for sanitization.
     *
     * Each entry: type => one of checkbox|text|key|email|url|enum|int|float|largetext|prompt.
     * int/float also carry min/max; enum carries options. Keys NOT listed here are handled
     * as special cases (triggers, api_key) or preserved verbatim (legacy webhook_* keys).
     */
    public static function field_specs()
    {
        return [
            'enabled'                         => ['type' => 'checkbox'],
            'api_base'                        => ['type' => 'url'],
            'model'                           => ['type' => 'text'],
            'temperature'                     => ['type' => 'float', 'min' => 0, 'max' => 2],
            'max_tokens'                      => ['type' => 'int', 'min' => 100, 'max' => 8000],
            'force_json_response'             => ['type' => 'checkbox'],
            'system_prompt'                   => ['type' => 'prompt'],
            'output_language'                 => ['type' => 'enum', 'options' => ['site_locale', 'english', 'spanish', 'persian']],
            'mode'                            => ['type' => 'enum', 'options' => ['internal_ai_actions', 'internal_ai_comment', 'internal_log_only']],
            'board_allowlist'                 => ['type' => 'text'],
            'rate_limit_per_hour'             => ['type' => 'int', 'min' => 1, 'max' => 500],
            'dedupe_window_seconds'           => ['type' => 'int', 'min' => 0, 'max' => 3600],
            'write_private_comment'           => ['type' => 'checkbox'],
            'action_ai_comment'               => ['type' => 'checkbox'],
            'action_update_priority'          => ['type' => 'checkbox'],
            'action_create_subtasks'          => ['type' => 'checkbox'],
            'max_subtasks_per_task'           => ['type' => 'int', 'min' => 1, 'max' => 20],
            'action_email_admin'              => ['type' => 'checkbox'],
            'notify_email'                    => ['type' => 'email'],
            'notify_only_risky'               => ['type' => 'checkbox'],
            'overdue_scan_enabled'            => ['type' => 'checkbox'],
            'overdue_comment_enabled'         => ['type' => 'checkbox'],
            'overdue_email_enabled'           => ['type' => 'checkbox'],
            'overdue_scan_limit'              => ['type' => 'int', 'min' => 1, 'max' => 100],
            'daily_digest_enabled'            => ['type' => 'checkbox'],
            'daily_digest_email'              => ['type' => 'email'],
            'daily_digest_hour'               => ['type' => 'int', 'min' => 0, 'max' => 23],
            'log_retention'                   => ['type' => 'int', 'min' => 20, 'max' => 1000],
            'debug'                           => ['type' => 'checkbox'],
            'knowledge_enabled'               => ['type' => 'checkbox'],
            'company_profile'                 => ['type' => 'largetext'],
            'company_products'                => ['type' => 'largetext'],
            'company_goals'                   => ['type' => 'largetext'],
            'company_customers'               => ['type' => 'largetext'],
            'company_constraints'             => ['type' => 'largetext'],
            'company_glossary'                => ['type' => 'largetext'],
            'knowledge_entries'               => ['type' => 'largetext'],
            'team_directory'                  => ['type' => 'largetext'],
            'include_wp_users'                => ['type' => 'checkbox'],
            'max_context_chars'               => ['type' => 'int', 'min' => 2000, 'max' => 50000],
            'recommendation_depth'            => ['type' => 'enum', 'options' => ['simple', 'standard', 'strategic']],
            'recommendation_style'            => ['type' => 'enum', 'options' => ['direct', 'supportive', 'executive']],
            'confidence_threshold'            => ['type' => 'float', 'min' => 0, 'max' => 1],
            'minimum_comment_confidence'      => ['type' => 'float', 'min' => 0, 'max' => 1],
            'suppress_low_value_comments'     => ['type' => 'checkbox'],
            'action_execution_policy'         => ['type' => 'enum', 'options' => ['review_first', 'safe_auto']],
            'store_ai_suggestions'            => ['type' => 'checkbox'],
            'feedback_learning_enabled'       => ['type' => 'checkbox'],
            'use_knowledge_posts'             => ['type' => 'checkbox'],
            'knowledge_post_limit'            => ['type' => 'int', 'min' => 0, 'max' => 12],
            'enable_task_enrichment'          => ['type' => 'checkbox'],
            'context_comment_limit'           => ['type' => 'int', 'min' => 0, 'max' => 30],
            'context_subtask_limit'           => ['type' => 'int', 'min' => 0, 'max' => 50],
            'ask_clarifying_questions'        => ['type' => 'checkbox'],
            'suggest_owner'                   => ['type' => 'checkbox'],
            'suggest_acceptance_criteria'     => ['type' => 'checkbox'],
            'suggest_due_date'                => ['type' => 'checkbox'],
            'suggest_reviewer'                => ['type' => 'checkbox'],
            'decision_rules'                  => ['type' => 'largetext'],
            'business_metrics'                => ['type' => 'largetext'],
            'brand_voice'                     => ['type' => 'largetext'],
            'project_playbooks'               => ['type' => 'largetext'],
            'operating_principles'            => ['type' => 'largetext'],
            'definition_of_done'              => ['type' => 'largetext'],
            'escalation_policy'               => ['type' => 'largetext'],
            'board_profiles'                  => ['type' => 'largetext'],
            'context_feedback_limit'          => ['type' => 'int', 'min' => 0, 'max' => 15],
            'team_member_limit'               => ['type' => 'int', 'min' => 1, 'max' => 30],
            'knowledge_entry_limit'           => ['type' => 'int', 'min' => 0, 'max' => 15],
            'memory_quality_guard'            => ['type' => 'checkbox'],
            'weekly_intelligence_enabled'     => ['type' => 'checkbox'],
            'weekly_intelligence_email'       => ['type' => 'email'],
            'weekly_intelligence_day'         => ['type' => 'int', 'min' => 1, 'max' => 7],
            'weekly_intelligence_hour'        => ['type' => 'int', 'min' => 0, 'max' => 23],
            'audit_retention'                 => ['type' => 'int', 'min' => 50, 'max' => 2000],
            'cost_guard_enabled'              => ['type' => 'checkbox'],
            'daily_ai_call_budget'            => ['type' => 'int', 'min' => 0, 'max' => 1000],
            'monthly_token_budget'            => ['type' => 'int', 'min' => 0, 'max' => 5000000],
            'playbooks_enabled'               => ['type' => 'checkbox'],
            'automation_playbooks'            => ['type' => 'largetext'],
            'playbook_limit'                  => ['type' => 'int', 'min' => 0, 'max' => 12],
            'comment_cooldown_minutes'        => ['type' => 'int', 'min' => 0, 'max' => 1440],
            'triage_scan_limit'               => ['type' => 'int', 'min' => 1, 'max' => 100],
            'triage_scan_window_days'         => ['type' => 'int', 'min' => 1, 'max' => 90],
            'ai_council_enabled'              => ['type' => 'checkbox'],
            'task_quality_score_enabled'      => ['type' => 'checkbox'],
            'raci_enabled'                    => ['type' => 'checkbox'],
            'meeting_recommendations_enabled' => ['type' => 'checkbox'],
            'sla_policy'                      => ['type' => 'largetext'],
            'raci_matrix'                     => ['type' => 'largetext'],
            'review_lenses'                   => ['type' => 'largetext'],
            'admin_ui_density'                => ['type' => 'enum', 'options' => ['comfortable', 'compact']],
            'admin_show_guidance'             => ['type' => 'checkbox'],
            'recipes_enabled'                 => ['type' => 'checkbox'],
            'recipe_allow_mutations'          => ['type' => 'checkbox'],
        ];
    }

    /**
     * Tab/section registry for the admin command center.
     *
     * Maps each admin section to the setting keys it owns. Section-scoped saving
     * only ever touches the keys for the submitted section, so a partial POST or
     * a max_input_vars truncation can never wipe settings that belong to other
     * sections. Every editable key must live in exactly one section.
     */
    public static function sections()
    {
        return [
            'provider' => [
                'api_base', 'api_key', 'model', 'temperature', 'max_tokens',
                'force_json_response', 'rate_limit_per_hour', 'cost_guard_enabled',
                'daily_ai_call_budget', 'monthly_token_budget',
            ],
            'intelligence' => [
                'knowledge_enabled', 'recommendation_depth', 'recommendation_style', 'output_language',
                'max_context_chars', 'confidence_threshold', 'minimum_comment_confidence',
                'enable_task_enrichment', 'context_comment_limit', 'context_subtask_limit',
                'suggest_owner', 'suggest_reviewer', 'suggest_acceptance_criteria', 'suggest_due_date',
                'ask_clarifying_questions', 'suppress_low_value_comments', 'task_quality_score_enabled',
                'ai_council_enabled', 'raci_enabled', 'meeting_recommendations_enabled',
                'store_ai_suggestions', 'feedback_learning_enabled', 'use_knowledge_posts',
                'knowledge_post_limit', 'knowledge_entry_limit', 'context_feedback_limit',
                'team_member_limit', 'memory_quality_guard', 'playbooks_enabled', 'playbook_limit',
                'comment_cooldown_minutes',
            ],
            'automations' => [
                'enabled', 'mode', 'board_allowlist', 'action_execution_policy', 'triggers',
                'write_private_comment', 'action_ai_comment', 'action_update_priority',
                'action_create_subtasks', 'max_subtasks_per_task', 'action_email_admin',
                'notify_email', 'notify_only_risky',
                'overdue_scan_enabled', 'overdue_comment_enabled', 'overdue_email_enabled', 'overdue_scan_limit',
                'daily_digest_enabled', 'daily_digest_email', 'daily_digest_hour',
                'weekly_intelligence_enabled', 'weekly_intelligence_email', 'weekly_intelligence_day', 'weekly_intelligence_hour',
                'recipes_enabled', 'recipe_allow_mutations',
            ],
            'memory' => [
                'company_profile', 'company_products', 'company_goals', 'company_customers',
                'company_constraints', 'company_glossary', 'business_metrics', 'brand_voice',
                'project_playbooks', 'operating_principles', 'definition_of_done', 'escalation_policy',
                'sla_policy', 'raci_matrix', 'review_lenses', 'decision_rules', 'board_profiles',
                'automation_playbooks', 'knowledge_entries',
            ],
            'team' => [
                'team_directory', 'include_wp_users',
            ],
            'advanced' => [
                'system_prompt', 'dedupe_window_seconds', 'log_retention', 'audit_retention',
                'triage_scan_limit', 'triage_scan_window_days', 'admin_ui_density',
                'admin_show_guidance', 'debug',
            ],
        ];
    }

    /**
     * Resolve the editable setting keys for a section. Returns [] for unknown sections.
     */
    public static function section_keys($section)
    {
        $sections = self::sections();
        $section = sanitize_key($section);
        return isset($sections[$section]) ? $sections[$section] : [];
    }

    /**
     * Whole-form save. Treats every editable field as in-scope. Still merges over the
     * stored option (never reconstructs from defaults), so unknown/legacy keys survive.
     */
    public static function update_settings($settings)
    {
        $all_keys = array_keys(self::field_specs());
        $all_keys[] = 'triggers';
        $all_keys[] = 'api_key';
        return self::apply_updates($all_keys, $settings);
    }

    /**
     * Section-scoped save. Only the keys belonging to $section are recomputed; every
     * other stored value is preserved verbatim. This is the safe default used by the
     * tabbed admin UI and is the primary defence against partial-POST data loss.
     */
    public static function update_settings_section($section, $settings)
    {
        $keys = self::section_keys($section);
        if (empty($keys)) {
            return new WP_Error('fbaia_unknown_section', __('Unknown settings section.', 'fluent-boards-ai-automation'));
        }
        return self::apply_updates($keys, $settings);
    }

    /**
     * Non-destructive write: start from the currently stored settings (back-filled with
     * defaults so every key exists), then overwrite ONLY the requested keys. Values are
     * expected to already be unslashed by the caller.
     *
     * @param string[] $keys     Keys to recompute from $raw.
     * @param array    $raw       Submitted (unslashed) values, typically $_POST['fbaia'].
     * @return array The full, persisted settings array.
     */
    private static function apply_updates(array $keys, $raw)
    {
        $raw = is_array($raw) ? $raw : [];
        $stored = get_option(FBAIA_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        // Back-fill missing keys from defaults without clobbering stored values.
        $current = wp_parse_args($stored, self::defaults());

        foreach ($keys as $key) {
            $current[$key] = self::sanitize_field($key, $raw, $current);
        }

        // Legacy webhook data is never edited through the UI; always carry it forward.
        $current['webhook_url'] = isset($stored['webhook_url']) ? $stored['webhook_url'] : '';
        $current['webhook_secret'] = isset($stored['webhook_secret']) ? $stored['webhook_secret'] : '';
        $current['require_incoming_timestamp'] = 'yes';

        update_option(FBAIA_OPTION, $current, false);

        if (class_exists('FBAIA_Plugin')) {
            FBAIA_Plugin::reschedule_events($current);
        }

        return $current;
    }

    /**
     * Sanitize one field from the submitted payload, preserving the stored value when the
     * field is non-boolean and absent from the payload. Checkboxes and the triggers group
     * follow HTML semantics (absent = off / none selected).
     *
     * @param string $key
     * @param array  $raw      Submitted (unslashed) payload.
     * @param array  $current  Current settings (used to preserve absent values).
     */
    private static function sanitize_field($key, array $raw, array $current)
    {
        $defaults = self::defaults();
        $stored_value = array_key_exists($key, $current) ? $current[$key] : ($defaults[$key] ?? '');

        // Multi-checkbox group: absence means "none selected" for the submitted section.
        if ($key === 'triggers') {
            $allowed = self::available_triggers();
            $incoming = (isset($raw['triggers']) && is_array($raw['triggers'])) ? $raw['triggers'] : [];
            return array_values(array_intersect(array_map('sanitize_key', $incoming), array_keys($allowed)));
        }

        // API key: only overwrite when a new non-empty key is provided; never echo or wipe.
        if ($key === 'api_key') {
            $new_key = isset($raw['api_key']) ? trim((string) $raw['api_key']) : '';
            if ($new_key !== '') {
                return sanitize_text_field($new_key);
            }
            return is_string($stored_value) ? $stored_value : '';
        }

        $specs = self::field_specs();
        $spec = isset($specs[$key]) ? $specs[$key] : ['type' => 'text'];

        // Checkboxes follow HTML form semantics: absent = unchecked = "no".
        if ($spec['type'] === 'checkbox') {
            return (isset($raw[$key]) && $raw[$key] === 'yes') ? 'yes' : 'no';
        }

        // For every other field: only overwrite when actually present in the payload.
        // This protects individual fields from truncation even within a submitted section.
        if (!array_key_exists($key, $raw)) {
            return $stored_value;
        }

        $value = $raw[$key];

        switch ($spec['type']) {
            case 'int':
                return (string) max($spec['min'], min($spec['max'], (int) $value));
            case 'float':
                return (string) max($spec['min'], min($spec['max'], (float) $value));
            case 'email':
                return sanitize_email($value);
            case 'url':
                return esc_url_raw((string) $value);
            case 'enum':
                $v = sanitize_key($value);
                return in_array($v, $spec['options'], true) ? $v : ($defaults[$key] ?? $spec['options'][0]);
            case 'largetext':
                return self::sanitize_large_text($value);
            case 'prompt':
                return self::limit_chars(sanitize_textarea_field((string) $value), 20000);
            case 'key':
                return sanitize_key($value);
            case 'text':
            default:
                return sanitize_text_field((string) $value);
        }
    }

    public static function sanitize_large_text($value)
    {
        // Callers pass already-unslashed values (the admin handler unslashes $_POST once).
        $value = (string) $value;
        $value = str_replace("\0", '', $value);
        $value = sanitize_textarea_field($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 50000);
        }
        return substr($value, 0, 50000);
    }

    public static function available_triggers()
    {
        return [
            'task_created'              => 'Task created',
            'task_completed'            => 'Task completed',
            'task_stage_changed'        => 'Task stage changed',
            'task_date_changed'         => 'Task date changed',
            'task_priority_changed'     => 'Task priority changed',
            'task_label_changed'        => 'Task label added/removed',
            'comment_created'           => 'Comment created',
            'assignee_added'            => 'Assignee added',
            'task_archived'             => 'Task archived',
            'stage_added'               => 'Stage added',
            'subtask_added'             => 'Subtask added',
            'repeat_task_created'       => 'Repeat task created',
            'custom_field_changed'      => 'Custom field changed',
            'task_attachment_added'     => 'Task attachment added',
            'task_attachment_deleted'   => 'Task attachment deleted',
            'task_dependency_added'     => 'Task dependency added',
            'task_dependency_removed'   => 'Task dependency removed',
            'external_event'            => 'Manual REST event',
        ];
    }

    public static function normalize($value, $depth = 0)
    {
        if ($depth > 5) {
            return is_scalar($value) ? self::truncate_string((string) $value) : '[max_depth]';
        }

        if (is_null($value)) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::truncate_string($value);
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $value = $value->toArray();
            } else {
                $value = get_object_vars($value);
            }
        }

        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $key => $item) {
                $count++;
                if ($count > self::MAX_ARRAY_ITEMS) {
                    $out['__truncated_items'] = true;
                    break;
                }
                if (self::is_sensitive_key((string) $key)) {
                    continue;
                }
                $out[$key] = self::normalize($item, $depth + 1);
            }
            return $out;
        }

        return self::truncate_string((string) $value);
    }

    public static function truncate_string($value)
    {
        $value = (string) $value;
        if (function_exists('mb_strlen') && mb_strlen($value) > self::MAX_STRING_LENGTH) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '...[truncated]';
        }
        if (!function_exists('mb_strlen') && strlen($value) > self::MAX_STRING_LENGTH) {
            return substr($value, 0, self::MAX_STRING_LENGTH) . '...[truncated]';
        }
        return $value;
    }

    public static function limit_chars($value, $limit)
    {
        $value = (string) $value;
        $limit = max(1, (int) $limit);
        if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            return mb_substr($value, 0, $limit) . '...[truncated]';
        }
        if (!function_exists('mb_strlen') && strlen($value) > $limit) {
            return substr($value, 0, $limit) . '...[truncated]';
        }
        return $value;
    }

    public static function to_bool($value, $default = false)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((float) $value) !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'n', 'off', 'disabled', 'null', 'none'], true)) {
                return false;
            }
            return (bool) $default;
        }

        if (is_null($value)) {
            return (bool) $default;
        }

        return !empty($value);
    }

    public static function is_sensitive_key($key)
    {
        $key = strtolower((string) $key);
        foreach (['password', 'passwd', 'pwd', 'api_key', 'apikey', 'authorization', 'auth', 'token', 'secret', 'nonce', 'cookie', 'session'] as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function payload_contains_generated_marker($payload)
    {
        $payload = self::normalize($payload);
        $json = wp_json_encode($payload);
        if (!is_string($json)) {
            return false;
        }
        return strpos($json, self::GENERATED_MARKER) !== false
            || strpos($json, 'FBAIA_GENERATED_COMMENT') !== false
            || strpos($json, 'fluent_boards_ai_automation') !== false;
    }

    public static function extract_task_id($payload)
    {
        $payload = self::normalize($payload);
        foreach (['id', 'task_id', 'object_id'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }
        foreach (['task', 'parent_task', 'subtask', 'comment', 'attachment'] as $container) {
            if (isset($payload[$container]['task_id']) && is_numeric($payload[$container]['task_id'])) {
                return (int) $payload[$container]['task_id'];
            }
            if (($container === 'task' || $container === 'subtask' || $container === 'parent_task') && isset($payload[$container]['id']) && is_numeric($payload[$container]['id'])) {
                return (int) $payload[$container]['id'];
            }
        }
        return 0;
    }

    public static function extract_board_id($payload)
    {
        $payload = self::normalize($payload);
        foreach (['board_id', 'object_id'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }
        foreach (['board', 'task', 'parent_task', 'subtask', 'comment', 'stage', 'attachment'] as $container) {
            if (isset($payload[$container]['board_id']) && is_numeric($payload[$container]['board_id'])) {
                return (int) $payload[$container]['board_id'];
            }
            if ($container === 'board' && isset($payload[$container]['id']) && is_numeric($payload[$container]['id'])) {
                return (int) $payload[$container]['id'];
            }
        }
        return 0;
    }

    public static function extract_task_title($payload)
    {
        $payload = self::normalize($payload);
        foreach (['title', 'name'] as $key) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                return sanitize_text_field((string) $payload[$key]);
            }
        }
        foreach (['task', 'parent_task', 'subtask'] as $container) {
            if (!empty($payload[$container]['title']) && is_scalar($payload[$container]['title'])) {
                return sanitize_text_field((string) $payload[$container]['title']);
            }
        }
        return '';
    }

    public static function board_is_allowed($board_id, $allowlist)
    {
        $allowlist = trim((string) $allowlist);
        if ($allowlist === '') {
            return true;
        }
        $ids = array_filter(array_map('absint', preg_split('/[,\s]+/', $allowlist)));
        return in_array((int) $board_id, $ids, true);
    }

    public static function event_fingerprint($event, array $payload)
    {
        $task_id = self::extract_task_id($payload);
        $board_id = self::extract_board_id($payload);
        $stable = [
            'event' => sanitize_key($event),
            'task_id' => $task_id,
            'board_id' => $board_id,
            'payload' => $payload,
        ];
        return md5(wp_json_encode($stable));
    }

    public static function mask_secret($secret)
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 8) {
            return str_repeat('*', strlen($secret));
        }
        return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
    }

    public static function admin_url()
    {
        return admin_url('options-general.php?page=fluent-boards-ai-automation');
    }
}
