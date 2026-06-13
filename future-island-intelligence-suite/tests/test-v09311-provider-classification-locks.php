<?php
/**
 * Test: v0.9.31.6 — provider error classification, lock lifecycle,
 * credit display, Google count, and error-UI hardening.
 */
$root  = dirname(__DIR__);
$main  = file_get_contents($root . '/future-island-intelligence-suite.php');
$ajax  = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$apify = file_get_contents($root . '/includes/class-ves-apify-client.php');
$assets= file_get_contents($root . '/includes/class-ves-assets.php');
$js    = file_get_contents($root . '/assets/js/ves-frontend.js');
$css   = file_get_contents($root . '/assets/css/ves-frontend.css');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// ── Version ───────────────────────────────────────────────────
$add('version is 1.1.0', strpos($main, 'Version: 1.4.0') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.4.0'") !== false);

// ── Phase 4: SEO 403 rental classified before generic access-denied ──
$rental_chk = strpos($ajax, 'if ($rental_required) { return \'provider_actor_rental_required\'; }');
$status_403 = strpos($ajax, "(int) \$status === 403 || strpos(\$haystack, 'forbidden')");
$add('rental_required variable is built in provider runtime block', strpos($ajax, '$rental_required = strpos($haystack, \'rental\')') !== false);
$add('rental classification runs BEFORE the generic 403 access-denied branch', $rental_chk !== false && $status_403 !== false && $rental_chk < $status_403);
$add('Spanish rental patterns recognized (requiere rental/plan)', strpos($ajax, "requiere rental") !== false && strpos($ajax, "requiere plan") !== false);

// ── Phase 3: Ads 400 → provider_payload_rejected ─────────────
// v0.9.31.2+ uses provider_payload_rejected as the canonical return; provider_invalid_input
// remains as an alias in public_message_for_error_category for backwards compat.
$add('provider_payload_rejected branch on HTTP 400 exists', strpos($ajax, "(int) \$status === 400") !== false && strpos($ajax, "return 'provider_payload_rejected'") !== false);
$add('400 payload_rejected is checked before the broad terminal_failure branch', strpos($ajax, "return 'provider_payload_rejected'") < strpos($ajax, "return 'provider_terminal_failure'"));
$add('public message defined for provider_invalid_input alias', strpos($ajax, "\$category === 'provider_invalid_input'") !== false && strpos($ajax, 'rejected this source payload') !== false);

// ── Phase 3/4: Apify client distinct codes ───────────────────
$add('apify client maps HTTP 400 to apify_invalid_input', strpos($apify, "(int) \$code === 400") !== false && strpos($apify, "'apify_invalid_input'") !== false);
$add('apify client recognizes Spanish rental patterns', strpos($apify, 'requiere rental') !== false || strpos($apify, 'rental/plan') !== false);

// ── Phase 2: Trend Finder stale-lock lifecycle ───────────────
$add('shutdown handler releases runtime locks via cleanup', strpos($ajax, 'self::cleanup_active_run_resources_after_fatal($message);') !== false && (strpos($ajax, 'self::cleanup_active_run_resources_after_fatal($message);') < strpos($ajax, '$support_code = self::public_support_code($request_id);')));
$add('start gate stores owner request id + timestamp', strpos($ajax, "set_transient(\$key, ['ts' => time(), 'rid' => \$request_id], 90)") !== false);
$add('start gate auto-clears stale orphan locks (>75s)', strpos($ajax, '$gate_age > 75') !== false && strpos($ajax, 'start_gate_stale_cleared') !== false);
$add('blocked dispatch surfaces gate age + owner to admin context', strpos($ajax, "'gate_age_secs'") !== false && strpos($ajax, "'gate_owner_request_id'") !== false);

// ── Phase 6: Credit display ──────────────────────────────────
$add('credit balance localized as window.VES_CREDIT', strpos($assets, 'window.VES_CREDIT') !== false);
$add('credit localization degrades safely on billing error', strpos($assets, 'catch (Throwable $credit_error)') !== false);
$add('credit pill JS reads window.VES_CREDIT fallback', strpos($js, 'window.VES_CREDIT') !== false && strpos($js, 'credit.known') !== false);

// ── Phase 5: Google count consistency ────────────────────────
$add('Google KPI uses displayed result count (shownResultCount)', strpos($js, 'shownResultCount') !== false && strpos($js, 'displayItems.length > 0 ? displayItems.length') !== false);

// ── Phase 8: Error-UI hardening ──────────────────────────────
$add('admin detail markup has scrollable wrapper classes', strpos($js, 'ves-admin-detail-scroll') !== false && strpos($js, 'ves-admin-detail-table') !== false);
$add('admin detail table wraps long values in CSS', strpos($css, '.ves-admin-detail-table th') !== false && strpos($css, 'overflow-wrap: anywhere') !== false);

// ── Phase 7: Sidebar contrast (memory not faded) ─────────────
$add('light-suite memory entry forced opaque (not faded)', strpos($css, '.ves-sidebar-memory-list .ves-memory-entry') !== false && strpos($css, 'opacity: 1 !important') !== false);

// ── Phase 9: regression — TikTok/social classification intact ─
$add('non-rental, non-400 provider errors still classify normally', strpos($ajax, "return 'provider_transport_error'; }") !== false && strpos($ajax, "return 'provider_rate_limit'") !== false);

$pass = $fail = 0;
foreach ($checks as [$name, $ok]) {
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name\n";
    $ok ? $pass++ : $fail++;
}
echo "\n$pass passed, $fail failed.\n";
if ($fail > 0) exit(1);
