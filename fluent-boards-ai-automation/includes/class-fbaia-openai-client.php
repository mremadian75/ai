<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_OpenAI_Client
{
    private $settings;

    public function __construct($settings = null)
    {
        $this->settings = $settings ?: FBAIA_Helpers::get_settings();
    }

    public function complete($event, array $payload)
    {
        if (class_exists('FBAIA_Usage_Tracker')) {
            $budget_check = FBAIA_Usage_Tracker::can_call($this->settings);
            if (is_wp_error($budget_check)) {
                return $budget_check;
            }
        }

        $api_key = trim((string) ($this->settings['api_key'] ?? ''));
        if ($api_key === '') {
            return new WP_Error('missing_api_key', __('OpenAI API key is missing.', 'fluent-boards-ai-automation'));
        }

        if (!$this->rate_limit_ok()) {
            return new WP_Error('fbaia_rate_limited', __('AI rate limit reached. Increase the limit or wait for the next hour.', 'fluent-boards-ai-automation'));
        }

        $base = rtrim((string) ($this->settings['api_base'] ?? 'https://api.openai.com/v1'), '/');
        $url = esc_url_raw($base . '/chat/completions');

        $context_builder = new FBAIA_Context_Builder($this->settings);
        $company_context = $context_builder->build($event, $payload);
        $task_context = $this->build_task_context($payload);

        $body = [
            'model' => sanitize_text_field($this->settings['model'] ?? 'gpt-4o-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => (string) ($this->settings['system_prompt'] ?? FBAIA_Helpers::default_system_prompt()),
                ],
                [
                    'role' => 'user',
                    'content' => $this->build_user_prompt($event, $payload, $company_context, $task_context),
                ],
            ],
            'temperature' => (float) ($this->settings['temperature'] ?? 0.15),
            'max_tokens' => (int) ($this->settings['max_tokens'] ?? 1800),
        ];

        if (($this->settings['force_json_response'] ?? 'yes') === 'yes') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $encoded = wp_json_encode($body);
        if (!$encoded) {
            return new WP_Error('openai_body_encode_failed', __('Could not encode OpenAI request body.', 'fluent-boards-ai-automation'));
        }
        $estimated_tokens = class_exists('FBAIA_Usage_Tracker') ? FBAIA_Usage_Tracker::estimate_tokens_from_text($encoded) : 0;

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 60,
            'body'    => $encoded,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? ('OpenAI request failed with HTTP ' . $code)) : ('OpenAI request failed with HTTP ' . $code);
            return new WP_Error('openai_http_error', sanitize_text_field($message), ['status' => $code]);
        }

        if (!is_array($decoded)) {
            return new WP_Error('openai_invalid_json', __('OpenAI returned invalid JSON at transport level.', 'fluent-boards-ai-automation'));
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            return new WP_Error('empty_ai_response', __('OpenAI returned an empty response.', 'fluent-boards-ai-automation'));
        }

        $json = $this->parse_model_json((string) $content);
        if (!is_array($json)) {
            $json = $this->fallback_json($content);
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $model = $decoded['model'] ?? ($this->settings['model'] ?? '');
        if (class_exists('FBAIA_Usage_Tracker')) {
            FBAIA_Usage_Tracker::record($usage, [
                'event' => $event,
                'task_id' => FBAIA_Helpers::extract_task_id($payload),
                'model' => $model,
                'estimated_tokens' => $estimated_tokens,
            ]);
        }

        return [
            'raw' => FBAIA_Helpers::truncate_string((string) $content),
            'parsed' => $this->sanitize_ai_result($json),
            'usage' => $usage,
            'model' => $model,
            'context_summary' => [
                'knowledge_enabled' => !empty($company_context['enabled']),
                'company_sections' => isset($company_context['company_memory']) && is_array($company_context['company_memory']) ? count($company_context['company_memory']) : 0,
                'knowledge_entries' => isset($company_context['retrieved_knowledge']) && is_array($company_context['retrieved_knowledge']) ? count($company_context['retrieved_knowledge']) : 0,
                'knowledge_posts' => isset($company_context['knowledge_posts']) && is_array($company_context['knowledge_posts']) ? count($company_context['knowledge_posts']) : 0,
                'feedback_examples' => isset($company_context['prior_feedback_learning']) && is_array($company_context['prior_feedback_learning']) ? count($company_context['prior_feedback_learning']) : 0,
                'team_members' => isset($company_context['team_context']) && is_array($company_context['team_context']) ? count($company_context['team_context']) : 0,
                'board_profiles' => isset($company_context['board_profile']) && is_array($company_context['board_profile']) ? count($company_context['board_profile']) : 0,
                'playbooks' => isset($company_context['matched_automation_playbooks']) && is_array($company_context['matched_automation_playbooks']) ? count($company_context['matched_automation_playbooks']) : 0,
                'task_enriched' => !empty($task_context['available']),
                'task_comments' => isset($task_context['comments']) && is_array($task_context['comments']) ? count($task_context['comments']) : 0,
                'task_subtasks' => isset($task_context['subtasks']) && is_array($task_context['subtasks']) ? count($task_context['subtasks']) : 0,
            ],
        ];
    }

    public function test()
    {
        return $this->complete('connection_test', [
            'task' => [
                'id' => 0,
                'title' => 'Test connection: improve onboarding task',
                'description' => 'Use company memory, decision rules, team roles, and realistic acceptance criteria. Return a practical analysis, not generic advice.',
            ],
        ]);
    }

    private function build_task_context(array $payload)
    {
        if (($this->settings['enable_task_enrichment'] ?? 'yes') !== 'yes') {
            return ['available' => false, 'reason' => 'disabled'];
        }

        $task_id = FBAIA_Helpers::extract_task_id($payload);
        if (!$task_id) {
            return ['available' => false, 'reason' => 'no_task_id'];
        }

        $adapter = new FBAIA_FluentBoards_Adapter();
        $comment_limit = max(0, min(30, (int) ($this->settings['context_comment_limit'] ?? 8)));
        $subtask_limit = max(0, min(50, (int) ($this->settings['context_subtask_limit'] ?? 15)));
        $context = $adapter->get_task_context($task_id, $comment_limit, $subtask_limit);
        return is_array($context) ? $context : ['available' => false, 'reason' => 'not_available'];
    }

    private function build_user_prompt($event, array $payload, array $company_context, array $task_context)
    {
        $safe_payload = FBAIA_Helpers::normalize($payload);
        $payload_json = wp_json_encode($safe_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $context_json = wp_json_encode($company_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $task_context_json = wp_json_encode($task_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "Event: " . sanitize_key($event) . "\n\nCompany Memory, Board Profiles, Team Context, and Decision Rules:\n" . $context_json . "\n\nCurrent Task Snapshot / Recent Thread Context:\n" . $task_context_json . "\n\nFluent Boards Event Payload:\n" . $payload_json . "\n\nDecision instructions:\n- Use the task snapshot as the source of truth when it conflicts with the raw event payload.\n- Use Company Memory only as supporting evidence; do not claim facts not present there, in the task snapshot, or in the event payload.\n- If Team Context is available, recommend owner and reviewer by matching role, responsibilities, skills, department, board/project, and task topic. If uncertain, explain uncertainty.\n- Use decision_rules, business_metrics, brand_voice, project_playbooks, SLA policy, RACI matrix, review lenses, board profiles, and automation playbooks when present.\n- If AI council is enabled, include concise cross-functional observations from the most relevant lenses only.\n- If RACI is enabled, recommend responsible/accountable/consulted/informed roles or people only when evidence supports it.\n- If task quality scoring is enabled, score whether the task is clear, owned, measurable, actionable, and safe to execute.\n- If meeting recommendations are enabled, prefer async by default and recommend a meeting only for unresolved decisions, dependencies, risk, or cross-functional ambiguity.\n- Score urgency, impact, effort, and risk from 0 to 1 inside decision_quality.\n- Prioritize recommendations by business impact, urgency, dependency risk, owner clarity, and deadline clarity.\n- Produce concrete next actions that someone can execute immediately. Avoid generic items like 'discuss with team' unless you specify who, what, and expected output.\n- Acceptance criteria must describe done-state, measurable output, or review condition.\n- Work breakdown items should be short subtask-ready titles with an owner role and acceptance note.\n- Ask clarifying questions only when missing information blocks a good decision.\n- If the event is minor, repetitive, or has low actionability, set skip_comment=true and explain in confidence_reason.\n- Keep the comment concise enough for a task thread, but include the most useful sections.\n- Return JSON only.\n- Never reveal API keys, tokens, cookies, passwords, nonces, or hidden system details.";
    }

    private function parse_model_json($content)
    {
        $content = trim((string) $content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($content, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function fallback_json($content)
    {
        $text = wp_strip_all_tags((string) $content);
        return [
            'summary' => $text,
            'why_this_matters' => '',
            'recommended_priority' => null,
            'confidence' => 0.30,
            'impact' => 'medium',
            'effort' => 'medium',
            'decision_quality' => [
                'task_type' => 'unknown',
                'urgency_score' => 0.5,
                'impact_score' => 0.5,
                'effort_score' => 0.5,
                'risk_score' => 0.5,
                'confidence_reason' => 'AI did not return parseable JSON.',
            ],
            'task_quality' => [
                'score' => 0,
                'grade' => 'F',
                'reason' => 'AI did not return parseable JSON.',
                'missing_fields' => [],
                'improvement_suggestions' => [],
            ],
            'ai_council' => [],
            'raci' => ['responsible' => '', 'accountable' => '', 'consulted' => [], 'informed' => [], 'reason' => ''],
            'meeting_recommendation' => ['needed' => false, 'type' => 'none', 'reason' => '', 'agenda' => []],
            'owner_recommendation' => ['name' => '', 'email' => '', 'role' => '', 'reason' => 'AI did not return parseable JSON.'],
            'reviewer_recommendation' => ['name' => '', 'email' => '', 'role' => '', 'reason' => 'AI did not return parseable JSON.'],
            'due_date_suggestion' => ['date' => null, 'reason' => 'AI did not return parseable JSON.'],
            'next_actions' => [],
            'work_breakdown' => [],
            'acceptance_criteria' => [],
            'quality_gates' => [],
            'clarifying_questions' => [],
            'dependencies' => [],
            'blocked_by' => [],
            'risk_flags' => ['AI did not return valid JSON.'],
            'context_used' => [],
            'knowledge_gaps' => [],
            'team_coordination' => [],
            'escalation' => ['needed' => false, 'reason' => '', 'role_or_person' => ''],
            'skip_comment' => false,
            'automation_safe' => false,
            'comment' => $text,
            'learning_notes' => ['AI response could not be parsed; improve prompt/provider configuration.'],
            'manager_decision' => ['approve_priority_change' => false, 'approve_subtasks' => false, 'reason' => 'Invalid JSON response.'],
            'internal_actions' => [
                'create_subtasks' => [],
                'notify_admin' => false,
                'priority_reason' => '',
            ],
        ];
    }

    private function sanitize_ai_result(array $result)
    {
        $priority = $result['recommended_priority'] ?? null;
        $allowed_priority = ['low', 'medium', 'high', 'urgent', null, 'null', ''];
        if (!in_array($priority, $allowed_priority, true)) {
            $priority = null;
        }

        $impact = sanitize_key($result['impact'] ?? 'medium');
        if (!in_array($impact, ['low', 'medium', 'high'], true)) {
            $impact = 'medium';
        }
        $effort = sanitize_key($result['effort'] ?? 'medium');
        if (!in_array($effort, ['low', 'medium', 'high'], true)) {
            $effort = 'medium';
        }

        $owner = is_array($result['owner_recommendation'] ?? null) ? $result['owner_recommendation'] : [];
        $reviewer = is_array($result['reviewer_recommendation'] ?? null) ? $result['reviewer_recommendation'] : [];
        $due = is_array($result['due_date_suggestion'] ?? null) ? $result['due_date_suggestion'] : [];
        $internal = is_array($result['internal_actions'] ?? null) ? $result['internal_actions'] : [];
        $manager = is_array($result['manager_decision'] ?? null) ? $result['manager_decision'] : [];
        $quality = is_array($result['decision_quality'] ?? null) ? $result['decision_quality'] : [];
        $task_quality = is_array($result['task_quality'] ?? null) ? $result['task_quality'] : [];
        $ai_council = is_array($result['ai_council'] ?? null) ? $result['ai_council'] : [];
        $raci = is_array($result['raci'] ?? null) ? $result['raci'] : [];
        $meeting = is_array($result['meeting_recommendation'] ?? null) ? $result['meeting_recommendation'] : [];
        $brief = is_array($result['decision_brief'] ?? null) ? $result['decision_brief'] : [];
        $policy_controls = is_array($result['policy_controls'] ?? null) ? $result['policy_controls'] : [];
        $confidence = $this->score($result['confidence'] ?? 0.5);

        return [
            'summary' => sanitize_textarea_field($result['summary'] ?? ''),
            'why_this_matters' => sanitize_textarea_field($result['why_this_matters'] ?? ''),
            'recommended_priority' => $priority === 'null' || $priority === '' ? null : $priority,
            'confidence' => $confidence,
            'impact' => $impact,
            'effort' => $effort,
            'decision_quality' => [
                'task_type' => sanitize_text_field($quality['task_type'] ?? ''),
                'urgency_score' => $this->score($quality['urgency_score'] ?? 0),
                'impact_score' => $this->score($quality['impact_score'] ?? 0),
                'effort_score' => $this->score($quality['effort_score'] ?? 0),
                'risk_score' => $this->score($quality['risk_score'] ?? 0),
                'confidence_reason' => sanitize_textarea_field($quality['confidence_reason'] ?? ''),
            ],
            'task_quality' => $this->sanitize_task_quality($task_quality),
            'ai_council' => $this->sanitize_ai_council($ai_council),
            'raci' => $this->sanitize_raci($raci),
            'meeting_recommendation' => $this->sanitize_meeting_recommendation($meeting),
            'owner_recommendation' => $this->sanitize_person($owner),
            'reviewer_recommendation' => $this->sanitize_person($reviewer),
            'due_date_suggestion' => [
                'date' => $this->sanitize_date_or_null($due['date'] ?? null),
                'reason' => sanitize_textarea_field($due['reason'] ?? ''),
            ],
            'next_actions' => $this->sanitize_string_list($result['next_actions'] ?? []),
            'work_breakdown' => $this->sanitize_work_breakdown($result['work_breakdown'] ?? []),
            'acceptance_criteria' => $this->sanitize_string_list($result['acceptance_criteria'] ?? []),
            'quality_gates' => $this->sanitize_string_list($result['quality_gates'] ?? []),
            'clarifying_questions' => $this->sanitize_string_list($result['clarifying_questions'] ?? []),
            'dependencies' => $this->sanitize_string_list($result['dependencies'] ?? []),
            'blocked_by' => $this->sanitize_string_list($result['blocked_by'] ?? []),
            'risk_flags' => $this->sanitize_string_list($result['risk_flags'] ?? []),
            'context_used' => $this->sanitize_string_list($result['context_used'] ?? []),
            'knowledge_gaps' => $this->sanitize_string_list($result['knowledge_gaps'] ?? []),
            'team_coordination' => $this->sanitize_string_list($result['team_coordination'] ?? []),
            'escalation' => $this->sanitize_escalation(is_array($result['escalation'] ?? null) ? $result['escalation'] : []),
            'skip_comment' => FBAIA_Helpers::to_bool($result['skip_comment'] ?? false),
            'automation_safe' => FBAIA_Helpers::to_bool($result['automation_safe'] ?? false),
            'comment' => wp_kses_post($result['comment'] ?? ($result['summary'] ?? '')),
            'learning_notes' => $this->sanitize_string_list($result['learning_notes'] ?? []),
            'decision_brief' => [
                'headline' => sanitize_text_field($brief['headline'] ?? ''),
                'recommended_manager_action' => sanitize_textarea_field($brief['recommended_manager_action'] ?? ''),
                'do_next' => sanitize_textarea_field($brief['do_next'] ?? ''),
            ],
            'policy_controls' => FBAIA_Helpers::normalize($policy_controls),
            'manager_decision' => [
                'approve_priority_change' => FBAIA_Helpers::to_bool($manager['approve_priority_change'] ?? false),
                'approve_subtasks' => FBAIA_Helpers::to_bool($manager['approve_subtasks'] ?? false),
                'reason' => sanitize_textarea_field($manager['reason'] ?? ''),
            ],
            'internal_actions' => [
                'create_subtasks' => $this->sanitize_string_list($internal['create_subtasks'] ?? []),
                'notify_admin' => FBAIA_Helpers::to_bool($internal['notify_admin'] ?? false),
                'priority_reason' => sanitize_text_field($internal['priority_reason'] ?? ''),
            ],
        ];
    }

    private function sanitize_task_quality(array $item)
    {
        $grade = strtoupper(sanitize_text_field($item['grade'] ?? ''));
        if (!in_array($grade, ['A', 'B', 'C', 'D', 'F'], true)) {
            $grade = '';
        }
        return [
            'score' => $this->score($item['score'] ?? 0),
            'grade' => $grade,
            'reason' => sanitize_textarea_field($item['reason'] ?? ''),
            'missing_fields' => $this->sanitize_string_list($item['missing_fields'] ?? []),
            'improvement_suggestions' => $this->sanitize_string_list($item['improvement_suggestions'] ?? []),
        ];
    }

    private function sanitize_ai_council(array $items)
    {
        $out = [];
        $allowed_lenses = ['product', 'marketing', 'operations', 'risk', 'customer', 'finance', 'technical'];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lens_raw = sanitize_text_field($item['lens'] ?? '');
            $lens_key = sanitize_key($lens_raw);
            if ($lens_key && !in_array($lens_key, $allowed_lenses, true)) {
                $lens_raw = ucfirst($lens_key);
            }
            $risk = sanitize_key($item['risk'] ?? '');
            if (!in_array($risk, ['low', 'medium', 'high'], true)) {
                $risk = 'medium';
            }
            $observation = sanitize_textarea_field($item['observation'] ?? '');
            $recommendation = sanitize_textarea_field($item['recommendation'] ?? '');
            if ($lens_raw === '' && $observation === '' && $recommendation === '') {
                continue;
            }
            $out[] = [
                'lens' => $lens_raw,
                'observation' => $observation,
                'recommendation' => $recommendation,
                'risk' => $risk,
            ];
        }
        return array_slice($out, 0, 8);
    }

    private function sanitize_raci(array $item)
    {
        return [
            'responsible' => sanitize_text_field($item['responsible'] ?? ''),
            'accountable' => sanitize_text_field($item['accountable'] ?? ''),
            'consulted' => $this->sanitize_string_list($item['consulted'] ?? []),
            'informed' => $this->sanitize_string_list($item['informed'] ?? []),
            'reason' => sanitize_textarea_field($item['reason'] ?? ''),
        ];
    }

    private function sanitize_meeting_recommendation(array $item)
    {
        $type = sanitize_key($item['type'] ?? 'none');
        if (!in_array($type, ['async_update', 'quick_sync', 'decision_meeting', 'none'], true)) {
            $type = 'none';
        }
        return [
            'needed' => FBAIA_Helpers::to_bool($item['needed'] ?? false),
            'type' => $type,
            'reason' => sanitize_textarea_field($item['reason'] ?? ''),
            'agenda' => $this->sanitize_string_list($item['agenda'] ?? []),
        ];
    }

    private function sanitize_escalation(array $item)
    {
        return [
            'needed' => FBAIA_Helpers::to_bool($item['needed'] ?? false),
            'reason' => sanitize_textarea_field($item['reason'] ?? ''),
            'role_or_person' => sanitize_text_field($item['role_or_person'] ?? ''),
        ];
    }

    private function sanitize_person(array $person)
    {
        return [
            'name' => sanitize_text_field($person['name'] ?? ''),
            'email' => sanitize_email($person['email'] ?? ''),
            'role' => sanitize_text_field($person['role'] ?? ''),
            'reason' => sanitize_textarea_field($person['reason'] ?? ''),
        ];
    }

    private function score($value)
    {
        return max(0, min(1, (float) $value));
    }

    private function sanitize_work_breakdown($items)
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $title = sanitize_text_field((string) $item);
                if ($title !== '') {
                    $out[] = ['title' => $title, 'owner_role' => '', 'acceptance' => ''];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $title = sanitize_text_field($item['title'] ?? $item['task'] ?? '');
            if ($title === '') {
                continue;
            }
            $out[] = [
                'title' => $title,
                'owner_role' => sanitize_text_field($item['owner_role'] ?? ''),
                'acceptance' => sanitize_textarea_field($item['acceptance'] ?? $item['acceptance_criteria'] ?? ''),
            ];
        }
        return array_slice($out, 0, 20);
    }

    private function sanitize_date_or_null($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }

    private function sanitize_string_list($items)
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_scalar($item)) {
                $clean = sanitize_text_field((string) $item);
                if ($clean !== '') {
                    $out[] = $clean;
                }
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 20);
    }

    private function rate_limit_ok()
    {
        $limit = max(1, (int) ($this->settings['rate_limit_per_hour'] ?? 40));
        $key = 'fbaia_ai_rate_' . md5(site_url());
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }
}
