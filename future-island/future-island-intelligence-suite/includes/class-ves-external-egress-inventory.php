<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_External_Egress_Inventory — Phase 9D.1 single external egress policy map.
 *
 * One honest, static registry of every code path that talks to the network.
 * Backed by grep-style tests (tests/test-ves-egress-lockdown-9d.php) that fail
 * the build if a NEW external call appears without being classified here, and
 * consumed by rc-readiness-check (strict mode blocks on unknown egress or any
 * paid Apify run-start outside the core guard). Pure/static — never performs
 * HTTP itself. PHP 7.4 compatible.
 */
final class VES_External_Egress_Inventory {

    const CLASSIFICATIONS = [
        'apify_run_start_core_guarded',   // paid run-start routed through VES_Apify_Client::request()
        'apify_read_guarded',             // status/dataset/user reads, header token, no token-in-URL
        'apify_direct_legacy_blocked',    // legacy direct dispatch now fail-closed (no HTTP)
        'ai_provider_gated',              // AI call behind config + operator action + usage path
        'ai_provider_legacy_requires_review', // direct legacy AI call, key+operator gated; consolidation roadmap
        'billing_provider_explicit',      // Stripe billing API, explicit and isolated from usage credits
        'public_content_fetch_guarded',   // SSRF-guarded fetch of public scraped content (no provider API)
        'unknown_external_egress_blocker',// anything unclassified — must never exist
        'test_only',
    ];

    /** The full static registry. Every row: provider/class/method/classification/guarded/notes. */
    public static function inventory() {
        return [
            // ── Apify run-start (paid) — ALL routed through the single core gate ──
            self::row('apify', 'VES_Apify_Client', 'request', 'apify_run_start_core_guarded', true,
                ['THE single dispatch gate: fail-closed allowlist + hard maxTotalChargeUsd + header-only token + security events.']),
            self::row('apify', 'VES_Run_Execution_Service', 'start_source_apify', 'apify_run_start_core_guarded', true,
                ['Builds run URL via ves_make_run_url (ceiling included) and dispatches through VES_Apify_Client::request.']),
            self::row('apify', 'VES_Google_Intel', 'run_actor_with_slug', 'apify_run_start_core_guarded', true,
                ['Adds maxTotalChargeUsd explicitly; dispatches through VES_Apify_Client::request.']),
            self::row('apify', 'VES_Monitor_Runner', 'dispatch', 'apify_run_start_core_guarded', true,
                ['ves_make_run_url + VES_Apify_Client::request.']),
            self::row('apify', 'VES_Brand_Audit_Execution_Service', 'start_source', 'apify_run_start_core_guarded', true,
                ['ves_make_run_url + VES_Apify_Client::request.']),
            self::row('apify', 'VES_Ajax_Controller', 'multiple dispatch handlers', 'apify_run_start_core_guarded', true,
                ['All run URLs built by ves_make_run_url and dispatched via VES_Apify_Client::request.']),
            self::row('apify', 'VES_Market_Signal_Commercial', 'run_apify_sync', 'apify_run_start_core_guarded', true,
                ['Phase 9D.2: direct wp_remote_post removed; run-sync routed through VES_Apify_Client::request with ceiling; fail-closed without the core client. get_apify_token remains ONLY for a boolean diagnostics presence check.']),
            self::row('apify', 'FIDTF_Core_Apify_Client_Adapter', 'start', 'apify_run_start_core_guarded', true,
                ['DTF bridge into the core client; run URL via ves_make_run_url (ceiling included).']),
            self::row('apify', 'FIDTF_Generic_Apify_Live_Adapter', 'start_direct_apify_run', 'apify_direct_legacy_blocked', true,
                ['Phase 9D.2: legacy direct dispatch DISABLED — fail-closed WP_Error + security event; zero HTTP.']),
            self::row('apify', 'FIDTF_TikTok_Live_Adapter', 'start_direct_apify_run', 'apify_direct_legacy_blocked', true,
                ['Phase 9D.2: legacy direct dispatch routes to the core adapter when available, otherwise fail-closed; zero direct HTTP.']),

            // ── Apify reads (status / datasets / account) — header token, no token-in-URL ──
            self::row('apify', 'VES_Apify_Client', 'fetch_run / fetch_items / abort_run', 'apify_read_guarded', true,
                ['Core read paths; Authorization header; scrubbed diagnostics.']),
            self::row('apify', 'VES_Admin', 'handle_apify_connection_test', 'apify_read_guarded', true,
                ['Admin-only /v2/users/me connectivity test; header token; capability + nonce gated.']),
            self::row('apify', 'VES_Ajax_Controller', 'dataset item fetch (host-validated)', 'apify_read_guarded', true,
                ['Validates host === api.apify.com and /v2/datasets/ path before fetching.']),
            self::row('apify', 'FIDTF_Generic_Apify_Live_Adapter', 'refresh_direct_apify_run / fetch_items_*', 'apify_read_guarded', true,
                ['Read-only status/dataset fetches; Authorization header; no run-start.']),
            self::row('apify', 'FIDTF_TikTok_Live_Adapter', 'refresh_direct_apify_run / fetch_dataset_*', 'apify_read_guarded', true,
                ['Read-only status/dataset fetches; Authorization header; no run-start.']),

            // ── AI provider (OpenAI) — no default-on generation anywhere ──
            self::row('openai', 'VES_OpenAI_Client', 'request', 'ai_provider_gated', true,
                ['Core AI client for operator-triggered analysis; API-key + credit gated; prompt-discipline tests; NOT generation (ves_generation_execution_enabled stays false and has no execution path).']),
            self::row('openai', 'includes/analysis.php', 'ves_request_item_analysis (direct post)', 'ai_provider_legacy_requires_review', true,
                ['Legacy direct call for scrape analysis; key + operator action gated; consolidation into VES_OpenAI_Client is roadmap.']),
            self::row('openai', 'VES_Market_Signal_Commercial', 'generate_with_openai (direct post)', 'ai_provider_legacy_requires_review', true,
                ['MarketSignal ChatGPT call; key + admin settings gated; roadmap consolidation.']),
            self::row('openai', 'FIDTF_AI_Bridge', 'plan (direct post)', 'ai_provider_legacy_requires_review', true,
                ['DTF planner call; key + source-enabled gated; deterministic fallback when absent.']),
            self::row('openai', 'FICI_OpenAI_Provider', 'generate (direct post)', 'ai_provider_legacy_requires_review', true,
                ['Creative Intelligence provider; key gated, admin module; roadmap consolidation.']),

            // ── Billing ──
            self::row('stripe', 'VES_Stripe_Billing', 'api_request', 'billing_provider_explicit', true,
                ['Explicit Stripe REST calls for billing; isolated from the usage-credit ledger; secrets never logged (covered by test-ves-stripe-billing.php).']),

            // ── Public content fetch (not a provider API) ──
            self::row('public_web', 'includes/analysis.php', 'ves_fetch_subtitle_text (wp_remote_get)', 'public_content_fetch_guarded', true,
                ['Fetches public subtitle/transcript URLs from scraped items; SSRF-guarded by ves_is_public_http_url; no credentials sent.']),
        ];
    }

