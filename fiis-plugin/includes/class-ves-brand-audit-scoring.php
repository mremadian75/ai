<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Brand_Audit_Scoring {
    public static function confidence_mode($summary, $context = []) {
        $gate = self::confidence_gate($summary, $context);
        return (string) ($gate['level'] ?? 'technical_only');
    }

    public static function confidence_gate($summary, $context = []) {
        $summary = is_array($summary) ? $summary : [];
        $context = is_array($context) ? $context : [];
        $usable = max(0, (int) ($summary['usable_sources'] ?? 0));
        $partial = max(0, (int) ($summary['partial_sources'] ?? 0));
        $failed = max(0, (int) ($summary['failed_sources'] ?? 0));
        $total = max(1, (int) ($summary['total_sources'] ?? ($usable + $partial + $failed)));
        $usable_ratio = $total > 0 ? ($usable / $total) : 0;
        $family_count = count((array) ($summary['source_family_coverage'] ?? []));
        $ai_success = !empty($context['ai_success']);
        $social_items = max(0, (int) ($summary['social_item_count'] ?? 0));
        $competitor_total = max(0, (int) ($summary['competitor_total'] ?? 0));
        $competitor_missing = max(0, (int) ($summary['competitor_partial'] ?? 0)) + max(0, (int) ($summary['competitor_failed'] ?? 0));
        $reasons = [];
        $blocks = [];
        $score = 0;
        if ($total <= 0 || $usable <= 0) {
            $level = 'technical_only';
            $reasons[] = 'No usable evidence sources were available.';
        } elseif ($usable_ratio >= 0.82 && $family_count >= 4 && $ai_success) {
            $level = 'validated';
            $score = 90;
        } elseif ($usable_ratio >= 0.62 && $family_count >= 3) {
            $level = 'directional';
            $score = 72;
        } elseif ($usable_ratio >= 0.35 || $usable >= 2) {
            $level = 'exploratory';
            $score = 52;
        } else {
            $level = 'limited';
            $score = 32;
        }
        if ($usable_ratio < 0.50 && !in_array($level, ['limited','technical_only'], true)) {
            $level = 'exploratory';
            $score = min($score ?: 52, 52);
            $reasons[] = 'Usable sources are below 50% of planned sources.';
        }
        if (!$ai_success && !in_array($level, ['limited','technical_only'], true)) {
            $level = 'exploratory';
            $score = min($score ?: 52, 52);
            $reasons[] = 'AI strategic layer was unavailable, so strategic confidence is capped.';
        }
        if (($failed + $partial) > $usable) {
            $score = min($score ?: 50, 50);
            if (!in_array($level, ['limited','technical_only'], true)) { $level = 'exploratory'; }
            $reasons[] = 'Partial and failed sources outnumber usable sources.';
        }
        if ($competitor_total > 0 && $competitor_missing >= max(1, (int) ceil($competitor_total * 0.50))) {
            $blocks[] = 'competitor_recommendations_blocked';
            $reasons[] = 'Competitor coverage is mostly partial or failed.';
        }
        if ($social_items < 20) {
            $blocks[] = 'social_strategy_blocked';
            $reasons[] = 'Social evidence is below the minimum sample size for content strategy conclusions.';
        }
        if (!empty($summary['only_internal_or_search_evidence'])) {
            $blocks[] = 'content_strategy_blocked';
            $reasons[] = 'Only internal/search evidence is available; social/content strategy conclusions are blocked.';
        }
        if ($level === 'technical_only') { $score = 15; }
        if ($level === 'limited' && $score <= 0) { $score = 30; }
        return [
            'level' => $level,
            'score' => round(max(0, min(100, $score)), 2),
            'usable_ratio' => round($usable_ratio, 4),
            'reasons' => array_values(array_unique($reasons)),
            'blocks' => array_values(array_unique($blocks)),
            'allowed_levels' => ['validated', 'directional', 'exploratory', 'limited', 'technical_only'],
        ];
    }

    public static function source_family($source_key, $source = []) {
        $key = sanitize_key((string) $source_key);
        $type = sanitize_key((string) ($source['source_type'] ?? ''));
        if ($key === 'brand_profile') { return 'workspace'; }
        if ($key === 'website_crawler') { return 'owned_web'; }
        if (strpos($key, 'google_search') !== false) { return 'search'; }
        if (strpos($key, 'google_news') !== false) { return 'public_web'; }
        if (strpos($key, 'google_trends') !== false) { return 'trends'; }
        if (strpos($key, 'maps') !== false) { return 'reviews_maps'; }
        if (strpos($key, 'competitor') !== false) { return 'competitors'; }
        if ($type === 'social_apify' || strpos($key, 'instagram') !== false || strpos($key, 'tiktok') !== false || strpos($key, 'youtube') !== false || strpos($key, 'twitter') !== false || strpos($key, 'facebook') !== false || strpos($key, 'linkedin') !== false || strpos($key, 'reddit') !== false) { return 'social'; }
        return $type !== '' ? $type : 'other';
    }

    public static function source_status_label($status) {
        $status = strtoupper((string) $status);
        if ($status === 'SUCCEEDED') { return 'usable'; }
        if (in_array($status, ['PARTIAL', 'CONFIG_ERROR', 'SKIPPED_INVALID_INPUT', 'WAITING_RETRY'], true)) { return 'partial'; }
        if (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'DISPATCH_ERROR'], true)) { return 'failed'; }
        if (in_array($status, ['QUEUED', 'RUNNING'], true)) { return 'running'; }
        return 'partial';
    }

    public static function build_source_quality($sources, $summary = []) {
        $families = [];
        $cards = [];
        foreach ((array) $sources as $key => $source) {
            if (!is_array($source)) { continue; }
            $family = self::source_family($key, $source);
            if (!isset($families[$family])) {
                $families[$family] = ['total' => 0, 'usable' => 0, 'partial' => 0, 'failed' => 0, 'items' => 0];
            }
            $status = strtoupper((string) ($source['status'] ?? ''));
            $usability = sanitize_key((string) ($source['analytical_usability_status'] ?? ''));
            $label = self::source_status_label($status);
            if ($usability === 'usable') { $label = 'usable'; }
            elseif ($usability === 'limited') { $label = 'partial'; }
            elseif ($usability === 'failed') { $label = 'failed'; }
            $item_count = (int) ($source['item_count'] ?? 0);
            $families[$family]['total']++;
            $families[$family]['items'] += $item_count;
            if ($label === 'usable') { $families[$family]['usable']++; }
            elseif ($label === 'failed') { $families[$family]['failed']++; }
            else { $families[$family]['partial']++; }
            $cards[$key] = [
                'source_key' => sanitize_key((string) $key),
                'label' => sanitize_text_field((string) ($source['label'] ?? $key)),
                'family' => $family,
                'source_type' => sanitize_key((string) ($source['source_type'] ?? '')),
                'status' => $status,
                'usability' => $label,
                'dispatch_status' => sanitize_key((string) ($source['dispatch_status'] ?? '')),
                'item_count' => $item_count,
                'mode' => sanitize_key((string) ($source['mode'] ?? '')),
                'notes' => sanitize_text_field((string) ($source['notes'] ?? '')),
                'provider_status' => sanitize_text_field((string) ($source['provider_status'] ?? '')),
            ];
        }
        return [
            'summary' => is_array($summary) ? $summary : [],
            'families' => $families,
            'cards' => $cards,
            'debug_hint' => 'Source status is separated from analytical usability. Failed or partial sources should not be treated as market evidence.',
        ];
    }

    private static function lower($value) {
        $value = trim((string) $value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function text_contains_any($text, $needles) {
        $text = self::lower($text);
        foreach ((array) $needles as $needle) {
            $needle = self::lower($needle);
            if ($needle !== '' && strpos($text, $needle) !== false) { return true; }
        }
        return false;
    }

    public static function cluster_search_intent($items, $query_plan = []) {
        $clusters = [
            'review_reputation' => ['label' => 'Reviews / reputation', 'items' => [], 'signals' => 0],
            'purchase_availability' => ['label' => 'Where to buy / price / availability', 'items' => [], 'signals' => 0],
            'comparison' => ['label' => 'Comparison / alternatives', 'items' => [], 'signals' => 0],
            'product_variant' => ['label' => 'Product / variant / taste', 'items' => [], 'signals' => 0],
            'brand_story' => ['label' => 'Brand story / ownership / corporate', 'items' => [], 'signals' => 0],
            'campaign_social' => ['label' => 'Campaign / social / content', 'items' => [], 'signals' => 0],
            'employment_corporate' => ['label' => 'Employment / corporate reputation', 'items' => [], 'signals' => 0],
            'other' => ['label' => 'Other / mixed intent', 'items' => [], 'signals' => 0],
        ];
        $rules = [
            'review_reputation' => ['opinion', 'opinión', 'opiniones', 'review', 'reviews', 'reseña', 'reseñas', 'valoracion', 'valoración', 'trustpilot', 'glassdoor'],
            'purchase_availability' => ['precio', 'comprar', 'donde comprar', 'dónde comprar', 'amazon', 'tienda', 'cerca de mi', 'near me', 'delivery', 'envio', 'envío'],
            'comparison' => [' vs ', 'versus', 'mejor', 'compar', 'alternativa', 'competidor'],
            'product_variant' => ['ipa', 'clasica', 'clásica', 'reserva', 'sin alcohol', 'sabor', 'cata', 'maridaje', 'cerveza'],
            'brand_story' => ['historia', 'dueñ', 'owner', 'fundad', 'empresa', 'grupo', 'maestros', '1890'],
            'campaign_social' => ['instagram', 'tiktok', 'youtube', 'twitter', 'x ', 'campaña', 'anuncio', 'hashtag', 'social'],
            'employment_corporate' => ['trabajar', 'empleo', 'job', 'infojobs', 'glassdoor', 'salario'],
        ];
        foreach ((array) $items as $item) {
            if (!is_array($item)) { continue; }
            $text = trim(implode(' ', [(string) ($item['title'] ?? ''), (string) ($item['text'] ?? ''), (string) ($item['source'] ?? ''), (string) ($item['url'] ?? '')]));
            $cluster_key = 'other';
            foreach ($rules as $key => $needles) {
                if (self::text_contains_any($text, $needles)) { $cluster_key = $key; break; }
            }
            $clusters[$cluster_key]['signals']++;
            if (count($clusters[$cluster_key]['items']) < 6) {
                $clusters[$cluster_key]['items'][] = [
                    'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                    'source' => sanitize_text_field((string) ($item['source'] ?? '')),
                    'url' => esc_url_raw((string) ($item['url'] ?? '')),
                    'text' => sanitize_textarea_field((string) ($item['text'] ?? '')),
                ];
            }
        }
        $queries = array_values((array) ($query_plan['source_queries'] ?? []));
        foreach ($queries as $query) {
            $cluster_key = 'other';
            foreach ($rules as $key => $needles) {
                if (self::text_contains_any($query, $needles)) { $cluster_key = $key; break; }
            }
            $clusters[$cluster_key]['signals']++;
        }
        $out = [];
        foreach ($clusters as $key => $cluster) {
            if ((int) $cluster['signals'] <= 0 && empty($cluster['items'])) { continue; }
            $cluster['key'] = $key;
            $out[] = $cluster;
        }
        usort($out, static function($a, $b) { return ((int) ($b['signals'] ?? 0)) <=> ((int) ($a['signals'] ?? 0)); });
        return $out;
    }

    public static function classify_social_evidence($social_evidence, $request = []) {
        $social_evidence = is_array($social_evidence) ? $social_evidence : [];
        $brand = self::lower($request['brand_name'] ?? '');
        $handles = [];
        foreach (['instagram_url', 'tiktok_url', 'youtube_url', 'twitter_url', 'facebook_url', 'linkedin_url'] as $key) {
            $url = (string) ($request[$key] ?? '');
            if ($url !== '') {
                $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
                $host = trim((string) wp_parse_url($url, PHP_URL_HOST));
                if ($path !== '') { $handles[] = self::lower(preg_replace('/[^a-z0-9_.-]+/i', ' ', $path)); }
                if ($host !== '') { $handles[] = self::lower($host); }
            }
        }
        $owned = [];
        $earned = [];
        $competitor_social = [];
        $paid = [];
        $giveaway = [];
        $organic = [];
        $territory_counts = [];
        $platform_quality = [];
        $classified_posts = [];
        $posts = is_array($social_evidence['posts'] ?? null) ? $social_evidence['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) { continue; }
            $source = self::lower($post['source'] ?? '');
            $title = (string) ($post['title'] ?? '');
            $text = (string) ($post['text'] ?? '');
            $all_text = self::lower($source . ' ' . $title . ' ' . $text . ' ' . (string) ($post['url'] ?? ''));
            $is_owned = false;
            if ($brand !== '' && $source !== '' && strpos($source, $brand) !== false) { $is_owned = true; }
            foreach ($handles as $handle) {
                if ($handle !== '' && ($source !== '' && strpos($source, $handle) !== false || strpos($all_text, $handle) !== false)) { $is_owned = true; break; }
            }
            $is_paid = self::text_contains_any($all_text, ['ad ', 'ads ', 'sponsored', 'promoted', 'paid partnership', 'publicidad', 'patrocinado', '#ad', '#publi', 'branded content']);
            $is_giveaway = self::text_contains_any($all_text, ['giveaway', 'sorteo', 'concurso', 'win ', 'gana ', 'regalo', 'premio', 'participa']);
            $territories = self::social_territories($all_text);
            foreach ($territories as $territory) { $territory_counts[$territory] = ($territory_counts[$territory] ?? 0) + 1; }
            $platform = sanitize_key((string) ($post['platform'] ?? 'social'));
            $entity_role = sanitize_key((string) ($post['entity_role'] ?? 'brand'));
            if (!isset($platform_quality[$platform])) {
                $platform_quality[$platform] = ['platform' => $platform, 'items' => 0, 'with_metrics' => 0, 'owned' => 0, 'earned' => 0, 'paid_flagged' => 0, 'giveaway_flagged' => 0, 'quality_score' => 0, 'confidence' => 'low'];
            }
            $metrics = is_array($post['metrics'] ?? null) ? $post['metrics'] : [];
            $has_metrics = ((float) ($metrics['views'] ?? 0) + (float) ($metrics['likes'] ?? 0) + (float) ($metrics['comments'] ?? 0) + (float) ($metrics['shares'] ?? 0) + (float) ($metrics['saves'] ?? 0)) > 0;
            if ($entity_role === 'competitor') { $is_owned = false; }
            $post['ownership'] = $entity_role === 'competitor' ? 'competitor' : ($is_owned ? 'owned' : 'earned');
            $post['source_nature'] = $is_paid ? 'paid_or_sponsored_flag' : ($is_giveaway ? 'giveaway_flag' : 'organic_or_unknown');
            $post['content_flags'] = array_values(array_filter([$is_paid ? 'paid_or_sponsored' : '', $is_giveaway ? 'giveaway' : '']));
            $post['content_territories'] = $territories;
            $classified_posts[] = $post;
            if ($entity_role === 'competitor') { $competitor_social[] = $post; }
            elseif ($is_owned) { $owned[] = $post; $platform_quality[$platform]['owned']++; }
            else { $earned[] = $post; $platform_quality[$platform]['earned']++; }
            if ($is_paid) { $paid[] = $post; $platform_quality[$platform]['paid_flagged']++; }
            elseif ($is_giveaway) { $giveaway[] = $post; $platform_quality[$platform]['giveaway_flagged']++; }
            else { $organic[] = $post; }
            $platform_quality[$platform]['items']++;
            if ($has_metrics) { $platform_quality[$platform]['with_metrics']++; }
        }
        foreach ($platform_quality as $platform => $row) {
            $items = max(0, (int) $row['items']);
            $metric_ratio = $items > 0 ? ((int) $row['with_metrics'] / $items) : 0;
            $score = 0;
            if ($items >= 20) { $score += 45; }
            elseif ($items >= 10) { $score += 35; }
            elseif ($items >= 5) { $score += 25; }
            elseif ($items > 0) { $score += 12; }
            $score += (int) round($metric_ratio * 35);
            if ((int) $row['owned'] > 0 && (int) $row['earned'] > 0) { $score += 15; }
            elseif ((int) $row['owned'] > 0 || (int) $row['earned'] > 0) { $score += 8; }
            $score = min(100, max(0, $score));
            $platform_quality[$platform]['quality_score'] = $score;
            $platform_quality[$platform]['confidence'] = $score >= 70 ? 'strong' : ($score >= 45 ? 'directional' : ($score > 0 ? 'limited' : 'none'));
            $platform_quality[$platform]['metric_coverage'] = $items > 0 ? round($metric_ratio * 100, 1) : 0;
        }
        arsort($territory_counts);
        $social_evidence['posts'] = $classified_posts;
        $social_evidence['top_posts'] = array_slice($classified_posts, 0, 20);
        $social_evidence['owned_posts'] = array_slice($owned, 0, 18);
        $social_evidence['earned_posts'] = array_slice($earned, 0, 18);
        $social_evidence['organic_posts'] = array_slice($organic, 0, 18);
        $social_evidence['paid_or_sponsored_posts'] = array_slice($paid, 0, 12);
        $social_evidence['giveaway_posts'] = array_slice($giveaway, 0, 12);
        $social_evidence['competitor_social_posts'] = array_slice($competitor_social, 0, 30);
        $social_evidence['content_territories'] = array_map(static function($name, $count) { return ['territory' => $name, 'count' => $count]; }, array_keys($territory_counts), array_values($territory_counts));
        $social_evidence['quality_scores'] = array_values($platform_quality);
        $social_evidence['owned_vs_earned_summary'] = ['owned_count' => count($owned), 'earned_count' => count($earned), 'competitor_count' => count($competitor_social), 'paid_or_sponsored_count' => count($paid), 'giveaway_count' => count($giveaway), 'organic_or_unknown_count' => count($organic)];
        return $social_evidence;
    }

    private static function social_territories($text) {
        $rules = [
            'sports / football' => ['fútbol', 'football', 'soccer', 'deporte', 'sport', 'bernabeu', 'real madrid', 'atleti'],
            'heritage / origin' => ['historia', 'tradición', 'tradicion', 'maestros', '1890', 'origen', 'legacy'],
            'product / taste' => ['sabor', 'cerveza', 'ipa', 'reserva', 'clasica', 'clásica', 'cata', 'maridaje'],
            'music / events' => ['festival', 'música', 'musica', 'concierto', 'evento', 'party'],
            'local culture' => ['madrid', 'barrio', 'plaza', 'local', 'ciudad'],
            'promotion / giveaway' => ['sorteo', 'giveaway', 'concurso', 'gana', 'premio'],
            'sustainability / corporate' => ['sostenibilidad', 'sustainable', 'empleo', 'corporate', 'empresa'],
        ];
        $out = [];
        foreach ($rules as $label => $needles) {
            if (self::text_contains_any($text, $needles)) { $out[] = $label; }
        }
        return $out ?: ['uncategorized'];
    }

    public static function analyze_reviews($maps_evidence, $search_items = []) {
        $maps_evidence = is_array($maps_evidence) ? $maps_evidence : [];
        $reviews = is_array($maps_evidence['review_items'] ?? null) ? $maps_evidence['review_items'] : [];
        $places = is_array($maps_evidence['places'] ?? null) ? $maps_evidence['places'] : [];
        $positive_words = ['excelente', 'bueno', 'buena', 'calidad', 'rápido', 'rapido', 'recomiendo', 'agradable', 'perfecto', 'rica', 'rico', 'great', 'good'];
        $negative_words = ['malo', 'mala', 'vergüenza', 'verguenza', 'no contestan', 'problema', 'queja', 'tarde', 'caro', 'poor', 'bad', 'slow', 'terrible'];
        $theme_rules = [
            'customer service' => ['sac', 'atención', 'atencion', 'customer service', 'no contestan', 'teléfono', 'telefono'],
            'delivery / order' => ['pedido', 'entrega', 'delivery', 'envío', 'envio'],
            'price / value' => ['precio', 'caro', 'barato', 'oferta'],
            'taste / product quality' => ['sabor', 'calidad', 'rica', 'rico', 'cerveza'],
            'workplace / employer' => ['trabajar', 'empleado', 'glassdoor', 'infojobs'],
        ];
        $positive = [];
        $negative = [];
        $neutral = [];
        $themes = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) { continue; }
            $text = (string) ($review['text'] ?? '');
            $score = 0;
            if (self::text_contains_any($text, $positive_words)) { $score++; }
            if (self::text_contains_any($text, $negative_words)) { $score--; }
            foreach ($theme_rules as $theme => $needles) {
                if (self::text_contains_any($text, $needles)) { $themes[$theme] = ($themes[$theme] ?? 0) + 1; }
            }
            if ($score > 0) { $positive[] = $review; }
            elseif ($score < 0) { $negative[] = $review; }
            else { $neutral[] = $review; }
        }
        // Search results can reveal review intent and third-party visibility but are not direct sentiment corpus.
        $review_visibility = [];
        foreach ((array) $search_items as $item) {
            if (!is_array($item)) { continue; }
            $text = implode(' ', [(string) ($item['title'] ?? ''), (string) ($item['source'] ?? ''), (string) ($item['text'] ?? '')]);
            if (self::text_contains_any($text, ['opinion', 'opinión', 'opiniones', 'review', 'reseña', 'trustpilot', 'glassdoor', 'infojobs', 'valoracion'])) {
                $review_visibility[] = [
                    'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                    'source' => sanitize_text_field((string) ($item['source'] ?? '')),
                    'url' => esc_url_raw((string) ($item['url'] ?? '')),
                    'text' => sanitize_textarea_field((string) ($item['text'] ?? '')),
                ];
            }
        }
        arsort($themes);
        return [
            'sentiment_summary' => [
                'direct_review_count' => count($reviews),
                'places_count' => count($places),
                'positive_count' => count($positive),
                'negative_count' => count($negative),
                'neutral_or_unclear_count' => count($neutral),
                'confidence' => count($reviews) >= 20 ? 'directional' : (count($reviews) > 0 ? 'limited' : 'no_direct_review_corpus'),
            ],
            'positive_drivers' => array_slice(array_map(static function($r) { return (string) ($r['text'] ?? ''); }, $positive), 0, 6),
            'negative_drivers' => array_slice(array_map(static function($r) { return (string) ($r['text'] ?? ''); }, $negative), 0, 6),
            'complaint_themes' => array_map(static function($theme, $count) { return ['theme' => $theme, 'count' => $count]; }, array_keys($themes), array_values($themes)),
            'review_visibility_signals' => array_slice($review_visibility, 0, 10),
            'trust_signals' => count($places) ? ['Google Maps place/rating evidence collected.'] : [],
            'guardrail_note' => count($reviews) ? 'Direct review snippets are available.' : 'No direct review corpus. Review-related search results only show review intent/visibility.',
        ];
    }

    public static function build_competitor_context($items, $request = [], $query_plan = []) {
        $competitors = array_values((array) ($request['competitors'] ?? []));
        $items = is_array($items) ? $items : [];
        $groups = [];
        foreach ($competitors as $competitor) {
            $competitor = trim((string) $competitor);
            if ($competitor === '') { continue; }
            $groups[$competitor] = ['competitor' => $competitor, 'signals' => [], 'themes' => [], 'what_they_appear_to_own' => 'Limited evidence collected.', 'brand_opportunity' => 'Run deeper competitor evidence collection before making strong claims.', 'confidence' => 'limited'];
        }
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $text = implode(' ', [(string) ($item['title'] ?? ''), (string) ($item['source'] ?? ''), (string) ($item['text'] ?? '')]);
            foreach ($groups as $name => $row) {
                if (self::text_contains_any($text, [$name])) {
                    $groups[$name]['signals'][] = [
                        'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                        'source' => sanitize_text_field((string) ($item['source'] ?? '')),
                        'url' => esc_url_raw((string) ($item['url'] ?? '')),
                        'text' => sanitize_textarea_field((string) ($item['text'] ?? '')),
                    ];
                    $groups[$name]['themes'] = array_values(array_unique(array_merge($groups[$name]['themes'], self::competitor_themes($text))));
                }
            }
        }
        foreach ($groups as $name => $row) {
            $count = count($row['signals']);
            if ($count > 0) {
                $theme_label = $row['themes'] ? implode(', ', array_slice($row['themes'], 0, 3)) : 'search visibility';
                $groups[$name]['what_they_appear_to_own'] = $theme_label . ' based on ' . $count . ' collected signal(s).';
                $groups[$name]['brand_opportunity'] = 'Compare the brand against ' . $name . ' on ' . $theme_label . ' with stronger source-specific evidence before final positioning claims.';
                $groups[$name]['confidence'] = $count >= 5 ? 'directional' : 'limited';
            }
            $groups[$name]['signals'] = array_slice($groups[$name]['signals'], 0, 6);
        }
        $discovery = [];
        if (!$competitors && !empty($query_plan['competitor_queries'])) {
            $discovery[] = 'Competitor discovery queries were generated from category/industry input, but explicit competitors were not provided.';
        }
        return [
            'competitors' => $competitors,
            'competitor_queries' => array_values((array) ($query_plan['competitor_queries'] ?? [])),
            'competitor_search_items' => array_slice($items, 0, 18),
            'gap_matrix' => array_values($groups),
            'discovery_notes' => $discovery,
            'white_space' => [],
        ];
    }

    private static function competitor_themes($text) {
        $rules = [
            'price/value' => ['precio', 'barato', 'oferta', 'value'],
            'premium/quality' => ['premium', 'calidad', 'quality', 'reserva'],
            'local/culture' => ['local', 'madrid', 'galicia', 'españa', 'spanish'],
            'campaign/social' => ['campaña', 'anuncio', 'instagram', 'tiktok', 'youtube'],
            'reviews/reputation' => ['opinion', 'review', 'reseña', 'valoracion'],
        ];
        $out = [];
        foreach ($rules as $label => $needles) {
            if (self::text_contains_any($text, $needles)) { $out[] = $label; }
        }
        return $out ?: ['general visibility'];
    }
}
