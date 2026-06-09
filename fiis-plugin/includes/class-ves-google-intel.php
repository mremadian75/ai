<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Google Intelligence source family for the VES SaaS shell.
 *
 * Google Ads Transparency is intentionally excluded from this source family.
 * It belongs to the Ads Scraper page so research, search intelligence and ad
 * intelligence stay separated in the product UI and in the credit model.
 */
final class VES_Google_Intel {
    public static function modules() {
        $countries = [
            '' => 'Worldwide', 'es' => 'España', 'us' => 'Estados Unidos', 'gb' => 'Reino Unido', 'fr' => 'Francia', 'de' => 'Alemania', 'it' => 'Italia', 'pt' => 'Portugal', 'mx' => 'México', 'br' => 'Brasil', 'ar' => 'Argentina', 'cl' => 'Chile', 'co' => 'Colombia', 'nl' => 'Países Bajos', 'ca' => 'Canadá', 'au' => 'Australia', 'jp' => 'Japón', 'kr' => 'Corea del Sur', 'in' => 'India', 'ae' => 'Emiratos Árabes'
        ];
        $geo_countries = [
            '' => 'Worldwide', 'ES' => 'España', 'US' => 'Estados Unidos', 'GB' => 'Reino Unido', 'FR' => 'Francia', 'DE' => 'Alemania', 'IT' => 'Italia', 'PT' => 'Portugal', 'MX' => 'México', 'BR' => 'Brasil', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'NL' => 'Países Bajos', 'CA' => 'Canadá', 'AU' => 'Australia', 'JP' => 'Japón', 'KR' => 'Corea del Sur', 'IN' => 'India', 'AE' => 'Emiratos Árabes'
        ];
        $languages = [
            '' => 'Any language', 'es' => 'Spanish', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic'
        ];
        $lr_languages = [
            '' => 'Any result language', 'lang_es' => 'Spanish results', 'lang_en' => 'English results', 'lang_fr' => 'French results', 'lang_de' => 'German results', 'lang_it' => 'Italian results', 'lang_pt' => 'Portuguese results'
        ];
        $date_ranges = [
            '' => 'Cualquier fecha', 'd1' => 'Últimas 24 h', 'd7' => 'Últimos 7 días', 'm1' => 'Último mes', 'm3' => 'Últimos 3 meses', 'y1' => 'Último año'
        ];
        $trend_categories = [
            '' => 'Todas las categorías', '3' => 'Arte y entretenimiento', '47' => 'Automoción', '44' => 'Belleza y fitness', '22' => 'Libros', '12' => 'Negocios', '5' => 'Tecnología', '7' => 'Finanzas', '71' => 'Comida y bebida', '8' => 'Gaming', '45' => 'Salud', '65' => 'Ocio', '11' => 'Hogar', '13' => 'Internet y telecom', '958' => 'Empleo y educación', '19' => 'Gobierno y leyes', '16' => 'Noticias', '299' => 'Comunidades online', '14' => 'Sociedad', '66' => 'Mascotas', '29' => 'Inmobiliario', '533' => 'Referencia', '174' => 'Ciencia', '18' => 'Shopping', '20' => 'Deportes', '67' => 'Viajes'
        ];

        return [
            'search' => [
                'label' => 'Google Search',
                'description' => 'Organic SERP, search intent, visible competitors, People Also Ask and content signals.',
                'actor' => self::actor_slug('SEARCH', 'apify/google-search-scraper'),
                'fetch_limit' => 70,
                'fields' => [
                    ['name'=>'query','label'=>'Búsquedas','type'=>'textarea','required'=>true,'placeholder'=>"your brand keyword\ncompetitor keyword"],
                    ['name'=>'country_code','label'=>'País','type'=>'select','default'=>'es','options'=>$countries],
                    ['name'=>'search_language','label'=>'Idioma','type'=>'select','default'=>'es','options'=>$languages,'ui_visible'=>true],
                    ['name'=>'max_pages','label'=>'Páginas por búsqueda','type'=>'select','default'=>'1','options'=>['1'=>'1 page','2'=>'2 pages','3'=>'3 pages'],'ui_visible'=>false],
                    ['name'=>'quick_date_range','label'=>'Fecha','type'=>'select','default'=>'','options'=>$date_ranges],
                    ['name'=>'force_exact_match','label'=>'Exact phrase match','type'=>'checkbox','default'=>'0','ui_visible'=>false],
                ],
            ],
            'news' => [
                'label' => 'Google News',
                'description' => 'News results by keyword with country, language and time window.',
                'actor' => self::actor_slug('NEWS', 'easyapi/google-news-scraper'),
                'fetch_limit' => 60,
                'fields' => [
                    ['name'=>'query','label'=>'Búsqueda','type'=>'text','required'=>true,'placeholder'=>'your-brand market campaign'],
                    ['name'=>'gl','label'=>'País','type'=>'select','default'=>'es','options'=>$countries],
                    ['name'=>'hl','label'=>'Interface language','type'=>'select','default'=>'es','options'=>$languages,'ui_visible'=>false],
                    ['name'=>'lr','label'=>'Idioma','type'=>'select','default'=>'lang_es','options'=>$lr_languages,'ui_visible'=>true],
                    ['name'=>'time_period','label'=>'Fecha','type'=>'select','default'=>'last_month','options'=>['last_hour'=>'Last hour','last_day'=>'Last day','last_week'=>'Last week','last_month'=>'Last month','last_year'=>'Last year']],
                    ['name'=>'max_items','label'=>'Max results','type'=>'select','default'=>'30','options'=>['10'=>'10 results','20'=>'20 results','30'=>'30 results','50'=>'50 results'],'ui_visible'=>false],
                ],
            ],
            'trends' => [
                'label' => 'Google Trends',
                'description' => 'Interest over time, regions, related queries and related topics.',
                'actor' => self::actor_slug('TRENDS', 'apify/google-trends-scraper'),
                'fetch_limit' => 50,
                'fields' => [
                    ['name'=>'search_terms','label'=>'Términos','type'=>'textarea','required'=>true,'placeholder'=>"your brand\nrelated topic"],
                    ['name'=>'geo','label'=>'País','type'=>'select','default'=>'ES','options'=>$geo_countries],
                    ['name'=>'time_range','label'=>'Fecha','type'=>'select','default'=>'today 3-m','options'=>['now 1-H'=>'Última hora','now 4-H'=>'Últimas 4 horas','now 1-d'=>'Último día','now 7-d'=>'Últimos 7 días','today 1-m'=>'Último mes','today 3-m'=>'Últimos 3 meses','today 5-y'=>'Últimos 5 años','all'=>'Todo el histórico']],
                    ['name'=>'category','label'=>'Categoría','type'=>'select','default'=>'','options'=>$trend_categories],
                    ['name'=>'max_items','label'=>'Max rows','type'=>'select','default'=>'25','options'=>['10'=>'10 rows','25'=>'25 rows','50'=>'50 rows'],'ui_visible'=>false],
                ],
            ],
            'images' => [
                'label' => 'Google Images',
                'description' => 'Visual research for creative assets, competitors, visual patterns and image SEO.',
                'actor' => self::actor_slug('IMAGES', 'easyapi/google-images-scraper'),
                'fetch_limit' => 50,
                'fields' => [
                    ['name'=>'query','label'=>'Búsqueda visual','type'=>'text','required'=>true,'placeholder'=>'your-brand summer campaign'],
                    ['name'=>'gl','label'=>'País','type'=>'select','default'=>'es','options'=>$countries],
                    ['name'=>'hl','label'=>'Idioma','type'=>'select','default'=>'es','options'=>$languages,'ui_visible'=>true],
                    ['name'=>'images_color','label'=>'Color','type'=>'select','default'=>'any','options'=>['any'=>'Any','black_and_white'=>'Black & white','transparent'=>'Transparent','red'=>'Red','orange'=>'Orange','yellow'=>'Yellow','green'=>'Green','teal'=>'Teal','blue'=>'Blue','purple'=>'Purple','pink'=>'Pink','white'=>'White','gray'=>'Gray','black'=>'Black','brown'=>'Brown'],'ui_visible'=>false],
                    ['name'=>'images_size','label'=>'Size','type'=>'select','default'=>'','options'=>[''=>'Any size','large'=>'Large','medium'=>'Medium','icon'=>'Icon'],'ui_visible'=>false],
                    ['name'=>'images_type','label'=>'Type','type'=>'select','default'=>'','options'=>[''=>'Any type','clipart'=>'Clipart','line_drawing'=>'Line drawing','gif'=>'GIF'],'ui_visible'=>false],
                    ['name'=>'max_items','label'=>'Max images','type'=>'select','default'=>'24','options'=>['12'=>'12 images','24'=>'24 images','36'=>'36 images','50'=>'50 images'],'ui_visible'=>false],
                ],
            ],
            'maps' => [
                'public' => false,
                'label' => 'Google Maps',
                'description' => 'Local discovery: businesses, ratings, categories, location and distribution signals.',
                'actor' => self::actor_slug('MAPS', 'compass/crawler-google-places'),
                'fetch_limit' => 50,
                'fields' => [
                    ['name'=>'search_terms','label'=>'Búsquedas locales','type'=>'textarea','required'=>true,'placeholder'=>"bares cerveza artesanal\nrestaurantes con terraza"],
                    ['name'=>'location_query','label'=>'Ubicación','type'=>'text','required'=>true,'placeholder'=>'Madrid, Spain'],
                    ['name'=>'country_code','label'=>'País','type'=>'select','default'=>'es','options'=>$countries],
                    ['name'=>'language','label'=>'Language','type'=>'select','default'=>'es','options'=>$languages,'ui_visible'=>false],
                    ['name'=>'max_places','label'=>'Places per search','type'=>'select','default'=>'10','options'=>['5'=>'5 places','10'=>'10 places','15'=>'15 places','25'=>'25 places'],'ui_visible'=>false],
                ],
            ],
            'crawler' => [
                'public' => false,
                'label' => 'Website Content Crawler',
                'description' => 'Website crawling for text, markdown and page-level AI analysis.',
                'actor' => self::actor_slug('CRAWLER', 'apify/website-content-crawler'),
                'fetch_limit' => 50,
                'fields' => [
                    ['name'=>'start_urls','label'=>'URLs iniciales','type'=>'textarea','required'=>true,'placeholder'=>"https://example.com\nhttps://example.com/blog"],
                    ['name'=>'crawler_type','label'=>'Crawler','type'=>'select','default'=>'playwright:adaptive','options'=>['playwright:adaptive'=>'Adaptive','playwright:firefox'=>'Headless browser','cheerio'=>'Raw HTTP'],'ui_visible'=>false],
                    ['name'=>'max_crawl_pages','label'=>'Max pages','type'=>'select','default'=>'15','options'=>['5'=>'5 pages','10'=>'10 pages','15'=>'15 pages','25'=>'25 pages','50'=>'50 pages'],'ui_visible'=>false],
                    ['name'=>'max_crawl_depth','label'=>'Depth','type'=>'select','default'=>'1','options'=>['0'=>'Only start URLs','1'=>'1 level','2'=>'2 levels','3'=>'3 levels'],'ui_visible'=>false],
                    ['name'=>'use_sitemaps','label'=>'Use sitemaps','type'=>'checkbox','default'=>'0','ui_visible'=>false],
                ],
            ],
        ];
    }

