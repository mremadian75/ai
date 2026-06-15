<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Semantic-lite retrieval index for workspace memory.
 *
 * v0.9.24.38 keeps v2 lightweight and avoids a heavy vector database. It stores a compact
 * searchable text/keyword index and an optional embedding_json field. Embeddings
 * can be supplied later through filters or custom integrations; retrieval works
 * without them using lexical/semantic keyword overlap, recency, importance and
 * feedback scores.
 */
final class VES_Semantic_Index {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'ves_semantic_index_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_semantic_index';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function create_table($force = false) {
        if (!$force && get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(40) NOT NULL DEFAULT '',
            object_id varchar(80) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'recent',
            source_family varchar(64) NOT NULL DEFAULT '',
            platform varchar(64) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            summary longtext NULL,
            searchable_text longtext NULL,
            keywords_json longtext NULL,
            embedding_json longtext NULL,
            evidence_refs_json longtext NULL,
            importance_score decimal(7,2) NOT NULL DEFAULT 0.00,
            feedback_score decimal(7,2) NOT NULL DEFAULT 0.00,
            last_used_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_lookup (workspace_id, object_type, object_id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY object_type (object_type),
            KEY status (status),
            KEY source_family (source_family),
            KEY platform (platform),
            KEY updated_at (updated_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function safe_status($status) {
        $status = sanitize_key((string) $status);
        $allowed = ['approved','accepted','saved','pinned','recent','rejected','archived','superseded','proposed'];
        return in_array($status, $allowed, true) ? $status : 'recent';
    }

    public static function safe_object_type($type) {
        $type = sanitize_key((string) $type);
        $allowed = ['profile','insight','memory','brief','draft','asset','run_summary','manual_note'];
        return in_array($type, $allowed, true) ? $type : 'memory';
    }

    public static function upsert_item($args = []) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        $object_type = self::safe_object_type($args['object_type'] ?? 'memory');
        $object_id = sanitize_text_field((string) ($args['object_id'] ?? ''));
        $title = sanitize_text_field((string) ($args['title'] ?? ''));
        $summary = self::truncate(wp_strip_all_tags((string) ($args['summary'] ?? '')), 3000);
        if ($workspace_id <= 0 || $object_id === '' || ($title === '' && $summary === '')) { return 0; }

        $searchable = self::build_searchable_text(array_merge($args, ['title' => $title, 'summary' => $summary]));
        $keywords = self::keyword_terms($searchable, 80);
        $embedding = self::normalize_embedding($args['embedding'] ?? $args['embedding_json'] ?? null);
        if (empty($embedding)) {
            /**
             * Allows a site-specific embedding provider to attach vectors without
             * this plugin making a blocking API call. Return an array of floats or null.
             */
            $embedding = self::normalize_embedding(apply_filters('ves_semantic_index_embedding', null, $args, $searchable));
        }
        $evidence_refs = self::string_list($args['evidence_refs'] ?? [], 80);
        $now = current_time('mysql');
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => max(0, (int) ($args['user_id'] ?? 0)),
            'object_type' => $object_type,
            'object_id' => self::truncate($object_id, 80),
            'status' => self::safe_status($args['status'] ?? 'recent'),
            'source_family' => sanitize_key((string) ($args['source_family'] ?? $args['source_type'] ?? '')),
            'platform' => sanitize_key((string) ($args['platform'] ?? '')),
            'title' => self::truncate($title, 240),
            'summary' => $summary,
            'searchable_text' => self::truncate($searchable, 12000),
            'keywords_json' => wp_json_encode($keywords, JSON_UNESCAPED_UNICODE),
            'embedding_json' => !empty($embedding) ? wp_json_encode($embedding) : null,
            'evidence_refs_json' => wp_json_encode($evidence_refs, JSON_UNESCAPED_UNICODE),
            'importance_score' => self::normalize_score($args['importance_score'] ?? 50),
            'feedback_score' => self::normalize_feedback_score($args['feedback_score'] ?? 0),
            'updated_at' => $now,
        ];
        if (class_exists('VES_Feedback_Learning')) {
            $row['feedback_score'] = VES_Feedback_Learning::aggregate_score($workspace_id, $object_type, $row['object_id']);
        }
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE workspace_id = %d AND object_type = %s AND object_id = %s LIMIT 1',
            $workspace_id,
            $object_type,
            $row['object_id']
        ));
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%s'];
        if ($existing_id > 0) {
            $updated = $wpdb->update(self::table_name(), $row, ['id' => $existing_id], $formats, ['%d']);
            return false === $updated ? 0 : $existing_id;
        }
        $row['created_at'] = $now;
        $inserted = $wpdb->insert(self::table_name(), $row, array_merge($formats, ['%s']));
        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    public static function seed_from_context($workspace_id, $user_id, $profile = [], $approved_insights = [], $recent_memory = []) {
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        if ($workspace_id <= 0) { return ['profile' => 0, 'insights' => 0, 'memory' => 0]; }
        $counts = ['profile' => 0, 'insights' => 0, 'memory' => 0];
        if (is_array($profile) && !empty(array_filter($profile))) {
            $title = sanitize_text_field((string) ($profile['company_name'] ?? $profile['brand_name'] ?? 'Workspace profile'));
            $summary_parts = [];
            foreach (['brand_positioning','industry','market_country','tone_style_guidance'] as $key) {
                if (!empty($profile[$key])) { $summary_parts[] = is_array($profile[$key]) ? implode(', ', $profile[$key]) : (string) $profile[$key]; }
            }
            foreach (['known_products_services','competitors','audience_segments','known_strengths','known_risks','known_opportunities','approved_strategic_learnings'] as $key) {
                if (!empty($profile[$key])) { $summary_parts[] = is_array($profile[$key]) ? implode(', ', $profile[$key]) : (string) $profile[$key]; }
            }
            $id = self::upsert_item([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'object_type' => 'profile',
                'object_id' => 'profile_' . $workspace_id,
                'status' => 'approved',
                'title' => $title !== '' ? $title : 'Workspace profile',
                'summary' => implode(' | ', array_filter($summary_parts)),
                'source_family' => 'workspace_profile',
                'platform' => 'workspace',
                'importance_score' => 80,
            ]);
            if ($id) { $counts['profile']++; }
        }
        foreach ((array) $approved_insights as $insight) {
            if (!is_array($insight)) { continue; }
            $status = self::safe_status($insight['status'] ?? '');
            if (!in_array($status, ['approved','accepted','saved'], true)) { continue; }
            $id = self::upsert_item([
                'workspace_id' => $workspace_id,
                'user_id' => (int) ($insight['user_id'] ?? $user_id),
                'object_type' => 'insight',
                'object_id' => (string) ($insight['id'] ?? md5(($insight['title'] ?? '') . ($insight['summary'] ?? ''))),
                'status' => $status,
                'title' => $insight['title'] ?? 'Approved insight',
                'summary' => $insight['summary'] ?? '',
                'source_family' => 'insight_ledger',
                'platform' => $insight['platform'] ?? '',
                'evidence_refs' => $insight['evidence_refs'] ?? [],
                'importance_score' => 70 + (float) (($insight['confidence'] ?? $insight['confidence_score'] ?? 0.5) * 20),
            ]);
            if ($id) { $counts['insights']++; }
        }
        foreach ((array) $recent_memory as $memory) {
            if (!is_array($memory)) { continue; }
            $status = !empty($memory['is_pinned']) ? 'pinned' : 'recent';
            $id = self::upsert_item([
                'workspace_id' => $workspace_id,
                'user_id' => (int) ($memory['user_id'] ?? $user_id),
                'object_type' => 'memory',
                'object_id' => (string) ($memory['id'] ?? md5(($memory['title'] ?? '') . ($memory['summary'] ?? ''))),
                'status' => $status,
                'title' => $memory['title'] ?? 'Memory record',
                'summary' => $memory['summary'] ?? '',
                'source_family' => $memory['source_type'] ?? 'memory',
                'platform' => $memory['platform'] ?? '',
                'importance_score' => (float) ($memory['importance_score'] ?? (!empty($memory['is_pinned']) ? 85 : 55)),
            ]);
            if ($id) { $counts['memory']++; }
        }
        return $counts;
    }

