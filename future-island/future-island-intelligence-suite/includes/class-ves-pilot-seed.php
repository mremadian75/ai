<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Pilot_Seed — Phase 4 sample workspace for the controlled pilot.
 *
 * Seeds the three pilot scenarios as REAL loop objects (through the same
 * intake processors operators use), every title prefixed [DEMO], every
 * object id recorded in a per-workspace registry option. The scenarios are
 * staged at different depths so the pilot exercises every remaining step
 * live:
 *
 *   A — Competitor signal → campaign brief   (seeded through BRIEF + memory candidate)
 *   B — Cultural signal → brand opportunity  (seeded through APPROVED insight)
 *   C — AI-visibility gap → corrective brief (seeded through DRAFT insight — review it!)
 *
 * Reset deletes EXACTLY the registry-listed rows (plus demo preview usage
 * events and feedback on demo objects) — never a cascade, never non-demo
 * data. The append-only review ledger keeps its demo decision rows by
 * design (audit ledgers never lose entries); they are workspace-scoped and
 * reference [DEMO] objects only.
 *
 * No fake client names (bracketed placeholders only), no invented
 * performance claims, no external fetching.
 */
final class VES_Pilot_Seed {

    const REGISTRY_OPT_PREFIX = 'ves_pilot_seed_registry_';

    private static function err($code, $message) { return new WP_Error($code, $message); }
    private static function is_err($t) { return function_exists('is_wp_error') ? is_wp_error($t) : ($t instanceof WP_Error); }

    public static function registry_option($ws) { return self::REGISTRY_OPT_PREFIX . max(1, (int) $ws); }

    public static function status($ws) {
        $reg = function_exists('get_option') ? get_option(self::registry_option($ws), null) : null;
        if (!is_array($reg)) {
            return ['seeded' => false, 'scenarios' => [], 'counts' => []];
        }
        $counts = [];
        foreach (['source', 'signal', 'evidence', 'insight', 'brief', 'memory'] as $k) {
            $counts[$k] = count((array) ($reg[$k] ?? []));
        }
        return ['seeded' => true, 'scenarios' => (array) ($reg['scenarios'] ?? []), 'counts' => $counts, 'seeded_at' => (string) ($reg['seeded_at'] ?? '')];
    }

    /**
     * Seed the three scenarios. Refuses when a registry already exists —
     * reset first, so demo data can never silently pile up.
     * @return array|WP_Error
     */
    public static function seed($ws) {
        $ws = (int) $ws;
        if ($ws <= 0) { return self::err('ves_seed_workspace', 'A positive workspace id is required.'); }
        if (!class_exists('VES_Source_Intake') || !class_exists('VES_Intelligence_Store')) {
            return self::err('ves_seed_unavailable', 'Intake/store unavailable; cannot seed.');
        }
        $existing = function_exists('get_option') ? get_option(self::registry_option($ws), null) : null;
        if (is_array($existing)) {
            return self::err('ves_seed_already_seeded', 'This workspace already carries pilot demo data — reset it before seeding again.');
        }
        $reg = ['source' => [], 'signal' => [], 'evidence' => [], 'insight' => [], 'brief' => [], 'memory' => [], 'scenarios' => [], 'seeded_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s')];