    private static function actor_slug($suffix, $default) {
        foreach ([
            'VE_GOOGLE_INTEL_' . $suffix . '_SLUG',
            'VE_SOCIAL_GOOGLE_INTEL_' . $suffix . '_SLUG',
            'SAAS_GOOGLE_INTEL_' . $suffix . '_SLUG',
        ] as $const) {
            if (defined($const) && constant($const)) {
                return trim((string) constant($const));
            }
        }
        return $default;
    }

    public static function public_modules() {
        $public = [];
        foreach (self::modules() as $key => $module) {
            if (defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP && $key !== 'search') {
                continue;
            }
            if (isset($module['public']) && !$module['public']) {
                continue;
            }
            $public[$key] = [
                'key' => $key,
                'label' => $module['label'],
                'description' => $module['description'],
                'fields' => $module['fields'],
                'analysisModes' => [
                    ['key'=>'summary','label'=>'Resumen'],
                    ['key'=>'opportunities','label'=>'Oportunidades'],
                    ['key'=>'content','label'=>'Brief / contenido'],
                ],
            ];
        }
        return $public;
    }

    public static function get_module($key) {
        $key = sanitize_key((string) $key);
        $modules = self::modules();
        return $modules[$key] ?? null;
    }

    public static function build_input($module_key, $payload) {
        $module_key = sanitize_key((string) $module_key);
        $payload = is_array($payload) ? $payload : [];

        switch ($module_key) {
            case 'search':
                $queries = self::parse_lines((string) ($payload['query'] ?? ''), 'text', 10, 140);
                if (!$queries) { return new WP_Error('ves_google_missing_query', 'Introduce al menos una consulta de Google Search.'); }
                $input = [
                    'queries' => implode("\n", $queries),
                    'maxPagesPerQuery' => self::clean_int($payload['max_pages'] ?? 1, 1, 3, 1),
                    'resultsPerPage' => 10,
                    'saveHtmlToKeyValueStore' => false,
                    'includeUnfilteredResults' => false,
                    'focusOnPaidAds' => false,
                    'forceExactMatch' => !empty($payload['force_exact_match']),
                    'mobileResults' => false,
                ];
                if (!empty($payload['country_code'])) { $input['countryCode'] = strtolower(sanitize_text_field((string) $payload['country_code'])); }
                if (!empty($payload['search_language'])) {
                    $lang = sanitize_text_field((string) $payload['search_language']);
                    $input['searchLanguage'] = $lang;
                    $input['languageCode'] = $lang;
                }
                if (!empty($payload['quick_date_range'])) {
                    $input['quickDateRange'] = sanitize_text_field((string) $payload['quick_date_range']);
                } elseif (!empty($payload['after_date'])) {
                    $input['afterDate'] = sanitize_text_field((string) $payload['after_date']);
                }
                return $input;

            case 'news':
                $query = self::one_line($payload['query'] ?? '', 180);
                if ($query === '') { return new WP_Error('ves_google_missing_news_query', 'Introduce una consulta de Google News.'); }
                $input = ['query' => $query, 'maxItems' => self::clean_int($payload['max_items'] ?? 30, 1, 50, 30)];
                foreach (['gl', 'hl', 'lr', 'time_period'] as $field) {
                    if (!empty($payload[$field])) { $input[$field] = sanitize_text_field((string) $payload[$field]); }
                }
                return $input;

            case 'trends':
                $terms = self::parse_lines((string) ($payload['search_terms'] ?? ''), 'text', 5, 120);
                if (!$terms) { return new WP_Error('ves_google_missing_terms', 'Anade al menos un termino para Google Trends.'); }
                $input = ['searchTerms' => $terms, 'maxItems' => self::clean_int($payload['max_items'] ?? 25, 1, 50, 25), 'isMultiple' => false];
                if (!empty($payload['time_range'])) { $input['timeRange'] = sanitize_text_field((string) $payload['time_range']); }
                if (!empty($payload['geo'])) {
                    $geo = strtoupper(sanitize_text_field((string) $payload['geo']));
                    $input['geo'] = $geo;
                    $input['viewedFrom'] = strtolower($geo);
                }
                if (!empty($payload['category'])) { $input['category'] = sanitize_text_field((string) $payload['category']); }
                $input['skipDebugScreen'] = true;
                return $input;

            case 'images':
                $query = self::one_line($payload['query'] ?? '', 180);
                if ($query === '') { return new WP_Error('ves_google_missing_image_query', 'Introduce una consulta de Google Images.'); }
                $input = ['query' => $query, 'maxItems' => self::clean_int($payload['max_items'] ?? 24, 1, 50, 24)];
                foreach (['images_color','images_size','images_type','gl','hl'] as $field) {
                    if (isset($payload[$field]) && (string) $payload[$field] !== '') { $input[$field] = sanitize_text_field((string) $payload[$field]); }
                }
                return $input;

            case 'maps':
                $terms = self::parse_lines((string) ($payload['search_terms'] ?? ''), 'text', 5, 120);
                $location = self::one_line($payload['location_query'] ?? '', 160);
                if (!$terms || $location === '') { return new WP_Error('ves_google_missing_maps_input', 'Introduce busquedas y ubicacion para Google Maps.'); }
                $input = [
                    'searchStringsArray' => $terms,
                    'locationQuery' => $location,
                    'maxCrawledPlacesPerSearch' => self::clean_int($payload['max_places'] ?? 10, 1, 25, 10),
                    'scrapePlaceDetailPage' => !empty($payload['scrape_place_detail_page']) || !empty($payload['scrapePlaceDetailPage']),
                    'maxReviews' => self::clean_int($payload['max_reviews'] ?? ($payload['maxReviews'] ?? 0), 0, 100, 0),
                    'maxImages' => self::clean_int($payload['max_images'] ?? ($payload['maxImages'] ?? 0), 0, 20, 0),
                    'scrapeContacts' => !empty($payload['scrape_contacts']) || !empty($payload['scrapeContacts']),
                    'includeWebResults' => !empty($payload['include_web_results']) || !empty($payload['includeWebResults']),
                ];
                if (!empty($payload['language'])) { $input['language'] = sanitize_text_field((string) $payload['language']); }
                if (!empty($payload['country_code'])) { $input['countryCode'] = strtolower(sanitize_text_field((string) $payload['country_code'])); }
                return $input;

            case 'crawler':
                $urls = self::parse_lines((string) ($payload['start_urls'] ?? ''), 'url', 5, 500);
                if (!$urls) { return new WP_Error('ves_google_missing_urls', 'Introduce al menos una URL valida para el crawler.'); }
                $pages = self::clean_int($payload['max_crawl_pages'] ?? 15, 1, 50, 15);
                $depth = self::clean_int($payload['max_crawl_depth'] ?? 1, 0, 3, 1);
                return [
                    'startUrls' => self::make_start_urls($urls),
                    'crawlerType' => sanitize_text_field((string) ($payload['crawler_type'] ?? 'playwright:adaptive')),
                    'maxCrawlPages' => $pages,
                    'maxResults' => $pages,
                    'maxCrawlDepth' => $depth,
                    'useSitemaps' => !empty($payload['use_sitemaps']),
                    'useLlmsTxt' => false,
                    'saveMarkdown' => true,
                    'saveHtmlAsFile' => false,
                    'saveHtml' => false,
                    'saveFiles' => false,
                    'blockMedia' => true,
                    'respectRobotsTxtFile' => true,
                    'removeCookieWarnings' => true,
                    'maxConcurrency' => 8,
                    'maxRequestRetries' => 2,
                    'requestTimeoutSecs' => 45,
                    'proxyConfiguration' => ['useApifyProxy' => true],
                ];
        }

        return new WP_Error('ves_google_invalid_module', 'Modulo de Google Intelligence no soportado.');
    }

