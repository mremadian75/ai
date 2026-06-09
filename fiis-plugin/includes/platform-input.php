<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('ves_prepare_run_options')) {
    function ves_prepare_run_options() {
        return [
            'build'             => 'latest',
            'timeout'           => 0,
            'maxTotalChargeUsd' => ves_hard_max_charge_usd(),
            'waitForFinish'     => 20,
        ];
    }
}

if (!function_exists('ves_make_run_url')) {
    function ves_make_run_url($actor_slug, $options = []) {
        $actor_slug = str_replace('/', '~', trim((string) $actor_slug));
        $actor_slug = preg_replace('/[^A-Za-z0-9~\-_]/', '', $actor_slug);

        // v0.9.24.6: token is sent via Authorization header in VES_Apify_Client::request().
        $query = [
            'build'         => $options['build'] ?? 'latest',
            'waitForFinish' => $options['waitForFinish'] ?? 20,
        ];
        if (isset($options['memory']) && $options['memory'] > 0) {
            $query['memory'] = (int) $options['memory'];
        }
        if (array_key_exists('timeout', $options)) {
            $query['timeout'] = (int) $options['timeout'];
        }
        if (isset($options['maxTotalChargeUsd']) && $options['maxTotalChargeUsd'] > 0) {
            $query['maxTotalChargeUsd'] = (float) $options['maxTotalChargeUsd'];
        }
        return add_query_arg($query, "https://api.apify.com/v2/acts/{$actor_slug}/runs");
    }
}

if (!function_exists('ves_fetch_items_by_run')) {
    function ves_fetch_items_by_run($run_id, $limit = 150) {
        return VES_Apify_Client::fetch_items($run_id, $limit);
    }
}

if (!function_exists('ves_sanitize_upstream_message')) {
    function ves_sanitize_upstream_message($message) {
        $message = (string) $message;
        if ($message === '') return '';
        if (class_exists('VES_Query_Planner')) {
            try { $mapped = VES_Query_Planner::provider_error_public_message('', $message); } catch (Throwable $e) { $mapped = ''; }
            if ($mapped !== '') { return $mapped; }
        }
        // v0.9.24.12: Strip Apify-internal technical status messages that are
        // not meaningful to users and look like errors.
        $apify_internal_patterns = [
            '/^Scraped \d+\/\d+ profiles?.*/i',
            '/^Scraped \d+\/\d+ pages?.*/i',
            '/^Processed \d+ (?:item|page|result|url|request)/i',
            '/^Crawled \d+ pages?/i',
            '/^Run finished/i',
            '/^Actor finished/i',
            '/^Memory limit exceeded/i', // keep - legitimate error, below
        ];
        foreach ($apify_internal_patterns as $i => $pat) {
            // Only suppress if it is the ENTIRE message (Apify status line)
            if ($i !== 6 && preg_match($pat, $message)) {
                return ''; // suppress, not an error
            }
        }
        $message = preg_replace('/apify_api_[A-Za-z0-9]+/i', '[token]', $message);
        $message = preg_replace('/sk-[A-Za-z0-9_\-]+/i', '[token]', $message);
        $message = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[token]', $message);
        $message = preg_replace('#https?://[^\s]+#i', '[url]', $message);
        $message = trim(preg_replace('/\s+/', ' ', $message));
        if (strlen($message) > 400) $message = substr($message, 0, 400) . '…';
        return $message;
    }
}


if (!function_exists('ves_query_planner_record_degraded')) {
    /**
     * v0.9.30.13: Query Planner must never be able to kill a provider run while
     * building legacy actor input. Diagnostics are useful; pre-dispatch fatals are not.
     */
    function ves_query_planner_record_degraded($stage, $error, $context = []) {
        if (!class_exists('VES_Admin')) { return; }
        try {
            $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
            $ctx = is_array($context) ? $context : [];
            $ctx['stage'] = sanitize_key((string) $stage);
            $ctx['provider_call_attempted'] = false;
            $ctx['provider_run_created'] = false;
            if ($error instanceof Throwable) {
                $ctx['exception_class'] = get_class($error);
                $ctx['file'] = $error->getFile();
                $ctx['line'] = $error->getLine();
            }
            VES_Admin::record_diagnostic('query_planner_input_build_degraded', $message, $ctx);
        } catch (Throwable $ignored) {}
    }
}

if (!function_exists('ves_query_planner_normalize_safe')) {
    function ves_query_planner_normalize_safe($module, $platform, $src) {
        if (!class_exists('VES_Query_Planner') || !method_exists('VES_Query_Planner', 'normalize_request_fields')) { return []; }
        try {
            $normalized = VES_Query_Planner::normalize_request_fields($module, $platform, is_array($src) ? $src : []);
            return is_array($normalized) ? $normalized : [];
        } catch (Throwable $e) {
            ves_query_planner_record_degraded('normalize_request_fields_failed', $e, ['module' => $module, 'platform' => $platform]);
            return [];
        }
    }
}

if (!function_exists('ves_query_planner_short_term_safe')) {
    function ves_query_planner_short_term_safe($term, $max_words = 6, $max_len = 80) {
        try {
            if (class_exists('VES_Query_Planner') && method_exists('VES_Query_Planner', 'short_term_or_empty')) {
                return (string) VES_Query_Planner::short_term_or_empty($term, $max_words, $max_len);
            }
        } catch (Throwable $e) {
            ves_query_planner_record_degraded('short_term_or_empty_failed', $e, ['term_preview' => substr((string) $term, 0, 80)]);
        }
        $term = sanitize_text_field((string) $term);
        $term = trim($term, " \t\n\r\0\x0B,.;:!?()[]{}\"'");
        if ($term === '' || preg_match('~^https?://~i', $term)) { return ''; }
        $word_count = preg_match_all('/[\p{L}\p{N}\+]+/u', $term, $m);
        $len = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
        if ($word_count > (int) $max_words || $len > (int) $max_len) { return ''; }
        return $term;
    }
}

if (!function_exists('ves_query_planner_extract_seo_keywords_safe')) {
    function ves_query_planner_extract_seo_keywords_safe($src) {
        try {
            if (class_exists('VES_Query_Planner') && method_exists('VES_Query_Planner', 'extract_seo_seed_keywords')) {
                $out = VES_Query_Planner::extract_seo_seed_keywords(is_array($src) ? $src : []);
                return is_array($out) ? $out : [];
            }
        } catch (Throwable $e) {
            ves_query_planner_record_degraded('extract_seo_seed_keywords_failed', $e, ['platform' => sanitize_key((string) ($src['platform'] ?? ''))]);
        }
        $raw = is_array($src) ? ($src['seedKeyword'] ?? $src['seed_keyword'] ?? $src['targets'] ?? '') : '';
        $out = [];
        foreach (preg_split('/[\r\n,;]+/u', (string) $raw) as $line) {
            $line = ves_query_planner_short_term_safe($line, 6, 80);
            if ($line !== '') { $out[] = $line; }
        }
        return array_slice(array_values(array_unique(array_filter($out))), 0, 10);
    }
}

if (!function_exists('ves_query_planner_looks_like_objective_safe')) {
    function ves_query_planner_looks_like_objective_safe($text) {
        try {
            if (class_exists('VES_Query_Planner') && method_exists('VES_Query_Planner', 'looks_like_objective')) {
                return (bool) VES_Query_Planner::looks_like_objective($text);
            }
        } catch (Throwable $e) {
            ves_query_planner_record_degraded('looks_like_objective_failed', $e, ['text_preview' => substr((string) $text, 0, 120)]);
        }
        $text = strtolower(sanitize_text_field((string) $text));
        $word_count = preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
        return $word_count > 8 || (bool) preg_match('/\b(identify|analyze|analyse|discover|compare|generate|brief|objective|goal|campaign|insight|recommend|analizar|identificar|comparar|generar|objetivo|campan|recomendar)\b/u', $text);
    }
}

if (!function_exists('ves_query_planner_keyword_candidates_safe')) {
    function ves_query_planner_keyword_candidates_safe($text) {
        try {
            if (class_exists('VES_Query_Planner') && method_exists('VES_Query_Planner', 'extract_keyword_candidates_from_sentence')) {
                $out = VES_Query_Planner::extract_keyword_candidates_from_sentence($text);
                return is_array($out) ? $out : [];
            }
        } catch (Throwable $e) {
            ves_query_planner_record_degraded('extract_keyword_candidates_failed', $e, ['text_preview' => substr((string) $text, 0, 120)]);
        }
        return [];
    }
}

if (!function_exists('ves_query_planner_conservative_social_queries_safe')) {
    function ves_query_planner_conservative_social_queries_safe($platform, $clean, $normalized) {
        try {
            if (class_exists('VES_Query_Planner') && method_exists('VES_Query_Planner', 'conservative_social_queries')) {
                $out = VES_Query_Planner::conservative_social_queries($platform, $clean, is_array($normalized) ? $normalized : []);
                return is_array($out) ? $out : [];
            }
        } catch (Throwable $e) {
            ves_query_planner_record_degraded('conservative_social_queries_failed', $e, ['platform' => $platform]);
        }
        return is_array($clean) ? $clean : [];
    }
}


if (!function_exists('ves_extract_apify_cost_usd')) {
    function ves_extract_apify_cost_usd($run_body) {
        $data = isset($run_body['data']) && is_array($run_body['data']) ? $run_body['data'] : (is_array($run_body) ? $run_body : []);
        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $candidates = [];
        foreach (['usageTotalUsd', 'totalChargeUsd', 'totalUsd', 'totalCharge', 'costUsd', 'totalUsageUsd', 'total_charge_usd'] as $key) {
            if (isset($data[$key])) { $candidates[] = $data[$key]; }
        }
        foreach (['totalChargeUsd', 'totalUsd', 'totalCharge', 'costUsd', 'usageTotalUsd', 'totalUsageUsd', 'total_charge_usd'] as $key) {
            if (isset($usage[$key])) { $candidates[] = $usage[$key]; }
        }
        if (isset($usage['totalCharge']) && is_array($usage['totalCharge'])) {
            foreach (['usd', 'amountUsd', 'costUsd'] as $key) {
                if (isset($usage['totalCharge'][$key])) { $candidates[] = $usage['totalCharge'][$key]; }
            }
        }
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) { return (float) $candidate; }
        }
        return null;
    }
}

if (!function_exists('ves_format_run_response')) {
    function ves_format_run_response($platform, $run_body, $items = null) {
        $data   = isset($run_body['data']) && is_array($run_body['data']) ? $run_body['data'] : [];
        $run_id = $data['id'] ?? '';
        $status = $data['status'] ?? 'UNKNOWN';
        $stats  = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : [];
        $usage  = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $upstream_message = isset($data['statusMessage']) ? (string) $data['statusMessage'] : '';
        $exit_code = isset($data['exitCode']) ? (int) $data['exitCode'] : null;

        if (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true) && $upstream_message !== '') {
            $safe_log_message = ves_sanitize_upstream_message($upstream_message);
            if ($safe_log_message !== '') {
                error_log('[Vietnam Estudio Social] run ' . sanitize_text_field((string) $run_id) . ' ' . sanitize_key((string) $status) . ' - ' . $safe_log_message);
            }
        }

        if (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true) && class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic('actor_run_status', ves_sanitize_upstream_message($upstream_message !== '' ? $upstream_message : $status), [
                'run_id' => $run_id,
                'platform' => $platform,
                'status' => $status,
                'exit_code' => $exit_code,
            ]);
        }

        // Surface a sanitised version of the upstream message (token-free) so users can
        // understand why a run failed without leaking internal details.
        $public_message = ves_sanitize_upstream_message($upstream_message);

        // If the actor SUCCEEDED but returned zero items, surface a helpful hint.
        if ($status === 'SUCCEEDED' && is_array($items) && count($items) === 0 && $public_message === '') {
            $public_message = __ves_empty_hint($platform);
        }

        $total_charge_usd = function_exists('ves_extract_apify_cost_usd') ? ves_extract_apify_cost_usd($run_body) : null;

        // v0.9.30.2 — provider mechanics isolation.
        // total_charge_usd / provider_cost_usd are USD provider costs and must NOT
        // reach normal users. They are intentionally still returned here because
        // server-side billing/settlement (VES_AJAX_Controller and the brand-audit
        // execution service) reads them from this array BEFORE the response is
        // sent. They are stripped from the user-facing payload at the boundary by
        // VES_AJAX_Controller::strip_admin_diagnostics() (blocked-key list), so
        // normal users never receive them while admin/debug sessions still do.
        // run_id is also retained for the frontend poller; replacing it with a
        // public-safe run token is a remaining beta blocker tracked for v0.10.0
        // (see KNOWN_LIMITATIONS.md).
        return [
            'platform'           => $platform,
            'run_id'             => $run_id,
            'status'             => $status,
            'status_message'     => $public_message,
            'exit_code'          => $exit_code,
            'item_count_preview' => is_array($items) ? count($items) : 0,
            'items'              => is_array($items) ? $items : [],
            'runtime_secs'       => isset($stats['runTimeSecs']) ? (float) $stats['runTimeSecs'] : null,
            'total_charge_usd'   => $total_charge_usd,
            'provider_cost_usd'  => $total_charge_usd,
        ];
    }
}

if (!function_exists('__ves_empty_hint')) {
    function __ves_empty_hint($platform) {
        switch (sanitize_key((string) $platform)) {
            case 'tiktok':
                return 'El perfil fue detectado, pero TikTok no devolvió el feed de vídeos. Prueba con proxy ES/US, sin mínimo de views, o usa búsqueda por autor.';
            case 'facebook':
                return 'La ejecución terminó correctamente pero no se obtuvieron posts. Verifica que la URL corresponda a una página pública (no a un perfil personal) y que sea accesible sin login. Prueba a quitar la fecha y los filtros mínimos.';
            case 'instagram':
                return 'La ejecución terminó pero sin resultados. Si buscaste por hashtag: Instagram bloquea sus páginas /explore/ para scrapers sin sesión. Usa el modo "Por URLs" con URLs directas de posts/perfiles públicos, o quita el filtro "Visualizaciones mínimas" para descartar que esté bloqueando resultados.';
            case 'facebook_ads':
                return 'La ejecución terminó correctamente pero no se obtuvieron anuncios. Verifica la URL de Ad Library o la página, y prueba con "Active status: All" y un país específico.';
            case 'google_ads':
                return 'La ejecución terminó correctamente pero no se obtuvieron anuncios de Google. Verifica el dominio, keyword, advertiser ID o URL de Google Ads Transparency, y prueba con otro país o con Worldwide.';
            case 'twitter':
                return 'La ejecución terminó correctamente pero no se obtuvieron tweets. Si usaste "Latest" prueba con "Top". Quita filtros como verified/blue/media para descartar.';
            case 'linkedin':
                return 'La ejecución terminó correctamente pero no se obtuvieron perfiles de LinkedIn. Prueba con una búsqueda más amplia, menos filtros o aumenta el límite.';
            case 'reddit':
                return 'La ejecución terminó correctamente pero no se obtuvieron resultados de Reddit. Prueba con una búsqueda más amplia, una comunidad válida, menos filtros o URLs directas de Reddit.';
            case 'pinterest':
                return 'La ejecución terminó correctamente pero Pinterest no devolvió pins ni perfiles. Prueba con una keyword más amplia, URLs públicas de Pinterest o reduce opciones como comentarios.';
            case 'semrush':
                return 'La ejecución terminó correctamente pero Semrush no devolvió datos. Prueba con una keyword/dominio más claro, menos módulos activos o un país diferente.';
            case 'moz':
                return 'La ejecución terminó correctamente pero MOZ no devolvió datos. Revisa dominios/URLs y prueba con menos opciones.';
            case 'ahrefs':
                return 'La ejecución terminó correctamente pero Ahrefs no devolvió datos. Revisa dominio, keyword, país o el tipo de análisis seleccionado.';
            case 'youtube':
                return 'La ejecución terminó correctamente pero no se obtuvieron vídeos. Prueba otro término de búsqueda o un rango de fechas más amplio.';
        }
        return 'La ejecución terminó correctamente pero sin resultados. Revisa los filtros aplicados.';
    }
}

