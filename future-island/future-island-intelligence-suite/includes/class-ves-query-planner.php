<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Source-specific query planner.
 *
 * v0.9.24.79 hardens the separation between provider-safe inputs and AI-only
 * context. The provider must receive only short source-specific terms, URLs,
 * domains, handles or IDs. Objectives, market context and interpretation goals
 * stay in the AI context package and must not leak into actor payloads.
 */
final class VES_Query_Planner {
    public static function normalize_request_fields($module, $platform, $request) {
        $request = is_array($request) ? $request : [];
        $module = sanitize_key((string) $module);
        $platform = sanitize_key((string) $platform);
        $candidate_pool = self::int_value(self::first_non_empty($request, ['candidatePoolSize','candidatePool','candidate_pool','candidate_pool_size','poolSize','maxItems']), 0, 0, 2000);
        $desired = self::int_value(self::first_non_empty($request, ['desiredFinalResults','desiredFinalTrends','desired_final_results','desired_final_trends','limit','maxResults']), 20, 1, 1000);

        return [
            'module' => $module,
            'platform' => $platform,
            'mode' => sanitize_key((string) self::first_non_empty($request, ['mode','scrapeMode','objectiveType','linkedinObjective','socialPlatformMode','adsMode','seoMode'])),
            'query_intent' => sanitize_key((string) self::first_non_empty($request, ['queryIntent','query_intent','redditIntent','trendType'])),
            'scraper_query_terms' => self::extract_scraper_terms($module, $platform, $request),
            'seed_keywords' => self::extract_seo_seed_keywords($request),
            'hashtags' => self::extract_social_hashtags($request),
            'target_urls' => self::extract_target_urls($request),
            'target_profiles' => self::extract_profiles($request),
            'subreddits' => self::extract_subreddits($request),
            'domains' => self::extract_domains($request),
            'handles' => self::extract_handles($request),
            'business_objective' => self::clean_text(self::first_non_empty($request, ['businessObjective','business_objective','businessGoal','business_goal','trendReason','reason','objective','aiObjective']), 1200),
            'search_context' => self::clean_text(self::first_non_empty($request, ['searchContext','context','marketContext','market_context']), 1600),
            'ai_analysis_goal' => self::clean_text(self::first_non_empty($request, ['aiAnalysisGoal','ai_analysis_goal','analysisGoal','analysis_focus']), 1200),
            'ranking_mode' => sanitize_key((string) self::first_non_empty($request, ['rankingMode','ranking_mode','sortPriority','sort_priority','trendSort','sortBy','sort_by'])),
            'candidate_pool_size' => $candidate_pool,
            'desired_final_results' => $desired,
            'country' => self::country_code($request),
            'language' => self::language_code($request),
            'date_range' => self::date_range_key($request),
            'strict_language_filter' => self::bool_flag($request, ['strictLanguageFilter','strict_language_filter']),
            'include_transcript' => self::bool_flag($request, ['includeTranscript','include_transcript']),
            'strict_subtitle_language' => self::bool_flag($request, ['strictSubtitleLanguage','strict_subtitle_language','requireSubtitles','require_subtitles']),
            'allow_english_terms' => self::bool_flag($request, ['allowEnglishSeoTerms','allow_english_seo_terms','allowEnglishTerms','allow_english_terms']),
            'negative_terms' => self::extract_negative_terms($request),
            'warnings' => [],
        ];
    }

    /**
     * Sprint 1 (Part A): collect sanitized negative/exclusion terms already
     * produced upstream (query expansion). These are used ONLY to append safe
     * Google query-string exclusions; they never become a new payload field and
     * are never applied to non-Google providers.
     */
    public static function extract_negative_terms($request) {
        $request = is_array($request) ? $request : [];
        $raw = [];
        if (!empty($request['negative_terms']) && is_array($request['negative_terms'])) {
            $raw = array_merge($raw, $request['negative_terms']);
        }
        if (!empty($request['expanded_queries']['negative_terms']) && is_array($request['expanded_queries']['negative_terms'])) {
            $raw = array_merge($raw, $request['expanded_queries']['negative_terms']);
        }
        $out = [];
        foreach ($raw as $term) {
            $term = sanitize_text_field((string) $term);
            $term = trim(preg_replace('/\s+/', ' ', $term));
            if ($term !== '') { $out[] = $term; }
        }
        return array_values(array_unique($out));
    }

    /**
     * Sprint 1 (Part A): append Google query-string exclusions to an existing
     * raw query. Single-word -> -term ; multi-word -> -"multi word". Caps the
     * number of injected exclusions; sanitizes and de-duplicates; returns the
     * original query untouched when there are no usable negative terms.
     *
     * This is Google-only by contract: callers must only invoke it for Google
     * Search / Google News raw query strings. It adds NO payload field.
     */
    public static function apply_google_query_exclusions($query, $negative_terms, $max = 6) {
        $query = (string) $query;
        // Guard: never build a negative-only query. If the base query is empty or
        // whitespace-only, return it untouched so no exclusion-only string is
        // dispatched to the provider.
        if (trim($query) === '') { return $query; }
        $suffix = self::build_negative_query_suffix($negative_terms, $max);
        if ($suffix === '') { return $query; }
        return $query . ' ' . $suffix;
    }

    public static function build_negative_query_suffix($negative_terms, $max = 6) {
        if (!is_array($negative_terms) || empty($negative_terms)) { return ''; }
        $max = max(0, (int) $max);
        $parts = [];
        $seen = [];
        foreach ($negative_terms as $term) {
            if (count($parts) >= $max) { break; }
            $term = trim(preg_replace('/\s+/', ' ', (string) $term));
            // Strip characters that would break Google query / quoting syntax.
            $term = str_replace(['"', '\\', "\n", "\r", "\t"], '', $term);
            $term = trim($term, "- \t");
            if ($term === '') { continue; }
            $key = strtolower($term);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $parts[] = (strpos($term, ' ') !== false) ? ('-"' . $term . '"') : ('-' . $term);
        }
        return implode(' ', $parts);
    }

