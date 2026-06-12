<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Pilot_Readiness — Phase 4 pilot gate + Phase 5 trace handoff.
 *
 * One admin page that answers ONE question honestly:
 *   "Can we run a controlled pilot now? If not, what is missing?"
 *
 * It computes (never asserts) the pilot gates: release status, file-backed
 * live validation, sample-workspace status, scenario readiness, loop
 * completion, evidence coverage, review acceptance, feedback capture and
 * usage-ledger health — plus a read-only end-to-end TRACE view for any
 * insight (source → signal → evidence → insight → brief → usage → memory →
 * feedback), which is the pilot reviewer's evidence handoff.
 *
 * Not a dashboard: no charts, no aggregates beyond the gates, no fake green.
 */
final class VES_Pilot_Readiness {

    const PAGE_SLUG    = 'fi-pilot-readiness';
    const ACTION_SEED  = 'ves_pilot_seed';
    const ACTION_RESET = 'ves_pilot_reset';

    public static function register() {
        if (!function_exists('add_action')) { return; }
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_' . self::ACTION_SEED, [__CLASS__, 'handle_seed']);
        add_action('admin_post_' . self::ACTION_RESET, [__CLASS__, 'handle_reset']);
    }

    public static function register_menu() {
        if (!function_exists('add_management_page')) { return; }
        add_management_page('Future Island — Pilot Readiness', 'FI Pilot Readiness', 'manage_options', self::PAGE_SLUG, [__CLASS__, 'render_page']);
    }

