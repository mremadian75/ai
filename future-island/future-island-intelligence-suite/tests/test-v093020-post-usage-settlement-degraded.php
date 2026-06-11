<?php
/**
 * v0.9.30.21 — post-provider billing settlement must be non-fatal.
 *
 * Root cause: post_standard_usage_or_error() called self::error() on WP_Error from
 * post_reserved_usage(), terminating a request whose provider run had already succeeded.
 * For unlimited/admin users, reserve_usage() could also return a non-empty usage_key
 * when record_event() failed (missing DB table), producing a phantom key that caused
 * "No existe reserva para este usage key" at settlement time.
 *
 * This release:
 * 1. post_standard_usage_or_error() now logs and returns — never calls self::error().
 * 2. settle_standard_usage_by_delivery() same: is_wp_error($settled) logs, returns $formatted.
 * 3. reserve_usage() unlimited path: returns WP_Error if record_event() fails (no phantom key).
 */
$root = dirname(__DIR__);
$ajax    = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$billing = file_get_contents($root . '/includes/class-ves-billing.php');
$main    = file_get_contents($root . '/future-island-intelligence-suite.php');

$checks = [];
$add = function ($label, $cond) use (&$checks) { $checks[$label] = (bool) $cond; };

// Version bump.
$add('version bumped to 1.1.0', strpos($main, 'Version: 1.2.6') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.6'") !== false);

// --- Fix 1: post_standard_usage_or_error() must not call self::error() on WP_Error ---
// Locate the function body.
$fn_start = strpos($ajax, 'private static function post_standard_usage_or_error(');
$fn_body  = $fn_start !== false ? substr($ajax, $fn_start, 1800) : '';

// After WP_Error from post_reserved_usage, must log — not call self::error().
$add('post_standard_usage_or_error logs wp_error instead of terminating',
    strpos($fn_body, 'post_usage_wp_error_degraded') !== false);

// The old self::error() call with stage=>post_usage must be gone from that function.
// We check: within the function body there must be no self::error(... 'stage' => 'post_usage')
$has_fatal_post_usage = (bool) preg_match(
    "/self::error\([^)]*'stage'\s*=>\s*'post_usage'/s",
    $fn_body
);
$add('post_standard_usage_or_error no longer calls self::error on wp_error path', !$has_fatal_post_usage);

// Must not void usage on missing-reservation (voiding a non-existent row is harmless but
// the old code voided then fataled — the fatal is gone now).
$add('post_standard_usage_or_error returns instead of dying on wp_error', strpos($fn_body, 'return;') !== false);

// --- Fix 2: settle_standard_usage_by_delivery() must not call self::error() on WP_Error ---
$settle_start = strpos($ajax, 'private static function settle_standard_usage_by_delivery(');
$settle_body  = $settle_start !== false ? substr($ajax, $settle_start, 3600) : '';

$add('settle_standard_usage_by_delivery logs wp_error instead of terminating',
    strpos($settle_body, 'settle_usage_wp_error_degraded') !== false);

$has_fatal_settle = (bool) preg_match(
    "/self::error\([^)]*'stage'\s*=>\s*'settle_usage'/s",
    $settle_body
);
$add('settle_standard_usage_by_delivery no longer calls self::error on wp_error path', !$has_fatal_settle);

// settle must return $formatted even when billing fails.
$add('settle_standard_usage_by_delivery returns formatted on wp_error',
    strpos($settle_body, 'return $formatted;') !== false);

// --- Fix 3: reserve_usage() unlimited path must check record_event() result ---
$reserve_start = strpos($billing, 'public static function reserve_usage(');
$reserve_body  = $reserve_start !== false ? substr($billing, $reserve_start, 3000) : '';

// Find the unlimited user block.
$unlimited_block_start = strpos($reserve_body, 'is_unlimited_user(');
$unlimited_block = $unlimited_block_start !== false ? substr($reserve_body, $unlimited_block_start, 1000) : '';

$add('reserve_usage unlimited path checks record_event result',
    strpos($unlimited_block, 'if (!$event_id)') !== false);

$add('reserve_usage unlimited path returns WP_Error on record_event failure',
    strpos($unlimited_block, "new WP_Error('usage_event_failed'") !== false);

// The WP_Error from unlimited user will be caught by the admin degradation path in
// reserve_standard_usage_or_error(), which returns '' — verified by checking that the
// admin-WP_Error degradation block includes 'usage_event_failed' in its allow-list,
// OR that the general WP_Error path for admin returns ''.
// The admin degradation allow-list already includes 'usage_event_failed'.
$add('reserve_standard_usage_or_error admin degradation handles usage_event_failed',
    strpos($ajax, "'usage_event_failed','missing_usage_key','usage_table_missing','db_error'") !== false ||
    strpos($ajax, "'usage_event_failed', 'missing_usage_key', 'usage_table_missing', 'db_error'") !== false ||
    (strpos($ajax, 'usage_event_failed') !== false && strpos($ajax, 'reserve_usage_wp_error_degraded') !== false));

// --- Structural: classify_error_category must still handle post_usage source for non-provider errors ---
$add('classify_error_category still covers post_usage stage string',
    strpos($ajax, "'post_usage'") !== false || strpos($ajax, '"post_usage"') !== false);

// --- No regression: pre-provider reserve still blocks non-admin on WP_Error ---
$add('reserve_standard_usage_or_error still calls self::error for non-admin wp_error',
    strpos($ajax, "'error_category' => 'usage_reservation_failed'") !== false);

$bad = array_keys(array_filter($checks, static fn($ok) => !$ok));
if ($bad) {
    fwrite(STDERR, "\nv0.9.30.21 post-usage-settlement-degraded check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.30.21 post-usage-settlement-degraded checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