    public static function retrieve($args = [], $limit = 5) {
        global $wpdb;
        self::create_table();
        $args = is_array($args) ? $args : [];
        $limit = max(0, min(20, (int) $limit));
        if ($limit <= 0) { return []; }
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = VES_Memory_Records::infer_workspace_id($args, (int) ($args['user_id'] ?? 0));
        }
        if ($workspace_id <= 0) { return []; }
        $platform = sanitize_key((string) ($args['platform'] ?? $args['source_type'] ?? ''));
        $query_text = self::query_text_from_args($args);
        $query_terms = self::keyword_terms($query_text, 60);
        $query_embedding = self::normalize_embedding($args['query_embedding'] ?? null);
        if (empty($query_embedding)) {
            $query_embedding = self::normalize_embedding(apply_filters('ves_semantic_retrieval_query_embedding', null, $args, $query_text));
        }
        $statuses = ['approved','accepted','saved','pinned','recent'];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . " WHERE workspace_id = %d AND status IN ({$placeholders}) ORDER BY updated_at DESC LIMIT 180",
            array_merge([$workspace_id], $statuses)
        ), ARRAY_A);
        $scored = [];
        $has_query = !empty($query_terms) || !empty($query_embedding) || $platform !== '';
        foreach ((array) $rows as $row) {
            $row = self::score_candidate_for_query($row, $query_terms, $query_embedding, $platform);
            if (self::passes_retrieval_gate($row, $has_query)) { $scored[] = $row; }
        }
        usort($scored, static function($a, $b) {
            if ((float) $a['_semantic_score'] === (float) $b['_semantic_score']) { return strcmp((string) $b['updated_at'], (string) $a['updated_at']); }
            return (float) $a['_semantic_score'] < (float) $b['_semantic_score'] ? 1 : -1;
        });
        $selected = array_slice($scored, 0, $limit);
        if (!empty($selected)) {
            $ids = array_map(static function($r){ return (int) ($r['id'] ?? 0); }, $selected);
            $ids = array_values(array_filter($ids));
            if (!empty($ids)) {
                $wpdb->query('UPDATE ' . self::table_name() . " SET last_used_at = '" . esc_sql(current_time('mysql')) . "' WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')');
            }
        }
        return array_map([__CLASS__, 'prepare'], $selected);
    }

    public static function query_text_from_args($args = []) {
        $args = is_array($args) ? $args : [];
        $keys = ['brand_name','brandName','company_name','topic','focus','query','keyword','keywords','industry','country','market_country','research_goal','audit_goal','platform','source_type','prompt','current_summary','analysis_text'];
        $parts = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $args)) { continue; }
            $value = $args[$key];
            if (is_array($value)) { $value = implode(' ', array_slice(array_map('strval', $value), 0, 20)); }
            if (is_scalar($value)) { $parts[] = (string) $value; }
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))));
    }

    public static function term_overlap_score($query_terms, $item_terms, $haystack = '') {
        $query_terms = array_values(array_unique(array_filter(array_map('strval', (array) $query_terms))));
        $item_terms = array_values(array_unique(array_filter(array_map('strval', (array) $item_terms))));
        if (empty($query_terms)) { return 0.0; }
        $item_map = array_flip($item_terms);
        $haystack = strtolower((string) $haystack);
        $score = 0.0;
        foreach ($query_terms as $term) {
            if (isset($item_map[$term])) { $score += 12; }
            elseif (strlen($term) >= 4 && $haystack !== '' && strpos($haystack, $term) !== false) { $score += 5; }
        }
        return min(80.0, $score);
    }

    /**
     * Scores a candidate without letting importance/recency masquerade as relevance.
     * The final score is used for ordering, while semantic_relevance_score is used as
     * the retrieval gate whenever the request has a query/platform/embedding signal.
     */
    public static function score_candidate_for_query($row, $query_terms = [], $query_embedding = [], $platform = '') {
        $row = is_array($row) ? $row : [];
        $keywords = $row['keywords_json'] ?? ($row['keywords'] ?? []);
        if (is_string($keywords)) { $keywords = json_decode($keywords, true); }
        $keywords = is_array($keywords) ? array_values(array_map('strval', $keywords)) : [];
        $platform = sanitize_key((string) $platform);
        $row_platform = sanitize_key((string) ($row['platform'] ?? ''));
        $status = self::safe_status($row['status'] ?? 'recent');
        $object_type = self::safe_object_type($row['object_type'] ?? 'memory');

        $importance = self::normalize_score($row['importance_score'] ?? 50);
        $feedback = self::normalize_feedback_score($row['feedback_score'] ?? 0);
        $base_score = min(30.0, $importance * 0.30) + $feedback;
        $status_score = in_array($status, ['approved','accepted','saved'], true) ? 8.0 : 0.0;
        if ($status === 'pinned') { $status_score += 20.0; }
        $platform_score = ($platform !== '' && $platform === $row_platform) ? 12.0 : 0.0;
        $overlap_score = self::term_overlap_score($query_terms, $keywords, (string) ($row['searchable_text'] ?? $row['summary'] ?? ''));

        $embedding = $row['embedding_json'] ?? ($row['embedding'] ?? []);
        if (is_string($embedding)) { $embedding = json_decode($embedding, true); }
        $embedding = self::normalize_embedding($embedding);
        $query_embedding = self::normalize_embedding($query_embedding);
        $similarity = (!empty($query_embedding) && !empty($embedding)) ? self::cosine_similarity($query_embedding, $embedding) : null;
        $similarity_score = $similarity === null ? 0.0 : max(0.0, (float) $similarity) * 45.0;

        $age_days = self::age_days((string) ($row['updated_at'] ?? ''));
        $recency_score = $age_days === null ? 0.0 : max(0.0, 8.0 - min(8.0, (float) $age_days));
        $profile_score = $object_type === 'profile' ? 10.0 : 0.0;
        $pinned_relevance = $status === 'pinned' ? 6.0 : 0.0;
        $relevance_score = $overlap_score + $platform_score + $similarity_score + $profile_score + $pinned_relevance;
        $score = $base_score + $status_score + $platform_score + $overlap_score + $similarity_score + $recency_score;

        $reasons = [];
        if ($overlap_score > 0) { $reasons[] = 'term_overlap'; }
        if ($platform_score > 0) { $reasons[] = 'platform_match'; }
        if ($similarity_score > 0) { $reasons[] = 'embedding_similarity'; }
        if ($profile_score > 0) { $reasons[] = 'profile'; }
        if ($status === 'pinned') { $reasons[] = 'pinned'; }
        if ($feedback > 0) { $reasons[] = 'positive_feedback'; }
        if ($feedback <= -36) { $reasons[] = 'strong_negative_feedback'; }

        $row['_semantic_score'] = round($score, 2);
        $row['_semantic_relevance_score'] = round($relevance_score, 2);
        $row['_semantic_similarity'] = $similarity === null ? null : round((float) $similarity, 4);
        $row['_semantic_reason'] = implode(',', $reasons);
        return $row;
    }

    public static function passes_retrieval_gate($row, $has_query = true) {
        $row = is_array($row) ? $row : [];
        $status = self::safe_status($row['status'] ?? 'recent');
        if (in_array($status, ['rejected','archived','superseded','proposed'], true)) { return false; }
        $score = (float) ($row['_semantic_score'] ?? $row['semantic_score'] ?? 0);
        if ($score <= 0) { return false; }
        $feedback = self::normalize_feedback_score($row['feedback_score'] ?? 0);
        if ($feedback <= -36 && $status !== 'pinned') { return false; }
        $object_type = self::safe_object_type($row['object_type'] ?? 'memory');
        if (!$has_query) {
            return $object_type === 'profile' || in_array($status, ['pinned','approved','accepted','saved'], true);
        }
        $relevance = (float) ($row['_semantic_relevance_score'] ?? $row['semantic_relevance_score'] ?? 0);
        if ($object_type === 'profile') { return true; }
        if ($status === 'pinned' && $relevance >= 4.0) { return true; }
        return $relevance > 0;
    }

    public static function keyword_terms($text, $limit = 60) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $text = preg_replace('/https?:\/\/\S+/i', ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}_\- ]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', trim((string) $text));
        $stop = array_flip(['the','and','for','with','from','that','this','are','was','were','you','your','our','their','into','sobre','para','con','una','los','las','del','que','por','como','más','mais','este','esta','not','but','should','would','could','company','brand','analysis','summary']);
        $counts = [];
        foreach ((array) $parts as $part) {
            $part = trim($part, "-_ \t\n\r\0\x0B");
            if ($part === '' || isset($stop[$part])) { continue; }
            if ((function_exists('mb_strlen') ? mb_strlen($part) : strlen($part)) < 3) { continue; }
            $counts[$part] = ($counts[$part] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, max(1, min(200, (int) $limit)));
    }

    public static function cosine_similarity($a, $b) {
        $a = self::normalize_embedding($a);
        $b = self::normalize_embedding($b);
        $n = min(count($a), count($b));
        if ($n <= 0) { return null; }
        $dot = 0.0; $mag_a = 0.0; $mag_b = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $mag_a += $a[$i] * $a[$i];
            $mag_b += $b[$i] * $b[$i];
        }
        if ($mag_a <= 0 || $mag_b <= 0) { return null; }
        return $dot / (sqrt($mag_a) * sqrt($mag_b));
    }

    public static function refresh_feedback_score($workspace_id, $object_type, $object_id) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $object_type = self::safe_object_type($object_type);
        $object_id = sanitize_text_field((string) $object_id);
        if ($workspace_id <= 0 || $object_id === '' || !class_exists('VES_Feedback_Learning')) { return 0.0; }
        $score = VES_Feedback_Learning::aggregate_score($workspace_id, $object_type, $object_id);
        $wpdb->update(self::table_name(), ['feedback_score' => $score, 'updated_at' => current_time('mysql')], ['workspace_id' => $workspace_id, 'object_type' => $object_type, 'object_id' => $object_id], ['%f','%s'], ['%d','%s','%s']);
        return $score;
    }

    public static function prepare($row) {
        $keywords = json_decode((string) ($row['keywords_json'] ?? '[]'), true);
        $refs = json_decode((string) ($row['evidence_refs_json'] ?? '[]'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'object_type' => (string) ($row['object_type'] ?? ''),
            'object_id' => (string) ($row['object_id'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'source_family' => (string) ($row['source_family'] ?? ''),
            'platform' => (string) ($row['platform'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'summary' => self::truncate((string) ($row['summary'] ?? ''), 900),
            'keywords' => is_array($keywords) ? array_slice(array_values($keywords), 0, 20) : [],
            'evidence_refs' => is_array($refs) ? array_values($refs) : [],
            'importance_score' => (float) ($row['importance_score'] ?? 0),
            'feedback_score' => (float) ($row['feedback_score'] ?? 0),
            'semantic_score' => (float) ($row['_semantic_score'] ?? 0),
            'semantic_relevance_score' => (float) ($row['_semantic_relevance_score'] ?? 0),
            'semantic_similarity' => $row['_semantic_similarity'] ?? null,
            'semantic_reason' => (string) ($row['_semantic_reason'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function build_searchable_text($args) {
        $parts = [];
        foreach (['title','summary','source_family','platform','status'] as $key) {
            if (!empty($args[$key]) && is_scalar($args[$key])) { $parts[] = (string) $args[$key]; }
        }
        foreach (['tags','evidence_refs','keywords'] as $key) {
            if (!empty($args[$key]) && is_array($args[$key])) { $parts[] = implode(' ', array_slice(array_map('strval', $args[$key]), 0, 50)); }
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    private static function normalize_embedding($value) {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $v) {
            if (is_numeric($v)) { $out[] = (float) $v; }
            if (count($out) >= 4096) { break; }
        }
        return $out;
    }

    private static function string_list($items, $limit = 50) {
        if (is_string($items)) { $items = preg_split('/[\n,]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['ref'] ?? $item['id'] ?? $item['evidence_ref'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $item = trim(sanitize_text_field((string) $item));
            if ($item !== '') { $out[] = self::truncate($item, 120); }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    private static function normalize_score($value) {
        return max(0, min(100, is_numeric($value) ? (float) $value : 50.0));
    }

    private static function normalize_feedback_score($value) {
        return max(-60, min(60, is_numeric($value) ? (float) $value : 0.0));
    }

    private static function age_days($mysql) {
        $ts = strtotime((string) $mysql);
        if (!$ts) { return null; }
        return max(0, (time() - $ts) / DAY_IN_SECONDS);
    }

    private static function truncate($value, $max) {
        $value = trim((string) $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) { return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value; }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
