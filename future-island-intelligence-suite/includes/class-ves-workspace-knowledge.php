<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Workspace_Knowledge {
    const USER_META_KEY = '_ves_workspace_knowledge';
    const MAX_ITEMS = 40;
    const MAX_TEXT_BYTES = 180000;

    private static function now() {
        return current_time('mysql');
    }

    private static function uuid() {
        try {
            return 'vesk-' . strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'vesk-' . strtolower(wp_generate_password(18, false, false));
        }
    }

    private static function allowed_extensions() {
        return [
            'txt'  => 'text/plain',
            'md'   => 'text/markdown',
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'html' => 'text/html',
            'htm'  => 'text/html',
            'xml'  => 'application/xml',
            'yml'  => 'text/plain',
            'yaml' => 'text/plain',
        ];
    }

    public static function accepted_extensions_hint() {
        return '.txt,.md,.csv,.json,.html,.htm,.xml,.yml,.yaml';
    }

    private static function validate_public_ingest_url($url) {
        $url = esc_url_raw((string) $url, ['http', 'https']);
        if ($url === '') {
            return new WP_Error('invalid_url', 'Introduce una URL válida.');
        }

        $parts = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return new WP_Error('invalid_url', 'Solo se permiten URLs públicas HTTP o HTTPS.');
        }

        $blocked_hosts = ['localhost', 'localhost.localdomain'];
        if (in_array($host, $blocked_hosts, true) || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
            return new WP_Error('blocked_url', 'No se permiten URLs locales o internas.');
        }

        // v0.9.24.66 BUG-S fix: gethostbynamel() only resolves IPv4 (A records).
        // A host pointing only to ::1 (IPv6 loopback) or fd00::/8 (private ULA)
        // would return no IPs and skip the private-range check entirely.
        // Fix: also resolve AAAA records and reject IPv4-mapped IPv6 addresses.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips[] = $host;
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ips[] = $host;
        } else {
            // IPv4 A records
            $resolved_v4 = function_exists('gethostbynamel') ? gethostbynamel($host) : false;
            if (is_array($resolved_v4)) {
                $ips = array_merge($ips, $resolved_v4);
            }
            // IPv6 AAAA records
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!empty($record['ipv6'])) {
                            $ips[] = (string) $record['ipv6'];
                        }
                    }
                }
            }
        }

        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') { continue; }
            // Reject IPv4-mapped IPv6 like ::ffff:192.168.1.1 by extracting the embedded IPv4.
            if (preg_match('/^::ffff:([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$/i', $ip, $m)) {
                $ip = $m[1]; // Treat as IPv4 for the private-range check.
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new WP_Error('blocked_url', 'No se permiten URLs que resuelven a redes privadas o reservadas.');
            }
        }

        return $url;
    }

    private static function ends_with($haystack, $needle) {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }

    private static function sanitize_tags($tags) {
        $clean = [];
        foreach ((array) $tags as $tag) {
            $tag = sanitize_text_field((string) $tag);
            if ($tag !== '') {
                $clean[] = $tag;
            }
        }
        return array_values(array_slice(array_unique($clean), 0, 16));
    }

    private static function sanitize_text_block($text, $max = 8000) {
        $text = wp_strip_all_tags((string) $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, (int) $max);
        }
        return substr($text, 0, (int) $max);
    }

    private static function sanitize_item($item) {
        $item = is_array($item) ? $item : [];
        return [
            'id' => sanitize_key((string) ($item['id'] ?? self::uuid())),
            'source_type' => sanitize_key((string) ($item['source_type'] ?? 'manual')),
            'title' => sanitize_text_field((string) ($item['title'] ?? 'Knowledge item')),
            'source_url' => esc_url_raw((string) ($item['source_url'] ?? '')),
            'file_url' => esc_url_raw((string) ($item['file_url'] ?? '')),
            'file_path' => isset($item['file_path']) ? wp_normalize_path((string) $item['file_path']) : '',
            'file_name' => sanitize_file_name((string) ($item['file_name'] ?? '')),
            'mime_type' => sanitize_text_field((string) ($item['mime_type'] ?? '')),
            'parse_status' => sanitize_key((string) ($item['parse_status'] ?? 'parsed')),
            'content_text' => self::sanitize_text_block((string) ($item['content_text'] ?? ''), 12000),
            'summary_text' => self::sanitize_text_block((string) ($item['summary_text'] ?? ''), 1800),
            'tags' => self::sanitize_tags($item['tags'] ?? []),
            'created_at' => sanitize_text_field((string) ($item['created_at'] ?? self::now())),
            'updated_at' => sanitize_text_field((string) ($item['updated_at'] ?? self::now())),
        ];
    }

    public static function get_user_items($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $items = get_user_meta($user_id, self::USER_META_KEY, true);
        if (!is_array($items)) {
            return [];
        }
        $clean = [];
        foreach ($items as $item) {
            $clean[] = self::sanitize_item($item);
        }
        usort($clean, static function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return array_slice($clean, 0, self::MAX_ITEMS);
    }

    private static function save_user_items($user_id, $items) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Usuario inválido.');
        }
        $clean = [];
        foreach ((array) $items as $item) {
            $clean[] = self::sanitize_item($item);
        }
        usort($clean, static function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        $clean = array_slice($clean, 0, self::MAX_ITEMS);
        update_user_meta($user_id, self::USER_META_KEY, $clean);
        return $clean;
    }

    private static function summarize_from_text($text, $max = 420) {
        $text = self::sanitize_text_block($text, 5000);
        if ($text === '') {
            return '';
        }
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text);
        $summary = '';
        foreach ((array) $sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            $candidate = trim($summary . ' ' . $sentence);
            if (function_exists('mb_strlen')) {
                if (mb_strlen($candidate) > $max) {
                    break;
                }
            } elseif (strlen($candidate) > $max) {
                break;
            }
            $summary = $candidate;
            if ((function_exists('mb_strlen') ? mb_strlen($summary) : strlen($summary)) >= ($max * 0.75)) {
                break;
            }
        }
        if ($summary === '') {
            $summary = function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
        }
        return trim($summary);
    }

    private static function strip_html_to_text($html) {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html);
        $text = wp_strip_all_tags($html, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::sanitize_text_block($text, 12000);
    }

    private static function parse_text_file($path, $mime = '', $ext = '') {
        $path = wp_normalize_path((string) $path);
        if ($path === '' || !file_exists($path) || !is_readable($path)) {
            return ['', 'metadata_only'];
        }
        $bytes = @file_get_contents($path, false, null, 0, self::MAX_TEXT_BYTES);
        if (!is_string($bytes) || $bytes === '') {
            return ['', 'metadata_only'];
        }
        $ext = strtolower((string) $ext);
        if (in_array($ext, ['html', 'htm', 'xml'], true) || strpos((string) $mime, 'html') !== false || strpos((string) $mime, 'xml') !== false) {
            return [self::strip_html_to_text($bytes), 'parsed'];
        }
        if ($ext === 'json') {
            $decoded = json_decode($bytes, true);
            if (is_array($decoded)) {
                $bytes = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }
        return [self::sanitize_text_block($bytes, 12000), 'parsed'];
    }

    private static function build_tags_from_text($text, $extras = []) {
        $tokens = preg_split('/\s+/u', strtolower(self::sanitize_text_block($text, 3000)));
        $stop = ['para','este','esta','with','from','that','your','the','and','por','con','una','uno','unos','unas','que','como','sobre','para','from','into','https','http','www'];
        $counts = [];
        foreach ((array) $tokens as $token) {
            $token = preg_replace('/[^a-z0-9áéíóúñü\-]/u', '', (string) $token);
            if ($token === '' || strlen($token) < 4 || in_array($token, $stop, true)) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, 8);
        return self::sanitize_tags(array_merge((array) $extras, $top));
    }

    public static function add_uploaded_file($user_id, $field = 'knowledge_file', $title = '') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Usuario inválido.');
        }
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
            return new WP_Error('missing_file', 'Selecciona un archivo válido.');
        }
        $file = $_FILES[$field];
        if (!empty($file['error'])) {
            return new WP_Error('upload_error', 'No se pudo subir el archivo.');
        }

        $name = sanitize_file_name((string) ($file['name'] ?? 'knowledge.txt'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = self::allowed_extensions();
        if (!isset($allowed[$ext])) {
            return new WP_Error('invalid_type', 'Este tipo de archivo no está soportado todavía. Usa TXT, MD, CSV, JSON, HTML o XML.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $mimes = $allowed;
        $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => $mimes]);
        if (!is_array($uploaded) || !empty($uploaded['error'])) {
            return new WP_Error('upload_failed', sanitize_text_field((string) ($uploaded['error'] ?? 'No se pudo procesar el archivo.')));
        }

        [$content_text, $parse_status] = self::parse_text_file($uploaded['file'] ?? '', (string) ($uploaded['type'] ?? ''), $ext);
        $resolved_title = trim((string) $title) !== '' ? sanitize_text_field((string) $title) : sanitize_text_field(preg_replace('/\.[^.]+$/', '', $name));
        $summary = self::summarize_from_text($content_text !== '' ? $content_text : $resolved_title);
        $tags = self::build_tags_from_text($resolved_title . ' ' . $summary, [$ext, 'upload']);

        $items = self::get_user_items($user_id);
        array_unshift($items, [
            'id' => self::uuid(),
            'source_type' => 'upload',
            'title' => $resolved_title !== '' ? $resolved_title : 'Uploaded knowledge',
            'source_url' => '',
            'file_url' => esc_url_raw((string) ($uploaded['url'] ?? '')),
            'file_path' => wp_normalize_path((string) ($uploaded['file'] ?? '')),
            'file_name' => $name,
            'mime_type' => sanitize_text_field((string) ($uploaded['type'] ?? '')),
            'parse_status' => $parse_status,
            'content_text' => $content_text,
            'summary_text' => $summary,
            'tags' => $tags,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ]);
        self::save_user_items($user_id, $items);
        return $items[0];
    }

    private static function regex_first($pattern, $html) {
        if (preg_match($pattern, (string) $html, $m) && !empty($m[1])) {
            return html_entity_decode(trim((string) $m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private static function collect_html_paragraphs($html, $limit = 12) {
        $paragraphs = [];
        if (preg_match_all('/<(p|li|h1|h2|h3)[^>]*>(.*?)<\/\1>/is', (string) $html, $matches)) {
            foreach ((array) ($matches[2] ?? []) as $chunk) {
                $text = self::strip_html_to_text((string) $chunk);
                if ($text !== '') {
                    $paragraphs[] = $text;
                }
                if (count($paragraphs) >= $limit) {
                    break;
                }
            }
        }
        return $paragraphs;
    }

    public static function ingest_url_profile($user_id, $url, $title = '') {
        $user_id = (int) $user_id;
        $url = self::validate_public_ingest_url($url);
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Usuario inválido.');
        }
        if (is_wp_error($url)) {
            return $url;
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 20,
            'redirection' => 0,
            'limit_response_size' => self::MAX_TEXT_BYTES,
            'headers' => ['User-Agent' => 'Mozilla/5.0 Vietnam Estudio Workspace Ingest'],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if ($location) {
                $redirect = self::validate_public_ingest_url((string) $location);
                if (!is_wp_error($redirect)) {
                    $response = wp_safe_remote_get($redirect, [
                        'timeout' => 20,
                        'redirection' => 0,
                        'limit_response_size' => self::MAX_TEXT_BYTES,
                        'headers' => ['User-Agent' => 'Mozilla/5.0 Vietnam Estudio Workspace Ingest'],
                    ]);
                    if (is_wp_error($response)) {
                        return new WP_Error('fetch_failed', $response->get_error_message());
                    }
                    $code = (int) wp_remote_retrieve_response_code($response);
                }
            }
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('fetch_http', 'No se pudo leer la URL indicada en este momento.');
        }
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($content_type !== '' && !preg_match('/(text\/html|application\/xhtml\+xml|text\/plain|application\/json|text\/xml|application\/xml)/i', $content_type)) {
            return new WP_Error('unsupported_content_type', 'La URL no parece devolver contenido textual seguro para ingerir.');
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return new WP_Error('empty_body', 'La URL no devolvió contenido útil.');
        }
        if (strlen($body) > self::MAX_TEXT_BYTES) {
            $body = substr($body, 0, self::MAX_TEXT_BYTES);
        }

        $page_title = self::regex_first('/<title[^>]*>(.*?)<\/title>/is', $body);
        $meta_desc = self::regex_first('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/is', $body);
        if ($meta_desc === '') {
            $meta_desc = self::regex_first('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/is', $body);
        }
        $h1 = self::regex_first('/<h1[^>]*>(.*?)<\/h1>/is', $body);
        $site_name = self::regex_first('/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']/is', $body);
        $paragraphs = self::collect_html_paragraphs($body, 14);
        $content_text = self::sanitize_text_block(implode("\n", array_filter([$site_name, $page_title, $h1, $meta_desc, implode("\n", $paragraphs)])), 12000);
        $resolved_title = trim((string) $title) !== '' ? sanitize_text_field((string) $title) : sanitize_text_field($page_title !== '' ? $page_title : parse_url($url, PHP_URL_HOST));
        $summary_bits = array_filter([$site_name, $page_title, $h1, $meta_desc]);
        $summary = self::summarize_from_text(implode('. ', $summary_bits) . ' ' . implode(' ', array_slice($paragraphs, 0, 4)));
        $domain = parse_url($url, PHP_URL_HOST);
        $tags = self::build_tags_from_text($resolved_title . ' ' . $summary . ' ' . $content_text, [$domain ? sanitize_text_field((string) $domain) : '', 'url_profile']);

        $items = self::get_user_items($user_id);
        array_unshift($items, [
            'id' => self::uuid(),
            'source_type' => 'url_profile',
            'title' => $resolved_title !== '' ? $resolved_title : 'URL profile',
            'source_url' => $url,
            'file_url' => '',
            'file_path' => '',
            'file_name' => '',
            'mime_type' => sanitize_text_field((string) wp_remote_retrieve_header($response, 'content-type')),
            'parse_status' => 'parsed',
            'content_text' => $content_text,
            'summary_text' => $summary,
            'tags' => $tags,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ]);
        self::save_user_items($user_id, $items);
        return $items[0];
    }

    public static function delete_item($user_id, $item_id) {
        $user_id = (int) $user_id;
        $item_id = sanitize_key((string) $item_id);
        if ($user_id <= 0 || $item_id === '') {
            return new WP_Error('invalid_request', 'Solicitud inválida.');
        }
        $items = self::get_user_items($user_id);
        $kept = [];
        $deleted = null;
        foreach ($items as $item) {
            if (($item['id'] ?? '') === $item_id) {
                $deleted = $item;
                continue;
            }
            $kept[] = $item;
        }
        if (!$deleted) {
            return new WP_Error('not_found', 'No se ha encontrado el asset de conocimiento.');
        }
        self::save_user_items($user_id, $kept);
        if (!empty($deleted['file_path']) && file_exists($deleted['file_path'])) {
            wp_delete_file($deleted['file_path']);
        }
        return true;
    }

    private static function normalize_text_for_match($text) {
        $text = strtolower(self::sanitize_text_block($text, 5000));
        $text = preg_replace('/[^a-z0-9áéíóúñü\s\-\.]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', trim((string) $text));
        $parts = array_values(array_filter(array_map('trim', (array) $parts), static function ($token) {
            return $token !== '' && strlen($token) >= 3;
        }));
        return array_slice(array_values(array_unique($parts)), 0, 60);
    }

    private static function build_query_tokens($args = []) {
        $pieces = [];
        foreach (['topic','focus','targets','brand_name','primary_goal','website_url','country','platform','reason','title','url'] as $key) {
            if (!empty($args[$key])) {
                $pieces[] = (string) $args[$key];
            }
        }
        if (!empty($args['markets'])) {
            $pieces[] = (string) $args['markets'];
        }
        if (!empty($args['competitors'])) {
            $pieces[] = (string) $args['competitors'];
        }
        return self::normalize_text_for_match(implode(' ', $pieces));
    }

    private static function age_bonus($created_at) {
        $ts = strtotime((string) $created_at);
        if (!$ts) {
            return 0.2;
        }
        $age = max(1, time() - $ts);
        if ($age <= DAY_IN_SECONDS * 7) {
            return 1.4;
        }
        if ($age <= DAY_IN_SECONDS * 30) {
            return 0.8;
        }
        if ($age <= DAY_IN_SECONDS * 90) {
            return 0.35;
        }
        return 0.1;
    }

    private static function score_item($item, $tokens, $args = []) {
        $title = strtolower((string) ($item['title'] ?? ''));
        $summary = strtolower((string) ($item['summary_text'] ?? ''));
        $content = strtolower((string) ($item['content_text'] ?? ''));
        $tags = array_map('strtolower', (array) ($item['tags'] ?? []));
        $score = 0.0;
        $matched = [];
        foreach ((array) $tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (strpos($title, $token) !== false) {
                $score += 2.4;
                $matched[] = $token;
                continue;
            }
            if (strpos($summary, $token) !== false) {
                $score += 1.8;
                $matched[] = $token;
                continue;
            }
            if (in_array($token, $tags, true)) {
                $score += 1.5;
                $matched[] = $token;
                continue;
            }
            if (strpos($content, $token) !== false) {
                $score += 0.9;
                $matched[] = $token;
            }
        }

        $topic = strtolower(trim((string) ($args['topic'] ?? '')));
        if ($topic !== '') {
            if (strpos($title, $topic) !== false || strpos($summary, $topic) !== false) {
                $score += 3.5;
            } elseif (strpos($content, $topic) !== false) {
                $score += 1.8;
            }
        }

        $focus = strtolower(trim((string) ($args['focus'] ?? '')));
        if ($focus !== '') {
            if (strpos($summary, $focus) !== false || strpos($content, $focus) !== false) {
                $score += 2.2;
            }
        }

        $website = strtolower((string) parse_url((string) ($args['website_url'] ?? $args['url'] ?? ''), PHP_URL_HOST));
        $sourceHost = strtolower((string) parse_url((string) ($item['source_url'] ?? ''), PHP_URL_HOST));
        if ($website !== '' && $sourceHost !== '' && ($website === $sourceHost || self::ends_with($sourceHost, '.' . $website) || self::ends_with($website, '.' . $sourceHost))) {
            $score += 2.8;
        }

        if (($item['source_type'] ?? '') === 'url_profile') {
            $score += 0.8;
        } elseif (($item['source_type'] ?? '') === 'upload') {
            $score += 0.5;
        }

        $score += self::age_bonus($item['created_at'] ?? '');
        if ($score <= 0 && ($item['summary_text'] ?? '') !== '') {
            $score = 0.4 + self::age_bonus($item['created_at'] ?? '');
        }
        return [$score, array_slice(array_values(array_unique($matched)), 0, 6)];
    }

    public static function get_relevant_context($user_id, $args = [], $limit = 4) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $limit = max(1, min(8, (int) $limit));
        $items = self::get_user_items($user_id);
        $tokens = self::build_query_tokens($args);
        $scored = [];
        foreach ($items as $item) {
            [$score, $matched] = self::score_item($item, $tokens, $args);
            if ($score <= 0 && !empty($tokens)) {
                continue;
            }
            $scored[] = [
                'id' => $item['id'],
                'source_type' => $item['source_type'],
                'title' => $item['title'],
                'source_url' => $item['source_url'],
                'file_url' => $item['file_url'],
                'file_name' => $item['file_name'],
                'parse_status' => $item['parse_status'],
                'summary' => $item['summary_text'] !== '' ? $item['summary_text'] : self::summarize_from_text($item['content_text']),
                'key_points' => array_values(array_slice(array_filter(preg_split('/(?<=[\.\!\?])\s+/u', (string) ($item['summary_text'] ?: $item['content_text']))), 0, 3)),
                'tags' => array_values(array_slice((array) ($item['tags'] ?? []), 0, 8)),
                'created_at' => $item['created_at'],
                'relevance_score' => round($score, 2),
                'matched_terms' => $matched,
            ];
        }
        usort($scored, static function ($a, $b) {
            $scoreA = (float) ($a['relevance_score'] ?? 0);
            $scoreB = (float) ($b['relevance_score'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            }
            return $scoreA < $scoreB ? 1 : -1;
        });
        return array_slice($scored, 0, $limit);
    }
}
