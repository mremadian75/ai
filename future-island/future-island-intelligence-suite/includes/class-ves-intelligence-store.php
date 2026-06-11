<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Intelligence_Store — Phase 2 canonical intelligence data contract.
 *
 * The durable product spine: Source → Signal → Evidence → Insight → Brief → Draft → Memory.
 *
 * Design decisions (see PHASE-2 report for full rationale):
 *  - The existing run-scoped stores (VES_Evidence_Store, VES_Insight_Records,
 *    VES_Brief_Records, …) remain the system of record for the EXISTING trend /
 *    brand-audit pipeline and are NOT modified. This class is the NEW, generic,
 *    workspace-first contract that future modules write to. No data is migrated
 *    or duplicated automatically; the two coexist (a later phase may unify them).
 *  - Memory is the one entity we ADAPT rather than duplicate: create_memory_record()
 *    delegates to the existing VES_Memory_Records when present, and only falls back
 *    to a canonical table when it is unavailable.
 *  - Evidence/Signal links use JSON-array columns (matching the existing stores'
 *    evidence_refs_json convention) with safe append helpers — not link tables.
 *    This keeps the contract simple and additive; link tables can come later if
 *    per-record reference counts grow large.
 *
 * Safety: every write validates workspace, enums and required fields, sanitizes
 * strings, scrubs forbidden/secret metadata, bounds metadata size, and uses
 * $wpdb->insert with explicit formats. Methods return int id or WP_Error.
 */
final class VES_Intelligence_Store {

    const DB_VERSION  = '1.1.0';
    const VERSION_OPT = 'ves_intelligence_store_db_version';
    const MAX_META_BYTES = 8000;
    const MAX_LONGTEXT   = 200000;

    /** Metadata keys that must never be persisted. */
    const FORBIDDEN_META_KEYS = [
        'prompt', 'input', 'messages', 'message', 'response', 'raw_response', 'raw',
        'api_key', 'apikey', 'token', 'bearer', 'authorization', 'auth',
        'password', 'secret', 'webhook_secret', 'key',
    ];

    /** Canonical enum sets (B2). */
    private static function enums(): array {
        return [
            'source_type'   => ['web', 'social', 'search', 'ai_answer', 'file', 'manual', 'api', 'scrape', 'other'],
            'provider'      => ['openai', 'apify', 'google', 'reddit', 'tiktok', 'instagram', 'linkedin', 'manual', 'other'],
            'signal_type'   => ['mention', 'trend', 'competitor_move', 'citation', 'claim', 'content_pattern', 'audience_signal', 'market_signal', 'ai_visibility', 'other'],
            'evidence_type' => ['quote', 'metric', 'screenshot_ref', 'citation', 'excerpt', 'observation', 'other'],
            'insight_type'  => ['opportunity', 'risk', 'trend', 'competitor', 'audience', 'content', 'aeo', 'market', 'other'],
            'insight_status'=> ['draft', 'reviewed', 'approved', 'rejected', 'archived'],
            'brief_type'    => ['content', 'campaign', 'aeo_fix', 'creative', 'report', 'research', 'other'],
            'brief_status'  => ['draft', 'reviewed', 'approved', 'rejected', 'archived'],
            'draft_type'    => ['post', 'article', 'landing_copy', 'ad', 'email', 'report_section', 'script', 'other'],
            'draft_status'  => ['generated', 'edited', 'approved', 'rejected', 'archived'],
            'memory_type'   => ['brand', 'audience', 'competitor', 'performance', 'preference', 'constraint', 'learning', 'other'],
            'memory_status' => ['active', 'superseded', 'deleted'],
        ];
    }

    /** Entities backed by a canonical ves_intel_* table (memory is handled separately). */
    private static function entities(): array {
        return ['source', 'signal', 'evidence', 'insight', 'brief', 'draft'];
    }

    /**
     * Which entities actually have a `status` column. Used to guard the generic
     * list() status filter so callers like list_sources(['status'=>'draft']) can
     * never generate invalid SQL against a no-status table.
     */
    private static function supports_status(string $entity): bool {
        return in_array($entity, ['insight', 'brief', 'draft', 'memory'], true);
    }

    public static function table_name(string $entity): string {
        global $wpdb;
        return $wpdb->prefix . 'ves_intel_' . $entity . 's';
    }