    public static function actor_slug_for_module($module_key) {
        $module = self::get_module($module_key);
        return $module ? trim((string) ($module['actor'] ?? '')) : '';
    }

    public static function run_actor_with_slug($actor_slug, $input, $wait_for_finish = 45) {
        $actor_slug = trim((string) $actor_slug);
        if ($actor_slug === '') { return new WP_Error('ves_google_missing_actor', 'Actor no configurado para este modulo.'); }
        if (!VES_Config::get_token()) { return new WP_Error('ves_google_missing_token', 'Falta configurar la API key de Apify.'); }
        $query = [
            'waitForFinish' => max(0, min(120, (int) $wait_for_finish)),
            'memory' => 4096,
            'build' => 'latest',
            'timeout' => 0,
        ];
        if (function_exists('ves_hard_max_charge_usd')) {
            $query['maxTotalChargeUsd'] = ves_hard_max_charge_usd();
        }
        $url = add_query_arg($query, 'https://api.apify.com/v2/acts/' . rawurlencode(str_replace('/', '~', $actor_slug)) . '/runs');
        return VES_Apify_Client::request('POST', $url, $input);
    }

    public static function run_actor($module_key, $input, $wait_for_finish = 45) {
        $module = self::get_module($module_key);
        if (!$module) { return new WP_Error('ves_google_invalid_module', 'Modulo de Google Intelligence no valido.'); }
        return self::run_actor_with_slug((string) ($module['actor'] ?? ''), $input, $wait_for_finish);
    }

