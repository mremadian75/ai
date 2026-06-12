<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Controlled, source-aware query expansion for Trend Finder.
 * Provider queries are intentionally short; business goals remain AI context only.
 */
final class VES_Trend_Query_Expander {
    private static function clean_term($term, $max_words = 7, $max_len = 90) {
        $term = function_exists('wp_unslash') ? wp_unslash($term) : $term;
        $term = trim(preg_replace('/\s+/u', ' ', function_exists('wp_strip_all_tags') ? wp_strip_all_tags((string) $term) : strip_tags((string) $term)));
        $term = preg_replace('/["“”`]+/u', '', $term);
        if ($term === '') { return ''; }
        $words = preg_split('/\s+/u', $term);
        if (count($words) > $max_words) { $words = array_slice($words, 0, $max_words); }
        $term = implode(' ', $words);
        if (function_exists('mb_substr')) { $term = mb_substr($term, 0, $max_len, 'UTF-8'); }
        else { $term = substr($term, 0, $max_len); }
        return function_exists('sanitize_text_field') ? sanitize_text_field(trim($term)) : trim(strip_tags($term));
    }

    private static function lower($value) {
        $value = (string) $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function uniq($items, $limit = 12) {
        $out = [];
        $seen = [];
        foreach ((array) $items as $item) {
            $item = self::clean_term($item, 8, 100);
            if ($item === '') { continue; }
            $key = self::lower($item);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    private static function hashtag($term) {
        $term = trim((string) $term);
        $term = preg_replace('/[^\p{L}\p{N}]+/u', '', $term);
        if ($term === '') { return ''; }
        return '#' . (function_exists('sanitize_text_field') ? sanitize_text_field($term) : $term);
    }

    private static function is_spanish($language, $market) {
        $language = self::lower($language);
        $market = self::lower($market);
        return in_array($language, ['es', 'es-es', 'spanish', 'espanol', 'español'], true) || strpos($market, 'spain') !== false || strpos($market, 'espa') !== false;
    }

    private static function topic_pack($topic_lc, $spanish) {
        $packs = [
            'ai' => [
                'match' => ['ai marketing','ia marketing','inteligencia artificial','artificial intelligence'],
                'es' => ['marketing con inteligencia artificial','IA aplicada al marketing','automatizacion marketing','herramientas IA marketing'],
                'en' => ['ai marketing','artificial intelligence marketing','marketing automation','ai marketing tools'],
                'hashtags' => ['#aimarketing','#marketingia','#inteligenciaartificial','#automatizacionmarketing'],
                'negative' => ['crypto','trading','token','casino','adult','job spam'],
            ],
            'beer' => [
                'match' => ['beer','cerveza','mahou','budweiser','estrella'],
                'es' => ['cerveza','cerveza artesanal','cerveza en españa','marcas de cerveza'],
                'en' => ['beer','beer brands','craft beer','beer marketing'],
                'hashtags' => ['#cerveza','#beer','#cervezaartesanal','#beerlovers'],
                'negative' => ['homebrew equipment','alcohol abuse','casino','crypto'],
            ],
            'pharmacy' => [
                'match' => ['pharmacy','wellness','farmacia','bienestar','healthcare'],
                'es' => ['farmacia','bienestar','salud preventiva','parafarmacia'],
                'en' => ['pharmacy','wellness','preventive health','health retail'],
                'hashtags' => ['#farmacia','#bienestar','#salud','#wellness'],
                'negative' => ['prescription sale','illegal drugs','casino','adult'],
            ],
            'ev' => [
                'match' => ['electric car','electric cars','ev','coches electricos','coches eléctricos','vehiculo electrico','vehículo eléctrico'],
                'es' => ['coches electricos','vehiculos electricos','coche electrico españa','carga coche electrico'],
                'en' => ['electric cars','EV market','electric vehicle charging','EV adoption'],
                'hashtags' => ['#cocheselectricos','#movilidadelectrica','#ev','#electriccars'],
                'negative' => ['crypto','stock price','job spam'],
            ],
            'saas' => [
                'match' => ['saas','software','tools','herramientas','seo tools','creative testing'],
                'es' => ['herramientas SaaS','software para marketing','herramientas SEO','testing creativo'],
                'en' => ['SaaS tools','marketing software','SEO tools','creative testing'],
                'hashtags' => ['#saas','#marketingtools','#seotools','#growth'],
                'negative' => ['nulled','crack','pirated','casino'],
            ],
            'food' => [
                'match' => ['food','restaurant','comida','restaurante','alimentacion','alimentación'],
                'es' => ['marcas de comida','restaurantes','tendencias gastronomicas','delivery comida'],
                'en' => ['food brands','restaurant trends','food marketing','delivery food'],
                'hashtags' => ['#food','#comida','#gastronomia','#foodmarketing'],
                'negative' => ['diet pills','adult','casino'],
            ],
            'tourism' => [
                'match' => ['tourism','travel','turismo','viajes','hotel'],
                'es' => ['turismo','viajes','turismo en españa','hoteles'],
                'en' => ['tourism','travel trends','destination marketing','hotels'],
                'hashtags' => ['#turismo','#viajes','#travel','#destinationmarketing'],
                'negative' => ['visa scam','casino','adult'],
            ],
            'education' => [
                'match' => ['education','school','university','educacion','educación','formacion','formación'],
                'es' => ['educacion','formacion online','universidades','edtech'],
                'en' => ['education','online learning','edtech','university marketing'],
                'hashtags' => ['#educacion','#edtech','#formacion','#onlinelearning'],
                'negative' => ['essay spam','degree mill','casino'],
            ],
        ];
        foreach ($packs as $pack) {
            foreach ($pack['match'] as $needle) {
                if (strpos($topic_lc, self::lower($needle)) !== false) {
                    return [
                        'terms' => $spanish ? $pack['es'] : $pack['en'],
                        'hashtags' => $pack['hashtags'],
                        'negative' => $pack['negative'],
                    ];
                }
            }
        }
        return ['terms' => [], 'hashtags' => [], 'negative' => ['crypto','trading','token','casino','adult','job spam']];
    }

    public static function expand($request) {
        $request = is_array($request) ? $request : [];
        $topic = self::clean_term($request['topic'] ?? $request['category'] ?? $request['query'] ?? '', 6, 80);
        $market = self::clean_term($request['market'] ?? $request['country'] ?? '', 3, 40);
        $language = (string) ($request['language'] ?? '');
        $business_goal = self::clean_term($request['business_goal'] ?? $request['trendReason'] ?? $request['reason'] ?? '', 14, 160);
        $topic_lc = self::lower($topic);
        $market_lc = self::lower($market);
        $spanish = self::is_spanish($language, $market);
        $pack = self::topic_pack($topic_lc, $spanish);

        $seed_terms = array_values(array_filter(array_merge([$topic], $pack['terms'])));
        $local_terms = [];
        if ($topic !== '' && $market !== '' && $market_lc !== 'none') {
            $local_terms[] = trim($topic . ' ' . $market);
            if (strpos($market_lc, 'spain') !== false || strpos($market_lc, 'espa') !== false || strtoupper($market) === 'ES') {
                $local_terms[] = trim($topic . ' España');
                $local_terms[] = trim($topic . ' Madrid');
                if ($spanish && strpos($topic_lc, 'ia') === false && strpos($topic_lc, 'marketing') !== false) {
                    $local_terms[] = trim($topic . ' mercado español');
                }
            }
        }

        $question_terms = [];
        if ($topic !== '') {
            if ($spanish) {
                $question_terms = [
                    'como usar ' . $topic,
                    'mejores herramientas ' . $topic,
                    $topic . ' ejemplos',
                    $topic . ' precio',
                    $topic . ' opiniones',
                ];
            } else {
                $question_terms = [
                    'how to use ' . $topic,
                    'best ' . $topic . ' tools',
                    $topic . ' examples',
                    $topic . ' pricing',
                    $topic . ' reviews',
                ];
            }
        }

        $platform_terms = array_values(array_filter(array_merge(
            [$topic],
            array_slice($pack['terms'], 0, 4),
            $spanish ? ['tendencias ' . $topic, $topic . ' casos reales', $topic . ' problemas'] : ['trending ' . $topic, $topic . ' use cases', $topic . ' pain points']
        )));
        $news_terms = array_values(array_filter(array_merge(
            $local_terms,
            $spanish ? ['noticias ' . $topic, $topic . ' mercado', $topic . ' lanzamiento'] : ['news ' . $topic, $topic . ' market', $topic . ' launch']
        )));
        $competitor_terms = [];
        if ($topic !== '') {
            $competitor_terms[] = $spanish ? 'competidores ' . $topic : $topic . ' competitors';
            $competitor_terms[] = $spanish ? 'alternativas ' . $topic : $topic . ' alternatives';
        }

        $hashtags = $pack['hashtags'];
        if ($topic !== '') {
            $hashtags[] = self::hashtag($topic);
            $hashtags[] = self::hashtag(str_replace(' ', '', $topic));
        }
        $language_variants = [];
        if ($spanish && $topic !== '') {
            $language_variants = [$topic, str_replace(' AI ', ' IA ', ' ' . $topic . ' '), str_replace('marketing', 'mercadotecnia', $topic)];
        } elseif ($topic !== '') {
            $language_variants = [$topic];
        }

        return [
            'seed_terms' => self::uniq($seed_terms, 8),
            'local_terms' => self::uniq($local_terms, 8),
            'question_terms' => self::uniq($question_terms, 8),
            'hashtag_candidates' => self::uniq($hashtags, 10),
            'platform_terms' => self::uniq($platform_terms, 10),
            'news_terms' => self::uniq($news_terms, 8),
            'competitor_terms' => self::uniq($competitor_terms, 8),
            'negative_terms' => self::uniq(array_merge($pack['negative'], ['crypto','trading','token','casino','adult','job spam']), 14),
            'language_variants' => self::uniq($language_variants, 8),
            'expansion_context' => [
                'business_goal_used_for_context_only' => $business_goal !== '',
                'language_mode' => $spanish ? 'spanish' : 'default',
            ],
        ];
    }
}