if (!function_exists('ves_parse_csv_terms')) {
    function ves_parse_csv_terms($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return [];
        $parts = preg_split('/[\\r\\n,]+/', $raw);
        $items = [];
        foreach ($parts as $part) {
            $part = sanitize_text_field(trim((string) $part));
            if ($part !== '') $items[] = $part;
        }
        return array_values(array_unique($items));
    }
}

if (!function_exists('ves_parse_twitter_handles')) {
    function ves_parse_twitter_handles($raw) {
        $lines = preg_split('/\\r\\n|\\r|\\n/', (string) $raw);
        $items = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;
            $line = ltrim($line, '@');
            $line = preg_replace('/[^A-Za-z0-9_]/', '', $line);
            if ($line !== '') $items[] = $line;
        }
        return array_values(array_unique($items));
    }
}


if (!function_exists('ves_linkedin_lines')) {
    function ves_linkedin_lines($raw, $max = 50, $url_only = false) {
        $items = [];
        foreach (ves_array_field_values($raw, max(1, (int) $max)) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if ($url_only) {
                $url = function_exists('ves_normalize_url') ? ves_normalize_url($line) : esc_url_raw($line);
                if ($url === '') {
                    continue;
                }
                $items[] = esc_url_raw($url);
            } else {
                $items[] = sanitize_text_field($line);
            }
            if (count($items) >= max(1, (int) $max)) {
                break;
            }
        }
        return array_values(array_unique(array_filter($items)));
    }
}

if (!function_exists('ves_linkedin_id_lines')) {
    function ves_linkedin_id_lines($raw, $max = 50) {
        $items = [];
        foreach (ves_array_field_values($raw, max(1, (int) $max)) as $line) {
            $line = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
            if (count($items) >= max(1, (int) $max)) {
                break;
            }
        }
        return array_values(array_unique($items));
    }
}


if (!function_exists('ves_reddit_terms')) {
    function ves_reddit_terms($raw, $max = 50) {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string) $raw) as $line) {
            $line = trim(sanitize_text_field((string) $line));
            if ($line === '') { continue; }
            $items[] = $line;
            if (count($items) >= max(1, (int) $max)) { break; }
        }
        return array_values(array_unique($items));
    }
}

if (!function_exists('ves_reddit_community_name')) {
    function ves_reddit_community_name($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') { return ''; }
        if (preg_match('~reddit\.com/r/([^/\s?#]+)~i', $raw, $m)) {
            $raw = $m[1];
        }
        $raw = preg_replace('~^/?r/~i', '', $raw);
        $raw = preg_replace('/[^A-Za-z0-9_\-]/', '', $raw);
        return sanitize_text_field($raw);
    }
}

if (!function_exists('ves_reddit_time_from_preset')) {
    function ves_reddit_time_from_preset($preset) {
        $preset = sanitize_key((string) $preset);
        $map = [
            '1d' => 'day',
            '7d' => 'week',
            '14d' => 'month',
            '30d' => 'month',
            '60d' => 'year',
            '90d' => 'year',
            '180d' => 'year',
            '365d' => 'year',
            'any' => 'all',
        ];
        return $map[$preset] ?? 'all';
    }
}

if (!function_exists('ves_parse_digits_lines')) {
    function ves_parse_digits_lines($raw) {
        $lines = preg_split('/\\r\\n|\\r|\\n/', (string) $raw);
        $items = [];
        foreach ($lines as $line) {
            $line = preg_replace('/\D+/', '', (string) $line);
            if ($line !== '') $items[] = $line;
        }
        return array_values(array_unique($items));
    }
}

if (!function_exists('ves_fb_ads_period_from_preset')) {
    function ves_fb_ads_period_from_preset($preset) {
        $map = [
            '1d'  => 'last24h',
            '7d'  => 'last7d',
            '14d' => 'last14d',
            '30d' => 'last30d',
        ];
        return $map[sanitize_key((string) $preset)] ?? '';
    }
}


if (!function_exists('ves_google_ads_region_from_country')) {
    function ves_google_ads_region_from_country($country_code) {
        $country_code = strtoupper((string) $country_code);
        $map = [
            'ES' => 'Spain',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'FR' => 'France',
            'DE' => 'Germany',
            'IT' => 'Italy',
            'PT' => 'Portugal',
            'MX' => 'Mexico',
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'CO' => 'Colombia',
            'CL' => 'Chile',
            'PE' => 'Peru',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'NL' => 'Netherlands',
            'CH' => 'Switzerland',
            'SE' => 'Sweden',
            'IN' => 'India',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'AE' => 'United Arab Emirates',
            'TR' => 'Turkiye',
        ];
        return $map[$country_code] ?? 'WORLDWIDE';
    }
}

if (!function_exists('ves_google_ads_date_preset')) {
    function ves_google_ads_date_preset($preset) {
        $preset = sanitize_key((string) $preset);
        if ($preset === '1d') return 'TODAY';
        if ($preset === '7d') return 'LAST_7_DAYS';
        if (in_array($preset, ['14d', '30d'], true)) return 'LAST_30_DAYS';
        if ($preset === '60d') return 'ALL_TIME';
        return 'ALL_TIME';
    }
}

if (!function_exists('ves_country_search_terms')) {
    function ves_country_search_terms($country_code) {
        $country_code = strtoupper((string) $country_code);
        $map = [
            'ES' => ['España', 'Spain', 'Madrid'],
            'MX' => ['México', 'Mexico', 'mexicano'],
            'US' => ['United States', 'USA', 'US'],
            'GB' => ['United Kingdom', 'UK', 'Britain'],
            'FR' => ['France', 'Francia', 'Paris'],
            'DE' => ['Germany', 'Alemania', 'Deutschland'],
            'IT' => ['Italy', 'Italia'],
            'PT' => ['Portugal', 'portugues'],
            'BR' => ['Brasil', 'Brazil'],
            'AR' => ['Argentina'],
            'CO' => ['Colombia'],
            'CL' => ['Chile'],
            'PE' => ['Peru'],
        ];
        return $map[$country_code] ?? [];
    }
}

if (!function_exists('ves_contextual_search_phrases')) {
    /**
     * v0.9.10.0 — Build precise search queries without turning every plain
     * brand query into a hashtag scrape. Hashtag scraping is intentionally left
     * to explicit #inputs only because #brand-style searches are very noisy.
     */
    function ves_contextual_search_phrases($query, $src = [], $max = 8) {
        $query = trim(sanitize_text_field((string) $query));
        if ($query === '') {
            return [];
        }
        $country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($src['country'] ?? '') : '';
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($src['language'] ?? '') : '';
        $context = sanitize_textarea_field((string) ($src['searchContext'] ?? ($src['context'] ?? '')));

        // v0.9.22.0: build narrower search intent before Apify dispatch.
        // Exact brand/topic remains first, then brand + category, brand+market and brand+use-case variants.
        $phrases = [$query];
        $context_norm = function_exists('remove_accents') ? remove_accents($context) : $context;
        $context_norm = function_exists('mb_strtolower') ? mb_strtolower($context_norm, 'UTF-8') : strtolower($context_norm);
        $query_norm = function_exists('remove_accents') ? remove_accents($query) : $query;
        $query_norm = function_exists('mb_strtolower') ? mb_strtolower($query_norm, 'UTF-8') : strtolower($query_norm);

        if ($context_norm !== '') {
            if (strpos($context_norm, 'san miguel') !== false) {
                $phrases[] = $query . ' San Miguel';
            }
            if (strpos($context_norm, 'cinco estrellas') !== false) {
                $phrases[] = $query . ' Cinco Estrellas';
            }
            if (preg_match('/(?<![\p{L}\p{N}_])(cerveza|beer|lager)(?![\p{L}\p{N}_])/u', $context_norm)) {
                $phrases[] = $query . ' cerveza';
                $phrases[] = 'cerveza ' . $query;
            }
        }

        if ($context !== '') {
            $context_words = preg_split('/[^\p{L}\p{N}]+/u', $context);
            $added = 0;
            $context_stopwords = ['marca','brand','empresa','company','negocio','business','campana','campaña','campaign','sobre','para','con','the','and','los','las','una','uno','del','de','en','san','espana','españa','spain','madrid','barcelona','espanol','español','castellano'];
            foreach ((array) $context_words as $word) {
                $word = trim((string) $word);
                $norm = function_exists('remove_accents') ? remove_accents($word) : $word;
                $norm = function_exists('mb_strtolower') ? mb_strtolower($norm, 'UTF-8') : strtolower($norm);
                $len = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
                if ($len < 4 || $norm === $query_norm || in_array($norm, $context_stopwords, true)) {
                    continue;
                }
                $phrases[] = $query . ' ' . sanitize_text_field($word);
                $added++;
                if ($added >= 3) {
                    break;
                }
            }
        }

        // v0.9.24.62: Do not turn country/language constraints into extra
        // social search keywords by default. TikTok/Instagram/YouTube already have
        // local filters/region fields; adding phrases like "brand España" or
        // "brand español" makes provider search behave like an OR query and pulls
        // broad Spain/language noise that is later discarded at the user's expense.
        $platform_hint = sanitize_key((string) ($src['platform'] ?? ''));
        $is_social_discovery = in_array($platform_hint, ['tiktok', 'instagram', 'youtube', 'facebook', 'twitter', 'reddit', 'pinterest'], true);
        $allow_geo_keyword_expansion = !empty($src['allowGeoKeywordExpansion']) || !empty($src['allow_geo_keyword_expansion']);
        if (!$is_social_discovery || $allow_geo_keyword_expansion) {
            foreach (ves_country_search_terms($country) as $term) {
                $phrases[] = $query . ' ' . $term;
                if ($context_norm !== '' && preg_match('/(?<![\p{L}\p{N}_])(cerveza|beer|lager)(?![\p{L}\p{N}_])/u', $context_norm)) {
                    $phrases[] = $query . ' cerveza ' . $term;
                }
            }
            if ($language === 'es') {
                $phrases[] = $query . ' español';
            }
        }

        $clean = [];
        foreach ($phrases as $phrase) {
            $phrase = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $phrase)));
            if ($phrase !== '') {
                $clean[] = $phrase;
            }
        }
        return array_slice(array_values(array_unique($clean)), 0, max(1, (int) $max));
    }
}


if (!function_exists('ves_cap_multiquery_results_per_page')) {
    /**
     * Keep Apify search runs cost-predictable when contextual query expansion
     * creates several searchQueries. The Clockworks TikTok actor applies
     * resultsPerPage per query, so 5 expanded queries with resultsPerPage=100
     * can request up to 500 records when the user only asked for 20.
     */
    function ves_cap_multiquery_results_per_page($input, $actor_total_budget, $requested_limit = 20, $min_per_query = 5) {
        $input = is_array($input) ? $input : [];
        $queries = isset($input['searchQueries']) && is_array($input['searchQueries']) ? array_values($input['searchQueries']) : [];
        $query_count = count($queries);
        if ($query_count <= 1) {
            return $input;
        }
        $actor_total_budget = max(1, (int) $actor_total_budget);
        $requested_limit = max(1, (int) $requested_limit);
        $min_per_query = max(1, min(20, (int) $min_per_query));
        $current = isset($input['resultsPerPage']) ? max(1, (int) $input['resultsPerPage']) : $actor_total_budget;

        // Distribute the intended total actor budget across expanded queries.
        // Keep a small per-query floor so a no-threshold search does not become
        // too brittle, but never let query expansion multiply the intended budget.
        $cap = max($min_per_query, (int) ceil($actor_total_budget / max(1, $query_count)));
        $cap = max(1, min($current, $cap));
        $input['resultsPerPage'] = $cap;
        return $input;
    }
}

if (!function_exists('ves_array_field_values')) {
    function ves_array_field_values($value, $max = 100) {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                foreach (ves_array_field_values($entry, $max) as $nested) {
                    $items[] = $nested;
                    if (count($items) >= $max) break 2;
                }
                continue;
            }
            $entry = sanitize_text_field(trim((string) $entry));
            if ($entry !== '') {
                $items[] = $entry;
                if (count($items) >= $max) break;
            }
        }
        return array_values(array_unique($items));
    }
}
if (!function_exists('ves_pick_allowed_values')) {
    function ves_pick_allowed_values($value, $allowed, $max = 100) {
        $allowed = array_map('strval', (array) $allowed);
        $items = ves_array_field_values($value, $max);
        return array_values(array_intersect($items, $allowed));
    }
}
if (!function_exists('ves_domain_or_url_lines')) {
    function ves_domain_or_url_lines($raw, $max = 100) {
        $lines = ves_array_field_values($raw, $max);
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;

            // v0.9.24.24: Do not manufacture fake domains from keyword lines.
            // The old sanitizer removed spaces/slashes, so "seo tools example.com"
            // became "seotoolsexample.com" and "example.com/page" became
            // "example.compage". Accept only valid domains or URLs.
            if (preg_match('/\s/u', $line)) {
                continue;
            }

            $normalized_url = function_exists('ves_normalize_url') ? ves_normalize_url($line) : '';
            if ($normalized_url !== '') {
                $host = parse_url($normalized_url, PHP_URL_HOST);
                $path = parse_url($normalized_url, PHP_URL_PATH);
                $query = parse_url($normalized_url, PHP_URL_QUERY);
                // Plain domains are cleaner for domain-analysis actors; full URLs
                // are preserved when the user entered a path or query string.
                if ($host && ($path === null || $path === '' || $path === '/') && ($query === null || $query === '')) {
                    $out[] = strtolower((string) $host);
                } else {
                    $out[] = esc_url_raw($normalized_url);
                }
                continue;
            }

            if (preg_match('~^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$~i', $line)) {
                $out[] = strtolower($line);
            }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $max))));
    }
}