    public static function fetch_response($module_key, $run_id, $wait_for_finish = 15) {
        $module = self::get_module($module_key);
        if (!$module) { return new WP_Error('ves_google_invalid_module', 'Modulo de Google Intelligence no valido.'); }
        $run = VES_Apify_Client::fetch_run($run_id, $wait_for_finish);
        if (is_wp_error($run)) { return $run; }
        $status = (string) ($run['body']['data']['status'] ?? '');
        $items = [];
        if ($status === 'SUCCEEDED') {
            $items = VES_Apify_Client::fetch_items($run_id, (int) ($module['fetch_limit'] ?? 50));
            if (is_wp_error($items)) { $items = []; }
        }
        return self::format_response($module_key, $module, $run['body'], $items);
    }

    public static function format_response($module_key, $module, $run_body, $items, $input = null) {
        $data = is_array($run_body['data'] ?? null) ? $run_body['data'] : [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $items = is_array($items) ? array_values($items) : [];
        $status = (string) ($data['status'] ?? 'UNKNOWN');
        $display_items = self::prepare_display_items($module_key, $items, 36);
        $status_message = self::status_message($data);
        if ($status === 'SUCCEEDED' && empty($display_items) && $status_message === '') {
            $status_message = self::empty_hint($module_key);
        }
        $total_charge_usd = function_exists('ves_extract_apify_cost_usd') ? ves_extract_apify_cost_usd(['data' => $data]) : (isset($usage['totalChargeUsd']) ? (float) $usage['totalChargeUsd'] : null);
        return [
            'platform' => 'google_intel',
            'module' => sanitize_key((string) $module_key),
            'module_label' => (string) ($module['label'] ?? $module_key),
            'run_id' => (string) ($data['id'] ?? ''),
            'status' => $status,
            'item_count_preview' => count($items),
            'all_items_count' => count($items),
            'items' => array_slice($items, 0, 60),
            'display_items' => $display_items,
            'analysis_items' => self::prepare_analysis_items($module_key, $items, 24),
            'analysis_ready' => !empty($display_items),
            'result_quality' => self::result_quality($status, $display_items),
            'started_at' => (string) ($data['startedAt'] ?? ''),
            'finished_at' => (string) ($data['finishedAt'] ?? ''),
            'runtime_secs' => isset($stats['runTimeSecs']) ? (float) $stats['runTimeSecs'] : null,
            'total_charge_usd' => $total_charge_usd,
            'provider_cost_usd' => $total_charge_usd,
            'actor_input' => is_array($input) ? self::redact_input($input) : null,
            'status_message' => $status_message,
        ];
    }

    private static function result_quality($status, $display_items) {
        if ($status === 'SUCCEEDED' && !empty($display_items)) { return 'usable'; }
        if ($status === 'SUCCEEDED') { return 'empty'; }
        if (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) { return 'failed'; }
        return 'pending';
    }

    private static function empty_hint($module_key) {
        switch (sanitize_key((string) $module_key)) {
            case 'search': return 'Google Search termino correctamente pero no devolvio SERP legible. Prueba una query mas corta, menos filtros o otro pais.';
            case 'news': return 'Google News no encontro resultados legibles. Prueba una ventana temporal mas amplia o una keyword menos especifica.';
            case 'trends': return 'Google Trends no devolvio widgets legibles. Prueba un termino con mas demanda, una ventana de 3 meses o Worldwide.';
            case 'images': return 'Google Images no devolvio imagenes legibles. Prueba una query visual mas amplia y sin filtros de color/tamano.';
            case 'maps': return 'Google Maps no devolvio lugares. Revisa la ubicacion y usa una busqueda local simple, por ejemplo "bares" + "Madrid, Spain".';
            case 'crawler': return 'El crawler termino sin texto legible. Revisa que las URLs sean publicas, que robots.txt permita el acceso y que el limite de paginas no sea demasiado bajo.';
        }
        return 'La ejecucion termino correctamente pero sin resultados legibles.';
    }

    private static function status_message($data) {
        $data = is_array($data) ? $data : [];
        $msg = '';
        foreach (['statusMessage','status_message','errorMessage','error_message'] as $key) {
            if (!empty($data[$key])) { $msg = (string) $data[$key]; break; }
        }
        return self::truncate($msg, 600);
    }

    public static function prepare_display_items($module_key, $items, $max = 24) {
        $items = array_values(is_array($items) ? $items : []);
        $rows = [];
        $push = function($row, $raw = null) use (&$rows) {
            $row['raw_index'] = count($rows);
            if ($raw !== null) { $row['raw'] = $raw; }
            $rows[] = $row;
        };

        switch ($module_key) {
            case 'search':
                foreach ($items as $page) {
                    if (!empty($page['aiOverview']) && is_array($page['aiOverview'])) {
                        $overview = $page['aiOverview'];
                        $push(['title'=>'Google AI Overview','url'=>self::safe_url($page['url'] ?? ''),'source'=>'AI overview','text'=>self::truncate($overview['content'] ?? '',360),'badge'=>'AI Overview'], $overview);
                    }
                    foreach ((array) ($page['organicResults'] ?? []) as $result) {
                        $meta = [];
                        if (!empty($result['displayedUrl'])) { $meta[] = (string) $result['displayedUrl']; }
                        if (!empty($result['date'])) { $meta[] = (string) $result['date']; }
                        $push(['title'=>self::truncate($result['title'] ?? '',160),'url'=>self::safe_url($result['url'] ?? ($result['link'] ?? '')),'source'=>self::truncate(implode(' - ', $meta),120),'text'=>self::truncate($result['description'] ?? ($result['snippet'] ?? ''),280),'badge'=>'Organic'], $result);
                    }
                    foreach ((array) ($page['peopleAlsoAsk'] ?? []) as $paa) {
                        if (!is_array($paa) || empty($paa['question'])) { continue; }
                        $push(['title'=>self::truncate($paa['question'],160),'url'=>self::safe_url($paa['url'] ?? ''),'source'=>'People Also Ask','text'=>self::truncate($paa['answer'] ?? '',260),'badge'=>'Question'], $paa);
                    }
                    foreach ((array) ($page['relatedQueries'] ?? []) as $rel) {
                        if (!is_array($rel) || empty($rel['title'])) { continue; }
                        $push(['title'=>self::truncate($rel['title'],160),'url'=>self::safe_url($rel['url'] ?? ''),'source'=>'Related query','text'=>'','badge'=>'Related'], $rel);
                    }
                    foreach ((array) ($page['paidResults'] ?? []) as $result) {
                        $push(['title'=>self::truncate($result['title'] ?? '',160),'url'=>self::safe_url($result['url'] ?? ($result['link'] ?? '')),'source'=>'Paid SERP result','text'=>self::truncate($result['description'] ?? ($result['snippet'] ?? ''),260),'badge'=>'Ad'], $result);
                    }
                    if (empty($page['organicResults']) && empty($page['paidResults']) && empty($page['peopleAlsoAsk']) && empty($page['relatedQueries']) && empty($page['aiOverview'])) {
                        // v0.9.31.4: do not push a generic_row whose only content is a google search URL —
                        // it creates a fake SERP card with the raw query URL as the title/link.
                        $page_url = (string) ($page['url'] ?? '');
                        if (!self::is_google_search_url($page_url)) {
                            $push(self::generic_row($page, 'SERP'), $page);
                        }
                    }
                }
                break;
            case 'news':
                foreach ($items as $item) {
                    $source = self::source_text($item['source'] ?? ($item['publisher'] ?? ''));
                    $push(['title'=>self::truncate($item['title'] ?? ($item['headline'] ?? ''),160),'url'=>self::safe_url($item['link'] ?? ($item['url'] ?? '')),'source'=>$source,'text'=>self::truncate($item['description'] ?? ($item['snippet'] ?? ($item['summary'] ?? '')),280),'badge'=>(string)($item['date'] ?? ($item['publishedAt'] ?? 'News')),'image'=>self::safe_url($item['image'] ?? ($item['imageUrl'] ?? ''))], $item);
                }
                break;
            case 'trends':
                foreach ($items as $item) {
                    $timeline = is_array($item['interestOverTime_timelineData'] ?? null) ? $item['interestOverTime_timelineData'] : (is_array($item['interestOverTime'] ?? null) ? $item['interestOverTime'] : []);
                    $avg = is_array($item['interestOverTime_averages'] ?? null) ? implode(', ', array_slice(array_map('strval', $item['interestOverTime_averages']), 0, 5)) : '';
                    $queries = [];
                    foreach (['relatedQueries_top','relatedQueries_rising'] as $bucket) {
                        foreach (array_slice((array) ($item[$bucket] ?? []), 0, 4) as $q) {
                            if (is_array($q) && !empty($q['query'])) { $queries[] = (string) $q['query']; }
                        }
                    }
                    $topics = [];
                    foreach (['relatedTopics_top','relatedTopics_rising'] as $bucket) {
                        foreach (array_slice((array) ($item[$bucket] ?? []), 0, 3) as $topic_row) {
                            $topic = is_array($topic_row['topic'] ?? null) ? ($topic_row['topic']['title'] ?? '') : '';
                            if ($topic !== '') { $topics[] = (string) $topic; }
                        }
                    }
                    $timeline_summary = self::timeline_summary($timeline);
                    $title = $item['inputUrlOrTerm'] ?? ($item['searchTerm'] ?? ($item['term'] ?? ($item['query'] ?? 'Google Trends')));
                    $text_bits = ['Timeline points: ' . count($timeline)];
                    if ($timeline_summary !== '') { $text_bits[] = $timeline_summary; }
                    if ($avg !== '') { $text_bits[] = 'Average: ' . $avg; }
                    if ($queries) { $text_bits[] = 'Queries: ' . implode(', ', array_values(array_unique($queries))); }
                    if ($topics) { $text_bits[] = 'Topics: ' . implode(', ', array_values(array_unique($topics))); }
                    $push(['title'=>self::truncate($title,160),'url'=>'','source'=>(string)($item['geo'] ?? ''),'text'=>self::truncate(implode(' - ', $text_bits),420),'badge'=>'Trends'], $item);
                }
                break;
            case 'images':
                foreach ($items as $item) {
                    $source = is_array($item['source'] ?? null) ? $item['source'] : [];
                    $push(['title'=>self::truncate($item['title'] ?? ($item['alt'] ?? 'Image result'),140),'url'=>self::safe_url($item['sourceUrl'] ?? ($source['link'] ?? ($item['link'] ?? ''))),'source'=>(string)($item['domain'] ?? ($source['domain'] ?? ($source['name'] ?? ''))),'text'=>self::truncate($item['sourceTitle'] ?? ($source['name'] ?? ''),220),'badge'=>'Image','image'=>self::safe_url($item['imageUrl'] ?? ($item['image_url'] ?? ($item['image'] ?? '')))], $item);
                }
                break;
            case 'maps':
                foreach ($items as $item) {
                    $bits = [];
                    if (!empty($item['categoryName'])) { $bits[] = (string) $item['categoryName']; }
                    if (isset($item['totalScore'])) { $bits[] = 'Rating ' . (string) $item['totalScore']; }
                    if (isset($item['reviewsCount'])) { $bits[] = (string) $item['reviewsCount'] . ' reviews'; }
                    if (!empty($item['phone'])) { $bits[] = (string) $item['phone']; }
                    $review_bits = [];
                    foreach ((array) ($item['reviews'] ?? ($item['reviewsData'] ?? [])) as $review) {
                        if (!is_array($review)) { continue; }
                        $review_text = self::first_text($review, ['text','textTranslated','reviewText','comment','snippet']);
                        if ($review_text !== '') { $review_bits[] = self::truncate($review_text, 160); }
                        if (count($review_bits) >= 2) { break; }
                    }
                    if ($review_bits) { $bits[] = 'Review snippets: ' . implode(' | ', $review_bits); }
                    $push(['title'=>self::truncate($item['title'] ?? ($item['name'] ?? ''),160),'url'=>self::safe_url($item['website'] ?? ($item['url'] ?? '')),'source'=>self::truncate($item['address'] ?? '',160),'text'=>self::truncate(implode(' - ', $bits),420),'badge'=>isset($item['rank']) ? 'Rank ' . (string) $item['rank'] : 'Maps','image'=>self::safe_url($item['imageUrl'] ?? '')], $item);
                }
                break;
            case 'crawler':
                foreach ($items as $item) {
                    $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                    $crawl = is_array($item['crawl'] ?? null) ? $item['crawl'] : [];
                    $title = $item['title'] ?? ($metadata['title'] ?? ($item['url'] ?? ''));
                    $text = $item['text'] ?? ($item['markdown'] ?? ($metadata['description'] ?? ''));
                    $host = self::host_from_url($item['url'] ?? '');
                    $badge = !empty($crawl['httpStatusCode']) ? 'HTTP ' . (string) $crawl['httpStatusCode'] : 'Crawled';
                    $push(['title'=>self::truncate($title,160),'url'=>self::safe_url($item['url'] ?? ''),'source'=>$host,'text'=>self::truncate($text,360),'badge'=>$badge], $item);
                }
                break;
            default:
                foreach ($items as $item) { $push(self::generic_row($item, 'Result'), $item); }
        }
        return array_slice(array_values(array_filter($rows, function($row){ return trim((string)($row['title'] ?? $row['text'] ?? $row['url'] ?? '')) !== ''; })), 0, max(1, (int) $max));
    }

    public static function prepare_analysis_items($module_key, $items, $max = 20) {
        $rows = self::prepare_display_items($module_key, $items, $max);
        foreach ($rows as &$row) {
            unset($row['image'], $row['raw']);
        }
        unset($row);
        return $rows;
    }

    private static function timeline_summary($timeline) {
        $values = [];
        foreach ((array) $timeline as $point) {
            if (!is_array($point)) { continue; }
            $value = $point['value'] ?? null;
            if (is_array($value)) { $value = reset($value); }
            if (is_numeric($value)) { $values[] = (float) $value; }
        }
        if (count($values) < 2) { return ''; }
        $first = reset($values);
        $last = end($values);
        $max = max($values);
        $delta = $last - $first;
        return 'First ' . round($first, 1) . ', latest ' . round($last, 1) . ', max ' . round($max, 1) . ', delta ' . ($delta >= 0 ? '+' : '') . round($delta, 1);
    }

    private static function is_google_search_url($url) {
        $url = (string) $url;
        return (bool) preg_match('~^https?://(?:www\.)?google\.[a-z.]+/search~i', $url)
            || strpos($url, '/search?q=') !== false
            || strpos($url, '/search?hl=') !== false;
    }

    private static function generic_row($item, $badge = 'Result') {
        $item = is_array($item) ? $item : [];
        // Exclude google search URL from title candidates (it is not a useful display title).
        $title_candidates = ['title','name','headline','query','searchTerm','inputUrlOrTerm'];
        $raw_url = self::first_text($item, ['url','link','sourceUrl','landingPageUrl','previewUrl','adTransparencyUrl']);
        if (!self::is_google_search_url($raw_url)) {
            $title_candidates[] = 'url';
        }
        $title = self::first_text($item, $title_candidates);
        $url = self::is_google_search_url($raw_url) ? '' : self::safe_url($raw_url);
        $text = self::first_text($item, ['description','snippet','text','markdown','body','summary']);
        return ['title'=>self::truncate($title ?: $badge,160),'url'=>$url,'source'=>'','text'=>self::truncate($text,260),'badge'=>$badge,'image'=>self::safe_url(self::first_text($item, ['imageUrl','image','thumbnailUrl','thumbnail']))];
    }

    private static function first_text($item, $keys) {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') { return (string) $item[$key]; }
        }
        return '';
    }

