<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Automation_Playbooks
{
    private $settings;

    public function __construct($settings = null)
    {
        $this->settings = $settings ?: FBAIA_Helpers::get_settings();
    }

    public function retrieve($query_text, $limit = null)
    {
        if (($this->settings['playbooks_enabled'] ?? 'yes') !== 'yes') {
            return [];
        }
        $items = $this->parse((string) ($this->settings['automation_playbooks'] ?? ''));
        if (empty($items)) {
            return [];
        }
        $limit = is_null($limit) ? (int) ($this->settings['playbook_limit'] ?? 5) : (int) $limit;
        $limit = max(0, min(12, $limit));
        if (!$limit) {
            return [];
        }

        $scored = [];
        foreach ($items as $item) {
            $text = implode(' ', array_filter([
                $item['name'] ?? '',
                $item['match'] ?? '',
                $item['instructions'] ?? '',
                $item['risk_rules'] ?? '',
                $item['required_context'] ?? '',
                $item['reviewer'] ?? '',
                $item['tags'] ?? '',
            ]));
            $score = $this->keyword_score($query_text, $text);
            $item['match_score'] = $score;
            $scored[] = $item;
        }
        usort($scored, function ($a, $b) {
            return ($b['match_score'] ?? 0) - ($a['match_score'] ?? 0);
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            if (($item['match_score'] ?? 0) <= 0 && count($out) >= 2) {
                continue;
            }
            $out[] = FBAIA_Helpers::normalize($item);
        }
        return $out;
    }

    public function enforce($event, array $payload, array $ai_payload)
    {
        if (($this->settings['playbooks_enabled'] ?? 'yes') !== 'yes') {
            return $ai_payload;
        }
        $query_text = sanitize_key($event) . ' ' . wp_json_encode(FBAIA_Helpers::normalize($payload), JSON_UNESCAPED_UNICODE) . ' ' . wp_json_encode(FBAIA_Helpers::normalize($ai_payload), JSON_UNESCAPED_UNICODE);
        $matched = $this->retrieve($query_text, 5);
        if (empty($matched)) {
            return $ai_payload;
        }

        if (empty($ai_payload['policy_controls']) || !is_array($ai_payload['policy_controls'])) {
            $ai_payload['policy_controls'] = [];
        }
        $ai_payload['policy_controls']['matched_playbooks'] = array_map(function ($item) {
            return [
                'name' => sanitize_text_field($item['name'] ?? ''),
                'score' => (int) ($item['match_score'] ?? 0),
                'block_auto_actions' => FBAIA_Helpers::to_bool($item['block_auto_actions'] ?? false),
                'min_confidence' => isset($item['min_confidence']) ? (float) $item['min_confidence'] : null,
            ];
        }, $matched);

        $confidence = isset($ai_payload['confidence']) ? (float) $ai_payload['confidence'] : 0.5;
        foreach ($matched as $rule) {
            $name = sanitize_text_field($rule['name'] ?? 'Unnamed playbook');
            if (FBAIA_Helpers::to_bool($rule['block_auto_actions'] ?? false)) {
                $ai_payload['automation_safe'] = false;
                if (!isset($ai_payload['risk_flags']) || !is_array($ai_payload['risk_flags'])) {
                    $ai_payload['risk_flags'] = [];
                }
                $ai_payload['risk_flags'][] = 'Auto-actions blocked by playbook: ' . $name;
                $this->manager_hold($ai_payload, 'Playbook requires human review: ' . $name);
            }
            $min = isset($rule['min_confidence']) ? (float) $rule['min_confidence'] : 0;
            if ($min > 0 && $confidence < $min) {
                $ai_payload['automation_safe'] = false;
                $this->manager_hold($ai_payload, 'Playbook confidence threshold not met: ' . $name . ' requires ' . $min);
            }
            if (!empty($rule['reviewer']) && empty($ai_payload['reviewer_recommendation']['name'])) {
                $ai_payload['reviewer_recommendation'] = [
                    'name' => '',
                    'email' => '',
                    'role' => sanitize_text_field($rule['reviewer']),
                    'reason' => 'Recommended by matched playbook: ' . $name,
                ];
            }
        }
        return FBAIA_Helpers::normalize($ai_payload);
    }

    public function parse($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $out = [];
            foreach ($json as $item) {
                if (is_array($item)) {
                    $out[] = $this->normalize_rule($item);
                }
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
            $rule = [
                'name' => '',
                'match' => '',
                'instructions' => '',
                'risk_rules' => '',
                'required_context' => '',
                'reviewer' => '',
                'tags' => '',
                'block_auto_actions' => false,
                'min_confidence' => 0,
            ];
            $lines = preg_split('/\r\n|\r|\n/', $entry);
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (strpos($line, ':') === false) {
                    $rule['instructions'] .= ($rule['instructions'] ? "\n" : '') . $line;
                    continue;
                }
                list($key, $value) = array_map('trim', explode(':', $line, 2));
                $key = sanitize_key(str_replace([' ', '-'], '_', $key));
                if ($key === 'block' || $key === 'block_auto') {
                    $key = 'block_auto_actions';
                }
                if ($key === 'risk' || $key === 'risks') {
                    $key = 'risk_rules';
                }
                if ($key === 'required') {
                    $key = 'required_context';
                }
                if (array_key_exists($key, $rule)) {
                    if ($key === 'block_auto_actions') {
                        $rule[$key] = in_array(strtolower($value), ['1','yes','true','on','block'], true);
                    } elseif ($key === 'min_confidence') {
                        $rule[$key] = max(0, min(1, (float) $value));
                    } else {
                        $rule[$key] = sanitize_textarea_field($value);
                    }
                } else {
                    $rule['instructions'] .= ($rule['instructions'] ? "\n" : '') . $line;
                }
            }
            $out[] = $this->normalize_rule($rule);
        }
        return array_values(array_filter($out));
    }

    private function normalize_rule(array $item)
    {
        $rule = [
            'name' => sanitize_text_field($item['name'] ?? $item['title'] ?? ''),
            'match' => sanitize_textarea_field($item['match'] ?? $item['applies_to'] ?? ''),
            'instructions' => sanitize_textarea_field($item['instructions'] ?? $item['guidance'] ?? ''),
            'risk_rules' => sanitize_textarea_field($item['risk_rules'] ?? $item['risk'] ?? ''),
            'required_context' => sanitize_textarea_field($item['required_context'] ?? $item['required'] ?? ''),
            'reviewer' => sanitize_text_field($item['reviewer'] ?? ''),
            'tags' => sanitize_text_field(is_array($item['tags'] ?? null) ? implode(', ', $item['tags']) : ($item['tags'] ?? '')),
            'block_auto_actions' => FBAIA_Helpers::to_bool($item['block_auto_actions'] ?? ($item['block_auto'] ?? ($item['block'] ?? false))),
            'min_confidence' => max(0, min(1, (float) ($item['min_confidence'] ?? 0))),
        ];
        if ($rule['name'] === '' && $rule['match'] === '' && $rule['instructions'] === '') {
            return [];
        }
        return $rule;
    }

    private function manager_hold(array &$ai_payload, $reason)
    {
        if (empty($ai_payload['manager_decision']) || !is_array($ai_payload['manager_decision'])) {
            $ai_payload['manager_decision'] = [];
        }
        $ai_payload['manager_decision']['approve_priority_change'] = false;
        $ai_payload['manager_decision']['approve_subtasks'] = false;
        $existing = trim((string) ($ai_payload['manager_decision']['reason'] ?? ''));
        $ai_payload['manager_decision']['reason'] = trim($existing . ($existing ? ' ' : '') . $reason);
    }

    private function keyword_score($needle_text, $haystack_text)
    {
        $needle_text = strtolower(wp_strip_all_tags((string) $needle_text));
        $haystack = ' ' . strtolower(wp_strip_all_tags((string) $haystack_text)) . ' ';
        $tokens = preg_split('/[^\p{L}\p{N}_@.\-]+/u', $needle_text);
        $stop = ['the','and','for','with','from','this','that','have','has','will','task','board','comment','created','updated','changed','user','your','you','در','از','به','برای','که','این','آن','های','است','شد','شود','یک','یا','و','را','با'];
        $score = 0;
        $seen = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || strlen($token) < 3 || isset($seen[$token]) || in_array($token, $stop, true)) {
                continue;
            }
            $seen[$token] = true;
            if (strpos($haystack, $token) !== false) {
                $score++;
            }
        }
        return $score;
    }
}