if (!function_exists('ves_tiktok_conservative_search_terms')) {
    /**
     * v0.9.30.4: keep TikTok first-pass keyword search narrow and predictable.
     * Prefer the primary query plus only a few close variants instead of generic hashtag bundles.
     */
    function ves_tiktok_conservative_search_terms($terms, $src = [], $max = 5) {
        $max = max(1, min(5, (int) $max));
        $terms = is_array($terms) ? $terms : preg_split('/\r\n|\r|\n|,/', (string) $terms);
        $clean = [];
        foreach ((array) $terms as $term) {
            $term = trim(sanitize_text_field((string) $term));
            if ($term === '' || preg_match('~^https?://~i', $term)) { continue; }
            $term = ltrim($term, '#');
            if ($term === '') { continue; }
            $clean[] = $term;
        }
        $clean = array_values(array_unique($clean));
        if (empty($clean)) { return []; }
        $normalized = ves_query_planner_normalize_safe('social', 'tiktok', is_array($src) ? $src : []);
        if (class_exists('VES_Query_Planner')) {
            $planned = ves_query_planner_conservative_social_queries_safe('tiktok', $clean, $normalized);
            return array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $planned)))), 0, $max);
        }
        $generic = ['marketing','digitalmarketing','tiktokmarketing','marketingtips','contentmarketing','socialmediamarketing','marketingstrategy','marketing101','marketingagency','tips','viral','trend','trends'];
        $primary = $clean[0] ?? '';
        $out = [];
        foreach ($clean as $term) {
            $compact = strtolower(preg_replace('/[^a-z0-9]+/i', '', $term));
            if ($term !== $primary && in_array($compact, $generic, true)) { continue; }
            $out[] = $term;
            if (count($out) >= $max) { break; }
        }
        return array_slice(array_values(array_unique($out)), 0, $max);
    }
}