    private static function source_text($value) {
        if (is_string($value) || is_numeric($value)) { return self::truncate((string) $value, 140); }
        if (is_array($value)) {
            foreach (['name','title','domain','link','url'] as $key) {
                if (!empty($value[$key]) && is_scalar($value[$key])) { return self::truncate((string) $value[$key], 140); }
            }
        }
        return '';
    }

    private static function host_from_url($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private static function parse_lines($raw, $type = 'text', $max = 10, $max_len = 200) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $out = [];
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            $line = self::truncate($line, $max_len);
            if ($type === 'url') {
                $line = esc_url_raw($line);
                if (!$line || !filter_var($line, FILTER_VALIDATE_URL)) { continue; }
            } else {
                $line = sanitize_text_field($line);
            }
            if ($line !== '') { $out[] = $line; }
            if (count($out) >= $max) { break; }
        }
        return array_values(array_unique($out));
    }

    private static function make_start_urls($urls) {
        return array_map(function($url){ return ['url' => esc_url_raw($url)]; }, (array) $urls);
    }

    private static function clean_int($value, $min = 0, $max = null, $default = null) {
        if ($value === '' || $value === null) { return $default; }
        $n = (int) $value;
        if ($n < $min) { $n = $min; }
        if ($max !== null && $n > $max) { $n = $max; }
        return $n;
    }

    private static function one_line($value, $max = 180) {
        return self::truncate(sanitize_text_field((string) $value), $max);
    }

    private static function truncate($text, $max = 240) {
        $text = trim(wp_strip_all_tags((string) $text));
        $text = preg_replace('/\s+/', ' ', $text);
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $max) { return $text; }
        return (function_exists('mb_substr') ? mb_substr($text, 0, max(0, $max - 1)) : substr($text, 0, max(0, $max - 1))) . '...';
    }

    private static function safe_url($url) {
        $url = esc_url_raw((string) $url);
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private static function redact_input($input) {
        $input = is_array($input) ? $input : [];
        $json = wp_json_encode($input);
        if (strlen((string) $json) > 8000) { return ['summary' => 'Input too large to display safely.']; }
        return $input;
    }
}