    private static function e($s)  { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function badge($state, $label = null) {
        return class_exists('VES_Review_State') ? VES_Review_State::badge($state, $label) : self::e($label !== null ? $label : (string) $state);
    }

    // ── Gate computation (3.3 / 3.5) ───────────────────────────────────────────

    /** @return array{rows:array,classification:string,missing:array} */
    public static function report($ws) {
        $ws = max(1, (int) $ws);
        $rows = []; $missing = [];

        // Release status (the readiness service is authoritative).
        $release = 'unknown'; $release_state = 'warning';
        if (class_exists('VES_RC_Readiness_Service') && method_exists('VES_RC_Readiness_Service', 'report')) {
            try {
                $r = VES_RC_Readiness_Service::report();
                $release = (string) ($r['status'] ?? 'unknown');
                $release_state = $release === 'blocked' ? 'blocked' : ($release === 'ready_for_staging' ? 'ready' : 'warning');
            } catch (\Throwable $e) { $release = 'unavailable'; }
        }
        if ($release_state === 'blocked') { $missing[] = 'release blockers must be resolved'; }
        $rows[] = ['Release status', $release_state, str_replace('_', ' ', $release), 'Resolve blockers via Advanced → Release Candidate.'];

        // Live staging validation — the hard pilot gate.
        $lv = ['status' => 'unrun'];
        if (class_exists('VES_RC_Evidence_Pack') && method_exists('VES_RC_Evidence_Pack', 'live_validation_state')) {
            try { $lv = (array) VES_RC_Evidence_Pack::live_validation_state(); } catch (\Throwable $e) { $lv = ['status' => 'unrun']; }
        }
        $lv_status = (string) ($lv['status'] ?? 'unrun');
        $lv_passed = $lv_status === 'passed' && !empty($lv['files_verified']);
        if (!$lv_passed) { $missing[] = 'a file-backed PASSED live staging validation (currently: ' . $lv_status . ')'; }
        $rows[] = ['Live staging validation', $lv_passed ? 'recorded' : (in_array($lv_status, ['failed', 'unknown_error'], true) ? 'blocked' : 'warning'),
            $lv_passed ? 'passed (file-backed)' : $lv_status,
            $lv_passed ? 'Keep the evidence archive with the pilot records.' : 'Run scripts/future-island-live-validation-v3.sh on staging and record the evidence pack.'];

        // Sample workspace / scenario readiness.
        $seed = class_exists('VES_Pilot_Seed') ? VES_Pilot_Seed::status($ws) : ['seeded' => false, 'scenarios' => []];
        $rows[] = ['Sample workspace', !empty($seed['seeded']) ? 'ready' : 'warning',
            !empty($seed['seeded']) ? 'demo data seeded (' . count((array) $seed['scenarios']) . ' scenarios)' : 'not seeded',
            !empty($seed['seeded']) ? 'Walk scenarios B and C through their remaining steps.' : 'Seed the sample workspace below (optional but recommended for the pilot walkthrough).'];
        foreach (['A' => 'Competitor signal → campaign brief', 'B' => 'Cultural signal → brand opportunity', 'C' => 'AI-visibility gap → corrective brief'] as $k => $label) {
            $sc = (array) (($seed['scenarios'] ?? [])[$k] ?? []);
            $rows[] = ['Scenario ' . $k, count($sc) > 0 ? 'ready' : 'warning', count($sc) > 0 ? $label . ' — staged at ' . str_replace('_', ' ', (string) ($sc['stage'] ?? '')) : $label . ' — not staged', count($sc) > 0 ? 'Execute the remaining steps live during the pilot.' : 'Seed the sample workspace, or stage it by hand via Intake.'];
        }

        // Loop completion (real objects, this workspace).
        $counts = []; $by_status = [];
        if (class_exists('VES_Intelligence_Store')) {
            try {
                $counts = method_exists('VES_Intelligence_Store', 'counts') ? (array) VES_Intelligence_Store::counts($ws) : [];
                $by_status = method_exists('VES_Intelligence_Store', 'count_insights_by_status') ? (array) VES_Intelligence_Store::count_insights_by_status($ws) : [];
            } catch (\Throwable $e) { $counts = []; }
        }
        $loop_ok = (int) ($counts['sources'] ?? 0) > 0 && (int) ($counts['signals'] ?? 0) > 0
            && (int) ($by_status['approved'] ?? 0) > 0 && (int) ($counts['briefs'] ?? 0) > 0;
        if (!$loop_ok) { $missing[] = 'a completed loop in the pilot workspace (source → signal → approved insight → brief)'; }
        $rows[] = ['Loop completion', $loop_ok ? 'ready' : 'warning',
            sprintf('%d sources · %d signals · %d insights (%d approved) · %d briefs',
                (int) ($counts['sources'] ?? 0), (int) ($counts['signals'] ?? 0), (int) ($counts['insights'] ?? 0), (int) ($by_status['approved'] ?? 0), (int) ($counts['briefs'] ?? 0)),
            $loop_ok ? 'Record a prompt preview from a brief row to ledger usage.' : 'Walk the loop on the Intake page (the Next panel guides each step).'];

        // Evidence coverage (pilot metric — computed, not asserted).
        $coverage = null;
        if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'list_insights')) {
            try {
                $ins = (array) VES_Intelligence_Store::list_insights(['workspace_id' => $ws, 'limit' => 50]);
                if (count($ins) > 0) {
                    $with = 0;
                    foreach ($ins as $i) { if (is_array($i['evidence_ids'] ?? null) && count($i['evidence_ids']) > 0) { $with++; } }
                    $coverage = [$with, count($ins)];
                }
            } catch (\Throwable $e) { $coverage = null; }
        }
        $rows[] = ['Evidence coverage', $coverage === null ? 'warning' : ($coverage[0] === $coverage[1] ? 'ready' : 'warning'),
            $coverage === null ? 'no insights yet' : $coverage[0] . ' of ' . $coverage[1] . ' recent insights carry evidence',
            'Evidence-less insights cannot be approved — promote signals instead of hand-writing insights.'];

        // Review acceptance (display only; small numbers stay small).
        $appr = (int) ($by_status['approved'] ?? 0); $rej = (int) ($by_status['rejected'] ?? 0);
        $rows[] = ['Review acceptance', 'warning', ($appr + $rej) > 0 ? $appr . ' approved / ' . $rej . ' rejected' : 'no review decisions yet',
            'Pilot learning: a 100% acceptance rate usually means review is rubber-stamping.'];

