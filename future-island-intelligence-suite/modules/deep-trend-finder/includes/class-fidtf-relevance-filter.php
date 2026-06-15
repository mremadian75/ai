<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Relevance_Filter {
    public static function score(array $normalized, array $request, array $source_plan = []): array {
        $keywords = self::keywords_from_request($request, $source_plan);
        $core_keywords = self::core_keywords_from_request($request);
        $expansion_keywords = self::expansion_keywords_from_source_plan($source_plan, $keywords, $core_keywords);
        $haystack = self::item_text($normalized);
        $matched_core = [];
        $matched_expansion = [];
        $excluded = self::matched_exclusions($haystack, (array) ($request['exclude_terms'] ?? []));
        $early_quality = FIDTF_Normalizer::evidence_quality($normalized);
        if (!empty($early_quality['provider_malformed_empty_item'])) {
            return [
                'score' => 0.0,
                'matched_keywords' => [],
                'matched_core_keywords' => [],
                'matched_expansion_keywords' => [],
                'reason' => 'Provider returned a malformed/empty social item with no usable caption, metrics, date, author or hashtag evidence.',
                'evidence_quality' => $early_quality,
                'is_relevant' => false,
                'relevance_tier' => 'noise',
                'recommended_use' => 'hide',
                'signal_type' => 'malformed provider row',
                'evidence_tier' => 'noise',
                'marketing_allowed_use' => 'excluded',
                'client_safe' => false,
                'risk_note' => 'Excluded because the provider row did not contain enough usable evidence.',
                'view_count_missing' => false,
                'below_min_views' => false,
            ];
        }

        if (!empty($excluded)) {
            return [
                'score' => 0.0,
                'matched_keywords' => [],
                'reason' => 'Excluded by request terms: ' . implode(', ', array_slice($excluded, 0, 4)),
                'evidence_quality' => FIDTF_Normalizer::evidence_quality($normalized),
                'is_relevant' => false,
                'relevance_tier' => 'noise',
                'recommended_use' => 'hide',
                'signal_type' => 'excluded signal',
                'evidence_tier' => 'noise',
                'marketing_allowed_use' => 'excluded',
                'client_safe' => false,
                'risk_note' => 'Excluded by the operator request.',
            ];
        }
        if (sanitize_key((string) ($normalized['source_key'] ?? '')) === 'tiktok') {
            $min_views = class_exists('FIDTF_Provider_TikTok') ? FIDTF_Provider_TikTok::minimum_view_count() : 20000;
            $metrics = (array) ($normalized['metrics'] ?? []);
            $view_count_missing = !array_key_exists('views', $metrics) || $metrics['views'] === null || $metrics['views'] === '';
            if ($view_count_missing) {
                $normalized['_view_count_missing'] = true;
            } elseif ($min_views > 0) {
                $views = (float) ($metrics['views'] ?? 0);
                if ($views < $min_views) {
                    return [
                        'score' => 0.0,
                        'matched_keywords' => [],
                        'reason' => 'Below TikTok minimum view threshold: ' . (int) $views . ' < ' . (int) $min_views,
                        'evidence_quality' => FIDTF_Normalizer::evidence_quality($normalized),
                        'is_relevant' => false,
                        'relevance_tier' => 'noise',
                        'recommended_use' => 'hide',
                        'signal_type' => 'low-view TikTok signal',
                        'evidence_tier' => 'noise',
                        'marketing_allowed_use' => 'excluded',
                        'client_safe' => false,
                        'risk_note' => 'Below configured TikTok view threshold.',
                        'view_count_missing' => false,
                        'below_min_views' => true,
                    ];
                }
            }
        }

        foreach ($core_keywords as $keyword) {
            if ($keyword !== '' && self::contains($haystack, $keyword)) { $matched_core[] = $keyword; }
        }
        foreach ($expansion_keywords as $keyword) {
            if ($keyword !== '' && self::contains($haystack, $keyword)) { $matched_expansion[] = $keyword; }
        }
        $matched_core = array_values(array_unique($matched_core));
        // v0.3.46 marketing-readiness: when a brand/company focus exists,
        // generic category/platform terms (beer, cerveza, fyp, viral, etc.)
        // are not allowed to turn a row into direct brand evidence. They remain
        // useful as adjacent creative context, but not as claim support.
        $has_brand_focus = self::has_brand_focus($request);
        if ($has_brand_focus && !empty($matched_core)) {
            $strong_core = [];
            $generic_core = [];
            foreach ($matched_core as $term) {
                if (self::is_low_value_or_generic_term((string) $term)) { $generic_core[] = $term; }
                else { $strong_core[] = $term; }
            }
            if (empty($strong_core) && !empty($generic_core)) {
                $matched_expansion = array_values(array_unique(array_merge($matched_expansion, $generic_core)));
                $matched_core = [];
            } else {
                $matched_core = array_values(array_unique($strong_core));
                $matched_expansion = array_values(array_unique(array_merge($matched_expansion, $generic_core)));
            }
        }
        $phrase_gate = self::phrase_gate($haystack, $core_keywords);
        $matched_expansion = array_values(array_unique(array_diff($matched_expansion, $matched_core)));
        $matched = array_values(array_unique(array_merge($matched_core, $matched_expansion)));

        $core_score = min(56.0, count($matched_core) * 18.0);
        $expansion_score = min(28.0, count($matched_expansion) * 7.0);
        $provenance_score = self::provider_query_score((string) ($normalized['provider_query'] ?? ''), $keywords, $core_keywords);
        $quality = FIDTF_Normalizer::evidence_quality($normalized);
        $quality_score = ((float) ($quality['score'] ?? 0)) * 0.22;
        $metrics_score = self::metrics_score((array) ($normalized['metrics'] ?? []));
        $market_score = self::market_score($haystack, (string) ($request['market'] ?? ''), (array) ($source_plan['market_hints'] ?? []));
        $freshness_score = self::freshness_score((string) ($normalized['published_at'] ?? ''));
        $score = min(100.0, $core_score + $expansion_score + $provenance_score + $quality_score + $metrics_score + $market_score + $freshness_score);

        // v0.3.26: when a specific brand/query was entered, broad planner-expanded
        // category terms must not flood the report. They remain available as
        // adjacent signals only when they have enough supporting quality/metrics.
        $has_core_intent = !empty($core_keywords);
        $has_core_match = !empty($matched_core);
        $generic_only = !$has_core_match && !empty($matched_expansion) && self::matches_only_generic_expansion($matched_expansion);
        if ($has_core_intent && !empty($phrase_gate['strict_cap'])) {
            $score = min($score, 46.0);
        }
        if ($has_core_intent && !$has_core_match) {
            if ($generic_only) {
                $score = min($score, 34.0);
            } elseif (sanitize_key((string) ($normalized['source_key'] ?? '')) === 'reddit' && empty($matched_expansion) && $provenance_score > 0) {
                // v0.3.37 live Reddit QA: provider query provenance means the
                // actor used the requested search term, not that the returned
                // row itself is semantically relevant. High-engagement adjacent
                // Reddit/news rows must not become showable evidence only
                // because the provider query matched.
                $score = min($score, 44.0);
            } else {
                $score = min($score, 58.0);
                if (($metrics_score + $quality_score + $freshness_score) < 22.0) { $score = min($score, 42.0); }
            }
        }

        if ($has_core_intent && !empty($phrase_gate['strict_cap']) && !$has_core_match) { $score = min($score, 44.0); }
        $source_key = sanitize_key((string) ($normalized['source_key'] ?? ''));
        $acronym_gate = self::acronym_context_gate($haystack, $core_keywords, $source_key);
        if (!empty($acronym_gate['strict_cap'])) {
            $score = min($score, (float) ($acronym_gate['cap'] ?? 34.0));
        }
        $intent_gate = self::marketing_intent_context_gate($haystack, $core_keywords, $source_key);
        if (!empty($intent_gate['strict_cap'])) {
            $score = min($score, (float) ($intent_gate['cap'] ?? 46.0));
        }
        $market_gate = self::source_market_gate($normalized, (string) ($request['market'] ?? ''), $source_key);
        if (!empty($market_gate['strict_cap'])) {
            $score = min($score, (float) ($market_gate['cap'] ?? 34.0));
        }
        $language_gate = self::language_gate($normalized, (string) ($request['language'] ?? ''), $source_key);
        if (!empty($language_gate['strict_cap'])) {
            $score = min($score, (float) ($language_gate['cap'] ?? 42.0));
        }
        // v0.3.51 live QA: Mahou is an ambiguous entity token in social data
        // (Spanish beer brand vs Japanese/anime "mahou"/magic contexts). Do
        // not let a bare word match, anime hashtag, or foreign festival context
        // become claim-ready brand evidence for a Spain/Mahou run.
        $entity_gate = self::entity_context_gate($haystack, $core_keywords, $request, $source_key);
        if (!empty($entity_gate['strict_cap'])) {
            $score = min($score, (float) ($entity_gate['cap'] ?? 30.0));
        }
        $text_market_gate = self::text_market_drift_gate($haystack, (string) ($request['market'] ?? ''), $source_key);
        if (!empty($text_market_gate['strict_cap'])) {
            $score = min($score, (float) ($text_market_gate['cap'] ?? 30.0));
        }

        $tier = self::tier($score, $has_core_match, !empty($matched_expansion));
        $recommended_use = self::recommended_use($score, $normalized['source_key'] ?? '', $has_core_intent, $has_core_match, $generic_only);
        $signal_type = self::signal_type($normalized, $request, $source_plan, $has_core_match, !empty($matched_expansion));
        $reason = self::reason($matched_core, $matched_expansion, $quality, $metrics_score, $market_score, $freshness_score, $provenance_score, $has_core_intent, $has_core_match, $generic_only);
        if (!empty($phrase_gate['strict_cap'])) { $reason .= ' Multi-word request only weakly matched; capped as context.'; }
        if (!empty($acronym_gate['strict_cap'])) { $reason .= ' ' . (string) ($acronym_gate['reason'] ?? 'Acronym context was too weak; capped as context.'); }
        if (!empty($intent_gate['strict_cap'])) { $reason .= ' ' . (string) ($intent_gate['reason'] ?? 'Marketing intent context was too weak; capped as adjacent/weak evidence.'); }
        if (!empty($market_gate['strict_cap'])) { $reason .= ' ' . (string) ($market_gate['reason'] ?? 'Source country does not match the requested market; capped as non-market evidence.'); }
        if (!empty($language_gate['strict_cap'])) { $reason .= ' ' . (string) ($language_gate['reason'] ?? 'Source language does not match the requested language; capped as weak evidence.'); }
        if (!empty($entity_gate['strict_cap'])) { $reason .= ' ' . (string) ($entity_gate['reason'] ?? 'Entity context was ambiguous; capped as non-brand evidence.'); }
        if (!empty($text_market_gate['strict_cap'])) { $reason .= ' ' . (string) ($text_market_gate['reason'] ?? 'Text-level market evidence does not match the requested market; capped as non-market evidence.'); }
        return [
            'score' => round($score, 2),
            'matched_keywords' => $matched,
            'matched_core_keywords' => $matched_core,
            'matched_expansion_keywords' => $matched_expansion,
            'reason' => $reason,
            'evidence_quality' => $quality,
            'is_relevant' => $recommended_use !== 'hide',
            'relevance_tier' => $tier,
            'recommended_use' => $recommended_use,
            'signal_type' => $signal_type,
            'evidence_tier' => self::evidence_tier($score, $tier, $recommended_use, $source_key, $has_core_match, !empty($matched_expansion), $quality),
            'marketing_allowed_use' => self::marketing_allowed_use($score, $tier, $recommended_use, $source_key, $has_core_match, !empty($matched_expansion)),
            'client_safe' => self::client_safe($score, $tier, $recommended_use, $source_key),
            'risk_note' => self::risk_note($source_key, $tier, $recommended_use, $has_core_match),
            'view_count_missing' => !empty($normalized['_view_count_missing']),
            'below_min_views' => false,
        ];
    }

    public static function filter_items(array $items, array $request, array $source_plan = []): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $score = self::score($item, $request, $source_plan);
            $item['relevance_score'] = $score['score'];
            $item['relevance_tier'] = $score['relevance_tier'];
            $item['recommended_use'] = $score['recommended_use'];
            $item['signal_type'] = $score['signal_type'];
            $item['evidence_tier'] = sanitize_key((string) ($score['evidence_tier'] ?? 'weak'));
            $item['marketing_allowed_use'] = sanitize_key((string) ($score['marketing_allowed_use'] ?? 'background_only'));
            $item['client_safe'] = !empty($score['client_safe']);
            $item['risk_note'] = sanitize_text_field((string) ($score['risk_note'] ?? 'Review before using in a client-facing brief.'));
            $item['relevance_reason'] = $score['reason'];
            $item['evidence_quality'] = $score['evidence_quality'];
            $item['matched_keywords'] = $score['matched_keywords'];
            $item['matched_core_keywords'] = $score['matched_core_keywords'] ?? [];
            $item['matched_expansion_keywords'] = $score['matched_expansion_keywords'] ?? [];
            $item['is_relevant'] = $score['is_relevant'];
            $item['view_count_missing'] = !empty($score['view_count_missing']);
            $item['below_min_views'] = !empty($score['below_min_views']);
            $out[] = $item;
        }
        usort($out, static function($a, $b) {
            return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        });
        return $out;
    }

    public static function summarize_view_diagnostics(array $scored_items): array {
        $missing = 0;
        $below = 0;
        $passed = 0;
        foreach ($scored_items as $item) {
            if (!empty($item['view_count_missing'])) { $missing++; }
            if (!empty($item['below_min_views'])) { $below++; }
            if (empty($item['below_min_views']) && empty($item['view_count_missing'])) { $passed++; }
        }
        return [
            'view_count_missing' => $missing,
            'below_min_views' => $below,
            'view_gate_passed' => $passed,
        ];
    }

    private static function keywords_from_request(array $request, array $source_plan): array {
        $values = [];
        foreach (['keywords', 'queries', 'hashtags'] as $key) {
            foreach ((array) ($request[$key] ?? []) as $item) { $values[] = $item; }
            foreach ((array) ($source_plan[$key] ?? []) as $item) { $values[] = $item; }
        }
        foreach (['user_brief', 'objective'] as $key) {
            $text = (string) ($request[$key] ?? '');
            foreach (preg_split('/[,;
]+/', $text) as $part) {
                $part = trim($part);
                if ($part !== '' && str_word_count($part) <= 5) { $values[] = $part; }
            }
        }
        return self::clean_keyword_list($values, 40);
    }



    private static function phrase_gate(string $haystack, array $core_keywords): array {
        foreach ($core_keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '' || str_word_count($keyword) < 2) { continue; }
            if (self::contains($haystack, $keyword)) { continue; }
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', self::safe_lower($keyword)) ?: [];
            $tokens = array_values(array_filter(array_unique($tokens), static function($token) {
                return is_string($token) && strlen($token) >= 3 && !in_array($token, ['the','and','for','with','from','this','that','una','uno','para','con','por','del','las','los'], true);
            }));
            if (count($tokens) < 2) { continue; }
            $matched = 0;
            foreach ($tokens as $token) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($token, '/') . '(?![\p{L}\p{N}])/u', $haystack)) { $matched++; }
            }
            $coverage = $matched / max(1, count($tokens));
            if ($coverage < 0.75) {
                return ['strict_cap' => true, 'keyword' => $keyword, 'coverage' => $coverage];
            }
        }
        return ['strict_cap' => false];
    }

    private static function provider_query_score(string $provider_query, array $keywords, array $core_keywords = []): float {
        $provider_query = trim($provider_query);
        if ($provider_query === '') { return 0.0; }
        $score = 0.0;
        foreach ($core_keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '' && self::contains($provider_query, $keyword)) { $score += 7.0; }
        }
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '' && self::contains($provider_query, $keyword)) { $score += 3.0; }
        }
        return min(12.0, $score);
    }

    private static function matched_exclusions(string $haystack, array $exclude_terms): array {
        $matched = [];
        foreach ($exclude_terms as $term) {
            $term = trim(wp_strip_all_tags((string) $term));
            if ($term !== '' && self::contains($haystack, $term)) { $matched[] = sanitize_text_field($term); }
        }
        return array_values(array_unique($matched));
    }

    private static function item_text(array $item): string {
        $source_key = sanitize_key((string) ($item['source_key'] ?? $item['source'] ?? ''));
        // v0.3.43 live QA: source/publisher/community names can create false
        // positives (for example Google News source "SEO/BirdLife" for a query
        // about SEO marketing). Use creator/source names for display, not as the
        // primary semantic evidence text for news/community sources.
        // v0.3.49 live QA: social author handles can be false positives
        // (e.g. @ice.beer50 matching a beer query while the post text is only #CapCut).
        // Keep author names for display only, not semantic evidence matching.
        $author_part = in_array($source_key, ['tiktok', 'instagram', 'reddit', 'google_news'], true) ? '' : (string) ($item['author'] ?? '');
        $parts = [
            $item['title'] ?? '',
            $item['caption_or_text'] ?? '',
            $item['text'] ?? '',
            $item['caption'] ?? '',
            $author_part,
            implode(' ', (array) ($item['comments_sample'] ?? [])),
            implode(' ', (array) ($item['hashtags'] ?? [])),
        ];
        return self::safe_lower(wp_strip_all_tags(implode(' ', $parts)));
    }

    private static function marketing_intent_context_gate(string $haystack, array $core_keywords, string $source_key): array {
        if (!in_array($source_key, ['reddit', 'google_news'], true)) {
            return ['strict_cap' => false];
        }
        $intent_terms = [];
        foreach ($core_keywords as $keyword) {
            $keyword = self::safe_lower(trim((string) $keyword));
            if ($keyword !== '') { $intent_terms[] = $keyword; }
        }
        $intent_blob = implode(' ', $intent_terms);
        $marketing_intent = false;
        foreach (['marketing','brand strategy','branding','campaign','advertising','ads','seo','social media','content strategy','growth','funnel','positioning','lead generation'] as $needle) {
            if (strpos($intent_blob, $needle) !== false) { $marketing_intent = true; break; }
        }
        if (!$marketing_intent) {
            return ['strict_cap' => false];
        }
        // v0.3.44 live Reddit QA: rows can contain the literal words "brand" and
        // "strategy" while the actual context is celebrity gossip / entertainment.
        // Require business/marketing vocabulary outside the bare search terms before
        // treating Reddit or News as direct strategic evidence.
        $context_terms = [
            'marketing', 'marketer', 'campaign', 'advertising', 'ads', 'agency', 'client', 'customer', 'audience',
            'consumer', 'market research', 'positioning', 'brand positioning', 'brand strategy', 'growth', 'sales',
            'lead generation', 'funnel', 'conversion', 'creative', 'brief', 'media buying', 'social media', 'seo',
            'search engine', 'organic traffic', 'content strategy', 'website', 'business growth', 'go to market',
            'gtm', 'producto', 'cliente', 'clientes', 'campaña', 'publicidad', 'posicionamiento', 'marca',
            'marketing digital', 'tráfico orgánico', 'conversiones'
        ];
        foreach ($context_terms as $term) {
            if (self::contains($haystack, $term)) {
                return ['strict_cap' => false];
            }
        }
        return [
            'strict_cap' => true,
            'cap' => 46.0,
            'reason' => 'Marketing/brand-strategy query matched without enough business or marketing context; capped to avoid celebrity/news/community drift.'
        ];
    }

    private static function entity_context_gate(string $haystack, array $core_keywords, array $request, string $source_key): array {
        $source_key = sanitize_key($source_key);
        if (!in_array($source_key, ['tiktok','instagram','reddit'], true)) { return ['strict_cap' => false]; }

        $core = array_map(static fn($v) => strtolower(trim((string) $v)), $core_keywords);
        $brand = self::safe_lower(trim((string) ($request['brand'] ?? $request['company'] ?? '')));
        $has_mahou_focus = in_array('mahou', $core, true) || $brand === 'mahou' || strpos($brand, 'mahou') !== false;
        if (!$has_mahou_focus || !self::contains($haystack, 'mahou')) { return ['strict_cap' => false]; }

        $brand_context_terms = [
            'cerveza', 'cervezas', 'beer', 'beers', 'birra', 'bier', 'mahou san miguel', 'san miguel',
            'cinco estrellas', '5 estrellas', 'cincoestrella', 'san isidro', 'madrid', 'madrile', 'españa', 'espana', 'spanien',
            'reserva', 'clásica', 'clasica', 'rubia', 'sin alcohol', '0,0', '0.0', 'botella', 'botellin', 'botellín',
            'caña', 'cana', 'bar', 'terraza', 'hosteler', 'clavel', 'castiza', 'tapas', 'gastronom', 'pulpo', 'bodega', 'bodegas'
        ];
        foreach ($brand_context_terms as $term) {
            if ($term !== '' && self::contains($haystack, $term)) { return ['strict_cap' => false]; }
        }

        $collision_terms = [
            'anime', 'manga', 'otaku', 'mahou shoujo', 'mahoushoujo', 'mahou shojo', 'magical girl', 'magicalgirl',
            'madoka', 'tsukaikata', 'machigatta', 'roshutsu', 'boyfriend', 'dcxnime', 'wofsq', 'truyen', 'truyenhay',
            'animetiktok', 'animefyp', 'animescene', 'fate', 'series', 'magicaldestroyers', 'manosaba', 'xuhuong'
        ];
        foreach ($collision_terms as $term) {
            if ($term !== '' && self::contains($haystack, $term)) {
                return [
                    'strict_cap' => true,
                    'cap' => 30.0,
                    'reason' => 'The word Mahou appears in an anime/magic or non-beer context without Mahou beer/Madrid/category context; excluded from brand evidence.',
                ];
            }
        }

        // A bare Mahou match in social data is too ambiguous for claim support.
        // Keep it out of evidence cards unless the row also carries beer, Madrid,
        // San Isidro, product, or owned-brand context.
        return [
            'strict_cap' => true,
            'cap' => 46.0,
            'reason' => 'Bare Mahou mention lacks beer/Madrid/San Isidro/product context; capped to avoid entity-collision false positives.',
        ];
    }

    private static function text_market_drift_gate(string $haystack, string $market, string $source_key): array {
        $source_key = sanitize_key($source_key);
        if (!in_array($source_key, ['tiktok','instagram','reddit'], true)) { return ['strict_cap' => false]; }
        $market = self::safe_lower(trim($market));
        if ($market === '' || !preg_match('/\b(spain|españa|espana|madrid)\b/u', $market)) { return ['strict_cap' => false]; }

        $spain_terms = ['spain','españa','espana','madrid','barcelona','valencia','sevilla','bilbao','mallorca','palma','san isidro','madrile'];
        foreach ($spain_terms as $term) {
            if (self::contains($haystack, $term)) { return ['strict_cap' => false]; }
        }

        $foreign_terms = [
            'ecuador','ecuadorec','guayaquil','bénin','benin','francefr','nigeria','nigerian','nigeriantiktok','tanzania',
            'tanzaniatiktok','congo','libya','zambia','southafrica','cote d ivoire','côte d ivoire','ivory coast','guinee',
            'guyana','gy','guayaquil', 'ec', 'ci', 'vn', 'tr', 'mx'
        ];
        foreach ($foreign_terms as $term) {
            if ($term !== '' && self::contains($haystack, $term)) {
                return [
                    'strict_cap' => true,
                    'cap' => 30.0,
                    'reason' => 'The row contains explicit non-Spain market/context terms and no Spain/Madrid/San Isidro context; excluded from Spain strategy.',
                ];
            }
        }
        return ['strict_cap' => false];
    }

    private static function acronym_context_gate(string $haystack, array $core_keywords, string $source_key): array {
        $core = array_map(static fn($v) => strtolower(trim((string) $v)), $core_keywords);
        if (!in_array('seo', $core, true)) {
            return ['strict_cap' => false];
        }
        // SEO is a high-risk acronym in live Google News data because it can be a
        // publisher/entity name rather than search-engine optimization intent.
        // Require at least one search/marketing context term outside the raw acronym.
        if (!in_array($source_key, ['google_news', 'reddit'], true)) {
            return ['strict_cap' => false];
        }
        $context_terms = [
            'search engine', 'search engines', 'google', 'ranking', 'rankings', 'organic', 'keyword', 'keywords', 'serp',
            'backlink', 'backlinks', 'technical seo', 'content marketing', 'website', 'web traffic', 'optimization',
            'optimisation', 'posicionamiento', 'buscador', 'buscadores', 'busqueda', 'búsqueda', 'trafico organico',
            'tráfico orgánico', 'marketing digital', 'analytics', 'semrush', 'ahrefs', 'search console'
        ];
        foreach ($context_terms as $term) {
            if (self::contains($haystack, $term)) {
                return ['strict_cap' => false];
            }
        }
        return [
            'strict_cap' => true,
            'cap' => 32.0,
            'reason' => 'SEO acronym matched without search/marketing context; capped to avoid publisher/entity false positives.'
        ];
    }

    private static function contains(string $haystack, string $needle): bool {
        $haystack = self::safe_lower(wp_strip_all_tags($haystack));
        $needle = self::safe_lower(trim(wp_strip_all_tags($needle)));
        if ($needle === '') { return false; }
        $needle = preg_replace('/\s+/u', ' ', $needle);
        $haystack = preg_replace('/\s+/u', ' ', $haystack);
        if ($needle === null || $haystack === null || $needle === '') { return false; }

        // v0.3.49 live QA: avoid substring false positives in social search
        // ("san" inside unrelated words, "beer" inside handles, "mahou"-like fragments).
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $needle) ?: [];
        $tokens = array_values(array_filter($tokens, static function($token) { return is_string($token) && $token !== ''; }));
        if (count($tokens) <= 1) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u';
            return (bool) preg_match($pattern, $haystack);
        }

        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u';
        return (bool) preg_match($pattern, $haystack);
    }


    private static function safe_lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function metrics_score(array $metrics): float {
        $views = (float) ($metrics['views'] ?? 0);
        $likes = (float) ($metrics['likes'] ?? 0);
        $comments = (float) ($metrics['comments'] ?? 0);
        $shares = (float) ($metrics['shares'] ?? 0);
        $saves = (float) ($metrics['saves'] ?? 0);
        $score_metric = (float) ($metrics['score'] ?? 0);
        $trend = (float) ($metrics['trend_interest'] ?? 0);
        $score = 0.0;
        if ($views > 0) { $score += min(8.0, log10(max(10, $views)) * 1.4); }
        if ($likes > 0) { $score += min(6.0, log10(max(10, $likes)) * 1.2); }
        if ($comments > 0) { $score += min(5.0, log10(max(10, $comments)) * 1.5); }
        if ($shares > 0) { $score += min(4.0, log10(max(10, $shares)) * 1.5); }
        if ($saves > 0) { $score += min(3.0, log10(max(10, $saves)) * 1.2); }
        if ($score_metric > 0) { $score += min(5.0, log10(max(10, $score_metric)) * 1.5); }
        if ($trend > 0) { $score += min(6.0, $trend / 20.0); }
        return min(22.0, $score);
    }

    private static function market_score(string $haystack, string $market, array $hints): float {
        $needles = array_filter(array_merge([$market], $hints));
        foreach ($needles as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && self::contains($haystack, $needle)) { return 9.0; }
        }
        return 0.0;
    }

    private static function freshness_score(string $published_at): float {
        if ($published_at === '') { return 0.0; }
        $timestamp = strtotime($published_at);
        if (!$timestamp) { return 0.0; }
        $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $age_days = max(0, (time() - $timestamp) / $day_seconds);
        if ($age_days <= 14) { return 5.0; }
        if ($age_days <= 45) { return 3.0; }
        if ($age_days <= 120) { return 1.0; }
        return 0.0;
    }

    private static function threshold(string $source_key): float {
        $source_key = sanitize_key($source_key);
        $thresholds = [
            'google_trends' => 35.0,
            'google_news' => 38.0,
            'reddit' => 40.0,
            'tiktok' => 45.0,
            'instagram' => 45.0,
            'ai_research' => 45.0,
        ];
        return (float) ($thresholds[$source_key] ?? 45.0);
    }

    private static function tier(float $score, bool $has_core_match = false, bool $has_expansion_match = false): string {
        if ($has_core_match && $score >= 70) { return 'direct'; }
        if ($has_core_match && $score >= 50) { return 'direct'; }
        if ($has_expansion_match && $score >= 50) { return 'adjacent'; }
        if ($score >= 35) { return 'weak'; }
        return 'noise';
    }

    private static function recommended_use(float $score, string $source_key, bool $has_core_intent = false, bool $has_core_match = false, bool $generic_only = false): string {
        if ($generic_only) { return 'hide'; }
        if ($has_core_intent && !$has_core_match && $score < 50.0) { return 'hide'; }
        if ($score >= self::threshold($source_key)) { return 'show'; }
        if ($score >= 35) { return 'maybe'; }
        return 'hide';
    }

    private static function signal_type(array $item, array $request, array $source_plan, bool $has_core_match = false, bool $has_expansion_match = false): string {
        $source = sanitize_key((string) ($item['source_key'] ?? ''));
        $text = self::item_text($item);
        if ($source === 'google_news') { return $has_core_match ? 'news validation signal' : 'news context signal'; }
        if ($source === 'google_trends') { return $has_core_match ? 'search demand signal' : 'search context signal'; }
        if ($source === 'reddit') {
            if (preg_match('/problem|issue|hate|pain|frustrat|why|how|problema|dolor|queja/i', $text)) { return 'consumer pain'; }
            return $has_core_match ? 'direct community signal' : 'adjacent community signal';
        }
        if ($has_core_match) { return 'direct cultural signal'; }
        if ($has_expansion_match) { return 'adjacent cultural signal'; }
        if (preg_match('/format|hook|trend|template|challenge|meme|video/i', $text)) { return 'content format'; }
        if (preg_match('/competitor|brand|marca|rival/i', $text)) { return 'competitor signal'; }
        return 'cultural signal';
    }

    private static function reason(array $matched_core, array $matched_expansion, array $quality, float $metrics_score, float $market_score, float $freshness_score, float $provenance_score = 0.0, bool $has_core_intent = false, bool $has_core_match = false, bool $generic_only = false): string {
        $parts = [];
        if (!empty($matched_core)) { $parts[] = 'Direct match: ' . implode(', ', array_slice($matched_core, 0, 5)) . '.'; }
        if (!empty($matched_expansion)) { $parts[] = 'Adjacent match: ' . implode(', ', array_slice($matched_expansion, 0, 5)) . '.'; }
        if ($metrics_score > 0) { $parts[] = 'Engagement signal present.'; }
        if ($provenance_score > 0) { $parts[] = 'Matches provider discovery query.'; }
        if ($market_score > 0) { $parts[] = 'Contains selected market hint.'; }
        if ($freshness_score > 0) { $parts[] = 'Freshness signal present.'; }
        if ($has_core_intent && !$has_core_match) { $parts[] = 'No direct match to the core request; treat as adjacent context only.'; }
        if ($generic_only) { $parts[] = 'Generic category-only match; hidden from evidence by default.'; }
        if (($quality['score'] ?? 0) < 45) { $parts[] = 'Weak evidence quality.'; }
        if (empty($parts)) { $parts[] = 'Low semantic overlap with the request.'; }
        return implode(' ', $parts);
    }

    private static function core_keywords_from_request(array $request): array {
        $values = [];
        foreach (['keywords', 'hashtags', 'brand', 'company', 'competitors'] as $key) {
            $raw = $request[$key] ?? [];
            if (!is_array($raw)) { $raw = preg_split('/[,;\n]+/', (string) $raw) ?: []; }
            foreach ((array) $raw as $item) { $values[] = $item; }
        }
        return self::clean_keyword_list($values, 30);
    }

    private static function expansion_keywords_from_source_plan(array $source_plan, array $all_keywords, array $core_keywords): array {
        $values = [];
        foreach (['queries', 'hashtags', 'seed_keywords', 'related_query_hints', 'creator_or_format_hints', 'profile_or_topic_hints', 'publisher_or_topic_hints'] as $key) {
            foreach ((array) ($source_plan[$key] ?? []) as $item) { $values[] = $item; }
        }
        foreach ($all_keywords as $item) { $values[] = $item; }
        $clean = self::clean_keyword_list($values, 50);
        return array_values(array_diff($clean, $core_keywords));
    }

    private static function clean_keyword_list(array $values, int $limit): array {
        $out = [];
        foreach ($values as $value) {
            $value = trim(wp_strip_all_tags((string) $value));
            $value = ltrim($value, '#');
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($value !== '' && $length >= 2 && !self::is_artifact_term($value)) { $out[] = sanitize_text_field($value); }
            if (count($out) >= $limit) { break; }
        }
        return array_values(array_unique($out));
    }

    private static function matches_only_generic_expansion(array $matched_expansion): bool {
        if (empty($matched_expansion)) { return false; }
        $generic = ['beer','cerveza','beverage','beverages','drink','drinks','bebida','bebidas','food','recipe','recipes','mocktail','refreshment','refreshments','summer','viral','trend','trends','content','fyp','foryou','for you','fy','parati','explore','reels','shorts','tiktok','instagram'];
        foreach ($matched_expansion as $term) {
            $t = self::safe_lower(trim((string) $term));
            if (!in_array($t, $generic, true)) { return false; }
        }
        return true;
    }


    private static function has_brand_focus(array $request): bool {
        foreach (['brand', 'company'] as $key) {
            if (trim((string) ($request[$key] ?? '')) !== '') { return true; }
        }
        $competitors = $request['competitors'] ?? [];
        if (!is_array($competitors)) { $competitors = preg_split('/[,;
]+/', (string) $competitors) ?: []; }
        foreach ($competitors as $competitor) {
            if (trim((string) $competitor) !== '') { return true; }
        }
        return false;
    }

    private static function array_get(array $data, string $path) {
        $current = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) { return null; }
            $current = $current[$part];
        }
        return $current;
    }

    private static function source_market_gate(array $normalized, string $market, string $source_key): array {
        $source_key = sanitize_key($source_key);
        if (!in_array($source_key, ['tiktok','instagram','reddit'], true)) { return ['strict_cap' => false]; }
        $market = self::safe_lower(trim($market));
        if ($market === '') { return ['strict_cap' => false]; }
        $country = self::safe_lower(trim((string) ($normalized['source_country'] ?? '')));
        if ($country === '' && isset($normalized['raw']) && is_array($normalized['raw'])) {
            $country = self::safe_lower(trim((string) self::array_get((array) $normalized['raw'], 'locationCreated')));
            if ($country === '') { $country = self::safe_lower(trim((string) self::array_get((array) $normalized['raw'], 'locationMeta.countryCode'))); }
            if ($country === '') { $country = self::safe_lower(trim((string) self::array_get((array) $normalized['raw'], 'countryCode'))); }
        }
        if ($country === '' || $country === 'un' || $country === 'unknown') { return ['strict_cap' => false]; }
        $expected = self::market_country_aliases($market);
        if (empty($expected)) { return ['strict_cap' => false]; }
        if (!in_array($country, $expected, true)) {
            return [
                'strict_cap' => true,
                'cap' => 34.0,
                'reason' => 'Source country (' . strtoupper($country) . ') does not match requested market (' . $market . '); hidden from strategy by default.',
            ];
        }
        return ['strict_cap' => false];
    }

    private static function market_country_aliases(string $market): array {
        $m = self::safe_lower($market);
        if (preg_match('/\b(spain|españa|espana|madrid|barcelona|valencia|sevilla|bilbao)\b/u', $m)) { return ['es','spain','españa','espana']; }
        if (preg_match('/\b(mexico|méxico|mx)\b/u', $m)) { return ['mx','mexico','méxico']; }
        if (preg_match('/\b(united states|usa|us|america)\b/u', $m)) { return ['us','usa','united states']; }
        if (preg_match('/\b(france|francia|fr)\b/u', $m)) { return ['fr','france','francia']; }
        if (preg_match('/\b(italy|italia|it)\b/u', $m)) { return ['it','italy','italia']; }
        if (preg_match('/\b(germany|alemania|de)\b/u', $m)) { return ['de','germany','alemania']; }
        if (preg_match('/\b(united kingdom|uk|gb|britain|reino unido)\b/u', $m)) { return ['gb','uk','united kingdom','reino unido']; }
        return [];
    }

    private static function language_gate(array $normalized, string $requested_language, string $source_key): array {
        $source_key = sanitize_key($source_key);
        if (!in_array($source_key, ['tiktok','instagram','reddit'], true)) { return ['strict_cap' => false]; }
        $requested = sanitize_key($requested_language);
        if ($requested === '') { return ['strict_cap' => false]; }
        $lang = sanitize_key((string) ($normalized['language'] ?? ''));
        if ($lang === '' && isset($normalized['raw']) && is_array($normalized['raw'])) {
            $lang = sanitize_key((string) self::array_get((array) $normalized['raw'], 'textLanguage'));
        }
        if ($lang === '' || $lang === 'un' || $lang === 'und' || $lang === 'unknown') { return ['strict_cap' => false]; }
        $allowed = [$requested];
        if ($requested === 'es') { $allowed[] = 'es-419'; }
        if (!in_array($lang, $allowed, true)) {
            return [
                'strict_cap' => true,
                'cap' => 42.0,
                'reason' => 'Source language (' . $lang . ') does not match requested language (' . $requested . '); capped as weak evidence.',
            ];
        }
        return ['strict_cap' => false];
    }

    private static function is_artifact_term(string $term): bool {
        $t = self::safe_lower(trim(ltrim(wp_strip_all_tags($term), '#')));
        $t = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $t);
        if ($t === '') { return true; }
        $artifacts = ['amp','nbsp','https','http','www','com','co','es','html','redd','redditcom','utm','ref','src','href','img','video','post','undefined','null'];
        return in_array($t, $artifacts, true);
    }

    private static function is_platform_noise_term(string $term): bool {
        $t = self::safe_lower(trim(ltrim(wp_strip_all_tags($term), '#')));
        $t = str_replace(['_', '-'], '', $t);
        $noise = ['fyp','foryou','foryoupage','fy','parati','viral','trending','trend','explore','reels','shorts','myvideo','tiktok','instagram'];
        return in_array($t, $noise, true);
    }

    private static function is_low_value_or_generic_term(string $term): bool {
        $t = self::safe_lower(trim(ltrim(wp_strip_all_tags($term), '#')));
        if (self::is_artifact_term($t) || self::is_platform_noise_term($t)) { return true; }
        $generic = ['beer','cerveza','beers','birra','bier','biere','beverage','beverages','drink','drinks','bebida','bebidas','food','comida','recipe','recipes','receta','recetas','summer','verano','content','post','video','humor','meme'];
        return in_array($t, $generic, true);
    }

    private static function evidence_tier(float $score, string $tier, string $recommended_use, string $source_key, bool $has_core_match, bool $has_expansion_match, array $quality): string {
        $tier = sanitize_key($tier);
        $recommended_use = sanitize_key($recommended_use);
        $quality_score = (float) ($quality['score'] ?? 0);
        if ($recommended_use === 'hide' || $tier === 'noise' || $score < 35) { return 'noise'; }
        if ($has_core_match && $score >= 72 && $quality_score >= 45) { return 'strong'; }
        if ($has_core_match && $score >= 50) { return 'support'; }
        if (in_array($source_key, ['tiktok','instagram'], true) && ($has_expansion_match || $tier === 'adjacent')) { return 'creative_only'; }
        if ($has_expansion_match && $score >= 50) { return 'support'; }
        return 'weak';
    }

    private static function marketing_allowed_use(float $score, string $tier, string $recommended_use, string $source_key, bool $has_core_match, bool $has_expansion_match): string {
        $tier = sanitize_key($tier);
        $recommended_use = sanitize_key($recommended_use);
        if ($recommended_use === 'hide' || $tier === 'noise' || $score < 35) { return 'excluded'; }
        if ($has_core_match && $score >= 70) { return 'claim_support_after_review'; }
        if ($has_core_match) { return 'directional_support'; }
        if (in_array($source_key, ['tiktok','instagram'], true)) { return 'creative_inspiration_only'; }
        if ($has_expansion_match) { return 'context_support_only'; }
        return 'background_only';
    }

    private static function client_safe(float $score, string $tier, string $recommended_use, string $source_key): bool {
        if (sanitize_key($recommended_use) !== 'show') { return false; }
        if (sanitize_key($tier) === 'weak' || sanitize_key($tier) === 'noise') { return false; }
        return $score >= 50.0;
    }

    private static function risk_note(string $source_key, string $tier, string $recommended_use, bool $has_core_match): string {
        if (sanitize_key($recommended_use) === 'hide') { return 'Hidden from strategy by default.'; }
        if (!$has_core_match && in_array($source_key, ['tiktok','instagram'], true)) { return 'Use as hook or format inspiration only; not demand proof.'; }
        if ($source_key === 'reddit') { return 'Community discourse is not representative market opinion without repeated fit.'; }
        if ($source_key === 'google_trends') { return 'Directional demand movement only; needs explanation from other sources.'; }
        if ($source_key === 'google_news') { return 'Media framing only; not consumer intent by itself.'; }
        if (sanitize_key($tier) === 'direct') { return 'Review source context before using as claim support.'; }
        return 'Use only after human review.';
    }

}
