<?php
if (!defined('ABSPATH')) { exit; }

final class VES_OpenAI_Client {

    private static function scrub_diagnostic_text($value) {
        $value = (string) $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9_\-.]+/i', 'Bearer [redacted]', $value);
        $value = preg_replace('/sk-[A-Za-z0-9_\-]{16,}/i', '[openai_key_redacted]', $value);
        $value = preg_replace('/apify_api_[A-Za-z0-9_\-]{12,}/i', '[apify_key_redacted]', $value);
        $value = preg_replace('/AIza[0-9A-Za-z_\-]{16,}/', '[google_key_redacted]', $value);
        return trim($value);
    }

    public static function extract_output_text($payload) {
        if (!is_array($payload)) {
            return '';
        }


        $chunks = [];

        if (!empty($payload['output_text']) && is_string($payload['output_text'])) {
            $chunks[] = trim($payload['output_text']);
        }

        // Be tolerant of both Responses API and legacy/chat-style shapes. Some
        // models/providers return text inside nested content arrays, while others
        // expose it directly on the output item.
        foreach (($payload['output'] ?? []) as $output_item) {
            if (!is_array($output_item)) {
                continue;
            }

            if (!empty($output_item['text']) && is_string($output_item['text'])) {
                $chunks[] = trim($output_item['text']);
            }

            foreach (($output_item['content'] ?? []) as $content_item) {
                if (!is_array($content_item)) {
                    if (is_string($content_item)) {
                        $chunks[] = trim($content_item);
                    }
                    continue;
                }

                if (!empty($content_item['text']) && is_string($content_item['text'])) {
                    $chunks[] = trim($content_item['text']);
                }
                if (!empty($content_item['content']) && is_string($content_item['content'])) {
                    $chunks[] = trim($content_item['content']);
                }
                if (!empty($content_item['text']) && is_array($content_item['text']) && !empty($content_item['text']['value']) && is_string($content_item['text']['value'])) {
                    $chunks[] = trim($content_item['text']['value']);
                }
            }
        }

        if (empty($chunks) && !empty($payload['choices'][0]['message']['content']) && is_string($payload['choices'][0]['message']['content'])) {
            $chunks[] = trim($payload['choices'][0]['message']['content']);
        }

        return trim(implode("\n\n", array_filter($chunks)));
    }

    private static function describe_empty_response($payload) {
        if (!is_array($payload)) {
            return 'payload_not_array';
        }


        $parts = [];
        if (!empty($payload['status'])) {
            $parts[] = 'status=' . sanitize_key((string) $payload['status']);
        }
        if (!empty($payload['incomplete_details']['reason'])) {
            $parts[] = 'reason=' . sanitize_key((string) $payload['incomplete_details']['reason']);
        }


        $types = [];
        foreach (($payload['output'] ?? []) as $output_item) {
            if (is_array($output_item) && !empty($output_item['type'])) {
                $types[] = sanitize_key((string) $output_item['type']);
            }
        }
        if (!empty($types)) {
            $parts[] = 'output_types=' . implode(',', array_slice(array_unique($types), 0, 6));
        }

        return !empty($parts) ? implode('; ', $parts) : 'no_text_chunks';
    }

    private static function retry_empty_text_response($request_body, $first_payload, $context = 'analysis') {
        if (!is_array($first_payload)) {
            return null;
        }

        $retry_body = $request_body;
        $retry_body['max_output_tokens'] = max((int) ($retry_body['max_output_tokens'] ?? 0), 5600);
        $retry_body['instructions'] = trim((string) ($retry_body['instructions'] ?? '') . "\n\nIMPORTANTE: devuelve siempre una respuesta final en texto plano. No dejes la salida vacía. Si la evidencia es limitada, dilo claramente y entrega un análisis prudente con acciones concretas.");

        $model = (string) ($retry_body['model'] ?? '');
        if (self::is_gpt5_family($model)) {

            $retry_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }

        if (class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic('openai_empty_retry', 'Retrying empty OpenAI text response.', [
                'model' => $model,
                'context' => sanitize_key((string) $context),
                'details' => self::describe_empty_response($first_payload),
            ]);
        }

        $retry_payload = self::request_responses_api($retry_body);
        if (is_wp_error($retry_payload)) {
            return $retry_payload;
        }

        return $retry_payload;
    }

    public static function extract_output_json($payload) {
        if (!is_array($payload)) {
            return null;
        }


        $direct_candidates = [];
        if (!empty($payload['output_parsed']) && is_array($payload['output_parsed'])) {

            $direct_candidates[] = $payload['output_parsed'];
        }

        foreach (($payload['output'] ?? []) as $output_item) {
            foreach (($output_item['content'] ?? []) as $content_item) {
                if (!empty($content_item['parsed']) && is_array($content_item['parsed'])) {

                    $direct_candidates[] = $content_item['parsed'];
                }
                if (!empty($content_item['json']) && is_array($content_item['json'])) {

                    $direct_candidates[] = $content_item['json'];
                }
            }
        }

        foreach ($direct_candidates as $candidate) {
            if (is_array($candidate) && !empty($candidate)) {
                return $candidate;
            }
        }

        $text = self::extract_output_text($payload);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Tolerate JSON in markdown fences or a short preface.
        $clean = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text));
        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($clean, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private static function is_gpt5_family($model) {
        return strpos(strtolower((string) $model), 'gpt-5') === 0;
    }

    private static function normalize_reasoning_effort($model, $effort) {
        $model = strtolower((string) $model);
        $effort = sanitize_key((string) $effort);
        if ($effort === '' || $effort === 'auto') {
            if (strpos($model, 'gpt-5.2') === 0) {
                return strpos($model, 'mini') !== false || strpos($model, 'nano') !== false ? 'low' : 'medium';
            }
            if (strpos($model, 'gpt-5.1') === 0) {
                return 'medium';
            }
            if (strpos($model, 'gpt-5-mini') === 0 || strpos($model, 'gpt-5-nano') === 0) {
                return 'low';
            }
            if (strpos($model, 'gpt-5') === 0) {
                return 'medium';
            }
            return '';
        }

        if (strpos($model, 'gpt-5.2') === 0) {
            return in_array($effort, ['none', 'low', 'medium', 'high', 'xhigh'], true) ? $effort : 'medium';
        }
        if (strpos($model, 'gpt-5.1') === 0) {
            if ($effort === 'xhigh') {
                return 'high';
            }
            return in_array($effort, ['none', 'low', 'medium', 'high'], true) ? $effort : 'medium';
        }
        if (strpos($model, 'gpt-5') === 0) {
            if ($effort === 'none') {
                return 'minimal';
            }
            if ($effort === 'xhigh') {
                return 'high';
            }
            return in_array($effort, ['minimal', 'low', 'medium', 'high'], true) ? $effort : 'medium';
        }
        return '';
    }

    private static function apply_reasoning_preferences($request_body) {
        $model = (string) ($request_body['model'] ?? '');
        if (!self::is_gpt5_family($model)) {
            unset($request_body['reasoning']);
            return $request_body;
        }

        // Allow call sites to deliberately use a lower reasoning effort for short
        // text tasks. This prevents GPT-5-family models from spending the entire
        // max_output_tokens budget on reasoning and returning no final text.
        $requested_effort = null;
        if (isset($request_body['reasoning']) && is_array($request_body['reasoning']) && isset($request_body['reasoning']['effort'])) {

            $requested_effort = $request_body['reasoning']['effort'];
        }

        $effort = self::normalize_reasoning_effort($model, $requested_effort !== null ? $requested_effort : VES_Config::get_openai_reasoning_effort());
        if ($effort !== '') {

            $request_body['reasoning'] = ['effort' => $effort];
        }
        return $request_body;
    }

    private static function trend_max_output_tokens($model, $structured = true) {
        $model = strtolower((string) $model);
        if (strpos($model, 'gpt-5.2') === 0 || strpos($model, 'gpt-5.1') === 0 || strpos($model, 'gpt-5') === 0) {
            return $structured ? 6000 : 4000;
        }
        return $structured ? 2400 : 1800;
    }

    private static function supports_structured_trend_output($model) {
        $model = strtolower((string) $model);
        return strpos($model, 'gpt-5.2-pro') !== 0 && strpos($model, 'gpt-5-pro') !== 0;
    }

    private static function normalize_responses_request_body($request_body) {
        if (!is_array($request_body)) {

            return [];
        }

        // OpenAI Responses API does not accept a top-level `format` parameter.
        // Some older plugin call-sites used `format` directly, which causes:
        // "Unknown parameter: 'format'". Keep this compatibility shim so older
        // call-sites are translated to the supported `text.format` shape.
        if (array_key_exists('format', $request_body)) {

            $format = $request_body['format'];
            unset($request_body['format']);

            if (!isset($request_body['text']) || !is_array($request_body['text'])) {

                $request_body['text'] = [];
            }

            if (is_array($format) && !empty($format)) {
                $request_body['text']['format'] = $format;
            } elseif (is_string($format) && $format === 'text') {

                $request_body['text']['format'] = ['type' => 'text'];
            }
        }

        return $request_body;
    }
    private static function http_timeout_seconds($purpose = 'fast_analysis') {
        if (class_exists('VES_Config') && method_exists('VES_Config', 'get_openai_timeout_seconds')) {
            return VES_Config::get_openai_timeout_seconds($purpose);
        }
        $timeout = 35;
        if (defined('VE_SOCIAL_OPENAI_TIMEOUT')) {
            $timeout = (int) VE_SOCIAL_OPENAI_TIMEOUT;
        }
        return max(10, min(55, (int) $timeout));
    }

    /**
     * Phase 1 cost-spine wrapper. Times the single OpenAI HTTP chokepoint and
     * records one usage-telemetry row per call (success or failure) WITHOUT
     * touching any of the ~13 internal callers or the returned response shape.
     * All real request/response handling stays in request_responses_api_raw().
     */
    private static function request_responses_api($request_body) {
        $model = is_array($request_body) ? (string) ($request_body['model'] ?? '') : '';
        $purpose = is_array($request_body) ? sanitize_key((string) ($request_body['_ves_purpose'] ?? 'fast_analysis')) : 'fast_analysis';
        $max_output = is_array($request_body) ? (int) ($request_body['max_output_tokens'] ?? 0) : 0;

        // A2: optional per-call attribution. Callers may set $request_body['_ves_meta']
        // = ['module'=>, 'operation_type'=>, 'workspace_id'=>, 'run_id'=>, 'user_id'=>].
        // It is stripped here so it never reaches the OpenAI API.
        $meta = (is_array($request_body) && is_array($request_body['_ves_meta'] ?? null)) ? $request_body['_ves_meta'] : [];
        if (is_array($request_body)) { unset($request_body['_ves_meta']); }

        $started_at = microtime(true);
        $result = self::request_responses_api_raw($request_body);

        if (class_exists('VES_AI_Usage_Tracker')) {
            $ctx = VES_AI_Usage_Tracker::get_context();
            $record = [
                'module'         => $meta['module'] ?? ($ctx['module'] ?? 'openai_client'),
                'operation_type' => $meta['operation_type'] ?? ($ctx['operation_type'] ?? self::usage_operation_type($purpose)),
                'model'          => $model,
                'started_at'     => $started_at,
                'response'       => $result,
                'metadata'       => ['purpose' => $purpose, 'max_output_tokens' => $max_output],
            ];
            // Only set tenant/run/user when known so record()'s ambient merge can fill the rest.
            foreach (['workspace_id', 'run_id', 'user_id'] as $k) {
                if (isset($meta[$k]) && $meta[$k] !== '' && $meta[$k] !== null) { $record[$k] = $meta[$k]; }
            }
            VES_AI_Usage_Tracker::record_openai_call($record);
        }

        return $result;
    }

    /** Map an internal model purpose to a stable telemetry operation_type. */
    private static function usage_operation_type($purpose) {
        $purpose = sanitize_key((string) $purpose);
        $allowed = ['fast_analysis', 'deep_analysis', 'brief_generation', 'assistant', 'general', 'legacy'];
        return in_array($purpose, $allowed, true) ? $purpose : 'fast_analysis';
    }

    private static function request_responses_api_raw($request_body) {
        $api_key = VES_Config::get_openai_api_key();
        if ($api_key === '') {
            return new WP_Error('missing_api_key', 'Falta configurar la OpenAI API Key para usar el análisis AI.');
        }

        $purpose = sanitize_key((string) ($request_body['_ves_purpose'] ?? 'fast_analysis'));
        unset($request_body['_ves_purpose']);
        $request_body = self::normalize_responses_request_body($request_body);
        $request_body = self::apply_reasoning_preferences($request_body);

        $timeout_seconds = self::http_timeout_seconds($purpose);
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => $timeout_seconds,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
        ]);

        if (is_wp_error($response)) {
            $transport_message = self::scrub_diagnostic_text($response->get_error_message());
            $transport_l = strtolower((string) $transport_message);
            $error_code = (strpos($transport_l, 'timed out') !== false || strpos($transport_l, 'cURL error 28') !== false || strpos($transport_l, 'operation timed out') !== false) ? 'request_timeout' : 'upstream_unavailable';
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_transport', $transport_message, [
                    'model' => $request_body['model'] ?? '',
                    'timeout_seconds' => $timeout_seconds,
                    'mapped_error' => $error_code,
                ]);
            }
            return new WP_Error(
                $error_code,
                $error_code === 'request_timeout' ? 'OpenAI no respondió a tiempo. Prueba de nuevo con menos resultados seleccionados o usa un modelo más rápido.' : 'OpenAI no está disponible en este momento. El sistema devolverá estado parcial si hay evidencia.',
                ['transport_message' => self::scrub_diagnostic_text($transport_message), 'timeout_seconds' => $timeout_seconds]
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        $upstream_message = '';
        if (is_array($json)) {
            // The error node may be an object {message:..} or, on some gateways, a
            // bare string. Accessing a string offset by key throws a TypeError in
            // PHP 8 (which ?? does NOT suppress), so branch on the shape.
            $err = $json['error'] ?? null;
            if (is_array($err)) { $upstream_message = trim((string) ($err['message'] ?? '')); }
            elseif (is_string($err)) { $upstream_message = trim($err); }
        }
        // Scrub the provider's error text before it is surfaced/returned, mirroring
        // the Apify client's discipline (no echoed request fragments/secrets).
        if ($upstream_message !== '') { $upstream_message = self::scrub_diagnostic_text($upstream_message); }

        if ($code === 401 || $code === 403) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_auth', 'Clave inválida o sin permisos.', ['status' => $code, 'model' => $request_body['model'] ?? '']);
            }
            return new WP_Error('invalid_api_key', 'La clave de OpenAI no es válida o no tiene permisos para este modelo.', ['status' => $code, 'upstream_message' => $upstream_message]);
        }
        if ($code === 429) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_rate_limit', 'OpenAI rate limit.', ['status' => $code, 'model' => $request_body['model'] ?? '']);
            }
            return new WP_Error('rate_limited', 'OpenAI ha aplicado rate limit. Inténtalo de nuevo en unos minutos.', ['status' => $code, 'upstream_message' => $upstream_message]);
        }
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_http', self::scrub_diagnostic_text(substr((string) $raw, 0, 1200)), ['status' => $code, 'model' => $request_body['model'] ?? '', 'purpose' => $purpose]);
            }
            $mapped_error = (!is_array($json) && $code >= 200 && $code < 300) ? 'invalid_json' : ($code === 400 ? 'invalid_request' : 'upstream_unavailable');
            return new WP_Error(
                $mapped_error,
                $upstream_message !== '' ? $upstream_message : 'No se pudo generar el análisis AI en este momento.',
                ['status' => $code, 'upstream_message' => $upstream_message, 'purpose' => $purpose]
            );
        }

        return $json;
    }

    private static function prompt_evidence_coverage_summary($compact_sources) {
        $summary = [
            'sources_queried' => 0,
            'usable_sources' => 0,
            'partial_sources' => 0,
            'failed_sources' => 0,
            'usable_records' => 0,
            'direct_matches' => 0,
            'adjacent_matches' => 0,
            'discarded_or_noise_count' => 0,
            'market_context_sources' => 0,
            'confidence_reason' => 'No usable topic evidence was found.',
        ];
        foreach ((array) $compact_sources as $source) {
            $source = is_array($source) ? $source : [];
            $summary['sources_queried']++;
            $state = sanitize_key((string) ($source['analytical_usability_status'] ?? 'unusable'));
            if ($state === 'usable') { $summary['usable_sources']++; }
            elseif ($state === 'partial') { $summary['partial_sources']++; }
            else { $summary['failed_sources']++; }
            if (($source['evidence_role'] ?? '') === 'market_context') { $summary['market_context_sources']++; }
            $summary['usable_records'] += (int) ($source['usable_records_count'] ?? $source['item_count'] ?? 0);
            $summary['direct_matches'] += (int) ($source['direct_match_count'] ?? 0);
            $summary['adjacent_matches'] += (int) ($source['adjacent_match_count'] ?? 0);
            $summary['discarded_or_noise_count'] += (int) ($source['rejected_records_count'] ?? 0);
        }
        if ($summary['direct_matches'] > 0 && $summary['usable_sources'] >= 2) {
            $summary['confidence_reason'] = 'Direct topic matches exist in at least two usable sources.';
        } elseif ($summary['usable_sources'] > 0 || $summary['partial_sources'] > 0) {
            $summary['confidence_reason'] = 'Evidence exists, but direct matches or source coverage are limited; produce a validation plan, not executive certainty.';
        }
        return $summary;
    }

    private static function build_trend_prompt_payload($request, $compact_sources, $project_context = null, $related_memory = []) {
        $max_items = class_exists('VES_Config') && method_exists('VES_Config', 'get_openai_max_evidence_items') ? VES_Config::get_openai_max_evidence_items() : 24;
        $max_refs = class_exists('VES_Config') && method_exists('VES_Config', 'get_openai_max_evidence_refs') ? VES_Config::get_openai_max_evidence_refs() : 40;
        $bounded_sources = [];
        foreach ((array) $compact_sources as $source_key => $source) {
            $source = is_array($source) ? $source : [];
            $items = array_values(array_slice((array) ($source['items'] ?? []), 0, $max_items));
            $highlights = array_values(array_slice((array) ($source['highlights'] ?? []), 0, min(12, $max_refs)));
            $bounded_sources[$source_key] = $source;
            $bounded_sources[$source_key]['items'] = $items;
            $bounded_sources[$source_key]['highlights'] = $highlights;
        }
        $related_memory = array_values(array_slice(is_array($related_memory) ? $related_memory : [], 0, 6));
        // Resolve fusion_result once: it may arrive as a non-array, and reaching
        // into a string offset by key throws a TypeError in PHP 8.
        $fusion = is_array($request['fusion_result'] ?? null) ? $request['fusion_result'] : [];
        return [
            'requested_topic' => $request['topic'] ?? '',
            'country' => $request['country'] ?? 'None',
            'country_label' => function_exists('ves_trend_label_country') ? ves_trend_label_country($request['country'] ?? 'None') : ($request['country'] ?? 'None'),
            'language' => $request['language'] ?? '',
            'requested_time_range' => $request['duration'] ?? '7d',
            'requested_time_range_label' => function_exists('ves_trend_duration_label') ? ves_trend_duration_label($request['duration'] ?? '7d') : ($request['duration'] ?? '7d'),
            'output_language' => $request['output_language'] ?? 'es',
            'date_from' => $request['date_from'] ?? '',
            'user_reason' => $request['business_goal'] ?? $request['reason'] ?? '',
            'normalized_request' => $request['normalized_request'] ?? null,
            'source_plan' => is_array($request['source_plan'] ?? null) ? $request['source_plan'] : [],
            'expanded_queries' => is_array($request['expanded_queries'] ?? null) ? $request['expanded_queries'] : [],
            'source_diagnostics' => is_array($request['source_diagnostics'] ?? null) ? $request['source_diagnostics'] : [],
            'fallback_log' => is_array($request['fallback_log'] ?? null) ? $request['fallback_log'] : [],
            'fusion_result' => $fusion,
            'report_mode' => sanitize_key((string) ($request['report_mode'] ?? ($fusion['report_mode'] ?? ''))),
            'brief_readiness' => $request['brief_readiness'] ?? ($fusion['brief_readiness_status'] ?? ''),
            'credit_audit' => is_array($request['credit_audit'] ?? null) ? $request['credit_audit'] : [],
            'memory_context' => is_array($request['memory_context'] ?? null) ? $request['memory_context'] : [],
            'project_context' => is_array($project_context) ? $project_context : null,
            'related_project_memory' => is_array($related_memory) ? $related_memory : [],
            'evidence_coverage_summary' => self::prompt_evidence_coverage_summary($bounded_sources),
            'source_results' => $bounded_sources,

        ];
    }



    private static function normalize_related_memory($memory_packets) {
        $memory_packets = is_array($memory_packets) ? $memory_packets : [];
        $clean = [];
        $sanitize_record = static function($record) {
            $record = is_array($record) ? $record : [];
            return [
                'id' => isset($record['id']) ? (int) $record['id'] : 0,
                'memory_type' => sanitize_key((string) ($record['memory_type'] ?? $record['entry_type'] ?? '')),
                'brand_name' => sanitize_text_field((string) ($record['brand_name'] ?? '')),
                'title' => sanitize_text_field((string) ($record['title'] ?? $record['report_title'] ?? '')),
                'summary' => sanitize_textarea_field((string) ($record['summary'] ?? '')),
                'source_type' => sanitize_key((string) ($record['source_type'] ?? '')),
                'source_id' => sanitize_text_field((string) ($record['source_id'] ?? '')),
                'tags' => array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($record['tags'] ?? $record['tags_json'] ?? []))), 0, 12)),
                'score' => isset($record['score']) ? (float) $record['score'] : (float) ($record['relevance_score'] ?? 0),
                'created_at' => sanitize_text_field((string) ($record['created_at'] ?? '')),
            ];
        };

        foreach (array_slice($memory_packets, 0, 8) as $packet) {
            if (!is_array($packet)) { continue; }
            $records = [];
            foreach (array_slice((array) ($packet['records'] ?? []), 0, 8) as $record) {
                if (is_array($record)) {
                    $records[] = $sanitize_record($record);
                }
            }
            $clean[] = [
                'entry_type' => sanitize_key((string) ($packet['entry_type'] ?? $packet['memory_type'] ?? '')),
                'platform' => sanitize_key((string) ($packet['platform'] ?? '')),
                'created_at' => sanitize_text_field((string) ($packet['created_at'] ?? '')),
                'title' => sanitize_text_field((string) ($packet['title'] ?? $packet['report_title'] ?? '')),
                'summary' => sanitize_textarea_field((string) ($packet['summary'] ?? '')),
                'signals' => array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($packet['signals'] ?? []))), 0, 6)),
                'matched_terms' => array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($packet['matched_terms'] ?? []))), 0, 6)),
                'relevance_score' => (float) ($packet['relevance_score'] ?? $packet['score'] ?? 0),
                'records' => $records,
            ];
        }
        return $clean;
    }



    private static function filter_related_memory_for_item($memory_packets, $platform, $payload_item) {
        $memory_packets = is_array($memory_packets) ? $memory_packets : [];
        $platform = sanitize_key((string) $platform);
        $payload_item = is_array($payload_item) ? $payload_item : [];
        $topic_text = strtolower(wp_strip_all_tags(wp_json_encode([
            $payload_item['title'] ?? '',
            $payload_item['caption_or_text'] ?? '',
            $payload_item['hashtags'] ?? [],
            $payload_item['search_relevance']['reasons'] ?? [],
        ], JSON_UNESCAPED_UNICODE)));
        $filtered = [];
        foreach ($memory_packets as $packet) {
            if (!is_array($packet)) { continue; }
            $entry_type = sanitize_key((string) ($packet['entry_type'] ?? $packet['memory_type'] ?? ''));
            $packet_platform = sanitize_key((string) ($packet['platform'] ?? ''));
            $base_score = (float) ($packet['relevance_score'] ?? $packet['score'] ?? 0);
            $matched_terms = array_values(array_filter(array_map('sanitize_text_field', (array) ($packet['matched_terms'] ?? []))));
            $overlap = 0;
            foreach ($matched_terms as $term) {
                $term_l = strtolower(trim((string) $term));
                if ($term_l !== '' && strpos($topic_text, $term_l) !== false) { $overlap++; }
            }
            $score = max($base_score, min(1.0, ($overlap * 0.18) + $base_score));
            $looks_seo = (bool) preg_match('/seo|semrush|keyword|domain|backlink|serp/i', $entry_type . ' ' . $packet_platform . ' ' . ($packet['title'] ?? ''));
            $same_platform = $packet_platform === '' || $packet_platform === $platform;
            if (!$same_platform && $looks_seo && $overlap < 1 && $score < 0.35) { continue; }
            if ($score < 0.12 && empty($matched_terms) && !$same_platform) { continue; }
            $packet['memory_relevance_score'] = round($score, 3);
            $packet['memory_use_rule'] = 'Use only as recent/contextual background. Current item evidence has priority.';
            $filtered[] = $packet;
            if (count($filtered) >= 5) { break; }
        }
        return $filtered;
    }

    private static function should_use_local_analysis_fallback($error) {
        if (!is_wp_error($error)) { return false; }
        return in_array($error->get_error_code(), ['request_timeout', 'upstream_unavailable', 'rate_limited'], true);
    }

    private static function format_local_metric_summary($metrics) {
        $metrics = is_array($metrics) ? $metrics : [];
        $metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics(['metrics' => $metrics]) : $metrics;
        $parts = [];
        foreach (['views' => 'views', 'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'saves' => 'saves'] as $key => $label) {
            $value = array_key_exists($key, $metrics) ? $metrics[$key] : null;
            if ($value !== null) { $parts[] = $label . ': ' . number_format_i18n((int) $value); }
        }
        if (!empty($metrics['duration'])) { $parts[] = 'duration: ' . sanitize_text_field((string) $metrics['duration']); }
        return !empty($parts) ? implode(', ', $parts) : 'metrics unavailable';
    }


    private static function build_local_item_analysis_fallback($payload_item, $focus = '', $error = null) {
        $payload_item = is_array($payload_item) ? $payload_item : [];
        $title = sanitize_text_field((string) ($payload_item['title'] ?? 'Contenido seleccionado'));
        $author = sanitize_text_field((string) ($payload_item['author'] ?? ''));
        $text = trim((string) ($payload_item['caption_or_text'] ?? ''));
        $transcript = trim((string) ($payload_item['transcript'] ?? ''));
        $metrics = is_array($payload_item['metrics'] ?? null) ? $payload_item['metrics'] : [];
        $focus = trim(sanitize_textarea_field((string) $focus));
        $reason = is_wp_error($error) ? sanitize_text_field($error->get_error_message()) : 'OpenAI no respondió a tiempo.';
        $snippet = mb_substr($text !== '' ? $text : $transcript, 0, 420);
        if ($snippet === '') { $snippet = 'No hay texto/transcripción suficiente en el resultado seleccionado.'; }

        $analysis = "**Análisis local de emergencia**\n\n";
        $analysis .= "OpenAI no completó la respuesta, así que el sistema devuelve una lectura segura basada solo en los datos visibles. Motivo: {$reason}\n\n";
        $analysis .= "**Resumen ejecutivo**\n";
        $analysis .= "- Pieza: " . ($title !== '' ? $title : 'sin título') . ($author !== '' ? " · Autor: {$author}" : '') . "\n";
        $analysis .= "- Métricas disponibles: " . self::format_local_metric_summary($metrics) . "\n";
        $analysis .= "- Texto visible: " . $snippet . "\n\n";
        $analysis .= "**Lectura útil**\n";
        $analysis .= "- Trata este resultado como señal preliminar, no como insight definitivo.\n";
        $analysis .= "- Revisa si la pieza contiene coincidencia directa de marca/tema antes de convertirla en oportunidad.\n";
        $analysis .= "- Si las métricas son altas pero la coincidencia semántica es débil, úsalo como contexto de ruido/mercado, no como recomendación.\n";
        if ($focus !== '') { $analysis .= "- Foco solicitado: {$focus}. Valídalo manualmente con el contenido completo.\n"; }
        $analysis .= "\n**Siguiente acción recomendada**\nSelecciona menos resultados, reduce transcripciones/contexto o usa un modelo más rápido para obtener análisis AI completo.";
        return $analysis;
    }


    private static function build_item_analysis_response_format() {
        $string = ['type' => 'string'];
        $string_array = ['type' => 'array', 'items' => $string];
        $bool = ['type' => 'boolean'];
        $confidence_enum = ['type' => 'string', 'enum' => ['low', 'medium', 'high']];
        $maybe_number = ['type' => ['number', 'null']];
        $derived_ratio = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => $string,
                'value' => $string,
                'interpretation' => $string,
                'confidence' => $string,
            ],
            'required' => ['name', 'value', 'interpretation', 'confidence'],
        ];
        $recommendation = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'action' => $string,
                'why' => $string,
                'evidence_used' => $string_array,
                'confidence' => $confidence_enum,
                'risk' => $string,
            ],
            'required' => ['action', 'why', 'evidence_used', 'confidence', 'risk'],
        ];
        $ab_test = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'hypothesis' => $string,
                'variant_a' => $string,
                'variant_b' => $string,
                'primary_kpi' => $string,
                'secondary_kpi' => $string,
                'minimum_evidence_needed' => $string,
                'test_duration_suggestion' => $string,
                'why_this_test' => $string,
                'confidence' => $confidence_enum,
            ],
            'required' => ['hypothesis', 'variant_a', 'variant_b', 'primary_kpi', 'secondary_kpi', 'minimum_evidence_needed', 'test_duration_suggestion', 'why_this_test', 'confidence'],
        ];
        return [
            'type' => 'json_schema',
            'name' => 'ves_item_analysis_v0930',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'analysis_mode' => ['type' => 'string', 'enum' => ['caption_metrics_only', 'full_creative', 'transcript_available', 'limited']],
                    'confidence' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['level' => $confidence_enum, 'reason' => $string],
                        'required' => ['level', 'reason'],
                    ],
                    'evidence_available' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'caption' => $bool,
                            'metrics' => $bool,
                            'video_preview' => $bool,
                            'audio' => $bool,
                            'transcript' => $bool,
                            'comments' => $bool,
                            'profile_context' => $bool,
                            'video_frames' => $bool,
                        ],
                        'required' => ['caption', 'metrics', 'video_preview', 'audio', 'transcript', 'comments', 'profile_context', 'video_frames'],
                    ],
                    'evidence_quality' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'caption' => $bool,
                            'metrics' => $bool,
                            'comments' => $bool,
                            'transcript' => $bool,
                            'video_frames' => $bool,
                            'audio' => $bool,
                        ],
                        'required' => ['caption', 'metrics', 'comments', 'transcript', 'video_frames', 'audio'],
                    ],
                    'executive_read' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'summary' => $string,
                            'what_is_observed' => $string_array,
                            'what_is_inferred' => $string_array,
                            'what_cannot_be_concluded' => $string_array,
                        ],
                        'required' => ['summary', 'what_is_observed', 'what_is_inferred', 'what_cannot_be_concluded'],
                    ],
                    'performance_metrics' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'views' => $maybe_number,
                            'likes' => $maybe_number,
                            'comments' => $maybe_number,
                            'shares' => $maybe_number,
                            'saves' => $maybe_number,
                            'derived_ratios' => ['type' => 'array', 'items' => $derived_ratio],
                        ],
                        'required' => ['views', 'likes', 'comments', 'shares', 'saves', 'derived_ratios'],
                    ],
                    'hook_and_retention' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'observed_hook' => $string,
                            'retention_hypothesis' => $string,
                            'limitations' => $string_array,
                        ],
                        'required' => ['observed_hook', 'retention_hypothesis', 'limitations'],
                    ],
                    'content_pattern' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'message_angle' => $string,
                            'format_pattern' => $string,
                            'cta_pattern' => $string,
                            'hashtags_pattern' => $string,
                            'reusable_formula' => $string,
                        ],
                        'required' => ['message_angle', 'format_pattern', 'cta_pattern', 'hashtags_pattern', 'reusable_formula'],
                    ],
                    'brand_or_market_fit' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'fit_summary' => $string,
                            'audience_fit' => $string,
                            'market_implication' => $string,
                        ],
                        'required' => ['fit_summary', 'audience_fit', 'market_implication'],
                    ],
                    'recommendations' => ['type' => 'array', 'items' => $recommendation],
                    'ab_tests' => ['type' => 'array', 'items' => $ab_test],
                    'limitations' => $string_array,
                ],
                'required' => ['analysis_mode', 'confidence', 'evidence_available', 'evidence_quality', 'executive_read', 'performance_metrics', 'hook_and_retention', 'content_pattern', 'brand_or_market_fit', 'recommendations', 'ab_tests', 'limitations'],
            ],
        ];
    }

    private static function item_analysis_evidence_flags($payload_item) {
        $payload_item = is_array($payload_item) ? $payload_item : [];
        $availability = is_array($payload_item['data_availability'] ?? null) ? $payload_item['data_availability'] : [];
        $quality = is_array($payload_item['evidence_quality'] ?? null) ? $payload_item['evidence_quality'] : [];
        $metrics = is_array($payload_item['metrics'] ?? null) ? $payload_item['metrics'] : [];
        $has_metrics = !empty($metrics['engagement_visible']);
        if (!$has_metrics && function_exists('ves_normalize_social_metrics')) {
            $canonical = ves_normalize_social_metrics(['metrics' => $metrics]);
            $has_metrics = !empty($canonical['engagement_visible']);
        }
        $caption = array_key_exists('caption', $quality) ? (bool) $quality['caption'] : (!empty($availability['caption_available']) || trim((string) ($payload_item['caption_or_text'] ?? $payload_item['caption'] ?? '')) !== '');
        $comments = array_key_exists('comments', $quality) ? (bool) $quality['comments'] : (!empty($availability['comment_sample_available']) || !empty($availability['comments_dataset_available']) || !empty($payload_item['comments_sample']));
        $transcript = array_key_exists('transcript', $quality) ? (bool) $quality['transcript'] : (!empty($availability['transcript_available']) || trim((string) ($payload_item['transcript'] ?? '')) !== '');
        $video_frames = array_key_exists('video_frames', $quality) ? (bool) $quality['video_frames'] : (!empty($availability['video_frames_available']) || !empty($payload_item['video_frames']));
        $audio = array_key_exists('audio', $quality) ? (bool) $quality['audio'] : (!empty($availability['audio_available']) || trim((string) ($payload_item['audio_transcript'] ?? '')) !== '');
        return [
            'caption' => $caption,
            'metrics' => $has_metrics,
            'video_preview' => !empty($availability['cover_available']) || trim((string) ($payload_item['video']['cover_url'] ?? '')) !== '',
            'audio' => $audio,
            'transcript' => $transcript,
            'comments' => $comments,
            'profile_context' => !empty($availability['profile_metadata_available']) || !empty($payload_item['author_profile']['username'] ?? '') || !empty($payload_item['profile']['username'] ?? ''),
            'video_frames' => $video_frames,
        ];
    }

    private static function item_analysis_evidence_quality($payload_item) {
        $flags = self::item_analysis_evidence_flags($payload_item);
        return [
            'caption' => (bool) $flags['caption'],
            'metrics' => (bool) $flags['metrics'],
            'comments' => (bool) $flags['comments'],
            'transcript' => (bool) $flags['transcript'],
            'video_frames' => (bool) $flags['video_frames'],
            'audio' => (bool) $flags['audio'],
        ];
    }

    private static function item_analysis_limitation_text($quality) {
        $quality = is_array($quality) ? $quality : [];
        if (!empty($quality['caption']) && !empty($quality['metrics']) && !empty($quality['comments'])) {
            return 'Análisis basado en caption, métricas visibles y muestra de comentarios. No se realizó análisis visual frame-by-frame ni análisis de audio.';
        }
        if (!empty($quality['caption']) && !empty($quality['metrics'])) {
            return 'Análisis basado en caption y métricas visibles. No se realizó análisis visual frame-by-frame ni análisis de audio.';
        }
        return 'Evidencia limitada. No se realizó análisis visual frame-by-frame ni análisis de audio.';
    }


    private static function build_local_item_analysis_structured_fallback($payload_item, $focus = '', $error = null) {
        $payload_item = is_array($payload_item) ? $payload_item : [];
        $flags = self::item_analysis_evidence_flags($payload_item);
        $quality = self::item_analysis_evidence_quality($payload_item);
        $metrics = is_array($payload_item['metrics'] ?? null) ? $payload_item['metrics'] : [];
        $canonical_metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics(['metrics' => $metrics]) : $metrics;
        // `post` may not be an array on malformed items; guard before indexing it
        // (a string offset by key is a TypeError in PHP 8, not suppressed by ??).
        $post_node = is_array($payload_item['post'] ?? null) ? $payload_item['post'] : [];
        $title = sanitize_text_field((string) ($payload_item['title'] ?? $post_node['id'] ?? 'Contenido seleccionado'));
        $text = trim((string) ($payload_item['caption_or_text'] ?? $payload_item['caption'] ?? $post_node['caption_or_text'] ?? ''));
        $snippet = self::analysis_text_excerpt($text, 320);
        $reason = is_wp_error($error) ? sanitize_text_field($error->get_error_message()) : 'La respuesta AI completa no estuvo disponible.';
        $confidence = (!empty($quality['comments']) && !empty($quality['caption']) && !empty($quality['metrics'])) ? 'medium' : 'low';
        if (!empty($quality['video_frames']) || !empty($quality['audio']) || !empty($quality['transcript'])) { $confidence = 'medium'; }
        $mode = !empty($quality['transcript']) ? 'transcript_available' : ((!empty($quality['caption']) || !empty($quality['metrics'])) ? 'caption_metrics_only' : 'limited');
        $limitation = self::item_analysis_limitation_text($quality);
        return [
            'analysis_mode' => $mode,
            'confidence' => [
                'level' => $confidence,
                'reason' => 'Lectura local basada solo en evidencia disponible normalizada. ' . $reason,
            ],
            'evidence_available' => $flags,
            'evidence_quality' => $quality,
            'executive_read' => [
                'summary' => 'Señal preliminar dentro de esta muestra para ' . ($title !== '' ? $title : 'la pieza seleccionada') . '.',
                'what_is_observed' => array_values(array_filter([
                    $title !== '' ? 'Contenido seleccionado: ' . $title : '',
                    $snippet !== '' ? 'Texto/caption disponible: ' . $snippet : '',
                    self::format_local_metric_summary($canonical_metrics),
                ])),
                'what_is_inferred' => ['Puede ser una señal potencial, pero requiere benchmark y revisión del contenido completo antes de escalar.'],
                'what_cannot_be_concluded' => ['No se puede concluir rendimiento relativo sin benchmark.', 'No se puede analizar edición visual frame-by-frame, audio, voz, música o retención real sin esa evidencia.'],
            ],
            'performance_metrics' => [
                'views' => $canonical_metrics['views'] ?? null,
                'likes' => $canonical_metrics['likes'] ?? null,
                'comments' => $canonical_metrics['comments'] ?? null,
                'shares' => $canonical_metrics['shares'] ?? null,
                'saves' => $canonical_metrics['saves'] ?? null,
                'derived_ratios' => [
                    ['name' => 'Engagement visible', 'value' => 'Requiere cálculo con benchmark', 'interpretation' => 'No se califica como alto o bajo sin referencia comparable.', 'confidence' => 'low'],
                ],
            ],
            'hook_and_retention' => [
                'observed_hook' => $snippet !== '' ? mb_substr($snippet, 0, 160) : '',
                'retention_hypothesis' => 'Hipótesis no confirmada; requiere retención, transcript o benchmark de piezas similares.',
                'limitations' => ['Sin visual/audio completo no se evalúa edición, sonido ni retención real.'],
            ],
            'content_pattern' => [
                'message_angle' => $focus !== '' ? 'Foco solicitado: ' . sanitize_text_field((string) $focus) : 'Ángulo no concluyente con la evidencia local.',
                'format_pattern' => 'Requiere revisión del formato real antes de reutilizarlo.',
                'cta_pattern' => 'No confirmado.',
                'hashtags_pattern' => 'Usar solo si los hashtags están presentes en la pieza.',
                'reusable_formula' => 'Tema + prueba controlada + benchmark antes de escalar.',
            ],
            'brand_or_market_fit' => [
                'fit_summary' => 'Encaje potencial, no confirmado.',
                'audience_fit' => 'Requiere validación con audiencia objetivo.',
                'market_implication' => 'Tratar como señal exploratoria dentro de esta muestra.',
            ],
            'recommendations' => [
                [
                    'action' => 'Usar esta pieza como referencia de hipótesis, no como recomendación final.',
                    'why' => 'La evidencia disponible es limitada y puede depender de contexto no capturado.',
                    'evidence_used' => array_values(array_keys(array_filter($quality))),
                    'confidence' => $confidence,
                    'risk' => 'Sobreinterpretar métricas sin benchmark o media no analizada.',
                ],
            ],
            'ab_tests' => [
                [
                    'hypothesis' => 'El ángulo observado puede generar señal si se adapta a la marca y se mide contra una pieza control.',
                    'variant_a' => 'Recrear el enfoque/caption observado con tono de marca.',
                    'variant_b' => 'Probar un hook alternativo con el mismo tema y CTA más explícito.',
                    'primary_kpi' => 'Retención o visualizaciones completas',
                    'secondary_kpi' => 'Engagement rate, comentarios cualitativos y guardados',
                    'minimum_evidence_needed' => 'Benchmark interno de al menos 3 piezas comparables o muestra suficiente por plataforma.',
                    'test_duration_suggestion' => '7-14 días o hasta alcanzar muestra estadísticamente útil.',
                    'why_this_test' => 'La evidencia actual no permite una conclusión fuerte, pero sí un plan de validación.',
                    'confidence' => 'low',
                ],
            ],
            'limitations' => ['Análisis local/fallback.', $limitation, 'Los ratios requieren benchmark.'],
        ];
    }


    private static function normalize_item_analysis_structured($structured, $payload_item, $output_language = 'es') {
        $structured = is_array($structured) ? $structured : [];
        $fallback = self::build_local_item_analysis_structured_fallback($payload_item, '', null);
        $merged = array_replace_recursive($fallback, $structured);
        $flags = self::item_analysis_evidence_flags($payload_item);
        $quality = self::item_analysis_evidence_quality($payload_item);
        $merged['evidence_available'] = array_merge($flags, ['video_preview' => !empty($flags['video_preview'])]);
        $merged['evidence_quality'] = $quality;
        $canonical_metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics(['metrics' => (array) ($payload_item['metrics'] ?? [])]) : (array) ($payload_item['metrics'] ?? []);
        $merged['performance_metrics'] = is_array($merged['performance_metrics'] ?? null) ? $merged['performance_metrics'] : [];
        foreach (['views', 'likes', 'comments', 'shares', 'saves'] as $metric_key) {
            $merged['performance_metrics'][$metric_key] = $canonical_metrics[$metric_key] ?? null;
        }
        if (empty($merged['performance_metrics']['derived_ratios']) || !is_array($merged['performance_metrics']['derived_ratios'])) {
            $merged['performance_metrics']['derived_ratios'] = $fallback['performance_metrics']['derived_ratios'];
        }
        $has_frames = !empty($quality['video_frames']);
        $has_audio = !empty($quality['audio']);
        $has_transcript = !empty($quality['transcript']);
        $has_comments = !empty($quality['comments']);
        if (!$has_frames || !$has_audio || !$has_transcript) {
            $current_level = sanitize_key((string) ($merged['confidence']['level'] ?? 'low'));
            $merged['confidence']['level'] = ($has_comments && !empty($quality['caption']) && !empty($quality['metrics'])) ? ($current_level === 'high' ? 'medium' : ($current_level ?: 'medium')) : 'low';
            $limitation = self::item_analysis_limitation_text($quality);
            if (empty($merged['limitations']) || !in_array($limitation, (array) $merged['limitations'], true)) {
                $merged['limitations'][] = $limitation;
            }
        }
        if (!$has_transcript && $merged['analysis_mode'] === 'full_creative') {
            $merged['analysis_mode'] = 'caption_metrics_only';
        }
        if (!$has_frames) {
            $merged['hook_and_retention']['limitations'][] = 'El análisis visual frame-by-frame estará disponible en Deep Video Analysis.';
        }
        if (empty($merged['ab_tests']) || !is_array($merged['ab_tests'])) {
            $merged['ab_tests'] = $fallback['ab_tests'];
        }
        if (empty($merged['recommendations']) || !is_array($merged['recommendations'])) {
            $merged['recommendations'] = $fallback['recommendations'];
        }
        return $merged;
    }


    private static function format_item_analysis_text_from_structured($structured) {
        if (!is_array($structured) || empty($structured)) { return ''; }
        $lines = [];
        $confidence = is_array($structured['confidence'] ?? null) ? $structured['confidence'] : [];
        $exec = is_array($structured['executive_read'] ?? null) ? $structured['executive_read'] : [];
        $quality = is_array($structured['evidence_quality'] ?? null) ? $structured['evidence_quality'] : (is_array($structured['evidence_available'] ?? null) ? $structured['evidence_available'] : []);
        $metrics = is_array($structured['performance_metrics'] ?? null) ? $structured['performance_metrics'] : [];
        $lines[] = 'Lectura ejecutiva';
        $lines[] = 'Confianza: ' . sanitize_text_field((string) ($confidence['level'] ?? 'low')) . ' — ' . sanitize_text_field((string) ($confidence['reason'] ?? ''));
        if (!empty($exec['summary'])) { $lines[] = sanitize_textarea_field((string) $exec['summary']); }
        $lines[] = '';
        $lines[] = 'Evidencia usada:';
        $evidence_labels = [
            'caption' => 'Caption',
            'metrics' => 'Métricas',
            'comments' => 'Comentarios',
            'video_frames' => 'Video frame-by-frame',
            'audio' => 'Audio',
            'transcript' => 'Transcript',
        ];
        foreach ($evidence_labels as $key => $label) {
            $lines[] = ' ' . (!empty($quality[$key]) ? '✓' : '✕') . ' ' . $label;
        }
        $lines[] = '';
        $lines[] = 'Métricas:';
        foreach (['views' => 'Views', 'likes' => 'Likes', 'comments' => 'Comentarios', 'shares' => 'Shares', 'saves' => 'Saves'] as $key => $label) {
            $value = array_key_exists($key, $metrics) ? $metrics[$key] : null;
            $lines[] = ' - ' . $label . ': ' . ($value === null ? '—' : number_format_i18n((int) $value));
        }
        foreach (['what_is_observed' => 'Observado', 'what_is_inferred' => 'Inferido', 'what_cannot_be_concluded' => 'No se puede concluir'] as $key => $label) {
            $items = is_array($exec[$key] ?? null) ? $exec[$key] : [];
            if (!$items) { continue; }
            $lines[] = '';
            $lines[] = $label . ':';
            foreach (array_slice($items, 0, 5) as $item) { $lines[] = ' - ' . sanitize_textarea_field((string) $item); }
        }
        if (!empty($structured['recommendations']) && is_array($structured['recommendations'])) {
            $lines[] = '';
            $lines[] = 'Recomendaciones:';
            foreach (array_slice($structured['recommendations'], 0, 5) as $rec) {
                if (!is_array($rec)) { continue; }
                $lines[] = ' - ' . sanitize_text_field((string) ($rec['action'] ?? 'Acción')) . ': ' . sanitize_textarea_field((string) ($rec['why'] ?? ''));
            }
        }
        if (!empty($structured['ab_tests']) && is_array($structured['ab_tests'])) {
            $lines[] = '';
            $lines[] = 'Plan de A/B testing:';
            foreach (array_slice($structured['ab_tests'], 0, 3) as $test) {
                if (!is_array($test)) { continue; }
                $lines[] = ' - ' . sanitize_text_field((string) ($test['hypothesis'] ?? 'Hipótesis')) . ' | A: ' . sanitize_textarea_field((string) ($test['variant_a'] ?? '')) . ' | B: ' . sanitize_textarea_field((string) ($test['variant_b'] ?? ''));
            }
        }
        if (!empty($structured['limitations']) && is_array($structured['limitations'])) {
            $lines[] = '';
            $lines[] = 'Limitaciones:';
            foreach (array_slice($structured['limitations'], 0, 6) as $limitation) { $lines[] = ' - ' . sanitize_textarea_field((string) $limitation); }
        }
        return trim(implode("\n", array_filter($lines, static function($line) { return $line !== null; })));
    }


    private static function build_local_batch_analysis_fallback($platform, $payload_items, $focus = '', $error = null) {
        $platform = sanitize_key((string) $platform);
        $payload_items = array_values(array_filter(is_array($payload_items) ? $payload_items : [], 'is_array'));
        $focus = trim(sanitize_textarea_field((string) $focus));
        $reason = is_wp_error($error) ? sanitize_text_field($error->get_error_message()) : 'OpenAI no respondió a tiempo.';
        usort($payload_items, static function($a, $b) {
            $am = is_array($a['metrics'] ?? null) ? $a['metrics'] : [];
            $bm = is_array($b['metrics'] ?? null) ? $b['metrics'] : [];
            $as = (int) ($am['views'] ?? 0) + (int) ($am['likes'] ?? 0) + (int) ($am['comments'] ?? 0) + (int) ($am['shares'] ?? 0);
            $bs = (int) ($bm['views'] ?? 0) + (int) ($bm['likes'] ?? 0) + (int) ($bm['comments'] ?? 0) + (int) ($bm['shares'] ?? 0);
            return $bs <=> $as;
        });
        $analysis = "**Análisis comparativo local de emergencia**\n\n";
        $analysis .= "OpenAI no completó la respuesta, así que el sistema devuelve una lectura segura basada solo en los datos visibles. Motivo: {$reason}\n\n";
        $analysis .= "**Resumen comparativo**\n";
        $analysis .= "- Plataforma: {$platform}\n";
        $analysis .= "- Resultados seleccionados: " . count($payload_items) . "\n";
        if ($focus !== '') { $analysis .= "- Foco solicitado: {$focus}\n"; }
        $analysis .= "- No se generaron inferencias AI; usa esto como fallback operativo.\n\n";
        $analysis .= "**Piezas con más señal métrica visible**\n";
        foreach (array_slice($payload_items, 0, 5) as $item) {
            $title = sanitize_text_field((string) ($item['title'] ?? 'sin título'));
            $author = sanitize_text_field((string) ($item['author'] ?? ''));
            $metrics = is_array($item['metrics'] ?? null) ? $item['metrics'] : [];
            $analysis .= "- " . ($title !== '' ? $title : 'sin título') . ($author !== '' ? " · {$author}" : '') . " — " . self::format_local_metric_summary($metrics) . "\n";
        }
        $analysis .= "\n**Recomendaciones seguras**\n";
        $analysis .= "- Analiza primero las piezas marcadas como best match/direct match, no los descartados.\n";
        $analysis .= "- No conviertas ruido o menciones casuales en oportunidades estratégicas.\n";
        $analysis .= "- Reintenta el análisis con 3–5 piezas, sin transcripciones largas, o cambia a un modelo rápido.\n";
        return $analysis;
    }

    private static function normalize_project_context($project_context) {
        if (!is_array($project_context) || empty($project_context)) {
            return null;
        }

        $clean = [];
        foreach ($project_context as $key => $value) {
            if ($value === '' || $value === null) { continue; }
            $clean[sanitize_key((string) $key)] = is_string($value) ? sanitize_textarea_field($value) : $value;
        }
        return !empty($clean) ? $clean : null;
    }

    private static function build_trend_response_format() {
        $string_array = ['type' => 'array', 'items' => ['type' => 'string']];
        $trend_item = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
                'confidence' => ['type' => 'string'],
                'evidence' => $string_array,
                'why_it_matters' => ['type' => 'string'],
                'recommended_next_step' => ['type' => 'string'],
                'watchout' => ['type' => 'string'],
            ],
            'required' => ['name', 'score', 'confidence', 'evidence', 'why_it_matters', 'recommended_next_step', 'watchout'],
        ];
        return [
            'type' => 'json_schema',
            'name' => 'trend_finder_analysis_v84',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'report_mode' => ['type' => 'string', 'enum' => ['trend_confirmed','trend_emerging','weak_signal_validation_needed','no_actionable_trend_found','source_failure_partial_report']],
                    'executive_summary' => ['type' => 'string'],
                    'evidence_coverage' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'sources_checked' => ['type' => 'integer'],
                            'usable_sources' => ['type' => 'integer'],
                            'usable_records' => ['type' => 'integer'],
                            'direct_matches' => ['type' => 'integer'],
                            'adjacent_matches' => ['type' => 'integer'],
                            'ambient_signals' => ['type' => 'integer'],
                            'discarded_or_noise' => ['type' => 'integer'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['sources_checked','usable_sources','usable_records','direct_matches','adjacent_matches','ambient_signals','discarded_or_noise','summary'],
                    ],
                    'signal_quality' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'confidence' => ['type' => 'string'],
                            'evidence_vs_inference' => ['type' => 'string'],
                            'blind_spots' => $string_array,
                        ],
                        'required' => ['confidence','evidence_vs_inference','blind_spots'],
                    ],
                    'prioritized_trends' => ['type' => 'array', 'items' => $trend_item],
                    'adjacent_opportunities' => ['type' => 'array', 'items' => $trend_item],
                    'ambient_signals' => ['type' => 'array', 'items' => $trend_item],
                    'source_reading' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'source' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'usefulness' => ['type' => 'string'],
                                'limitation' => ['type' => 'string'],
                                'next_action' => ['type' => 'string'],
                            ],
                            'required' => ['source','status','usefulness','limitation','next_action'],
                        ],
                    ],
                    'validation_recommendations' => $string_array,
                    'action_board' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'do_in_24h' => $string_array,
                            'test_this_week' => $string_array,
                            'monitor' => $string_array,
                            'ignore_for_now' => $string_array,
                        ],
                        'required' => ['do_in_24h','test_this_week','monitor','ignore_for_now'],
                    ],
                    'risks_false_positives' => $string_array,
                    'what_to_ignore' => $string_array,
                    'brief_readiness' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['locked_no_insights','validation_needed','ready_for_test_brief','ready_for_campaign_brief']],
                            'reason' => ['type' => 'string'],
                            'allowed_output' => ['type' => 'string'],
                        ],
                        'required' => ['status','reason','allowed_output'],
                    ],
                    'memory_summary' => ['type' => 'string'],
                ],
                'required' => ['report_mode','executive_summary','evidence_coverage','signal_quality','prioritized_trends','adjacent_opportunities','ambient_signals','source_reading','validation_recommendations','action_board','risks_false_positives','what_to_ignore','brief_readiness','memory_summary'],
            ],
        ];
    }

    private static function format_trend_analysis_text($structured) {
        if (!is_array($structured) || empty($structured)) { return ''; }
        $lines = [];
        $add = static function($value = '') use (&$lines) { $lines[] = (string) $value; };
        $heading = static function($title) use (&$lines) {
            $lines[] = '';
            $lines[] = $title;
            $lines[] = str_repeat('—', max(8, function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)));
        };
        $has_text = static function($value) {
            if (is_array($value)) { return count(array_filter($value, static function($v) { return trim(is_array($v) ? wp_json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v) !== ''; })) > 0; }
            return trim((string) $value) !== '';
        };
        $list = static function($items, $prefix = '- ') use (&$lines) {
            foreach ((array) $items as $item) {
                if (is_array($item)) { $item = wp_json_encode($item, JSON_UNESCAPED_UNICODE); }
                $item = trim((string) $item);
                if ($item !== '') { $lines[] = $prefix . $item; }
            }
        };

        $mode = trim((string) ($structured['report_mode'] ?? ''));
        $heading('Resumen ejecutivo');
        if ($mode !== '') { $add('Modo de reporte: ' . $mode); }
        $add(trim((string) ($structured['executive_summary'] ?? '')));

        $coverage = is_array($structured['evidence_coverage'] ?? null) ? $structured['evidence_coverage'] : [];
        if (!empty($coverage)) {
            $heading('Cobertura de evidencia');
            $summary = trim((string) ($coverage['summary'] ?? ''));
            if ($summary !== '') { $add($summary); }
            $parts = [];
            foreach (['sources_checked' => 'fuentes', 'usable_records' => 'registros utiles', 'direct_matches' => 'matches directos', 'adjacent_matches' => 'matches adyacentes', 'ambient_signals' => 'ambientales', 'discarded_or_noise' => 'ruido'] as $k => $label) {
                if (isset($coverage[$k])) { $parts[] = $label . ': ' . (int) $coverage[$k]; }
            }
            if (!empty($parts)) { $add(implode(' · ', $parts)); }
        }

        $quality = is_array($structured['signal_quality'] ?? null) ? $structured['signal_quality'] : [];
        if (!empty($quality)) {
            $heading('Calidad de señal');
            foreach (['confidence' => 'Confianza', 'evidence_vs_inference' => 'Evidencia vs inferencia'] as $k => $label) {
                if (!empty($quality[$k])) { $add($label . ': ' . trim((string) $quality[$k])); }
            }
            if (!empty($quality['blind_spots'])) { $add('Puntos ciegos:'); $list($quality['blind_spots']); }
        }

        foreach ([
            'prioritized_trends' => 'Tendencias priorizadas',
            'adjacent_opportunities' => 'Oportunidades adyacentes',
            'ambient_signals' => 'Señales ambientales'
        ] as $key => $title) {
            $items = is_array($structured[$key] ?? null) ? $structured[$key] : [];
            if (!$has_text($items)) { continue; }
            $heading($title);
            foreach ($items as $item) {
                if (!is_array($item)) { continue; }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') { continue; }
                $add($name . (isset($item['score']) ? ' | score ' . (int) $item['score'] . '/10' : ''));
                foreach (['confidence','why_it_matters','recommended_next_step','watchout'] as $field) {
                    if (!empty($item[$field])) { $add('- ' . str_replace('_', ' ', $field) . ': ' . trim((string) $item[$field])); }
                }
                if (!empty($item['evidence'])) { $add('- evidence: ' . implode(' | ', array_slice(array_map('strval', (array) $item['evidence']), 0, 3))); }
            }
        }

        $source_reading = is_array($structured['source_reading'] ?? null) ? $structured['source_reading'] : [];
        if ($has_text($source_reading)) {
            $heading('Lectura por fuente');
            foreach ($source_reading as $src) {
                if (!is_array($src)) { continue; }
                $name = trim((string) ($src['source'] ?? ''));
                if ($name === '') { continue; }
                $add($name . ' — ' . trim((string) ($src['status'] ?? '')));
                foreach (['usefulness','limitation','next_action'] as $field) {
                    if (!empty($src[$field])) { $add('- ' . str_replace('_', ' ', $field) . ': ' . trim((string) $src[$field])); }
                }
            }
        }

        if ($has_text($structured['validation_recommendations'] ?? [])) { $heading('Plan de validacion'); $list($structured['validation_recommendations']); }
        $action = is_array($structured['action_board'] ?? null) ? $structured['action_board'] : [];
        if ($has_text($action)) {
            $heading('Action board');
            foreach (['do_in_24h' => 'Que hacer en 24h', 'test_this_week' => 'Que testear esta semana', 'monitor' => 'Que vigilar', 'ignore_for_now' => 'Que ignorar'] as $key => $label) {
                if (!empty($action[$key])) { $add($label . ':'); $list($action[$key]); }
            }
        }
        if ($has_text($structured['risks_false_positives'] ?? [])) { $heading('Riesgos y falsos positivos'); $list($structured['risks_false_positives']); }
        if ($has_text($structured['what_to_ignore'] ?? [])) { $heading('Que ignorar por ahora'); $list($structured['what_to_ignore']); }
        $brief = is_array($structured['brief_readiness'] ?? null) ? $structured['brief_readiness'] : [];
        if (!empty($brief)) {
            $heading('Brief readiness');
            $add('Estado: ' . trim((string) ($brief['status'] ?? '')));
            if (!empty($brief['reason'])) { $add('Motivo: ' . trim((string) $brief['reason'])); }
            if (!empty($brief['allowed_output'])) { $add('Output permitido: ' . trim((string) $brief['allowed_output'])); }
        }
        if ($has_text($structured['memory_summary'] ?? '')) { $heading('Memory summary'); $add($structured['memory_summary']); }
        return trim(implode("\n", array_values(array_filter($lines, static function($line) { return $line !== null; }))));
    }

    private static function analyze_trend_bundle_structured($model, $request, $compact_sources, $project_context = null, $related_memory = []) {
        // v0.9.9.3: Strengthened instructions — frame the model as a senior
        // marketing data scientist analysing cross-channel trends FOR THIS
        // SPECIFIC BRAND, weaving in the user's stated reason and the project
        // context (brand, audience, tone, products) as the lens through which
        // every signal should be evaluated.
        $lang_instruction = self::output_language_instruction($request['output_language'] ?? 'es');
        $instructions = 'Eres un Senior Marketing Data Scientist con formación en cultural insight, content strategy y growth marketing. ' . $lang_instruction . ' Trabajas embebido como consultor para la marca descrita en project_context. Tu trabajo NO es resumir tendencias en abstracto: es interpretar evidencia estructurada y devolver un reporte honesto según report_mode. '
            . 'Has recibido request original, request normalizado, source_plan, expanded_queries, source diagnostics, fallback_log, normalized evidence, fusion_result, report_mode, brief_readiness, credit_audit y memoria. Los actores pueden devolver ruido, idiomas mezclados, mercados ajenos o contexto genérico. Usa fusion_result y evidence_coverage_summary como fuente de verdad para decidir qué es evidencia directa, adyacente, ambiental, formato, pain point o ruido. '
            . 'Pasos mentales que debes seguir antes de redactar (no los escribas, sólo úsalos): '
            . '(1) Identifica para qué busca la marca estas tendencias según user_reason — campaña concreta, monitoreo, pivote estratégico, etc. '
            . '(2) Mapea cada tendencia a la audiencia objetivo del project_context y al producto/servicio relevante. '
            . '(3) Separa con disciplina TRES capas: (a) evidencia DIRECTA del tema solicitado, (b) señales adyacentes culturalmente relevantes, (c) ruido / viralidad genérica sin puente real con la marca. '
            . '(4) Para cada tendencia evalúa: encaje con el objetivo declarado, activabilidad real para esta marca, timing, fuerza de evidencia, y RIESGOS reputacionales o de marca. '
            . '(5) Cruza con related_project_memory si existe — reportes anteriores son contexto, no evidencia actual. '
            . 'Cuando el contexto histórico confirme, debilite o contradiga el run actual, dilo de forma explícita. No reutilices rejected/outdated assumptions. Separa hechos/evidencia, inferencias, hipótesis y recomendaciones. '
            . 'Responde en JSON válido que cumpla EXACTAMENTE el schema solicitado. '
            . 'Reglas de calidad NO negociables: '
            . '- No inventes datos ni métricas. Si report_mode es weak_signal_validation_needed, no escribas recomendaciones de campaña; enfoca validation_recommendations y action_board en pruebas de bajo riesgo. '
            . '- Si report_mode es no_actionable_trend_found, dilo claramente y explica qué faltó. Recomienda la siguiente fuente o query, no una campaña. '
            . '- Si report_mode es source_failure_partial_report, separa fallo de fuente de conclusión de mercado; no afirmes que no hay demanda si las fuentes fallaron. '
            . '- Si report_mode es trend_confirmed, puedes proponer acción de campaña. Si es trend_emerging, solo pruebas ligeras y brief de test. '
            . '- No conviertas eventos virales genéricos ni trend lists ambientales en tendencias accionables si no hay match semántico. '
            . '- Deduplica claims: max 1 resumen ejecutivo, max 1 explicación de calidad de señal, max 1 action board. Cada sección debe aportar información nueva. '
            . '- Evita consejos genéricos como crear calendario de contenido salvo que conecten con evidencia concreta. '
            . '- brief_readiness debe respetar la entrada: locked_no_insights, validation_needed, ready_for_test_brief o ready_for_campaign_brief. '
            . 'Si project_context, workspace_knowledge o knowledge_graph están vacíos, dilo en signal_quality.blind_spots y haz tu análisis sin inventar atributos de marca.';

        $request_body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => "Contexto de la búsqueda:\n" . wp_json_encode(self::build_trend_prompt_payload($request, $compact_sources, self::normalize_project_context($project_context), self::normalize_related_memory($related_memory)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => self::trend_max_output_tokens($model, true),
            'text' => ['format' => self::build_trend_response_format()],
            'store' => false,
            '_ves_purpose' => 'fast_analysis',
        ];

        if (self::is_gpt5_family($model)) {
            $request_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }

        $json = self::request_responses_api($request_body);
        if (is_wp_error($json)) {
            return $json;
        }

        $structured = self::extract_output_json($json);
        if (!is_array($structured) || empty($structured)) {
            return new WP_Error('invalid_response', 'La respuesta estructurada del modelo ha llegado vacía.');
        }

        return [
            'analysis' => self::format_trend_analysis_text($structured),
            'analysis_structured' => $structured,
            'model' => $model,
            'format' => 'json_schema',

        ];
    }

    private static function analyze_trend_bundle_legacy($model, $request, $compact_sources, $project_context = null, $related_memory = []) {
        // v0.9.9.3: Same data-scientist framing for the legacy text-only path.
        $lang_instruction = self::output_language_instruction($request['output_language'] ?? 'es');
        $instructions = 'Eres un Senior Marketing Data Scientist trabajando como consultor para la marca descrita en project_context. ' . $lang_instruction . ' '
            . 'Has recibido fuentes solicitadas con restricciones de mercado, idioma y rango temporal, pero actual returned items may contain noise: los resultados pueden contener ruido, idiomas mezclados, mercados ajenos o contexto genérico. Usa evidence_coverage_summary, source_quality_classification, evidence_role y la relevancia item-level antes de concluir. Tu lente para interpretarlas es: la marca, su audiencia, su tono y el motivo concreto del usuario (user_reason). '
            . 'No resumas datos en abstracto: interpretalos para ESTA marca específica. Devuelve una respuesta estructurada con estos bloques, en este orden y con títulos claros: '
            . '1) Resumen ejecutivo (conclusión + 3 movimientos prioritarios), '
            . '2) Calidad de señal (overall_confidence + qué es evidencia directa vs inferencia + blindspots), '
            . '3) Lectura por canal, '
            . '4) Tendencias priorizadas (cada una con score 1-10 de encaje con el objetivo, fuerza de evidencia, tipo de señal, activación sugerida específica para esta marca, contraargumento y por qué puede estar creciendo), '
            . '5) Riesgos o falsos positivos (incluye riesgos reputacionales para esta marca), '
            . '6) Recomendaciones de validación: si la evidencia directa es débil, propone tests y qué validar antes de producir; solo da piezas concretas cuando haya evidencia topic-specific suficiente. '
            . 'Sé concreto, accionable, duro con la evidencia y evita relleno. Cuando una fuente tenga limitaciones, dilo. Si recibes project_context, workspace_knowledge, knowledge_graph o related_project_memory, úsalo para personalizar la lectura, no para inventar señal. Cuando el contexto histórico confirme, debilite o contradiga el run actual, dilo. Distingue hallazgos nuevos de hallazgos repetidos y evita supuestos rechazados u obsoletos. Si project_context está vacío, dilo y haz tu análisis sin inventar atributos de marca.';

        $request_body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => "Contexto de la búsqueda:\n" . wp_json_encode(self::build_trend_prompt_payload($request, $compact_sources, self::normalize_project_context($project_context), self::normalize_related_memory($related_memory)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => self::trend_max_output_tokens($model, false),

            'store' => false,
            '_ves_purpose' => 'fast_analysis',

        ];

        if (self::is_gpt5_family($model)) {

            $request_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }

        $json = self::request_responses_api($request_body);
        if (is_wp_error($json)) {
            return $json;
        }

        $analysis = self::extract_output_text($json);
        if ($analysis === '') {
            // v0.9.24.6: mirror the retry used by google_intelligence and item_analysis.
            $retry_payload = self::retry_empty_text_response($request_body, $json, 'trend_legacy');
            if (!is_wp_error($retry_payload) && is_array($retry_payload)) {
                $analysis = self::extract_output_text($retry_payload);
            } elseif (is_wp_error($retry_payload)) {
                return $retry_payload;
            }
        }
        if ($analysis === '') {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_empty', 'La respuesta del modelo para Trend Finder llegó vacía.', ['model' => $model]);
            }
            return new WP_Error('invalid_response', 'La respuesta del modelo ha llegado vacía.');
        }

        return [
            'analysis' => $analysis,
            'analysis_structured' => null,
            'model' => $model,
            'format' => 'text',

        ];
    }


    private static function build_trend_brief_response_format() {
        return [
            'type' => 'json_schema',
            'name' => 'trend_activation_brief',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'brief_title' => ['type' => 'string'],
                    'selected_format' => ['type' => 'string'],
                    'selected_trend' => ['type' => 'string'],
                    'objective' => ['type' => 'string'],
                    'why_now' => ['type' => 'string'],
                    'confidence_note' => ['type' => 'string'],
                    'target_audience' => ['type' => 'string'],
                    'key_insight' => ['type' => 'string'],
                    'strategic_angle' => ['type' => 'string'],
                    'message_pillars' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'content_deliverables' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'hooks' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'cta_options' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'production_plan_72h' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'guardrails' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'kpis' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['brief_title', 'selected_format', 'selected_trend', 'objective', 'why_now', 'confidence_note', 'target_audience', 'key_insight', 'strategic_angle', 'message_pillars', 'content_deliverables', 'hooks', 'cta_options', 'production_plan_72h', 'guardrails', 'risks', 'kpis'],
            ],

        ];
    }

    private static function format_trend_brief_text($structured) {
        if (!is_array($structured) || empty($structured)) {
            return '';
        }

        $lines = [];
        $lines[] = 'Brief de activación';
        $lines[] = '——————————';
        foreach ([
            'brief_title' => 'Título',
            'selected_format' => 'Formato',
            'selected_trend' => 'Trend seleccionado',
            'objective' => 'Objetivo',
            'why_now' => 'Por qué ahora',
            'confidence_note' => 'Nota de confianza',
            'target_audience' => 'Audiencia',
            'key_insight' => 'Insight clave',
            'strategic_angle' => 'Ángulo estratégico',
        ] as $key => $label) {
            if (!empty($structured[$key])) {
                $lines[] = $label . ': ' . trim((string) $structured[$key]);
            }
        }
        foreach ([
            'message_pillars' => 'Pilares de mensaje',
            'content_deliverables' => 'Piezas a producir',
            'hooks' => 'Hooks',
            'cta_options' => 'CTAs',
            'production_plan_72h' => 'Plan 72h',
            'guardrails' => 'Guardrails',
            'risks' => 'Riesgos',
            'kpis' => 'KPIs',
        ] as $key => $label) {

            $items = is_array($structured[$key] ?? null) ? $structured[$key] : [];
            if (empty($items)) {
                continue;
            }
            $lines[] = '';
            $lines[] = $label . ':';
            foreach ($items as $item) {
                if (trim((string) $item) !== '') {
                    $lines[] = ' - ' . trim((string) $item);
                }
            }
        }
        return trim(implode("
", $lines));
    }

    public static function generate_trend_brief($report_payload, $args = [], $related_memory = [], $project_context = null) {
        $model = VES_Config::get_openai_model('brief_generation');

        $report_payload = is_array($report_payload) ? $report_payload : [];

        $args = is_array($args) ? $args : [];
        $trend_name = sanitize_text_field((string) ($args['trend_name'] ?? ''));
        $brief_format = sanitize_text_field((string) ($args['brief_format'] ?? 'TikTok / Reels'));
        $extra_context = sanitize_textarea_field((string) ($args['extra_context'] ?? ''));
        if ($trend_name === '') {
            return new WP_Error('missing_trend', 'No se ha indicado el trend para crear el brief.');
        }


        $structured_report = is_array($report_payload['analysis_structured'] ?? null) ? $report_payload['analysis_structured'] : [];
        $matching_trend = null;
        foreach ((array) ($structured_report['prioritized_trends'] ?? []) as $trend) {
            if (mb_strtolower(trim((string) ($trend['trend_name'] ?? ''))) === mb_strtolower(trim($trend_name))) {
                $matching_trend = $trend;
                break;
            }
        }
        $context = [
            'requested' => $report_payload['requested'] ?? [],
            'executive_summary' => $structured_report['executive_summary'] ?? ($report_payload['analysis'] ?? ''),
            'overall_take' => $structured_report['overall_take'] ?? '',
            'selected_trend' => $matching_trend,
            'opportunity_board' => $structured_report['opportunity_board'] ?? [],
            'action_board' => $structured_report['action_board'] ?? [],
            'production_recommendations' => $structured_report['production_recommendations'] ?? [],
            'extra_context' => $extra_context,
            'brief_format' => $brief_format,
            'project_context' => self::normalize_project_context($project_context),
            'related_project_memory' => self::normalize_related_memory($related_memory),

        ];

        $instructions = 'Eres un strategist de marketing y content activation. Convierte un trend detectado en un brief accionable, corto pero sólido. Usa el project_context, el workspace knowledge, el knowledge_graph y la memoria reciente del proyecto cuando añadan precisión real. Distingue aprendizaje histórico de evidencia actual; no reutilices supuestos rechazados u obsoletos. '
            . 'No sobreprometas: si la señal es adyacente o débil, el brief debe reflejarlo. '
            . 'Tu salida debe servir para pasar inmediatamente a producción de contenido o a revisión interna. '
            . 'Responde SOLO con JSON válido que cumpla exactamente el schema.';

        $request_body = [
            'model' => $model,
            '_ves_purpose' => 'brief_generation',
            'instructions' => $instructions,
            'input' => "Contexto del reporte y del brief:
" . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => 3200,
            'text' => ['format' => self::build_trend_brief_response_format()],

            'store' => false,

        ];

        $use_structured_brief = self::supports_structured_trend_output($model);
        if (!$use_structured_brief) {
            unset($request_body['text']);
        }

        $json = self::request_responses_api($request_body);
        if (is_wp_error($json)) {
            return $json;
        }

        $structured = $use_structured_brief ? self::extract_output_json($json) : null;
        if (is_array($structured) && !empty($structured)) {
            return [
                'brief_text' => self::format_trend_brief_text($structured),
                'brief_structured' => $structured,
                'model' => $model,
                'format' => 'json_schema',

            ];
        }

        $text = self::extract_output_text($json);
        if ($text === '') {
            return new WP_Error('invalid_response', 'El brief generado ha llegado vacío.');
        }

        return [
            'brief_text' => $text,
            'brief_structured' => null,
            'model' => $model,
            'format' => 'text',

        ];
    }

    private static function fallback_google_intelligence_analysis($module_key, $module_label, $items, $mode = 'summary', $focus = '') {
        $module_key = sanitize_key((string) $module_key);
        $module_label = sanitize_text_field((string) $module_label);
        $mode = sanitize_key((string) $mode);
        $focus = trim(sanitize_text_field((string) $focus));
        $items = is_array($items) ? array_values(array_slice($items, 0, 12)) : [];

        $rows = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) { continue; }
            $title = '';
            foreach (['title', 'name', 'headline', 'query', 'searchTerm', 'inputUrlOrTerm', 'url'] as $key) {
                if (!empty($item[$key]) && is_scalar($item[$key])) { $title = (string) $item[$key]; break; }
            }
            $text = '';
            foreach (['text', 'description', 'snippet', 'summary', 'source', 'badge'] as $key) {
                if (!empty($item[$key]) && is_scalar($item[$key])) { $text = (string) $item[$key]; break; }
            }
            $url = '';
            foreach (['url', 'link', 'sourceUrl', 'adTransparencyUrl'] as $key) {
                if (!empty($item[$key]) && is_scalar($item[$key])) { $url = (string) $item[$key]; break; }
            }
            $title = trim(wp_strip_all_tags($title));
            $text = trim(wp_strip_all_tags($text));
            if ($title === '' && $text === '' && $url === '') { continue; }
            $rows[] = [
                'title' => self::short_text($title !== '' ? $title : ('Resultado ' . ($idx + 1)), 120),
                'text' => self::short_text($text, 220),
                'url' => esc_url_raw($url),
            ];
        }

        $top = array_slice($rows, 0, 6);
        $lines = [];
        $lines[] = '## Resumen ejecutivo';
        $lines[] = 'OpenAI devolvio una respuesta vacia para Google Intelligence, asi que se ha generado un analisis local de seguridad usando solo los datos scrapeados. La confianza es menor que un analisis AI completo, pero evita dejar al usuario sin salida.';
        $lines[] = '';
        $lines[] = '- Fuente: ' . ($module_label !== '' ? $module_label : $module_key) . '.';
        $lines[] = '- Resultados analizables: ' . count($rows) . '.';
        $lines[] = '- Modo solicitado: ' . ($mode !== '' ? $mode : 'summary') . '.';
        if ($focus !== '') { $lines[] = '- Foco del usuario: ' . self::short_text($focus, 180) . '.'; }
        $lines[] = '';
        $lines[] = '## Senales observadas';
        if (empty($top)) {
            $lines[] = '- No hay resultados suficientemente legibles para extraer patrones. Revisa el input, los filtros y la fuente seleccionada.';
        } else {
            foreach ($top as $row) {
                $line = '- ' . $row['title'];
                if ($row['text'] !== '') { $line .= ': ' . $row['text']; }
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = '## Oportunidades priorizadas';
        $lines[] = '- Validar manualmente los 3 resultados mas relevantes antes de convertirlos en brief o contenido.';
        $lines[] = '- Usar los titulos, preguntas, queries relacionadas o paginas detectadas como semillas para nuevos runs mas especificos.';
        $lines[] = '- Comparar esta fuente con al menos otra senal publica para evitar decisiones basadas en un unico scraper.';
        $lines[] = '';
        $lines[] = '## Riesgos o puntos ciegos';
        $lines[] = '- Este fallback no inventa metricas ni interpreta sentimiento profundo; solo resume senales visibles.';
        $lines[] = '- Si los resultados son pocos, puede ser un problema de filtros, pais, idioma, limite temporal o bloqueo del sitio.';
        $lines[] = '';
        $lines[] = '## Acciones recomendadas';
        $lines[] = '- Repetir el run con una query mas amplia si hay pocos resultados.';
        $lines[] = '- Ejecutar el mismo tema en Google Search, News y Trends para triangular demanda, narrativa y oportunidad.';
        if ($mode === 'content') {
            $lines[] = '- Convertir las mejores senales en 3 hooks, 1 brief corto y 1 angulo creativo para test.';
        }

        return implode("\n", $lines);
    }

    private static function short_text($text, $max = 180) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $max) { return $text; }
        return (function_exists('mb_substr') ? mb_substr($text, 0, max(0, $max - 3)) : substr($text, 0, max(0, $max - 3))) . '...';
    }

    private static function analysis_text_excerpt($value, $max = 1200) {
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $value = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value)));
        $max = max(80, (int) $max);
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($len <= $max) { return $value; }
        return (function_exists('mb_substr') ? mb_substr($value, 0, $max - 3, 'UTF-8') : substr($value, 0, $max - 3)) . '...';
    }

    private static function analysis_pick($item, $paths, $default = '') {
        foreach ((array) $paths as $path) {
            $value = function_exists('ves_arr_get') ? ves_arr_get($item, $path, null) : null;
            if ($value === null && is_array($item) && array_key_exists($path, $item)) { $value = $item[$path]; }
            if (is_scalar($value) && trim((string) $value) !== '') { return $value; }
            if (is_array($value) && !empty($value)) { return $value; }
        }
        return $default;
    }

    private static function analysis_compact_hashtags($item, $max = 20) {
        $hashtags = self::analysis_pick($item, ['hashtags', 'tags'], []);
        $out = [];
        foreach ((array) $hashtags as $tag) {
            if (is_array($tag)) { $tag = $tag['name'] ?? ($tag['title'] ?? ''); }
            $tag = trim(ltrim((string) $tag, '#'));
            if ($tag !== '') { $out[] = $tag; }
            if (count($out) >= $max) { break; }
        }
        return array_values(array_unique($out));
    }

    private static function analysis_compact_comments($item, $max = 30) {
        $sources = [
            self::analysis_pick($item, ['commentsList'], []),
            self::analysis_pick($item, ['comments'], []),
            self::analysis_pick($item, ['topComments'], []),
            self::analysis_pick($item, ['latestComments'], []),
        ];
        $out = [];
        foreach ($sources as $comments) {
            if (!is_array($comments)) { continue; }
            foreach ($comments as $comment) {
                if (is_array($comment)) {
                    $text = self::analysis_pick($comment, ['text', 'comment', 'body', 'message', 'content'], '');
                    $author = self::analysis_pick($comment, ['author', 'username', 'user.username', 'user.name', 'authorMeta.name'], '');
                    $likes = self::analysis_pick($comment, ['diggCount', 'likeCount', 'likes', 'votes'], 0);
                    $out[] = [
                        'author' => self::analysis_text_excerpt($author, 80),
                        'text' => self::analysis_text_excerpt($text, 360),
                        'likes' => is_numeric($likes) ? (int) $likes : 0,
                    ];
                } elseif (is_scalar($comment) && trim((string) $comment) !== '') {
                    $out[] = ['author' => '', 'text' => self::analysis_text_excerpt($comment, 360), 'likes' => 0];
                }
                if (count($out) >= $max) { break 2; }
            }
        }
        return $out;
    }

    private static function analysis_compact_structured_fields($item, $max = 40) {
        $item = is_array($item) ? $item : [];
        $priority = [
            'keyword','phrase','query','intent','avgMonthlySearches','searchVolume','search_volume','volume','monthlySearchVolume','lowCPC','highCPC','competitionValue','monetizationScore',
            'domain','url','website','websiteUrl','domainRating','domain_rating','domainAuthority','domain_authority','authority','authority_score','traffic','organicTraffic','backlinks',
            'format','creativeId','advertiserId','advertiserName','pageName','ad_archive_id','impressions','targetDomain','displayUrl',
            'locationCreated','country','language','textLanguage','searchQuery','sort','time','type','dataType','ves_enrichment_status','ves_enrichment_run_id','ves_comments_enriched_from_dataset'
        ];
        $out = [];
        foreach ($priority as $key) {
            $value = function_exists('ves_arr_get') ? ves_arr_get($item, $key, null) : ($item[$key] ?? null);
            if ($value === null || $value === '' || is_object($value)) { continue; }
            if (is_array($value)) {
                if (empty($value)) { continue; }
                $out[$key] = self::analysis_text_excerpt($value, 700);
            } elseif (is_scalar($value)) {
                $out[$key] = is_numeric($value) ? (0 + $value) : self::analysis_text_excerpt($value, 500);
            }
            if (count($out) >= $max) { break; }
        }
        return $out;
    }

    private static function build_social_evidence_pack($platform, $item, $caption_limit = 1800, $transcript_limit = 1600) {
        $platform = sanitize_key((string) $platform);
        $item = is_array($item) ? $item : [];
        $caption = ves_first_non_empty(
            ves_arr_get($item, 'caption_or_text', ''),
            ves_arr_get($item, 'ves_text', ''),
            ves_arr_get($item, 'text', ''),
            ves_arr_get($item, 'caption', ''),
            ves_arr_get($item, 'description', ''),
            ves_arr_get($item, 'message', '')
        );
        $transcript = ves_first_non_empty(
            ves_arr_get($item, 'transcript', ''),
            ves_arr_get($item, 'ves_transcript', ''),
            ves_arr_get($item, 'captionText', ''),
            ves_arr_get($item, 'subtitleText', '')
        );
        $url = ves_first_non_empty(
            ves_arr_get($item, 'url', ''), ves_arr_get($item, 'ves_url', ''), ves_arr_get($item, 'webVideoUrl', ''), ves_arr_get($item, 'link', ''), ves_arr_get($item, 'shareUrl', '')
        );
        $published_at = self::analysis_text_excerpt(ves_first_non_empty(ves_arr_get($item, 'published_at', ''), ves_arr_get($item, 'date', ''), ves_arr_get($item, 'ves_date', ''), ves_arr_get($item, 'createTimeISO', ''), ves_arr_get($item, 'createTime', '')), 80);
        $author_username = ves_first_non_empty(
            ves_arr_get($item, 'authorMeta.name', ''), ves_arr_get($item, 'author.username', ''), ves_arr_get($item, 'username', ''), ves_arr_get($item, 'ownerUsername', ''), ves_arr_get($item, 'ves_author', '')
        );
        $author_nickname = ves_first_non_empty(
            ves_arr_get($item, 'authorMeta.nickName', ''), ves_arr_get($item, 'authorMeta.nickname', ''), ves_arr_get($item, 'author.name', ''), ves_arr_get($item, 'ownerFullName', ''), ves_arr_get($item, 'ves_author', '')
        );
        $canonical_metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics($item) : [];
        foreach (['views', 'likes', 'comments', 'shares', 'saves'] as $k) {
            if (!array_key_exists($k, $canonical_metrics)) { $canonical_metrics[$k] = null; }
        }
        $canonical_metrics['engagement_visible'] = !empty($canonical_metrics['engagement_visible']);
        $views = $canonical_metrics['views'];
        $views_for_ratio = $views !== null ? max(1, (int) $views) : 0;
        $duration = ves_first_non_empty(ves_arr_get($item, 'metrics.duration', ''), ves_arr_get($item, 'ves_duration', ''), ves_arr_get($item, 'videoMeta.duration', ''), ves_arr_get($item, 'duration', ''));
        $inline_comments = self::analysis_compact_comments($item, 30);
        $comments_dataset_url = ves_first_non_empty(ves_arr_get($item, 'commentsDatasetUrl', ''), ves_arr_get($item, 'commentsDatasetURL', ''), ves_arr_get($item, 'commentsUrl', ''));
        $media_urls = self::analysis_pick($item, ['mediaUrls', 'videoMeta.downloadAddr', 'videoUrl', 'video.playAddr'], []);
        $media_count = is_array($media_urls) ? count($media_urls) : (trim((string) $media_urls) !== '' ? 1 : 0);
        $cover_url = self::analysis_text_excerpt(ves_first_non_empty(ves_arr_get($item, 'videoMeta.coverUrl', ''), ves_arr_get($item, 'videoMeta.originalCoverUrl', ''), ves_arr_get($item, 'ves_thumb', ''), ves_arr_get($item, 'coverUrl', '')), 500);
        $evidence_quality = [
            'caption' => trim((string) $caption) !== '',
            'metrics' => !empty($canonical_metrics['engagement_visible']),
            'comments' => !empty($inline_comments),
            'transcript' => trim((string) $transcript) !== '',
            'video_frames' => false,
            'audio' => false,
        ];
        $metrics_with_duration = $canonical_metrics;
        $metrics_with_duration['duration'] = $duration;
        $evidence_limitation = self::item_analysis_limitation_text($evidence_quality);
        return [
            'source' => $platform,
            'url' => self::analysis_text_excerpt($url, 500),
            'caption' => self::analysis_text_excerpt($caption, $caption_limit),
            'author' => self::analysis_text_excerpt(ves_first_non_empty($author_username, $author_nickname), 160),
            'published_at' => $published_at,
            'metrics' => $metrics_with_duration,
            'comments_sample' => $inline_comments,
            'transcript' => trim((string) $transcript) !== '' ? self::analysis_text_excerpt($transcript, $transcript_limit) : null,
            'video_frames' => [],
            'audio_transcript' => null,
            'evidence_quality' => $evidence_quality,
            'evidence_limitation' => $evidence_limitation,
            'platform' => $platform,
            'post' => [
                'id' => self::analysis_text_excerpt(ves_first_non_empty(ves_arr_get($item, 'id', ''), ves_arr_get($item, 'videoId', ''), ves_arr_get($item, 'postId', '')), 120),
                'url' => self::analysis_text_excerpt($url, 500),
                'caption_or_text' => self::analysis_text_excerpt($caption, $caption_limit),
                'language' => self::analysis_text_excerpt(ves_first_non_empty(ves_arr_get($item, 'textLanguage', ''), ves_arr_get($item, 'lang', ''), ves_arr_get($item, 'language', '')), 40),
                'published_at' => $published_at,
                'search_query_that_found_it' => self::analysis_text_excerpt(ves_arr_get($item, 'searchQuery', ''), 160),
                'location_created' => self::analysis_text_excerpt(ves_arr_get($item, 'locationCreated', ''), 40),
                'hashtags' => self::analysis_compact_hashtags($item, 20),
                'mentions' => array_slice((array) self::analysis_pick($item, ['mentions', 'detailedMentions'], []), 0, 10),
                'is_ad' => !empty($item['isAd']) || !empty($item['isSponsored']),
                'is_pinned' => !empty($item['isPinned']),
            ],
            'video' => [
                'cover_url' => $cover_url,
                'width' => ves_arr_get($item, 'videoMeta.width', ''),
                'height' => ves_arr_get($item, 'videoMeta.height', ''),
                'definition' => self::analysis_text_excerpt(ves_arr_get($item, 'videoMeta.definition', ''), 80),
                'format' => self::analysis_text_excerpt(ves_arr_get($item, 'videoMeta.format', ''), 40),
                'transcript_or_subtitle' => trim((string) $transcript) !== '' ? self::analysis_text_excerpt($transcript, $transcript_limit) : null,
                'video_frames' => [],
                'audio_transcript' => null,
                'media_asset_count' => $media_count,
                'music' => [],
                'visual_pixels_analyzed' => false,
                'visual_analysis_note' => 'El análisis visual frame-by-frame estará disponible en Deep Video Analysis.',
                'audio_analysis_note' => 'No se realizó análisis de audio.',
            ],
            'profile' => [
                'username' => self::analysis_text_excerpt($author_username, 120),
                'nickname' => self::analysis_text_excerpt($author_nickname, 160),
                'profile_url' => self::analysis_text_excerpt(ves_first_non_empty(ves_arr_get($item, 'authorMeta.profileUrl', ''), ves_arr_get($item, 'profileUrl', ''), ves_arr_get($item, 'author.url', '')), 500),
                'verified' => (bool) ves_arr_get($item, 'authorMeta.verified', false),
                'private_account' => (bool) ves_arr_get($item, 'authorMeta.privateAccount', false),
                'followers' => ves_normalize_number(ves_arr_get($item, 'authorMeta.fans', ves_arr_get($item, 'authorMeta.followers', ves_arr_get($item, 'followersCount', 0)))),
                'following' => ves_normalize_number(ves_arr_get($item, 'authorMeta.following', 0)),
                'total_hearts' => ves_normalize_number(ves_arr_get($item, 'authorMeta.heart', 0)),
                'video_count' => ves_normalize_number(ves_arr_get($item, 'authorMeta.video', 0)),
                'bio_or_signature' => self::analysis_text_excerpt(ves_arr_get($item, 'authorMeta.signature', ''), 500),
            ],
            'comments' => [
                'declared_comment_count' => $canonical_metrics['comments'],
                'inline_sample_size' => count($inline_comments),
                'comments_dataset_url_available' => trim((string) $comments_dataset_url) !== '',
                'comments_dataset_url' => self::analysis_text_excerpt($comments_dataset_url, 500),
                'sample' => $inline_comments,
            ],
            'search_relevance' => [
                'score' => ves_arr_get($item, 'ves_relevance_score', null),
                'class' => self::analysis_text_excerpt(ves_arr_get($item, 'ves_relevance_class', ''), 80),
                'label' => self::analysis_text_excerpt(ves_arr_get($item, 'ves_relevance_label', ''), 160),
                'reasons' => array_slice((array) ves_arr_get($item, 'ves_relevance_reasons', []), 0, 8),
                'explanation' => self::analysis_text_excerpt(ves_arr_get($item, 'ves_relevance_explanation', ''), 500),
            ],
            'structured_source_fields' => self::analysis_compact_structured_fields($item, 40),
            'data_availability' => [
                'caption_available' => $evidence_quality['caption'],
                'transcript_available' => $evidence_quality['transcript'],
                'profile_metadata_available' => !empty($author_username) || !empty($author_nickname),
                'comment_sample_available' => $evidence_quality['comments'],
                'comments_dataset_available' => trim((string) $comments_dataset_url) !== '',
                'cover_available' => trim((string) $cover_url) !== '',
                'video_frames_available' => false,
                'audio_available' => false,
                'selected_item_enrichment_status' => self::analysis_text_excerpt(ves_arr_get($item, 'ves_enrichment_status', ''), 80),
                'selected_item_enrichment_run_id' => self::analysis_text_excerpt(ves_arr_get($item, 'ves_enrichment_run_id', ''), 120),
                'comments_fetched_from_dataset' => !empty($item['ves_comments_enriched_from_dataset']),
                'missing_analysis_inputs_warning' => $evidence_limitation,
            ],
        ];
    }



    private static function output_language_label($language) {
        $language = sanitize_key((string) $language);
        $map = [
            'es' => 'Spanish',
            'en' => 'English',
            'fa' => 'Persian (Farsi)',
        ];
        return $map[$language] ?? 'Spanish';
    }

    private static function output_language_instruction($language) {
        return 'Respond entirely in ' . self::output_language_label($language) . '. Use clear headings, short paragraphs and actionable bullets.';
    }

    public static function analyze_google_intelligence($module_key, $module_label, $items, $mode = 'summary', $focus = '', $project_context = null, $related_memory = [], $output_language = 'es') {
        $module_key = sanitize_key((string) $module_key);
        $module_label = sanitize_text_field((string) $module_label);
        $mode = sanitize_key((string) $mode);
        if (!in_array($mode, ['summary', 'opportunities', 'content'], true)) {
            $mode = 'summary';
        }

        $items = is_array($items) ? array_values(array_slice($items, 0, 20)) : [];
        if (empty($items)) {
            return new WP_Error('missing_google_items', 'No hay resultados suficientes para analizar.');
        }

        $mode_labels = [
            'summary' => 'resumen ejecutivo con patrones, señales útiles y limitaciones',
            'opportunities' => 'oportunidades, gaps competitivos, riesgos y próximos pasos priorizados',
            'content' => 'briefs, ángulos de contenido, hooks, mensajes y formatos accionables',

        ];
        $focus = trim((string) $focus);
        if (strlen($focus) > 500) {
            $focus = substr($focus, 0, 500);
        }

        $model = VES_Config::get_openai_model('fast_analysis');
        $lang_instruction = self::output_language_instruction($output_language);
        $instructions = 'Eres un senior marketing intelligence analyst. ' . $lang_instruction . ' Analiza resultados de Google Intelligence con rigor: separa evidencia directa de inferencia, no inventes métricas, no sobreinterpretes datos débiles y convierte señales públicas en decisiones de marketing. El modo solicitado es: ' . $mode_labels[$mode] . '.';
        if ($focus !== '') {
            $instructions .= ' Foco prioritario del usuario: ' . $focus . '.';
        }
        $instructions .= ' Estructura la respuesta con secciones claras: 1) Resumen ejecutivo, 2) Señales observadas, 3) Implicaciones de negocio/marketing, 4) Oportunidades priorizadas, 5) Riesgos o puntos ciegos, 6) Acciones recomendadas. Si el modo es content, añade hooks, briefs y formatos. Usa memoria/proyecto sólo cuando ayude; explica si la evidencia actual confirma, debilita o contradice patrones previos; distingue hallazgos nuevos de repetidos; no reutilices supuestos rechazados u obsoletos; separa hechos, evidencia, hipótesis y recomendaciones.';

        $request_body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => "Google Intelligence payload:\n" . wp_json_encode([
                'module' => $module_key,
                'module_label' => $module_label,
                'analysis_mode' => $mode,
                'focus' => $focus,
                'items' => $items,
                'project_context' => self::normalize_project_context($project_context),
                'related_project_memory' => self::normalize_related_memory($related_memory),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => 3200,

            'store' => false,

        ];
        if (self::is_gpt5_family($model)) {

            $request_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }

        $json = self::request_responses_api($request_body);
        if (is_wp_error($json)) {
            return $json;
        }
        $analysis = self::extract_output_text($json);
        if ($analysis === '') {
            $retry_payload = self::retry_empty_text_response($request_body, $json, 'google_intelligence');
            if (!is_wp_error($retry_payload) && is_array($retry_payload)) {
                $analysis = self::extract_output_text($retry_payload);
                $json = $retry_payload;
            } elseif (is_wp_error($retry_payload)) {
                return $retry_payload;
            }
        }
        if ($analysis === '') {
            $analysis = self::fallback_google_intelligence_analysis($module_key, $module_label, $items, $mode, $focus);
            return [
                'analysis' => $analysis,
                'model' => $model,
                'model_label' => 'Análisis local de respaldo', // v0.9.31.5: fallback label audit
                'analysis_source' => 'local_fallback', // v0.9.31.5: fallback label audit
                'usage' => is_array($json) ? ($json['usage'] ?? null) : null,
                'format' => 'local_fallback',
                'fallback' => true, // v0.9.31.5: fallback label audit
                'fallback_reason' => 'empty_ai_output', // v0.9.31.5: fallback label audit
            ];
        }
        return [
            'analysis' => $analysis,
            'model' => $model,
            'usage' => is_array($json) ? ($json['usage'] ?? null) : null,

        ];
    }

    public static function refine_input_text($text, $module = '', $field = '', $context = []) {
        $text = trim((string) $text);
        if ($text === '') {
            return new WP_Error('empty_input', 'No hay texto para mejorar.');
        }
        $module = sanitize_key((string) $module);
        $field = sanitize_key((string) $field);
        $context = is_array($context) ? $context : [];
        $raw_field = isset($context['field_name']) ? sanitize_key((string) $context['field_name']) : '';
        $field_compact = strtolower(preg_replace('/[^a-z0-9]/', '', (string) ($field ?: $raw_field)));
        $field_aliases = [
            'seedkeyword' => 'seo_keyword_seed',
            'seoseedkeyword' => 'seo_keyword_seed',
            'keywordseed' => 'seo_keyword_seed',
            'queryterms' => 'scraper_query',
            'scraperqueryterms' => 'scraper_query',
            'searchterms' => 'scraper_query',
            'tiktokquery' => 'scraper_query',
            'hashtags' => 'hashtag',
            'instagramhashtag' => 'hashtag',
            'businessobjective' => 'business_objective',
            'campaigngoal' => 'business_objective',
            'searchcontext' => 'search_context',
            'context' => 'search_context',
            'aianalysisgoal' => 'ai_analysis_goal',
            'analysisgoal' => 'ai_analysis_goal',
            'analysisfocus' => 'ai_analysis_goal',
            'profileurl' => 'profile_url',
            'profileurls' => 'profile_url',
            'posturl' => 'post_url',
            'posturls' => 'post_url',
            'targeturls' => 'post_url',
            'competitorlist' => 'competitor_list',
            'competitordomains' => 'competitor_list',
            'targetaudience' => 'target_audience',
            'linkedinrole' => 'linkedin_role',
            'linkedinroles' => 'linkedin_role',
            'linkedincompanyfilter' => 'linkedin_company_filter',
            'companyfilter' => 'linkedin_company_filter',
        ];
        if (isset($field_aliases[$field_compact])) {
            $field = $field_aliases[$field_compact];
        }
        $model = VES_Config::get_openai_model('fast_analysis');
        $field_rules = [
            'seo_keyword_seed' => 'Return 3 to 10 short SEO seed keyword candidates, one per line. No full sentences. Examples: seo tools, ai seo tools, herramientas seo.',
            'seedkeyword' => 'Return 3 to 10 short SEO seed keyword candidates, one per line. No full sentences.',
            'scraper_query' => 'Return short source-specific query terms only. No campaign objective sentences.',
            'hashtag' => 'Return clean hashtags only, one per line. No explanations.',
            'business_objective' => 'Return one clear business objective sentence. Do not make it a provider query.',
            'search_context' => 'Return a concise context paragraph for AI interpretation. Do not make it a provider query.',
            'ai_analysis_goal' => 'Return one clear instruction for the AI analysis step after data collection. Do not make it a provider query.',
            'profile_url' => 'Return profile handles or profile URLs only, one per line. No prose.',
            'post_url' => 'Return valid public post URLs only, one per line. No prose.',
            'competitor_list' => 'Return competitor domains or company names only, one per line. No explanation.',
            'target_audience' => 'Return 2 to 5 concise audience presets, one per line. No campaign objective prose.',
            'linkedin_role' => 'Return role/seniority presets, not prose.',
            'linkedin_company_filter' => 'Return company filter presets, not prose.',
        ];
        $rule = $field_rules[$field] ?? 'Return a clearer, field-appropriate input. Keep provider-facing queries short and keep objectives/context separate.';
        $instructions = 'You are a senior marketing intelligence operator for a WordPress + OpenAI + Apify SaaS. Improve the user input for its specific field type. ' . $rule . ' Preserve the user language when reasonable. Do not invent brand facts, dates, credentials, private data, or results. Return only the improved text, no markdown fences, no quotes.';
        $payload = [
            'module' => $module,
            'field' => $field,
            'platform' => isset($context['platform']) ? sanitize_key((string) $context['platform']) : '',
            'original_text' => $text,
        ];
        $body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => 220,
            'store' => false,
            '_ves_purpose' => 'fast_analysis',
        ];
        if (self::is_gpt5_family($model)) {
            $body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }
        $json = self::request_responses_api($body);
        if (is_wp_error($json)) {
            return $json;
        }
        $out = trim((string) self::extract_output_text($json));
        $out = preg_replace('/^```(?:text)?|```$/m', '', $out);
        $out = trim($out, " \t\n\r\0\x0B\"'“”‘’");
        if ($out === '') {
            return new WP_Error('empty_ai_output', 'OpenAI no devolvió una mejora utilizable.');
        }
        if (function_exists('mb_strlen') && mb_strlen($out, 'UTF-8') > 700) {
            $out = mb_substr($out, 0, 700, 'UTF-8');
        } elseif (strlen($out) > 1200) {
            $out = substr($out, 0, 1200);
        }
        return [
            'improved_text' => sanitize_textarea_field($out),
            'model' => $model,
            'usage' => is_array($json) ? ($json['usage'] ?? null) : null,
        ];
    }

    public static function analyze_item($platform, $item, $focus = '', $related_memory = [], $project_context = null, $output_language = 'es') {
        $model = VES_Config::get_openai_model('fast_analysis');
        $focus = trim((string) $focus);
        $payload_item = self::build_social_evidence_pack($platform, $item, 2200, 1800);
        $normalized_memory = self::filter_related_memory_for_item(self::normalize_related_memory($related_memory), $platform, $payload_item);

        $lang_instruction = self::output_language_instruction($output_language);
        $instructions = 'Eres un CMO analítico y data scientist de contenido social. ' . $lang_instruction . ' Devuelve EXCLUSIVAMENTE JSON válido con el schema solicitado. No uses markdown fuera del JSON. Prioriza la evidencia del item actual por encima de memoria o contexto. La memoria solo puede usarse si es claramente relevante; si contradice el item actual, indica el conflicto. Distingue hechos observados, señales, hipótesis y recomendaciones. No inventes datos. Usa current_item.evidence_quality como contrato estricto: si video_frames=false, no hagas claims visuales/frame-by-frame; si audio=false, no hagas claims de sonido/música/voz; si transcript=false, no hagas análisis de transcript/subtítulos; si comments=false, cualquier inferencia de audiencia debe ser cautelosa. Copia las métricas normalizadas; no inventes ni sustituyas métricas. No llames un ratio "muy bueno", "altísimo" o similar si no hay benchmark. Usa lenguaje prudente: "dentro de esta muestra", "señal potencial" y "requiere benchmark". recommendations debe contener solo acciones. limitations debe contener solo limitaciones. ab_tests nunca debe estar vacío; si la evidencia es insuficiente, devuelve un plan de validación estructurado. Incluye evidence_quality y una limitación explícita: "Análisis basado en caption y métricas visibles. No se realizó análisis visual frame-by-frame ni análisis de audio." o, si hay comentarios, "Análisis basado en caption, métricas visibles y muestra de comentarios. No se realizó análisis visual frame-by-frame ni análisis de audio."';
        if ($focus !== '') {
            $instructions .= ' Prioriza especialmente este foco: ' . sanitize_text_field($focus) . '.';
        }

        $request_body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => "Contenido a analizar:\n" . wp_json_encode([
                'current_item' => $payload_item,
                'project_context' => self::normalize_project_context($project_context),
                'related_project_memory' => $normalized_memory,
                'memory_rule' => 'Current item evidence has priority. Use memory only when same workspace/module/topic relevance is clear. Do not import unrelated SEO/Semrush assumptions into Social/TikTok analysis.',
                'required_output_language' => self::output_language_label($output_language),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => 4200,
            'text' => ['format' => self::build_item_analysis_response_format()],
            'store' => false,
            '_ves_purpose' => 'deep_analysis',
        ];
        if (self::is_gpt5_family($model)) {
            $request_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }

        $json = self::request_responses_api($request_body);
        if (is_wp_error($json)) {
            if (self::should_use_local_analysis_fallback($json)) {
                $structured = self::build_local_item_analysis_structured_fallback($payload_item, $focus, $json);
                return [
                    'analysis' => self::format_item_analysis_text_from_structured($structured),
                    'analysis_structured' => $structured,
                    'model' => $model,
                    'model_label' => 'Análisis local de respaldo',
                    'analysis_source' => 'local_fallback',
                    'fallback' => true,
                    'fallback_reason' => $json->get_error_code(),
                ];
            }
            return $json;
        }

        $structured = self::extract_output_json($json);
        if (is_array($structured) && !empty($structured)) {
            $structured = self::normalize_item_analysis_structured($structured, $payload_item, $output_language);
            return [
                'analysis' => self::format_item_analysis_text_from_structured($structured),
                'analysis_structured' => $structured,
                'model' => $model,
                'model_label' => $model,
                'analysis_source' => 'ai_structured',
                'usage' => is_array($json) ? ($json['usage'] ?? null) : null,
            ];
        }

        $analysis = self::extract_output_text($json);
        if ($analysis === '') {
            $retry_payload = self::retry_empty_text_response($request_body, $json, 'item_analysis');
            if (!is_wp_error($retry_payload) && is_array($retry_payload)) {
                $structured = self::extract_output_json($retry_payload);
                if (is_array($structured) && !empty($structured)) {
                    $structured = self::normalize_item_analysis_structured($structured, $payload_item, $output_language);
                    return [
                        'analysis' => self::format_item_analysis_text_from_structured($structured),
                        'analysis_structured' => $structured,
                        'model' => $model,
                        'model_label' => $model,
                        'analysis_source' => 'ai_structured',
                        'usage' => $retry_payload['usage'] ?? null,
                    ];
                }
                $analysis = self::extract_output_text($retry_payload);
                $json = $retry_payload;
            } elseif (is_wp_error($retry_payload)) {
                return $retry_payload;
            }
        }

        if ($analysis === '') {
            $details = self::describe_empty_response($json);
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_empty', 'La respuesta del modelo llegó vacía.', ['model' => $model, 'details' => $details]);
            }
            $structured = self::build_local_item_analysis_structured_fallback($payload_item, $focus, new WP_Error('empty_ai_output', $details));
            return [
                'analysis' => self::format_item_analysis_text_from_structured($structured),
                'analysis_structured' => $structured,
                'model' => $model,
                'model_label' => 'Análisis local de respaldo',
                'analysis_source' => 'local_fallback',
                'fallback' => true,
                'fallback_reason' => 'empty_ai_output',
            ];
        }

        $structured = self::build_local_item_analysis_structured_fallback($payload_item, $focus, null);
        $structured['limitations'][] = 'El modelo no devolvió JSON estructurado; se conserva una lectura textual como fallback.';
        return [
            'analysis' => $analysis,
            'analysis_structured' => $structured,
            'model' => $model,
            'model_label' => 'Texto AI + métricas locales',
            'analysis_source' => 'ai_text_with_local_metrics',
            'usage' => is_array($json) ? ($json['usage'] ?? null) : null,
        ];
    }

    public static function analyze_items($platform, $items, $focus = '', $related_memory = [], $project_context = null, $output_language = 'es') {
        $model = VES_Config::get_openai_model('fast_analysis');
        $platform = sanitize_key((string) $platform);
        $focus = trim((string) $focus);
        $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
        $items = array_slice($items, 0, 12);
        if (empty($items)) {
            return new WP_Error('empty_items', 'No hay contenidos seleccionados para analizar.');
        }

        $item_count = max(1, count($items));
        $caption_limit = $item_count > 6 ? 900 : ($item_count > 3 ? 1200 : 1600);
        $transcript_limit = $item_count > 6 ? 700 : ($item_count > 3 ? 900 : 1200);

        $payload_items = [];
        foreach ($items as $idx => $item) {
            $compact = self::build_social_evidence_pack($platform, $item, $caption_limit, $transcript_limit);
            $compact['index'] = $idx + 1;
            $payload_items[] = $compact;
        }

        $lang_instruction = self::output_language_instruction($output_language);
        // v0.9.25.0 — Phase 5: clarify selected-vs-memory count.
        // Previously the AI would conflate related_project_memory items with
        // selected_items and say "6 elementos analizados" when the user had
        // only chosen 1. Force an explicit count and an explicit role for
        // memory ("background only, not evidence to summarize").
        $selected_count_clause = sprintf(
            'IMPORTANTE: el usuario ha seleccionado EXACTAMENTE %d ítem%s para este análisis. '
          . 'project_context y related_project_memory son CONTEXTO de fondo, NO son ítems seleccionados. '
          . 'Cuando te refieras a "ítems analizados", "selecciones" o "piezas comparadas", usa siempre el número %d. '
          . 'No sumes la memoria relacionada al conteo. ',
            $item_count,
            $item_count === 1 ? '' : 's',
            $item_count
        );
        $instructions = 'Eres un CMO analítico y data scientist de contenido social. ' . $lang_instruction . ' ' . $selected_count_clause . 'Analiza los ítems seleccionados con una lectura comparativa profunda. Usa caption, transcript si existe, métricas, perfil, comentarios disponibles, señales de búsqueda y contexto/memoria. No inventes datos; cuando falten comments/transcript/visual frames, indícalo y ajusta confidence. Entrega: 1) resumen ejecutivo comparativo, 2) score de calidad de señal por pieza, 3) performance reading con ratios, 4) anatomía de hook/narrativa/sonido/CTA, 5) audiencia y comentarios, 6) patrones ganadores y riesgos, 7) implicación para marca/mercado, 8) oportunidades de contenido similar, 9) brief creativo reutilizable, 10) plan de A/B tests priorizado con hipótesis y métrica objetivo. Separa evidencia, inferencia, hipótesis y recomendación. Formatea las métricas como filas legibles "- Métrica: valor" — NO uses tablas con pipes "|".';
        if ($focus !== '') {
            $instructions .= ' Prioriza especialmente este foco: ' . $focus . '.';
        }

        $request_body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => "Contenidos seleccionados para análisis comparativo:\n" . wp_json_encode([
                'selected_items_count' => $item_count,
                'selected_items' => $payload_items,
                'project_context_role' => 'background_only_not_evidence',
                'project_context' => self::normalize_project_context($project_context),
                'related_project_memory_role' => 'background_only_not_evidence',
                'related_project_memory' => self::normalize_related_memory($related_memory),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => max(2200, min(4200, 1600 + (count($payload_items) * 450))),
            'store' => false,
            // Bug fix: batch analysis can request up to 4200 output tokens; use the
            // deep_analysis timeout (55 s) so it does not time out on fast_analysis (30 s).
            '_ves_purpose' => 'deep_analysis',
        ];
        if (self::is_gpt5_family($model)) {
            $request_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
        }

        $json = self::request_responses_api($request_body);
        if (is_wp_error($json)) {
            if (self::should_use_local_analysis_fallback($json)) {
                return [
                    'analysis' => self::build_local_batch_analysis_fallback($platform, $payload_items, $focus, $json),
                    'model' => $model,
                    'model_label' => 'Análisis local de respaldo',
                    'analysis_source' => 'local_fallback',
                    'usage' => null,
                    'selected_items' => count($payload_items),
                    'fallback' => true,
                    'fallback_reason' => $json->get_error_code(),
                ];
            }
            return $json;
        }
        $analysis = self::extract_output_text($json);
        if ($analysis === '') {
            $retry_payload = self::retry_empty_text_response($request_body, $json, 'item_batch_analysis');
            if (!is_wp_error($retry_payload) && is_array($retry_payload)) {
                $analysis = self::extract_output_text($retry_payload);
                $json = $retry_payload;
            } elseif (is_wp_error($retry_payload)) {
                return $retry_payload;
            }
        }
        if ($analysis === '') {
            $details = self::describe_empty_response($json);
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_empty_batch', 'La respuesta comparativa del modelo llegó vacía.', ['model' => $model, 'details' => $details]);
            }
            return new WP_Error('invalid_response', 'OpenAI devolvió una respuesta comparativa sin texto utilizable. Detalle técnico: ' . $details . '.');
        }
        return [
            'analysis' => $analysis,
            'model' => $model,
            'usage' => is_array($json) ? ($json['usage'] ?? null) : null,
            'selected_items' => count($payload_items),
        ];
    }


    private static function fallback_label_is_valid($value, $field = '') {
        if (class_exists('VES_Trend_Label_Guard')) {
            return VES_Trend_Label_Guard::is_safe_label($value, $field);
        }
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '' || preg_match('/^\d+(?:[\.,]\d+)?\s*[kmb]?$/i', $value)) { return false; }
        if (preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL)) { return false; }
        return (bool) preg_match('/[\p{L}#@]/u', $value);
    }

    private static function fallback_item_label($item, $source_key = '') {
        if (!is_array($item)) { return ''; }
        $source_key = sanitize_key((string) $source_key);
        $field_map = [
            'google_trends' => ['trend','query','keyword','searchTerm','title'],
            'youtube_trending' => ['title','videoTitle','name'],
            'tiktok_trends' => ['title','caption','text','hashtag','name'],
            'twitter_trends' => ['trend','hashtag','name','title','query'],
        ];
        $fields = $field_map[$source_key] ?? ['trend','name','title','hashtag','keyword','searchTerm','query','caption','videoTitle'];
        foreach ($fields as $key) {
            if (!empty($item[$key]) && is_scalar($item[$key])) {
                $value = trim((string) $item[$key]);
                if (self::fallback_label_is_valid($value, $key)) { return $value; }
            }
        }
        return '';
    }

    private static function fallback_item_metrics($item) {
        if (!is_array($item)) {
            return [];
        }
        $parts = [];
        foreach (['views' => 'views', 'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'volume' => 'volume', 'rank' => 'rank'] as $key => $label) {
            if (isset($item[$key]) && $item[$key] !== '' && $item[$key] !== null) {
                $parts[] = $label . ': ' . sanitize_text_field((string) $item[$key]);
            }
        }
        if (!empty($item['url']) && is_scalar($item['url'])) {
            $parts[] = 'url: ' . esc_url_raw((string) $item['url']);
        }
        return $parts;
    }

    public static function build_local_trend_analysis_fallback($request, $compact_sources, $error = null) {
        $request = is_array($request) ? $request : [];
        $compact_sources = is_array($compact_sources) ? $compact_sources : [];
        $topic = sanitize_text_field((string) ($request['topic'] ?? 'tema solicitado'));
        $reason = sanitize_textarea_field((string) ($request['reason'] ?? ''));
        $country_label = function_exists('ves_trend_label_country') ? ves_trend_label_country($request['country'] ?? 'None') : (string) ($request['country'] ?? 'None');
        $duration_label = function_exists('ves_trend_duration_label') ? ves_trend_duration_label($request['duration'] ?? '7d') : (string) ($request['duration'] ?? '7d');
        // v0.9.24.6: Scrub internal details (API keys, URLs) from the error note
        // before it gets embedded in the analysis that users may see.
        $raw_error_msg = is_wp_error($error) ? $error->get_error_message() : '';
        $error_note = $raw_error_msg !== '' ? self::scrub_diagnostic_text($raw_error_msg) : '';

        $channel_readouts = [];
        $trends = [];
        $seen = [];
        foreach ($compact_sources as $source_key => $source) {
            if (!is_array($source)) {
                continue;
            }
            $label = sanitize_text_field((string) ($source['label'] ?? $source_key));
            $status = sanitize_text_field((string) ($source['status'] ?? 'UNKNOWN'));
            $items = is_array($source['items'] ?? null) ? $source['items'] : (is_array($source['compact_items'] ?? null) ? $source['compact_items'] : []);
            $highlights = is_array($source['highlights'] ?? null) ? $source['highlights'] : [];
            $item_count = (int) ($source['item_count'] ?? count($items));
            $notes = sanitize_text_field((string) ($source['notes'] ?? ''));
            $examples = [];
            foreach (array_slice($highlights, 0, 4) as $highlight) {
                if (is_scalar($highlight) && trim((string) $highlight) !== '') {
                    $examples[] = sanitize_text_field((string) $highlight);
                }
            }
            foreach (array_slice($items, 0, 4) as $item) {
                $candidate = self::fallback_item_label($item, $source_key);
                if ($candidate !== '') {
                    $examples[] = sanitize_text_field($candidate);
                }
            }
            $examples = array_values(array_unique(array_slice($examples, 0, 6)));
            $usable = ($status === 'SUCCEEDED' && $item_count > 0);
            $channel_readouts[] = [
                'channel' => $label,
                'status' => $status,
                'what_stands_out' => $usable ? ('Se recuperaron ' . $item_count . ' señales. La lectura debe tratarse como direccional porque el análisis AI estructurado no devolvió salida usable.') : 'La fuente no entregó señales suficientes para una lectura sólida.',
                'confidence' => $usable ? 'media' : 'baja',
                'limitation' => $notes !== '' ? $notes : 'Fallback local activado: no hubo interpretación avanzada del modelo.',
                'relevance_to_goal' => $reason !== '' ? ('Debe evaluarse contra el objetivo declarado: ' . $reason) : ('Debe evaluarse contra el tema solicitado: ' . $topic),
                'evidence_examples' => $examples,
            ];

            if (!$usable) {
                continue;
            }
            foreach (array_slice($items, 0, 8) as $item) {
                $name = self::fallback_item_label($item, $source_key);
                if ($name === '') {
                    continue;
                }
                $key = strtolower($label . '|' . $name);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $evidence = self::fallback_item_metrics($item);
                if (empty($evidence)) {
                    $evidence = ['Señal observada en ' . $label];
                }
                $name_l = strtolower($name);
                $topic_l = strtolower($topic);
                $direct = ($topic_l !== '' && strpos($name_l, $topic_l) !== false);
                $fit = $direct ? 7 : 5;
                if (count($trends) < 8) {
                    $trends[] = [
                        'trend_name' => sanitize_text_field($name),
                        'primary_channel' => $label,
                        'fit_score' => $fit,
                        'fit_reason' => $direct ? 'La señal contiene directamente el tema solicitado.' : 'Señal adyacente detectada; requiere validación antes de convertirla en campaña.',
                        'signal_type' => sanitize_text_field((string) ($item['type'] ?? ($source_key === 'google_trends' ? 'search_trend' : 'social_trend'))),
                        'evidence_strength' => $direct ? 'media' : 'baja-media',
                        'audience_or_context' => 'Mercado: ' . sanitize_text_field((string) $country_label) . '. Ventana: ' . sanitize_text_field((string) $duration_label) . '.',
                        'activation_window' => 'short_term_test',
                        'recommended_action' => 'Usar como hipótesis de contenido o research adicional, no como decisión estratégica cerrada.',
                        'growth_drivers' => ['Visibilidad reciente en la fuente', 'Posible conversación cultural o búsqueda emergente'],
                        'evidence' => array_values(array_slice($evidence, 0, 6)),
                        'content_actions' => ['Crear un micro-brief de validación', 'Comparar la señal con competidores y comentarios reales', 'Probar 2-3 hooks antes de producir una pieza grande'],
                        'risk_note' => 'La lectura viene de fallback local; falta interpretación semántica avanzada del modelo.',
                        'counterargument' => 'Puede ser ruido de plataforma o tendencia amplia sin encaje real con la marca.',
                    ];
                }
            }
        }

        if (empty($trends)) {
            $trends[] = [
                'trend_name' => $topic !== '' ? $topic : 'Trend Finder sin señales suficientes',
                'primary_channel' => 'system',
                'fit_score' => 3,
                'fit_reason' => 'No se recuperaron señales suficientes o el modelo no devolvió análisis estructurado.',
                'signal_type' => 'insufficient_signal',
                'evidence_strength' => 'baja',
                'audience_or_context' => 'Mercado: ' . sanitize_text_field((string) $country_label) . '. Ventana: ' . sanitize_text_field((string) $duration_label) . '.',
                'activation_window' => 're-run_required',
                'recommended_action' => 'Repetir la búsqueda con un tema más específico o revisar la configuración de los actores.',
                'growth_drivers' => [],
                'evidence' => [],
                'content_actions' => ['Cambiar query', 'Reducir país/categoría', 'Revisar actor fallido'],
                'risk_note' => 'Insuficiente evidencia para recomendar producción.',
                'counterargument' => 'La ausencia de señal puede venir de fallo técnico o de una query demasiado amplia.',
            ];
        }

        $structured = [
            'fallback_used' => true,
            'confidence_mode' => 'diagnostic_fallback',
            'validation_required' => true,
            'can_generate_brief' => false,
            'memory_type' => 'fallback_diagnostic',
            'report_status' => 'fallback_diagnostic',
            'executive_summary' => 'Diagnostic fallback only: el análisis AI estructurado no devolvió una salida usable. Esta lectura local conserva evidencia parcial, pero no es insight estratégico validado ni debe usarse para decisiones de campaña todavía.',
            'overall_take' => 'Do not use for campaign decision yet. Valida evidencia seleccionada, corrige fuentes fallidas y relanza AI analysis antes de convertir esto en brief normal.',
            'data_quality_summary' => [
                'overall_confidence' => 'diagnostic_fallback',
                'direct_topic_signal' => 'Variable según fuente; revisar prioritized_trends.',
                'what_is_inference_vs_evidence' => 'La evidencia son los items recuperados. La interpretación estratégica es fallback local y por tanto limitada.',
                'main_blindspots' => array_values(array_filter(['OpenAI no devolvió JSON usable.', $error_note, 'Algunas fuentes pueden haber fallado o entregado datos parciales.'])),
            ],
            'channel_readouts' => $channel_readouts,
            'prioritized_trends' => $trends,
            'false_positives_and_risks' => ['Fallback local con menor capacidad semántica.', 'Las tendencias de plataforma pueden ser ruido si no conectan con el objetivo de marca.', 'Conviene validar con social search/comment analysis antes de producir activos grandes.'],
            'production_recommendations' => [
                'strategic_principles' => ['No producir desde datos crudos sin validación.', 'Priorizar señales con puente claro hacia el objetivo del usuario.', 'Tratar fuentes parciales como hipótesis, no como prueba.'],
                'angles' => ['Angle de validación rápida basado en la señal más repetida.', 'Angle comparativo: lo que la categoría está ignorando.', 'Angle educativo si el tema tiene intención de búsqueda.'],
                'formats' => ['short-form test', 'carousel insight', 'brief interno', 'competitor check'],
                'hooks' => ['Lo que la conversación está empezando a mostrar', 'La señal débil que vale la pena validar', 'Antes de producir, mira este patrón'],
                'first_pieces_to_produce' => ['1 brief de validación con 3 hooks y KPI de retención/interacción.', '1 post corto basado en la señal con mayor fit_score.', '1 análisis comparativo manual de competidores.'],
                'measurement_notes' => ['Medir engagement inicial, saves/shares, comentarios cualitativos y coherencia con objetivo de campaña.'],
            ],
            'opportunity_board' => [],
            'action_board' => [
                'do_in_24h' => ['Revisar fuentes fallidas y relanzar sólo la fuente afectada.', 'Validar manualmente los 3 trends con mejor fit_score.'],
                'test_this_week' => ['Probar 2-3 hooks derivados de los trends priorizados.'],
                'watch' => ['Señales que aparecen en más de una fuente.'],
                'ignore_for_now' => ['Trends genéricos sin puente con la marca o el objetivo.'],
            ],
        ];

        return [
            'analysis' => self::format_trend_analysis_text($structured),
            'analysis_structured' => $structured,
            'model' => class_exists('VES_Config') ? VES_Config::get_openai_model('fast_analysis') : 'local_fallback',
            'model_label' => 'Análisis local de respaldo',
            'analysis_source' => 'local_fallback',
            'format' => 'local_fallback',
            'fallback_used' => true,
            'confidence_mode' => 'diagnostic_fallback',
            'validation_required' => true,
            'can_generate_brief' => false,
            'memory_type' => 'fallback_diagnostic',
            'report_status' => 'fallback_diagnostic',
        ];
    }
    private static function build_brand_audit_response_format() {
        if (class_exists('VES_Brand_Audit_Report_Schema')) {
            return VES_Brand_Audit_Report_Schema::openai_response_format();
        }
        return [
            'type' => 'json_schema',
            'name' => 'brand_deep_audit_analysis',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'executive_summary' => ['type' => 'string'],
                    'strategic_diagnosis' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'main_finding' => ['type' => 'string'],
                            'why_it_matters' => ['type' => 'string'],
                            'confidence' => ['type' => 'string'],
                            'evidence_summary' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['main_finding', 'why_it_matters', 'confidence', 'evidence_summary'],
                    ],
                    'brand_positioning_readout' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'what_brand_says' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'what_market_sees' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'positioning_gap' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['what_brand_says', 'what_market_sees', 'positioning_gap'],
                    ],
                    'search_intent_readout' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'main_intents' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'decision_stage_needs' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'unanswered_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'seo_content_opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['main_intents', 'decision_stage_needs', 'unanswered_questions', 'seo_content_opportunities'],
                    ],
                    'social_readout' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'owned_content_patterns' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'earned_content_patterns' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'top_attention_assets' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'weak_or_missing_territories' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['owned_content_patterns', 'earned_content_patterns', 'top_attention_assets', 'weak_or_missing_territories'],
                    ],
                    'review_and_trust_readout' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'positive_proof' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'friction_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'trust_gap' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'reassurance_opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['positive_proof', 'friction_points', 'trust_gap', 'reassurance_opportunities'],
                    ],
                    'competitor_gap_matrix' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'competitor' => ['type' => 'string'],
                                'what_they_appear_to_own' => ['type' => 'string'],
                                'brand_opportunity' => ['type' => 'string'],
                                'evidence' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['competitor', 'what_they_appear_to_own', 'brand_opportunity', 'evidence'],
                        ],
                    ],
                    'opportunity_territories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'evidence' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'business_implication' => ['type' => 'string'],
                                'recommended_action' => ['type' => 'string'],
                                'content_examples' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['name', 'evidence', 'business_implication', 'recommended_action', 'content_examples'],
                        ],
                    ],
                    'insights' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'summary' => ['type' => 'string'],
                                'explanation' => ['type' => 'string'],
                                'evidence_refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'evidence_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'source_platforms' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'business_implication' => ['type' => 'string'],
                                'severity' => ['type' => 'string'],
                                'opportunity_score' => ['type' => 'number'],
                                'confidence' => ['type' => 'number'],
                                'limitations' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'recommended_action' => ['type' => 'string'],
                                'next_workflow' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'type', 'summary', 'explanation', 'evidence_refs', 'evidence_ids', 'source_platforms', 'business_implication', 'severity', 'opportunity_score', 'confidence', 'limitations', 'recommended_action', 'next_workflow'],
                        ],
                    ],
                    'opportunities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'why_it_matters' => ['type' => 'string'],
                                'evidence_refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'linked_insight_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'priority' => ['type' => 'string'],
                                'effort' => ['type' => 'string'],
                                'business_value' => ['type' => 'string'],
                                'impact' => ['type' => 'string'],
                                'confidence' => ['type' => 'number'],
                                'recommended_next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'suggested_next_step' => ['type' => 'string'],
                                'content_or_campaign_angle' => ['type' => 'string'],
                                'suggested_workflow' => ['type' => 'string'],
                                'starter_prompt_for_assistant' => ['type' => 'string'],
                            ],
                            'required' => ['title', 'description', 'why_it_matters', 'evidence_refs', 'linked_insight_ids', 'priority', 'effort', 'business_value', 'impact', 'confidence', 'recommended_next_steps', 'suggested_next_step', 'content_or_campaign_angle', 'suggested_workflow', 'starter_prompt_for_assistant'],
                        ],
                    ],
                    'evidence_used' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'recommended_next_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'questions_to_explore_with_assistant' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'deck_outline' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'slide_number' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'main_message' => ['type' => 'string'],
                                'evidence_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'visual_suggestion' => ['type' => 'string'],
                            ],
                            'required' => ['slide_number', 'title', 'main_message', 'evidence_points', 'visual_suggestion'],
                        ],
                    ],
                    'limitations' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['executive_summary', 'strategic_diagnosis', 'brand_positioning_readout', 'search_intent_readout', 'social_readout', 'review_and_trust_readout', 'competitor_gap_matrix', 'opportunity_territories', 'insights', 'opportunities', 'evidence_used', 'recommended_next_actions', 'questions_to_explore_with_assistant', 'deck_outline', 'limitations'],
            ],
        ];
    }

    private static function compact_brand_audit_social_evidence($social_owned) {
        $social_owned = is_array($social_owned) ? $social_owned : [];
        $trim_post = static function($post) {
            $post = is_array($post) ? $post : [];
            return [
                'platform' => sanitize_key((string) ($post['platform'] ?? '')),
                'entity_role' => sanitize_key((string) ($post['entity_role'] ?? '')),
                'entity_name' => sanitize_text_field((string) ($post['entity_name'] ?? '')),
                'source_variant' => sanitize_key((string) ($post['source_variant'] ?? '')),
                'title' => sanitize_text_field((string) ($post['title'] ?? '')),
                'source' => sanitize_text_field((string) ($post['source'] ?? '')),
                'url' => esc_url_raw((string) ($post['url'] ?? '')),
                'text' => sanitize_textarea_field((string) ($post['text'] ?? '')),
                'metrics' => is_array($post['metrics'] ?? null) ? [
                    'views' => (float) ($post['metrics']['views'] ?? 0),
                    'likes' => (float) ($post['metrics']['likes'] ?? 0),
                    'comments' => (float) ($post['metrics']['comments'] ?? 0),
                    'shares' => (float) ($post['metrics']['shares'] ?? 0),
                    'saves' => (float) ($post['metrics']['saves'] ?? 0),
                    'engagement_total' => (float) ($post['metrics']['engagement_total'] ?? 0),
                ] : [],
                'content_flags' => array_values((array) ($post['content_flags'] ?? [])),
                'content_territories' => array_values((array) ($post['content_territories'] ?? [])),
            ];
        };
        $platforms = [];
        foreach (array_slice((array) ($social_owned['platforms'] ?? []), 0, 40) as $key => $row) {
            if (!is_array($row)) { continue; }
            $platforms[$key] = [
                'source_key' => sanitize_key((string) ($row['source_key'] ?? $key)),
                'platform' => sanitize_key((string) ($row['platform'] ?? '')),
                'entity_role' => sanitize_key((string) ($row['entity_role'] ?? '')),
                'entity_name' => sanitize_text_field((string) ($row['entity_name'] ?? '')),
                'source_variant' => sanitize_key((string) ($row['source_variant'] ?? '')),
                'status' => sanitize_text_field((string) ($row['status'] ?? '')),
                'readable_items' => (int) ($row['readable_items'] ?? 0),
                'minimum_expected_items' => (int) ($row['minimum_expected_items'] ?? 20),
                'notes' => sanitize_text_field((string) ($row['notes'] ?? '')),
            ];
        }
        return [
            'channels_submitted' => array_filter((array) ($social_owned['channels_submitted'] ?? [])),
            'hashtags' => array_values((array) ($social_owned['hashtags'] ?? [])),
            'coverage_summary' => sanitize_textarea_field((string) ($social_owned['coverage_summary'] ?? '')),
            'minimum_posts_per_entity_platform' => (int) ($social_owned['minimum_posts_per_entity_platform'] ?? 20),
            'coverage_blindspots' => array_values(array_slice((array) ($social_owned['coverage_blindspots'] ?? []), 0, 20)),
            'platforms' => $platforms,
            'entity_platform_stats' => array_values(array_slice((array) ($social_owned['entity_platform_stats'] ?? []), 0, 40)),
            'entity_comparison' => array_values(array_slice((array) ($social_owned['entity_comparison'] ?? []), 0, 12)),
            'quality_scores' => array_values(array_slice((array) ($social_owned['quality_scores'] ?? []), 0, 12)),
            'owned_vs_earned_summary' => is_array($social_owned['owned_vs_earned_summary'] ?? null) ? $social_owned['owned_vs_earned_summary'] : [],
            'content_territories' => array_values(array_slice((array) ($social_owned['content_territories'] ?? []), 0, 12)),
            'top_posts' => array_map($trim_post, array_slice((array) ($social_owned['top_posts'] ?? []), 0, 20)),
            'owned_posts' => array_map($trim_post, array_slice((array) ($social_owned['owned_posts'] ?? []), 0, 12)),
            'earned_posts' => array_map($trim_post, array_slice((array) ($social_owned['earned_posts'] ?? []), 0, 12)),
            'competitor_social_posts' => array_map($trim_post, array_slice((array) ($social_owned['competitor_social_posts'] ?? []), 0, 18)),
        ];
    }

    private static function compact_brand_audit_evidence($evidence_pack) {
        $evidence_pack = is_array($evidence_pack) ? $evidence_pack : [];
        $trim_items = static function($items, $max_items = 8) {
            $out = [];
            foreach (array_slice((array) $items, 0, $max_items) as $item) {
                if (!is_array($item)) { continue; }
                $out[] = [
                    'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                    'source' => sanitize_text_field((string) ($item['source'] ?? '')),
                    'badge' => sanitize_text_field((string) ($item['badge'] ?? '')),
                    'url' => esc_url_raw((string) ($item['url'] ?? '')),
                    'text' => sanitize_textarea_field((string) ($item['text'] ?? '')),
                ];
            }
            return $out;
        };
        $owned_pages = $trim_items($evidence_pack['owned_web']['pages'] ?? [], 6);
        $search_items = $trim_items($evidence_pack['search_intent']['google_search_items'] ?? [], 12);
        $news_items = $trim_items($evidence_pack['public_web_news']['news_items'] ?? [], 8);
        $trend_items = $trim_items($evidence_pack['trend_interest']['trend_items'] ?? [], 8);
        $social_owned = is_array($evidence_pack['social_owned'] ?? null) ? $evidence_pack['social_owned'] : [];
        $reviews_maps = is_array($evidence_pack['reviews_maps'] ?? null) ? $evidence_pack['reviews_maps'] : [];
        $social_evidence_count = 0;
        foreach (['posts', 'items', 'mentions', 'earned_mentions', 'content_territories', 'top_posts'] as $social_key) {
            if (isset($social_owned[$social_key]) && is_array($social_owned[$social_key])) {
                $social_evidence_count += count($social_owned[$social_key]);
            }
        }
        $review_evidence_count = is_array($reviews_maps['review_items'] ?? null) ? count($reviews_maps['review_items']) : 0;
        $maps_place_count = is_array($reviews_maps['places'] ?? null) ? count($reviews_maps['places']) : 0;

        return [
            'evidence_availability' => [
                'website_pages' => count($owned_pages),
                'search_items' => count($search_items),
                'news_items' => count($news_items),
                'trend_items' => count($trend_items),
                'social_evidence_items' => $social_evidence_count,
                'submitted_social_channels' => count((array) ($social_owned['channels_submitted'] ?? [])),
                'submitted_hashtags' => count((array) ($social_owned['hashtags'] ?? [])),
                'review_snippet_items' => $review_evidence_count,
                'maps_place_items' => $maps_place_count,
                'review_map_evidence_items' => $review_evidence_count,
                'guardrail_note' => 'Submitted social URLs and hashtags are user inputs, not observed social performance evidence. Google Maps places/ratings support local visibility/trust context; direct review sentiment requires review_items. Empty sections must be treated as blind spots, not proof.',
            ],
            'brand_profile' => is_array($evidence_pack['brand_profile'] ?? null) ? $evidence_pack['brand_profile'] : [],
            'owned_web' => [
                'positioning_summary' => sanitize_textarea_field((string) ($evidence_pack['owned_web']['positioning_summary'] ?? '')),
                'pages' => $owned_pages,
            ],
            'search_intent' => [
                'top_queries' => array_values(array_slice((array) ($evidence_pack['search_intent']['top_queries'] ?? []), 0, 16)),
                'review_intent' => array_values(array_slice((array) ($evidence_pack['search_intent']['review_intent'] ?? []), 0, 8)),
                'intent_clusters' => array_values(array_slice((array) ($evidence_pack['search_intent']['intent_clusters'] ?? []), 0, 8)),
                'google_search_items' => $search_items,
            ],
            'public_web_news' => [
                'coverage_summary' => sanitize_textarea_field((string) ($evidence_pack['public_web_news']['coverage_summary'] ?? '')),
                'news_items' => $news_items,
            ],
            'trend_interest' => [
                'terms_compared' => array_values(array_slice((array) ($evidence_pack['trend_interest']['terms_compared'] ?? []), 0, 8)),
                'trend_items' => $trend_items,
            ],
            'social_owned' => self::compact_brand_audit_social_evidence($social_owned),
            'reviews_maps' => $reviews_maps,
            'competitor_context' => is_array($evidence_pack['competitor_context'] ?? null) ? $evidence_pack['competitor_context'] : [],
            'source_quality' => is_array($evidence_pack['source_quality'] ?? null) ? $evidence_pack['source_quality'] : [],
        ];
    }

    private static function brand_audit_text_contains_social_claim($value) {
        $text = strtolower(is_array($value) ? wp_json_encode($value) : (string) $value);
        return (bool) preg_match('/\b(social|instagram|tiktok|youtube|linkedin|twitter|x\/twitter|facebook|hashtag|ugc|creator|influencer|lifestyle story|engagement|community)\b/u', $text);
    }

    private static function brand_audit_apply_evidence_guardrails($structured, $compact_evidence) {
        $structured = is_array($structured) ? $structured : [];
        $compact_evidence = is_array($compact_evidence) ? $compact_evidence : [];
        $availability = is_array($compact_evidence['evidence_availability'] ?? null) ? $compact_evidence['evidence_availability'] : [];
        $social_items = (int) ($availability['social_evidence_items'] ?? 0);
        $review_items = (int) ($availability['review_map_evidence_items'] ?? 0);
        $has_social_evidence = $social_items > 0;
        $has_direct_review_evidence = $review_items > 0;

        if (!isset($structured['limitations']) || !is_array($structured['limitations'])) {
            $structured['limitations'] = [];
        }

        if (!$has_social_evidence) {
            $structured['limitations'][] = 'No observed social post/comment/profile performance evidence was collected in this run. Submitted social URLs or hashtags are inputs only and must not be treated as proof of social activity, sentiment, engagement, or content gaps.';
            $structured['social_readout'] = [
                'owned_content_patterns' => [],
                'earned_content_patterns' => [],
                'top_attention_assets' => [],
                'weak_or_missing_territories' => [
                    'Social evidence was not collected yet. Add real Instagram/TikTok/YouTube/X adapters before making social performance or content territory claims.',
                ],
            ];

            $territories = [];
            foreach ((array) ($structured['opportunity_territories'] ?? []) as $territory) {
                if (!is_array($territory)) { continue; }
                $combined = implode(' ', [
                    (string) ($territory['name'] ?? ''),
                    (string) ($territory['business_implication'] ?? ''),
                    (string) ($territory['recommended_action'] ?? ''),
                    wp_json_encode($territory['evidence'] ?? []),
                ]);
                if (self::brand_audit_text_contains_social_claim($combined)) {
                    continue;
                }
                $territories[] = $territory;
            }
            $territories[] = [
                'name' => 'Social evidence gap, not a confirmed social opportunity',
                'evidence' => ['No social post, comment, creator, or engagement dataset was collected in this run.'],
                'business_implication' => 'The audit can identify that social research is still missing, but it cannot yet diagnose social performance or lifestyle storytelling gaps as confirmed findings.',
                'recommended_action' => 'Run dedicated social collectors before creating social-channel recommendations.',
                'content_examples' => [],
            ];
            $structured['opportunity_territories'] = array_values($territories);

            $deck = [];
            foreach ((array) ($structured['deck_outline'] ?? []) as $slide) {
                if (!is_array($slide)) { continue; }
                $combined = implode(' ', [
                    (string) ($slide['title'] ?? ''),
                    (string) ($slide['main_message'] ?? ''),
                    wp_json_encode($slide['evidence_points'] ?? []),
                ]);
                if (self::brand_audit_text_contains_social_claim($combined)) {
                    $slide['title'] = 'Social Evidence Blind Spot';
                    $slide['main_message'] = 'This run did not collect social performance evidence, so social claims should remain out of the strategic diagnosis until social adapters are connected.';
                    $slide['evidence_points'] = [
                        'No observed social posts/comments/engagement items were collected.',
                        'Submitted social handles or hashtags are inputs, not evidence.',
                        'Next step: run dedicated social collectors before making social recommendations.',
                    ];
                    $slide['visual_suggestion'] = 'Blind spot card showing missing social evidence sources.';
                }
                $deck[] = $slide;
            }
            $structured['deck_outline'] = $deck;
        }

        if (!$has_direct_review_evidence) {
            $structured['limitations'][] = 'No direct Google Maps/review corpus was collected. Review-related findings may only be treated as search-intent evidence, not as verified customer sentiment.';
            if (!isset($structured['review_and_trust_readout']) || !is_array($structured['review_and_trust_readout'])) {
                $structured['review_and_trust_readout'] = ['positive_proof' => [], 'friction_points' => [], 'trust_gap' => [], 'reassurance_opportunities' => []];
            }
            $structured['review_and_trust_readout']['positive_proof'] = [];
            $structured['review_and_trust_readout']['friction_points'] = [];
            $structured['review_and_trust_readout']['trust_gap'] = array_values(array_merge((array) ($structured['review_and_trust_readout']['trust_gap'] ?? []), ['Review demand appears in search evidence, but direct review sentiment was not collected.']));
        }

        $structured['limitations'] = array_values(array_unique(array_filter(array_map('strval', $structured['limitations']))));
        return $structured;
    }

    public static function analyze_brand_audit_bundle($request, $evidence_pack, $related_memory = [], $project_context = null) {
        $primary_model = VES_Config::get_openai_model('deep_analysis');
        $request = is_array($request) ? $request : [];
        $output_language = $request['language'] ?? ($request['output_language'] ?? 'es');
        $brand_name = sanitize_text_field((string) ($request['brand_name'] ?? ''));
        $lang_instruction = self::output_language_instruction($output_language);
        $compact_evidence = self::compact_brand_audit_evidence($evidence_pack);
        $max_evidence_refs = min(30, class_exists('VES_Config') ? VES_Config::get_openai_max_evidence_refs() : 24);
        $evidence_items_compact = is_array($request['evidence_items_compact'] ?? null) ? array_slice($request['evidence_items_compact'], 0, $max_evidence_refs) : (is_array($evidence_pack['_evidence_items_compact'] ?? null) ? array_slice($evidence_pack['_evidence_items_compact'], 0, $max_evidence_refs) : []);
        $allowed_evidence_refs = is_array($request['allowed_evidence_refs'] ?? null) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('strval', $request['allowed_evidence_refs']))))) : [];
        if (empty($allowed_evidence_refs) && !empty($evidence_items_compact)) {
            foreach ($evidence_items_compact as $ev) {
                $ref = is_array($ev) ? sanitize_text_field((string) ($ev['evidence_ref'] ?? '')) : '';
                if ($ref !== '') { $allowed_evidence_refs[] = $ref; }
            }
            $allowed_evidence_refs = array_values(array_unique($allowed_evidence_refs));
        }
        // Anti-hallucination hardening: only canonical `ev_<digits>_<hex>` refs may
        // enter the allowlist — whether provided in the request or derived from
        // scraped evidence items. A provider/AI cannot smuggle a malformed or
        // injected ref into the allowed set; non-canonical entries are dropped.
        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'normalize_evidence_refs')) {
            $allowed_evidence_refs = array_slice(VES_Evidence_Store::normalize_evidence_refs($allowed_evidence_refs), 0, $max_evidence_refs);
        }
        $metric_snapshots = is_array($request['metric_snapshots'] ?? null) ? array_slice($request['metric_snapshots'], 0, 60) : (is_array($evidence_pack['_metric_snapshots'] ?? null) ? array_slice($evidence_pack['_metric_snapshots'], 0, 60) : []);
        $pattern_candidates = is_array($request['pattern_candidates'] ?? null) ? array_slice($request['pattern_candidates'], 0, 40) : (is_array($evidence_pack['_pattern_candidates'] ?? null) ? array_slice($evidence_pack['_pattern_candidates'], 0, 40) : []);
        $normalized_summary = is_array($evidence_pack['_normalized_evidence_summary'] ?? null) ? $evidence_pack['_normalized_evidence_summary'] : [];
        $memory_context = self::normalize_related_memory($related_memory);
        $project_context_clean = self::normalize_project_context($project_context);

        $platform_stats = [];
        foreach ((array) ($normalized_summary['by_platform'] ?? []) as $platform => $count) {
            $platform_stats[] = [
                'platform' => sanitize_key((string) $platform),
                'items_count' => (int) $count,
            ];
        }
        foreach ((array) ($compact_evidence['social_owned']['entity_platform_stats'] ?? []) as $row) {
            if (is_array($row)) {
                $platform_stats[] = $row;
            }
        }

        $entity_comparison = [
            'by_entity' => $normalized_summary['by_entity'] ?? [],
            'by_entity_type' => $normalized_summary['by_entity_type'] ?? [],
            'competitor_gap_matrix' => $compact_evidence['competitor_context']['gap_matrix'] ?? [],
            'social_entity_platform_stats' => $compact_evidence['social_owned']['entity_platform_stats'] ?? [],
        ];

        $coverage_quality = [
            'source_quality' => $compact_evidence['source_quality'] ?? [],
            'evidence_availability' => $compact_evidence['evidence_availability'] ?? [],
            'warnings' => $normalized_summary['coverage_warnings'] ?? [],
        ];
        $known_limitations = [];
        foreach ((array) ($coverage_quality['warnings'] ?? []) as $warning) {
            $known_limitations[] = sanitize_text_field((string) $warning);
        }
        foreach ((array) ($compact_evidence['source_quality']['coverage'] ?? []) as $source_key => $source) {
            if (is_array($source) && !empty($source['notes'])) {
                $known_limitations[] = sanitize_text_field((string) $source_key) . ': ' . sanitize_text_field((string) $source['notes']);
            }
        }
        $known_limitations = array_values(array_unique(array_filter($known_limitations)));

        $memory_summary = [
            'workspace_memory' => [],
            'brand_memory' => [],
            'competitor_memory' => [],
        ];
        foreach ($memory_context as $packet) {
            foreach ((array) ($packet['records'] ?? []) as $record) {
                $line = trim((string) ($record['title'] ?? '') . ': ' . (string) ($record['summary'] ?? ''));
                if ($line === '') { continue; }
                $memory_summary['workspace_memory'][] = $line;
                if (!empty($record['brand_name']) && $brand_name !== '' && strcasecmp((string) $record['brand_name'], $brand_name) === 0) {
                    $memory_summary['brand_memory'][] = $line;
                }
                if (sanitize_key((string) ($record['memory_type'] ?? '')) === 'competitor_context') {
                    $memory_summary['competitor_memory'][] = $line;
                }
            }
            if (!empty($packet['summary'])) {
                $memory_summary['workspace_memory'][] = sanitize_textarea_field((string) $packet['summary']);
            }
        }
        foreach ($memory_summary as $key => $values) {
            $memory_summary[$key] = array_values(array_unique(array_slice(array_filter($values), 0, 8)));
        }

        $default_workspace_label = class_exists('VES_Branding') ? VES_Branding::workspace_name() : 'Intelligence Workspace';
        $prompt_variables = [
            'workspace_name' => sanitize_text_field((string) ($request['workspace_name'] ?? $default_workspace_label)),
            'brand_name' => $brand_name,
            'brand_website' => esc_url_raw((string) ($request['website_url'] ?? '')),
            'industry_category' => sanitize_text_field((string) ($request['industry'] ?? '')),
            'country_market' => sanitize_text_field((string) ($request['country'] ?? '')),
            'research_goal' => sanitize_textarea_field((string) ($request['audit_goal'] ?? '')),
            'quality_mode' => sanitize_key((string) ($request['depth'] ?? 'standard')),
            'competitors' => wp_json_encode($request['competitors'] ?? [], JSON_UNESCAPED_UNICODE),
            'evidence_summary' => wp_json_encode($normalized_summary ?: ($compact_evidence['evidence_availability'] ?? []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'evidence_items' => wp_json_encode(!empty($evidence_items_compact) ? $evidence_items_compact : $compact_evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'platform_stats' => wp_json_encode(['platform_stats' => $platform_stats, 'metric_snapshots' => $metric_snapshots], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'entity_comparison' => wp_json_encode($entity_comparison, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'coverage_quality' => wp_json_encode($coverage_quality, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'patterns' => wp_json_encode($pattern_candidates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'insights' => wp_json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'opportunities' => wp_json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'workspace_memory' => wp_json_encode($memory_summary['workspace_memory'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'brand_memory' => wp_json_encode($memory_summary['brand_memory'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'competitor_memory' => wp_json_encode($memory_summary['competitor_memory'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'known_limitations' => wp_json_encode($known_limitations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'allowed_evidence_refs' => wp_json_encode($allowed_evidence_refs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'evidence_ref_contract' => 'Use only evidence_refs from allowed_evidence_refs. evidence_refs is canonical; evidence_ids may be present only as the same values. If no evidence supports a claim, leave evidence_refs empty and mark it as hypothesis/limitation.',
        ];
        $rendered_prompt = '';
        $rendered_system_prompt = '';
        if (class_exists('VES_AI_Prompt_Templates') && method_exists('VES_AI_Prompt_Templates', 'render')) {
            $rendered_template = VES_AI_Prompt_Templates::render('brand_audit_final_report', $prompt_variables);
            if (is_array($rendered_template)) {
                $rendered_system_prompt = (string) ($rendered_template['system'] ?? '');
                $rendered_prompt = (string) ($rendered_template['user'] ?? '');
            } elseif (is_string($rendered_template)) {
                $rendered_prompt = $rendered_template;
            }
        }

        $payload = [
            'request' => [
                'brand_name' => $brand_name,
                'website_url' => esc_url_raw((string) ($request['website_url'] ?? '')),
                'country' => sanitize_text_field((string) ($request['country'] ?? '')),
                'industry' => sanitize_text_field((string) ($request['industry'] ?? '')),
                'audit_goal' => sanitize_textarea_field((string) ($request['audit_goal'] ?? '')),
                'depth' => sanitize_key((string) ($request['depth'] ?? 'standard')),
                'workspace_id' => isset($request['workspace_id']) ? (int) $request['workspace_id'] : 0,
            ],
            'project_context' => $project_context_clean,
            'related_project_memory' => $memory_context,
            'evidence_pack' => $compact_evidence,
            'evidence_items_compact' => $evidence_items_compact,
            'allowed_evidence_refs' => $allowed_evidence_refs,
            'evidence_ref_contract' => [
                'canonical_field' => 'evidence_refs',
                'legacy_alias' => 'evidence_ids',
                'rule' => 'Use only values from allowed_evidence_refs. If no provided evidence supports a claim, leave evidence_refs empty and mark it as hypothesis/limitation.',
            ],
            'evidence_summary' => $normalized_summary,
            'platform_stats' => $platform_stats,
            'metric_snapshots' => $metric_snapshots,
            'pattern_candidates' => $pattern_candidates,
            'entity_comparison' => $entity_comparison,
            'coverage_quality' => $coverage_quality,
            'known_limitations' => $known_limitations,
            'rendered_template' => $rendered_prompt,
            'prompt_pipeline_contract' => [
                'current_stage' => 'brand_audit_final_report',
                'implemented_steps_this_version' => ['normalized_evidence_refs', 'metric_snapshots', 'rule_based_pattern_candidates', 'memory_pack', 'final_report', 'insight_opportunity_persistence'],
                'not_yet_separate_ai_calls' => ['pattern_extraction', 'insight_generation', 'opportunity_generation', 'strategic_critic'],
                'expected_internal_order' => ['evidence', 'metrics', 'patterns', 'insights', 'opportunities', 'final_report', 'deck_outline'],
                'major_output_requirements' => ['summary', 'evidence_used', 'confidence', 'limitations', 'business_implication', 'recommended_action', 'next_workflow'],
            ],
        ];

        if (class_exists('VES_AI_Prompt_Templates') && method_exists('VES_AI_Prompt_Templates', 'all')) {
            $payload['prompt_pipeline_contract']['available_template_keys'] = array_keys(VES_AI_Prompt_Templates::all());
        }

        $base_instructions = ($rendered_system_prompt !== '' ? ($rendered_system_prompt . ' ') : '') . 'You are a senior brand intelligence strategist, marketing data analyst, and pitch strategist. ' . $lang_instruction . ' '
            . 'Analyze the provided evidence pack for the specific brand and market. Do NOT summarize raw data. Follow the product pipeline internally: evidence first, then metrics/coverage, then patterns, then insights, then opportunities, then final report and deck narrative. Convert evidence into strategic diagnosis, demand-vs-output gaps, opportunity territories, and deck-ready narrative. '
            . 'Evidence contract: submitted URLs, social handles, hashtags, requested keywords, and competitor names are user inputs, not observed evidence. Only collected items with titles, text, URLs, metrics, or source payloads count as evidence. '
            . 'Strict rules: do not invent numbers, do not claim sentiment if no social/review evidence exists, use Google Maps places/review snippets only as direct review evidence when provided, do not claim that a brand is active on Instagram, TikTok, YouTube, X, LinkedIn, or Facebook unless observed social items were collected, do not treat placeholders or failed sources as evidence, and clearly separate confirmed evidence from inference. '
            . 'Search results that point to review sites are evidence of review/search intent or third-party review visibility; they are not a full review corpus unless direct review snippets are present. '
            . 'Do not diagnose low engagement unless metrics include a meaningful baseline or the evidence directly shows very low engagement relative to views/followers; otherwise phrase as an observed signal requiring validation. '
            . 'Use the entity_comparison, entity_platform_stats, owned_vs_earned_summary, quality_scores, source_nature, content_flags, and content_territories fields when discussing social evidence. Compare the brand against every competitor where social items were collected, and explicitly call out source blindspots when any entity/platform has fewer than 20 readable posts. Separate owned brand output from earned/creator/user signals. Treat paid_or_sponsored and giveaway flags as potentially inflated attention, not organic brand love. '
            . 'Use search_intent.intent_clusters to distinguish review/reputation, purchase/availability, comparison, product, brand-story, social/campaign, and employment intent. Do not collapse all search evidence into generic SEO recommendations. '
            . 'Use reviews_maps.sentiment_summary and complaint_themes only when review_items exist. If only review-related search results exist, call it review intent/visibility, not sentiment. '
            . 'Use competitor_context.gap_matrix only as directional competitor evidence. If competitor signals are thin, state the confidence and do not invent competitor ownership. '
            . 'When a section has zero evidence items, mark it as a blind spot or next data-collection step; do not turn it into a confirmed opportunity. '
            . 'For every insight and opportunity, cite canonical evidence_refs only from allowed_evidence_refs / evidence_items_compact. Never invent evidence refs. Keep evidence_ids only as a legacy alias that mirrors evidence_refs. If no provided evidence supports the claim, leave evidence_refs empty, mark the item as hypothesis/unsupported, and explain the limitation. For every recommendation, tie it to visible evidence from the evidence pack. If evidence is thin, say so and lower confidence. '
            . 'Use cumulative memory as continuity: state when the audit confirms, weakens, or contradicts previous workspace findings; distinguish new findings from repeated patterns; do not reuse rejected or outdated assumptions; clearly separate facts/evidence, hypotheses, and recommendations. '
            . 'Make the deck practical: 7 to 9 slides, one sharp message per slide, evidence bullets with source labels, and visual suggestions that a designer can build. '
            . 'The user wants a practical brand audit useful for marketing, social/search strategy, pitch preparation, and content production.';

        $compact_strategy_payload = [
            'request' => $payload['request'] ?? [],
            'source_health_summary' => $payload['coverage_quality']['source_quality'] ?? [],
            'metric_summary' => $metric_snapshots,
            'allowed_evidence_refs' => array_slice($allowed_evidence_refs, 0, 30),
            'evidence_items_compact' => array_slice($evidence_items_compact, 0, 30),
            'top_blind_spots' => array_slice($known_limitations, 0, 12),
            'patterns' => array_slice($pattern_candidates, 0, 20),
            'entity_comparison' => $entity_comparison,
            'memory_summary' => $memory_summary,
            'project_context' => $project_context_clean,
        ];
        $input = ($rendered_prompt !== '')
            ? ((string) $rendered_prompt . "

Compact structured payload for audit engine:
" . wp_json_encode($compact_strategy_payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            : ("Compact structured Brand Audit evidence/context payload:
" . wp_json_encode($compact_strategy_payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $payload_size_estimate = strlen((string) $input);
        $prompt_version = class_exists('VES_Brand_Audit_Report_Schema') ? VES_Brand_Audit_Report_Schema::PROMPT_VERSION : 'brand_deep_audit_legacy_schema';
        $input_hash = hash('sha256', (string) $input);
        $base_instructions .= ' Required final output: a strict Brand Deep Audit report object with report_meta, executive_verdict, evidence_quality, what_we_know, what_we_do_not_know, key_patterns, strategic_diagnosis, competitor_implications, opportunities, recommended_actions, briefs, next_data_collection_plan, and evidence_appendix. Every claim must be evidence-linked or explicitly marked as insufficient evidence. Do not include raw provider diagnostics in the user-facing report.';
        $models = array_values(array_unique(array_filter([$primary_model, 'gpt-4.1-mini', 'gpt-4o-mini'])));
        $errors = [];
        $attempt_log = [];
        // brand_audit_timeout_fallback_cascade: request_timeout, rate_limited, and upstream_unavailable continue to the next mode/model instead of returning early.

        foreach ($models as $model) {
            $model = trim((string) $model);
            if ($model === '') { continue; }

            foreach (['json_schema', 'json_text'] as $mode) {
                $instructions = $base_instructions;
                $request_body = [
                    'model' => $model,
                    '_ves_purpose' => 'deep_analysis',
                    'instructions' => $instructions,
                    'input' => $input,
                    'max_output_tokens' => min(4200, max(2600, self::trend_max_output_tokens($model, true))),
                    'store' => false,
                ];

                if ($mode === 'json_schema') {
                    $request_body['instructions'] .= ' Return JSON that exactly matches the requested schema.';
                    $request_body['text'] = ['format' => self::build_brand_audit_response_format()];
                } else {
                    $request_body['instructions'] .= ' Return only one valid JSON object. Prefer the strict report schema keys: report_meta, executive_verdict, evidence_quality, what_we_know, what_we_do_not_know, key_patterns, strategic_diagnosis, competitor_implications, opportunities, recommended_actions, briefs, next_data_collection_plan, evidence_appendix. Do not wrap it in markdown.';
                }

                if (self::is_gpt5_family($model)) {
                    $request_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
                }

                $json = self::request_responses_api($request_body);
                if (is_wp_error($json)) {
                    $attempt_log[] = [
                        'model' => $model,
                        'mode' => $mode,
                        'timeout' => $json->get_error_code() === 'request_timeout',
                        'payload_size_estimate' => $payload_size_estimate,
                        'success' => false,
                        'error_code' => $json->get_error_code(),
                        'error_message' => $json->get_error_message(),
                    ];
                    $errors[] = $model . '/' . $mode . ': ' . $json->get_error_message();
                    continue;
                }

                $structured = self::extract_output_json($json);
                if (!is_array($structured) || empty($structured)) {
                    $details = self::describe_empty_response($json);
                    $raw_text = self::extract_output_text($json);
                    if ($raw_text !== '') {
                        $repair_body = [
                            'model' => $model,
                            '_ves_purpose' => 'deep_analysis_schema_repair',
                            'instructions' => 'Repair the provided invalid Brand Deep Audit output into one valid JSON object matching the required schema. Do not add new facts. Preserve evidence references only if present in the source text. Return JSON only.',
                            'input' => "Invalid output to repair:
" . $raw_text,
                            'max_output_tokens' => min(3600, max(2200, self::trend_max_output_tokens($model, true))),
                            'store' => false,
                            'text' => ['format' => self::build_brand_audit_response_format()],
                        ];
                        if (self::is_gpt5_family($model)) { $repair_body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')]; }
                        $repair_json = self::request_responses_api($repair_body);
                        if (!is_wp_error($repair_json)) {
                            $repair_structured = self::extract_output_json($repair_json);
                            if (is_array($repair_structured) && !empty($repair_structured)) {
                                $structured = $repair_structured;
                                $attempt_log[] = ['model' => $model, 'mode' => $mode . '_schema_repair', 'timeout' => false, 'payload_size_estimate' => $payload_size_estimate, 'success' => true, 'error_code' => ''];
                            }
                        }
                    }
                    if (!is_array($structured) || empty($structured)) {
                        $attempt_log[] = ['model' => $model, 'mode' => $mode, 'timeout' => false, 'payload_size_estimate' => $payload_size_estimate, 'success' => false, 'error_code' => 'empty_or_invalid_json'];
                        $errors[] = $model . '/' . $mode . ': empty_or_invalid_json (' . $details . ')';
                        continue;
                    }
                }

                // Normalize optional/missing keys from non-schema fallback mode so the UI never breaks.
                $structured['executive_summary'] = (string) ($structured['executive_summary'] ?? 'Brand Audit analysis generated.');
                $structured['strategic_diagnosis'] = is_array($structured['strategic_diagnosis'] ?? null) ? $structured['strategic_diagnosis'] : ['main_finding' => '', 'why_it_matters' => '', 'confidence' => 'limited', 'evidence_summary' => []];
                $structured['brand_positioning_readout'] = is_array($structured['brand_positioning_readout'] ?? null) ? $structured['brand_positioning_readout'] : ['what_brand_says' => [], 'what_market_sees' => [], 'positioning_gap' => []];
                $structured['search_intent_readout'] = is_array($structured['search_intent_readout'] ?? null) ? $structured['search_intent_readout'] : ['main_intents' => [], 'decision_stage_needs' => [], 'unanswered_questions' => [], 'seo_content_opportunities' => []];
                $structured['social_readout'] = is_array($structured['social_readout'] ?? null) ? $structured['social_readout'] : ['owned_content_patterns' => [], 'earned_content_patterns' => [], 'top_attention_assets' => [], 'weak_or_missing_territories' => []];
                $structured['review_and_trust_readout'] = is_array($structured['review_and_trust_readout'] ?? null) ? $structured['review_and_trust_readout'] : ['positive_proof' => [], 'friction_points' => [], 'trust_gap' => [], 'reassurance_opportunities' => []];
                $structured['competitor_gap_matrix'] = is_array($structured['competitor_gap_matrix'] ?? null) ? $structured['competitor_gap_matrix'] : [];
                $structured['opportunity_territories'] = is_array($structured['opportunity_territories'] ?? null) ? $structured['opportunity_territories'] : [];
                $structured['insights'] = is_array($structured['insights'] ?? null) ? $structured['insights'] : [];
                $structured['opportunities'] = is_array($structured['opportunities'] ?? null) ? $structured['opportunities'] : [];
                $structured['evidence_used'] = is_array($structured['evidence_used'] ?? null) ? $structured['evidence_used'] : [];
                $structured['questions_to_explore_with_assistant'] = is_array($structured['questions_to_explore_with_assistant'] ?? null) ? $structured['questions_to_explore_with_assistant'] : [];
                if (empty($structured['opportunities']) && !empty($structured['opportunity_territories'])) { $structured['opportunities'] = $structured['opportunity_territories']; }
                $structured['recommended_next_actions'] = is_array($structured['recommended_next_actions'] ?? null) ? $structured['recommended_next_actions'] : [];
                $structured['deck_outline'] = is_array($structured['deck_outline'] ?? null) ? $structured['deck_outline'] : [];
                $structured['limitations'] = is_array($structured['limitations'] ?? null) ? $structured['limitations'] : [];
                $structured = self::normalize_brand_audit_insight_opportunity_refs($structured);
                $structured = self::validate_brand_audit_refs_against_allowed($structured, $allowed_evidence_refs);

                $structured = self::brand_audit_apply_evidence_guardrails($structured, $payload['evidence_pack']);
                if (class_exists('VES_Brand_Audit_Report_Schema')) {
                    $structured['report_schema'] = VES_Brand_Audit_Report_Schema::normalize($structured, [
                        'request' => $request,
                        'model' => $model,
                        'prompt_version' => $prompt_version,
                        'input_hash' => $input_hash,
                        'generated_at' => current_time('mysql'),
                    ], $normalized_summary, $evidence_items_compact);
                    if (!empty($structured['report_schema']['executive_verdict']['summary'])) {
                        $structured['executive_summary'] = $structured['report_schema']['executive_verdict']['summary'];
                    }
                }

                $attempt_log[] = ['model' => $model, 'mode' => $mode, 'timeout' => false, 'payload_size_estimate' => $payload_size_estimate, 'success' => true, 'error_code' => ''];
                return [
                    'analysis' => trim((string) ($structured['executive_summary'] ?? ($structured['report_schema']['executive_verdict']['summary'] ?? ''))),
                    'analysis_structured' => $structured,
                    'deck' => is_array($structured['deck_outline'] ?? null) ? $structured['deck_outline'] : [],
                    'model' => $model,
                    'format' => $mode,
                    'prompt_version' => $prompt_version,
                    'input_hash' => $input_hash,
                    'attempts' => $errors,
                    'attempt_log' => $attempt_log,
                    'compact_strategy_payload' => true,
                ];
            }
        }

        $message = 'OpenAI Brand Audit strategy failed after retrying configured and safe fallback models.';
        if (!empty($errors)) {
            $message .= ' Last errors: ' . implode(' | ', array_slice($errors, -4));
        }
        return new WP_Error('brand_audit_openai_failed', $message, ['attempt_log' => $attempt_log]);
    }
    private static function validate_brand_audit_refs_against_allowed($structured, $allowed_refs) {
        $structured = is_array($structured) ? $structured : [];
        $allowed_refs = is_array($allowed_refs) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('strval', $allowed_refs))))) : [];
        $allowed = array_flip($allowed_refs);
        $invalid_all = [];
        $validate_items = static function($items) use ($allowed, &$invalid_all) {
            $items = is_array($items) ? $items : [];
            foreach ($items as $idx => $item) {
                if (!is_array($item)) { continue; }
                $raw_refs = $item['evidence_refs'] ?? $item['evidence_ids'] ?? [];
                if (is_string($raw_refs)) { $raw_refs = preg_split('/[\s,]+/', $raw_refs); }
                $refs = is_array($raw_refs) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('strval', $raw_refs))))) : [];
                $valid = [];
                $invalid = [];
                foreach ($refs as $ref) {
                    if (isset($allowed[$ref])) { $valid[] = $ref; } else { $invalid[] = $ref; }
                }
                if (!empty($invalid)) { $invalid_all = array_merge($invalid_all, $invalid); }
                $items[$idx]['evidence_refs'] = array_values(array_unique($valid));
                $items[$idx]['evidence_ids'] = $items[$idx]['evidence_refs'];
                $meta = is_array($items[$idx]['meta'] ?? null) ? $items[$idx]['meta'] : [];
                $meta['invalid_evidence_refs'] = array_values(array_unique($invalid));
                $meta['evidence_ref_count'] = count($valid);
                if (empty($valid)) {
                    $meta['unsupported_by_evidence'] = true;
                    $meta['evidence_validation_status'] = 'unsupported';
                    $items[$idx]['evidence_validation_status'] = 'unsupported';
                    $items[$idx]['confidence'] = min((float) ($items[$idx]['confidence'] ?? 0.25), 0.35);
                    $items[$idx]['limitations'] = array_values(array_unique(array_merge((array) ($items[$idx]['limitations'] ?? []), ['No valid evidence_ref from this run supports this item; treat it as hypothesis.'])));
                } elseif (count($valid) < 2) {
                    $meta['unsupported_by_evidence'] = false;
                    $meta['evidence_validation_status'] = 'weak';
                    $items[$idx]['evidence_validation_status'] = 'weak';
                    $items[$idx]['confidence'] = min((float) ($items[$idx]['confidence'] ?? 0.5), 0.65);
                } else {
                    $meta['unsupported_by_evidence'] = false;
                    $meta['evidence_validation_status'] = 'supported';
                    $items[$idx]['evidence_validation_status'] = 'supported';
                }
                $items[$idx]['meta'] = $meta;
            }
            return $items;
        };
        foreach (['insights', 'opportunities', 'opportunity_territories'] as $key) {
            if (isset($structured[$key])) { $structured[$key] = $validate_items($structured[$key]); }
        }
        if (isset($structured['evidence_used'])) {
            $valid_used = [];
            foreach ((array) $structured['evidence_used'] as $ref) {
                $ref = sanitize_text_field((string) $ref);
                if ($ref === '') { continue; }
                if (isset($allowed[$ref])) { $valid_used[] = $ref; }
                else { $invalid_all[] = $ref; }
            }
            $structured['evidence_used'] = array_values(array_unique($valid_used));
        }
        $structured['_evidence_ref_validation'] = [
            'allowed_count' => count($allowed_refs),
            'invalid_evidence_refs' => array_values(array_unique($invalid_all)),
            'status' => empty($invalid_all) ? 'clean' : 'invalid_refs_removed',
        ];
        return $structured;
    }

    private static function normalize_brand_audit_insight_opportunity_refs($structured) {
        $structured = is_array($structured) ? $structured : [];
        foreach ((array) ($structured['insights'] ?? []) as $i => $insight) {
            if (!is_array($insight)) { continue; }
            $refs = $insight['evidence_refs'] ?? $insight['evidence_ids'] ?? [];
            if (is_string($refs)) { $refs = preg_split('/[\n,]+/', $refs); }
            $refs = is_array($refs) ? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $refs)))) : [];
            $structured['insights'][$i]['evidence_refs'] = $refs;
            $structured['insights'][$i]['evidence_ids'] = $refs;
            $structured['insights'][$i]['explanation'] = (string) ($insight['explanation'] ?? $insight['summary'] ?? '');
        }
        foreach ((array) ($structured['opportunities'] ?? []) as $i => $opportunity) {
            if (!is_array($opportunity)) { continue; }
            $refs = $opportunity['evidence_refs'] ?? $opportunity['evidence_ids'] ?? [];
            if (is_string($refs)) { $refs = preg_split('/[\n,]+/', $refs); }
            $refs = is_array($refs) ? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $refs)))) : [];
            $structured['opportunities'][$i]['evidence_refs'] = $refs;
            $structured['opportunities'][$i]['evidence_ids'] = $refs;
            $structured['opportunities'][$i]['why_it_matters'] = (string) ($opportunity['why_it_matters'] ?? $opportunity['description'] ?? '');
            $structured['opportunities'][$i]['impact'] = (string) ($opportunity['impact'] ?? $opportunity['business_value'] ?? 'medium');
            $structured['opportunities'][$i]['suggested_next_step'] = (string) ($opportunity['suggested_next_step'] ?? $opportunity['recommended_action'] ?? '');
            $structured['opportunities'][$i]['content_or_campaign_angle'] = (string) ($opportunity['content_or_campaign_angle'] ?? $opportunity['campaign_angle'] ?? '');
        }
        return $structured;
    }

    private static function build_assistant_response_format() {
        return [
            'type' => 'json_schema',
            'name' => 'future_island_assistant_answer',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'direct_answer' => ['type' => 'string'],
                    'evidence_used' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'strategic_implication' => ['type' => 'string'],
                    'recommended_next_step' => ['type' => 'string'],
                    'suggested_workflows' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'confidence' => ['type' => 'number'],
                    'limitations' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['direct_answer', 'evidence_used', 'strategic_implication', 'recommended_next_step', 'suggested_workflows', 'confidence', 'limitations'],
            ],
        ];
    }

    /**
     * v0.9.24.75 — neutral alias for the assistant entrypoint.
     * Use this in new code; answer_future_island_assistant() is kept only for
     * back-compat with existing call sites that already passed lint.
     */
    public static function answer_assistant($question, $context_pack = [], $project_context = null, $memory_pack = []) {
        return self::answer_future_island_assistant($question, $context_pack, $project_context, $memory_pack);
    }

    public static function answer_future_island_assistant($question, $context_pack = [], $project_context = null, $memory_pack = []) {
        $primary_model = VES_Config::get_openai_model('assistant');
        $question = sanitize_textarea_field((string) $question);
        if ($question === '') {
            return new WP_Error('assistant_empty_question', 'Assistant question is empty.');
        }
        $context_pack = is_array($context_pack) ? $context_pack : [];
        $memory_pack = is_array($memory_pack) ? $memory_pack : [];
        $project_context_clean = self::normalize_project_context($project_context);
        $brand_name = sanitize_text_field((string) ($context_pack['brand_name'] ?? ($context_pack['current_run_summary']['brand_name'] ?? '')));
        $default_workspace_label2 = class_exists('VES_Branding') ? VES_Branding::workspace_name() : 'Intelligence Workspace';
        $variables = [
            'workspace_name' => sanitize_text_field((string) ($context_pack['workspace_name'] ?? $default_workspace_label2)),
            'brand_name' => $brand_name,
            'country_market' => sanitize_text_field((string) ($context_pack['country_market'] ?? $context_pack['country'] ?? '')),
            'industry_category' => sanitize_text_field((string) ($context_pack['industry_category'] ?? $context_pack['industry'] ?? '')),
            'user_goal' => sanitize_textarea_field((string) ($context_pack['user_goal'] ?? $context_pack['research_goal'] ?? '')),
            'context_type' => sanitize_key((string) ($context_pack['context_type'] ?? 'brand_audit')),
            'selected_context' => wp_json_encode($context_pack['selected_context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'current_run_summary' => wp_json_encode($context_pack['current_run_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'evidence_summary' => wp_json_encode($context_pack['evidence_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'relevant_evidence' => wp_json_encode($context_pack['relevant_evidence'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'relevant_insights' => wp_json_encode($context_pack['relevant_insights'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'relevant_opportunities' => wp_json_encode($context_pack['relevant_opportunities'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'workspace_memory' => wp_json_encode($memory_pack['records'] ?? $context_pack['memory_records'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'brand_memory' => wp_json_encode($context_pack['brand_memory'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'user_preferences' => wp_json_encode($context_pack['user_preferences'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'known_limitations' => wp_json_encode($context_pack['known_limitations'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'user_question' => $question,
            // v0.9.24.75 — neutral assistant identity injected so the system prompt is white-labelable.
            'assistant_name' => class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant',
        ];
        $assistant_label = class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant';
        $system_prompt = 'You are ' . $assistant_label . ', a context-aware marketing intelligence operator. Use only the provided context and be honest about limitations. Separate evidence from inference. Always state confidence and missing data. Never fabricate metrics.';
        $template_user = '';
        if (class_exists('VES_AI_Prompt_Templates') && method_exists('VES_AI_Prompt_Templates', 'render')) {
            $assistant_template = VES_AI_Prompt_Templates::render('future_island_assistant_system', $variables);
            if (is_array($assistant_template)) {
                $system_prompt = (string) ($assistant_template['system'] ?? $system_prompt);
                $template_user = (string) ($assistant_template['user'] ?? '');
            } elseif (is_string($assistant_template) && $assistant_template !== '') {
                $system_prompt = $assistant_template;
            }
        }

        $input = ($template_user !== '' ? ("Assistant template context:\n" . $template_user . "\n\n") : '') . "Assistant context pack:\n" . wp_json_encode([
            'question' => $question,
            'context_pack' => $context_pack,
            'project_context' => $project_context_clean,
            'memory_pack' => $memory_pack,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $models = array_values(array_unique(array_filter([$primary_model, 'gpt-4.1-mini', 'gpt-4o-mini'])));
        $errors = [];
        foreach ($models as $model) {
            $model = trim((string) $model);
            if ($model === '') { continue; }
            foreach (['json_schema', 'json_text'] as $mode) {
                $body = [
                    'model' => $model,
                    '_ves_purpose' => 'assistant',
                    'instructions' => $system_prompt . ' Return a practical answer. Do not invent facts. Use the output schema.',
                    'input' => $input,
                    'max_output_tokens' => min(1800, max(900, self::trend_max_output_tokens($model, false))),
                    'store' => false,
                ];
                if ($mode === 'json_schema') {
                    $body['text'] = ['format' => self::build_assistant_response_format()];
                } else {
                    $body['instructions'] .= ' Return only valid JSON with keys: direct_answer, evidence_used, strategic_implication, recommended_next_step, suggested_workflows, confidence, limitations.';
                }
                if (self::is_gpt5_family($model)) {
                    $body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')];
                }
                $json = self::request_responses_api($body);
                if (is_wp_error($json)) {
                    $errors[] = $model . '/' . $mode . ': ' . $json->get_error_message();
                    continue;
                }
                $answer = self::extract_output_json($json);
                if (!is_array($answer) || empty($answer)) {
                    $errors[] = $model . '/' . $mode . ': empty_or_invalid_json';
                    continue;
                }
                $answer['direct_answer'] = sanitize_textarea_field((string) ($answer['direct_answer'] ?? ''));
                $answer['evidence_used'] = array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($answer['evidence_used'] ?? []))), 0, 8));
                $answer['strategic_implication'] = sanitize_textarea_field((string) ($answer['strategic_implication'] ?? ''));
                $answer['recommended_next_step'] = sanitize_textarea_field((string) ($answer['recommended_next_step'] ?? ''));
                $answer['suggested_workflows'] = array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($answer['suggested_workflows'] ?? []))), 0, 8));
                $answer['confidence'] = max(0, min(1, (float) ($answer['confidence'] ?? 0.5)));
                $answer['limitations'] = array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($answer['limitations'] ?? []))), 0, 8));
                return [
                    'answer' => $answer,
                    'model' => $model,
                    'format' => $mode,
                    'attempts' => $errors,
                ];
            }
        }
        $assistant_label_err = class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant';
        $message = $assistant_label_err . ' failed to generate a usable answer.';
        if (!empty($errors)) { $message .= ' Last errors: ' . implode(' | ', array_slice($errors, -4)); }
        return new WP_Error('assistant_openai_failed', $message);
    }

    private static function build_brief_response_format() {
        $string_array = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'json_schema',
            'name' => 'future_island_brief_from_opportunity',
            'strict' => false,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string'],
                    'objective' => ['type' => 'string'],
                    'audience' => ['type' => 'string'],
                    'key_insight' => ['type' => 'string'],
                    'proposition' => ['type' => 'string'],
                    'message_angles' => $string_array,
                    'content_formats' => $string_array,
                    'channel_recommendations' => $string_array,
                    'hooks' => $string_array,
                    'creative_direction' => ['type' => 'string'],
                    'proof_points' => $string_array,
                    'risks' => $string_array,
                    'next_steps' => $string_array,
                    'evidence_refs' => $string_array,
                    'limitations' => $string_array,
                ],
                'required' => ['title','objective','audience','key_insight','proposition','message_angles','content_formats','channel_recommendations','hooks','creative_direction','proof_points','risks','next_steps','evidence_refs','limitations'],
            ],
        ];
    }

    public static function generate_brief_from_opportunity($context, $project_context = null, $memory_pack = []) {
        $context = is_array($context) ? $context : [];
        $memory_pack = is_array($memory_pack) ? $memory_pack : [];
        $run_id = sanitize_text_field((string) ($context['run_id'] ?? ''));
        $allowed_refs = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::filter_valid_evidence_refs($run_id, $context['evidence_refs'] ?? []) : array_values((array) ($context['evidence_refs'] ?? []));
        $default_workspace_label3 = class_exists('VES_Branding') ? VES_Branding::workspace_name() : 'Intelligence Workspace';
        $variables = [
            'workspace_name' => sanitize_text_field((string) ($context['workspace_name'] ?? $default_workspace_label3)),
            'brand_name' => sanitize_text_field((string) ($context['brand_name'] ?? '')),
            'brief_type' => sanitize_key((string) ($context['brief_type'] ?? 'campaign')),
            'output_language' => sanitize_key((string) ($context['output_language'] ?? '')),
            'opportunity' => wp_json_encode($context['opportunity'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'linked_insights' => wp_json_encode($context['linked_insights'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'evidence_items' => wp_json_encode($context['evidence_items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'allowed_evidence_refs' => wp_json_encode($allowed_refs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'workspace_memory' => wp_json_encode($memory_pack['records'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'project_context' => wp_json_encode(self::normalize_project_context($project_context), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
        $system_prompt = 'You are a senior marketing strategist. Generate a concise evidence-grounded brief. Return valid JSON only.';
        $user_prompt = "Brief context:\n" . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (class_exists('VES_AI_Prompt_Templates') && method_exists('VES_AI_Prompt_Templates', 'render')) {
            $template = VES_AI_Prompt_Templates::render('brief_from_opportunity', $variables);
            if (is_array($template)) {
                $system_prompt = (string) ($template['system'] ?? $system_prompt);
                $user_prompt = (string) ($template['user'] ?? $user_prompt);
            }
        }
        $models = array_values(array_unique(array_filter([VES_Config::get_openai_model('assistant'), VES_Config::get_openai_model('fast_analysis'), 'gpt-4.1-mini', 'gpt-4o-mini'])));
        $errors = [];
        foreach ($models as $model) {
            $model = trim((string) $model);
            if ($model === '') { continue; }
            foreach (['json_schema','json_text'] as $mode) {
                $body = [
                    'model' => $model,
                    'instructions' => $system_prompt . "\nUse only allowed evidence refs. If no evidence supports a claim, put it in limitations. No fake performance claims.",
                    'input' => $user_prompt,
                    'max_output_tokens' => min(2200, max(1000, self::trend_max_output_tokens($model, false))),
                    'store' => false,
                ];
                if ($mode === 'json_schema') { $body['text'] = ['format' => self::build_brief_response_format()]; }
                else { $body['instructions'] .= ' Return only valid JSON with the required brief keys.'; }
                if (self::is_gpt5_family($model)) { $body['reasoning'] = ['effort' => self::normalize_reasoning_effort($model, 'low')]; }
                $json = self::request_responses_api($body);
                if (is_wp_error($json)) { $errors[] = $model . '/' . $mode . ': ' . $json->get_error_message(); continue; }
                $brief = self::extract_output_json($json);
                if (!is_array($brief) || empty($brief)) { $errors[] = $model . '/' . $mode . ': invalid_json'; continue; }
                $brief = self::normalize_brief_output($brief, $run_id, $allowed_refs);
                return ['brief' => $brief, 'model' => $model, 'format' => $mode, 'attempts' => $errors];
            }
        }
        return new WP_Error('brief_openai_failed', 'OpenAI failed to generate a usable brief. ' . implode(' | ', array_slice($errors, -3)));
    }

    private static function normalize_brief_output($brief, $run_id, $allowed_refs = []) {
        $brief = is_array($brief) ? $brief : [];
        $string_keys = ['title','objective','audience','key_insight','proposition','creative_direction'];
        foreach ($string_keys as $key) { $brief[$key] = sanitize_textarea_field((string) ($brief[$key] ?? '')); }
        $array_keys = ['message_angles','content_formats','channel_recommendations','hooks','proof_points','risks','next_steps','limitations'];
        foreach ($array_keys as $key) {
            $brief[$key] = array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($brief[$key] ?? []))), 0, 18));
        }
        if (class_exists('VES_Evidence_Store')) {
            $refs = VES_Evidence_Store::normalize_evidence_refs($brief['evidence_refs'] ?? [], 40);
            $brief['evidence_refs'] = VES_Evidence_Store::filter_valid_evidence_refs($run_id, $refs);
            $invalid = array_values(array_diff($refs, $brief['evidence_refs']));
            if (!empty($invalid)) { $brief['limitations'][] = 'Some invalid evidence refs were removed from the brief.'; }
        } else {
            $brief['evidence_refs'] = array_values(array_intersect((array) ($brief['evidence_refs'] ?? []), (array) $allowed_refs));
        }
        if (empty($brief['evidence_refs'])) { $brief['limitations'][] = 'No valid current-run evidence refs support this brief; treat as hypothesis.'; }
        return $brief;
    }


    public static function analyze_trend_bundle($request, $source_results, $related_memory = [], $project_context = null) {
        $model = VES_Config::get_openai_model('fast_analysis');
        $request = function_exists('ves_trend_sanitize_request') ? ves_trend_sanitize_request($request) : (array) $request;

        $max_ai_items = class_exists('VES_Config') ? VES_Config::get_openai_max_evidence_items() : 24;
        $max_ai_refs = class_exists('VES_Config') ? VES_Config::get_openai_max_evidence_refs() : 24;
        $compact_sources = [];
        foreach ((array) $source_results as $source_key => $source) {
            $compact_sources[$source_key] = [
                'label' => $source['label'] ?? $source_key,
                'status' => $source['status'] ?? '',
                'dispatch_status' => $source['dispatch_status'] ?? '',
                'parse_status' => $source['parse_status'] ?? '',
                'analytical_usability_status' => $source['analytical_usability_status'] ?? '',
                'evidence_role' => $source['evidence_role'] ?? '',
                'output_validation_status' => $source['output_validation_status'] ?? '',
                'exclusion_reason' => $source['exclusion_reason'] ?? '',
                'notes' => $source['notes'] ?? '',
                'source_quality_class' => $source['source_quality_class'] ?? '',
                'source_quality_classification' => $source['source_quality_classification'] ?? ($source['source_quality_class'] ?? ''),
                'source_family' => $source['source_family'] ?? '',
                'source_role' => $source['source_role'] ?? '',
                'source_status' => $source['source_status'] ?? '',
                'item_count' => (int) ($source['item_count'] ?? 0),
                'usable_records_count' => (int) ($source['usable_records_count'] ?? 0),
                'rejected_records_count' => (int) ($source['rejected_records_count'] ?? 0),
                'direct_match_count' => (int) ($source['direct_match_count'] ?? 0),
                'adjacent_match_count' => (int) ($source['adjacent_match_count'] ?? 0),
                'ambient_signals' => (int) ($source['ambient_signals'] ?? 0),
                'format_signals' => (int) ($source['format_signals'] ?? 0),
                'pain_point_signals' => (int) ($source['pain_point_signals'] ?? 0),
                'discarded_rows' => (int) ($source['discarded_rows'] ?? 0),
                'highlights' => is_array($source['highlights'] ?? null) ? array_slice($source['highlights'], 0, min(8, $max_ai_refs)) : [],
                'normalized_evidence' => is_array($source['trend_signals'] ?? null) ? array_slice(array_map(static function($sig) {
                    unset($sig['raw']);
                    return $sig;
                }, $source['trend_signals']), 0, 12) : [],
                'items' => is_array($source['compact_items'] ?? null) ? array_slice($source['compact_items'], 0, $max_ai_items) : [],
            ];
        }

        if (!self::supports_structured_trend_output($model)) {
            $legacy = self::analyze_trend_bundle_legacy($model, $request, $compact_sources, $project_context, $related_memory);
            return is_wp_error($legacy) ? self::build_local_trend_analysis_fallback($request, $compact_sources, $legacy) : $legacy;
        }

        $structured = self::analyze_trend_bundle_structured($model, $request, $compact_sources, $project_context, $related_memory);
        if (!is_wp_error($structured)) {
            return $structured;
        }

        if (class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic('trend_json_fallback', $structured->get_error_message(), [
                'model' => $model,
                'error_code' => $structured->get_error_code(),
                'status' => is_array($structured->get_error_data()) ? ($structured->get_error_data()['status'] ?? '') : '',
            ]);
        }

        $legacy = self::analyze_trend_bundle_legacy($model, $request, $compact_sources, $project_context, $related_memory);
        if (!is_wp_error($legacy)) {
            return $legacy;
        }

        if (class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic('trend_local_fallback', 'OpenAI no devolvió análisis usable; se usará fallback local.', [
                'model' => $model,
                'error_code' => $legacy->get_error_code(),
            ]);
        }

        return self::build_local_trend_analysis_fallback($request, $compact_sources, $legacy);
    }
}
