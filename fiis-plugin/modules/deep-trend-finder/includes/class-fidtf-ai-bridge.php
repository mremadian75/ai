<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Optional AI planner adapter.
 *
 * This class deliberately does not create a direct OpenAI/Gemini/Claude client.
 * It only delegates to an already-configured mother-plugin bridge when such a
 * bridge exposes a compatible planner method. Otherwise it returns WP_Error and
 * the local deterministic planner remains the source of truth.
 */
final class FIDTF_AI_Bridge {
    public static function register(): void {
        add_filter('fidtf_ai_planner_result', [__CLASS__, 'filter_planner_result'], 20, 3);
    }

    public static function filter_planner_result($current, array $request, array $fallback = []) {
        if (is_array($current) || is_wp_error($current)) {
            return $current;
        }
        return self::plan($request, $fallback);
    }

    public static function plan(array $request, array $fallback = []) {
        $settings = FIDTF_Settings::get();
        $model_alias = sanitize_key((string) ($settings['planner_model_alias'] ?? ''));
        $payload = self::planner_payload($request, $fallback, $model_alias);

        $callable = self::detect_planner_callable();
        if (!$callable) {
            return new WP_Error('ai_planner_unavailable', 'No configured AI planner client was available.', [
                'retryable' => false,
                'diagnostic' => 'No configured AI planner client was available.',
            ]);
        }

        try {
            $result = call_user_func($callable, $payload, $request, $fallback);
        } catch (Throwable $e) {
            return new WP_Error('ai_planner_bridge_failed', 'AI planner bridge failed.', [
                'retryable' => false,
                'diagnostic' => self::safe_diagnostic($e->getMessage()),
            ]);
        }

        if (is_wp_error($result)) {
            return $result;
        }
        if (is_array($result)) {
            return self::normalize_bridge_result($result, $model_alias);
        }
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                return self::normalize_bridge_result($decoded, $model_alias, $result);
            }
            return new WP_Error('ai_planner_invalid_json', 'AI planner bridge returned text that was not valid JSON.', [
                'retryable' => false,
                'raw_text' => self::safe_raw_text($result),
            ]);
        }

        return new WP_Error('ai_planner_invalid_result', 'AI planner bridge returned an unsupported result shape.', ['retryable' => false]);
    }

    public static function planner_payload(array $request, array $fallback = [], string $model_alias = ''): array {
        $request = FIDTF_Run_Service::sanitize_request($request);
        return [
            'task' => 'future_island_deep_trend_finder_source_plan',
            'model_alias' => sanitize_key($model_alias),
            'prompt_schema_version' => 'fidtf.planner_prompt.v1',
            'schema' => self::planner_json_schema(),
            'instructions' => self::planner_instructions(),
            'input' => [
                'request_context' => FIDTF_AI_Planner::request_context($request),
                'enabled_sources' => array_values(array_filter((array) ($request['channels'] ?? []))),
                'source_limits' => self::source_limits_for((array) ($request['channels'] ?? [])),
                'fallback_plan' => $fallback,
            ],
        ];
    }

    public static function planner_instructions(): string {
        return implode("\n", [
            'You are a source-planning assistant for a marketing intelligence workspace.',
            'Convert the user brief into a source-specific query plan only.',
            'Input fields: user_brief, objective, market, language, date_range, keywords, brand, company, competitors, audience, exclude_terms, selected channels, and source limits.',
            'Do not claim that scraping, browsing, web research, trend validation, video analysis, audio analysis, transcript analysis, or final synthesis has happened.',
            'No fake browsing and no fake scraping: create only planned queries, hints, questions, limits, and strategy notes for later provider execution.',
            'Use only the provided request context, selected sources, and source limits. Omit unselected sources.',
            'Return valid JSON matching the provided source-specific schema. No markdown fences.',
            'TikTok may use queries, hashtags, creator_or_format_hints, language_hints, market_hints, max_items, sort_strategy, and notes.',
            'Instagram may use queries, hashtags, profile_or_topic_hints, language_hints, market_hints, max_items, sort_strategy, and notes.',
            'Reddit may use queries, subreddits, time_range, language_hints, market_hints, max_items, sort_strategy, and notes.',
            'Google Trends may use seed_keywords, related_query_hints, geo, time_range, language_hints, market_hints, max_items, sort_strategy, and notes.',
            'Google News may use queries, publisher_or_topic_hints, geo, language, time_range, max_items, sort_strategy, and notes.',
            'AI Research may use research_questions, assumptions_to_test, market_questions, competitor_questions, max_items, sort_strategy, and notes. Do not answer the questions.',
            'Add limitations when the brief is broad, the market is vague, or the plan needs provider validation.',
        ]);
    }

    public static function planner_json_schema(): array {
        return [
            'type' => 'object',
            'required' => ['schema_version', 'brief_summary', 'sources', 'limitations', 'request_context'],
            'additionalProperties' => false,
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['fidtf.plan.v1']],
                'brief_summary' => ['type' => 'string'],
                'model_label' => ['type' => 'string'],
                'validation_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'limitations' => ['type' => 'array', 'items' => ['type' => 'string']],
                'request_context' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => self::request_context_schema_properties(),
                ],
                'sources' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => self::source_schema_properties(),
                ],
            ],
        ];
    }

    private static function source_schema_properties(): array {
        return [
            'tiktok' => self::source_schema(['queries', 'hashtags', 'creator_or_format_hints', 'language_hints', 'market_hints'], [], 'tiktok'),
            'instagram' => self::source_schema(['queries', 'hashtags', 'profile_or_topic_hints', 'language_hints', 'market_hints'], [], 'instagram'),
            'reddit' => self::source_schema(['queries', 'subreddits', 'language_hints', 'market_hints'], ['time_range'], 'reddit'),
            'google_trends' => self::source_schema(['seed_keywords', 'related_query_hints', 'language_hints', 'market_hints'], ['geo', 'time_range'], 'google_trends'),
            'google_news' => self::source_schema(['queries', 'publisher_or_topic_hints'], ['geo', 'language', 'time_range'], 'google_news'),
            'ai_research' => self::source_schema(['research_questions', 'assumptions_to_test', 'market_questions', 'competitor_questions'], [], 'ai_research'),
        ];
    }

    private static function source_schema(array $list_fields, array $string_fields, string $source_key): array {
        $props = [
            'source_key' => ['type' => 'string', 'enum' => [$source_key]],
            'max_items' => ['type' => 'integer'],
            'sort_strategy' => ['type' => 'string'],
            'notes' => ['type' => 'string'],
        ];
        foreach ($list_fields as $field) {
            $props[$field] = ['type' => 'array', 'items' => ['type' => 'string']];
        }
        foreach ($string_fields as $field) {
            $props[$field] = ['type' => 'string'];
        }
        return [
            'type' => 'object',
            'required' => ['source_key', 'max_items', 'sort_strategy'],
            'additionalProperties' => false,
            'properties' => $props,
        ];
    }

    private static function request_context_schema_properties(): array {
        $string = ['type' => 'string'];
        $string_array = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'user_brief' => $string,
            'objective' => $string,
            'market' => $string,
            'language' => $string,
            'date_range' => $string,
            'keywords' => $string_array,
            'brand' => $string,
            'company' => $string,
            'competitors' => $string_array,
            'audience' => $string,
            'exclude_terms' => $string_array,
            'channels' => $string_array,
        ];
    }

    private static function source_limits_for(array $channels): array {
        $out = [];
        foreach ($channels as $channel) {
            $source = sanitize_key((string) $channel);
            if ($source !== '' && FIDTF_Settings::source_enabled($source)) {
                $out[$source] = FIDTF_Settings::source_limit($source);
            }
        }
        return $out;
    }

    private static function detect_planner_callable() {
        $candidates = [
            ['VES_AI_Planner_Bridge', 'plan_deep_trend_finder'],
            ['VES_AI_Router', 'plan_deep_trend_finder'],
            ['VES_OpenAI_Client', 'plan_deep_trend_finder'],
        ];
        foreach ($candidates as $candidate) {
            if (is_callable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function normalize_bridge_result(array $result, string $model_alias = '', string $raw_text = ''): array {
        if (isset($result['ok']) && empty($result['ok'])) {
            return $result;
        }

        $provider_issue = self::provider_issue_from_response($result);
        if (!empty($provider_issue)) {
            return $provider_issue;
        }

        // Some mother plugins pass a raw OpenAI/ChatGPT response instead of a
        // pre-decoded plan. Accept common Responses API / Chat Completions
        // shapes, SDK structured-output `parsed` payloads, extract model text,
        // strip markdown fences, and decode the JSON source plan before it reaches
        // the strict planner validator.
        if (!isset($result['plan']) && !isset($result['sources']) && !isset($result['schema_version'])) {
            $parsed_payload = self::parsed_payload_from_response($result);
            if (is_array($parsed_payload)) {
                if (!empty($result['model']) && empty($parsed_payload['model_label'])) {
                    $parsed_payload['model_label'] = sanitize_text_field((string) $result['model']);
                }
                return self::normalize_bridge_result($parsed_payload, $model_alias, '');
            }
            $model_text = self::model_text_from_response($result);
            if ($model_text !== '') {
                $decoded = self::decode_model_json_text($model_text);
                if (is_array($decoded)) {
                    if (!empty($result['model']) && empty($decoded['model_label'])) {
                        $decoded['model_label'] = sanitize_text_field((string) $result['model']);
                    }
                    return self::normalize_bridge_result($decoded, $model_alias, $model_text);
                }
                return [
                    'raw_text' => self::safe_raw_text($model_text),
                    'validation_notes' => ['AI planner bridge returned model text, but it was not valid JSON. Local fallback should be used.'],
                ];
            }
        }

        $plan = is_array($result['plan'] ?? null) ? (array) $result['plan'] : $result;
        if (!empty($result['model_label']) && empty($plan['model_label'])) {
            $plan['model_label'] = sanitize_text_field((string) $result['model_label']);
        } elseif (!empty($result['model']) && empty($plan['model_label'])) {
            $plan['model_label'] = sanitize_text_field((string) $result['model']);
        } elseif ($model_alias !== '' && empty($plan['model_label'])) {
            $plan['model_label'] = sanitize_key($model_alias);
        }
        if (!empty($result['validation_notes']) && empty($plan['validation_notes'])) {
            $plan['validation_notes'] = (array) $result['validation_notes'];
        }
        if ($raw_text !== '' && empty($plan['raw_text'])) {
            $plan['raw_text'] = self::safe_raw_text($raw_text);
        } elseif (!empty($result['raw_text']) && empty($plan['raw_text'])) {
            $plan['raw_text'] = self::safe_raw_text((string) $result['raw_text']);
        }
        return $plan;
    }

    private static function provider_issue_from_response(array $result): array {
        $notes = [];
        $raw = '';
        $code = '';
        if (isset($result['error']) && is_array($result['error'])) {
            $err = (array) $result['error'];
            $code = sanitize_key((string) ($err['code'] ?? $err['type'] ?? 'provider_error'));
            $msg = self::safe_diagnostic((string) ($err['message'] ?? 'OpenAI provider error.'));
            $notes[] = 'AI planner bridge returned provider error: ' . $msg;
            $raw = $msg;
        }
        $status = sanitize_key((string) ($result['status'] ?? ''));
        if (in_array($status, ['incomplete', 'failed', 'cancelled', 'canceled', 'requires_action'], true)) {
            $reason = '';
            if (isset($result['incomplete_details']) && is_array($result['incomplete_details'])) {
                $reason = (string) ($result['incomplete_details']['reason'] ?? '');
            }
            if ($reason === '' && isset($result['error']) && is_array($result['error'])) {
                $reason = (string) ($result['error']['message'] ?? '');
            }
            $notes[] = 'AI planner bridge returned non-complete response status: ' . $status . ($reason !== '' ? ' (' . self::safe_diagnostic($reason) . ')' : '') . '.';
        }
        $refusal = self::model_refusal_from_response($result);
        if ($refusal !== '') {
            $notes[] = 'AI planner bridge returned a model refusal instead of a source plan.';
            $raw = $refusal;
        }
        if (empty($notes)) { return []; }
        return [
            'validation_notes' => $notes,
            'provider_error_code' => $code !== '' ? $code : ($status !== '' ? $status : 'model_refusal'),
            'raw_text' => self::safe_raw_text($raw !== '' ? $raw : implode(' ', $notes)),
        ];
    }

    private static function parsed_payload_from_response(array $result) {
        $candidates = [];
        foreach (['parsed', 'output_parsed', 'json', 'structured_output'] as $field) {
            if (isset($result[$field]) && is_array($result[$field])) { $candidates[] = $result[$field]; }
        }
        if (isset($result['message']) && is_array($result['message'])) {
            foreach (['parsed', 'output_parsed'] as $field) {
                if (isset($result['message'][$field]) && is_array($result['message'][$field])) { $candidates[] = $result['message'][$field]; }
            }
        }
        if (isset($result['choices'][0]['message']) && is_array($result['choices'][0]['message'])) {
            foreach (['parsed', 'output_parsed'] as $field) {
                if (isset($result['choices'][0]['message'][$field]) && is_array($result['choices'][0]['message'][$field])) { $candidates[] = $result['choices'][0]['message'][$field]; }
            }
        }
        if (isset($result['output']) && is_array($result['output'])) {
            foreach ((array) $result['output'] as $output_item) {
                if (!is_array($output_item)) { continue; }
                foreach (['parsed', 'output_parsed'] as $field) {
                    if (isset($output_item[$field]) && is_array($output_item[$field])) { $candidates[] = $output_item[$field]; }
                }
                if (isset($output_item['content']) && is_array($output_item['content'])) {
                    foreach ((array) $output_item['content'] as $content_item) {
                        if (!is_array($content_item)) { continue; }
                        foreach (['parsed', 'output_parsed'] as $field) {
                            if (isset($content_item[$field]) && is_array($content_item[$field])) { $candidates[] = $content_item[$field]; }
                        }
                    }
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($candidate['schema_version']) || isset($candidate['sources']) || isset($candidate['plan'])) { return $candidate; }
        }
        return null;
    }

    private static function model_text_from_response(array $result): string {
        $candidates = [];
        if (self::is_list_array($result)) {
            $candidates = array_merge($candidates, self::text_from_content_blocks($result));
        }
        if (isset($result['output_text'])) { $candidates[] = $result['output_text']; }
        if (isset($result['text'])) { $candidates[] = $result['text']; }
        if (isset($result['content'])) {
            if (is_array($result['content'])) { $candidates = array_merge($candidates, self::text_from_content_blocks((array) $result['content'])); }
            else { $candidates[] = $result['content']; }
        }
        if (isset($result['response']) && is_array($result['response'])) {
            $nested = self::model_text_from_response((array) $result['response']);
            if ($nested !== '') { return $nested; }
        }
        if (isset($result['message']) && is_array($result['message'])) {
            $nested = self::model_text_from_response((array) $result['message']);
            if ($nested !== '') { return $nested; }
        }
        if (isset($result['choices'][0]['message']['content'])) {
            $choice_content = $result['choices'][0]['message']['content'];
            if (is_array($choice_content)) { $candidates = array_merge($candidates, self::text_from_content_blocks((array) $choice_content)); }
            else { $candidates[] = $choice_content; }
        }
        if (isset($result['choices'][0]['text'])) { $candidates[] = $result['choices'][0]['text']; }
        if (isset($result['output']) && is_array($result['output'])) {
            foreach ((array) $result['output'] as $output_item) {
                if (!is_array($output_item)) { continue; }
                if (isset($output_item['content']) && is_array($output_item['content'])) {
                    $candidates = array_merge($candidates, self::text_from_content_blocks((array) $output_item['content']));
                }
                foreach (['text', 'content', 'output_text'] as $field) {
                    if (isset($output_item[$field])) { $candidates[] = $output_item[$field]; }
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $nested = self::model_text_from_response($candidate);
                if ($nested !== '') { return $nested; }
                continue;
            }
            if (is_scalar($candidate)) {
                $text = trim((string) $candidate);
                if ($text !== '') { return $text; }
            }
        }
        return '';
    }

    private static function text_from_content_blocks(array $blocks): array {
        $out = [];
        foreach ($blocks as $block) {
            if (is_string($block) || is_numeric($block)) { $out[] = (string) $block; continue; }
            if (!is_array($block)) { continue; }
            foreach (['text', 'content', 'output_text'] as $field) {
                if (isset($block[$field]) && is_scalar($block[$field])) { $out[] = (string) $block[$field]; }
            }
            if (isset($block['parts']) && is_array($block['parts'])) {
                $out = array_merge($out, self::text_from_content_blocks((array) $block['parts']));
            }
        }
        return $out;
    }

    private static function model_refusal_from_response(array $result): string {
        $candidates = [];
        if (isset($result['refusal']) && is_scalar($result['refusal'])) { $candidates[] = (string) $result['refusal']; }
        if (isset($result['choices'][0]['message']['refusal']) && is_scalar($result['choices'][0]['message']['refusal'])) { $candidates[] = (string) $result['choices'][0]['message']['refusal']; }
        foreach (['content', 'output'] as $field) {
            if (!isset($result[$field]) || !is_array($result[$field])) { continue; }
            foreach ((array) $result[$field] as $item) {
                if (!is_array($item)) { continue; }
                if (isset($item['refusal']) && is_scalar($item['refusal'])) { $candidates[] = (string) $item['refusal']; }
                if (isset($item['content']) && is_array($item['content'])) {
                    foreach ((array) $item['content'] as $content_item) {
                        if (is_array($content_item) && isset($content_item['refusal']) && is_scalar($content_item['refusal'])) { $candidates[] = (string) $content_item['refusal']; }
                    }
                }
            }
        }
        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') { return self::safe_diagnostic($text); }
        }
        return '';
    }

    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) { return false; }
            $expected++;
        }
        return true;
    }

    private static function decode_model_json_text(string $text) {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', (string) $text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) { return $decoded; }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) { return $decoded; }
        }
        return null;
    }



    /**
     * v0.3.39: admin-only OpenAI runtime diagnostics for live QA.
     *
     * The planner still delegates to the mother plugin by default. This method is
     * intentionally a small smoke test for installations that explicitly provide a
     * key through server-side configuration (constant/env/filter). It never returns
     * the raw key and redacts provider diagnostics before exposing them to admins.
     */
    public static function openai_runtime_status(): array {
        $meta = self::openai_api_key_meta();
        $key = (string) ($meta['key'] ?? '');
        $transport_ready = function_exists('wp_remote_post');
        return [
            'configured' => $key !== '',
            'transport_ready' => $transport_ready,
            'endpoint' => 'https://api.openai.com/v1/responses',
            'model' => self::openai_preflight_model(),
            'response_format' => 'json_schema',
            'key_source' => sanitize_key((string) ($meta['source'] ?? 'none')),
            'can_live_test' => $key !== '' && $transport_ready,
        ];
    }

    public static function run_openai_smoke_test(array $sample = []): array {
        $status = self::openai_runtime_status();
        if (empty($status['configured'])) {
            return [
                'ok' => false,
                'status' => 'not_configured',
                'diagnostic' => 'No server-side OpenAI API key is configured for this WordPress runtime.',
                'runtime' => $status,
            ];
        }
        if (empty($status['transport_ready'])) {
            return [
                'ok' => false,
                'status' => 'transport_unavailable',
                'diagnostic' => 'WordPress HTTP transport is unavailable.',
                'runtime' => $status,
            ];
        }
        $request_key_ignored = isset($sample['api_key']) || isset($sample['openai_api_key']) || isset($sample['OPENAI_API_KEY']);
        $prepared_sample = self::prepare_openai_smoke_sample($sample);
        $body = [
            'model' => self::openai_preflight_model(),
            'input' => self::openai_smoke_prompt($prepared_sample),
            'max_output_tokens' => 1200,
            'store' => false,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'fidtf_live_smoke_analysis',
                    'schema' => self::openai_smoke_response_schema(),
                    'strict' => true,
                ],
            ],
        ];
        $ves_ai_t0 = microtime(true); // Phase 1 cost-spine timing.
        $ves_ai_meta = ['module' => 'deep_trend_finder', 'operation_type' => 'openai_smoke_test', 'model' => (string) ($body['model'] ?? '')];
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . self::openai_api_key(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            if (class_exists('VES_AI_Usage_Tracker')) { VES_AI_Usage_Tracker::record_openai_call(array_merge($ves_ai_meta, ['started_at' => $ves_ai_t0, 'response' => $response])); }
            return [
                'ok' => false,
                'status' => 'transport_error',
                'diagnostic' => self::safe_diagnostic($response->get_error_message()),
                'runtime' => $status,
                'request_key_ignored' => $request_key_ignored,
                'sample_profile' => $prepared_sample['sample_profile'] ?? [],
            ];
        }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $raw = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $decoded = json_decode($raw, true);
        if (class_exists('VES_AI_Usage_Tracker')) {
            $ves_ai_ok = ($code >= 200 && $code < 300 && is_array($decoded));
            VES_AI_Usage_Tracker::record_openai_call(array_merge($ves_ai_meta, [
                'started_at' => $ves_ai_t0, 'response' => is_array($decoded) ? $decoded : null,
                'status' => $ves_ai_ok ? 'completed' : 'failed', 'error_code' => $ves_ai_ok ? '' : ('http_' . (int) $code),
            ]));
        }
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => 'invalid_json_response',
                'http_status' => $code,
                'diagnostic' => self::safe_raw_text($raw),
                'runtime' => $status,
                'request_key_ignored' => $request_key_ignored,
                'sample_profile' => $prepared_sample['sample_profile'] ?? [],
            ];
        }
        $provider_issue = self::provider_issue_from_response($decoded);
        if ($code < 200 || $code >= 300 || !empty($provider_issue)) {
            return [
                'ok' => false,
                'status' => 'provider_error',
                'http_status' => $code,
                'diagnostic' => self::safe_raw_text(wp_json_encode(!empty($provider_issue) ? $provider_issue : $decoded)),
                'runtime' => $status,
                'request_key_ignored' => $request_key_ignored,
                'sample_profile' => $prepared_sample['sample_profile'] ?? [],
            ];
        }
        $text = self::model_text_from_response($decoded);
        $parsed = $text !== '' ? self::decode_model_json_text($text) : null;
        return [
            'ok' => is_array($parsed),
            'status' => is_array($parsed) ? 'ok' : 'unparsed_model_text',
            'http_status' => $code,
            'model' => sanitize_text_field((string) ($decoded['model'] ?? self::openai_preflight_model())),
            'parsed' => is_array($parsed) ? $parsed : null,
            'raw_text' => $text !== '' ? self::safe_raw_text($text) : '',
            'runtime' => $status,
            'request_key_ignored' => $request_key_ignored,
            'sample_profile' => $prepared_sample['sample_profile'] ?? [],
        ];
    }

    /**
     * v0.3.44: prepare a compact multi-platform evidence package for the live
     * GPT smoke test. The model receives row-level examples plus source-level
     * counts and quality flags, so the analysis can compare platforms rather than
     * summarize isolated rows.
     */
    private static function prepare_openai_smoke_sample(array $sample): array {
        $raw_evidence = array_slice((array) ($sample['evidence'] ?? []), 0, 25);
        $evidence = [];
        $source_counts = [];
        $metric_totals = [];
        $fit_counts = ['direct' => 0, 'adjacent' => 0, 'weak' => 0, 'noise' => 0, 'unknown' => 0];
        foreach ($raw_evidence as $row) {
            if (!is_array($row)) { continue; }
            $source = sanitize_key((string) ($row['source_key'] ?? $row['source'] ?? 'unknown'));
            if ($source === '') { $source = 'unknown'; }
            $metrics = is_array($row['metrics'] ?? null) ? (array) $row['metrics'] : [];
            $tier = sanitize_key((string) ($row['relevance_tier'] ?? $row['fit_tier'] ?? 'unknown'));
            if (!isset($fit_counts[$tier])) { $tier = 'unknown'; }
            $fit_counts[$tier]++;
            $source_counts[$source] = ($source_counts[$source] ?? 0) + 1;
            foreach (['views','likes','comments','shares','saves','trend_interest','score'] as $metric_key) {
                $metric_totals[$source][$metric_key] = ($metric_totals[$source][$metric_key] ?? 0) + (float) ($metrics[$metric_key] ?? 0);
            }
            $evidence[] = [
                'source' => $source,
                'title' => self::safe_raw_text((string) ($row['title'] ?? '')),
                'text' => self::safe_raw_text((string) ($row['caption_or_text'] ?? $row['text'] ?? $row['caption'] ?? '')),
                'url_present' => !empty($row['url']),
                'published_at' => sanitize_text_field((string) ($row['published_at'] ?? $row['timestamp'] ?? '')),
                'author_or_source' => sanitize_text_field((string) ($row['author'] ?? $row['ownerUsername'] ?? $row['source_name'] ?? '')),
                'provider_query' => sanitize_text_field((string) ($row['provider_query'] ?? '')),
                'metrics' => $metrics,
                'hashtags' => array_slice(array_values(array_map('sanitize_text_field', (array) ($row['hashtags'] ?? []))), 0, 12),
                'relevance_tier' => $tier,
                'recommended_use' => sanitize_key((string) ($row['recommended_use'] ?? 'unknown')),
                'matched_keywords' => array_slice(array_values(array_map('sanitize_text_field', (array) ($row['matched_keywords'] ?? []))), 0, 10),
                'risk_flags' => array_values(array_filter([
                    !empty($row['view_count_missing']) ? 'view_count_missing' : '',
                    !empty($row['below_min_views']) ? 'below_min_views' : '',
                    in_array($source, ['reddit','google_news'], true) && empty($row['matched_core_keywords']) ? 'possible_context_only_match' : '',
                ])),
            ];
        }
        $sample_profile = [
            'row_count' => count($evidence),
            'source_counts' => $source_counts,
            'fit_counts' => $fit_counts,
            'metric_totals_by_source' => $metric_totals,
            'claim_readiness_expectation' => 'Score claims by source-family coverage, direct evidence share, source concentration, and false-positive risk before recommending action.',
            'cross_platform_analysis_contract' => [
                'social_creative' => 'TikTok and Instagram can support creative mechanics, language, hooks, and format hypotheses.',
                'community_discourse' => 'Reddit can support objections and discourse only when title/body/subreddit fit the topic.',
                'demand_movement' => 'Google Trends can support search-interest movement, not purchase intent.',
                'market_framing' => 'Google News can support media framing only when title/content fit the topic.',
            ],
            'required_analysis_behavior' => [
                'compare_source_families',
                'separate_creative_traction_from_demand',
                'cap_claims_when_reddit_or_news_are_off_intent',
                'never_use_publisher_or_author_name_as_semantic_proof',
                'state_validation_design_before_campaign_recommendations',
            ],
        ];
        return [
            'request_context' => is_array($sample['request_context'] ?? null) ? (array) $sample['request_context'] : [],
            'sample_profile' => $sample_profile,
            'evidence' => $evidence,
        ];
    }

    private static function openai_smoke_response_schema(): array {
        $finding = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['finding', 'evidence_basis', 'implication', 'confidence'],
            'properties' => [
                'finding' => ['type' => 'string'],
                'evidence_basis' => ['type' => 'string'],
                'implication' => ['type' => 'string'],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'directional', 'medium', 'high']],
            ],
        ];
        $source = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['source', 'role', 'strength', 'risk'],
            'properties' => [
                'source' => ['type' => 'string'],
                'role' => ['type' => 'string'],
                'strength' => ['type' => 'string'],
                'risk' => ['type' => 'string'],
            ],
        ];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'status',
                'analysis_quality',
                'executive_summary',
                'cross_platform_findings',
                'source_diagnostics',
                'quantitative_checks',
                'platform_weighting',
                'cross_source_consistency',
                'evidence_gaps',
                'causal_limits',
                'hypotheses_to_test',
                'risks',
                'recommended_next_actions',
                'triangulation_matrix',
                'decision_confidence_score',
                'evidence_quality_score',
                'must_not_claim',
                'platform_interaction_map',
                'claim_audit',
                'follow_up_test_design',
                'sample_limits',
                'data_scientist_verdict',
                'claim_readiness_judgement',
                'cross_platform_synthesis_depth',
            ],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok']],
                'analysis_quality' => ['type' => 'string', 'enum' => ['smoke_test_only', 'directional', 'strong']],
                'executive_summary' => ['type' => 'string'],
                'cross_platform_findings' => ['type' => 'array', 'items' => $finding],
                'source_diagnostics' => ['type' => 'array', 'items' => $source],
                'quantitative_checks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'platform_weighting' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['source', 'weight', 'reason', 'bias_risk'],
                    'properties' => [
                        'source' => ['type' => 'string'],
                        'weight' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'reason' => ['type' => 'string'],
                        'bias_risk' => ['type' => 'string'],
                    ],
                ]],
                'cross_source_consistency' => ['type' => 'string'],
                'evidence_gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'causal_limits' => ['type' => 'array', 'items' => ['type' => 'string']],
                'hypotheses_to_test' => ['type' => 'array', 'items' => ['type' => 'string']],
                'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_next_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'triangulation_matrix' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['claim', 'supporting_sources', 'contradicting_sources', 'decision'],
                    'properties' => [
                        'claim' => ['type' => 'string'],
                        'supporting_sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'contradicting_sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'decision' => ['type' => 'string', 'enum' => ['accept_directionally', 'needs_validation', 'reject_or_ignore']],
                    ],
                ]],
                'decision_confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'evidence_quality_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'must_not_claim' => ['type' => 'array', 'items' => ['type' => 'string']],
                'platform_interaction_map' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['interaction', 'sources_involved', 'what_it_means', 'confidence'],
                    'properties' => [
                        'interaction' => ['type' => 'string'],
                        'sources_involved' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'what_it_means' => ['type' => 'string'],
                        'confidence' => ['type' => 'string', 'enum' => ['low', 'directional', 'medium', 'high']],
                    ],
                ]],
                'claim_audit' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['claim', 'status', 'evidence_needed', 'why'],
                    'properties' => [
                        'claim' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['supported', 'directional_only', 'unsupported', 'contradicted']],
                        'evidence_needed' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                    ],
                ]],
                'follow_up_test_design' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['test', 'source', 'success_metric', 'minimum_sample', 'reason'],
                    'properties' => [
                        'test' => ['type' => 'string'],
                        'source' => ['type' => 'string'],
                        'success_metric' => ['type' => 'string'],
                        'minimum_sample' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                ]],
                'sample_limits' => ['type' => 'array', 'items' => ['type' => 'string']],
                'data_scientist_verdict' => ['type' => 'string'],
                'claim_readiness_judgement' => ['type' => 'string', 'enum' => ['supported', 'directional_only', 'hypothesis_only', 'blocked']],
                'cross_platform_synthesis_depth' => ['type' => 'string', 'enum' => ['single_source_summary', 'multi_source_directional', 'cross_platform_triangulated']],
            ],
        ];
    }

    private static function openai_api_key(): string {
        $meta = self::openai_api_key_meta();
        return trim((string) ($meta['key'] ?? ''));
    }

    private static function openai_api_key_meta(): array {
        $key = '';
        $source = 'none';
        if (defined('FIDTF_OPENAI_API_KEY') && is_string(FIDTF_OPENAI_API_KEY) && trim((string) FIDTF_OPENAI_API_KEY) !== '') {
            $key = (string) FIDTF_OPENAI_API_KEY;
            $source = 'fidtf_constant';
        }
        if ($key === '' && defined('OPENAI_API_KEY') && is_string(OPENAI_API_KEY) && trim((string) OPENAI_API_KEY) !== '') {
            $key = (string) OPENAI_API_KEY;
            $source = 'openai_constant';
        }
        if ($key === '' && (string) getenv('OPENAI_API_KEY') !== '') {
            $key = (string) getenv('OPENAI_API_KEY');
            $source = 'environment';
        }
        if (function_exists('apply_filters')) {
            $filtered = (string) apply_filters('fidtf_openai_api_key', $key);
            if (trim($filtered) !== trim($key)) { $source = 'filter'; }
            $key = $filtered;
        }
        return ['key' => trim($key), 'source' => $source];
    }

    private static function openai_preflight_model(): string {
        $model = defined('FIDTF_OPENAI_PREFLIGHT_MODEL') ? (string) FIDTF_OPENAI_PREFLIGHT_MODEL : 'gpt-4o-mini';
        if (function_exists('apply_filters')) { $model = (string) apply_filters('fidtf_openai_preflight_model', $model); }
        return sanitize_text_field($model !== '' ? $model : 'gpt-4o-mini');
    }

    private static function openai_smoke_prompt(array $prepared_sample): string {
        $payload = [
            'task' => 'deep_trend_finder_live_data_scientist_smoke_test',
            'instruction' => implode(' ', [
                'Act like a senior marketing data scientist validating a cross-platform trend intelligence pipeline.',
                'Do not invent facts outside the supplied evidence.',
                'Compare the sources as a system: social creative signals, community discourse, demand movement, and news framing.',
                'Triangulate claims: strong claims require at least two source families or must be marked as needing validation.',
                'Separate signal strength, source bias, evidence quality, hypotheses, evidence gaps, causal limits, and platform weighting.',
                'Return JSON only matching the provided schema, including triangulation_matrix, decision_confidence_score, evidence_quality_score, and must_not_claim.',
            ]),
            'analysis_rules' => [
                'Compare platforms against each other instead of summarizing each row separately.',
                'Act like a data scientist: score evidence quality, decision confidence, source bias, sample limits, false positives, and causal limits.',
                'Use social sources for language/format mechanics, trends for demand direction, news for market framing, and Reddit for objections/community discourse.',
                'Assign platform weighting based on role and fit, not only volume.',
                'Flag weak or off-topic high-engagement evidence instead of treating engagement as proof.',
                'Explicitly state causal limits: correlation across platforms is not proof of demand or purchase intent.',
                'Every finding must include evidence_basis, implication, and confidence.',
                'Do not treat source names, publisher names, hashtags, or provider search provenance as semantic proof without title/body/content support.',
                'Use sample_profile to reason about coverage, source concentration, metric imbalance, and direct/adjacent/weak split.',
                'Create a claim audit: supported, directional-only, unsupported, or contradicted. Do not turn directional-only claims into recommendations.',
                'Design follow-up tests with minimum sample sizes and source-specific success metrics.',
                'Before any recommendation, produce a claim-readiness judgement: supported, directional-only, hypothesis-only, or blocked.',
                'Analyze TikTok, Instagram, Reddit, Google Trends, and Google News as complementary evidence families, not interchangeable rows.',
            ],
            'request_context' => (array) ($prepared_sample['request_context'] ?? []),
            'sample_profile' => (array) ($prepared_sample['sample_profile'] ?? []),
            'evidence_sample' => (array) ($prepared_sample['evidence'] ?? []),
        ];
        return 'Return valid JSON only. Input: ' . wp_json_encode($payload);
    }

    private static function safe_raw_text(string $text): string {
        $text = self::safe_diagnostic($text);
        return substr($text, 0, 6000);
    }

    private static function safe_diagnostic(string $text): string {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9_\-.]+/i', 'Bearer [redacted]', $text);
        $text = preg_replace('/(OPENAI_API_KEY|api[_-]?key|x-api-key)\s*[:=]\s*[A-Za-z0-9_\-.]{16,}/i', '$1=[redacted]', $text);
        $text = preg_replace('/sk-[A-Za-z0-9_\-]{16,}/i', '[api_key_redacted]', $text);
        $text = preg_replace('/AIza[0-9A-Za-z_\-]{16,}/', '[api_key_redacted]', $text);
        return trim((string) $text);
    }
}