if (!function_exists('ves_build_platform_input')) {
    function ves_build_platform_input($platform, $src) {
        $platform          = sanitize_key((string) ($platform ?? ''));
        $mode              = sanitize_key((string) ($src['mode'] ?? 'search'));
        $targetsRaw        = $src['targets'] ?? '';
        // v0.9.24.78: keep scraper/provider inputs separate from business objectives.
        // Legacy templates still post `targets`; explicit fields win when present.
        $ves_query_plan = ves_query_planner_normalize_safe($platform === 'semrush' ? 'seo' : 'social', $platform, $src);
        $ves_scraper_terms = isset($ves_query_plan['scraper_query_terms']) && is_array($ves_query_plan['scraper_query_terms']) ? $ves_query_plan['scraper_query_terms'] : [];
        $ves_seed_keywords = isset($ves_query_plan['seed_keywords']) && is_array($ves_query_plan['seed_keywords']) ? $ves_query_plan['seed_keywords'] : [];
        $ves_hashtags = isset($ves_query_plan['hashtags']) && is_array($ves_query_plan['hashtags']) ? $ves_query_plan['hashtags'] : [];
        $ves_target_urls = isset($ves_query_plan['target_urls']) && is_array($ves_query_plan['target_urls']) ? $ves_query_plan['target_urls'] : [];
        $ves_target_profiles = isset($ves_query_plan['target_profiles']) && is_array($ves_query_plan['target_profiles']) ? $ves_query_plan['target_profiles'] : [];
        // v0.9.9.7: capture user's requested limit, then over-fetch so post-filters
        // have headroom. The controller slices back down to $requested_limit after
        // filtering. Trend Finder and per-source actors are not affected because
        // they have their own dispatch path.
        $requested_limit   = ves_clean_int($src['limit'] ?? null, 1, ves_max_items());
        $is_post_url_mode  = function_exists('ves_is_post_url_mode') ? ves_is_post_url_mode($src) : in_array($mode, ['url_posts', 'post_urls', 'post_url'], true);
        $is_profile_url_mode = function_exists('ves_is_profile_url_mode') ? ves_is_profile_url_mode($src) : in_array($mode, ['url_profile', 'profile_url', 'profile_urls'], true);
        $is_mixed_url_mode = function_exists('ves_is_mixed_url_mode') ? ves_is_mixed_url_mode($src) : ($mode === 'urls');
        // v0.9.24.16: If cached frontend data submits a stale/empty mode but
        // the target is a profile URL, normalize it here so actor input and
        // fallback logic stay aligned.
        if ($platform === 'tiktok' && $is_profile_url_mode) {
            $mode = 'url_profile';
        }
        $limit             = $is_post_url_mode
            ? $requested_limit
            : (function_exists('ves_compute_actor_limit') ? ves_compute_actor_limit($requested_limit, $platform, $src) : $requested_limit); // v0.9.24.4: pass $src so over-fetch activates when minViews > 0
        // v0.9.9.4: resolve date filter from EITHER datePreset OR dateFromIso.
        // Calendar takes priority. Compute both so platform-specific actor inputs work.
        $date_filter       = function_exists('ves_resolve_date_filter_inputs')
            ? ves_resolve_date_filter_inputs($src)
            : ['preset' => sanitize_key((string) ($src['datePreset'] ?? 'any')), 'iso' => ''];
        $datePreset        = $date_filter['preset'];
        $dateFromIso       = $date_filter['iso'];
        $dateRange         = ves_date_preset_to_days($datePreset);
        $dateCode          = ves_yt_date_filter_code($datePreset);
        $tiktokDateCode    = function_exists('ves_tt_search_date_posted') ? ves_tt_search_date_posted($datePreset) : '0';
        $tiktokVideoDate  = function_exists('ves_tt_video_search_date_filter') ? ves_tt_video_search_date_filter($datePreset) : 'ALL_TIME';
        $minLikes          = function_exists('ves_requested_min_metric') ? ves_requested_min_metric($src) : ves_clean_int($src['minViews'] ?? ($src['minLikes'] ?? null), 0, null);
        $country           = ves_sanitize_country_code($src['country'] ?? 'None');
        $language          = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($src['language'] ?? '') : '';
        $defaultLimit      = $limit !== null ? $limit : 20;
        $includeTranscript = !array_key_exists('includeTranscript', (array) $src) ? false : !empty($src['includeTranscript']);
        $sortPriority      = function_exists('ves_sort_priority') ? ves_sort_priority($src) : sanitize_key((string) ($src['sortPriority'] ?? 'relevance'));

        // v0.9.24.79: Rewrite provider-facing target lines from explicit source fields.
        // Business objective/search context stay out of Apify payloads. Reddit and
        // Ads intentionally ignore hashtag logic because hashtags are not provider
        // targets for those actor families.
        if (class_exists('VES_Query_Planner')) {
            $planned_target_lines = [];
            $planner_sensitive_platforms = ['instagram', 'tiktok', 'reddit', 'youtube', 'twitter', 'facebook', 'facebook_ads', 'google_ads', 'pinterest'];
            if (in_array($platform, $planner_sensitive_platforms, true)) {
                foreach ((array) $ves_target_urls as $u) { $planned_target_lines[] = esc_url_raw($u); }
                if (in_array($platform, ['instagram', 'tiktok', 'twitter', 'youtube', 'pinterest'], true)) {
                    foreach ((array) $ves_target_profiles as $handle) {
                        $handle = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $handle);
                        if ($handle === '') { continue; }
                        if ($platform === 'instagram') { $planned_target_lines[] = 'https://www.instagram.com/' . $handle . '/'; }
                        elseif ($platform === 'tiktok') { $planned_target_lines[] = 'https://www.tiktok.com/@' . $handle; }
                        elseif ($platform === 'twitter') { $planned_target_lines[] = 'https://x.com/' . $handle; }
                    }
                }
                if (in_array($platform, ['instagram', 'tiktok', 'twitter', 'pinterest'], true)) {
                    foreach ((array) $ves_hashtags as $tag) {
                        $tag = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tag);
                        if ($tag !== '') { $planned_target_lines[] = '#' . $tag; }
                    }
                }
                if (in_array($platform, ['tiktok', 'reddit', 'youtube', 'twitter', 'facebook', 'facebook_ads', 'google_ads', 'pinterest'], true)) {
                    foreach ((array) $ves_scraper_terms as $term) {
                        $term = ves_query_planner_short_term_safe($term, 6, 80);
                        if ($term !== '') { $planned_target_lines[] = $term; }
                    }
                }
                if (in_array($platform, ['facebook_ads', 'google_ads'], true)) {
                    foreach (ves_query_planner_extract_seo_keywords_safe($src) as $term) {
                        if ($term !== '') { $planned_target_lines[] = $term; }
                    }
                }
                if (!empty($planned_target_lines) || ves_query_planner_looks_like_objective_safe($targetsRaw)) {
                    $targetsRaw = implode("\n", array_values(array_unique(array_filter($planned_target_lines))));
                }
            }
        }

        switch ($platform) {
            case 'tiktok':
                $actor_slug_for_input = function_exists('ves_get_actor_slug') ? ves_get_actor_slug('tiktok', $src) : '';
                $use_apidojo_tiktok_schema = function_exists('ves_tiktok_actor_uses_apidojo_schema')
                    ? ves_tiktok_actor_uses_apidojo_schema($actor_slug_for_input)
                    : (stripos((string) $actor_slug_for_input, 'apidojo/tiktok-scraper') !== false);

                // v0.9.24.48: Apidojo is the primary discovery adapter. It uses
                // keyword/startUrl-style inputs; Clockworks-only fields such as
                // searchQueries, searchDatePosted, videoSearchDateFilter and
                // oldestPostDateUnified must not be sent to it. Date constraints
                // remain locked in the product layer and are enforced locally after
                // collection when the actor has no equivalent safe date field.
                if ($use_apidojo_tiktok_schema) {
                    $apidojo_limit = max(1, min(1000, (int) ($limit !== null ? $limit : $defaultLimit)));
                    $input = [
                        'customMapFunction' => '(object) => { return {...object} }',
                        'dateRange' => function_exists('ves_apidojo_tiktok_date_range') ? ves_apidojo_tiktok_date_range($datePreset) : 'DEFAULT',
                        'includeSearchKeywords' => false,
                        // v0.9.24.67: do not silently default an unrestricted TikTok search to US.
                        // Apidojo has a US prefill in its UI schema, but the SaaS contract is stricter:
                        // only send location when the user explicitly locks a country.
                        'location' => ($country !== '' && $country !== 'None') ? strtoupper((string) $country) : '',
                        'maxItems' => $apidojo_limit,
                        'sortType' => function_exists('ves_apidojo_tiktok_sort_type') ? ves_apidojo_tiktok_sort_type($sortPriority) : 'RELEVANCE',
                    ];

                    $urls = [];
                    $keywords = [];
                    $keyword_terms = [];
                    $hashtag_terms = [];
                    foreach (preg_split('/\r\n|\r|\n/', (string) $targetsRaw) as $line) {
                        $line = trim((string) $line);
                        if ($line === '') { continue; }
                        $maybe_url = function_exists('ves_normalize_url') ? ves_normalize_url($line) : '';
                        if ($maybe_url !== '' && stripos((string) parse_url($maybe_url, PHP_URL_HOST), 'tiktok.com') !== false) {
                            $urls[] = esc_url_raw($maybe_url);
                            continue;
                        }
                        if (($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode) && $maybe_url === '') {
                            $handle = ltrim($line, "@ \t\n\r\0\x0B");
                            $handle = preg_replace('/[^A-Za-z0-9_.]/', '', $handle);
                            if ($handle !== '') {
                                $urls[] = 'https://www.tiktok.com/@' . $handle;
                                continue;
                            }
                        }
                        $term = sanitize_text_field($line);
                        if ($term === '') { continue; }
                        if (strpos($term, '#') === 0) {
                            $hashtag_terms[] = ltrim($term, '#');
                        } else {
                            $keyword_terms[] = $term;
                        }
                    }
                    $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));
                    if (!empty($urls) && function_exists('ves_filter_urls_for_mode')) {
                        list($urls, $wrong_urls) = ves_filter_urls_for_mode('tiktok', $urls, $src);
                    }
                    $keywords = function_exists('ves_tiktok_conservative_search_terms')
                        ? ves_tiktok_conservative_search_terms($keyword_terms, $src, 5)
                        : array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $keyword_terms)))), 0, 5);
                    if (empty($keywords) && !empty($hashtag_terms)) {
                        $keywords = function_exists('ves_tiktok_conservative_search_terms')
                            ? ves_tiktok_conservative_search_terms($hashtag_terms, $src, 3)
                            : array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $hashtag_terms)))), 0, 3);
                    }

                    if (!empty($urls)) {
                        $input['startUrls'] = $urls;
                        if ($is_post_url_mode) {
                            $input['maxItems'] = max(1, count($urls));
                        }
                    }
                    if (!empty($keywords)) {
                        $input['keywords'] = array_slice($keywords, 0, 5);
                    }
                    if (isset($input['location']) && trim((string) $input['location']) === '') {
                        unset($input['location']);
                    }
                    return $input;
                }

                $input = ['resultsPerPage' => $defaultLimit];
                if ($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode) {
                    $urls = [];
                    foreach (preg_split('/\r\n|\r|\n/', (string) $targetsRaw) as $line) {
                        $line = trim((string) $line);
                        if ($line === '') continue;
                        $u = function_exists('ves_normalize_url') ? ves_normalize_url($line) : '';
                        if ($u === '') {
                            $handle = ltrim($line, "@ \t\n\r\0\x0B");
                            $handle = preg_replace('/[^A-Za-z0-9_.]/', '', $handle);
                            if ($handle !== '') $u = 'https://www.tiktok.com/@' . $handle;
                        }
                        if ($u !== '') $urls[] = $u;
                    }
                    $urls = array_values(array_unique($urls));
                    list($urls, $wrong_urls) = function_exists('ves_filter_urls_for_mode')
                        ? ves_filter_urls_for_mode('tiktok', $urls, $src)
                        : [$urls, []];

                    if ($is_post_url_mode) {
                        if (!empty($urls)) {
                            $input['postURLs'] = $urls;
                            $input['resultsPerPage'] = max(1, count($urls));
                        }
                    } elseif ($is_mixed_url_mode) {
                        $post_urls = [];
                        $profile_urls = [];
                        foreach ($urls as $url) {
                            $kind = function_exists('ves_platform_url_kind') ? ves_platform_url_kind('tiktok', $url) : 'unknown';
                            if ($kind === 'post') {
                                $post_urls[] = $url;
                            } else {
                                $profile_urls[] = $url;
                            }
                        }
                        $post_urls = array_values(array_unique($post_urls));
                        $profile_urls = array_values(array_unique($profile_urls));
                        if (!empty($post_urls)) {
                            $input['postURLs'] = $post_urls;
                            $input['resultsPerPage'] = max(1, count($post_urls));
                        }
                        $profiles = function_exists('ves_tiktok_profile_handles_from_urls') ? ves_tiktok_profile_handles_from_urls($profile_urls) : [];
                        if (!empty($profiles)) {
                            $input['profiles'] = $profiles;
                            $input['profileScrapeSections'] = ['videos'];
                            $input['profileSorting'] = 'latest';
                            $input['excludePinnedPosts'] = false;
                            $input['maxProfilesPerQuery'] = max(1, count($profiles));
                            if ($dateFromIso !== '') {
                                $input['oldestPostDateUnified'] = $dateFromIso;
                            } elseif ($dateRange > 0) {
                                $input['oldestPostDateUnified'] = (string) max(1, (int) $dateRange);
                            }
                        }
                    } else {
                        $profiles = function_exists('ves_tiktok_profile_handles_from_urls') ? ves_tiktok_profile_handles_from_urls($urls) : [];
                        if (!empty($profiles)) {
                            $input['profiles'] = $profiles;
                            $input['profileScrapeSections'] = ['videos'];
                            $input['profileSorting'] = 'latest';
                            $input['excludePinnedPosts'] = false;
                            $input['maxProfilesPerQuery'] = max(1, count($profiles));
                            if ($dateFromIso !== '') {
                                $input['oldestPostDateUnified'] = $dateFromIso;
                            } elseif ($dateRange > 0) {
                                $input['oldestPostDateUnified'] = (string) max(1, (int) $dateRange);
                            }
                            // v0.9.24.13: ensure the actor fetches the requested
                            // number of videos from the profile, not just metadata.
                            // resultsPerPage controls how many VIDEOS per profile.
                            $profile_video_limit = $limit !== null ? (int) $limit : $defaultLimit;
                            $input['resultsPerPage'] = max(10, min(100, $profile_video_limit));
                        }
                    }

                } else {
                    $lines = preg_split('/\r\n|\r|\n/', (string) $targetsRaw);
                    $hashtags = [];
                    $queries = [];
                    foreach ((array) $lines as $line) {
                        $line = trim((string) $line);
                        if ($line === '') {
                            continue;
                        }
                        if (strpos($line, '#') === 0) {
                            $tag = ltrim($line, "# \t\n\r\0\x0B");
                            $tag = preg_replace('/[^A-Za-z0-9_.]/', '', $tag);
                            if ($tag !== '') {
                                $hashtags[] = $tag;
                            }
                        } else {
                            $clean_query = sanitize_text_field($line);
                            if ($clean_query !== '') {
                                $queries[] = $clean_query;
                                // v0.9.10.0: Do NOT auto-convert plain searches to hashtags.
                            }
                        }
                    }
                    if (!empty($hashtags)) {
                        $input['hashtags'] = array_values(array_unique($hashtags));
                    }
                    if (!empty($queries)) {
                        $input['searchQueries'] = function_exists('ves_tiktok_conservative_search_terms')
                            ? ves_tiktok_conservative_search_terms($queries, $src, 5)
                            : array_slice(array_values(array_unique($queries)), 0, 5);
                        $input['searchSection'] = '/video';
                        $input['searchSorting'] = '0';
                        if ($tiktokDateCode !== '0') {
                            // Legacy/date index kept for backward compatibility with older builds.
                            $input['searchDatePosted'] = $tiktokDateCode;
                        }
                        if ($tiktokVideoDate !== 'ALL_TIME') {
                            // Current clockworks/tiktok-scraper field. Without this, Apify merges
                            // its default videoSearchDateFilter=ALL_TIME into the run input.
                            $input['videoSearchDateFilter'] = $tiktokVideoDate;
                        }
                    }
                }
                // v0.9.24.15: TikTok discovery/profile runs must stay cheap and stable.
                // Transcription is only allowed for exact post URL runs. Profile/search runs
                // first collect candidate posts; selected posts can be transcribed later.
                if ($includeTranscript && $is_post_url_mode) {
                    $input['downloadSubtitlesOptions'] = 'DOWNLOAD_AND_TRANSCRIBE_VIDEOS_WITHOUT_SUBTITLES';
                } else {
                    $input['downloadSubtitlesOptions'] = 'NEVER_DOWNLOAD_SUBTITLES';
                }

                // When the user selected Spanish but left country as Any, use an ES proxy
                // as a practical default for Spanish TikTok profiles. This is a proxy hint,
                // not a post-filter; the country filter still runs later only when selected.
                // v0.9.24.16: TikTok profile feeds are geo-sensitive. If
                // the user leaves country as Any/None, use ES as a proxy hint
                // for profile collection. This is not a strict country filter.
                $input['proxyCountryCode'] = ($country !== '' && $country !== 'None')
                    ? $country
                    : (($language === 'es' || $is_profile_url_mode || $is_mixed_url_mode) ? 'ES' : 'None');

                if (!$is_post_url_mode && !$is_profile_url_mode && !$is_mixed_url_mode && function_exists('ves_cap_multiquery_results_per_page')) {
                    $input = ves_cap_multiquery_results_per_page($input, $defaultLimit, $requested_limit, 5);
                }

                // v0.9.24.15: do NOT pass minimumVideoPlayCount to the TikTok actor.
                // The field is not consistently supported across profile/search modes and
                // can make valid profiles return only metadata or zero posts. Keep minViews
                // as a local post-scrape filter via ves_apply_requested_filters().
                return $input;
            case 'youtube':
                $input = [
                    'maxResults'       => $limit !== null ? $limit : 10,
                    'maxResultsShorts' => 0,
                    'maxResultStreams' => 0,
                ];
                if ($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode) {
                    if ($is_profile_url_mode || $is_mixed_url_mode) {
                        $urls = [];
                        foreach (preg_split('/\r\n|\r|\n/', (string) $targetsRaw) as $line) {
                            $line = trim((string) $line);
                            if ($line === '') continue;
                            $u = ves_normalize_url($line);
                            if ($u === '') {
                                $handle = ltrim($line, "@ \t\n\r\0\x0B");
                                $handle = preg_replace('/[^A-Za-z0-9_.]/', '', $handle);
                                if ($handle !== '') $u = 'https://www.youtube.com/@' . $handle;
                            }
                            if ($u !== '') $urls[] = $u;
                        }
                        $urls = array_values(array_unique($urls));
                    } else {
                        $urls = ves_parse_lines($targetsRaw, 'url');
                    }
                    list($urls, $wrong_urls) = function_exists('ves_filter_urls_for_mode')
                        ? ves_filter_urls_for_mode('youtube', $urls, $src)
                        : [$urls, []];
                    if (!empty($urls)) {
                        $input['startUrls'] = ves_make_start_urls($urls);
                        if ($is_post_url_mode) {
                            $input['maxResults'] = max(1, count($urls));
                        }
                    }

                } else {
                    // v0.9.30.6: YouTube keyword search should be conservative.
                    // Keep the user's primary query first, then add only a few
                    // language-aligned close variants. Do not mix generic hashtag
                    // bundles or broad contextual terms into YouTube search by default.
                    $queries = [];
                    foreach (ves_parse_lines($targetsRaw, 'text') as $query_line) {
                        $query_line = trim((string) $query_line);
                        if ($query_line === '') { continue; }
                        $queries[] = $query_line;
                    }
                    $queries = function_exists('ves_youtube_search_terms')
                        ? ves_youtube_search_terms($queries, $src, 5)
                        : array_slice(array_values(array_unique(array_filter(array_map('strval', $queries)))), 0, 5);
                    if (!empty($queries)) { $input['searchQueries'] = $queries; }
                }
                if (!$is_post_url_mode) {
                    if ($dateFromIso !== '') {
                        $input['oldestPostDate'] = $dateFromIso;
                    } elseif ($dateRange) {
                        $input['oldestPostDate'] = $dateRange;
                    }
                    if ($language !== '') { $input['language'] = $language; }
                    if ($country !== '' && $country !== 'None') { $input['country'] = $country; }
                }
                if ($includeTranscript && (function_exists('ves_bool') ? ves_bool($src['strictSubtitleLanguage'] ?? ($src['requireSubtitles'] ?? false)) : in_array(strtolower((string) ($src['strictSubtitleLanguage'] ?? ($src['requireSubtitles'] ?? ''))), ['1','true','yes','on'], true))) {
                    // Subtitle language is an explicit transcript constraint only;
                    // never default to English for Spanish/general discovery runs.
                    $input['includeTranscript'] = true;
                    if ($language !== '') { $input['subtitlesLanguage'] = $language; }
                } elseif ($includeTranscript) {
                    // Keep transcript intent product-side without adding subtitle hard-filters.
                    $input['includeTranscript'] = true;
                }
                return $input;
            case 'facebook':
                $urls = ves_parse_lines($targetsRaw, 'url');
                $urls = array_values(array_unique(array_filter(array_map(static function ($u) {
                    $u = (string) $u;
                    if ($u === '') return '';
                    if (stripos($u, 'http') !== 0) $u = 'https://' . ltrim($u, '/');
                    $u = preg_replace('~^https?://(m|mobile|touch)\.facebook\.com~i', 'https://www.facebook.com', $u);
                    $u = preg_replace('~^https?://facebook\.com~i', 'https://www.facebook.com', $u);
                    $u = preg_replace('~\?.*$~', '', $u);
                    if (preg_match('~^https?://www\.facebook\.com/[^/?#]+$~i', $u)) $u .= '/';
                    return esc_url_raw($u);
                }, $urls))));
                list($urls, $wrong_urls) = function_exists('ves_filter_urls_for_mode')
                    ? ves_filter_urls_for_mode('facebook', $urls, $src)
                    : [$urls, []];
                $input = [];
                if (!empty($urls)) {
                    $input['startUrls'] = array_map(static fn ($url) => ['url' => $url], $urls);
                }
                if ($limit !== null && !$is_post_url_mode) $input['resultsLimit'] = $limit;
                if ($is_post_url_mode && !empty($urls)) $input['resultsLimit'] = max(1, count($urls));
                if ($includeTranscript) {
                    $input['captionText'] = true;
                }
                if (!$is_post_url_mode) {
                    if ($dateFromIso !== '') {
                        $input['onlyPostsNewerThan'] = $dateFromIso;
                    } elseif ($dateRange) {
                        $input['onlyPostsNewerThan'] = $dateRange;
                    }
                }

                return $input;
            case 'instagram':
                $instagram_actor_slug_for_input = function_exists('ves_get_actor_slug') ? ves_get_actor_slug('instagram', $src) : '';
                $use_apify_instagram_schema = stripos((string) $instagram_actor_slug_for_input, 'apify/instagram-scraper') !== false;
                $input = [
                    'resultsType'  => 'posts',
                    'resultsLimit' => $limit !== null ? $limit : 24,
                ];
                if (!$is_post_url_mode) {
                    if ($dateFromIso !== '') {
                        $input['onlyPostsNewerThan'] = $dateFromIso;
                    } elseif ($dateRange) {
                        $input['onlyPostsNewerThan'] = $dateRange;
                    }
                }

                if ($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode) {
                    $lines = preg_split('/\r\n|\r|\n/', (string) $targetsRaw);
                    $urls = [];
                    foreach ($lines as $line) {
                        $line = trim((string) $line);
                        if ($line === '') continue;
                        $looksLikeUrl = (stripos($line, 'http') === 0) || (stripos($line, 'instagram.com') !== false);
                        if ($looksLikeUrl) {
                            $u = function_exists('ves_normalize_url') ? ves_normalize_url($line) : $line;
                            if ($u === '') continue;
                        } else {
                            $handle = ltrim($line, "@ \t\n\r\0\x0B");
                            $handle = preg_replace('/[^A-Za-z0-9_.]/', '', $handle);
                            if ($handle === '') continue;
                            $u = 'https://www.instagram.com/' . $handle . '/';
                        }
                        $u = preg_replace('~^https?://(m|mobile)\.instagram\.com~i', 'https://www.instagram.com', $u);
                        $u = preg_replace('~^https?://instagram\.com~i', 'https://www.instagram.com', $u);
                        $u = preg_replace('~\?.*$~', '', $u);
                        if (preg_match('~^https?://www\.instagram\.com/[^/?#]+$~i', $u)) $u .= '/';
                        $urls[] = esc_url_raw($u);
                    }
                    $urls = array_values(array_unique(array_filter($urls)));
                    list($urls, $wrong_urls) = function_exists('ves_filter_urls_for_mode')
                        ? ves_filter_urls_for_mode('instagram', $urls, $src)
                        : [$urls, []];

                    $post_urls = [];
                    $profile_urls = [];
                    foreach ($urls as $candidate_url) {
                        $kind = function_exists('ves_platform_url_kind') ? ves_platform_url_kind('instagram', $candidate_url) : 'unknown';
                        if ($kind === 'post' || $kind === 'hashtag') {
                            $post_urls[] = esc_url_raw($candidate_url);
                        } elseif ($kind === 'profile') {
                            $profile_urls[] = esc_url_raw($candidate_url);
                        }
                    }
                    $post_urls = array_values(array_unique(array_filter($post_urls)));
                    $profiles = function_exists('ves_instagram_profile_handles_from_urls')
                        ? ves_instagram_profile_handles_from_urls($profile_urls)
                        : [];

                    if (!empty($post_urls)) {
                        $input['directUrls'] = $post_urls;
                        if ($is_post_url_mode) {
                            // apify/instagram-scraper treats resultsLimit as per URL.
                            // Exact post/reel URLs need one record per URL; using count($post_urls)
                            // can over-collect/cost more and distort result counts.
                            $input['resultsLimit'] = 1;
                        }
                    }
                    if (!$is_post_url_mode && !empty($profiles)) {
                        if ($use_apify_instagram_schema) {
                            // Current apify/instagram-scraper schema expects profile URLs in directUrls.
                            // Legacy profiles[] / profileScrapeSections fields are ignored by this actor.
                            unset($input['search'], $input['searchType'], $input['searchLimit']);
                            $profile_direct_urls = array_values(array_unique(array_filter($profile_urls)));
                            $input['directUrls'] = array_values(array_unique(array_merge($input['directUrls'] ?? [], $profile_direct_urls)));
                            $input['resultsLimit'] = $limit !== null ? max(1, (int) $limit) : 24;
                        } else {
                            if ($is_profile_url_mode) { unset($input['directUrls']); }
                            unset($input['search'], $input['searchType'], $input['searchLimit'], $input['resultsLimit']);
                            $input['profiles'] = $profiles;
                            $input['profileScrapeSections'] = ['posts'];
                            $input['profileSorting'] = 'latest';
                            $input['maxProfilesPerQuery'] = max(1, count($profiles));
                            $input['resultsPerPage'] = $limit !== null ? max(20, (int) $limit) : 24;
                        }
                    }

                } else {
                    $lines = preg_split('/\r\n|\r|\n/', (string) $targetsRaw);
                    $direct_urls = [];
                    $fallback_terms = [];
                    foreach ($lines as $line) {
                        $line = trim((string) $line);
                        if ($line === '') continue;
                        $maybe_url = function_exists('ves_normalize_url') ? ves_normalize_url($line) : '';
                        if ($maybe_url !== '' && stripos((string) parse_url($maybe_url, PHP_URL_HOST), 'instagram.com') !== false) {
                            $direct_urls[] = esc_url_raw($maybe_url);
                            continue;
                        }
                        if (strpos($line, '#') === 0) {
                            $tag = ltrim($line, "# \t\n\r\0\x0B");
                            $tag = preg_replace('/[^A-Za-z0-9_.]/', '', $tag);
                            if ($tag !== '') {
                                $direct_urls[] = 'https://www.instagram.com/explore/tags/' . $tag . '/';
                            }
                            continue;
                        }
                        if (strpos($line, '@') === 0) {
                            $handle = ltrim($line, "@ \t\n\r\0\x0B");
                            $handle = preg_replace('/[^A-Za-z0-9_.]/', '', $handle);
                            if ($handle !== '') {
                                $direct_urls[] = 'https://www.instagram.com/' . $handle . '/';
                            }
                            continue;
                        }
                        // v0.9.10.5: A plain single-word brand query (e.g. "brand")
                        // must remain a search term. The previous logic silently treated
                        // every single word as an Instagram profile URL, which caused the
                        // scraper to inspect the wrong account instead of brand/topic posts.
                        $clean_term = sanitize_text_field($line);
                        if ($clean_term !== '') {
                            $expanded_terms = function_exists('ves_contextual_search_phrases')
                                ? ves_contextual_search_phrases($clean_term, $src, 6)
                                : [$clean_term];
                            $fallback_terms = array_merge($fallback_terms, $expanded_terms);
                        }
                    }
                    $direct_urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $direct_urls))));
                    $post_direct_urls = [];
                    $profile_direct_urls = [];
                    foreach ($direct_urls as $candidate_url) {
                        $kind = function_exists('ves_platform_url_kind') ? ves_platform_url_kind('instagram', $candidate_url) : 'unknown';
                        if ($kind === 'post' || $kind === 'hashtag') {
                            $post_direct_urls[] = esc_url_raw($candidate_url);
                        } elseif ($kind === 'profile') {
                            $profile_direct_urls[] = esc_url_raw($candidate_url);
                        }
                    }
                    $post_direct_urls = array_values(array_unique(array_filter($post_direct_urls)));
                    $profile_handles = function_exists('ves_instagram_profile_handles_from_urls')
                        ? ves_instagram_profile_handles_from_urls($profile_direct_urls)
                        : [];
                    if (!empty($post_direct_urls)) {
                        $input['directUrls'] = $post_direct_urls;
                    }
                    if (!empty($profile_handles)) {
                        if ($use_apify_instagram_schema) {
                            $profile_urls_for_actor = [];
                            foreach ($profile_handles as $handle_for_actor) {
                                $handle_for_actor = preg_replace('/[^A-Za-z0-9_.]/', '', (string) $handle_for_actor);
                                if ($handle_for_actor !== '') {
                                    $profile_urls_for_actor[] = 'https://www.instagram.com/' . $handle_for_actor . '/';
                                }
                            }
                            if (!empty($profile_urls_for_actor)) {
                                $input['directUrls'] = array_values(array_unique(array_merge($input['directUrls'] ?? [], $profile_urls_for_actor)));
                                $input['resultsLimit'] = $limit !== null ? max(1, (int) $limit) : 24;
                                unset($input['search'], $input['searchType'], $input['searchLimit']);
                            }
                        } else {
                            $input['profiles'] = $profile_handles;
                            $input['profileScrapeSections'] = ['posts'];
                            $input['profileSorting'] = 'latest';
                            $input['maxProfilesPerQuery'] = max(1, count($profile_handles));
                            $input['resultsPerPage'] = $limit !== null ? max(20, (int) $limit) : 24;
                            unset($input['search'], $input['searchType'], $input['searchLimit'], $input['resultsLimit']);
                        }
                    }
                    if (!empty($fallback_terms)) {
                        $fallback_terms = array_values(array_unique(array_filter(array_map('sanitize_text_field', $fallback_terms))));

                        // v0.9.16.9: The Instagram actor search+searchType path returns
                        // directory entities (hashtags/users/places), not post rows.
                        // Convert brand/topic terms into hashtag URLs so resultsType=posts
                        // produces actual Instagram post/reel items.
                        $generated_tag_urls = [];
                        foreach ($fallback_terms as $term_for_tag) {
                            $tag = function_exists('remove_accents') ? remove_accents((string) $term_for_tag) : (string) $term_for_tag;
                            $tag = preg_replace('/[^A-Za-z0-9_]+/', '', $tag);
                            $tag = trim((string) $tag, '_');
                            if ($tag === '') {
                                continue;
                            }
                            $tag_lc = strtolower($tag);
                            if (in_array($tag_lc, ['marca','brand','beer','cerveza','spain','espana','madrid'], true)) {
                                continue;
                            }
                            $generated_tag_urls[] = 'https://www.instagram.com/explore/tags/' . rawurlencode($tag) . '/';
                            if (count($generated_tag_urls) >= 8) {
                                break;
                            }
                        }
                        $generated_tag_urls = array_values(array_unique(array_filter($generated_tag_urls)));
                        if (!empty($generated_tag_urls)) {
                            $input['directUrls'] = array_values(array_unique(array_merge($input['directUrls'] ?? [], $generated_tag_urls)));
                            unset($input['search'], $input['searchType'], $input['searchLimit']);
                        } else {
                            $input['search'] = implode(', ', $fallback_terms);
                            $input['searchType'] = 'hashtag';
                            $input['searchLimit'] = max(10, min(50, (int) $defaultLimit));
                        }
                    }
                }
                // Keep min likes as a local post-filter for the current official
                // apify/instagram-scraper schema. Some legacy/custom Instagram actors
                // support minLikesCount, so only pass it outside the official schema.
                if (!$use_apify_instagram_schema && !$is_post_url_mode && $minLikes !== null && $minLikes > 0) {
                    $input['minLikesCount'] = (int) $minLikes;
                }
                return $input;
            case 'reddit':
                $reddit_limit = max(1, min(200, (int) ($requested_limit ?: 20)));
                $reddit_proxy_group = strtoupper(sanitize_text_field((string) ($src['redditProxyGroup'] ?? 'RESIDENTIAL')));
                if (!in_array($reddit_proxy_group, ['RESIDENTIAL', 'DATACENTER'], true)) {
                    $reddit_proxy_group = 'RESIDENTIAL';
                }
                $reddit_url_result_mode = sanitize_key((string) ($src['redditUrlResultMode'] ?? 'posts'));
                if (!in_array($reddit_url_result_mode, ['posts', 'community', 'both'], true)) {
                    $reddit_url_result_mode = 'posts';
                }
                $reddit_include_comments = !empty($src['redditSearchComments']);
                $reddit_skip_comments = !empty($src['redditSkipComments']);
                $reddit_max_comments = max(0, min(200, (int) ($src['redditMaxComments'] ?? ($reddit_include_comments ? 10 : 0))));
                if ($reddit_skip_comments) {
                    $reddit_max_comments = 0;
                }
                $input = [
                    'maxItems' => $reddit_limit,
                    'maxPostCount' => $reddit_limit,
                    'maxComments' => $reddit_max_comments,
                    'maxCommunitiesCount' => max(1, min(50, (int) ($src['redditMaxCommunities'] ?? 5))),
                    'maxUserCount' => max(1, min(50, (int) ($src['redditMaxUsers'] ?? 5))),
                    'scrollTimeout' => max(10, min(120, (int) ($src['redditScrollTimeout'] ?? 40))),
                    'skipComments' => $reddit_skip_comments,
                    'skipUserPosts' => !empty($src['redditSkipUserPosts']),
                    'skipCommunity' => !empty($src['redditSkipCommunity']),
                    'includeNSFW' => !empty($src['redditIncludeNSFW']),
                    'proxy' => [
                        'useApifyProxy' => true,
                        'apifyProxyGroups' => [$reddit_proxy_group],
                    ],
                ];
                if ($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode || $mode === 'urls') {
                    $urls = ves_parse_lines($targetsRaw, 'url');
                    $reddit_urls = [];
                    foreach ($urls as $url) {
                        if (preg_match('~https?://(?:www\.)?reddit\.com/~i', (string) $url)) {
                            $reddit_urls[] = esc_url_raw($url);
                        }
                    }
                    if (!empty($reddit_urls)) {
                        $unique_reddit_urls = array_values(array_unique($reddit_urls));
                        $input['startUrls'] = array_map(static fn ($url) => ['url' => $url], $unique_reddit_urls);
                        // Live Apify test: subreddit URLs return community metadata first unless
                        // community extraction is skipped. Default URL mode should collect posts,
                        // while still allowing explicit community/both modes from the UI.
                        if ($reddit_url_result_mode === 'posts') {
                            $input['skipCommunity'] = true;
                        } elseif ($reddit_url_result_mode === 'community') {
                            $input['skipCommunity'] = false;
                            $input['skipComments'] = true;
                            $input['maxPostCount'] = 1;
                        } else {
                            $input['skipCommunity'] = false;
                        }
                    }
                } else {
                    // v0.9.24.79: Reddit is not a hashtag/social-card search surface.
                    // Do not expand short terms into "Target", "market", etc. and do not
                    // convert hashtags into provider queries unless they were explicitly typed
                    // as queryTerms. This prevents irrelevant commodity/noise runs.
                    $terms = [];
                    foreach ((array) $ves_scraper_terms as $term) {
                        $term = ves_query_planner_short_term_safe($term, 6, 80);
                        if ($term !== '') { $terms[] = $term; }
                    }
                    if (empty($terms)) {
                        foreach (ves_reddit_terms($targetsRaw, 50) as $term) {
                            if (strpos(trim((string) $term), '#') === 0) { continue; }
                            $term = ves_query_planner_short_term_safe($term, 6, 80);
                            if ($term !== '') { $terms[] = $term; }
                        }
                    }
                    $terms = array_values(array_unique(array_filter(array_map('sanitize_text_field', $terms))));
                    if (!empty($terms)) {
                        $input['searches'] = $terms;
                        $input['queries'] = $terms;
                        $input['search'] = $terms[0];
                        $community = ves_reddit_community_name($src['redditCommunity'] ?? '');
                        $planner_subreddits = isset($ves_query_plan['subreddits']) && is_array($ves_query_plan['subreddits']) ? $ves_query_plan['subreddits'] : [];
                        if ($community !== '') { $planner_subreddits[] = $community; }
                        $planner_subreddits = array_values(array_unique(array_filter(array_map('sanitize_text_field', $planner_subreddits))));
                        if (!empty($planner_subreddits)) {
                            $input['subreddits'] = $planner_subreddits;
                            $input['searchCommunityName'] = $planner_subreddits[0];
                        }
                        $sort = sanitize_key((string) ($src['redditSort'] ?? ''));
                        if ($sort === '' || $sort === 'new') {
                            if ($sortPriority === 'latest' || $sortPriority === 'newest') {
                                $sort = 'new';
                            } elseif (in_array($sortPriority, ['highest_engagement','most_commented'], true)) {
                                $sort = $sortPriority === 'most_commented' ? 'comments' : 'top';
                            } else {
                                $sort = 'relevance';
                            }
                        }
                        if (!in_array($sort, ['relevance','hot','top','new','rising','comments'], true)) {
                            $sort = 'relevance';
                        }
                        $input['sort'] = $sort;
                        $time = sanitize_key((string) ($src['redditTime'] ?? ''));
                        // Default Reddit to a bounded window. "all" is only respected when
                        // the global run date is also unrestricted.
                        if (!in_array($time, ['all','hour','day','week','month','year'], true) || ($time === 'all' && $datePreset !== 'any')) {
                            $time = ves_reddit_time_from_preset($datePreset);
                        }
                        if ($time === 'all' && $datePreset === 'any') { $time = 'month'; }
                        $input['time'] = $time;
                        $search_posts = array_key_exists('redditSearchPosts', (array) $src) ? !empty($src['redditSearchPosts']) : true;
                        $input['searchPosts'] = $search_posts;
                        $input['searchComments'] = $reddit_include_comments;
                        $input['searchCommunities'] = !empty($src['redditSearchCommunities']);
                        $input['searchUsers'] = !empty($src['redditSearchUsers']);
                    }
                }
                return $input;

            case 'pinterest':
                $pinterest_limit = max(1, min(500, (int) ($requested_limit ?: 20)));
                $input = [
                    'includeComments' => !empty($src['pinterestIncludeComments']),
                    'includeUserInfoOnly' => !empty($src['pinterestIncludeUserInfoOnly']),
                    'maxItems' => $pinterest_limit,
                    'endPage' => max(0, min(100, (int) ($src['pinterestEndPage'] ?? 1))),
                    'proxy' => [
                        'useApifyProxy' => true,
                        'apifyProxyGroups' => [
                            strtoupper(sanitize_text_field((string) ($src['pinterestProxyGroup'] ?? 'RESIDENTIAL'))) === 'DATACENTER' ? 'DATACENTER' : 'RESIDENTIAL',
                        ],
                    ],
                ];

                if ($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode || $mode === 'urls') {
                    $urls = [];
                    foreach (ves_array_field_values($targetsRaw, 100) as $line) {
                        $url = function_exists('ves_normalize_url') ? ves_normalize_url($line) : '';
                        if ($url !== '' && preg_match('~https?://(?:www\.)?pinterest\.[a-z.]+/~i', $url)) {
                            $urls[] = esc_url_raw($url);
                        }
                    }
                    if (!empty($urls)) {
                        // epctex/pinterest-scraper expects startUrls as an array of strings, not request objects.
                        $input['startUrls'] = array_values(array_unique($urls));
                    }
                } else {
                    $terms = ves_array_field_values($targetsRaw, 20);
                    $terms = array_values(array_unique(array_filter(array_map('sanitize_text_field', $terms))));
                    if (!empty($terms)) {
                        // v0.9.24.65 BUG-B note: epctex/pinterest-scraper accepts one search string per run.
                        // Only the first line is sent to the actor; additional lines are silently discarded.
                        // The UI form should hint this with placeholder text and a note.
                        $input['search'] = $terms[0];
                        // Warn in execution plan if user supplied multiple terms.
                        if (count($terms) > 1) {
                            $input['_pinterest_extra_terms_discarded'] = count($terms) - 1;
                        }
                    }
                }
                return $input;


            case 'semrush':
                $mode = sanitize_key((string) ($src['mode'] ?? 'keyword_magic'));
                $targets = ves_array_field_values($targetsRaw, 100);
                $country = strtolower(sanitize_text_field((string) ($src['semrushCountry'] ?? $src['semrushSeoCountry'] ?? 'es')));
                if (!preg_match('/^[a-z]{2}$/', $country)) { $country = 'es'; }
                $language = strtolower(sanitize_text_field((string) ($src['semrushLanguage'] ?? 'es')));
                if (!preg_match('/^[a-z]{2}$/', $language)) { $language = 'es'; }

                if ($mode === 'keyword_simple') {
                    $keywords = !empty($ves_seed_keywords) ? $ves_seed_keywords : [];
                    if (empty($keywords) && class_exists('VES_Query_Planner')) {
                        $keywords = ves_query_planner_extract_seo_keywords_safe($src);
                    }
                    $keywords = array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $keywords)))), 0, 50);
                    return !empty($keywords) ? ['d1' => implode(', ', $keywords)] : [];
                }

                if ($mode === 'keyword_magic') {
                    $explicit_seed = trim(sanitize_text_field((string) ($src['seedKeyword'] ?? $src['seed_keyword'] ?? $src['seoSeedKeyword'] ?? $src['semrushKeyword'] ?? '')));
                    if ($explicit_seed !== '' && ves_query_planner_looks_like_objective_safe($explicit_seed)) {
                        return ['_ves_bad_semrush_seed_keyword' => $explicit_seed];
                    }
                    $keyword = '';
                    if (!empty($ves_seed_keywords)) {
                        $keyword = sanitize_text_field((string) $ves_seed_keywords[0]);
                    }
                    if ($keyword === '' && class_exists('VES_Query_Planner')) {
                        $fallback_keywords = ves_query_planner_extract_seo_keywords_safe($src);
                        $keyword = sanitize_text_field((string) ($fallback_keywords[0] ?? ''));
                    }
                    if ($keyword !== '' && ves_query_planner_looks_like_objective_safe($keyword)) {
                        $keyword = '';
                    }
                    $max_results = max(25, min(1000, absint($src['semrushMaxResults'] ?? $src['candidatePoolSize'] ?? $src['limit'] ?? 100)));
                    return $keyword !== '' ? [
                        'keyword' => $keyword,
                        'country' => $country,
                        'language' => $language,
                        'maxResults' => $max_results,
                    ] : [];
                }

                if ($mode === 'top_websites') {
                    $industry = sanitize_key((string) ($src['semrushTopIndustry'] ?? 'all'));
                    if ($industry === '') { $industry = 'all'; }
                    $top_country = strtolower(sanitize_text_field((string) ($src['semrushTopCountry'] ?? 'global')));
                    if ($top_country !== 'global' && !preg_match('/^[a-z]{2}$/', $top_country)) { $top_country = 'global'; }
                    return [
                        'include_top_websites' => true,
                        'industry_top_websites' => $industry,
                        'country_top_websites' => $top_country,
                    ];
                }

                // Domain / SEO data mode uses radeance/semrush-scraper.
                $urls = ves_domain_or_url_lines($targetsRaw, 100);
                $keyword = sanitize_text_field((string) ($src['semrushSeoKeyword'] ?? ''));
                if ($keyword === '') {
                    foreach ($targets as $target_value) {
                        $target_value = sanitize_text_field((string) $target_value);
                        if ($target_value !== '' && !preg_match('~^https?://~i', $target_value) && !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(?:/.*)?$~i', $target_value)) {
                            $keyword = $target_value;
                            break;
                        }
                    }
                }
                $input = [];
                if (!empty($urls)) { $input['urls'] = $urls; }
                if ($keyword !== '') { $input['keyword'] = $keyword; }
                $input['country'] = strtolower(sanitize_text_field((string) ($src['semrushSeoCountry'] ?? $country)));
                if (!preg_match('/^[a-z]{2}$/', $input['country'])) { $input['country'] = 'es'; }

                $flag_map = [
                    'semrushIncludeAiVisibility' => 'include_ai_visibility',
                    'semrushIncludeOverview' => 'include_overview',
                    'semrushIncludeAuthority' => 'include_authority',
                    'semrushIncludeTraffic' => 'include_traffic',
                    'semrushIncludeBacklinks' => 'include_backlinks',
                    'semrushIncludeCompetitors' => 'include_competitors',
                    'semrushIncludeKeywordVolume' => 'include_keyword_volume',
                    'semrushIncludeKeywordsRanking' => 'include_keywords_ranking',
                    'semrushIncludeSeo' => 'include_seo',
                    'semrushIncludeSerp' => 'include_serp',
                ];
                foreach ($flag_map as $src_key => $payload_key) {
                    $input[$payload_key] = !empty($src[$src_key]);
                }
                return $input;

            case 'moz':
                $urls = ves_domain_or_url_lines($targetsRaw, 100);
                $input = [
                    'urls' => $urls,
                    'include_authority' => array_key_exists('mozIncludeAuthority', (array) $src) ? !empty($src['mozIncludeAuthority']) : true,
                    'include_history' => array_key_exists('mozIncludeHistory', (array) $src) ? !empty($src['mozIncludeHistory']) : true,
                ];
                return $input;

            case 'ahrefs':
                // v0.9.24.23: Ahrefs now uses pro100chok/ahrefs-seo-tools.
                // This actor expects a single searchType per run instead of the older
                // radeance schema with multiple include_* toggles.
                $allowed_search_types = [
                    'website_authority','backlinks_overview','backlinks_list','broken_links',
                    'traffic_overview','ai_visibility','website_details','keyword_ideas',
                    'keyword_difficulty','keyword_metrics','keyword_rank','serp_overview','top_websites'
                ];
                $legacy_mode_map = [
                    'domain' => 'website_authority',
                    'keyword' => 'keyword_ideas',
                    'keywords' => 'keyword_ideas',
                    'keyword_scraper' => 'keyword_ideas',
                    'keywords_scraper' => 'keyword_ideas',
                    'keyword_seed' => 'keyword_ideas',
                    'keyword_metrics' => 'keyword_metrics',
                    'serp' => 'serp_overview',
                    'bulk_urls' => 'website_authority',
                ];
                $search_type = sanitize_key((string) $mode);
                $search_type = $legacy_mode_map[$search_type] ?? $search_type;
                if (!in_array($search_type, $allowed_search_types, true)) {
                    $search_type = 'website_authority';
                }

                $targets = ves_array_field_values($targetsRaw, 100);
                $domains = ves_domain_or_url_lines($targetsRaw, 100);
                $keyword_targets = [];
                foreach ($targets as $target_value) {
                    if (!preg_match('~^https?://~i', $target_value) && !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(?:/.*)?$~i', $target_value)) {
                        $keyword_targets[] = sanitize_text_field((string) $target_value);
                    }
                }
                foreach (['keyword','keywords','keywordSeed','keyword_seed','seedKeyword','seed_keyword','seoKeyword','seo_keyword','ahrefsKeyword','ahrefs_keyword','ahrefsKeywords','ahrefs_keywords'] as $keyword_key) {
                    if (empty($src[$keyword_key])) { continue; }
                    foreach (ves_array_field_values($src[$keyword_key], 20) as $keyword_line) {
                        $keyword_line = trim(sanitize_text_field((string) $keyword_line));
                        if ($keyword_line !== '' && !preg_match('~^https?://~i', $keyword_line) && !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(?:/.*)?$~i', $keyword_line)) {
                            $keyword_targets[] = $keyword_line;
                        }
                    }
                }
                foreach ((array) ($ves_query_plan['seed_keywords'] ?? []) as $keyword_line) {
                    $keyword_line = trim(sanitize_text_field((string) $keyword_line));
                    if ($keyword_line !== '') { $keyword_targets[] = $keyword_line; }
                }
                $keyword_targets = array_values(array_unique(array_filter($keyword_targets)));

                $country_code = strtolower($country && $country !== 'None' ? $country : 'US');
                if (!preg_match('/^[a-z]{2}$/', $country_code)) {
                    $country_code = 'us';
                }

                $url_mode = sanitize_key((string) ($src['ahrefsDomainMode'] ?? 'subdomains'));
                if (!in_array($url_mode, ['exact','subdomains','prefix','domain'], true)) {
                    $url_mode = 'subdomains';
                }

                $search_engines = ['Google','Bing','YouTube','Amazon','Yahoo','Yandex','Baidu','ChatGPT','Gemini','Perplexity','Copilot','Grok','GoogleAIMode','GoogleImages','GoogleNews','GoogleVideos','Daum','Naver','Seznam','Yep'];
                $search_engine = sanitize_text_field((string) ($src['ahrefsSearchEngine'] ?? 'Google'));
                if (!in_array($search_engine, $search_engines, true)) {
                    $search_engine = 'Google';
                }

                $input = [
                    'searchType' => $search_type,
                    'country' => $country_code,
                    'mode' => $url_mode,
                    'searchEngine' => $search_engine,
                    'proxyConfiguration' => [
                        'useApifyProxy' => true,
                        'apifyProxyGroups' => ['RESIDENTIAL'],
                        'apifyProxyCountry' => strtoupper($country_code),
                    ],
                    'maxRetries' => max(1, min(10, (int) ($src['ahrefsMaxRetries'] ?? 5))),
                ];

                $website_search_types = ['website_authority','backlinks_overview','backlinks_list','broken_links','traffic_overview','website_details'];
                $keyword_search_types = ['ai_visibility','keyword_ideas','keyword_difficulty','keyword_metrics','keyword_rank','serp_overview'];

                if (in_array($search_type, $website_search_types, true)) {
                    if (!empty($domains)) {
                        $input['urls'] = array_slice($domains, 0, 100);
                    }
                }

                if (in_array($search_type, $keyword_search_types, true)) {
                    $keyword = sanitize_text_field((string) ($keyword_targets[0] ?? ($targets[0] ?? '')));
                    if ($keyword !== '') {
                        $input['keyword'] = mb_substr($keyword, 0, 200);
                    }
                    if ($search_type === 'keyword_rank' && !empty($domains)) {
                        $input['urls'] = array_slice($domains, 0, 100);
                    } elseif (!empty($domains) && empty($input['keyword'])) {
                        // Allows AI visibility / SERP checks around a brand-like domain if no keyword line was provided.
                        $input['keyword'] = mb_substr((string) $domains[0], 0, 200);
                    }
                }

                if ($search_type === 'top_websites') {
                    $top_mode = sanitize_key((string) ($src['ahrefsTopMode'] ?? 'ranking'));
                    $input['topWebsitesMode'] = in_array($top_mode, ['ranking','trending'], true) ? $top_mode : 'ranking';
                    $top_country = strtolower(sanitize_text_field((string) ($src['ahrefsTopCountry'] ?? 'worldwide')));
                    $input['topWebsitesCountry'] = $top_country !== '' ? $top_country : 'worldwide';
                    $category_allowed = ['all','arts-and-entertainment','autos-and-vehicles','beauty-and-fitness','books-and-literature','business','computers-and-electronics','finance','food-and-drink','games','health','hobbies-and-leisure','home-and-garden','internet-and-telecom','jobs-and-education','law-and-government','news','people-and-society','pets-and-animals','real-estate','reference','science','shopping','social-networks','sports','travel-and-transportation'];
                    $category = sanitize_key((string) ($src['ahrefsTopCategory'] ?? 'all'));
                    $input['topWebsitesCategory'] = in_array($category, $category_allowed, true) ? $category : 'all';
                    $top_limit = (int) ($src['ahrefsTopLimit'] ?? $requested_limit ?? 100);
                    $input['topWebsitesLimit'] = max(1, min(1000, $top_limit));
                    $input['includeDetails'] = !empty($src['ahrefsIncludeDetails']);
                }

                return $input;

            case 'linkedin':
                $linkedin_objective = sanitize_key((string) ($src['linkedinObjective'] ?? 'analyze_posts'));
                $supported_linkedin_objectives = ['analyze_posts', 'thought_leadership_topic_scan', 'competitor_company_scan'];
                if (!in_array($linkedin_objective, $supported_linkedin_objectives, true)) {
                    return ['_ves_unsupported_linkedin_objective' => $linkedin_objective];
                }
                $query = trim(sanitize_text_field((string) $targetsRaw));
                if ($query === '') {
                    $query = trim(sanitize_text_field((string) ($src['searchContext'] ?? '')));
                }
                $mode_raw = sanitize_text_field((string) ($src['linkedinProfileMode'] ?? 'Full'));
                $mode_map = [
                    'short' => 'Short',
                    'Short' => 'Short',
                    'full' => 'Full',
                    'Full' => 'Full',
                    'full_email' => 'Full + email search',
                    'Full + email search' => 'Full + email search',
                ];
                $profile_mode = $mode_map[$mode_raw] ?? 'Full';
                $linkedin_limit = max(1, min(200, (int) ($requested_limit ?: 20)));
                $input = [
                    'profileScraperMode' => $profile_mode,
                    'searchQuery'        => mb_substr($query, 0, 300),
                    'maxItems'           => $linkedin_limit,
                    'startPage'          => max(1, min(100, (int) ($src['linkedinStartPage'] ?? 1))),
                    'profileDeduplicationMode' => 'off',
                ];
                $field_map = [
                    'linkedinLocations' => ['locations', 70, false],
                    'linkedinCurrentCompanies' => ['currentCompanies', 50, true],
                    'linkedinPastCompanies' => ['pastCompanies', 50, true],
                    'linkedinSchools' => ['schools', 50, false],
                    'linkedinCurrentJobTitles' => ['currentJobTitles', 50, false],
                    'linkedinPastJobTitles' => ['pastJobTitles', 50, false],
                    'linkedinIndustryIds' => ['industryIds', 50, false],
                    'linkedinFirstNames' => ['firstNames', 50, false],
                    'linkedinLastNames' => ['lastNames', 50, false],
                    'linkedinProfileLanguages' => ['profileLanguages', 25, false],
                    'linkedinCompanyHeadquarterLocations' => ['companyHeadquarterLocations', 70, false],
                    'linkedinExcludeLocations' => ['excludeLocations', 70, false],
                    'linkedinExcludeCurrentCompanies' => ['excludeCurrentCompanies', 50, true],
                    'linkedinExcludePastCompanies' => ['excludePastCompanies', 50, true],
                    'linkedinExcludeSchools' => ['excludeSchools', 50, false],
                    'linkedinExcludeCurrentJobTitles' => ['excludeCurrentJobTitles', 50, false],
                    'linkedinExcludePastJobTitles' => ['excludePastJobTitles', 50, false],
                    'linkedinExcludeIndustryIds' => ['excludeIndustryIds', 50, false],
                    'linkedinExcludeCompanyHeadquarterLocations' => ['excludeCompanyHeadquarterLocations', 70, false],
                ];
                foreach ($field_map as $source_key => $spec) {
                    [$target_key, $max_items, $url_only] = $spec;
                    $items = ves_linkedin_lines($src[$source_key] ?? '', $max_items, $url_only);
                    if (!empty($items)) {
                        $input[$target_key] = $items;
                    }
                }
                $id_field_map = [
                    'linkedinYearsOfExperienceIds' => 'yearsOfExperienceIds',
                    'linkedinYearsAtCurrentCompanyIds' => 'yearsAtCurrentCompanyIds',
                    'linkedinSeniorityLevelIds' => 'seniorityLevelIds',
                    'linkedinFunctionIds' => 'functionIds',
                    'linkedinCompanyHeadcount' => 'companyHeadcount',
                    'linkedinExcludeSeniorityLevelIds' => 'excludeSeniorityLevelIds',
                    'linkedinExcludeFunctionIds' => 'excludeFunctionIds',
                ];
                foreach ($id_field_map as $source_key => $target_key) {
                    $items = ves_linkedin_id_lines($src[$source_key] ?? '', 50);
                    if (!empty($items)) {
                        $input[$target_key] = $items;
                    }
                }
                if (!empty($src['linkedinRecentlyChangedJobs'])) {
                    $input['recentlyChangedJobs'] = true;
                }
                if (!empty($src['linkedinRecentlyPostedOnLinkedIn'])) {
                    $input['recentlyPostedOnLinkedIn'] = true;
                }
                if (!empty($src['linkedinAutoQuerySegmentation'])) {
                    $input['autoQuerySegmentation'] = true;
                }
                return $input;

            case 'facebook_ads':
                $input = [];
                if ($mode === 'search') {
                    $terms = ves_parse_lines($targetsRaw, 'text');
                    $search_urls = [];
                    foreach ($terms as $term) {
                        $q = rawurlencode($term);
                        $cc = ($country === '' || $country === 'None') ? 'ALL' : strtoupper($country);
                        $search_urls[] = 'https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country=' . $cc . '&q=' . $q . '&search_type=keyword_unordered&media_type=all';
                    }
                    if (!empty($search_urls)) {
                        $input['urls'] = array_map(static fn ($url) => ['url' => $url], array_values(array_unique($search_urls)));
                    }
                } else {
                    $urls = ves_parse_lines($targetsRaw, 'url');
                    if (!empty($urls)) {
                        $input['urls'] = array_map(static fn ($url) => ['url' => $url], $urls);
                    }
                }
                if ($limit !== null) $input['count'] = $limit;
                // curious_coder/facebook-ads-library-scraper exposes these as
                // literal dot-named top-level properties, not as a nested object.
                $input['scrapePageAds.activeStatus'] = 'all';
                $input['scrapePageAds.sortBy'] = 'impressions_desc';
                $input['scrapePageAds.countryCode'] = $country === 'None' || $country === '' ? 'ALL' : strtoupper($country);
                $period = ves_fb_ads_period_from_preset($datePreset);
                if ($period !== '') {
                    $input['scrapePageAds.period'] = $period;
                }
                return $input;

            case 'google_ads':
                $targets = ves_parse_lines($targetsRaw, 'text');
                $ads_domains = isset($ves_query_plan['domains']) && is_array($ves_query_plan['domains']) ? $ves_query_plan['domains'] : [];
                foreach (['advertiserDomain','advertiser_domain','domain','targetDomain','target_domain','pageDomain','page_domain','advertiser','pageUrl','page_url'] as $ads_target_key) {
                    if (!empty($src[$ads_target_key])) {
                        foreach (ves_parse_lines($src[$ads_target_key], 'text') as $line) {
                            $line = trim(sanitize_text_field((string) $line));
                            if ($line !== '') { $targets[] = $line; }
                        }
                    }
                }
                foreach ((array) ($ves_query_plan['scraper_query_terms'] ?? []) as $term) {
                    $term = trim(sanitize_text_field((string) $term));
                    if ($term !== '') { $targets[] = $term; }
                }
                foreach ((array) $ads_domains as $domain) {
                    $domain = trim(sanitize_text_field((string) $domain));
                    if ($domain !== '') { $targets[] = $domain; }
                }
                $clean_targets = [];
                $clean_domains = [];
                foreach ((array) $targets as $target) {
                    $target = trim(sanitize_text_field((string) $target));
                    if ($target === '') { continue; }
                    $domain_candidate = strtolower(preg_replace('~^https?://~i', '', $target));
                    $domain_candidate = preg_replace('~/.*$~', '', $domain_candidate);
                    $domain_candidate = preg_replace('/^www\./', '', $domain_candidate);
                    if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain_candidate)) {
                        $clean_domains[] = $domain_candidate;
                        $clean_targets[] = $domain_candidate;
                    } else {
                        $clean_targets[] = $target;
                    }
                }
                $targets = array_values(array_unique(array_filter($clean_targets)));
                $ads_domains = array_values(array_unique(array_filter(array_merge((array) $ads_domains, $clean_domains))));
                $platform_filter = strtoupper(sanitize_text_field((string) ($src['option'] ?? 'ALL')));
                if (!in_array($platform_filter, ['ALL', 'YOUTUBE', 'SEARCH', 'SHOPPING', 'MAPS', 'PLAY'], true)) {
                    $platform_filter = 'ALL';
                }
                $format_filter = strtoupper(sanitize_text_field((string) ($src['extraSelect1'] ?? 'ALL')));
                if (!in_array($format_filter, ['ALL', 'VIDEO', 'IMAGE', 'TEXT'], true)) {
                    $format_filter = 'ALL';
                }
                $ads_limit = $limit !== null ? $limit : 10;
                $input = [
                    'searchTargets'         => $targets,
                    'domains'               => $ads_domains,
                    'text'                  => (string) ($targets[0] ?? ''),
                    'resultsPerQuery'       => max(1, min(50, (int) $ads_limit)),
                    'maxAdvertiserAccounts' => 2,
                    'maxDomainMatches'      => max(1, min(10, count($ads_domains) ?: 2)),
                    'targetPlatform'        => $platform_filter,
                    'adFormatType'          => $format_filter,
                    'geoTargetRegion'       => ves_google_ads_region_from_country($country),
                    'timeRangePreset'       => ves_google_ads_date_preset($datePreset),
                    'enableUrlFiltering'    => !empty($ads_domains),
                ];
                if ($dateFromIso !== '') {
                    $input['customStartDate'] = $dateFromIso;
                    // Do not rewrite a user-selected/custom date to ALL_TIME. Keep
                    // the nearest preset for actor compatibility and enforce the
                    // exact customStartDate locally after collection.
                    if (($input['timeRangePreset'] ?? '') === 'ALL_TIME') {
                        unset($input['timeRangePreset']);
                    }
                }
                return $input;


            case 'twitter':
                $input = [
                    'maxItems' => $is_post_url_mode ? 1 : $defaultLimit,
                    'sort'     => $sortPriority === 'latest' ? 'Latest' : 'Top',
                ];
                if (!$is_post_url_mode && $language !== '') {
                    $input['tweetLanguage'] = $language;
                }
                if (!$is_post_url_mode && $minLikes !== null && $minLikes > 0) {
                    $input['minimumFavorites'] = $minLikes;
                }
                if ($is_post_url_mode || $is_profile_url_mode || $is_mixed_url_mode) {
                    if ($is_profile_url_mode || $is_mixed_url_mode) {
                        $urls = [];
                        foreach (preg_split('/\r\n|\r|\n/', (string) $targetsRaw) as $line) {
                            $line = trim((string) $line);
                            if ($line === '') continue;
                            $u = ves_normalize_url($line);
                            if ($u === '') {
                                $handle = ltrim($line, "@ \t\n\r\0\x0B");
                                $handle = preg_replace('/[^A-Za-z0-9_.]/', '', $handle);
                                if ($handle !== '') $u = 'https://twitter.com/' . $handle;
                            }
                            if ($u !== '') $urls[] = $u;
                        }
                        $urls = array_values(array_unique($urls));
                    } else {
                        $urls = ves_parse_lines($targetsRaw, 'url');
                    }
                    list($urls, $wrong_urls) = function_exists('ves_filter_urls_for_mode')
                        ? ves_filter_urls_for_mode('twitter', $urls, $src)
                        : [$urls, []];
                    if (!empty($urls)) {
                        $input['startUrls'] = $urls;
                        if ($is_post_url_mode) {
                            $input['maxItems'] = max(1, count($urls));
                        }
                    }

                } else {
                    $terms = [];
                    foreach (ves_parse_lines($targetsRaw, 'text') as $term) {
                        $term = trim((string) $term);
                        if ($term === '') {
                            continue;
                        }
                        if (strpos($term, '#') === 0) {
                            $terms[] = $term;
                            continue;
                        }
                        // v0.9.10.5: use the same contextual expansion for X/Twitter
                        // search that TikTok/YouTube use, so brand queries can include
                        // market/category hints without relying only on a bare keyword.
                        $expanded_terms = function_exists('ves_contextual_search_phrases')
                            ? ves_contextual_search_phrases($term, $src, 6)
                            : [$term];
                        $terms = array_merge($terms, $expanded_terms);
                    }
                    $terms = array_values(array_unique(array_filter(array_map('sanitize_text_field', $terms))));
                    if (!empty($terms)) {
                        $input['searchTerms'] = $terms;
                    }
                }
                if (!$is_post_url_mode) {
                    if ($dateFromIso !== '') {
                        $input['since'] = $dateFromIso;
                    } elseif ($datePreset !== 'any') {
                        $days = ['1d'=>1,'7d'=>7,'30d'=>30,'90d'=>90,'180d'=>180,'365d'=>365][ $datePreset ] ?? 0;
                        if ($days > 0) {
                            $input['since'] = gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS));
                        }
                    }
                }
                return $input;
        }
        return [];
    }
}