        // ── Scenario A — Competitor signal → campaign brief (full trail) ─────
        $a = self::chain($ws, [
            'source' => ['intake_type' => 'url', 'source_url' => 'https://press.example/[competitor]-spring-launch', 'source_title' => '[DEMO] [Competitor] spring launch announcement', 'notes' => '[DEMO] Scenario A — competitor activity recorded as a URL reference (never fetched).'],
            'signal' => ['signal_type' => 'competitor_move', 'title' => '[DEMO] [Competitor] launches a craft-collab capsule line', 'summary' => '[DEMO] Announcement positions the capsule as artisan-led; pricing undisclosed at record time.', 'value_text' => 'capsule launch'],
            'insight' => ['insight_type' => 'risk', 'title' => '[DEMO] Capsule launch crowds our slow-craft positioning window', 'summary' => '[DEMO] The move overlaps the territory our audience associates with us; response timing matters more than scale.', 'recommendation' => '[DEMO] Stage a response brief anchored in our existing evidence, not a reactive copy.'],
        ]);
        if (self::is_err($a)) { return $a; }
        $approve = VES_Intelligence_Store::update_insight_status($a['insight_id'], 'approved', ['reviewed_via' => 'pilot_seed_demo']);
        if (self::is_err($approve)) { return $approve; }
        $brief = VES_Source_Intake::process_insight_to_brief(['workspace_id' => $ws, 'insight_id' => $a['insight_id']]);
        if (self::is_err($brief)) { return $brief; }
        $mem = VES_Source_Intake::process_memory_candidate(['workspace_id' => $ws, 'insight_id' => $a['insight_id']]);
        $reg['source'][] = $a['source_id']; $reg['signal'][] = $a['signal_id']; $reg['evidence'][] = $a['evidence_id']; $reg['insight'][] = $a['insight_id'];
        $reg['brief'][] = (int) $brief['brief_id'];
        if (is_array($mem)) { $reg['memory'][] = (int) $mem['memory_id']; }
        $reg['scenarios']['A'] = ['stage' => 'brief_built', 'insight_id' => $a['insight_id'], 'brief_id' => (int) $brief['brief_id']];

        // ── Scenario B — Cultural signal → brand opportunity (approved insight) ─
        $b = self::chain($ws, [
            'source' => ['intake_type' => 'manual', 'source_title' => '[DEMO] Field note — slow-craft meetup observation', 'notes' => '[DEMO] Scenario B — operator field note: attendees photographed process steps far more than finished pieces.'],
            'signal' => ['signal_type' => 'audience_signal', 'title' => '[DEMO] Audience documents PROCESS, not product', 'summary' => '[DEMO] Repeated at two meetups; process shots shared with longer captions than product shots.', 'value_text' => 'process > product'],
            'insight' => ['insight_type' => 'opportunity', 'opportunity_score' => 70, 'title' => '[DEMO] Process-first storytelling is an open lane', 'summary' => '[DEMO] The audience already narrates process; a process-led series meets existing behavior instead of inventing one.', 'recommendation' => '[DEMO] Brief a process-led content route before the lane crowds.'],
        ]);
        if (self::is_err($b)) { return $b; }
        $approve = VES_Intelligence_Store::update_insight_status($b['insight_id'], 'approved', ['reviewed_via' => 'pilot_seed_demo']);
        if (self::is_err($approve)) { return $approve; }
        $reg['source'][] = $b['source_id']; $reg['signal'][] = $b['signal_id']; $reg['evidence'][] = $b['evidence_id']; $reg['insight'][] = $b['insight_id'];
        $reg['scenarios']['B'] = ['stage' => 'insight_approved', 'insight_id' => $b['insight_id']];

        // ── Scenario C — AI-visibility gap → corrective brief (draft insight) ──
        $c = self::chain($ws, [
            'source' => ['intake_type' => 'manual', 'source_title' => '[DEMO] AI-answer check — brand absent from category answers', 'notes' => '[DEMO] Scenario C — manual observation: assistant answers about the category cite competitors, never us. Recorded by hand; no scraping.'],
            'signal' => ['signal_type' => 'ai_visibility', 'title' => '[DEMO] Brand missing from AI category answers', 'summary' => '[DEMO] Three category-level questions answered without mentioning the brand; one cited [Competitor] by name.', 'value_text' => '0/3 mentions'],
            'insight' => ['insight_type' => 'aeo', 'title' => '[DEMO] Category answers route around us — entity gap likely', 'summary' => '[DEMO] Our authority content does not connect the brand to the category language assistants use.', 'recommendation' => '[DEMO] Corrective content/entity brief; review this draft insight to proceed.'],
        ]);
        if (self::is_err($c)) { return $c; }
        $reg['source'][] = $c['source_id']; $reg['signal'][] = $c['signal_id']; $reg['evidence'][] = $c['evidence_id']; $reg['insight'][] = $c['insight_id'];
        $reg['scenarios']['C'] = ['stage' => 'insight_draft', 'insight_id' => $c['insight_id']];

