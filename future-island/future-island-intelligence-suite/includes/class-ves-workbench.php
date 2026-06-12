<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Workbench — Phase 8A. Human-reviewed Brief & Draft workbenches. READ-ONLY
 * inspection + dry-run package preview + Evidence Binder + DISABLED review controls
 * (no safe backend transition handler exists yet, so controls render disabled with
 * an honest reason). NO AI generation button, no Generate/Publish/Auto-approve. All
 * output escaped. PHP 7.4 compatible.
 */
final class VES_Workbench {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    /** Output guidance slots — placeholders only, never AI-generated claims. */
    const DRAFT_SLOTS = ['hook', 'body', 'cta', 'claims_to_avoid', 'terms_to_use', 'evidence_notes'];

    const ACTION_REVIEW = 'ves_workbench_review';

    /** Phase 4/5 — the review rail is now a real, audited transition surface. */
    public static function register() {
        if (!function_exists('add_action')) { return; }
        add_action('admin_post_' . self::ACTION_REVIEW, [__CLASS__, 'handle_review']);
    }

    /**
     * Human review decision: capability + nonce + workspace assertion, then the
     * store's AUDITED transition (lifecycle matrix + evidence gate + decision
     * ledger). Never an auto-approval — this runs only on an operator's click.
     */
    public static function handle_review() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die(self::e('Insufficient permissions.'), '', ['response' => 403]); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ACTION_REVIEW); }
        $in = isset($_POST) && is_array($_POST) ? $_POST : [];
        $in = function_exists('wp_unslash') ? wp_unslash($in) : $in;

        $type = (string) ($in['object_type'] ?? '');
        $type = in_array($type, ['insight', 'brief'], true) ? $type : '';
        $decision = (string) ($in['decision'] ?? '');
        $decision = in_array($decision, ['approve', 'reject'], true) ? $decision : '';
        $id = max(0, (int) ($in['object_id'] ?? 0));
        $ws = max(0, (int) ($in['workspace_id'] ?? 0));

        $err = '';
        if ($type === '' || $decision === '' || $id <= 0 || $ws <= 0) {
            $err = 'ves_workbench_bad_request';
        } elseif (!class_exists('VES_Intelligence_Store')) {
            $err = 'ves_workbench_store_missing';
        } else {
            $row = $type === 'insight' ? VES_Intelligence_Store::get_insight($id) : VES_Intelligence_Store::get_brief($id);
            if (!is_array($row) || empty($row['id'])) {
                $err = 'ves_workbench_not_found';
            } elseif ((int) ($row['workspace_id'] ?? 0) !== $ws) {
                $err = 'ves_workspace_mismatch';
            } else {
                $status = $decision === 'approve' ? 'approved' : 'rejected';
                $meta = ['reviewed_via' => 'workbench', 'reviewer_user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0];
                $res = $type === 'insight'
                    ? VES_Intelligence_Store::update_insight_status($id, $status, $meta)
                    : VES_Intelligence_Store::update_brief_status($id, $status, $meta);
                if (function_exists('is_wp_error') && is_wp_error($res)) {
                    $err = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $res->get_error_code()));
                    if (class_exists('VES_Log') && method_exists('VES_Log', 'warn')) {
                        VES_Log::warn('workbench_review', 'Review transition refused', ['object_type' => $type, 'object_id' => $id, 'decision' => $decision, 'error_code' => $err]);
                    }
                }
            }
        }

        // Return to the workbench the decision came from (whitelisted slugs only).
        $args = ['workspace_id' => max(1, $ws)];
        if ($type === 'brief') { $args['page'] = 'fi-draft-workbench'; $args['brief_id'] = $id; }
        else { $args['page'] = 'fi-brief-workbench'; $args['insight_id'] = $id; }
        if ($err !== '') { $args['fi_err'] = $err; } else { $args['fi_notice'] = 'review_' . $decision . 'd'; }
        $url = function_exists('admin_url') ? admin_url('tools.php') : 'tools.php';
        $url = function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        if (!defined('VES_INTAKE_NO_EXIT')) { exit; }
    }

    /** Notice strip: fixed code → copy map; query strings are never echoed raw. */
    private static function notice_html() {
        $notice = isset($_GET['fi_notice']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $_GET['fi_notice'])) : '';
        $err    = isset($_GET['fi_err']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['fi_err'])) : '';
        if ($notice !== '') {
            $map = [
                'review_approved'  => 'Decision recorded: APPROVED. The transition is in the review ledger.',
                'review_rejected'  => 'Decision recorded: REJECTED (terminal — reopening requires the audited override).',
                'preview_recorded' => 'Prompt-package preview built and ONE usage event ledgered (idempotent — repeat clicks reuse it). No provider was called.',
            ];
            return isset($map[$notice]) ? '<div class="notice notice-success inline"><p>' . self::e($map[$notice]) . '</p></div>' : '';
        }
        if ($err !== '') {
            $map = [
                'ves_intel_evidence_required'   => 'Approval refused by the evidence gate: link evidence before approving.',
                'ves_intel_transition_blocked'  => 'The lifecycle matrix refused this transition (terminal states never silently reopen).',
                'ves_workspace_mismatch'        => 'That object belongs to a different workspace.',
                'ves_workbench_not_found'       => 'Object not found.',
                'ves_workbench_bad_request'     => 'The review request was malformed; nothing was changed.',
            ];
            $msg = isset($map[$err]) ? $map[$err] : 'The decision could not be recorded (' . $err . '). Nothing was changed.';
            return '<div class="notice notice-error inline"><p>' . self::e($msg) . '</p></div>';
        }
        return '';
    }

    public static function render_brief($args = []) {
        $ws = max(0, (int) ($args['workspace_id'] ?? 0));
        $insight_id = (int) ($args['insight_id'] ?? 0);
        $h = '<div class="fi-workbench fi-brief-workbench ves-wrap">';
        $h .= '<div class="fi-breadcrumb">' . self::e('Future Island · Workbench · Brief') . '</div>';
        $h .= '<h1>' . self::e('Brief Workbench') . '</h1>';
        $h .= '<p class="fiis-sr-sub">' . self::e('Inspect an Insight against its evidence and record the review decision. Evidence first; no AI generation here.') . '</p>';
        $h .= self::notice_html();
        // The nav only lists sections that will actually render (no dangling anchors
        // on installs where the binder/builder is unavailable).
        $nav_items = ['fi-wb-target' => 'Target'];
        if (class_exists('VES_Evidence_Binder')) { $nav_items['fi-wb-evidence'] = 'Evidence'; }
        $nav_items['fi-wb-brief'] = 'Brief preview';
        if (class_exists('VES_Generation_Prompt_Package_Builder')) { $nav_items['fi-wb-package'] = 'Prompt package'; }
        $nav_items['fi-wb-review'] = 'Review';
        $h .= self::jump_nav($nav_items);

        if ($insight_id <= 0) { return $h . self::target_prompt('insight_id') . '</div>'; }
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'get_insight')) {
            return $h . self::error('Intelligence store unavailable.') . '</div>';
        }
        $insight = VES_Intelligence_Store::get_insight($insight_id);
        if (!is_array($insight) || empty($insight['id'])) { return $h . self::error('Insight not found.') . '</div>'; }
        if ($ws > 0 && class_exists('VES_Workspace_Guard') && VES_Workspace_Guard::assert_object_in_workspace('insight', $insight, $ws) !== true) { return $h . self::error('Insight is outside this workspace.') . '</div>'; }
        if ($ws > 0 && !class_exists('VES_Workspace_Guard') && (int) ($insight['workspace_id'] ?? 0) !== $ws) { return $h . self::error('Insight is outside this workspace.') . '</div>'; }

        // Object rail (center): target summary + brief preview.
        $status = (string) ($insight['status'] ?? 'unknown');
        $object  = '<section id="fi-wb-target" class="fi-readiness-panel"><h3>' . self::e('Target') . '</h3>';
        $object .= '<p class="fi-meta-line">' . self::e('insight') . ' <code>#' . self::e((string) (int) $insight['id']) . '</code> · '
            . self::e('status') . ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($status) : self::e($status)) . '</p>';
        $object .= '<p>' . self::e(self::clip((string) ($insight['title'] ?? ($insight['summary'] ?? '')), 240)) . '</p></section>';

        $object .= '<section id="fi-wb-brief" class="fi-readiness-panel"><h3>' . self::e('Brief preview') . '</h3>';
        if (class_exists('VES_Insight_Brief_Builder') && method_exists('VES_Insight_Brief_Builder', 'build_brief_from_insight')) {
            $payload = VES_Insight_Brief_Builder::build_brief_from_insight($insight_id);
            if (function_exists('is_wp_error') && is_wp_error($payload)) {
                $object .= '<p class="fi-empty-state">' . (class_exists('VES_Review_State') ? VES_Review_State::badge('blocked') : '') . ' '
                    . self::e($payload->get_error_code() === 'ves_brief_no_evidence' ? 'Blocked: this insight has no evidence. A brief requires evidence.' : 'Brief cannot be built for this insight yet.') . '</p>';
            } elseif (is_array($payload)) {
                $object .= '<p class="fi-meta-line">' . self::e('objective') . ': ' . self::e(self::clip((string) ($payload['objective'] ?? ''), 200)) . '</p>';
                $object .= '<p class="fi-meta-line">' . self::e('key message') . ': ' . self::e(self::clip((string) ($payload['key_message'] ?? ''), 240)) . '</p>';
            }
        } else {
            $object .= '<p class="fi-empty-state">' . self::e('Brief builder unavailable.') . '</p>';
        }
        $object .= '</section>';

        // Evidence rail (left) + decision rail (right: status card, package, review).
        // The object under review on THIS page is the insight.
        $evidence = class_exists('VES_Evidence_Binder') ? '<div id="fi-wb-evidence">' . VES_Evidence_Binder::render_html($insight) . '</div>' : '';
        $decision = self::decision_card('insight', $insight)
            . self::package_preview_section($ws, 'brief_generation', 'insight', $insight_id)
            . self::review_rail('insight', $insight, $ws)
            . (class_exists('VES_Pilot_Feedback') && method_exists('VES_Pilot_Feedback', 'form_html') ? VES_Pilot_Feedback::form_html($ws, 'insight', $insight_id) : '');

        $h .= self::rails($evidence, $object, $decision);
        $h .= self::actions_note();
        $h .= '</div>';
        return $h;
    }

    public static function render_draft($args = []) {
        $ws = max(0, (int) ($args['workspace_id'] ?? 0));
        $brief_id = (int) ($args['brief_id'] ?? 0);
        $h = '<div class="fi-workbench fi-draft-workbench ves-wrap">';
        $h .= '<div class="fi-breadcrumb">' . self::e('Future Island · Workbench · Draft') . '</div>';
        $h .= '<h1>' . self::e('Draft Workbench') . '</h1>';
        $h .= '<p class="fiis-sr-sub">' . self::e('Inspect a Brief, record its review decision, and preview the prompt package. No AI generation here.') . '</p>';
        $h .= self::notice_html();
        $nav_items = ['fi-wb-target' => 'Brief', 'fi-wb-readiness' => 'Readiness'];
        if (class_exists('VES_Evidence_Binder')) { $nav_items['fi-wb-evidence'] = 'Evidence'; }
        $nav_items['fi-wb-slots'] = 'Output slots';
        if (class_exists('VES_Generation_Prompt_Package_Builder')) { $nav_items['fi-wb-package'] = 'Prompt package'; }
        $nav_items['fi-wb-review'] = 'Review';
        $h .= self::jump_nav($nav_items);

        if ($brief_id <= 0) { return $h . self::target_prompt('brief_id') . '</div>'; }
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'get_brief')) {
            return $h . self::error('Intelligence store unavailable.') . '</div>';
        }
        $brief = VES_Intelligence_Store::get_brief($brief_id);
        if (!is_array($brief) || empty($brief['id'])) { return $h . self::error('Brief not found.') . '</div>'; }
        if ($ws > 0 && class_exists('VES_Workspace_Guard') && VES_Workspace_Guard::assert_object_in_workspace('brief', $brief, $ws) !== true) { return $h . self::error('Brief is outside this workspace.') . '</div>'; }
        if ($ws > 0 && !class_exists('VES_Workspace_Guard') && (int) ($brief['workspace_id'] ?? 0) !== $ws) { return $h . self::error('Brief is outside this workspace.') . '</div>'; }

        $status = strtolower((string) ($brief['status'] ?? 'draft'));
        $ready = in_array($status, ['approved', 'ready', 'reviewed'], true);

        // Object rail (center): brief summary + draft readiness + output slots.
        $object  = '<section id="fi-wb-target" class="fi-readiness-panel"><h3>' . self::e('Brief') . '</h3>';
        $object .= '<p class="fi-meta-line">' . self::e('brief') . ' <code>#' . self::e((string) (int) $brief['id']) . '</code> · '
            . self::e('status') . ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($status) : self::e($status)) . '</p>';
        $object .= '<p>' . self::e(self::clip((string) ($brief['objective'] ?? ($brief['summary'] ?? '')), 240)) . '</p></section>';

        $object .= '<section id="fi-wb-readiness" class="fi-readiness-panel"><h3>' . self::e('Draft readiness') . '</h3>';
        if (!$ready) {
            $object .= '<p>' . (class_exists('VES_Review_State') ? VES_Review_State::badge('blocked') : '') . ' '
                . self::e('Blocked: a draft requires an approved/ready brief. Approve the brief first.') . '</p>';
        } else {
            $object .= '<p>' . (class_exists('VES_Review_State') ? VES_Review_State::badge('ready') : '') . ' ' . self::e('Brief is approved/ready.') . '</p>';
        }
        $object .= '</section>';

        $object .= '<section id="fi-wb-slots" class="fi-readiness-panel"><h3>' . self::e('Output slots (guidance — not generated)') . '</h3><ul class="fi-output-slots">';
        foreach (self::DRAFT_SLOTS as $slot) { $object .= '<li><code>' . self::e($slot) . '</code> <span class="fi-empty-state">' . self::e('—') . '</span></li>'; }
        $object .= '</ul></section>';

        // Evidence rail (left) + decision rail (right). The object under review
        // on THIS page is the brief.
        $evidence = class_exists('VES_Evidence_Binder') ? '<div id="fi-wb-evidence">' . VES_Evidence_Binder::render_html($brief) . '</div>' : '';
        $decision = self::decision_card('brief', $brief)
            . self::package_preview_section($ws, 'draft_generation', 'brief', $brief_id)
            . self::review_rail('brief', $brief, $ws)
            . (class_exists('VES_Pilot_Feedback') && method_exists('VES_Pilot_Feedback', 'form_html') ? VES_Pilot_Feedback::form_html($ws, 'brief', $brief_id) : '')
            . (class_exists('VES_Pilot_Feedback') && class_exists('VES_Generation_Prompt_Package_Builder') && method_exists('VES_Pilot_Feedback', 'form_html') ? VES_Pilot_Feedback::form_html($ws, 'prompt_preview', $brief_id) : '');

        $h .= self::rails($evidence, $object, $decision);
        $h .= self::actions_note();
        $h .= '</div>';
        return $h;
    }

    /**
     * Phase 3 — three-rail operator layout: evidence (left) / object under
     * review (center) / decision + status (right). Pure wrappers — every
     * section keeps its id, so the jump nav and pinned anchors are unchanged.
     * Rails stack on narrow screens via the UI-system grid (782px/1100px).
     */
    private static function rails($evidence_html, $object_html, $decision_html) {
        $h  = '<div class="fi-wb-rails">';
        $h .= '<div class="fi-wb-rail fi-wb-rail-evidence" role="region" aria-label="' . self::ea('Evidence') . '">';
        $h .= $evidence_html !== '' ? $evidence_html
            : '<p class="fi-empty-state">' . self::e('Evidence binder unavailable on this install — evidence ids remain on the object record.') . '</p>';
        $h .= '</div>';
        $h .= '<div class="fi-wb-rail fi-wb-rail-object" role="region" aria-label="' . self::ea('Object under review') . '">' . $object_html . '</div>';
        $h .= '<div class="fi-wb-rail fi-wb-rail-decision" role="region" aria-label="' . self::ea('Decision and status') . '">' . $decision_html . '</div>';
        return $h . '</div>';
    }

    /**
     * Phase 3 — decision-status card: the operator's UX state with a label
     * (badge), what it means, and the concrete next action. Derived from the
     * presentation-state vocabulary; unknown states are never trusted.
     */
    private static function decision_card($kind, array $row) {
        if ($kind === 'insight') {
            $state = (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'insight_presentation_state'))
                ? VES_Intelligence_Store::insight_presentation_state($row)
                : strtolower((string) ($row['status'] ?? 'draft'));
            $map = [
                'draft'            => ['The insight is in draft.', 'Add evidence (promote its signal on the Intake page), then request review.'],
                'needs_evidence'   => ['No evidence is linked — this insight cannot enter review.', 'Promote the underlying signal on the Intake page to create traceable evidence, then return here.'],
                'ready_for_review' => ['Evidence is linked; the insight awaits operator review.', 'Read the evidence rail, then approve or reject from the operator queue.'],
                'approved'         => ['Human-approved. A brief can be built from this insight.', 'Build the brief (Intake page, step 5) — its evidence ids carry through.'],
                'rejected'         => ['Rejected — terminal.', 'Reopening requires the audited reopen override; it can never silently become approved.'],
                'archived'         => ['Archived — diagnostic only.', 'Restore via the audited restore override if it must re-enter review.'],
            ];
        } else {
            $state = strtolower((string) ($row['status'] ?? 'draft'));
            $map = [
                'draft'    => ['The brief awaits operator review.', 'Review the objective and key message against the evidence rail, then approve or reject.'],
                'reviewed' => ['Reviewed; awaiting final approval.', 'Approve to allow draft staging, or reject.'],
                'approved' => ['Approved. Draft staging may proceed — as a prompt-package preview only (AI execution is disabled).', 'Inspect the prompt package below; no provider is called and no content is fabricated.'],
                'rejected' => ['Rejected — terminal.', 'A new brief requires a newly approved insight.'],
                'archived' => ['Archived — diagnostic only.', 'Nothing proceeds from an archived brief.'],
            ];
        }
        $info = isset($map[$state]) ? $map[$state] : ['Status not recognized.', 'Inspect the object record; unknown states are never trusted.'];
        $badge = class_exists('VES_Review_State') ? VES_Review_State::badge($state) : self::e($state);
        return '<section class="fi-decision-card"><h3>' . self::e('Decision status') . '</h3>'
            . '<p class="fi-decision-state">' . $badge . '</p>'
            . '<p class="fi-decision-explain">' . self::e($info[0]) . '</p>'
            . '<p class="fi-decision-next"><strong>' . self::e('Next:') . '</strong> ' . self::e($info[1]) . '</p></section>';
    }

    private static function package_preview_section($ws, $use_case, $target_type, $target_id) {
        if (!class_exists('VES_Generation_Prompt_Package_Builder')) { return ''; }
        $pkg = VES_Generation_Prompt_Package_Builder::build(['workspace_id' => (int) $ws, 'use_case' => $use_case, 'target_type' => $target_type, 'target_id' => (int) $target_id]);
        $h = '<section id="fi-wb-package" class="fi-package-preview"><h3>' . self::e('Prompt Package Preview (dry-run)') . '</h3>';
        $h .= '<p class="fi-meta-line">' . self::e('build status') . ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($pkg['build_status']) : self::e($pkg['build_status']))
            . ' · ' . self::e('blocking reason') . ': <code>' . self::e((string) $pkg['blocking_reason']) . '</code>'
            . ' · ' . self::e('contract') . ': <code>' . self::e((string) ($pkg['output_contract']['schema_key'] ?? '')) . '</code></p>';
        $h .= '<p class="fi-meta-line">' . self::e(sprintf('brand_context applied=%s reason=%s · provider_execution_allowed=%s',
            $pkg['brand_context']['applied'] ? 'yes' : 'no', (string) $pkg['brand_context']['reason'], $pkg['safety']['provider_execution_allowed'] ? 'yes' : 'no')) . '</p>';
        $h .= '<p class="fi-memory-policy"><strong>' . self::e('Memory is not evidence. No provider call. Human review required.') . '</strong></p>';
        $h .= '</section>';
        return $h;
    }

    /**
     * Review rail — REAL human decisions through the audited lifecycle (Phase 4/5).
     * Active approve/reject forms render only when the store exposes the audited
     * transition AND the current status can move; terminal/approved states show
     * disabled controls with the exact reason. Never an auto-approval.
     */
    private static function review_rail($object_type, array $row, $ws) {
        $object_type = $object_type === 'insight' ? 'insight' : 'brief';
        $status = strtolower((string) ($row['status'] ?? 'draft'));
        $id = (int) ($row['id'] ?? 0);
        $method = $object_type === 'insight' ? 'update_insight_status' : 'update_brief_status';
        $store_ok = class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', $method);

        $h = '<section id="fi-wb-review" class="fi-review-rail"><h3>' . self::e('Review — ' . $object_type) . '</h3>';
        $disabled = function ($labels, $reason) {
            $r = self::ea($reason);
            $o = '';
            foreach ($labels as $b) {
                $o .= '<button type="button" class="button" disabled aria-disabled="true" title="' . $r . '">' . self::e($b) . '</button> ';
            }
            return $o . '<p class="fi-empty-state">' . self::e($reason) . '</p>';
        };

        if (!$store_ok || $id <= 0) {
            $h .= $disabled(['Approve ' . $object_type, 'Reject ' . $object_type],
                'Review actions unavailable: this install does not expose the audited status transitions.');
        } elseif (in_array($status, ['rejected', 'archived'], true)) {
            $h .= $disabled(['Approve ' . $object_type, 'Reject ' . $object_type],
                ucfirst($status) . ' is terminal — reopening requires the audited reopen/restore override, never a button.');
        } elseif ($status === 'approved') {
            $h .= $disabled(['Approve ' . $object_type, 'Reject ' . $object_type],
                'Already approved. The only remaining transition is archive (audited).');
        } else {
            $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
            $nonce = function_exists('wp_nonce_field') ? wp_nonce_field(self::ACTION_REVIEW, '_wpnonce', true, false) : '';
            foreach (['approve' => 'Approve ' . $object_type, 'reject' => 'Reject ' . $object_type] as $decision => $label) {
                $h .= '<form method="post" action="' . (function_exists('esc_url') ? esc_url($post_url) : self::ea($post_url)) . '" class="fi-review-form">' . $nonce
                    . '<input type="hidden" name="action" value="' . self::ea(self::ACTION_REVIEW) . '">'
                    . '<input type="hidden" name="object_type" value="' . self::ea($object_type) . '">'
                    . '<input type="hidden" name="object_id" value="' . self::ea((string) $id) . '">'
                    . '<input type="hidden" name="workspace_id" value="' . self::ea((string) max(0, (int) $ws)) . '">'
                    . '<input type="hidden" name="decision" value="' . self::ea($decision) . '">'
                    . '<button type="submit" class="button ' . ($decision === 'approve' ? 'button-primary' : 'button-secondary') . '">' . self::e($label) . '</button>'
                    . '</form> ';
            }
            $h .= '<p class="fi-empty-state">' . self::e('Your decision is recorded in the review ledger. The evidence gate can still refuse an approval — that refusal is shown here, not silently swallowed.') . '</p>';
        }
        $h .= '</section>';
        return $h;
    }

    private static function actions_note() {
        return '<p class="fi-memory-policy"><strong>' . self::e('No AI generation, no auto-approval, no publishing.') . '</strong> '
            . self::e('Allowed: preview, view package, open evidence, review.') . '</p>';
    }

    private static function target_prompt($param) {
        return '<p class="fi-empty-state">' . self::e('Provide a ' . $param . ' query parameter to load a target. Invalid or missing targets show a safe message — no records are written.') . '</p>';
    }
    /**
     * UI/UX upgrade — in-page jump navigation for long workbench pages. Pure
     * anchors (no routing assumptions), keyboard-reachable, escaped.
     */
    private static function jump_nav(array $items) {
        $h = '<nav class="fi-workbench-nav" aria-label="' . self::ea('On this page') . '">';
        foreach ($items as $anchor => $label) {
            $h .= '<a href="#' . self::ea($anchor) . '">' . self::e($label) . '</a>';
        }
        return $h . '</nav>';
    }

    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    private static function error($msg) { return '<div class="notice notice-error inline"><p>' . self::e($msg) . '</p></div>'; }
    private static function clip($s, $max) { $s = (string) $s; return (strlen($s) > $max) ? (substr($s, 0, $max - 1) . '…') : $s; }
}