if (!function_exists('ves_actor_payload_contract')) {
    /**
     * Actor-specific guardrail for manual scraper payloads.
     * This is intentionally conservative: it blocks known legacy/wrong fields
     * and verifies that at least one actor-supported input path is present.
     */
    function ves_actor_payload_contract($platform, $actor_slug) {
        $platform = sanitize_key((string) $platform);
        $slug = strtolower(str_replace('~', '/', trim((string) $actor_slug)));

        if ($platform === 'google_trends' && strpos($slug, 'data_xplorer/google-trends-trending-now') !== false) {
            return [
                'contract_key' => 'google_trends_trending_now_data_xplorer',
                'required_fields' => ['country'],
                'required_any_fields' => [],
                'forbidden_fields' => ['keyword', 'searchTerms', 'isMultiple', 'geo', 'viewedFrom'],
                'allowed_fields' => ['country', 'timeframe', 'categories', 'maxItems', 'proxyConfiguration'],
            ];
        }

        if ($platform === 'google_trends' && strpos($slug, 'apify/google-trends-scraper') !== false) {
            return [
                'contract_key' => 'google_trends_apify_interest',
                'required_fields' => ['searchTerms'],
                'required_any_fields' => [],
                'forbidden_fields' => ['keyword', 'country', 'timeframe'],
                'allowed_fields' => ['searchTerms', 'isMultiple', 'timeRange', 'geo', 'viewedFrom', 'maxItems'],
            ];
        }

        if ($platform === 'google_search' && strpos($slug, 'apify/google-search-scraper') !== false) {
            return [
                'contract_key' => 'google_search_apify_freshness',
                'required_any_fields' => ['queries', 'query'],
                'forbidden_fields' => ['searchTerms', 'keyword', 'business_goal', 'prompt'],
                'allowed_fields' => ['queries', 'query', 'countryCode', 'languageCode', 'maxResults'],
            ];
        }

        if ($platform === 'tiktok' && strpos($slug, 'clockworks/tiktok-hashtag-scraper') !== false) {
            return [
                'contract_key' => 'tiktok_clockworks_hashtag_only',
                'required_fields' => ['hashtags'],
                'required_any_fields' => ['hashtags'],
                'forbidden_fields' => ['keywords', 'searchTerms', 'searchQueries', 'startUrls', 'maxItems', 'sortType', 'country', 'language', 'profiles', 'postURLs'],
                'allowed_fields' => ['hashtags', 'resultsPerPage'],
            ];
        }

        if ($platform === 'tiktok' && (strpos($slug, 'clockworks/tiktok-scraper') !== false || strpos($slug, 'clockworks/free-tiktok-scraper') !== false)) {
            return [
                'contract_key' => 'tiktok_clockworks_search_current',
                'required_any_fields' => ['searchQueries', 'hashtags', 'profiles', 'postURLs'],
                'forbidden_fields' => ['keywords', 'searchTerms', 'startUrls', 'maxItems', 'sortType', 'country', 'language'],
                'allowed_fields' => ['searchQueries', 'hashtags', 'profiles', 'postURLs', 'resultsPerPage', 'searchSection', 'videoSearchSorting', 'proxyCountryCode', 'oldestPostDateUnified', 'newestPostDate'],
            ];
        }

        if ($platform === 'reddit') {
            return [
                'contract_key' => 'reddit_topic_posts_generic',
                'required_any_fields' => ['searches', 'queries', 'search', 'startUrls', 'subreddits'],
                'forbidden_fields' => ['hashtags', 'minViews', 'views', 'imagePreview'],
                'allowed_fields' => ['searches', 'queries', 'search', 'subreddits', 'startUrls', 'searchPosts', 'searchComments', 'searchCommunities', 'sort', 'time', 'maxItems', 'maxPostCount', 'maxComments', 'includeNSFW'],
            ];
        }

        if ($platform === 'tiktok' && strpos($slug, 'apidojo/tiktok-scraper') !== false) {
            return [
                'contract_key' => 'tiktok_apidojo_discovery',
                'required_any_fields' => ['keywords', 'startUrls'],
                'forbidden_fields' => ['hashtags', 'profiles', 'postURLs', 'searchQueries', 'resultsPerPage', 'proxyCountryCode', 'searchDatePosted', 'videoSearchDateFilter', 'oldestPostDateUnified', 'newestPostDate', 'profileScrapeSections', 'profileSorting', 'excludePinnedPosts', 'maxProfilesPerQuery'],
                'allowed_fields' => ['customMapFunction', 'dateRange', 'includeSearchKeywords', 'location', 'maxItems', 'sortType', 'startUrls', 'keywords', 'downloadSubtitlesOptions'],
            ];
        }

        if ($platform === 'youtube' && strpos($slug, 'streamers/youtube-scraper') !== false) {
            return [
                'contract_key' => 'youtube_streamers_current',
                'required_any_fields' => ['searchQueries', 'startUrls'],
                'forbidden_fields' => ['maxResultsStreams', 'searchTerms', 'keywords'],
                'allowed_fields' => ['searchQueries', 'startUrls', 'search', 'keywords', 'maxResults', 'maxItems', 'sortBy', 'publishedAfter', 'includeTranscript', 'language', 'country', 'subtitlesLanguage', 'maxResultsShorts', 'maxResultStreams'],
            ];
        }

        if ($platform === 'instagram' && strpos($slug, 'apify/instagram-scraper') !== false) {
            return [
                'contract_key' => 'instagram_apify_general',
                'required_any_fields' => ['directUrls', 'search'],
                'forbidden_fields' => ['profiles', 'username', 'hashtags', 'postURLs'],
                'allowed_fields' => ['directUrls', 'resultsType', 'resultsLimit', 'maxItems', 'search', 'searchType', 'searchLimit', 'addParentData', 'onlyPostsNewerThan'],
            ];
        }

        if ($platform === 'twitter' && (strpos($slug, 'twitter-trends-scraper') !== false || strpos($slug, 'x-twitter-trends') !== false)) {
            return [
                'contract_key' => 'x_trend_list_ambient',
                'required_any_fields' => ['country', 'location', 'woeid'],
                'forbidden_fields' => ['searchTerms', 'queries', 'hashtags', 'profiles', 'business_goal'],
                'allowed_fields' => ['country', 'location', 'woeid', 'maxItems', 'resultsLimit'],
            ];
        }

        if ($platform === 'twitter' && strpos($slug, 'apidojo/tweet-scraper') !== false) {
            return [
                'contract_key' => 'twitter_apidojo_current',
                'required_any_fields' => ['searchTerms', 'twitterHandles', 'startUrls', 'conversationIds'],
                'forbidden_fields' => ['queries', 'hashtags', 'profiles', 'start'],
                'allowed_fields' => ['searchTerms', 'twitterHandles', 'startUrls', 'conversationIds', 'maxItems', 'sort', 'tweetLanguage', 'since'],
            ];
        }

        if ($platform === 'facebook' && strpos($slug, 'facebook-posts-scraper') !== false) {
            return [
                'contract_key' => 'facebook_posts_apify_current',
                'required_any_fields' => ['startUrls'],
                'forbidden_fields' => ['urls', 'pageUrls'],
                'allowed_fields' => ['startUrls', 'resultsLimit', 'maxItems', 'sort', 'dateRange'],
            ];
        }

        if (class_exists('VES_Source_Adapter_Registry')) {
            $adapter = VES_Source_Adapter_Registry::adapter_for($platform, $actor_slug);
            $required_any = array_values((array) ($adapter['required_any'] ?? []));
            $required_fields = array_values((array) ($adapter['required_fields'] ?? []));
            $forbidden = array_values((array) ($adapter['forbidden_fields'] ?? []));
            $allowed = array_values((array) ($adapter['supports_fields'] ?? []));
            if (!empty($required_any) || !empty($required_fields) || !empty($forbidden) || !empty($allowed)) {
                return [
                    'contract_key' => sanitize_key((string) ($adapter['adapter_key'] ?? 'adapter_registry')),
                    'required_any_fields' => $required_any,
                    'required_fields' => $required_fields,
                    'forbidden_fields' => $forbidden,
                    'allowed_fields' => $allowed,
                ];
            }
        }

        return [
            'contract_key' => 'generic_manual_actor',
            'required_any_fields' => [],
            'forbidden_fields' => [],
        ];
    }
}

