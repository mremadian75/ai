<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Pilot_Feedback — Phase 4 pilot learning capture.
 *
 * One small, additive table of operator feedback tied to a loop object
 * (insight / brief / prompt preview / memory record): rating, decision,
 * comment, evidence note, next action. Workspace- and user-aware,
 * timestamped, sanitized, reviewable on the Pilot Readiness page.
 *
 * Deliberately NOT analytics: no aggregation pipeline, no dashboards —
 * pilot learning only. Never mixed into the usage/credits ledger.
 */
final class VES_Pilot_Feedback {

    const TABLE_SLUG  = 'ves_pilot_feedback';
    const VERSION_OPT = 'ves_pilot_feedback_db_version';
    const DB_VERSION  = '1.0.0';
    const ACTION      = 'ves_pilot_feedback';

    const OBJECT_TYPES = ['insight', 'brief', 'prompt_preview', 'memory_record'];
    const DECISIONS    = ['accepted', 'reworked', 'rejected', 'blocked', 'none'];

    public static function register() {
        if (!function_exists('add_action')) { return; }
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function table_name() {
        global $wpdb;
        return (isset($wpdb) && is_object($wpdb) ? $wpdb->prefix : 'wp_') . self::TABLE_SLUG;
    }

    /** Idempotent, additive installer (dbDelta). */
    public static function create_table($force = false) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = 'CREATE TABLE ' . self::table_name() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(24) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rating tinyint unsigned NOT NULL DEFAULT 0,
            decision varchar(24) NOT NULL DEFAULT 'none',
            comment text NULL,
            evidence_note text NULL,
            next_action text NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY object_lookup (object_type, object_id),
            KEY created_at (created_at)
        ) {$cc};";
        if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (function_exists('dbDelta')) { dbDelta($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    private static function txt($s, $max) {
        $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field((string) $s) : trim(strip_tags((string) $s));
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /**
     * Persist one feedback row. Validates type/decision against the
     * whitelists, clamps the rating to 0–5, bounds every free-text field.
     * @return int|WP_Error
     */
    public static function save(array $in) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'insert')) {
            return new WP_Error('ves_feedback_no_db', 'Database unavailable.');
        }
        $ws = (int) ($in['workspace_id'] ?? 0);
        if ($ws <= 0) { return new WP_Error('ves_feedback_workspace', 'A positive workspace id is required.'); }
        $type = (string) ($in['object_type'] ?? '');
        if (!in_array($type, self::OBJECT_TYPES, true)) { return new WP_Error('ves_feedback_bad_type', 'Unknown feedback object type.'); }
        $object_id = (int) ($in['object_id'] ?? 0);
        if ($object_id <= 0) { return new WP_Error('ves_feedback_bad_object', 'A positive object id is required.'); }
        $decision = (string) ($in['decision'] ?? 'none');
        if (!in_array($decision, self::DECISIONS, true)) { $decision = 'none'; }
        $rating = max(0, min(5, (int) ($in['rating'] ?? 0)));

        $row = [
            'workspace_id'  => $ws,
            'object_type'   => $type,
            'object_id'     => $object_id,
            'rating'        => $rating,
            'decision'      => $decision,
            'comment'       => self::txt($in['comment'] ?? '', 2000),
            'evidence_note' => self::txt($in['evidence_note'] ?? '', 1000),
            'next_action'   => self::txt($in['next_action'] ?? '', 500),
            'user_id'       => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'created_at'    => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
        $ok = $wpdb->insert(self::table_name(), $row, ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s']);
        if ($ok === false) { return new WP_Error('ves_feedback_insert_failed', 'Feedback could not be saved.'); }
        return (int) $wpdb->insert_id;
    }

    /** Recent feedback for a workspace (reviewable on the pilot page). */
    public static function recent($workspace_id, $limit = 12) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d ORDER BY id DESC LIMIT %d',
            max(0, (int) $workspace_id), max(1, min(50, (int) $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** Count of feedback rows in a workspace (pilot metric). */
    public static function count_for($workspace_id) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE workspace_id = %d', max(0, (int) $workspace_id)
        ));
    }

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    /**
     * Compact feedback form for a loop object — rendered in the workbench
     * decision rail. Pure POST + nonce; the redirect returns to the page the
     * decision was made on (whitelisted slugs only, like the review handler).
     */
    public static function form_html($workspace_id, $object_type, $object_id) {
        if (!in_array((string) $object_type, self::OBJECT_TYPES, true) || (int) $object_id <= 0) { return ''; }
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field(self::ACTION, '_wpnonce', true, false) : '';
        $h  = '<section class="fi-feedback-card"><h3>' . self::e('Pilot feedback') . '</h3>';
        $h .= '<p class="fi-intake-hint">' . self::e('One honest line beats a survey: was this ' . str_replace('_', ' ', (string) $object_type) . ' useful, and what should happen next?') . '</p>';
        $h .= '<form method="post" action="' . (function_exists('esc_url') ? esc_url($post_url) : self::ea($post_url)) . '">' . $nonce;
        $h .= '<input type="hidden" name="action" value="' . self::ea(self::ACTION) . '">';
        $h .= '<input type="hidden" name="workspace_id" value="' . self::ea((string) max(1, (int) $workspace_id)) . '">';
        $h .= '<input type="hidden" name="object_type" value="' . self::ea((string) $object_type) . '">';
        $h .= '<input type="hidden" name="object_id" value="' . self::ea((string) (int) $object_id) . '">';
        $h .= '<p><label>Usefulness <select name="rating">';
        foreach ([0 => '—', 1 => '1 · not useful', 2 => '2', 3 => '3 · usable', 4 => '4', 5 => '5 · exactly right'] as $v => $l) {
            $h .= '<option value="' . self::ea((string) $v) . '">' . self::e($l) . '</option>';
        }
        $h .= '</select></label> <label>Decision <select name="decision">';
        foreach (self::DECISIONS as $d) { $h .= '<option value="' . self::ea($d) . '">' . self::e($d) . '</option>'; }
        $h .= '</select></label></p>';
        $h .= '<p><label>Comment / confusion point<br><textarea name="comment" rows="2" maxlength="2000" class="large-text"></textarea></label></p>';
        $h .= '<p><label>Evidence issue (optional) <input type="text" name="evidence_note" maxlength="1000" class="regular-text"></label></p>';
        $h .= '<p><label>Next action (optional) <input type="text" name="next_action" maxlength="500" class="regular-text"></label></p>';
        $h .= '<p><button type="submit" class="button button-secondary">' . self::e('Record feedback') . '</button></p>';
        $h .= '</form></section>';
        return $h;
    }

    /** admin-post wrapper: capability + nonce + save + whitelisted redirect. */
    public static function handle() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die(self::e('Insufficient permissions.'), '', ['response' => 403]); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION); }
        $in = isset($_POST) && is_array($_POST) ? $_POST : [];
        $in = function_exists('wp_unslash') ? wp_unslash($in) : $in;
        $res = self::save($in);
        $ws = max(1, (int) ($in['workspace_id'] ?? 1));
        $type = (string) ($in['object_type'] ?? '');
        $id = (int) ($in['object_id'] ?? 0);
        // Return to the surface the feedback came from (whitelist only).
        if ($type === 'brief' || $type === 'prompt_preview') {
            $args = ['page' => 'fi-draft-workbench', 'workspace_id' => $ws, 'brief_id' => $id];
        } elseif ($type === 'insight') {
            $args = ['page' => 'fi-brief-workbench', 'workspace_id' => $ws, 'insight_id' => $id];
        } else {
            $args = ['page' => 'fi-pilot-readiness', 'workspace_id' => $ws];
        }
        if (function_exists('is_wp_error') && is_wp_error($res)) {
            $args['fi_err'] = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $res->get_error_code()));
        } else {
            $args['fi_notice'] = 'feedback_recorded';
        }
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $url = function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        if (!defined('VES_INTAKE_NO_EXIT')) { exit; }
    }
}
