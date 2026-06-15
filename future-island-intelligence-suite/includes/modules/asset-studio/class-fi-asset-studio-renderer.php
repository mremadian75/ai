<?php
if (!defined('ABSPATH')) { exit; }

/** FI_Asset_Studio_Renderer — exportable production fields, not a dashboard. */
final class FI_Asset_Studio_Renderer {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function u($s) { return function_exists('esc_url') ? esc_url((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function cut($s, $max): string { return function_exists('mb_substr') ? mb_substr((string) $s, 0, (int) $max, 'UTF-8') : substr((string) $s, 0, (int) $max); }

    private static function action_form(string $action, array $fields, string $label, string $class = 'button button-small'): string {
        $post_url = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field($action, '_wpnonce', true, false) : '';
        $h = '<form class="fi-intake-inline-action" method="post" action="' . self::u($post_url) . '">' . $nonce;
        $h .= '<input type="hidden" name="action" value="' . self::ea($action) . '">';
        foreach ($fields as $key => $value) {
            $h .= '<input type="hidden" name="' . self::ea((string) $key) . '" value="' . self::ea((string) $value) . '">';
        }
        $h .= '<button type="submit" class="' . self::ea($class) . '">' . self::e($label) . '</button></form>';
        return $h;
    }

    public static function render_html(): string {
        $ws = FI_Asset_Studio_Service::workspace_id();
        $drafts = FI_Asset_Studio_Service::google_ads_drafts($ws);
        $queue = FI_Asset_Studio_Service::briefs_without_draft($ws);
        $status = FI_Asset_Studio_Service::status();
        $actions_available = class_exists('VES_Source_Intake');

        $h  = '<div class="wrap ves-wrap fi-signal-room fi-module-page" id="fi-asset-studio">';
        $h .= '<header class="fi-room-context">';
        $h .= '<p class="fi-breadcrumb fiis-sr-eyebrow">' . self::e('Future Island · Asset Studio') . '</p>';
        $h .= '<h1>' . self::e('Asset Studio') . '</h1>';
        $h .= '<p class="fi-intake-sub">' . self::e('Brief → platform-ready output fields. Google Ads blocks carry their evidence caveat and proof-needed note; every field is copyable into the real platform. No ad platform API, no publishing.') . '</p>';
        $h .= '<p class="fi-module-detail">' . self::e((string) ($status['detail'] ?? '')) . '</p>';
        $h .= '</header>';

        $h .= '<div class="fi-room-grid"><main class="fi-room-main">';
        $h .= '<section class="fi-intake-card"><h2>' . self::e('Google Ads asset blocks') . '</h2>';
        if (empty($drafts)) {
            $h .= '<p class="fi-empty-state">' . self::e('No Google Ads blocks yet — create one from an approved brief (right rail).') . '</p>';
        }
        foreach ($drafts as $draft) {
            $id = (int) ($draft['id'] ?? 0);
            $meta = (array) ($draft['metadata'] ?? []);
            $parsed = (array) ($draft['parsed_fields'] ?? []);
            $groups = (array) ($parsed['groups'] ?? []);
            $pmeta = (array) ($parsed['meta'] ?? []);
            $draft_status = (string) ($draft['status'] ?? 'generated');
            $h .= '<article class="fi-asset-block">';
            $h .= '<div class="fidtf-source-row-head"><strong>' . self::e((string) ($draft['title'] ?? ('Draft #' . $id))) . '</strong>';
            $h .= '<span class="fi-status-badge fiis-badge-' . self::ea($draft_status === 'approved' ? 'approved' : 'candidate') . '">' . self::e(ucwords(str_replace('_', ' ', $draft_status))) . '</span></div>';
            $caveat = (string) ($meta['evidence_caveat'] ?? $pmeta['evidence_caveat'] ?? '');
            $proof = (string) ($meta['proof_needed'] ?? $pmeta['proof_needed'] ?? '');
            if ($caveat !== '') { $h .= '<p class="fidtf-asset-caveat"><strong>' . self::e('Evidence caveat:') . '</strong> ' . self::e($caveat) . '</p>'; }
            if ($proof !== '') { $h .= '<p class="fidtf-asset-caveat"><strong>' . self::e('Proof needed:') . '</strong> ' . self::e($proof) . '</p>'; }
            if (!empty($pmeta['cta'])) { $h .= '<p><strong>' . self::e('CTA suggestion:') . '</strong> ' . self::e((string) $pmeta['cta']) . '</p>'; }
            $group_labels = ['headlines' => 'Headlines (max 40)', 'long_headlines' => 'Long headlines (max 90)', 'descriptions' => 'Descriptions (max 90)'];
            foreach ($group_labels as $key => $label) {
                $fields = (array) ($groups[$key] ?? []);
                if (empty($fields)) { continue; }
                $h .= '<div class="fidtf-asset-field-group"><h5>' . self::e($label) . '</h5><div class="fidtf-asset-field-list">';
                foreach ($fields as $field) {
                    $valid = !empty($field['valid']);
                    $h .= '<div class="fidtf-asset-field ' . ($valid ? 'is-valid' : 'is-invalid') . '">';
                    $h .= '<span>' . self::e((string) $field['text']) . '</span>';
                    $h .= '<small>' . self::e($field['count'] . '/' . $field['limit'] . ' ' . ($valid ? 'OK' : 'flagged')) . '</small>';
                    if ($valid) {
                        $h .= '<button type="button" class="fidtf-copy-btn" data-fi-copy="' . self::ea((string) $field['text']) . '">' . self::e('Copy') . '</button>';
                    }
                    $h .= '</div>';
                }
                $h .= '</div></div>';
            }
            // Action rail per block: the audited intake actions, reused.
            if ($actions_available) {
                $h .= '<div class="fi-asset-actions">';
                if (in_array($draft_status, ['generated', 'edited'], true)) {
                    $h .= self::action_form(VES_Source_Intake::ACTION_APPROVE_DRAFT, ['workspace_id' => $ws, 'draft_id' => $id], 'Approve output');
                } elseif ($draft_status === 'approved') {
                    $h .= self::action_form(VES_Source_Intake::ACTION_USAGE, ['workspace_id' => $ws, 'draft_id' => $id], 'Record usage', 'button button-small button-secondary');
                }
                $h .= self::action_form(VES_Source_Intake::ACTION_MEMORY, ['workspace_id' => $ws, 'draft_id' => $id], 'Create memory candidate');
                $h .= '<a class="button button-small" href="' . self::u(function_exists('admin_url') ? admin_url('tools.php?page=fi-draft-workbench&brief_id=' . (int) ($draft['brief_id'] ?? 0)) : '#') . '">' . self::e('Open in workbench') . '</a>';
                $h .= '</div>';
            }
            $h .= '</article>';
        }
        $h .= '</section>';
        $h .= '</main><aside class="fi-room-rail">';

        $h .= '<section class="fi-intake-card"><h2>' . self::e('Create from a brief') . '</h2>';
        if (empty($queue)) {
            $h .= '<p class="fi-intake-hint">' . self::e('Every current brief already has its Google Ads block. New briefs appear here.') . '</p>';
        } else {
            $h .= '<p class="fi-intake-hint">' . self::e('Briefs without an asset block yet:') . '</p>';
            foreach ($queue as $brief) {
                $bid = (int) ($brief['id'] ?? 0);
                $h .= '<div class="fi-asset-queue-row"><span>' . self::e('#' . $bid . ' · ' . self::cut((string) ($brief['title'] ?? ''), 46)) . '</span>';
                $h .= $actions_available
                    ? self::action_form(VES_Source_Intake::ACTION_DRAFT, ['workspace_id' => $ws, 'brief_id' => $bid], 'Create Google Ads block', 'button button-small button-primary')
                    : '<span class="fi-intake-disabled">' . self::e('Actions unavailable') . '</span>';
                $h .= '</div>';
            }
        }
        $h .= '</section>';

        $h .= '<section class="fi-intake-card"><h2>' . self::e('Other output routes') . '</h2>';
        $h .= '<p class="fi-intake-hint">' . self::e('Social hooks, short-form video hook mechanics and content angles are generated per run inside the Trend Finder report (hook families, content angle briefs, creative territories). A dedicated multi-platform studio is future work — not claimed here.') . '</p>';
        $h .= '</section>';
        $h .= '</aside></div></div>';
        return $h;
    }
}