if (!function_exists('ves_validate_actor_payload_contract')) {
    function ves_validate_actor_payload_contract($platform, $actor_slug, $input) {
        $input = is_array($input) ? $input : [];
        $contract = function_exists('ves_actor_payload_contract') ? ves_actor_payload_contract($platform, $actor_slug) : [];
        $missing_any = [];
        $missing_required = [];
        $required_fields = array_values((array) ($contract['required_fields'] ?? []));
        foreach ($required_fields as $field) {
            $value = $input[$field] ?? null;
            if (is_array($value)) {
                $value = array_values(array_filter($value, static function($v) { return trim((string) $v) !== ''; }));
                if (empty($value)) { $missing_required[] = (string) $field; }
            } elseif (trim((string) $value) === '') {
                $missing_required[] = (string) $field;
            }
        }
        $required_any = array_values((array) ($contract['required_any_fields'] ?? []));
        if (!empty($required_any)) {
            $has_any = false;
            foreach ($required_any as $field) {
                $value = $input[$field] ?? null;
                if (is_array($value)) {
                    $value = array_values(array_filter($value, static function($v) { return trim((string) $v) !== ''; }));
                    if (!empty($value)) { $has_any = true; break; }
                } elseif (trim((string) $value) !== '') {
                    $has_any = true;
                    break;
                }
            }
            if (!$has_any) { $missing_any = $required_any; }
        }

        $forbidden = [];
        foreach ((array) ($contract['forbidden_fields'] ?? []) as $field) {
            if (array_key_exists($field, $input)) { $forbidden[] = (string) $field; }
        }

        $unsupported = [];
        $allowed_fields = array_values(array_filter((array) ($contract['allowed_fields'] ?? [])));
        if (!empty($allowed_fields)) {
            foreach (array_keys($input) as $field) {
                if (strpos((string) $field, '_ves_') === 0) { continue; }
                if (!in_array((string) $field, $allowed_fields, true)) {
                    $unsupported[] = (string) $field;
                }
            }
        }

        if ($platform === 'semrush') {
            $keyword = trim((string) ($input['keyword'] ?? ''));
            if ($keyword !== '') {
                $word_count = preg_match_all('/[\p{L}\p{N}]+/u', $keyword, $m);
                if ($word_count > 5 || preg_match('/\b(identify|analyze|analiza|prioritize|campaign|brief|recommend|investigar|comparar)\b/i', $keyword)) {
                    return new WP_Error('ves_semrush_seed_keyword_required', 'Enter a short seed keyword like seo tools, ai seo tools, herramientas seo.', [
                        'status' => 400,
                        'platform' => 'semrush',
                        'actor_slug' => sanitize_text_field((string) $actor_slug),
                        'bad_keyword' => sanitize_text_field($keyword),
                        'seed_suggestions' => ves_query_planner_extract_seo_keywords_safe(['targets' => $keyword, 'seedKeyword' => $keyword]),
                    ]);
                }
            }
        }

        if (!empty($missing_any) || !empty($missing_required) || !empty($forbidden) || !empty($unsupported)) {
            $message = 'Actor payload contract failed before provider dispatch.';
            if (!empty($missing_required)) { $message .= ' Requires: ' . implode(', ', $missing_required) . '.'; }
            if (!empty($missing_any)) { $message .= ' Requires one of: ' . implode(', ', $missing_any) . '.'; }
            if (!empty($forbidden)) { $message .= ' Unsupported field(s): ' . implode(', ', $forbidden) . '.'; }
            if (!empty($unsupported)) { $message .= ' Non-contract field(s): ' . implode(', ', $unsupported) . '.'; }
            if (class_exists('WP_Error')) {
                return new WP_Error('ves_actor_payload_contract_failed', $message, [
                    'status' => 400,
                    'contract_key' => sanitize_key((string) ($contract['contract_key'] ?? 'generic_manual_actor')),
                    'platform' => sanitize_key((string) $platform),
                    'actor_slug' => sanitize_text_field((string) $actor_slug),
                    'missing_required_fields' => $missing_required,
                    'missing_any_fields' => $missing_any,
                    'forbidden_fields' => $forbidden,
                    'unsupported_fields' => $unsupported,
                    'allowed_fields' => $allowed_fields,
                ]);
            }
            return false;
        }
        return true;
    }
}