        if (function_exists('update_option')) { update_option(self::registry_option($ws), $reg, false); }
        return $reg;
    }

    /** One source → signal → evidence+insight chain through the real intake processors. */
    private static function chain($ws, array $spec) {
        $src = VES_Source_Intake::process_source(array_merge(['workspace_id' => $ws], $spec['source']));
        if (self::is_err($src)) { return $src; }
        $sig = VES_Source_Intake::process_signal(array_merge(['workspace_id' => $ws, 'source_id' => (int) $src['source_id']], $spec['signal']));
        if (self::is_err($sig)) { return $sig; }
        $ins = VES_Source_Intake::process_signal_to_insight(array_merge(['workspace_id' => $ws, 'signal_id' => (int) $sig['signal_id']], $spec['insight']));
        if (self::is_err($ins)) { return $ins; }
        return ['source_id' => (int) $src['source_id'], 'signal_id' => (int) $sig['signal_id'], 'evidence_id' => (int) $ins['evidence_id'], 'insight_id' => (int) $ins['insight_id']];
    }

    /**
     * Remove EXACTLY the seeded rows: registry ids per entity table, demo
     * memory candidates, preview usage events for demo briefs, and feedback
     * on demo objects. Returns per-entity deletion counts.
     * @return array|WP_Error
     */
    public static function reset($ws) {
        global $wpdb;
        $ws = (int) $ws;
        if ($ws <= 0) { return self::err('ves_seed_workspace', 'A positive workspace id is required.'); }
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'delete')) {
            return self::err('ves_seed_no_db', 'Database unavailable.');
        }
        $reg = function_exists('get_option') ? get_option(self::registry_option($ws), null) : null;
        if (!is_array($reg)) { return self::err('ves_seed_not_seeded', 'No pilot demo data is registered for this workspace.'); }

        $deleted = ['source' => 0, 'signal' => 0, 'evidence' => 0, 'insight' => 0, 'brief' => 0, 'memory' => 0, 'usage_events' => 0, 'feedback' => 0];
        $entity_tables = [];
        if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'table_name')) {
            foreach (['source', 'signal', 'evidence', 'insight', 'brief'] as $entity) {
                $entity_tables[$entity] = VES_Intelligence_Store::table_name($entity);
            }
        }
        foreach ($entity_tables as $entity => $table) {
            foreach ((array) ($reg[$entity] ?? []) as $id) {
                $deleted[$entity] += (int) $wpdb->delete($table, ['id' => (int) $id, 'workspace_id' => $ws], ['%d', '%d']);
            }
        }
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'table_name')) {
            foreach ((array) ($reg['memory'] ?? []) as $id) {
                $deleted['memory'] += (int) $wpdb->delete(VES_Memory_Records::table_name(), ['id' => (int) $id, 'workspace_id' => $ws], ['%d', '%d']);
            }
        }
        // Demo preview usage events: run_id is deterministic per brief id.
        if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'table_name') && method_exists($wpdb, 'query')) {
            foreach ((array) ($reg['brief'] ?? []) as $bid) {
                $deleted['usage_events'] += (int) $wpdb->query($wpdb->prepare(
                    'DELETE FROM ' . VES_AI_Usage_Tracker::table_name() . ' WHERE workspace_id = %d AND operation_type = %s AND run_id LIKE %s',
                    $ws, 'prompt_preview', 'preview-brief-' . (int) $bid . '-u%'
                ));
            }
        }
        // Feedback on demo objects.
        if (class_exists('VES_Pilot_Feedback') && method_exists('VES_Pilot_Feedback', 'table_name')) {
            $map = ['insight' => 'insight', 'brief' => 'brief'];
            foreach ($map as $reg_key => $type) {
                foreach ((array) ($reg[$reg_key] ?? []) as $id) {
                    $deleted['feedback'] += (int) $wpdb->delete(VES_Pilot_Feedback::table_name(), ['workspace_id' => $ws, 'object_type' => $type, 'object_id' => (int) $id], ['%d', '%s', '%d']);
                }
            }
            foreach ((array) ($reg['brief'] ?? []) as $id) {
                $deleted['feedback'] += (int) $wpdb->delete(VES_Pilot_Feedback::table_name(), ['workspace_id' => $ws, 'object_type' => 'prompt_preview', 'object_id' => (int) $id], ['%d', '%s', '%d']);
            }
        }
        if (function_exists('delete_option')) { delete_option(self::registry_option($ws)); }
        elseif (function_exists('update_option')) { update_option(self::registry_option($ws), null, false); }
        return $deleted;
    }
}