    public static function memory_fallback_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ves_intel_memory';
    }

    // ── Migrations ────────────────────────────────────────────────────────────

    /** Idempotent installer for all canonical tables. dbDelta only adds, never drops. */
    public static function create_tables($force = false): void {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach (self::schemas() as $sql) {
            if (function_exists('dbDelta')) { dbDelta($sql); }
            elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        }
        if (function_exists('update_option')) {
            update_option(self::VERSION_OPT, self::DB_VERSION, false);
        }
    }

    /** Alias so this store can register in VES_Migrations::stores() like the others. */
    public static function create_table($force = false): void { self::create_tables($force); }

    private static function schemas(): array {
        global $wpdb;
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $base_head = "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,";
        $base_tail = "metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY created_at (created_at)";

        $t = [];
        $t[] = "CREATE TABLE " . self::table_name('source') . " (
            {$base_head}
            source_type varchar(32) NOT NULL DEFAULT 'other',
            provider varchar(32) NOT NULL DEFAULT 'other',
            source_url text NULL,
            source_title varchar(255) NOT NULL DEFAULT '',
            external_id varchar(190) NOT NULL DEFAULT '',
            fetched_at datetime NULL,
            language varchar(16) NOT NULL DEFAULT '',
            region varchar(16) NOT NULL DEFAULT '',
            raw_hash varchar(64) NOT NULL DEFAULT '',
            canonical_hash varchar(64) NOT NULL DEFAULT '',
            credibility_score decimal(5,2) NOT NULL DEFAULT 0.00,
            last_seen_at datetime NULL,
            {$base_tail},
            KEY source_type (source_type),
            KEY provider (provider),
            KEY raw_hash (raw_hash),
            KEY canonical_hash (canonical_hash),
            KEY external_id (external_id)
        ) {$cc};";

        $t[] = "CREATE TABLE " . self::table_name('signal') . " (
            {$base_head}
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            resolved_entity_id varchar(120) NOT NULL DEFAULT '',
            signal_type varchar(40) NOT NULL DEFAULT 'other',
            title varchar(255) NOT NULL DEFAULT '',
            summary longtext NULL,
            value_text longtext NULL,
            value_number decimal(18,4) NOT NULL DEFAULT 0,
            occurred_at datetime NULL,
            confidence_score decimal(5,2) NOT NULL DEFAULT 0.00,
            freshness_score decimal(5,2) NOT NULL DEFAULT 0.00,
            recurrence_score decimal(5,2) NOT NULL DEFAULT 0.00,
            specificity_score decimal(5,2) NOT NULL DEFAULT 0.00,
            canonical_signal_hash varchar(64) NOT NULL DEFAULT '',
            recurrence_count int unsigned NOT NULL DEFAULT 1,
            last_seen_at datetime NULL,
            {$base_tail},
            KEY source_id (source_id),
            KEY signal_type (signal_type),
            KEY canonical_signal_hash (canonical_signal_hash)
        ) {$cc};";

        $t[] = "CREATE TABLE " . self::table_name('evidence') . " (
            {$base_head}
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            signal_id bigint(20) unsigned NOT NULL DEFAULT 0,
            evidence_type varchar(32) NOT NULL DEFAULT 'other',
            text longtext NULL,
            source_url text NULL,
            source_label varchar(190) NOT NULL DEFAULT '',
            observed_at datetime NULL,
            confidence_score decimal(5,2) NOT NULL DEFAULT 0.00,
            {$base_tail},
            KEY source_id (source_id),
            KEY signal_id (signal_id),
            KEY evidence_type (evidence_type)
        ) {$cc};";

        $t[] = "CREATE TABLE " . self::table_name('insight') . " (
            {$base_head}
            insight_type varchar(40) NOT NULL DEFAULT 'other',
            title varchar(255) NOT NULL DEFAULT '',
            summary longtext NULL,
            recommendation longtext NULL,
            confidence_score decimal(5,2) NOT NULL DEFAULT 0.00,
            status varchar(24) NOT NULL DEFAULT 'draft',
            evidence_ids_json longtext NULL,
            signal_ids_json longtext NULL,
            {$base_tail},
            KEY insight_type (insight_type),
            KEY status (status)
        ) {$cc};";

        $t[] = "CREATE TABLE " . self::table_name('brief') . " (
            {$base_head}
            insight_id bigint(20) unsigned NOT NULL DEFAULT 0,
            brief_type varchar(40) NOT NULL DEFAULT 'other',
            title varchar(255) NOT NULL DEFAULT '',
            objective longtext NULL,
            audience varchar(255) NOT NULL DEFAULT '',
            key_message longtext NULL,
            constraints_json longtext NULL,
            evidence_ids_json longtext NULL,
            status varchar(24) NOT NULL DEFAULT 'draft',
            {$base_tail},
            KEY insight_id (insight_id),
            KEY brief_type (brief_type),
            KEY status (status)
        ) {$cc};";

        $t[] = "CREATE TABLE " . self::table_name('draft') . " (
            {$base_head}
            brief_id bigint(20) unsigned NOT NULL DEFAULT 0,
            draft_type varchar(40) NOT NULL DEFAULT 'other',
            title varchar(255) NOT NULL DEFAULT '',
            body longtext NULL,
            channel varchar(80) NOT NULL DEFAULT '',
            model varchar(100) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'generated',
            evidence_ids_json longtext NULL,
            {$base_tail},
            KEY brief_id (brief_id),
            KEY draft_type (draft_type),
            KEY status (status)
        ) {$cc};";

        // Canonical fallback memory table (only used when VES_Memory_Records is absent).
        $t[] = "CREATE TABLE " . self::memory_fallback_table() . " (
            {$base_head}
            memory_type varchar(40) NOT NULL DEFAULT 'other',
            text longtext NULL,
            importance_score decimal(6,2) NOT NULL DEFAULT 0.00,
            source_entity_type varchar(40) NOT NULL DEFAULT '',
            source_entity_id varchar(120) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'active',
            {$base_tail},
            KEY memory_type (memory_type),
            KEY status (status)
        ) {$cc};";

        return $t;
    }

    /**
     * Per-entity field spec: column => [type, required, max, in(enum), input(key), default].
     * Drives generic validation/sanitization/insert.
     */
    private static function field_specs(): array {
        return [
            'source' => [
                'source_type'       => ['type' => 'enum', 'in' => 'source_type', 'required' => true],
                'provider'          => ['type' => 'enum', 'in' => 'provider', 'required' => true],
                'source_url'        => ['type' => 'url', 'max' => 2048],
                'source_title'      => ['type' => 'text', 'max' => 255],
                'external_id'       => ['type' => 'text', 'max' => 190],
                'fetched_at'        => ['type' => 'datetime'],
                'language'          => ['type' => 'key', 'max' => 16],
                'region'            => ['type' => 'key', 'max' => 16],
                'raw_hash'          => ['type' => 'hash', 'max' => 64],
                'canonical_hash'    => ['type' => 'hash', 'max' => 64],
                'credibility_score' => ['type' => 'score'],
                'last_seen_at'      => ['type' => 'datetime'],
            ],
            'signal' => [
                'source_id'          => ['type' => 'idref'],
                'resolved_entity_id' => ['type' => 'text', 'max' => 120],
                'signal_type'        => ['type' => 'enum', 'in' => 'signal_type', 'required' => true],
                'title'              => ['type' => 'text', 'max' => 255, 'required' => true],
                'summary'            => ['type' => 'longtext'],
                'value_text'         => ['type' => 'longtext'],
                'value_number'       => ['type' => 'number'],
                'occurred_at'        => ['type' => 'datetime'],
                'confidence_score'   => ['type' => 'score'],
                'freshness_score'    => ['type' => 'score'],
                'recurrence_score'   => ['type' => 'score'],
                'specificity_score'  => ['type' => 'score'],
                'canonical_signal_hash' => ['type' => 'hash', 'max' => 64],
                'recurrence_count'   => ['type' => 'count'],
                'last_seen_at'       => ['type' => 'datetime'],
            ],
            'evidence' => [
                'source_id'        => ['type' => 'idref'],
                'signal_id'        => ['type' => 'idref'],
                'evidence_type'    => ['type' => 'enum', 'in' => 'evidence_type', 'required' => true],
                'text'             => ['type' => 'longtext', 'required' => true],
                'source_url'       => ['type' => 'url', 'max' => 2048],
                'source_label'     => ['type' => 'text', 'max' => 190],
                'observed_at'      => ['type' => 'datetime'],
                'confidence_score' => ['type' => 'score'],
            ],
            'insight' => [
                'insight_type'      => ['type' => 'enum', 'in' => 'insight_type', 'required' => true],
                'title'             => ['type' => 'text', 'max' => 255, 'required' => true],
                'summary'           => ['type' => 'longtext'],
                'recommendation'    => ['type' => 'longtext'],
                'confidence_score'  => ['type' => 'score'],
                'status'            => ['type' => 'enum', 'in' => 'insight_status', 'default' => 'draft'],
                'evidence_ids_json' => ['type' => 'json_ids', 'input' => 'evidence_ids'],
                'signal_ids_json'   => ['type' => 'json_ids', 'input' => 'signal_ids'],
            ],
            'brief' => [
                'insight_id'        => ['type' => 'idref'],
                'brief_type'        => ['type' => 'enum', 'in' => 'brief_type', 'required' => true],
                'title'             => ['type' => 'text', 'max' => 255, 'required' => true],
                'objective'         => ['type' => 'longtext', 'required' => true],
                'audience'          => ['type' => 'text', 'max' => 255],
                'key_message'       => ['type' => 'longtext'],
                'constraints_json'  => ['type' => 'json_obj', 'input' => 'constraints'],
                'evidence_ids_json' => ['type' => 'json_ids', 'input' => 'evidence_ids'],
                'status'            => ['type' => 'enum', 'in' => 'brief_status', 'default' => 'draft'],
            ],
            'draft' => [
                'brief_id'          => ['type' => 'idref'],
                'draft_type'        => ['type' => 'enum', 'in' => 'draft_type', 'required' => true],
                'title'             => ['type' => 'text', 'max' => 255],
                'body'              => ['type' => 'longtext', 'required' => true],
                'channel'           => ['type' => 'text', 'max' => 80],
                'model'             => ['type' => 'text', 'max' => 100],
                'status'            => ['type' => 'enum', 'in' => 'draft_status', 'default' => 'generated'],
                'evidence_ids_json' => ['type' => 'json_ids', 'input' => 'evidence_ids'],
            ],
        ];
    }

    // ── Generic create ────────────────────────────────────────────────────────

    /**
     * @return int|WP_Error  new row id, or WP_Error on validation/DB failure.
     */
    public static function create_record(string $entity, array $data) {
        $specs = self::field_specs();
        if (!isset($specs[$entity])) {
            return new WP_Error('ves_intel_unknown_entity', 'Unknown intelligence entity: ' . $entity);
        }
        $workspace_id = max(0, (int) ($data['workspace_id'] ?? 0));
        if ($workspace_id <= 0) {
            return new WP_Error('ves_intel_missing_workspace', 'workspace_id is required for tenant isolation.');
        }

        $row = ['workspace_id' => $workspace_id];
        $formats = ['%d'];
        foreach ($specs[$entity] as $column => $spec) {
            $input_key = $spec['input'] ?? $column;
            $present = array_key_exists($input_key, $data);
            $raw = $present ? $data[$input_key] : null;
            $type = $spec['type'];

            // Required check (empty string / null / empty array all count as missing).
            $is_empty = ($raw === null || $raw === '' || (is_array($raw) && empty($raw)));
            if (!empty($spec['required']) && $is_empty) {
                return new WP_Error('ves_intel_missing_field', "Missing required field '{$input_key}' for {$entity}.");
            }

            [$value, $fmt, $err] = self::coerce_field($type, $raw, $spec, $entity, $input_key);
            if ($err instanceof WP_Error) { return $err; }
            $row[$column] = $value;
            $formats[] = $fmt;
        }

        // Evidence enforcement for insights (B5): reviewed/approved require evidence.
        if ($entity === 'insight') {
            $status = (string) ($row['status'] ?? 'draft');
            $evi = self::decode_ids($row['evidence_ids_json'] ?? '[]');
            if (in_array($status, ['reviewed', 'approved'], true) && count($evi) === 0) {
                return new WP_Error('ves_intel_evidence_required',
                    'A reviewed/approved insight requires at least one evidence_id.');
            }
        }

        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $row['metadata'] = self::prepare_metadata($data['metadata'] ?? []);
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $formats[] = '%s'; $formats[] = '%s'; $formats[] = '%s';

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return new WP_Error('ves_intel_no_db', 'Database unavailable.');
        }
        $ok = $wpdb->insert(self::table_name($entity), $row, $formats);
        if ($ok === false) {
            return new WP_Error('ves_intel_insert_failed', 'Could not persist ' . $entity . ' record.');
        }
        return (int) $wpdb->insert_id;
    }

    // Typed creators -----------------------------------------------------------
    public static function create_source(array $d)   { return self::create_record('source', self::normalize_source($d)); }
    public static function create_signal(array $d)   { return self::create_record('signal', self::normalize_signal($d)); }
    public static function create_evidence(array $d) { return self::create_record('evidence', $d); }
    public static function create_insight(array $d)  { return self::create_record('insight', $d); }
    public static function create_brief(array $d)    { return self::create_record('brief', $d); }
    public static function create_draft(array $d)    { return self::create_record('draft', $d); }

    // ── Phase 3: Source normalization + provenance + dedup ─────────────────────

    /** Tracking query params stripped during URL canonicalization (dedup hygiene). */
    const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'fbclid', 'igshid', 'mc_cid', 'mc_eid', 'ref', 'ref_src', 'ref_url',
        '_hsenc', '_hsmi', 'spm', 'scm', 'yclid', 'mkt_tok',
    ];

    /**
     * Canonicalize a URL for stable dedup hashing: lowercase scheme+host, drop the
     * fragment, strip known tracking params, sort remaining params, trim trailing
     * slash. Returns '' for empty/invalid input.
     */
    public static function canonicalize_url(string $url): string {
        $url = trim($url);
        if ($url === '') { return ''; }
        $parts = @parse_url($url);
        if ($parts === false || empty($parts['host'])) { return ''; }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            foreach (array_keys($q) as $k) {
                if (in_array(strtolower((string) $k), self::TRACKING_PARAMS, true)) { unset($q[$k]); }
            }
            ksort($q);
            if (!empty($q)) { $query = '?' . http_build_query($q); }
        }
        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Normalize a Source payload + compute raw_hash and canonical_hash.
     * - raw_hash: weak fingerprint of the raw payload (or stable identifying fields).
     * - canonical_hash: strong dedup key over normalized provider + canonical URL
     *   (or external_id when no URL) + source_type, scoped per workspace.
     * Provenance fields are folded into bounded, scrubbed metadata.
     * @return array data ready for create_record('source', …)
     */
    public static function normalize_source(array $data): array {
        $out = $data;
        $workspace_id = max(0, (int) ($data['workspace_id'] ?? 0));
        $provider = self::normalize_provider((string) ($data['provider'] ?? 'other'));
        $out['provider'] = $provider;
        $out['source_type'] = self::sanitize_key((string) ($data['source_type'] ?? 'other'), 32);
        $url = (string) ($data['source_url'] ?? '');
        $canonical_url = self::canonicalize_url($url);
        $external_id = self::sanitize_text((string) ($data['external_id'] ?? ''), 190);
        $out['language'] = self::normalize_locale((string) ($data['language'] ?? ''));
        $out['region'] = self::normalize_locale((string) ($data['region'] ?? ''));

        // raw_hash: prefer an explicit raw payload, else stable identifying fields.
        if (empty($data['raw_hash'])) {
            $raw_basis = isset($data['raw_payload']) ? (is_string($data['raw_payload']) ? $data['raw_payload'] : wp_json_encode($data['raw_payload']))
                : implode('|', [$provider, $external_id, $url, (string) ($data['source_title'] ?? '')]);
            $out['raw_hash'] = hash('sha256', (string) $raw_basis);
        }
        // canonical_hash: dedup-significant fields only.
        if (empty($data['canonical_hash'])) {
            $canonical_basis = $canonical_url !== '' ? $canonical_url : ('ext:' . $provider . ':' . $external_id);
            $out['canonical_hash'] = hash('sha256', $workspace_id . '|' . $out['source_type'] . '|' . $provider . '|' . $canonical_basis);
        }
        unset($out['raw_payload']); // never persist the raw payload itself

        // Fold provenance into bounded metadata (scrubbed by create_record).
        $prov = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        foreach (['actor_id', 'dataset_id', 'item_id', 'request_id', 'query', 'source_platform',
                  'source_handle', 'source_author', 'source_published_at', 'retrieval_method',
                  'fetch_status', 'http_status', 'cost_event_id', 'usage_event_id', 'raw_payload_hash'] as $pk) {
            if (isset($data[$pk]) && !isset($prov[$pk])) { $prov[$pk] = $data[$pk]; }
        }
        $prov['provider'] = $provider;
        if ($canonical_url !== '') { $prov['canonical_url'] = $canonical_url; }
        $out['metadata'] = $prov;
        return $out;
    }

    public static function find_source_by_hash(int $workspace_id, string $canonical_hash, string $raw_hash = '') {
        global $wpdb;
        if ($workspace_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        $table = self::table_name('source');
        if ($canonical_hash !== '') {
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE workspace_id = %d AND canonical_hash = %s ORDER BY id ASC LIMIT 1', $workspace_id, $canonical_hash));
            if ($id > 0) { return $id; }
        }
        if ($raw_hash !== '') {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' WHERE workspace_id = %d AND raw_hash = %s ORDER BY id ASC LIMIT 1', $workspace_id, $raw_hash));
        }
        return 0;
    }

    /**
     * Create a Source, or return the id of an existing one with the same canonical
     * (or raw) hash in the same workspace. Preserves the first-seen record; only
     * touches last_seen_at on a repeat sighting (never overwrites metadata).
     * @return int|WP_Error
     */
    public static function create_or_get_source(array $data) {
        $norm = self::normalize_source($data);
        $workspace_id = max(0, (int) ($norm['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return new WP_Error('ves_intel_missing_workspace', 'workspace_id is required for tenant isolation.'); }
        $existing = self::find_source_by_hash($workspace_id, (string) ($norm['canonical_hash'] ?? ''), (string) ($norm['raw_hash'] ?? ''));
        if ($existing > 0) {
            self::touch_last_seen('source', $existing);
            return $existing;
        }
        return self::create_record('source', $norm);
    }

    /**
     * Phase 3 (Part D integration) — resolve a Source through the waterfall:
     * try the free "existing source by hash" tier first, then any caller-supplied
     * (e.g. provider-fetch) tiers, and finally the cheap "create" tier. Returns the
     * resolved source id plus the waterfall provenance trail. Low-risk + additive:
     * any future flow can call this instead of create_or_get_source to get an
     * attempt log without rewriting its sourcing.
     *
     * @return array{source_id:int,provider:string,attempts:array}
     */
    public static function source_with_waterfall(array $data, array $providers = []): array {
        $norm = self::normalize_source($data);
        $ws = max(0, (int) ($norm['workspace_id'] ?? 0));
        $canonical = (string) ($norm['canonical_hash'] ?? '');
        $raw = (string) ($norm['raw_hash'] ?? '');

        $existing_tier = ['provider' => 'existing_db', 'cost_tier' => 'free', 'callable' => function () use ($ws, $canonical, $raw) {
            $id = self::find_source_by_hash($ws, $canonical, $raw);
            if ($id > 0) { self::touch_last_seen('source', $id); return ['items' => [$id], 'source_id' => $id]; }
            return null;
        }];
        $create_tier = ['provider' => 'create', 'cost_tier' => 'cheap', 'callable' => function () use ($norm) {
            $id = self::create_record('source', $norm);
            if (self::is_wp_error_local($id)) { return $id; }
            return ['items' => [(int) $id], 'source_id' => (int) $id];
        }];

        $chain = array_merge([$existing_tier], $providers, [$create_tier]);
        if (!class_exists('VES_Waterfall_Sourcing')) {
            // Fallback if the framework is unavailable: behave like create_or_get_source.
            $id = self::create_or_get_source($data);
            return ['source_id' => self::is_wp_error_local($id) ? 0 : (int) $id, 'provider' => 'create', 'attempts' => []];
        }
        $res = VES_Waterfall_Sourcing::run([
            'workspace_id' => $ws,
            'module' => self::sanitize_token_local((string) ($data['module'] ?? '')),
            'operation_type' => 'source_resolution',
        ], $chain);
        return [
            'source_id' => (int) ($res['result']['source_id'] ?? 0),
            'provider' => (string) ($res['provider'] ?? ''),
            'attempts' => is_array($res['attempts'] ?? null) ? $res['attempts'] : [],
        ];
    }

    private static function is_wp_error_local($thing): bool {
        return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error);
    }
    private static function sanitize_token_local(string $v): string {
        $v = function_exists('sanitize_key') ? sanitize_key($v) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $v));
        return substr($v, 0, 80);
    }

    // ── Phase 3: Signal normalization + scoring + dedup ────────────────────────

    /**
     * Normalize a Signal payload + compute canonical_signal_hash, and fill any
     * missing quality scores via deterministic heuristics.
     * @return array data ready for create_record('signal', …)
     */
    public static function normalize_signal(array $data): array {
        $out = $data;
        $workspace_id = max(0, (int) ($data['workspace_id'] ?? 0));
        $out['signal_type'] = self::sanitize_key((string) ($data['signal_type'] ?? 'other'), 40);
        $source_id = (int) ($data['source_id'] ?? 0);
        $title = self::sanitize_text((string) ($data['title'] ?? ''), 255);
        $value = isset($data['value_number']) && $data['value_number'] !== '' ? (string) round((float) $data['value_number'], 4)
            : self::sanitize_text((string) ($data['value_text'] ?? ''), 120);
        $occurred = self::sanitize_datetime($data['occurred_at'] ?? null);

        if (empty($data['canonical_signal_hash'])) {
            $basis = implode('|', [$workspace_id, $source_id, $out['signal_type'], strtolower($title), strtolower($value), (string) $occurred]);
            $out['canonical_signal_hash'] = hash('sha256', $basis);
        }

        // Deterministic scores (no LLM): fill any not explicitly provided.
        $scores = self::score_signal_quality($out, is_array($data['source'] ?? null) ? $data['source'] : []);
        foreach (['confidence_score', 'freshness_score', 'recurrence_score', 'specificity_score'] as $sk) {
            if (!isset($data[$sk]) || $data[$sk] === '') { $out[$sk] = $scores[$sk]; }
        }

        // Provenance metadata.
        $prov = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        foreach (['source_provider', 'extraction_method', 'extractor_model', 'source_hash',
                  'evidence_id', 'usage_event_id', 'cost_event_id', 'dedup_group_id'] as $pk) {
            if (isset($data[$pk]) && !isset($prov[$pk])) { $prov[$pk] = $data[$pk]; }
        }
        if (!isset($prov['canonical_signal_hash'])) { $prov['canonical_signal_hash'] = $out['canonical_signal_hash']; }
        $prov['final_quality_score'] = $scores['final_quality_score'];
        $out['metadata'] = $prov;
        return $out;
    }

    public static function find_signal_by_hash(int $workspace_id, string $canonical_signal_hash) {
        global $wpdb;
        if ($workspace_id <= 0 || $canonical_signal_hash === '' || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name('signal') . ' WHERE workspace_id = %d AND canonical_signal_hash = %s ORDER BY id ASC LIMIT 1',
            $workspace_id, $canonical_signal_hash
        ));
    }

    /**
     * Create a Signal, or — when an identical signal already exists in the workspace
     * — bump its recurrence_count/recurrence_score + last_seen_at and return the
     * existing id (never overwriting the original text).
     * @return int|WP_Error
     */
    public static function create_or_get_signal(array $data) {
        $norm = self::normalize_signal($data);
        $workspace_id = max(0, (int) ($norm['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return new WP_Error('ves_intel_missing_workspace', 'workspace_id is required for tenant isolation.'); }
        $existing = self::find_signal_by_hash($workspace_id, (string) ($norm['canonical_signal_hash'] ?? ''));
        if ($existing > 0) {
            self::bump_signal_recurrence($existing);
            return $existing;
        }
        return self::create_record('signal', $norm);
    }

    private static function bump_signal_recurrence(int $signal_id): void {
        global $wpdb;
        if ($signal_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) { return; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT recurrence_count, recurrence_score FROM ' . self::table_name('signal') . ' WHERE id = %d', $signal_id), ARRAY_A);
        if (!is_array($row)) { return; }
        $count = max(1, (int) ($row['recurrence_count'] ?? 1)) + 1;
        // Recurrence score saturates toward 100 with more sightings (deterministic curve).
        $score = self::clamp_score(min(100, 40 + ($count - 1) * 15));
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $wpdb->update(self::table_name('signal'),
            ['recurrence_count' => $count, 'recurrence_score' => $score, 'last_seen_at' => $now, 'updated_at' => $now],
            ['id' => $signal_id], ['%d', '%f', '%s', '%s'], ['%d']);
    }

    /**
     * Deterministic (no-LLM) signal quality heuristics. Scores are 0–100.
     * @return array{confidence_score:float,freshness_score:float,specificity_score:float,recurrence_score:float,final_quality_score:float,reason:string}
     */
    public static function score_signal_quality(array $signal, array $source = []): array {
        $reasons = [];

        // Freshness: decays from 100 (today) to ~0 over ~90 days.
        $when = self::sanitize_datetime($signal['occurred_at'] ?? ($source['fetched_at'] ?? null));
        $freshness = 50.0;
        if ($when) {
            $age_days = max(0, (time() - strtotime($when . ' UTC')) / 86400);
            $freshness = self::clamp_score(100 - ($age_days / 90) * 100);
            $reasons[] = 'age=' . round($age_days, 1) . 'd';
        }

        // Specificity: rewards concrete signals (url, external id, numeric value, source link).
        $specificity = 20.0;
        if (!empty($signal['source_id'])) { $specificity += 25; }
        if (isset($signal['value_number']) && (float) $signal['value_number'] !== 0.0) { $specificity += 25; }
        if (!empty($source['source_url']) || !empty($signal['metadata']['canonical_url'])) { $specificity += 15; }
        if (!empty($signal['metadata']['evidence_id'])) { $specificity += 15; }
        $specificity = self::clamp_score($specificity);

        // Confidence: provider/source-type credibility + metadata completeness.
        $confidence = 40.0;
        $provider = strtolower((string) ($source['provider'] ?? ($signal['metadata']['source_provider'] ?? '')));
        $trusted = ['google', 'reddit', 'apify', 'openai'];
        if (in_array($provider, $trusted, true)) { $confidence += 20; }
        if (!empty($source['credibility_score'])) { $confidence = max($confidence, (float) $source['credibility_score']); }
        if (!empty($signal['metadata']['extraction_method'])) { $confidence += 10; }
        if (!empty($signal['source_id'])) { $confidence += 10; }
        $confidence = self::clamp_score($confidence);

        // Recurrence: from an explicit sighting count when available.
        $rc = max(1, (int) ($signal['recurrence_count'] ?? ($signal['metadata']['recurrence_count'] ?? 1)));
        $recurrence = self::clamp_score(min(100, 40 + ($rc - 1) * 15));

        $final = self::clamp_score(0.35 * $confidence + 0.25 * $specificity + 0.25 * $freshness + 0.15 * $recurrence);
        return [
            'confidence_score'    => $confidence,
            'freshness_score'     => $freshness,
            'specificity_score'   => $specificity,
            'recurrence_score'    => $recurrence,
            'final_quality_score' => $final,
            'reason'              => implode(',', $reasons),
        ];
    }

    private static function touch_last_seen(string $entity, int $id): void {
        global $wpdb;
        if ($id <= 0 || !in_array($entity, self::entities(), true) || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'update')) { return; }
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $wpdb->update(self::table_name($entity), ['last_seen_at' => $now, 'updated_at' => $now], ['id' => $id], ['%s', '%s'], ['%d']);
    }

    private static function normalize_provider(string $provider): string {
        $p = strtolower(trim($provider));
        $map = [
            'gpt' => 'openai', 'chatgpt' => 'openai', 'oai' => 'openai',
            'apify.com' => 'apify', 'ig' => 'instagram', 'x' => 'other', 'twitter' => 'other',
            'youtube' => 'other', 'yt' => 'other', 'news' => 'google', 'google_news' => 'google',
            'google_trends' => 'google', 'serp' => 'google',
        ];
        $p = $map[$p] ?? $p;
        $allowed = self::enums()['provider'];
        return in_array($p, $allowed, true) ? $p : 'other';
    }

    private static function normalize_locale(string $v): string {
        $v = strtolower(trim($v));
        $v = preg_replace('/[^a-z0-9_\-]/', '', $v);
        return substr((string) $v, 0, 16);
    }

    /**
     * Memory adapter (B2/B7): prefer the existing VES_Memory_Records store; fall back
     * to the canonical ves_intel_memory table only when it is unavailable.
     * @return int|WP_Error
     */
    public static function create_memory_record(array $d) {
        $workspace_id = max(0, (int) ($d['workspace_id'] ?? 0));
        if ($workspace_id <= 0) {
            return new WP_Error('ves_intel_missing_workspace', 'workspace_id is required for tenant isolation.');
        }
        $enums = self::enums();
        $memory_type = (string) ($d['memory_type'] ?? 'other');
        if ($memory_type !== '' && !in_array($memory_type, $enums['memory_type'], true)) {
            return new WP_Error('ves_intel_invalid_enum', "Invalid memory_type '{$memory_type}'.");
        }
        $status = (string) ($d['status'] ?? 'active');
        if ($status !== '' && !in_array($status, $enums['memory_status'], true)) {
            return new WP_Error('ves_intel_invalid_enum', "Invalid memory status '{$status}'.");
        }
        $text = (string) ($d['text'] ?? '');
        if ($text === '') {
            return new WP_Error('ves_intel_missing_field', "Missing required field 'text' for memory.");
        }

        // Preferred path: extend the existing memory store.
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'save_record')) {
            $id = (int) VES_Memory_Records::save_record([
                'workspace_id'     => $workspace_id,
                'user_id'          => max(0, (int) ($d['user_id'] ?? 0)),
                'memory_type'      => $memory_type,
                'title'            => self::sanitize_text((string) ($d['title'] ?? 'Memory record'), 240),
                'summary'          => $text,
                'source_type'      => self::sanitize_key((string) ($d['source_entity_type'] ?? 'manual'), 40),
                'source_id'        => self::sanitize_text((string) ($d['source_entity_id'] ?? ''), 120),
                'importance_score' => self::clamp_score($d['importance_score'] ?? 50),
                // Status has no column in VES_Memory_Records; carry canonical state in content.
                'content'          => ['_canonical_status' => $status, '_canonical_memory_type' => $memory_type],
            ]);
            if ($id <= 0) { return new WP_Error('ves_intel_memory_failed', 'Memory record could not be saved.'); }
            return $id;
        }

        // Fallback path: canonical table.
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_intel_no_db', 'Database unavailable.'); }
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert(self::memory_fallback_table(), [
            'workspace_id'       => $workspace_id,
            'memory_type'        => $memory_type !== '' ? $memory_type : 'other',
            'text'               => self::sanitize_longtext($text),
            'importance_score'   => self::clamp_score($d['importance_score'] ?? 50),
            'source_entity_type' => self::sanitize_key((string) ($d['source_entity_type'] ?? ''), 40),
            'source_entity_id'   => self::sanitize_text((string) ($d['source_entity_id'] ?? ''), 120),
            'status'             => $status !== '' ? $status : 'active',
            'metadata'           => self::prepare_metadata($d['metadata'] ?? []),
            'created_at'         => $now,
            'updated_at'         => $now,
        ], ['%d','%s','%s','%f','%s','%s','%s','%s','%s','%s']);
        if ($ok === false) { return new WP_Error('ves_intel_memory_failed', 'Memory record could not be saved.'); }
        return (int) $wpdb->insert_id;
    }

    /**
     * Read one memory record, normalized to the canonical memory shape.
     * Delegates to the existing VES_Memory_Records table when present, else the
     * canonical fallback table. Returns null when not found (existing store convention).
     */
    public static function get_memory_record(int $id) {
        global $wpdb;
        if ($id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) { return null; }
        $from_existing = class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'table_name');
        $table = $from_existing ? VES_Memory_Records::table_name() : self::memory_fallback_table();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? self::normalize_memory_row($row, $from_existing) : null;
    }

    /**
     * List memory records, normalized to the canonical memory shape.
     * Supported filters: workspace_id, memory_type, status, source_entity_type,
     * source_entity_id, limit, offset. Real columns are filtered in SQL; status —
     * which the existing store keeps in content, not a column — is filtered in PHP
     * for the delegated path and in SQL for the fallback table. Tenant isolation
     * (workspace_id) is always honored.
     */
    public static function list_memory_records(array $filters = []): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $from_existing = class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'table_name');
        $table = $from_existing ? VES_Memory_Records::table_name() : self::memory_fallback_table();

        $limit  = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $where = '1=1'; $params = [];
        if (isset($filters['workspace_id'])) { $where .= ' AND workspace_id = %d'; $params[] = (int) $filters['workspace_id']; }
        if (!empty($filters['memory_type'])) { $where .= ' AND memory_type = %s'; $params[] = self::sanitize_key((string) $filters['memory_type'], 40); }

        // Source-entity columns differ by backing store.
        $src_type_col = $from_existing ? 'source_type' : 'source_entity_type';
        $src_id_col   = $from_existing ? 'source_id' : 'source_entity_id';
        if (!empty($filters['source_entity_type'])) { $where .= " AND {$src_type_col} = %s"; $params[] = self::sanitize_key((string) $filters['source_entity_type'], 40); }
        if (!empty($filters['source_entity_id']))   { $where .= " AND {$src_id_col} = %s"; $params[] = self::sanitize_text((string) $filters['source_entity_id'], 120); }

        // status: real column on the fallback table; in content on the existing store.
        $status_filter = !empty($filters['status']) ? self::sanitize_key((string) $filters['status'], 24) : '';
        $php_status_filter = '';
        if ($status_filter !== '') {
            if ($from_existing) { $php_status_filter = $status_filter; }
            else { $where .= ' AND status = %s'; $params[] = $status_filter; }
        }
        // Over-fetch a little when post-filtering status in PHP so limit is still met.
        $fetch = $php_status_filter !== '' ? min(200, ($limit + $offset) * 3 + $limit) : $limit;

        $sql = 'SELECT * FROM ' . $table . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $fetch; $params[] = $php_status_filter !== '' ? 0 : $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $out = [];
        foreach ($rows as $row) {
            $norm = self::normalize_memory_row($row, $from_existing);
            if ($php_status_filter !== '' && ($norm['status'] ?? '') !== $php_status_filter) { continue; }
            $out[] = $norm;
        }
        if ($php_status_filter !== '') { $out = array_slice($out, $offset, $limit); }
        return $out;
    }

    /** Map a raw memory row (either backing store) to the canonical memory shape. */
    private static function normalize_memory_row(array $row, bool $from_existing): array {
        if ($from_existing) {
            $content = json_decode((string) ($row['content_json'] ?? ''), true);
            $content = is_array($content) ? $content : [];
            $status = (string) ($content['_canonical_status'] ?? 'active');
            return [
                'id'                 => (int) ($row['id'] ?? 0),
                'workspace_id'       => (int) ($row['workspace_id'] ?? 0),
                'memory_type'        => (string) ($row['memory_type'] ?? ''),
                'text'               => (string) ($row['summary'] ?? ''),
                'importance_score'   => (float) ($row['importance_score'] ?? 0),
                'source_entity_type' => (string) ($row['source_type'] ?? ''),
                'source_entity_id'   => (string) ($row['source_id'] ?? ''),
                'status'             => $status,
                'created_at'         => (string) ($row['created_at'] ?? ''),
                'updated_at'         => (string) ($row['updated_at'] ?? ''),
                'backing'            => 'memory_records',
            ];
        }
        $meta = json_decode((string) ($row['metadata'] ?? ''), true);
        return [
            'id'                 => (int) ($row['id'] ?? 0),
            'workspace_id'       => (int) ($row['workspace_id'] ?? 0),
            'memory_type'        => (string) ($row['memory_type'] ?? ''),
            'text'               => (string) ($row['text'] ?? ''),
            'importance_score'   => (float) ($row['importance_score'] ?? 0),
            'source_entity_type' => (string) ($row['source_entity_type'] ?? ''),
            'source_entity_id'   => (string) ($row['source_entity_id'] ?? ''),
            'status'             => (string) ($row['status'] ?? 'active'),
            'created_at'         => (string) ($row['created_at'] ?? ''),
            'updated_at'         => (string) ($row['updated_at'] ?? ''),
            'metadata'           => is_array($meta) ? $meta : [],
            'backing'            => 'intel_memory',
        ];
    }

    /**
     * Projector (B6): write a normalized Source→Signal→Evidence→Insight→Brief→Draft
     * bundle in one call, wiring the links automatically. Designed to be called from
     * existing flows additively — it NEVER throws and collects per-record errors so a
     * mapping problem can never break the user-visible response.
     *
     * @param array $bundle {
     *   @type int    $workspace_id  (required)
     *   @type int    $user_id
     *   @type array  $source        single source payload
     *   @type array  $signals       list of signal payloads
     *   @type array  $evidence      list of evidence payloads
     *   @type array  $insight       single insight payload (evidence/signal auto-linked)
     *   @type array  $brief         single brief payload
     *   @type array  $draft         single draft payload
     * }
     * @return array created ids + 'errors'
     */
    public static function ingest_bundle(array $bundle): array {
        $out = ['source_id' => 0, 'signal_ids' => [], 'evidence_ids' => [], 'insight_id' => 0, 'brief_id' => 0, 'draft_id' => 0, 'errors' => []];
        $ws = max(0, (int) ($bundle['workspace_id'] ?? 0));
        if ($ws <= 0) { $out['errors'][] = 'missing_workspace'; return $out; }
        $with_ws = function (array $d) use ($ws, $bundle) {
            $d['workspace_id'] = $ws;
            if (!isset($d['user_id']) && isset($bundle['user_id'])) { $d['user_id'] = $bundle['user_id']; }
            return $d;
        };
        $collect = function ($res, $label) use (&$out) {
            if ($res instanceof WP_Error) { $out['errors'][] = $label . ':' . $res->get_error_code(); return 0; }
            return (int) $res;
        };

        try {
            // Phase 3B Part B: a bundle-level usage_event_id backlinks every record to
            // the usage event that produced/fetched it (when the caller knows it).
            $usage_event_id = max(0, (int) ($bundle['usage_event_id'] ?? 0));
            $inject_usage = function (array $rec) use ($usage_event_id) {
                if ($usage_event_id > 0 && !isset($rec['usage_event_id'])) { $rec['usage_event_id'] = $usage_event_id; }
                return $rec;
            };

            if (!empty($bundle['source']) && is_array($bundle['source'])) {
                // Phase 3: dedup sources across runs by canonical/raw hash.
                $out['source_id'] = $collect(self::create_or_get_source($with_ws($inject_usage($bundle['source']))), 'source');
            }
            $source_provider = is_array($bundle['source'] ?? null) ? (string) ($bundle['source']['provider'] ?? '') : '';
            foreach ((array) ($bundle['signals'] ?? []) as $sig) {
                if (!is_array($sig)) { continue; }
                if ($out['source_id'] && !isset($sig['source_id'])) { $sig['source_id'] = $out['source_id']; }
                if ($source_provider !== '' && !isset($sig['source_provider'])) { $sig['source_provider'] = $source_provider; }
                // Phase 3: dedup recurring signals; bumps recurrence on repeat sightings.
                $id = $collect(self::create_or_get_signal($with_ws($inject_usage($sig))), 'signal');
                if ($id) { $out['signal_ids'][] = $id; }
            }
            foreach ((array) ($bundle['evidence'] ?? []) as $evi) {
                if (!is_array($evi)) { continue; }
                if ($out['source_id'] && !isset($evi['source_id'])) { $evi['source_id'] = $out['source_id']; }
                if (!empty($out['signal_ids']) && !isset($evi['signal_id'])) { $evi['signal_id'] = $out['signal_ids'][0]; }
                if ($usage_event_id > 0) {
                    $em = is_array($evi['metadata'] ?? null) ? $evi['metadata'] : [];
                    if (!isset($em['usage_event_id'])) { $em['usage_event_id'] = $usage_event_id; }
                    $evi['metadata'] = $em;
                }
                $id = $collect(self::create_evidence($with_ws($evi)), 'evidence');
                if ($id) { $out['evidence_ids'][] = $id; }
            }
            if (!empty($bundle['insight']) && is_array($bundle['insight'])) {
                $ins = $bundle['insight'];
                if (!isset($ins['evidence_ids']) && !empty($out['evidence_ids'])) { $ins['evidence_ids'] = $out['evidence_ids']; }
                if (!isset($ins['signal_ids']) && !empty($out['signal_ids'])) { $ins['signal_ids'] = $out['signal_ids']; }
                $out['insight_id'] = $collect(self::create_insight($with_ws($ins)), 'insight');
            }
            if (!empty($bundle['brief']) && is_array($bundle['brief'])) {
                $br = $bundle['brief'];
                if ($out['insight_id'] && !isset($br['insight_id'])) { $br['insight_id'] = $out['insight_id']; }
                if (!isset($br['evidence_ids']) && !empty($out['evidence_ids'])) { $br['evidence_ids'] = $out['evidence_ids']; }
                $out['brief_id'] = $collect(self::create_brief($with_ws($br)), 'brief');
            }
            if (!empty($bundle['draft']) && is_array($bundle['draft'])) {
                $dr = $bundle['draft'];
                if ($out['brief_id'] && !isset($dr['brief_id'])) { $dr['brief_id'] = $out['brief_id']; }
                if (!isset($dr['evidence_ids']) && !empty($out['evidence_ids'])) { $dr['evidence_ids'] = $out['evidence_ids']; }
                $out['draft_id'] = $collect(self::create_draft($with_ws($dr)), 'draft');
            }
        } catch (\Throwable $e) {
            $out['errors'][] = 'exception:' . $e->getCode();
        }
        return $out;
    }

    // ── Read API ──────────────────────────────────────────────────────────────

    public static function get(string $entity, int $id) {
        global $wpdb;
        if (!in_array($entity, self::entities(), true) || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name($entity) . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? self::decode_row($row) : null;
    }
    public static function get_source(int $id)   { return self::get('source', $id); }
    public static function get_signal(int $id)   { return self::get('signal', $id); }
    public static function get_evidence(int $id) { return self::get('evidence', $id); }
    public static function get_insight(int $id)  { return self::get('insight', $id); }
    public static function get_brief(int $id)    { return self::get('brief', $id); }
    public static function get_draft(int $id)    { return self::get('draft', $id); }

    public static function list(string $entity, array $filters = []): array {
        global $wpdb;
        if (!in_array($entity, self::entities(), true) || !isset($wpdb) || !is_object($wpdb)) { return []; }
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $where = '1=1'; $params = [];
        if (isset($filters['workspace_id'])) { $where .= ' AND workspace_id = %d'; $params[] = (int) $filters['workspace_id']; }
        if (!empty($filters['status'])) {
            // Only entities with a status column may be filtered on it; ignore safely otherwise.
            if (self::supports_status($entity)) {
                $where .= ' AND status = %s'; $params[] = self::sanitize_key((string) $filters['status'], 24);
            } elseif (class_exists('VES_Log')) {
                VES_Log::debug('intelligence_store', 'status_filter_ignored', ['module' => 'intelligence_store', 'operation_type' => $entity]);
            }
        }
        if (!empty($filters['type'])) {
            $type_col = $entity . '_type';
            $where .= " AND {$type_col} = %s"; $params[] = self::sanitize_key((string) $filters['type'], 40);
        }
        $sql = 'SELECT * FROM ' . self::table_name($entity) . " WHERE {$where} ORDER BY id DESC LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        return array_map([__CLASS__, 'decode_row'], $rows);
    }
    public static function list_sources(array $f = [])   { return self::list('source', $f); }
    public static function list_signals(array $f = [])   { return self::list('signal', $f); }
    public static function list_evidence(array $f = [])  { return self::list('evidence', $f); }
    public static function list_insights(array $f = [])  { return self::list('insight', $f); }
    public static function list_briefs(array $f = [])    { return self::list('brief', $f); }
    public static function list_drafts(array $f = [])     { return self::list('draft', $f); }

    // ── Link helpers (JSON arrays) ────────────────────────────────────────────

    public static function link_evidence_to_insight(int $evidence_id, int $insight_id) {
        return self::append_json_id('insight', $insight_id, 'evidence_ids_json', $evidence_id);
    }
    public static function link_signal_to_insight(int $signal_id, int $insight_id) {
        return self::append_json_id('insight', $insight_id, 'signal_ids_json', $signal_id);
    }
    public static function link_evidence_to_brief(int $evidence_id, int $brief_id) {
        return self::append_json_id('brief', $brief_id, 'evidence_ids_json', $evidence_id);
    }
    public static function link_evidence_to_draft(int $evidence_id, int $draft_id) {
        return self::append_json_id('draft', $draft_id, 'evidence_ids_json', $evidence_id);
    }

    private static function append_json_id(string $entity, int $id, string $column, int $value) {
        global $wpdb;
        if ($id <= 0 || $value <= 0) { return new WP_Error('ves_intel_bad_link', 'Invalid link ids.'); }
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_intel_no_db', 'Database unavailable.'); }
        $current = $wpdb->get_var($wpdb->prepare('SELECT ' . $column . ' FROM ' . self::table_name($entity) . ' WHERE id = %d', $id));
        if ($current === null) { return new WP_Error('ves_intel_not_found', ucfirst($entity) . ' not found.'); }
        $ids = self::decode_ids((string) $current);
        if (!in_array($value, $ids, true)) { $ids[] = $value; }
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update(self::table_name($entity),
            [$column => wp_json_encode(array_values($ids)), 'updated_at' => $now],
            ['id' => $id], ['%s', '%s'], ['%d']);
        return $updated === false ? new WP_Error('ves_intel_link_failed', 'Could not update link.') : true;
    }

    // ── Evidence enforcement (B5) ─────────────────────────────────────────────

    /**
     * @return array{has_evidence:bool,evidence_count:int,missing_reason:string,confidence:?float}
     */
    public static function validate_insight_evidence(int $insight_id): array {
        $insight = self::get_insight($insight_id);
        if (!is_array($insight)) {
            return ['has_evidence' => false, 'evidence_count' => 0, 'missing_reason' => 'insight_not_found', 'confidence' => null];
        }
        $ids = is_array($insight['evidence_ids'] ?? null) ? $insight['evidence_ids'] : self::decode_ids((string) ($insight['evidence_ids_json'] ?? '[]'));
        $count = count($ids);
        $status = (string) ($insight['status'] ?? 'draft');
        $missing = '';
        if ($count === 0) {
            $missing = in_array($status, ['reviewed', 'approved'], true) ? 'production_insight_without_evidence' : 'draft_without_evidence';
        }
        return [
            'has_evidence'   => $count > 0,
            'evidence_count' => $count,
            'missing_reason' => $missing,
            'confidence'     => isset($insight['confidence_score']) ? (float) $insight['confidence_score'] : null,
        ];
    }

    /**
     * v0.1 RC — insight lifecycle transition matrix. Key = current status, value =
     * statuses reachable WITHOUT an explicit override. Same-status writes are allowed
     * (idempotent retries / metadata refresh). rejected and archived are TERMINAL:
     * leaving them requires the explicit, audited reopen/restore override below —
     * rejected can never silently become approved, and approved can never silently
     * fall back to draft.
     */
    const INSIGHT_STATUS_TRANSITIONS = [
        'draft'    => ['draft', 'reviewed', 'approved', 'rejected', 'archived'],
        'reviewed' => ['reviewed', 'approved', 'rejected', 'archived'],
        'approved' => ['approved', 'archived'],
        'rejected' => ['rejected'],
        'archived' => ['archived'],
    ];

    /**
     * Pure transition check (no DB). $opts:
     *   - allow_reopen  (bool) permits ONLY rejected -> draft (back into review, never approved)
     *   - allow_restore (bool) permits ONLY archived -> draft (back into review, never approved)
     */
    public static function insight_transition_allowed(string $from, string $to, array $opts = []): bool {
        $from = self::sanitize_key($from, 24);
        $to   = self::sanitize_key($to, 24);
        $enum = self::enums()['insight_status'];
        if (!in_array($to, $enum, true)) { return false; }
        if (!in_array($from, $enum, true)) {
            // Unknown/legacy stored status: only allow moving into review states,
            // never directly into approved (mirrors "missing status is untrusted").
            return in_array($to, ['draft', 'reviewed', 'rejected', 'archived'], true);
        }
        $allowed = self::INSIGHT_STATUS_TRANSITIONS[$from] ?? [$from];
        if (in_array($to, $allowed, true)) { return true; }
        if ($from === 'rejected' && $to === 'draft' && !empty($opts['allow_reopen'])) { return true; }
        if ($from === 'archived' && $to === 'draft' && !empty($opts['allow_restore'])) { return true; }
        return false;
    }

    /** Readiness probe: true when the transition matrix is compiled in and enforcing. */
    public static function insight_transition_matrix_active(): bool {
        return self::insight_transition_allowed('draft', 'approved')
            && !self::insight_transition_allowed('rejected', 'approved')
            && !self::insight_transition_allowed('rejected', 'approved', ['allow_reopen' => true])
            && !self::insight_transition_allowed('archived', 'approved', ['allow_restore' => true])
            && !self::insight_transition_allowed('approved', 'draft');
    }

    /**
     * Phase 5 — update an insight's status (lifecycle). HARD EVIDENCE GATE: an
     * insight can never be moved to reviewed/approved without at least one linked
     * evidence id (the safety net for the create path's enforcement). v0.1 RC adds
     * the HARD TRANSITION MATRIX above: terminal states stay terminal unless the
     * caller passes the explicit reopen/restore override in $opts, and the override
     * only ever leads back to draft (review), never to approved. Updates only
     * status/updated_at and merges a bounded, scrubbed metadata patch; never touches
     * the evidence links or created_at. @return int|WP_Error
     */
    public static function update_insight_status(int $insight_id, string $status, array $metadata_merge = [], array $opts = []) {
        global $wpdb;
        $status = self::sanitize_key($status, 24);
        if (!in_array($status, self::enums()['insight_status'], true)) {
            return new WP_Error('ves_intel_invalid_enum', "Invalid insight status '{$status}'.");
        }
        $insight = self::get_insight($insight_id);
        if (!is_array($insight)) { return new WP_Error('ves_intel_not_found', 'Insight not found.'); }
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_intel_no_db', 'Database unavailable.'); }

        $current = self::sanitize_key((string) ($insight['status'] ?? 'draft'), 24);
        if (!self::insight_transition_allowed($current, $status, $opts)) {
            return new WP_Error('ves_intel_transition_blocked', "Insight status transition '{$current}' -> '{$status}' is not allowed.", ['from' => $current, 'to' => $status]);
        }

        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? $insight['evidence_ids'] : self::decode_ids((string) ($insight['evidence_ids_json'] ?? '[]'));
        if (in_array($status, ['reviewed', 'approved'], true) && count($evidence_ids) === 0) {
            return new WP_Error('ves_intel_evidence_required', 'Cannot promote an insight without evidence beyond draft.');
        }

        $meta = is_array($insight['metadata'] ?? null) ? $insight['metadata'] : [];
        foreach ($metadata_merge as $k => $v) { $meta[(string) $k] = $v; }
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $ok = $wpdb->update(self::table_name('insight'),
            ['status' => $status, 'metadata' => self::prepare_metadata($meta), 'updated_at' => $now],
            ['id' => (int) $insight_id], ['%s', '%s', '%s'], ['%d']);
        return $ok === false ? new WP_Error('ves_intel_update_failed', 'Could not update insight status.') : (int) $insight_id;
    }

    /**
     * Phase 5B — metadata-only merge on an insight (no status change, no evidence
     * touch). Used by batch reassessment's apply-metadata mode. Bounded + scrubbed.
     * @return int|WP_Error
     */
    public static function merge_insight_metadata(int $insight_id, array $metadata_merge) {
        global $wpdb;
        $insight = self::get_insight($insight_id);
        if (!is_array($insight)) { return new WP_Error('ves_intel_not_found', 'Insight not found.'); }
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_intel_no_db', 'Database unavailable.'); }
        $meta = is_array($insight['metadata'] ?? null) ? $insight['metadata'] : [];
        foreach ($metadata_merge as $k => $v) { $meta[(string) $k] = $v; }
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $ok = $wpdb->update(self::table_name('insight'),
            ['metadata' => self::prepare_metadata($meta), 'updated_at' => $now],
            ['id' => (int) $insight_id], ['%s', '%s'], ['%d']);
        return $ok === false ? new WP_Error('ves_intel_update_failed', 'Could not update insight metadata.') : (int) $insight_id;
    }

    /** Find an insight whose metadata references a given trend_record_id (dedup). 0 if none. */
    public static function find_insight_by_trend_record(int $workspace_id, int $trend_record_id): int {
        global $wpdb;
        if ($workspace_id <= 0 || $trend_record_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        $needle = '%"trend_record_id":' . (int) $trend_record_id . '%';
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name('insight') . " WHERE workspace_id = %d AND metadata LIKE %s ORDER BY id ASC LIMIT 1",
            $workspace_id, $needle
        ));
    }

    /** Find a brief whose metadata references a given source_insight_id (dedup). 0 if none. */
    public static function find_brief_by_insight(int $workspace_id, int $source_insight_id): int {
        global $wpdb;
        if ($workspace_id <= 0 || $source_insight_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        $needle = '%"source_insight_id":' . (int) $source_insight_id . '%';
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name('brief') . " WHERE workspace_id = %d AND metadata LIKE %s ORDER BY id ASC LIMIT 1",
            $workspace_id, $needle
        ));
    }

    /** Insight counts by status (admin diagnostics). */
    public static function count_insights_by_status(int $workspace_id = 0): array {
        return self::group_count('insight', 'status', $workspace_id);
    }

    /** Count insights with no evidence link (admin diagnostics). */
    public static function count_insights_missing_evidence(int $workspace_id = 0): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        $ws = $workspace_id > 0 ? ' AND workspace_id = ' . (int) $workspace_id : '';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name('insight') . " WHERE (evidence_ids_json IS NULL OR evidence_ids_json = '' OR evidence_ids_json = '[]'){$ws}");
    }

    // ── Diagnostics (B7) ──────────────────────────────────────────────────────

    /** Count by entity (global or per-workspace). Memory counted via the active backing store. */
    public static function counts(int $workspace_id = 0): array {
        global $wpdb;
        $out = [];
        if (!isset($wpdb) || !is_object($wpdb)) { return $out; }
        foreach (self::entities() as $entity) {
            $out[$entity . 's'] = self::count_table(self::table_name($entity), $workspace_id);
        }
        // Memory: prefer the existing store's table, else the canonical fallback.
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'table_name')) {
            $out['memory_records'] = self::count_table(VES_Memory_Records::table_name(), $workspace_id);
        } else {
            $out['memory_records'] = self::count_table(self::memory_fallback_table(), $workspace_id);
        }
        return $out;
    }

    private static function count_table(string $table, int $workspace_id): int {
        global $wpdb;
        if (!method_exists($wpdb, 'get_var')) { return 0; }
        if ($workspace_id > 0) {
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE workspace_id = %d', $workspace_id));
        }
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
    }

    // ── Phase 3: provenance diagnostics ────────────────────────────────────────

    /** GROUP BY count for a column on a canonical table (admin diagnostics). */
    private static function group_count(string $entity, string $column, int $workspace_id = 0, int $limit = 20): array {
        global $wpdb;
        if (!in_array($entity, self::entities(), true) || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $column = preg_replace('/[^a-z0-9_]/i', '', $column);
        $table = self::table_name($entity);
        $where = '1=1'; $params = [];
        if ($workspace_id > 0) { $where .= ' AND workspace_id = %d'; $params[] = $workspace_id; }
        $sql = "SELECT {$column} AS k, COUNT(*) AS c FROM {$table} WHERE {$where} GROUP BY {$column} ORDER BY c DESC LIMIT %d";
        $params[] = max(1, min(100, $limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) { $out[(string) ($r['k'] ?? '')] = (int) ($r['c'] ?? 0); }
        return $out;
    }

    /**
     * Phase 3B Part G — telemetry backlink health: how many source/signal/evidence
     * rows carry a usage_event_id in metadata vs not. Diagnostics-grade (LIKE on the
     * metadata JSON), workspace-scoped.
     */
    public static function backlink_health(int $workspace_id = 0): array {
        global $wpdb;
        $out = [
            'sources_total' => 0, 'sources_with_usage_event' => 0, 'sources_missing_usage_event' => 0,
            'signals_total' => 0, 'signals_with_usage_event' => 0, 'signals_missing_usage_event' => 0,
            'evidence_total' => 0, 'evidence_with_usage_event' => 0, 'evidence_missing_usage_event' => 0,
        ];
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return $out; }
        $ws = $workspace_id > 0 ? ' AND workspace_id = ' . (int) $workspace_id : '';
        foreach (['source' => 'sources', 'signal' => 'signals', 'evidence' => 'evidence'] as $entity => $prefix) {
            $table = self::table_name($entity);
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE 1=1{$ws}");
            $with = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE metadata LIKE '%usage_event_id%'{$ws}");
            $out[$prefix . '_total'] = $total;
            $out[$prefix . '_with_usage_event'] = $with;
            $out[$prefix . '_missing_usage_event'] = max(0, $total - $with);
        }
        return $out;
    }

    public static function provider_breakdown(int $workspace_id = 0): array { return self::group_count('source', 'provider', $workspace_id); }
    public static function signal_type_breakdown(int $workspace_id = 0): array { return self::group_count('signal', 'signal_type', $workspace_id); }

    /** Counts of records missing key provenance, for the admin health panel. */
    public static function provenance_health(int $workspace_id = 0): array {
        global $wpdb;
        $out = [
            'sources_missing_provider' => 0, 'sources_missing_hash' => 0,
            'signals_missing_source' => 0, 'evidence_missing_source' => 0, 'insights_missing_evidence' => 0,
        ];
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return $out; }
        $ws = $workspace_id > 0 ? ' AND workspace_id = ' . (int) $workspace_id : '';
        $src = self::table_name('source'); $sig = self::table_name('signal');
        $evi = self::table_name('evidence'); $ins = self::table_name('insight');
        $out['sources_missing_provider'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$src} WHERE (provider = '' OR provider = 'other'){$ws}");
        $out['sources_missing_hash']     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$src} WHERE canonical_hash = ''{$ws}");
        $out['signals_missing_source']   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sig} WHERE source_id = 0{$ws}");
        $out['evidence_missing_source']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$evi} WHERE source_id = 0{$ws}");
        $out['insights_missing_evidence'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ins} WHERE (evidence_ids_json IS NULL OR evidence_ids_json = '' OR evidence_ids_json = '[]'){$ws}");
        return $out;
    }

    /** Recent insights for the admin view (with evidence counts). */
    public static function recent_insights(int $limit = 10, int $workspace_id = 0): array {
        $filters = ['limit' => $limit];
        if ($workspace_id > 0) { $filters['workspace_id'] = $workspace_id; }
        $rows = self::list('insight', $filters);
        $out = [];
        foreach ($rows as $r) {
            $ids = is_array($r['evidence_ids'] ?? null) ? $r['evidence_ids'] : [];
            $out[] = [
                'id'             => (int) ($r['id'] ?? 0),
                'created_at'     => (string) ($r['created_at'] ?? ''),
                'insight_type'   => (string) ($r['insight_type'] ?? ''),
                'title'          => (string) ($r['title'] ?? ''),
                'status'         => (string) ($r['status'] ?? ''),
                'evidence_count' => count($ids),
                'confidence'     => (float) ($r['confidence_score'] ?? 0),
            ];
        }
        return $out;
    }

    // ── Coercion / sanitization helpers ───────────────────────────────────────

    /** @return array{0:mixed,1:string,2:?WP_Error} [value, wpdb_format, error] */
    private static function coerce_field(string $type, $raw, array $spec, string $entity, string $key): array {
        switch ($type) {
            case 'enum':
                $set = self::enums()[$spec['in']] ?? [];
                $val = is_string($raw) ? trim($raw) : '';
                if ($val === '') { $val = (string) ($spec['default'] ?? ''); }
                if ($val !== '' && !in_array($val, $set, true)) {
                    return [null, '%s', new WP_Error('ves_intel_invalid_enum', "Invalid value '{$val}' for {$entity}.{$key}.")];
                }
                return [$val, '%s', null];
            case 'text':
                return [self::sanitize_text((string) $raw, (int) ($spec['max'] ?? 190)), '%s', null];
            case 'key':
                return [self::sanitize_key((string) $raw, (int) ($spec['max'] ?? 32)), '%s', null];
            case 'longtext':
                return [self::sanitize_longtext((string) $raw), '%s', null];
            case 'url':
                $u = self::sanitize_url((string) $raw, (int) ($spec['max'] ?? 2048));
                return [$u, '%s', null];
            case 'hash':
                $h = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $raw));
                return [substr((string) $h, 0, (int) ($spec['max'] ?? 64)), '%s', null];
            case 'datetime':
                return [self::sanitize_datetime($raw), '%s', null];
            case 'idref':
                return [max(0, (int) $raw), '%d', null];
            case 'count':
                // Recurrence/occurrence counters default to 1 (first sighting).
                $c = ($raw === null || $raw === '') ? 1 : (int) $raw;
                return [max(1, $c), '%d', null];
            case 'score':
                return [self::clamp_score($raw), '%f', null];
            case 'number':
                return [round((float) $raw, 4), '%f', null];
            case 'json_ids':
                return [wp_json_encode(self::normalize_id_array($raw)), '%s', null];
            case 'json_obj':
                return [self::prepare_metadata(is_array($raw) ? $raw : []), '%s', null];
            default:
                return [self::sanitize_text((string) $raw, 190), '%s', null];
        }
    }

    private static function sanitize_text(string $v, int $max): string {
        $v = function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(strip_tags($v));
        return substr($v, 0, $max);
    }
    private static function sanitize_key(string $v, int $max): string {
        $v = function_exists('sanitize_key') ? sanitize_key($v) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $v));
        return substr($v, 0, $max);
    }
    private static function sanitize_longtext(string $v): string {
        $v = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($v) : trim(strip_tags($v));
        return substr($v, 0, self::MAX_LONGTEXT);
    }
    private static function sanitize_url(string $v, int $max): string {
        if ($v === '') { return ''; }
        $v = function_exists('esc_url_raw') ? esc_url_raw($v) : trim($v);
        return substr((string) $v, 0, $max);
    }
    private static function sanitize_datetime($v): ?string {
        if (!is_string($v) || $v === '') { return null; }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v)) {
            return strlen($v) === 10 ? $v . ' 00:00:00' : $v;
        }
        $ts = strtotime($v);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
    private static function clamp_score($v): float {
        $f = (float) $v;
        if ($f < 0) { $f = 0.0; }
        if ($f > 100) { $f = 100.0; }
        return round($f, 2);
    }
    private static function normalize_id_array($raw): array {
        if (is_string($raw)) { $raw = self::decode_ids($raw); }
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $v) { $i = (int) $v; if ($i > 0 && !in_array($i, $out, true)) { $out[] = $i; } }
        return array_values($out);
    }
    public static function decode_ids($json): array {
        if (is_array($json)) { return self::normalize_id_array($json); }
        $arr = json_decode((string) $json, true);
        return is_array($arr) ? self::normalize_id_array($arr) : [];
    }

    /** Decode JSON columns into arrays for callers. */
    private static function decode_row(array $row): array {
        foreach (['evidence_ids_json' => 'evidence_ids', 'signal_ids_json' => 'signal_ids'] as $col => $alias) {
            if (array_key_exists($col, $row)) { $row[$alias] = self::decode_ids((string) $row[$col]); }
        }
        if (array_key_exists('metadata', $row) && is_string($row['metadata'])) {
            $meta = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($meta) ? $meta : [];
        }
        return $row;
    }

    /** Build stored metadata JSON: drop forbidden keys, scrub secrets, bound size. */
    private static function prepare_metadata($metadata): string {
        $metadata = is_array($metadata) ? $metadata : [];
        $clean = [];
        foreach ($metadata as $key => $value) {
            $key_l = strtolower((string) $key);
            if (in_array($key_l, self::FORBIDDEN_META_KEYS, true)) { continue; }
            if (is_scalar($value) || $value === null) {
                $clean[$key_l] = is_string($value) ? self::scrub_secrets($value) : $value;
            } elseif (is_array($value)) {
                $flat = [];
                foreach ($value as $k2 => $v2) {
                    if (in_array(strtolower((string) $k2), self::FORBIDDEN_META_KEYS, true)) { continue; }
                    if (is_scalar($v2) || $v2 === null) {
                        $flat[strtolower((string) $k2)] = is_string($v2) ? self::scrub_secrets($v2) : $v2;
                    }
                }
                if (!empty($flat)) { $clean[$key_l] = $flat; }
            }
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($clean) : json_encode($clean);
        if (!is_string($json)) { $json = '{}'; }
        if (strlen($json) > self::MAX_META_BYTES) { $json = '{"truncated":true}'; }
        return $json;
    }

    public static function scrub_secrets(string $value): string {
        if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'scrub_secrets')) {
            return VES_AI_Usage_Tracker::scrub_secrets($value);
        }
        $patterns = [
            '/sk-[A-Za-z0-9_\-]{8,}/', '/sk_live_[A-Za-z0-9]{8,}/', '/whsec_[A-Za-z0-9]{8,}/',
            '/apify_api_[A-Za-z0-9]{8,}/', '/AIza[A-Za-z0-9_\-]{20,}/', '/Bearer\s+[A-Za-z0-9_\-.]+/i',
        ];
        return (string) preg_replace($patterns, '[redacted]', $value);
    }
}