if (!function_exists('ves_validate_platform_input')) {
    function ves_validate_platform_input($platform, $input) {
        switch ($platform) {
            case 'tiktok':
                return !empty($input['hashtags']) || !empty($input['profiles']) || !empty($input['searchQueries']) || !empty($input['postURLs']) || !empty($input['keywords']) || !empty($input['startUrls']);
            case 'youtube':
                return !empty($input['searchQueries']) || !empty($input['startUrls']);
            case 'facebook':
                return !empty($input['startUrls']);
            case 'instagram':
                $supports_keyword_search = !empty($input['_ves_actor_supports_keyword_search']) || !empty($input['supportsKeywordSearch']);
                return !empty($input['directUrls']) || !empty($input['profiles']) || !empty($input['hashtags']) || ($supports_keyword_search && !empty($input['search']));
            case 'facebook_ads':
                return !empty($input['urls']) || !empty($input['pageUrls']) || !empty($input['domains']) || !empty($input['searchTerms']);
            case 'google_ads':
                return !empty($input['searchTargets']) || !empty($input['domains']) || !empty($input['text']);
            case 'twitter':
                return !empty($input['searchTerms']) || !empty($input['twitterHandles']) || !empty($input['startUrls']) || !empty($input['conversationIds']);
            case 'linkedin':
                if (!empty($input['_ves_unsupported_linkedin_objective'])) { return false; }
                return !empty($input['searchQuery']) || !empty($input['keywords']) || !empty($input['startUrls']) || !empty($input['companyUrls']) || !empty($input['locations']) || !empty($input['currentJobTitles']) || !empty($input['currentCompanies']);
            case 'reddit':
                return !empty($input['startUrls']) || !empty($input['searches']) || !empty($input['queries']) || !empty($input['search']) || !empty($input['subreddits']);
            case 'pinterest':
                return !empty($input['startUrls']) || !empty($input['search']) || !empty($input['searchTerms']);
            case 'semrush':
                if (!empty($input['_ves_bad_semrush_seed_keyword'])) { return false; }
                if (!empty($input['d1'])) { return true; }
                if (!empty($input['maxResults']) && !empty($input['keyword'])) { return true; }
                if (!empty($input['include_top_websites'])) { return true; }
                $has_keyword_module = !empty($input['include_keyword_volume']) || !empty($input['include_serp']);
                $has_domain_module = !empty($input['include_authority']) || !empty($input['include_overview']) || !empty($input['include_traffic']) || !empty($input['include_backlinks']) || !empty($input['include_ai_visibility']) || !empty($input['include_competitors']) || !empty($input['include_keywords_ranking']) || !empty($input['include_seo']) || !empty($input['include_serp']);
                if (!$has_keyword_module && !$has_domain_module) { return false; }
                if ($has_domain_module && !empty($input['urls'])) { return true; }
                if ($has_keyword_module && !empty($input['keyword'])) { return true; }
                return false;
            case 'moz':
                return !empty($input['urls']);
            case 'ahrefs':
                $search_type = sanitize_key((string) ($input['searchType'] ?? 'website_authority'));
                if ($search_type === 'top_websites') {
                    return true;
                }
                if (in_array($search_type, ['website_authority','backlinks_overview','backlinks_list','broken_links','traffic_overview','website_details'], true)) {
                    return !empty($input['urls']);
                }
                if ($search_type === 'keyword_rank') {
                    return !empty($input['keyword']) && !empty($input['urls']);
                }
                if (in_array($search_type, ['ai_visibility','keyword_ideas','keyword_difficulty','keyword_metrics','serp_overview'], true)) {
                    return !empty($input['keyword']);
                }
                return !empty($input['urls']) || !empty($input['keyword']);
        }
        return false;
    }
}