    /** Summary for readiness diagnostics. */
    public static function summary() {
        $rows = self::inventory();
        $by = [];
        $unknown = 0;
        $unguarded_run_start = 0;
        foreach ($rows as $r) {
            $c = (string) $r['classification'];
            $by[$c] = ($by[$c] ?? 0) + 1;
            if ($c === 'unknown_external_egress_blocker') { $unknown++; }
            if (strpos($c, 'apify_run_start') === 0 && empty($r['guarded'])) { $unguarded_run_start++; }
            if ($c === 'apify_direct_legacy_blocked' && empty($r['guarded'])) { $unguarded_run_start++; }
        }
        return [
            'available' => true,
            'total' => count($rows),
            'by_classification' => $by,
            'unknown_count' => $unknown,
            'unguarded_run_start_count' => $unguarded_run_start,
            'single_dispatch_gate' => $unknown === 0 && $unguarded_run_start === 0,
        ];
    }

    /** Rows for a given provider (diagnostics). */
    public static function for_provider($provider) {
        $provider = strtolower(trim((string) $provider));
        $out = [];
        foreach (self::inventory() as $r) {
            if ($r['provider'] === $provider) { $out[] = $r; }
        }
        return $out;
    }

    private static function row($provider, $class, $method, $classification, $guarded, array $notes) {
        if (!in_array($classification, self::CLASSIFICATIONS, true)) { $classification = 'unknown_external_egress_blocker'; }
        return [
            'provider' => (string) $provider,
            'class' => (string) $class,
            'method' => (string) $method,
            'classification' => $classification,
            'guarded' => (bool) $guarded,
            'notes' => array_map('strval', $notes),
        ];
    }
}
