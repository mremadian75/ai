<?php
/**
 * v1.1.0 — member-safe diagnostic surface + token guard wiring.
 *
 * v0.9.31.6 centralized the visible error payload in vesErrorStatusHtml().
 * This test must validate that helper contract instead of stale direct string
 * concatenation patterns in each error path.
 */
$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$apify = file_get_contents($root . '/includes/class-ves-apify-client.php');
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$billing = file_get_contents($root . '/includes/class-ves-billing.php');

$checks = [];
$add = function ($label, $cond) use (&$checks) { $checks[$label] = (bool) $cond; };

$extractBlock = static function(string $src, string $needle, int $window = 5000): string {
    $pos = strpos($src, $needle);
    if ($pos === false) { return ''; }
    $open = strpos($src, '{', $pos);
    if ($open === false) { return substr($src, $pos, $window); }
    $depth = 0;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $pos, $i - $pos + 1); }
        }
    }
    return substr($src, $pos, $window);
};

// Version bump.
$add('version bumped to 1.1.0', strpos($main, 'Version: 1.2.4') !== false && strpos($main, "VES_PLUGIN_VERSION',         '1.2.4'") !== false);

// build_safe_diagnostic helper exists and is leak-free by construction.
$add('build_safe_diagnostic helper exists', strpos($ajax, 'private static function build_safe_diagnostic(') !== false);
$start = strpos($ajax, 'private static function build_safe_diagnostic(');
$body = $start !== false ? substr($ajax, $start, 900) : '';
$add('safe_diagnostic exposes error_category', strpos($body, "'error_category'") !== false);
$add('safe_diagnostic exposes stage', strpos($body, "'stage'") !== false);
$add('safe_diagnostic exposes last_successful_stage', strpos($body, "'last_successful_stage'") !== false);
$add('safe_diagnostic does NOT expose file', strpos($body, "'file'") === false);
$add('safe_diagnostic does NOT expose line', strpos($body, "'line'") === false);
$add('safe_diagnostic does NOT expose actor_slug', strpos($body, 'actor_slug') === false);
$add('safe_diagnostic does NOT expose provider cost', strpos($body, 'cost') === false);
$add('safe_diagnostic does NOT expose token', stripos($body, 'token') === false);

// Wired into all three failure exits (error/fatal/shutdown).
$occurrences = substr_count($ajax, "'safe_diagnostic' => self::build_safe_diagnostic(");
$add('safe_diagnostic wired into >=3 payloads (error/fatal/shutdown)', $occurrences >= 3);

// Empty-token guard fires before HTTP in the Apify client.
$add('apify client has empty-token guard', strpos($apify, "trim((string) \$apify_token) === ''") !== false);
$req_pos = strpos($apify, 'public static function request(');
$guard_pos = strpos($apify, 'missing_backend_token');
$remote_pos = strpos($apify, 'wp_remote_request($url');
$add('token guard precedes wp_remote_request', $req_pos !== false && $guard_pos !== false && $remote_pos !== false && $guard_pos < $remote_pos);

// Frontend renders the leak-free line for ALL users (not gated on VES_IS_ADMIN).
$add('frontend captures err.safeDiagnostic', strpos($js, 'err.safeDiagnostic') !== false);
$add('frontend has vesSafeDiagLine renderer', strpos($js, 'const vesSafeDiagLine') !== false);
$helper = $extractBlock($js, 'const vesErrorStatusHtml');
$add('vesErrorStatusHtml helper declared', $helper !== '');
$add('vesErrorStatusHtml uses safeUserMessage', strpos($helper, 'safeUserMessage(') !== false);
$add('vesErrorStatusHtml includes vesSafeDiagLine(err)', strpos($helper, 'vesSafeDiagLine(err)') !== false);
$add('vesErrorStatusHtml includes vesRetryActionHtml(err)', strpos($helper, 'vesRetryActionHtml(err)') !== false);
$add('vesErrorStatusHtml includes renderAdminDetail(err)', strpos($helper, 'renderAdminDetail(err)') !== false);
$finish = $extractBlock($js, 'const finishPollingAfterError');
$add('finishPollingAfterError uses vesErrorStatusHtml', strpos($finish, 'vesErrorStatusHtml(') !== false);
$startCatch = strrpos($js, "setStatus(widget, vesErrorStatusHtml('Ejecución no iniciada', err, widget)");
$add('start/dispatch catch uses vesErrorStatusHtml', $startCatch !== false);
$add('safe diag line is not admin-gated', strpos($js, 'window.VES_IS_ADMIN && err?.safeDiagnostic') === false);

// Billing summary degrades visibly instead of blanking/fataling.
$add('render_account_summary wrapped in try/catch', strpos($billing, 'try {' . "\n" . '            $summary = self::get_summary($user_id);') !== false);
$add('billing degraded diagnostic recorded', strpos($billing, 'usage_summary_degraded') !== false);
$add('billing summary key defaults applied', strpos($billing, "\$summary += ['balance' => 0") !== false);

$bad = array_keys(array_filter($checks, static fn($ok) => !$ok));
if ($bad) {
    fwrite(STDERR, "\nv1.1.0 member-safe diagnostic check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v1.1.0 member-safe diagnostic checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