if (!function_exists('ves_invalid_input_message')) {
    function ves_invalid_input_message($platform, $src = []) {
        if (function_exists('ves_is_post_url_mode') && ves_is_post_url_mode($src)) {
            return 'Has elegido URL de post(s), pero no encontramos URLs de publicaciones válidas. Si pegaste un perfil/canal, usa el modo "URL de perfil".';
        }
        if (function_exists('ves_is_profile_url_mode') && ves_is_profile_url_mode($src)) {
            return 'Has elegido URL de perfil, pero no encontramos perfiles/canales válidos. Si pegaste posts concretos, usa el modo "URL de post(s)".';
        }
        switch (sanitize_key((string) $platform)) {
            case 'facebook':
                return 'Facebook Posts requiere URLs completas de páginas públicas.';
            case 'instagram':
                return 'Introduce hashtags, perfiles o URLs de posts/reels compatibles.';
            case 'facebook_ads':
                return 'Facebook Ads Library requiere URLs de búsqueda de la librería de anuncios o URLs de páginas de Facebook.';
            case 'google_ads':
                return 'Google Ads requiere al menos un keyword, dominio, advertiser ID o URL de Google Ads Transparency.';
            case 'twitter':
                return 'Introduce al menos una búsqueda, hashtag, perfil o URL de post de X/Twitter.';
            case 'linkedin':
                $linkedin_objective = sanitize_key((string) ($src['linkedinObjective'] ?? $src['mode'] ?? ''));
                if (in_array($linkedin_objective, ['find_people','scan_profile','search_companies','find_leads','email_extraction','contact_extraction'], true)) {
                    return 'Este modo de LinkedIn requiere configuración adicional del proveedor. No se ha ejecutado ningún actor ni se han reservado créditos.';
                }
                return 'LinkedIn requiere una búsqueda, empresa, tema u objetivo de posts soportado.';
            case 'reddit':
                return 'Introduce una búsqueda, comunidad o URL pública de Reddit.';
            case 'pinterest':
                return 'Pinterest requiere una keyword o URLs públicas válidas de Pinterest.';
            case 'semrush':
                $explicit_seed = trim(sanitize_text_field((string) ($src['seedKeyword'] ?? $src['seed_keyword'] ?? $src['seoSeedKeyword'] ?? $src['semrushKeyword'] ?? '')));
                if ($explicit_seed !== '' && ves_query_planner_looks_like_objective_safe($explicit_seed)) {
                    $suggestions = ves_query_planner_keyword_candidates_safe($explicit_seed);
                    $suffix = !empty($suggestions) ? ' Sugerencias: ' . implode(', ', array_slice($suggestions, 0, 5)) . '.' : '';
                    return 'Introduce una keyword semilla corta como herramientas SEO, cerveza mahou o AI SEO tools.' . $suffix;
                }
                return 'Semrush requiere una keyword corta, dominio/URL, o el modo Top Websites según el tipo de análisis seleccionado.';
            case 'moz':
                return 'MOZ requiere al menos un dominio o URL válido.';
            case 'ahrefs':
                $search_type = sanitize_key((string) ($src['mode'] ?? ''));
                if ($search_type === 'keyword_rank') {
                    return 'Para Ahrefs Keyword Rank introduce una keyword y al menos un dominio/URL, una línea por cada valor.';
                }
                if (in_array($search_type, ['website_authority','backlinks_overview','backlinks_list','broken_links','traffic_overview','website_details'], true)) {
                    return 'Para este análisis de Ahrefs introduce al menos un dominio o URL válido.';
                }
                if (in_array($search_type, ['ai_visibility','keyword_ideas','keyword_difficulty','keyword_metrics','serp_overview'], true)) {
                    return 'Para este análisis de Ahrefs introduce una keyword, marca o frase válida.';
                }
                return 'Ahrefs requiere un dominio/URL, una keyword o el modo Top Websites.';
            case 'youtube':
                return 'Introduce al menos una búsqueda, canal o URL de vídeo de YouTube.';
            case 'tiktok':
                return 'Introduce al menos un término de búsqueda, hashtag, perfil o URL de vídeo.';
        }
        return 'Introduce una entrada válida para la plataforma seleccionada.';
    }
}
