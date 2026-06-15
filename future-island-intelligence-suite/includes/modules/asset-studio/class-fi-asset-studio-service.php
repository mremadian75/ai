<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Asset_Studio_Service — brief → platform-ready outputs. v0.4.0 scope is
 * Google Ads asset blocks (the strongest existing output): deterministic
 * drafts created from briefs by the intake bridge, plus the per-run block in
 * the Trend Finder report. No Google Ads API integration, no publishing.
 */
final class FI_Asset_Studio_Service {

    public static function store_available(): bool {
        return class_exists('VES_Intelligence_Store');
    }

    public static function workspace_id(): int {
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'workspace_id_for_user') && function_exists('get_current_user_id')) {
            $ws = (int) VES_Memory_Records::workspace_id_for_user(get_current_user_id());
            if ($ws > 0) { return $ws; }
        }
        return 1;
    }

    /** Google Ads drafts (newest first), with parsed copy fields. */
    public static function google_ads_drafts(int $workspace_id, int $limit = 12): array {
        if (!self::store_available()) { return []; }
        try {
            $drafts = (array) VES_Intelligence_Store::list_drafts(['workspace_id' => $workspace_id, 'limit' => 100]);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($drafts as $draft) {
            if (sanitize_key((string) ($draft['channel'] ?? '')) !== 'google_ads') { continue; }
            $draft['parsed_fields'] = self::parse_asset_body((string) ($draft['body'] ?? ''));
            $out[] = $draft;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    /**
     * Parse the deterministic draft body into copyable field groups.
     * Body shape (intake bridge): header lines, then per group
     * "Headlines, max 40 chars:" followed by "- TEXT [n/40]" lines.
     */
    public static function parse_asset_body(string $body): array {
        $groups = ['headlines' => [], 'long_headlines' => [], 'descriptions' => []];
        $meta = ['status' => '', 'evidence_caveat' => '', 'proof_needed' => '', 'cta' => ''];
        $current = '';
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            if (stripos($line, 'Status:') === 0) { $meta['status'] = trim(substr($line, 7)); continue; }
            if (stripos($line, 'Evidence caveat:') === 0) { $meta['evidence_caveat'] = trim(substr($line, 16)); continue; }
            if (stripos($line, 'Proof needed:') === 0) { $meta['proof_needed'] = trim(substr($line, 13)); continue; }
            if (stripos($line, 'CTA suggestion:') === 0) { $meta['cta'] = trim(substr($line, 15)); continue; }
            if (stripos($line, 'Headlines, max 40') === 0) { $current = 'headlines'; continue; }
            if (stripos($line, 'Long headlines, max 90') === 0) { $current = 'long_headlines'; continue; }
            if (stripos($line, 'Descriptions, max 90') === 0) { $current = 'descriptions'; continue; }
            if ($current !== '' && strpos($line, '- ') === 0) {
                $text = preg_replace('/\s*\[\d+\/\d+\]$/', '', substr($line, 2));
                $limit = $current === 'headlines' ? 40 : 90;
                $count = function_exists('mb_strlen') ? mb_strlen((string) $text) : strlen((string) $text);
                $groups[$current][] = [
                    'text' => (string) $text,
                    'count' => $count,
                    'limit' => $limit,
                    'valid' => $count <= $limit,
                ];
            }
        }
        return ['groups' => $groups, 'meta' => $meta];
    }

    /** Briefs without a Google Ads draft yet (the next-action queue). */
    public static function briefs_without_draft(int $workspace_id, int $limit = 8): array {
        if (!self::store_available()) { return []; }
        try {
            $briefs = (array) VES_Intelligence_Store::list_briefs(['workspace_id' => $workspace_id, 'limit' => 50]);
            $drafts = (array) VES_Intelligence_Store::list_drafts(['workspace_id' => $workspace_id, 'limit' => 200]);
        } catch (Throwable $e) {
            return [];
        }
        $covered = [];
        foreach ($drafts as $draft) {
            if (sanitize_key((string) ($draft['channel'] ?? '')) === 'google_ads') {
                $covered[(int) ($draft['brief_id'] ?? 0)] = true;
            }
        }
        $out = [];
        foreach ($briefs as $brief) {
            if ((string) ($brief['status'] ?? '') === 'archived') { continue; }
            if (!isset($covered[(int) ($brief['id'] ?? 0)])) { $out[] = $brief; }
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    public static function status(): array {
        if (!self::store_available()) {
            return ['state' => 'unavailable', 'detail' => 'Intelligence store is not loaded.'];
        }
        if (!class_exists('VES_Source_Intake')) {
            return ['state' => 'read_only', 'detail' => 'Intake actions unavailable — existing drafts are viewable but new blocks cannot be created.'];
        }
        return ['state' => 'available', 'detail' => 'Google Ads field blocks; social/video hooks are surfaced per-run in the Trend Finder report.'];
    }
}
