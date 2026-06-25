<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Context_Builder
{
    private $settings;

    public function __construct($settings = null)
    {
        $this->settings = $settings ?: FBAIA_Helpers::get_settings();
    }

    public function build($event, array $payload)
    {
        if (($this->settings['knowledge_enabled'] ?? 'yes') !== 'yes') {
            return [
                'enabled' => false,
                'company_memory' => [],
                'team_context' => [],
                'retrieved_knowledge' => [],
                'instructions' => $this->decision_instructions(),
            ];
        }

        $payload_text = $this->textify(FBAIA_Helpers::normalize($payload));
        $sections = $this->company_sections();
        $entries = $this->retrieve_entries($payload_text);
        $knowledge_posts = $this->retrieve_knowledge_posts($payload_text);
        $feedback = $this->retrieve_feedback($payload_text);
        $team = $this->retrieve_team($payload_text, $payload);
        $board_profile = $this->retrieve_board_profile($payload, $payload_text);
        $playbooks = $this->retrieve_playbooks($payload_text);
        $context = [
            'enabled' => true,
            'company_memory' => $sections,
            'team_context' => $team,
            'board_profile' => $board_profile,
            'matched_automation_playbooks' => $playbooks,
            'retrieved_knowledge' => $entries,
            'knowledge_posts' => $knowledge_posts,
            'prior_feedback_learning' => $feedback,
            'instructions' => $this->decision_instructions(),
        ];

        return $this->trim_context($context);
    }

    private function company_sections()
    {
        $map = [
            'company_profile' => 'Company profile',
            'company_products' => 'Products/services',
            'company_goals' => 'Current goals/strategy',
            'company_customers' => 'Customers/audience',
            'company_constraints' => 'Constraints/rules/processes',
            'company_glossary' => 'Glossary/codenames',
            'decision_rules' => 'Decision rules / if-then guidance',
            'business_metrics' => 'Business metrics and KPI definitions',
            'brand_voice' => 'Brand voice and communication rules',
            'project_playbooks' => 'Project playbooks / operating procedures',
            'operating_principles' => 'Operating principles / decision style',
            'definition_of_done' => 'Company-wide definition of done',
            'escalation_policy' => 'Escalation policy / who must be involved',
            'sla_policy' => 'SLA / response-time policy',
            'raci_matrix' => 'RACI / ownership matrix',
            'review_lenses' => 'AI council review lenses',
            'board_profiles' => 'Board profiles / board-specific operating context',
        ];
        $out = [];
        foreach ($map as $key => $label) {
            $value = trim((string) ($this->settings[$key] ?? ''));
            if ($value !== '') {
                $out[] = [
                    'section' => $label,
                    'content' => FBAIA_Helpers::limit_chars($value, 2500),
                ];
            }
        }
        return $out;
    }


    private function retrieve_board_profile(array $payload, $payload_text)
    {
        $profiles = $this->parse_board_profiles((string) ($this->settings['board_profiles'] ?? ''));
        if (empty($profiles)) {
            return [];
        }
        $board_id = FBAIA_Helpers::extract_board_id($payload);
        $scored = [];
        foreach ($profiles as $profile) {
            $score = 0;
            if ($board_id && !empty($profile['board_id']) && (int) $profile['board_id'] === (int) $board_id) {
                $score += 100;
            }
            $score += $this->keyword_score($payload_text, implode(' ', $profile));
            $profile['match_score'] = $score;
            $scored[] = $profile;
        }
        usort($scored, function ($a, $b) {
            return ($b['match_score'] ?? 0) - ($a['match_score'] ?? 0);
        });
        $out = [];
        foreach (array_slice($scored, 0, 3) as $profile) {
            if (($profile['match_score'] ?? 0) <= 0 && count($out) >= 1) {
                continue;
            }
            $out[] = FBAIA_Helpers::normalize($profile);
        }
        return $out;
    }

    private function parse_board_profiles($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $out = [];
            foreach ($json as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $out[] = [
                    'board_id' => absint($item['board_id'] ?? $item['id'] ?? 0),
                    'name' => sanitize_text_field($item['name'] ?? ''),
                    'goal' => sanitize_textarea_field($item['goal'] ?? ''),
                    'owner_role' => sanitize_text_field($item['owner_role'] ?? ''),
                    'default_reviewer' => sanitize_text_field($item['default_reviewer'] ?? ''),
                    'definition_of_done' => sanitize_textarea_field($item['definition_of_done'] ?? ''),
                    'risk_rules' => sanitize_textarea_field($item['risk_rules'] ?? ''),
                    'notes' => sanitize_textarea_field($item['notes'] ?? ''),
                ];
            }
            return array_values(array_filter($out));
        }

        $entries = preg_split('/\n\s*---+\s*\n/', $raw);
        $out = [];
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $entry);
            $profile = ['board_id' => 0, 'name' => '', 'goal' => '', 'owner_role' => '', 'default_reviewer' => '', 'definition_of_done' => '', 'risk_rules' => '', 'notes' => ''];
            foreach ($lines as $line) {
                if (strpos($line, ':') === false) {
                    $profile['notes'] .= ($profile['notes'] ? "\n" : '') . trim($line);
                    continue;
                }
                list($key, $value) = array_map('trim', explode(':', $line, 2));
                $key = sanitize_key(str_replace([' ', '-'], '_', $key));
                if ($key === 'id') {
                    $key = 'board_id';
                }
                if (array_key_exists($key, $profile)) {
                    $profile[$key] = $key === 'board_id' ? absint($value) : sanitize_textarea_field($value);
                }
            }
            $out[] = $profile;
        }
        return array_values(array_filter($out));
    }


    private function retrieve_playbooks($payload_text)
    {
        if (!class_exists('FBAIA_Automation_Playbooks')) {
            return [];
        }
        $playbooks = new FBAIA_Automation_Playbooks($this->settings);
        $limit = max(0, min(12, (int) ($this->settings['playbook_limit'] ?? 5)));
        return $playbooks->retrieve($payload_text, $limit);
    }

    private function retrieve_entries($payload_text)
    {
        $raw = trim((string) ($this->settings['knowledge_entries'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $chunks = preg_split('/\n\s*---+\s*\n/', $raw);
        if (!is_array($chunks)) {
            $chunks = [$raw];
        }

        $scored = [];
        foreach ($chunks as $index => $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }
            $scored[] = [
                'score' => $this->keyword_score($payload_text, $chunk),
                'index' => $index + 1,
                'content' => FBAIA_Helpers::limit_chars($chunk, 1800),
            ];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['index'] - $b['index'];
            }
            return $b['score'] - $a['score'];
        });

        $out = [];
        $limit = max(0, min(15, (int) ($this->settings['knowledge_entry_limit'] ?? 5)));
        foreach (array_slice($scored, 0, $limit) as $item) {
            if ($item['score'] <= 0 && count($out) >= 2) {
                continue;
            }
            $out[] = [
                'entry' => $item['index'],
                'score' => $item['score'],
                'content' => $item['content'],
            ];
        }
        return $out;
    }

    private function retrieve_knowledge_posts($payload_text)
    {
        if (($this->settings['use_knowledge_posts'] ?? 'yes') !== 'yes') {
            return [];
        }
        if (!class_exists('FBAIA_Knowledge_Library')) {
            return [];
        }
        $limit = max(0, min(12, (int) ($this->settings['knowledge_post_limit'] ?? 5)));
        return FBAIA_Knowledge_Library::retrieve($payload_text, $limit);
    }

    private function retrieve_feedback($payload_text)
    {
        if (!class_exists('FBAIA_Suggestion_Store')) {
            return [];
        }
        $limit = max(0, min(15, (int) ($this->settings['context_feedback_limit'] ?? 5)));
        return FBAIA_Suggestion_Store::feedback_context($payload_text, $limit);
    }

    private function retrieve_team($payload_text, array $payload)
    {
        $members = $this->parse_team_directory((string) ($this->settings['team_directory'] ?? ''));
        if (($this->settings['include_wp_users'] ?? 'no') === 'yes') {
            $members = array_merge($members, $this->wp_user_members());
        }

        if (empty($members)) {
            return [];
        }

        $payload_text .= ' ' . $this->textify($payload);
        $scored = [];
        foreach ($members as $member) {
            $text = implode(' ', array_filter([
                $member['name'] ?? '',
                $member['email'] ?? '',
                $member['role'] ?? '',
                $member['department'] ?? '',
                $member['responsibilities'] ?? '',
                $member['skills'] ?? '',
                $member['boards'] ?? '',
                $member['capacity'] ?? '',
                $member['timezone'] ?? '',
                $member['seniority'] ?? '',
                $member['manager'] ?? '',
                $member['notes'] ?? '',
            ]));
            $score = $this->keyword_score($payload_text, $text);

            // Give a small boost when an email/name is already present in payload.
            if (!empty($member['email']) && stripos($payload_text, $member['email']) !== false) {
                $score += 8;
            }
            if (!empty($member['name']) && stripos($payload_text, $member['name']) !== false) {
                $score += 5;
            }

            $member['match_score'] = $score;
            $scored[] = $member;
        }

        usort($scored, function ($a, $b) {
            if (($a['match_score'] ?? 0) === ($b['match_score'] ?? 0)) {
                return strcmp($a['name'] ?? '', $b['name'] ?? '');
            }
            return ($b['match_score'] ?? 0) - ($a['match_score'] ?? 0);
        });

        $out = [];
        $limit = max(1, min(30, (int) ($this->settings['team_member_limit'] ?? 8)));
        foreach (array_slice($scored, 0, $limit) as $member) {
            $out[] = [
                'name' => sanitize_text_field($member['name'] ?? ''),
                'email' => sanitize_email($member['email'] ?? ''),
                'role' => sanitize_text_field($member['role'] ?? ''),
                'department' => sanitize_text_field($member['department'] ?? ''),
                'responsibilities' => sanitize_textarea_field($member['responsibilities'] ?? ''),
                'skills' => sanitize_text_field($member['skills'] ?? ''),
                'boards' => sanitize_text_field($member['boards'] ?? ''),
                'capacity' => sanitize_text_field($member['capacity'] ?? ''),
                'timezone' => sanitize_text_field($member['timezone'] ?? ''),
                'seniority' => sanitize_text_field($member['seniority'] ?? ''),
                'manager' => sanitize_text_field($member['manager'] ?? ''),
                'notes' => sanitize_textarea_field($member['notes'] ?? ''),
                'match_score' => (int) ($member['match_score'] ?? 0),
            ];
        }
        return $out;
    }

    public function parse_team_directory($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            $out = [];
            foreach ($json as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $out[] = $this->normalize_member($item);
            }
            return array_values(array_filter($out));
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $first = strtolower((string) ($parts[0] ?? ''));
            $second = strtolower((string) ($parts[1] ?? ''));
            if (in_array($first, ['name', 'member', 'نام'], true) && in_array($second, ['email', 'ایمیل'], true)) {
                continue;
            }
            $out[] = $this->normalize_member([
                'name' => $parts[0] ?? '',
                'email' => $parts[1] ?? '',
                'role' => $parts[2] ?? '',
                'department' => $parts[3] ?? '',
                'responsibilities' => $parts[4] ?? '',
                'skills' => $parts[5] ?? '',
                'boards' => $parts[6] ?? '',
                'capacity' => $parts[7] ?? '',
                'timezone' => $parts[8] ?? '',
                'seniority' => $parts[9] ?? '',
                'manager' => $parts[10] ?? '',
                'notes' => $parts[11] ?? '',
            ]);
        }
        return array_values(array_filter($out));
    }

    private function normalize_member(array $item)
    {
        $name = sanitize_text_field($item['name'] ?? '');
        $email = sanitize_email($item['email'] ?? '');
        $role = sanitize_text_field($item['role'] ?? '');
        $department = sanitize_text_field($item['department'] ?? '');
        $responsibilities = sanitize_textarea_field($item['responsibilities'] ?? '');
        $skills = sanitize_text_field($item['skills'] ?? '');
        $boards = sanitize_text_field($item['boards'] ?? '');
        $capacity = sanitize_text_field($item['capacity'] ?? '');
        $timezone = sanitize_text_field($item['timezone'] ?? '');
        $seniority = sanitize_text_field($item['seniority'] ?? '');
        $manager = sanitize_text_field($item['manager'] ?? '');
        $notes = sanitize_textarea_field($item['notes'] ?? '');

        if ($name === '' && $email === '' && $role === '') {
            return [];
        }

        return compact('name', 'email', 'role', 'department', 'responsibilities', 'skills', 'boards', 'capacity', 'timezone', 'seniority', 'manager', 'notes');
    }

    private function wp_user_members()
    {
        $users = get_users([
            'number' => 50,
            'orderby' => 'display_name',
            'fields' => 'all',
        ]);
        $out = [];
        foreach ($users as $user) {
            $roles = is_array($user->roles ?? null) ? implode(', ', $user->roles) : '';
            $out[] = [
                'name' => $user->display_name ?? '',
                'email' => $user->user_email ?? '',
                'role' => $roles,
                'department' => 'WordPress user',
                'responsibilities' => '',
                'skills' => '',
                'boards' => '',
                'capacity' => '',
                'timezone' => '',
                'seniority' => '',
                'manager' => '',
                'notes' => '',
            ];
        }
        return $out;
    }

    private function decision_instructions()
    {
        return [
            'depth' => sanitize_key($this->settings['recommendation_depth'] ?? 'strategic'),
            'style' => sanitize_key($this->settings['recommendation_style'] ?? 'direct'),
            'confidence_threshold' => (float) ($this->settings['confidence_threshold'] ?? 0.55),
            'ask_clarifying_questions' => (($this->settings['ask_clarifying_questions'] ?? 'yes') === 'yes'),
            'suggest_owner' => (($this->settings['suggest_owner'] ?? 'yes') === 'yes'),
            'suggest_acceptance_criteria' => (($this->settings['suggest_acceptance_criteria'] ?? 'yes') === 'yes'),
            'suggest_due_date' => (($this->settings['suggest_due_date'] ?? 'yes') === 'yes'),
            'suggest_reviewer' => (($this->settings['suggest_reviewer'] ?? 'yes') === 'yes'),
            'output_language' => $this->output_language(),
            'suppress_low_value_comments' => (($this->settings['suppress_low_value_comments'] ?? 'yes') === 'yes'),
            'minimum_comment_confidence' => (float) ($this->settings['minimum_comment_confidence'] ?? 0.35),
            'task_enrichment_enabled' => (($this->settings['enable_task_enrichment'] ?? 'yes') === 'yes'),
            'action_execution_policy' => sanitize_key($this->settings['action_execution_policy'] ?? 'review_first'),
            'feedback_learning_enabled' => (($this->settings['feedback_learning_enabled'] ?? 'yes') === 'yes'),
            'knowledge_posts_enabled' => (($this->settings['use_knowledge_posts'] ?? 'yes') === 'yes'),
            'memory_quality_guard' => (($this->settings['memory_quality_guard'] ?? 'yes') === 'yes'),
            'playbooks_enabled' => (($this->settings['playbooks_enabled'] ?? 'yes') === 'yes'),
            'playbook_limit' => (int) ($this->settings['playbook_limit'] ?? 5),
            'ai_council_enabled' => (($this->settings['ai_council_enabled'] ?? 'yes') === 'yes'),
            'task_quality_score_enabled' => (($this->settings['task_quality_score_enabled'] ?? 'yes') === 'yes'),
            'raci_enabled' => (($this->settings['raci_enabled'] ?? 'yes') === 'yes'),
            'meeting_recommendations_enabled' => (($this->settings['meeting_recommendations_enabled'] ?? 'yes') === 'yes'),
            'cost_guard_enabled' => (($this->settings['cost_guard_enabled'] ?? 'yes') === 'yes'),
            'team_member_limit' => (int) ($this->settings['team_member_limit'] ?? 8),
            'knowledge_entry_limit' => (int) ($this->settings['knowledge_entry_limit'] ?? 5),
            'today' => current_time('Y-m-d'),
            'site_timezone' => wp_timezone_string(),
        ];
    }


    private function output_language()
    {
        $language = sanitize_key($this->settings['output_language'] ?? 'site_locale');
        if ($language === 'english') {
            return 'English';
        }
        if ($language === 'spanish') {
            return 'Spanish';
        }
        if ($language === 'persian') {
            return 'Persian / Farsi';
        }
        $locale = function_exists('get_locale') ? get_locale() : 'site_locale';
        return 'Site locale: ' . $locale;
    }

    private function trim_context(array $context)
    {
        $limit = max(2000, min(50000, (int) ($this->settings['max_context_chars'] ?? 12000)));
        $json = wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) <= $limit) {
            return $context;
        }

        // Trim least critical blocks first.
        foreach (['prior_feedback_learning', 'knowledge_posts', 'retrieved_knowledge', 'team_context', 'matched_automation_playbooks', 'board_profile', 'company_memory'] as $key) {
            while (!empty($context[$key]) && strlen((string) wp_json_encode($context, JSON_UNESCAPED_UNICODE)) > $limit) {
                array_pop($context[$key]);
            }
        }
        return $context;
    }

    private function keyword_score($needle_text, $haystack_text)
    {
        $needles = $this->keywords($needle_text);
        $haystack = ' ' . strtolower($haystack_text) . ' ';
        $score = 0;
        foreach ($needles as $word => $weight) {
            if (strpos($haystack, strtolower($word)) !== false) {
                $score += $weight;
            }
        }
        return $score;
    }

    private function keywords($text)
    {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $tokens = preg_split('/[^\p{L}\p{N}_@.\-]+/u', $text);
        $stop = ['the','and','for','with','from','this','that','have','has','will','task','board','comment','created','updated','changed','user','your','you','در','از','به','برای','که','این','آن','های','است','شد','شود','یک','یا','و','را','با'];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || strlen($token) < 3 || in_array($token, $stop, true)) {
                continue;
            }
            $out[$token] = isset($out[$token]) ? $out[$token] + 1 : 1;
        }
        arsort($out);
        return array_slice($out, 0, 80, true);
    }

    private function textify($value)
    {
        if (is_scalar($value) || is_null($value)) {
            return (string) $value;
        }
        $json = wp_json_encode(FBAIA_Helpers::normalize($value), JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }
}