    public static function first_non_empty($request, $keys) {
        $request = is_array($request) ? $request : [];
        foreach ((array) $keys as $key) {
            if (isset($request[$key])) {
                $value = $request[$key];
                if (is_array($value)) {
                    $flat = implode("\n", array_map('strval', $value));
                    if (trim($flat) !== '') { return $value; }
                } elseif (trim((string) $value) !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    public static function clean_text($value, $max = 800) {
        if (is_array($value)) { $value = implode("\n", array_map('strval', $value)); }
        $value = function_exists('wp_unslash') ? wp_unslash($value) : $value;
        $value = function_exists('sanitize_textarea_field') ? sanitize_textarea_field((string) $value) : strip_tags((string) $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($max > 0) {
            return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
        }
        return $value;
    }

    public static function is_long_sentence($text) {
        $text = trim((string) $text);
        if ($text === '') { return false; }
        $word_count = preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        return $len > 72 || $word_count > 8 || (bool) preg_match('/[\.\?\!;:]/u', $text);
    }

    public static function looks_like_objective($text) {
        $t = strtolower(self::clean_text($text, 400));
        if ($t === '') { return false; }
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:\/.*)?$/i', $t)) { return false; }
        if (self::is_long_sentence($t)) { return true; }
        return (bool) preg_match('/\b(identify|analyze|analyse|discover|prioritize|prioritise|compare|generate|inspire|brief|objective|goal|campaign|insight|recommend|turn|separate|explain|validate|analizar|identificar|comparar|generar|objetivo|campan|recomendar|explicar|validar)\b/u', $t);
    }

    public static function short_term_or_empty($text, $max_words = 6, $max_len = 80) {
        $text = self::clean_text($text, $max_len + 80);
        $text = trim($text, " \t\n\r\0\x0B,.;:!?()[]{}\"'");
        if ($text === '' || preg_match('~^https?://~i', $text)) { return ''; }
        if (self::looks_like_objective($text)) { return ''; }
        $word_count = preg_match_all('/[\p{L}\p{N}\+]+/u', $text, $m);
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($word_count > $max_words || $len > $max_len) { return ''; }
        return sanitize_text_field($text);
    }

    public static function split_lines($raw) {
        if (is_array($raw)) { $raw = implode("\n", array_map('strval', $raw)); }
        $parts = preg_split('/[\r\n,;]+/u', (string) $raw);
        $out = [];
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') { $out[] = $part; }
        }
        return $out;
    }

    public static function int_value($value, $default, $min, $max) {
        $n = is_numeric($value) ? (int) $value : (function_exists('absint') ? absint($value) : abs((int) $value));
        if ($n <= 0) { $n = (int) $default; }
        return max((int) $min, min((int) $max, $n));
    }

    public static function extract_seo_seed_keywords($request) {
        $request = is_array($request) ? $request : [];
        $raw = self::first_non_empty($request, ['seedKeyword','seed_keyword','seoSeedKeyword','seo_seed_keyword','seoKeyword','seo_keyword','keywordSeed','keyword_seed','keywords','keyword','semrushKeyword','semrush_keyword','ahrefsKeyword','ahrefs_keyword','ahrefsKeywords','ahrefs_keywords']);
        if ($raw === '') { $raw = self::first_non_empty($request, ['targets','queryTerms','query_terms']); }
        $candidates = [];
        foreach (self::split_lines($raw) as $line) {
            if (preg_match('~^https?://~i', $line) || preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(?:/.*)?$~i', $line)) { continue; }
            $term = self::short_term_or_empty($line, 6, 80);
            if ($term !== '') { $candidates[] = $term; continue; }
            foreach (self::extract_keyword_candidates_from_sentence($line) as $candidate) { $candidates[] = $candidate; }
        }
        return array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $candidates)))), 0, 10);
    }

    public static function extract_keyword_candidates_from_sentence($text) {
        $text = strtolower(self::clean_text($text, 500));
        $tokens = preg_split('/[^\p{L}\p{N}\+]+/u', $text);
        $stop = ['identify','analyze','analyse','top','with','for','from','that','this','these','those','target','targets','market','audience','strategy','strategies','campaign','campaigns','related','inspire','hooks','creative','angles','briefs','social','media','find','discover','prioritize','prioritise','and','the','to','of','on','in','a','an','para','por','con','los','las','una','uno','del','de','en','estrategia','campana','campaña','objetivo','analizar','identificar','mercado','audiencia'];
        $words = [];
        foreach ((array) $tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '' || in_array($tok, $stop, true) || preg_match('/^\d+$/', $tok)) { continue; }
            $words[] = $tok;
        }
        $out = [];
        for ($n = 2; $n <= 4; $n++) {
            for ($i = 0; $i <= count($words) - $n; $i++) {
                $phrase = implode(' ', array_slice($words, $i, $n));
                if (self::short_term_or_empty($phrase, 6, 80) !== '') { $out[] = $phrase; }
                if (count($out) >= 8) { break 2; }
            }
        }
        if (empty($out) && !empty($words)) { $out[] = implode(' ', array_slice($words, 0, min(3, count($words)))); }
        return array_values(array_unique($out));
    }

    public static function extract_scraper_terms($module, $platform, $request) {
        $request = is_array($request) ? $request : [];
        $platform = sanitize_key((string) $platform);
        $raw = self::first_non_empty($request, ['queryTerms','query_terms','scraperQueryTerms','scraper_query_terms','searchQuery','search_query','topic']);
        if ($raw === '') {
            $legacy = self::first_non_empty($request, ['targets']);
            $raw = self::looks_like_objective($legacy) ? '' : $legacy;
        }
        if ($raw !== '' && self::looks_like_objective($raw)) { return []; }
        $terms = [];
        foreach (self::split_lines($raw) as $line) {
            if (preg_match('~^https?://~i', $line)) { continue; }
            $trim = trim($line);
            if (strpos($trim, '@') === 0) { continue; }
            if (strpos($trim, '#') === 0 && $platform !== 'twitter') { continue; }
            $term = self::short_term_or_empty(ltrim($trim, '#'), 6, 80);
            if ($term !== '') { $terms[] = $term; }
        }
        return array_slice(array_values(array_unique($terms)), 0, 40);
    }

    public static function extract_social_hashtags($request) {
        $request = is_array($request) ? $request : [];
        $raw = self::first_non_empty($request, ['hashtags','hashtagTerms','hashtag_terms']);
        $fallback = self::first_non_empty($request, ['targets']);
        if (self::looks_like_objective($fallback)) { $fallback = ''; }
        $lines = array_merge(self::split_lines($raw), self::split_lines($fallback));
        $tags = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            if (strpos($line, '#') !== 0) {
                $short = self::short_term_or_empty($line, 3, 50);
                if ($short === '') { continue; }
                $line = '#' . $short;
            }
            $tag = ltrim($line, "# \t\n\r\0\x0B");
            if (self::looks_like_objective($tag)) { continue; }
            $tag = function_exists('remove_accents') ? remove_accents($tag) : $tag;
            $tag = preg_replace('/[^A-Za-z0-9_]+/', '', $tag);
            $tag = trim((string) $tag, '_');
            if ($tag !== '' && strlen($tag) <= 50) { $tags[] = strtolower($tag); }
        }
        return array_slice(array_values(array_unique($tags)), 0, 30);
    }

    public static function extract_target_urls($request) {
        $request = is_array($request) ? $request : [];
        $raw_lines = [];
        foreach (['targetUrls','target_urls','postUrls','post_urls','videoUrls','video_urls','profileUrls','profile_urls','channelUrls','channel_urls','startUrls','start_urls','urls','url','pageUrl','pageUrls','companyUrl','companyUrls'] as $key) {
            if (isset($request[$key]) && trim(is_array($request[$key]) ? implode("\n", $request[$key]) : (string) $request[$key]) !== '') {
                $raw_lines = array_merge($raw_lines, self::split_lines($request[$key]));
            }
        }
        $targets = self::first_non_empty($request, ['targets']);
        if ($targets !== '' && !self::looks_like_objective($targets)) { $raw_lines = array_merge($raw_lines, self::split_lines($targets)); }
        $urls = [];
        foreach ($raw_lines as $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(/.*)?$~i', $line)) { $line = 'https://' . $line; }
            if (!preg_match('~^https?://~i', $line)) { continue; }
            $url = esc_url_raw($line);
            if ($url !== '') { $urls[] = $url; }
        }
        return array_slice(array_values(array_unique($urls)), 0, 100);
    }

    public static function extract_profiles($request) {
        $urls = self::extract_target_urls($request);
        $profiles = [];
        foreach ($urls as $url) {
            if (preg_match('~/(?:@)?([A-Za-z0-9._-]{2,})/?$~', parse_url($url, PHP_URL_PATH) ?: '', $m)) { $profiles[] = $m[1]; }
        }
        foreach (self::split_lines(self::first_non_empty($request, ['profileUrls','profile_urls','handles','handle'])) as $line) {
            $line = trim((string) $line);
            if (strpos($line, '@') === 0) { $line = substr($line, 1); }
            if (!preg_match('~^https?://~i', $line) && preg_match('/^[A-Za-z0-9._-]{2,}$/', $line)) { $profiles[] = $line; }
        }
        return array_slice(array_values(array_unique(array_filter($profiles))), 0, 50);
    }

    public static function validate_scraper_query($module, $platform, $request) {
        $normalized = self::normalize_request_fields($module, $platform, $request);
        $platform = sanitize_key((string) $platform);

        if ($platform === 'linkedin') {
            $mode = sanitize_key((string) ($normalized['mode'] ?? ''));
            if (in_array($mode, ['find_people','scan_profile','search_companies','find_leads','email_extraction','lead_extraction','contact_extraction'], true)) {
                return new WP_Error('ves_linkedin_objective_not_configured', __('LinkedIn provider for this objective is not configured yet. Ask admin to configure or approve a compatible actor.', 'ves'), [
                    'requested_objective' => $mode,
                    'setup_required' => true,
                    'no_provider_call' => true,
                    'no_credit_reservation' => true,
                ]);
            }
        }

        if ($platform === 'semrush') {
            $mode = sanitize_key((string) ($normalized['mode'] ?? ''));
            $explicit_seed = self::first_non_empty($request, ['seedKeyword','seed_keyword','seoSeedKeyword','seo_seed_keyword','seoKeyword','seo_keyword','keywordSeed','keyword_seed','keywords','keyword','semrushKeyword','semrush_keyword','ahrefsKeyword','ahrefs_keyword','ahrefsKeywords','ahrefs_keywords']);
            $legacy_query = self::first_non_empty($request, ['targets','queryTerms','query_terms']);
            $bad_seed = '';
            if ($explicit_seed !== '' && self::looks_like_objective($explicit_seed)) {
                $bad_seed = is_array($explicit_seed) ? implode(' ', $explicit_seed) : (string) $explicit_seed;
            } elseif (in_array($mode, ['keyword_magic','keyword_simple','keyword_ideas','serp_overview'], true) && $explicit_seed === '' && $legacy_query !== '' && self::looks_like_objective($legacy_query)) {
                $bad_seed = is_array($legacy_query) ? implode(' ', $legacy_query) : (string) $legacy_query;
            }
            if ($bad_seed !== '') {
                return new WP_Error('ves_semrush_seed_keyword_required', __('Introduce una keyword semilla corta, por ejemplo cerveza mahou, seo tools o herramientas seo.', 'ves'), [
                    'seed_suggestions' => self::extract_keyword_candidates_from_sentence($bad_seed),
                    'bad_keyword' => self::clean_text($bad_seed, 160),
                    'no_provider_call' => true,
                    'no_credit_reservation' => true,
                ]);
            }
            if (in_array($mode, ['domain_seo','competitor_seo_scan','domain_overview'], true)) {
                if (empty($normalized['domains']) && empty($normalized['target_urls'])) {
                    return new WP_Error('ves_semrush_domain_required', __('Añade al menos un dominio válido, por ejemplo mahou.es o https://mahou.es.', 'ves'), [
                        'no_provider_call' => true,
                        'no_credit_reservation' => true,
                    ]);
                }
            } elseif ($mode !== 'top_websites') {
                $seed = $normalized['seed_keywords'][0] ?? '';
                if ($seed === '' || self::short_term_or_empty($seed, 6, 80) === '') {
                    return new WP_Error('ves_semrush_seed_keyword_required', __('Introduce una keyword semilla corta, por ejemplo cerveza mahou, seo tools o herramientas seo.', 'ves'), [
                        'seed_suggestions' => [],
                        'no_provider_call' => true,
                        'no_credit_reservation' => true,
                    ]);
                }
            }
        } elseif (in_array($platform, ['ahrefs','moz'], true) && empty($normalized['seed_keywords']) && empty($normalized['domains']) && empty($normalized['target_urls'])) {
            return new WP_Error('ves_seed_keyword_required', __('Add a short SEO seed keyword or domain before running this source.', 'ves'));
        }

        if ($platform === 'tiktok' && empty($normalized['scraper_query_terms']) && empty($normalized['hashtags']) && empty($normalized['target_urls']) && empty($normalized['target_profiles'])) {
            return new WP_Error('ves_tiktok_provider_input_required', __('Add TikTok query terms, hashtags, profile URLs, or video URLs before running. Business objective and context are AI-only and are not sent as provider keywords.', 'ves'), [
                'no_provider_call' => true,
                'no_credit_reservation' => true,
            ]);
        }
        if ($platform === 'instagram' && empty($normalized['scraper_query_terms']) && empty($normalized['hashtags']) && empty($normalized['target_urls']) && empty($normalized['target_profiles'])) {
            return new WP_Error('ves_instagram_provider_input_required', __('Add Instagram hashtags, profile URLs, post/reel URLs, or a short search term before running.', 'ves'), [
                'no_provider_call' => true,
                'no_credit_reservation' => true,
            ]);
        }
        if ($platform === 'reddit' && empty($normalized['scraper_query_terms']) && empty($normalized['target_urls']) && empty($normalized['subreddits'])) {
            return new WP_Error('ves_reddit_query_required', __('Add a Reddit search query, subreddit, or Reddit URL before running this source.', 'ves'));
        }
        if (in_array($platform, ['facebook_ads','google_ads'], true) && empty($normalized['domains']) && empty($normalized['scraper_query_terms']) && empty($normalized['target_urls'])) {
            return new WP_Error('ves_ads_target_required', __('Add an advertiser, domain, page, or keyword before running Ads Intelligence.', 'ves'));
        }
        return true;
    }

    private static function conservative_social_queries($platform, $terms, $normalized_request) {
        $platform = sanitize_key((string) $platform);
        $terms = array_values(array_filter(array_map('strval', (array) $terms)));
        $out = [];
        $dropped = [];
        $lang = strtolower((string) ($normalized_request['language'] ?? ''));
        $allow_english = !empty($normalized_request['allow_english_terms']);
        $generic = ['marketing','cerveza','beer','ia','ai','digital','tips','strategy','estrategia','online','agency','tools','herramientas'];
        foreach ($terms as $term) {
            $term = self::short_term_or_empty($term, 6, 80);
            if ($term === '') { continue; }
            $out[] = $term;
            $low = strtolower($term);
            $tokens = preg_split('/\s+/u', $low);
            if (count($tokens) >= 2) {
                if (strpos($low, ' con ia') !== false) {
                    $out[] = trim(str_replace(' con ia', ' inteligencia artificial', $low));
                    $out[] = trim(str_replace(' con ia', ' ia', $low));
                }
                if (strpos($low, 'mahou') !== false && strpos($low, 'cerveza') !== false) {
                    $out[] = 'mahou cerveza';
                }
            }
        }
        $clean = [];
        foreach ($out as $q) {
            $q = self::short_term_or_empty($q, 6, 80);
            if ($q === '') { continue; }
            $q_low = strtolower($q);
            $parts = preg_split('/\s+/u', $q_low);
            if (count($parts) === 1 && in_array($q_low, $generic, true)) { $dropped[] = ['variant' => $q, 'reason' => 'generic_single_token']; continue; }
            if (($lang === 'es' || $lang === 'es-es' || $lang === '') && !$allow_english && preg_match('/\b(marketingtips|marketingstrategy|marketingagency|marketingplan|marketingtools|marketingonline|marketingexperts|beer)\b/i', $q)) { $dropped[] = ['variant' => $q, 'reason' => 'english_or_generic_noise']; continue; }
            $clean[] = $q;
        }
        $clean = array_values(array_unique($clean));
        // Exact query first, then at most 3 close variants in normal mode.
        $clean = array_slice($clean, 0, 4);
        if (class_exists('VES_Admin') && current_user_can('manage_options')) {
            VES_Admin::record_diagnostic('query_expansion_diagnostics', 'Conservative social query expansion applied.', [
                'platform' => $platform,
                'original_input' => $terms,
                'generated_variants' => $clean,
                'dropped_variants' => $dropped,
            ]);
        }
        return $clean;
    }

    public static function build_actor_payload($module, $platform, $actor, $normalized_request) {
        $n = is_array($normalized_request) ? $normalized_request : self::normalize_request_fields($module, $platform, []);
        $platform = sanitize_key((string) $platform);
        $actor = sanitize_text_field((string) $actor);
        $limit = max(1, (int) ($n['candidate_pool_size'] ?: $n['desired_final_results'] ?: 20));
        $country = strtoupper((string) ($n['country'] ?: ''));
        $language = strtolower((string) ($n['language'] ?: ''));
        $mode = sanitize_key((string) ($n['mode'] ?? ''));

        switch ($platform) {
            case 'semrush':
                $seed = $n['seed_keywords'][0] ?? ($n['scraper_query_terms'][0] ?? '');
                $seed = self::short_term_or_empty($seed, 6, 80);
                if (in_array($mode, ['domain_seo','competitor_seo_scan','domain_overview'], true)) {
                    $domains = [];
                    foreach ((array) ($n['domains'] ?? []) as $domain) {
                        $domain = strtolower(trim((string) $domain));
                        $domain = preg_replace('~^https?://~i', '', $domain);
                        $domain = preg_replace('~/.*$~', '', $domain);
                        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) { $domains[] = 'https://' . $domain; }
                    }
                    foreach ((array) ($n['target_urls'] ?? []) as $url) {
                        $url = esc_url_raw((string) $url);
                        if ($url !== '') { $domains[] = $url; }
                    }
                    return array_filter([
                        'urls' => array_values(array_unique($domains)),
                        'country' => strtolower($country ?: 'es'),
                        'include_authority' => true,
                        'include_overview' => true,
                        'include_traffic' => true,
                        'include_backlinks' => true,
                        'include_competitors' => true,
                        'include_keyword_volume' => true,
                        'include_serp' => true,
                        'maxResults' => $limit,
                    ], [__CLASS__, 'non_empty']);
                }
                return array_filter([
                    'keyword' => $seed,
                    'country' => $country ?: 'ES',
                    'database' => strtolower($country ?: 'es'),
                    'language' => $language ?: 'es',
                    'limit' => $limit,
                    'maxResults' => $limit,
                    'includeRelated' => true,
                    'includeQuestions' => true,
                ], [__CLASS__, 'non_empty']);

            case 'ahrefs':
            case 'moz':
                $seed = $n['seed_keywords'][0] ?? ($n['scraper_query_terms'][0] ?? '');
                $seed = self::short_term_or_empty($seed, 6, 80);
                return array_filter([
                    'keyword' => $seed,
                    'country' => $country ?: 'ES',
                    'database' => strtolower($country ?: 'es'),
                    'language' => $language ?: 'es',
                    'limit' => $limit,
                    'maxResults' => $limit,
                    'includeRelated' => true,
                    'includeQuestions' => true,
                ], [__CLASS__, 'non_empty']);

            case 'instagram':
                $direct = [];
                foreach ($n['target_urls'] as $url) { if (strpos($url, 'instagram.com') !== false) { $direct[] = $url; } }
                foreach ($n['hashtags'] as $tag) { $direct[] = 'https://www.instagram.com/explore/tags/' . rawurlencode(ltrim($tag, '#')) . '/'; }
                return array_filter([
                    'directUrls' => array_values(array_unique($direct)),
                    'resultsType' => self::instagram_sort($n['ranking_mode'] ?? ''),
                    'resultsLimit' => $limit,
                    'maxItems' => $limit,
                    'search' => empty($direct) ? ($n['scraper_query_terms'][0] ?? '') : '',
                    'searchType' => empty($direct) ? 'hashtag' : '',
                    'addParentData' => false,
                ], [__CLASS__, 'non_empty']);

            case 'tiktok':
                $hashtags = array_values(array_unique(array_map(static function($tag) { return ltrim((string) $tag, '#'); }, $n['hashtags'])));
                $queries = self::conservative_social_queries('tiktok', array_values(array_unique($n['scraper_query_terms'])), $n);
                $tiktok_urls = array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'tiktok.com') !== false; }));
                $profiles = array_values(array_unique($n['target_profiles'] ?? []));
                $actor_lc = strtolower($actor);
                if (strpos($actor_lc, 'clockworks/tiktok-hashtag-scraper') !== false) {
                    return array_filter([
                        'hashtags' => $hashtags,
                        'resultsPerPage' => $limit,
                    ], [__CLASS__, 'non_empty']);
                }
                if (strpos($actor_lc, 'clockworks/tiktok-scraper') !== false || strpos($actor_lc, 'free-tiktok-scraper') !== false) {
                    return array_filter([
                        'searchQueries' => $queries,
                        'hashtags' => $hashtags,
                        'profiles' => $profiles,
                        'postURLs' => $tiktok_urls,
                        'resultsPerPage' => $limit,
                        'searchSection' => 'videos',
                        'videoSearchSorting' => self::tiktok_sort($n['ranking_mode'] ?? ''),
                        'proxyCountryCode' => $country,
                    ], [__CLASS__, 'non_empty']);
                }
                $keywords = array_values(array_unique(array_merge($queries, $hashtags)));
                return array_filter([
                    'keywords' => $keywords,
                    'startUrls' => $tiktok_urls,
                    'maxItems' => min($limit, 50),
                    'sortType' => self::tiktok_sort($n['ranking_mode'] ?? ''),
                    'location' => $country,
                ], [__CLASS__, 'non_empty']);

            case 'reddit':
                $queries = array_values(array_unique($n['scraper_query_terms']));
                $subreddits = array_values(array_unique($n['subreddits']));
                $include_comments = ($mode === 'comments' || $mode === 'pain_points' || $mode === 'questions' || $mode === 'objections');
                return array_filter([
                    'searches' => $queries,
                    'queries' => $queries,
                    'search' => $queries[0] ?? '',
                    'subreddits' => $subreddits,
                    'startUrls' => array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'reddit.com') !== false; })),
                    'searchPosts' => $mode !== 'communities',
                    'searchComments' => $include_comments,
                    'searchCommunities' => $mode === 'communities',
                    'sort' => self::reddit_sort($n['ranking_mode'] ?? '', $mode),
                    'time' => self::reddit_time($n['date_range'] ?? '', $request_time = null),
                    'maxItems' => $limit,
                    'maxPostCount' => $limit,
                    'maxComments' => $include_comments ? min(30, max(5, (int) floor($limit / 2))) : 0,
                    'includeNSFW' => false,
                ], [__CLASS__, 'non_empty']);

            case 'linkedin':
                if (in_array($mode, ['find_people','scan_profile','search_companies','find_leads','email_extraction','lead_extraction'], true)) {
                    return ['_ves_unsupported_linkedin_objective' => true, 'mode' => $mode];
                }
                return array_filter([
                    'searchQuery' => $n['scraper_query_terms'][0] ?? '',
                    'keywords' => $n['scraper_query_terms'],
                    'companyUrls' => array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'linkedin.com/company') !== false; })),
                    'startUrls' => array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'linkedin.com') !== false; })),
                    'maxItems' => $limit,
                    'sort' => self::generic_sort($n['ranking_mode'] ?? ''),
                    'dateRange' => self::date_range_key_to_provider($n['date_range'] ?? ''),
                    'contentLanguage' => $language,
                    'location' => $country,
                ], [__CLASS__, 'non_empty']);

            case 'youtube':
                $yt_urls = array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'youtu') !== false; }));
                $queries = array_values(array_unique(array_filter(array_map('strval', (array) ($n['scraper_query_terms'] ?? [])))));
                if (function_exists('ves_youtube_search_terms')) {
                    $queries = ves_youtube_search_terms($queries, ['language' => $language, 'country' => $country], 5);
                } else {
                    $queries = array_slice($queries, 0, 5);
                }
                $include_transcript = !empty($n['include_transcript']);
                $strict_subtitles = !empty($n['strict_subtitle_language']);
                $payload = [
                    'searchQueries' => $queries,
                    'startUrls' => $yt_urls,
                    'maxResults' => $limit,
                    'maxItems' => $limit,
                    'sortBy' => self::youtube_sort($n['ranking_mode'] ?? ''),
                    'publishedAfter' => self::published_after($n['date_range'] ?? ''),
                    'includeTranscript' => $include_transcript,
                    'language' => $language,
                    'country' => $country,
                    'maxResultsShorts' => 0,
                    'maxResultStreams' => 0,
                ];
                if ($include_transcript && $strict_subtitles && $language !== '') {
                    $payload['subtitlesLanguage'] = $language;
                }
                return array_filter($payload, [__CLASS__, 'non_empty']);

            case 'twitter':
            case 'x':
                return array_filter([
                    'searchTerms' => array_values(array_unique(array_merge($n['scraper_query_terms'], array_map(static function($tag) { return '#' . ltrim((string) $tag, '#'); }, $n['hashtags'])))),
                    'twitterHandles' => $n['handles'],
                    'startUrls' => array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'twitter.com') !== false || strpos($url, 'x.com') !== false; })),
                    'maxItems' => $limit,
                    'sort' => self::twitter_sort($n['ranking_mode'] ?? ''),
                    'tweetLanguage' => $language,
                    'since' => self::published_after($n['date_range'] ?? ''),
                ], [__CLASS__, 'non_empty']);

            case 'facebook':
            case 'facebook_posts':
                return array_filter([
                    'searchQueries' => $n['scraper_query_terms'],
                    'startUrls' => array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'facebook.com') !== false; })),
                    'resultsLimit' => $limit,
                    'maxItems' => $limit,
                    'sort' => self::generic_sort($n['ranking_mode'] ?? ''),
                    'dateRange' => self::date_range_key_to_provider($n['date_range'] ?? ''),
                ], [__CLASS__, 'non_empty']);

            case 'facebook_ads':
            case 'meta_ads':
                $domains = array_values(array_unique(array_merge($n['domains'], self::terms_that_look_like_domains($n['scraper_query_terms']))));
                $page_urls = array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'facebook.com') !== false || strpos($url, 'meta.com') !== false; }));
                $urls = self::meta_ads_urls($domains, $page_urls, $n['scraper_query_terms'], $country);
                return array_filter([
                    // curious_coder/facebook-ads-library-scraper expects URL objects and literal dot-named scrapePageAds.* keys.
                    'urls' => $urls,
                    'pageUrls' => $page_urls,
                    'domains' => $domains,
                    'searchTerms' => array_values(array_filter($n['scraper_query_terms'], static function($term) { return !self::looks_like_objective($term); })),
                    'country' => $country ?: 'ES',
                    'count' => $limit,
                    'resultsLimit' => $limit,
                    'maxItems' => $limit,
                    'scrapePageAds.activeStatus' => 'all',
                    'scrapePageAds.sortBy' => 'impressions_desc',
                    'scrapePageAds.countryCode' => strtoupper($country ?: 'ES'),
                ], [__CLASS__, 'non_empty']);

            case 'google_ads':
                $domains = array_values(array_unique(array_merge($n['domains'], self::terms_that_look_like_domains($n['scraper_query_terms']))));
                $terms = array_values(array_filter($n['scraper_query_terms'], static function($term) { return !self::looks_like_objective($term); }));
                $targets = [];
                foreach (array_merge($domains, $terms) as $target) {
                    $target = trim((string) $target);
                    if ($target === '') { continue; }
                    $targets[] = in_array(strtolower($target), $domains, true) ? strtolower($target) : $target;
                }
                $targets = array_values(array_unique($targets));
                $max_domain_matches = max(1, min(10, count($domains) ?: 2));
                return array_filter([
                    'searchTargets' => $targets,
                    'domains' => $domains,
                    'text' => $terms[0] ?? ($domains[0] ?? ($targets[0] ?? '')),
                    'region' => strtoupper($country ?: 'ES'),
                    'geoTargetRegion' => function_exists('ves_google_ads_region_from_country') ? ves_google_ads_region_from_country($country ?: 'ES') : strtoupper($country ?: 'ES'),
                    'resultsPerQuery' => max(1, min(50, $limit)),
                    'maxAdvertiserAccounts' => 2,
                    'maxDomainMatches' => $max_domain_matches,
                    'maxItems' => $limit,
                    'targetPlatform' => 'ALL',
                    'adFormatType' => 'ALL',
                    'timeRangePreset' => 'ALL_TIME',
                    'enableUrlFiltering' => !empty($domains),
                ], [__CLASS__, 'non_empty']);

            case 'google_search':
                // Sprint 1 (Part A): Google supports raw-query exclusion syntax,
                // so append safe negative terms to the query strings only.
                $gs_negatives = $n['negative_terms'] ?? [];
                return array_filter([
                    'queries' => array_map(static function ($q) use ($gs_negatives) {
                        return self::apply_google_query_exclusions($q, $gs_negatives);
                    }, $n['scraper_query_terms']),
                    'query' => self::apply_google_query_exclusions($n['scraper_query_terms'][0] ?? '', $gs_negatives),
                    'countryCode' => strtolower($country ?: 'es'),
                    'languageCode' => $language ?: 'es',
                    'maxResults' => $limit,
                ], [__CLASS__, 'non_empty']);

            case 'google_news':
                $gn_negatives = $n['negative_terms'] ?? [];
                return array_filter([
                    'query' => self::apply_google_query_exclusions($n['scraper_query_terms'][0] ?? '', $gn_negatives),
                    'queries' => array_map(static function ($q) use ($gn_negatives) {
                        return self::apply_google_query_exclusions($q, $gn_negatives);
                    }, $n['scraper_query_terms']),
                    'gl' => strtolower($country ?: 'es'),
                    'hl' => $language ?: 'es',
                    'time_period' => self::date_range_key_to_provider($n['date_range'] ?? ''),
                    'max_items' => $limit,
                ], [__CLASS__, 'non_empty']);

            case 'google_trends':
                $actor_lc = strtolower($actor);
                if (strpos($actor_lc, 'google-trends-trending-now') !== false) {
                    return array_filter([
                        'country' => strtoupper($country ?: 'ES'),
                        'timeframe' => self::trends_time_range($n['date_range'] ?? ''),
                        'maxItems' => $limit,
                    ], [__CLASS__, 'non_empty']);
                }
                if (strpos($actor_lc, 'apify/google-trends-scraper') !== false) {
                    return array_filter([
                        'searchTerms' => $n['scraper_query_terms'],
                        'isMultiple' => count($n['scraper_query_terms']) > 1,
                        'geo' => strtoupper($country ?: 'ES'),
                        'timeRange' => self::trends_time_range($n['date_range'] ?? ''),
                        'maxItems' => $limit,
                    ], [__CLASS__, 'non_empty']);
                }
                return array_filter([
                    'keyword' => $n['scraper_query_terms'][0] ?? '',
                    'geo' => strtoupper($country ?: 'ES'),
                    'timeRange' => self::trends_time_range($n['date_range'] ?? ''),
                    'maxItems' => $limit,
                ], [__CLASS__, 'non_empty']);

            case 'google_maps':
            case 'google_reviews':
                return array_filter([
                    'searchStringsArray' => $n['scraper_query_terms'],
                    'search' => $n['scraper_query_terms'][0] ?? '',
                    'startUrls' => $n['target_urls'],
                    'maxCrawledPlacesPerSearch' => $limit,
                    'language' => $language ?: 'es',
                    'countryCode' => strtolower($country ?: 'es'),
                    'reviewsLimit' => min(100, $limit),
                ], [__CLASS__, 'non_empty']);

            case 'pinterest':
                return array_filter([
                    'searchTerms' => array_values(array_unique(array_merge($n['scraper_query_terms'], array_map(static function($tag) { return ltrim((string) $tag, '#'); }, $n['hashtags'])))),
                    'startUrls' => array_values(array_filter($n['target_urls'], static function($url) { return strpos($url, 'pinterest.') !== false; })),
                    'maxItems' => $limit,
                    'sort' => self::generic_sort($n['ranking_mode'] ?? ''),
                ], [__CLASS__, 'non_empty']);
        }

        return array_filter([
            'searchTerms' => $n['scraper_query_terms'],
            'startUrls' => $n['target_urls'],
            'maxItems' => $limit,
        ], [__CLASS__, 'non_empty']);
    }

    public static function prune_payload_for_actor_contract($platform, $actor_slug, $payload) {
        $payload = is_array($payload) ? $payload : [];
        if (function_exists('ves_actor_payload_contract')) {
            $contract = ves_actor_payload_contract($platform, $actor_slug);
            $allowed = array_values(array_filter((array) ($contract['allowed_fields'] ?? [])));
            $forbidden = array_values(array_filter((array) ($contract['forbidden_fields'] ?? [])));
            foreach ($forbidden as $field) {
                unset($payload[$field]);
            }
            if (!empty($allowed)) {
                $clean = [];
                foreach ($allowed as $field) {
                    if (array_key_exists($field, $payload) && self::non_empty($payload[$field])) {
                        $clean[$field] = $payload[$field];
                    }
                }
                return $clean;
            }
        }
        if (class_exists('VES_Source_Adapter_Registry')) {
            $adapter = VES_Source_Adapter_Registry::adapter_for($platform, $actor_slug);
            $allowed = array_values(array_filter((array) ($adapter['supports_fields'] ?? [])));
            $forbidden = array_values(array_filter((array) ($adapter['forbidden_fields'] ?? [])));
            foreach ($forbidden as $field) { unset($payload[$field]); }
            if (!empty($allowed)) {
                $clean = [];
                foreach ($allowed as $field) {
                    if (array_key_exists($field, $payload) && self::non_empty($payload[$field])) {
                        $clean[$field] = $payload[$field];
                    }
                }
                return $clean;
            }
        }
        return array_filter($payload, [__CLASS__, 'non_empty']);
    }

    public static function build_ai_context($module, $platform, $request) {
        $n = self::normalize_request_fields($module, $platform, $request);
        return [
            'module' => $n['module'],
            'platform' => $n['platform'],
            'provider_terms' => $n['scraper_query_terms'],
            'provider_hashtags' => in_array($n['platform'], ['reddit','facebook_ads','google_ads'], true) ? [] : $n['hashtags'],
            'seed_keywords' => $n['seed_keywords'],
            'business_objective' => $n['business_objective'],
            'search_context' => $n['search_context'],
            'ai_analysis_goal' => $n['ai_analysis_goal'],
            'ranking_mode' => $n['ranking_mode'],
            'evidence_policy' => 'Every finding must cite current source evidence IDs, metrics, or an explicit limitation. If evidence is weak, produce a validation plan instead of campaign-ready claims.',
        ];
    }

    public static function provider_error_public_message($error_code, $message = '') {
        $code = strtolower((string) $error_code);
        $message_l = strtolower((string) $message);
        if ($code === 'ves_semrush_domain_required') {
            return __('Añade al menos un dominio válido, por ejemplo mahou.es o https://mahou.es.', 'ves');
        }
        if ($code === 'ves_semrush_seed_keyword_required') {
            return __('Introduce una keyword semilla corta, por ejemplo cerveza mahou, seo tools o herramientas seo.', 'ves');
        }
        if ($code === 'ves_linkedin_objective_not_configured') {
            return __('LinkedIn provider for this objective is not configured yet. Ask admin to configure or approve a compatible actor.', 'ves');
        }
        if ($code === 'ves_tiktok_provider_input_required') {
            return __('Add TikTok query terms, hashtags, profile URLs, or video URLs before running. Business objective and context are AI-only and are not sent as provider keywords.', 'ves');
        }
        if (strpos($code, 'permission') !== false || strpos($message_l, 'approval') !== false || strpos($message_l, 'permission') !== false) {
            return __('This actor requires Apify approval before use.', 'ves');
        }
        if (strpos($code, 'invalid') !== false || strpos($message_l, 'one of') !== false || strpos($message_l, 'required') !== false || strpos($message_l, 'contract') !== false) {
            return __('This source is not configured correctly. Admin diagnostics has details.', 'ves');
        }
        if (strpos($code, 'budget') !== false || strpos($message_l, 'budget') !== false || strpos($message_l, 'maximum cost') !== false) {
            return __('This run exceeded the configured provider budget.', 'ves');
        }
        return __('No usable results matched your filters.', 'ves');
    }

    private static function extract_subreddits($request) {
        $request = is_array($request) ? $request : [];
        $raw = self::first_non_empty($request, ['subreddits','customSubreddits','custom_subreddits','redditSubreddits','communities']);
        $out = [];
        foreach (self::split_lines($raw) as $line) {
            $line = trim((string) $line);
            $line = preg_replace('~^https?://(?:www\.)?reddit\.com/r/([^/]+).*~i', '$1', $line);
            $line = preg_replace('/^r\//i', '', $line);
            $line = preg_replace('/[^A-Za-z0-9_]+/', '', $line);
            if ($line !== '') { $out[] = $line; }
        }
        return array_slice(array_values(array_unique($out)), 0, 30);
    }

    private static function extract_domains($request) {
        $request = is_array($request) ? $request : [];
        $raw = self::first_non_empty($request, ['domains','domain','competitorDomains','competitor_domains','advertiserDomain','targetDomain']);
        $lines = self::split_lines($raw);
        $query_raw = self::first_non_empty($request, ['queryTerms','query_terms','targets','searchTargets']);
        $lines = array_merge($lines, self::split_lines($query_raw));
        foreach (self::extract_target_urls($request) as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) { $lines[] = $host; }
        }
        $out = [];
        foreach ($lines as $line) {
            $line = strtolower(trim((string) $line));
            $line = preg_replace('~^https?://~i', '', $line);
            $line = preg_replace('~/.*$~', '', $line);
            $line = preg_replace('/^www\./', '', $line);
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $line)) { $out[] = $line; }
        }
        return array_slice(array_values(array_unique($out)), 0, 30);
    }

    private static function extract_handles($request) {
        $request = is_array($request) ? $request : [];
        $raw = self::first_non_empty($request, ['handles','handle','twitterHandles','xHandles','profileUrls','profile_urls']);
        $out = [];
        foreach (self::split_lines($raw) as $line) {
            $line = trim((string) $line);
            if (preg_match('~/(?:@)?([A-Za-z0-9_\.]{2,})/?$~', $line, $m)) { $line = $m[1]; }
            $line = ltrim($line, '@');
            if (preg_match('/^[A-Za-z0-9_\.]{2,}$/', $line)) { $out[] = $line; }
        }
        return array_slice(array_values(array_unique($out)), 0, 50);
    }

    private static function bool_flag($request, $keys) {
        $request = is_array($request) ? $request : [];
        foreach ((array) $keys as $key) {
            if (array_key_exists($key, $request)) {
                $value = $request[$key];
                if (is_bool($value)) { return $value; }
                return in_array(strtolower((string) $value), ['1','true','yes','on'], true);
            }
        }
        return false;
    }

    private static function country_code($request) {
        $raw_value = self::first_non_empty($request, ['country','market','region','geoTargetRegion']);
        if (function_exists('ves_sanitize_country_code')) {
            $safe = ves_sanitize_country_code($raw_value);
            return ($safe !== '' && $safe !== 'None') ? $safe : '';
        }
        $raw_norm = function_exists('remove_accents') ? remove_accents((string) $raw_value) : (string) $raw_value;
        $raw = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $raw_norm));
        if ($raw === '' || $raw === 'NONE' || $raw === 'ANY' || $raw === 'CUALQUIERPAIS' || $raw === 'WORLDWIDE') { return ''; }
        if ($raw === 'SPAIN' || $raw === 'ESPANA') { return 'ES'; }
        if ($raw === 'UNITEDSTATES' || $raw === 'USA') { return 'US'; }
        return preg_match('/^[A-Z]{2}$/', $raw) ? $raw : '';
    }

    private static function language_code($request) {
        $raw_value = self::first_non_empty($request, ['language','lang','contentLanguage']);
        if (function_exists('ves_sanitize_language_code')) {
            return ves_sanitize_language_code($raw_value);
        }
        $raw_norm = function_exists('remove_accents') ? remove_accents((string) $raw_value) : (string) $raw_value;
        $raw = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $raw_norm));
        if ($raw === '' || $raw === 'any' || $raw === 'cualquieridioma') { return ''; }
        if ($raw === 'spanish' || $raw === 'espanol') { return 'es'; }
        if ($raw === 'english') { return 'en'; }
        return preg_match('/^[a-z]{2}$/', $raw) ? $raw : '';
    }

    private static function date_range_key($request) {
        $raw = sanitize_key((string) self::first_non_empty($request, ['trendRange','trendTimeRange','dateRange','date_range','time_range','datePreset','publishedRange','timeRange','redditTime','duration']));
        return $raw ?: 'last_30_days';
    }

    private static function reddit_sort($ranking_mode, $mode = '') {
        $ranking_mode = sanitize_key((string) $ranking_mode);
        $mode = sanitize_key((string) $mode);
        if ($ranking_mode === 'newest') { return 'new'; }
        if ($ranking_mode === 'most_commented') { return 'comments'; }
        if ($ranking_mode === 'highest_engagement') { return 'top'; }
        if ($mode === 'questions' || $mode === 'pain_points' || $mode === 'objections') { return 'relevance'; }
        return 'relevance';
    }

    private static function reddit_time($date_range, $unused = null) {
        $range = sanitize_key((string) $date_range);
        if (in_array($range, ['day','week','month','year','all'], true)) { return $range; }
        if ($range === '24h' || $range === '1d' || strpos($range, '24') !== false) { return 'day'; }
        if (strpos($range, '7') !== false) { return 'week'; }
        if (strpos($range, '365') !== false || strpos($range, 'year') !== false || strpos($range, '12') !== false) { return 'year'; }
        if (strpos($range, '90') !== false || strpos($range, '3') !== false || strpos($range, '30') !== false) { return 'month'; }
        return 'month';
    }

    private static function instagram_sort($ranking_mode) {
        $ranking_mode = sanitize_key((string) $ranking_mode);
        if ($ranking_mode === 'newest') { return 'latest'; }
        if ($ranking_mode === 'highest_engagement' || $ranking_mode === 'most_commented') { return 'top'; }
        return 'top';
    }

    private static function tiktok_sort($ranking_mode) {
        $ranking_mode = sanitize_key((string) $ranking_mode);
        if ($ranking_mode === 'newest') { return 'LATEST'; }
        if ($ranking_mode === 'highest_engagement' || $ranking_mode === 'most_commented') { return 'LIKES'; }
        return 'RELEVANCE';
    }

    private static function youtube_sort($ranking_mode) {
        $ranking_mode = sanitize_key((string) $ranking_mode);
        if ($ranking_mode === 'newest') { return 'date'; }
        if ($ranking_mode === 'highest_engagement') { return 'viewCount'; }
        return 'relevance';
    }

    private static function twitter_sort($ranking_mode) {
        $ranking_mode = sanitize_key((string) $ranking_mode);
        if ($ranking_mode === 'newest') { return 'Latest'; }
        if ($ranking_mode === 'highest_engagement' || $ranking_mode === 'most_commented') { return 'Top'; }
        return 'Top';
    }

    private static function generic_sort($ranking_mode) {
        $ranking_mode = sanitize_key((string) $ranking_mode);
        if ($ranking_mode === 'newest') { return 'new'; }
        if ($ranking_mode === 'highest_engagement') { return 'top'; }
        if ($ranking_mode === 'most_commented') { return 'comments'; }
        if ($ranking_mode === 'opportunity_score' || $ranking_mode === 'business_opportunity') { return 'opportunity'; }
        return 'relevance';
    }

    private static function date_range_key_to_provider($range) {
        $range = sanitize_key((string) $range);
        if ($range === '24h' || $range === '1d' || strpos($range, '24') !== false) { return 'last_day'; }
        if (strpos($range, '7') !== false) { return 'last_week'; }
        if (strpos($range, '90') !== false || strpos($range, '3') !== false) { return 'last_3_months'; }
        if (strpos($range, '365') !== false || strpos($range, 'year') !== false || strpos($range, '12') !== false) { return 'last_year'; }
        if (strpos($range, '6') !== false) { return 'last_6_months'; }
        return 'last_30_days';
    }

    private static function trends_time_range($range) {
        $range = sanitize_key((string) $range);
        if ($range === '24h' || $range === '1d' || strpos($range, '24') !== false) { return 'now 1-d'; }
        if (strpos($range, '7') !== false) { return 'now 7-d'; }
        if (strpos($range, '90') !== false || strpos($range, '3') !== false) { return 'today 3-m'; }
        if (strpos($range, '365') !== false || strpos($range, 'year') !== false || strpos($range, '12') !== false) { return 'today 12-m'; }
        return 'today 1-m';
    }

    private static function published_after($range) {
        $range = sanitize_key((string) $range);
        $days = 30;
        if ($range === '24h' || $range === '1d' || strpos($range, '24') !== false) { $days = 1; }
        elseif (strpos($range, '7') !== false) { $days = 7; }
        elseif (strpos($range, '90') !== false || strpos($range, '3') !== false) { $days = 90; }
        elseif (strpos($range, '365') !== false || strpos($range, 'year') !== false || strpos($range, '12') !== false) { $days = 365; }
        return gmdate('Y-m-d\TH:i:s\Z', time() - ($days * DAY_IN_SECONDS));
    }

    private static function terms_that_look_like_domains($terms) {
        $out = [];
        foreach ((array) $terms as $term) {
            $term = strtolower(trim((string) $term));
            $term = preg_replace('~^https?://~i', '', $term);
            $term = preg_replace('~/.*$~', '', $term);
            $term = preg_replace('/^www\./', '', $term);
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $term)) { $out[] = $term; }
        }
        return array_values(array_unique($out));
    }

    private static function meta_ads_urls($domains, $page_urls, $terms, $country) {
        $raw = [];
        foreach ((array) $page_urls as $url) {
            $url = esc_url_raw((string) $url);
            if ($url !== '') { $raw[] = $url; }
        }
        foreach ((array) $domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '' || strpos($domain, 'example.') !== false) { continue; }
            $raw[] = 'https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country=' . rawurlencode($country ?: 'ES') . '&q=' . rawurlencode($domain);
        }
        if (empty($raw)) {
            foreach ((array) $terms as $term) {
                $term = self::short_term_or_empty($term, 6, 80);
                if ($term === '' || stripos($term, 'example') !== false) { continue; }
                $raw[] = 'https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country=' . rawurlencode($country ?: 'ES') . '&q=' . rawurlencode($term);
            }
        }
        $raw = array_slice(array_values(array_unique($raw)), 0, 30);
        return array_map(static function($url) { return ['url' => $url]; }, $raw);
    }

    private static function non_empty($value) {
        return !($value === '' || $value === [] || $value === null || $value === false);
    }
}