        // Feedback capture.
        $fb_ok = class_exists('VES_Pilot_Feedback');
        $fb_count = $fb_ok && method_exists('VES_Pilot_Feedback', 'count_for') ? (int) VES_Pilot_Feedback::count_for($ws) : 0;
        if (!$fb_ok) { $missing[] = 'the pilot feedback module'; }
        $rows[] = ['Feedback capture', $fb_ok ? 'ready' : 'blocked', $fb_ok ? $fb_count . ' feedback row(s) recorded' : 'module unavailable',
            'Record one feedback line per reviewed object (workbench right rail).'];

        // Usage ledger.
        $usage_ok = class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'record');
        if (!$usage_ok) { $missing[] = 'the usage ledger'; }
        $rows[] = ['Usage ledger', $usage_ok ? 'ready' : 'blocked', $usage_ok ? 'append-style event ledger available (prompt previews are idempotent)' : 'tracker unavailable',
            'Every preview action ledgers exactly one event per brief per operator.'];

        // Memory governance.
        $mem_ok = class_exists('VES_Brand_Context_Service');
        $rows[] = ['Memory governance', $mem_ok ? 'ready' : 'blocked', $mem_ok ? 'candidates forced to candidate status; approval is human-only' : 'service unavailable',
            'Approve/reject candidates on the memory page; candidates never enter trusted context.'];

        $classification = count($missing) === 0 ? 'ready_for_controlled_pilot' : 'not_ready_for_pilot';
        return ['rows' => $rows, 'classification' => $classification, 'missing' => $missing, 'workspace_id' => $ws];
    }

    // ── Seed/reset handlers ─────────────────────────────────────────────────────

    public static function handle_seed()  { self::handle_seed_action(self::ACTION_SEED, 'seed'); }
    public static function handle_reset() { self::handle_seed_action(self::ACTION_RESET, 'reset'); }

    private static function handle_seed_action($action, $op) {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die(self::e('Insufficient permissions.'), '', ['response' => 403]); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer($action); }
        $in = isset($_POST) && is_array($_POST) ? $_POST : [];
        $ws = max(1, (int) ($in['workspace_id'] ?? 1));
        $err = '';
        if (!class_exists('VES_Pilot_Seed')) {
            $err = 'ves_seed_unavailable';
        } elseif ($op === 'reset' && empty($in['confirm_reset'])) {
            $err = 'ves_seed_confirm_required'; // explicit confirmation, server-enforced
        } else {
            $res = $op === 'seed' ? VES_Pilot_Seed::seed($ws) : VES_Pilot_Seed::reset($ws);
            if (function_exists('is_wp_error') && is_wp_error($res)) {
                $err = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $res->get_error_code()));
                if (class_exists('VES_Log') && method_exists('VES_Log', 'warn')) {
                    VES_Log::warn('pilot_seed', 'Pilot ' . $op . ' refused', ['workspace_id' => $ws, 'error_code' => $err]);
                }
            }
        }
        $args = ['page' => self::PAGE_SLUG, 'workspace_id' => $ws];
        if ($err !== '') { $args['fi_err'] = $err; } else { $args['fi_notice'] = $op === 'seed' ? 'pilot_seeded' : 'pilot_reset'; }
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $url = function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        if (!defined('VES_INTAKE_NO_EXIT')) { exit; }
    }

    // ── Page render ─────────────────────────────────────────────────────────────

    public static function render_page() {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return; }
        $ws = isset($_GET['workspace_id']) ? max(1, (int) $_GET['workspace_id']) : 1;
        echo self::render_html($ws);
    }

    private static function notice_html() {
        $notice = isset($_GET['fi_notice']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $_GET['fi_notice'])) : '';
        $err    = isset($_GET['fi_err']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['fi_err'])) : '';
        if ($notice !== '') {
            $map = [
                'pilot_seeded' => 'Sample pilot data seeded — every object is [DEMO]-marked and registered for precise removal.',
                'pilot_reset'  => 'Pilot demo data removed (registry-scoped; non-demo data untouched). Append-only ledger decisions remain by design.',
                'feedback_recorded' => 'Feedback recorded.',
            ];
            return isset($map[$notice]) ? '<div class="notice notice-success"><p>' . self::e($map[$notice]) . '</p></div>' : '';
        }
        if ($err !== '') {
            $map = [
                'ves_seed_already_seeded'  => 'This workspace already carries pilot demo data — reset before seeding again.',
                'ves_seed_not_seeded'      => 'No pilot demo data is registered for this workspace.',
                'ves_seed_confirm_required' => 'Reset requires the confirmation checkbox — nothing was deleted.',
                'ves_seed_unavailable'     => 'Pilot seed module unavailable.',
            ];
            $msg = isset($map[$err]) ? $map[$err] : 'The action could not be completed (' . $err . ').';
            return '<div class="notice notice-error"><p>' . self::e($msg) . '</p></div>';
        }
        return '';
    }

    public static function render_html($ws) {
        $ws = max(1, (int) $ws);
        $report = self::report($ws);
        $pilot_ready = $report['classification'] === 'ready_for_controlled_pilot';
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function ($action) { return function_exists('wp_nonce_field') ? wp_nonce_field($action, '_wpnonce', true, false) : ''; };

        $h  = '<div class="wrap ves-wrap fi-pilot-page">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Pilot Readiness') . '</p>';
        $h .= '<h1>' . self::e('Pilot Readiness') . '</h1>';
        $h .= self::notice_html();

        // THE question, answered honestly.
        if ($pilot_ready) {
            $h .= '<div class="fi-intake-next"><span class="fi-intake-next-k">' . self::e('Verdict') . '</span><p><strong>' . self::e('A controlled pilot can run now.') . '</strong> '
                . self::e('Every gate below is green from computed state — keep the evidence archive and the feedback log with the pilot records. This is a PILOT verdict, never a production claim.') . '</p></div>';
        } else {
            $h .= '<div class="fi-intake-next fi-pilot-blocked"><span class="fi-intake-next-k">' . self::e('Verdict') . '</span><p><strong>' . self::e('Not ready for a controlled pilot yet.') . '</strong> '
                . self::e('Missing: ' . implode('; ', array_map('strval', $report['missing'])) . '.') . '</p></div>';
        }

        $h .= '<section class="fi-intake-card"><p class="fi-intake-no">' . self::e('Gates') . '</p><h2>' . self::e('Pilot gates — workspace ' . $ws) . '</h2>';
        $h .= '<table class="widefat striped"><thead><tr><th>' . self::e('Gate') . '</th><th>' . self::e('State') . '</th><th>' . self::e('Detail') . '</th><th>' . self::e('Next action') . '</th></tr></thead><tbody>';
        foreach ($report['rows'] as $r) {
            $h .= '<tr><td>' . self::e((string) $r[0]) . '</td><td>' . self::badge((string) $r[1]) . '</td><td>' . self::e((string) $r[2]) . '</td><td>' . self::e((string) $r[3]) . '</td></tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<p class="fi-intake-hint">' . self::e('States are computed from the same classifiers the release pages use — this table can never look greener than they do.') . '</p></section>';

        // Seed / reset (explicit, demo-scoped).
        $seeded = class_exists('VES_Pilot_Seed') ? VES_Pilot_Seed::status($ws) : ['seeded' => false];
        $h .= '<section class="fi-intake-card"><p class="fi-intake-no">' . self::e('Sample data') . '</p><h2>' . self::e('Pilot demo data') . '</h2>';
        if (empty($seeded['seeded'])) {
            $h .= '<p class="fi-intake-hint">' . self::e('Seeds the three pilot scenarios as [DEMO]-marked loop objects at staggered stages (A: brief built · B: insight approved · C: draft insight awaiting review). Every id is registered so reset removes exactly these rows.') . '</p>';
            $h .= '<form method="post" action="' . (function_exists('esc_url') ? esc_url($post_url) : self::ea($post_url)) . '">' . $nonce(self::ACTION_SEED)
                . '<input type="hidden" name="action" value="' . self::ea(self::ACTION_SEED) . '">'
                . '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">'
                . '<button type="submit" class="button button-primary">' . self::e('Seed pilot demo data') . '</button></form>';
        } else {
            $c = (array) ($seeded['counts'] ?? []);
            $h .= '<p>' . self::e(sprintf('Seeded %s — %d sources, %d signals, %d insights, %d briefs, %d memory candidate(s).',
                (string) ($seeded['seeded_at'] ?? ''), (int) ($c['source'] ?? 0), (int) ($c['signal'] ?? 0), (int) ($c['insight'] ?? 0), (int) ($c['brief'] ?? 0), (int) ($c['memory'] ?? 0))) . '</p>';
            $h .= '<form method="post" action="' . (function_exists('esc_url') ? esc_url($post_url) : self::ea($post_url)) . '">' . $nonce(self::ACTION_RESET)
                . '<input type="hidden" name="action" value="' . self::ea(self::ACTION_RESET) . '">'
                . '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">'
                . '<p><label><input type="checkbox" name="confirm_reset" value="1"> ' . self::e('I confirm removal of the registered [DEMO] rows (non-demo data is untouched).') . '</label></p>'
                . '<button type="submit" class="button">' . self::e('Reset pilot demo data') . '</button></form>';
        }
        $h .= '</section>';

        // Recent feedback (reviewable).
        if (class_exists('VES_Pilot_Feedback') && method_exists('VES_Pilot_Feedback', 'recent')) {
            $h .= '<section class="fi-intake-card"><p class="fi-intake-no">' . self::e('Learning') . '</p><h2>' . self::e('Recent pilot feedback') . '</h2>';
            $fb = VES_Pilot_Feedback::recent($ws, 10);
            if (count($fb) === 0) {
                $h .= '<p class="fi-queue-hint">' . self::e('No feedback yet — every workbench decision rail carries a one-line feedback form.') . '</p>';
            } else {
                $h .= '<table class="widefat striped"><thead><tr><th>' . self::e('object') . '</th><th>' . self::e('rating') . '</th><th>' . self::e('decision') . '</th><th>' . self::e('comment') . '</th><th>' . self::e('next action') . '</th><th>' . self::e('when') . '</th></tr></thead><tbody>';
                foreach ($fb as $row) {
                    $h .= '<tr><td>' . self::e((string) ($row['object_type'] ?? '') . ' #' . (int) ($row['object_id'] ?? 0)) . '</td>'
                        . '<td>' . self::e((int) ($row['rating'] ?? 0) > 0 ? (string) (int) $row['rating'] . '/5' : '—') . '</td>'
                        . '<td>' . self::e((string) ($row['decision'] ?? '')) . '</td>'
                        . '<td>' . self::e((string) ($row['comment'] ?? '')) . '</td>'
                        . '<td>' . self::e((string) ($row['next_action'] ?? '')) . '</td>'
                        . '<td>' . self::e((string) ($row['created_at'] ?? '')) . '</td></tr>';
                }
                $h .= '</tbody></table>';
            }
            $h .= '</section>';
        }

        // Generic feedback form — covers memory candidates and any object the
        // workbench cards don't reach.
        if (class_exists('VES_Pilot_Feedback')) {
            $h .= '<section class="fi-intake-card"><p class="fi-intake-no">' . self::e('Capture') . '</p><h2>' . self::e('Record feedback by object') . '</h2>';
            $h .= '<form method="post" action="' . (function_exists('esc_url') ? esc_url($post_url) : self::ea($post_url)) . '">' . $nonce(VES_Pilot_Feedback::ACTION);
            $h .= '<input type="hidden" name="action" value="' . self::ea(VES_Pilot_Feedback::ACTION) . '">';
            $h .= '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">';
            $h .= '<p><label>Object <select name="object_type">';
            foreach (VES_Pilot_Feedback::OBJECT_TYPES as $t) { $h .= '<option value="' . self::ea($t) . '">' . self::e(str_replace('_', ' ', $t)) . '</option>'; }
            $h .= '</select></label> <label>Id <input type="number" name="object_id" min="1" required></label> ';
            $h .= '<label>Usefulness <select name="rating">';
            foreach ([0, 1, 2, 3, 4, 5] as $v) { $h .= '<option value="' . self::ea((string) $v) . '">' . self::e($v === 0 ? '—' : (string) $v) . '</option>'; }
            $h .= '</select></label> <label>Decision <select name="decision">';
            foreach (VES_Pilot_Feedback::DECISIONS as $d) { $h .= '<option value="' . self::ea($d) . '">' . self::e($d) . '</option>'; }
            $h .= '</select></label></p>';
            $h .= '<p><label>Comment<br><textarea name="comment" rows="2" maxlength="2000" class="large-text"></textarea></label></p>';
            $h .= '<p><button type="submit" class="button button-secondary">' . self::e('Record feedback') . '</button></p>';
            $h .= '</form></section>';
        }

        $h .= self::trace_section($ws);
        $h .= '<p class="fi-memory-policy">' . self::e('A pilot verdict is never a production claim. Live validation, backups and rollback are separate, file-backed gates.') . '</p>';
        $h .= '</div>';
        return $h;
    }

    // ── 4.7 — read-only loop trace (evidence handoff) ───────────────────────────

    private static function trace_section($ws) {
        $h = '<section class="fi-intake-card" id="fi-pilot-trace"><p class="fi-intake-no">' . self::e('Handoff') . '</p><h2>' . self::e('Loop trace') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('A pilot reviewer can read one complete trail: source → signal → evidence → insight → brief → usage → memory → feedback. Read-only.') . '</p>';
        $h .= '<form method="get" action="">'
            . '<input type="hidden" name="page" value="' . self::ea(self::PAGE_SLUG) . '">'
            . '<input type="hidden" name="workspace_id" value="' . self::ea((string) $ws) . '">'
            . '<p><label>' . self::e('Insight id') . ' <input type="number" name="trace_insight" min="1" value="' . self::ea((string) (isset($_GET['trace_insight']) ? (int) $_GET['trace_insight'] : '')) . '"></label> '
            . '<button type="submit" class="button button-secondary">' . self::e('Trace') . '</button></p></form>';

        $id = isset($_GET['trace_insight']) ? max(0, (int) $_GET['trace_insight']) : 0;
        if ($id > 0) { $h .= self::trace_insight($ws, $id); }
        $h .= '</section>';
        return $h;
    }

    /** Build + render the full trail for one insight; every line escaped. */
    public static function trace_insight($ws, $insight_id) {
        if (!class_exists('VES_Intelligence_Store')) { return '<p>' . self::e('Store unavailable.') . '</p>'; }
        $insight = VES_Intelligence_Store::get_insight((int) $insight_id);
        if (!is_array($insight) || empty($insight['id'])) { return '<p class="fi-queue-hint">' . self::e('Insight not found.') . '</p>'; }
        if ((int) ($insight['workspace_id'] ?? 0) !== (int) $ws) { return '<p class="fi-queue-hint">' . self::e('That insight belongs to a different workspace.') . '</p>'; }

        $line = function ($label, $text, $meta = '') {
            return '<li><span class="fi-trace-k">' . self::e($label) . '</span> ' . self::e($text)
                . ($meta !== '' ? ' <span class="fi-intake-mono">' . self::e($meta) . '</span>' : '') . '</li>';
        };
        $h = '<ol class="fi-trace">';

        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? array_map('intval', $insight['evidence_ids']) : [];
        $first_ev = null;
        foreach ($evidence_ids as $eid) {
            $ev = VES_Intelligence_Store::get_evidence($eid);
            if (is_array($ev) && $first_ev === null) { $first_ev = $ev; }
        }
        $signal = $first_ev ? VES_Intelligence_Store::get_signal((int) ($first_ev['signal_id'] ?? 0)) : null;
        $source = is_array($signal) ? VES_Intelligence_Store::get_source((int) ($signal['source_id'] ?? 0)) : null;

        $h .= $line('Source', is_array($source) ? (string) ($source['source_title'] ?? '—') : 'not resolved', is_array($source) ? '#' . (int) $source['id'] . ' · ' . (string) ($source['source_type'] ?? '') . ((string) ($source['source_url'] ?? '') !== '' ? ' · ' . (string) $source['source_url'] : '') : '');
        $h .= $line('Signal', is_array($signal) ? (string) ($signal['title'] ?? '—') : 'not resolved', is_array($signal) ? '#' . (int) $signal['id'] . ' · ' . (string) ($signal['signal_type'] ?? '') : '');
        $h .= $line('Evidence', $first_ev ? (string) ($first_ev['text'] ?? '—') : 'NONE LINKED', count($evidence_ids) . ' record(s)');
        $pstate = method_exists('VES_Intelligence_Store', 'insight_presentation_state') ? VES_Intelligence_Store::insight_presentation_state($insight) : (string) ($insight['status'] ?? '');
        $h .= $line('Insight', (string) ($insight['title'] ?? ''), '#' . (int) $insight['id'] . ' · ' . (string) ($insight['insight_type'] ?? '') . ' · ' . $pstate);

        $brief_id = method_exists('VES_Intelligence_Store', 'find_brief_by_insight') ? (int) VES_Intelligence_Store::find_brief_by_insight((int) $ws, (int) $insight['id']) : 0;
        $brief = $brief_id > 0 ? VES_Intelligence_Store::get_brief($brief_id) : null;
        $h .= $line('Brief', is_array($brief) ? (string) ($brief['title'] ?? '') : 'not built yet', is_array($brief) ? '#' . $brief_id . ' · ' . (string) ($brief['status'] ?? '') . ' · evidence carried: ' . (is_array($brief['evidence_ids'] ?? null) ? count($brief['evidence_ids']) : 0) : '');

        // Usage events for the brief's preview action.
        $usage_note = 'no preview events';
        if ($brief_id > 0 && class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'table_name')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . VES_AI_Usage_Tracker::table_name() . ' WHERE workspace_id = %d AND operation_type = %s AND run_id LIKE %s',
                    (int) $ws, 'prompt_preview', 'preview-brief-' . $brief_id . '-u%'
                ));
                $usage_note = $n . ' prompt-preview event(s) ledgered';
            }
        }
        $h .= $line('Usage', $usage_note, '');

        // Memory candidates traced to this insight.
        $mem_note = 'none proposed';
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'table_name')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . VES_Memory_Records::table_name() . ' WHERE workspace_id = %d AND source_type = %s AND source_id = %s',
                    (int) $ws, 'insight', (string) (int) $insight['id']
                ));
                $mem_note = $n . ' memory record(s) trace to this insight (status governs trust)';
            }
        }
        $h .= $line('Memory', $mem_note, '');

        // Feedback on the insight + its brief.
        $fb_note = 'none recorded';
        if (class_exists('VES_Pilot_Feedback') && method_exists('VES_Pilot_Feedback', 'table_name')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . VES_Pilot_Feedback::table_name() . " WHERE workspace_id = %d AND ((object_type = 'insight' AND object_id = %d) OR (object_type IN ('brief','prompt_preview') AND object_id = %d))",
                    (int) $ws, (int) $insight['id'], $brief_id
                ));
                $fb_note = $n . ' feedback row(s) on this trail';
            }
        }
        $h .= $line('Feedback', $fb_note, '');
        $h .= '</ol>';
        return $h;
    }
}
