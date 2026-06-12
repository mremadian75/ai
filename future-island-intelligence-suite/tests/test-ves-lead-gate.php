<?php
/**
 * Public lead gate — form surface + demo CTA gating.
 *
 * Verifies the anonymous lead form collects the required business fields, that
 * the demo CTA only shows for non-admins who have run out of credits (never for
 * admins or users with credits), and that the CTA uses the configured booking
 * link. The exiting POST handler is exercised indirectly via these surfaces.
 *
 * Run: php tests/test-ves-lead-gate.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }

$GLOBALS['__opts'] = [];
$GLOBALS['__logged_in'] = false;
$GLOBALS['__caps'] = [];      // user_id => can_manage_options
$GLOBALS['__balance'] = [];   // user_id => balance

$GLOBALS['__mail'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($k = '') { return 'Test Site'; } }
if (!function_exists('wp_mail')) { function wp_mail($to, $subj, $body, $h = '', $a = []) { $GLOBALS['__mail'][] = compact('to', 'subj', 'body'); return true; } }
class VES_Access_Control { const OPT_PLAN_ACCESS = 'ves_plan_access_map'; }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return (bool) $GLOBALS['__logged_in']; } }
if (!function_exists('user_can')) { function user_can($id, $cap) { return !empty($GLOBALS['__caps'][(int) $id]); } }
if (!function_exists('esc_html')) { function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($v) { return (string) $v; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($v) { return (string) $v; } }
if (!function_exists('sanitize_email')) { function sanitize_email($v) { return (string) $v; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'http://t/wp-admin/' . ltrim((string) $p, '/'); } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'http://t/' . ltrim((string) $p, '/'); } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a = -1, $n = '_wpnonce', $r = true, $e = false) { return '<input type="hidden" name="' . $n . '" value="x">'; } }

// Fake billing so should_show_demo() has deterministic balances/costs.
class VES_Usage_Billing {
    const BALANCE_META_KEY = 'ves_credit_balance';
    public static $plan_set = [];
    public static function estimate_for_run($p = '') { return 2.5; }
    public static function get_balance($id) { return (float) ($GLOBALS['__balance'][(int) $id] ?? 0.0); }
    public static function get_plan($slug = '') { return ['slug' => 'free', 'signup_credits' => 25]; }
    public static function set_user_plan($id, $slug, $rec = true) { self::$plan_set[(int) $id] = $slug; return $slug; }
    public static function adjust_user_credits($id, $amount, $msg = '', $ctx = []) {
        $GLOBALS['__balance'][(int) $id] = round((float) ($GLOBALS['__balance'][(int) $id] ?? 0.0) + (float) $amount, 2);
        return $GLOBALS['__balance'][(int) $id];
    }
    public static function get_user_plan_slug($id) { return $GLOBALS['__plan'][(int) $id] ?? 'free'; }
}
if (!function_exists('get_users')) {
    function get_users($args = []) {
        $number = isset($args['number']) ? (int) $args['number'] : 0;
        $as_int = (($args['fields'] ?? '') === 'ID');
        $out = [];
        foreach (($GLOBALS['__user_ids'] ?? []) as $id) {
            $out[] = $as_int ? (int) $id : (object) ['ID' => (int) $id];
            if ($number > 0 && count($out) >= $number) { break; }
        }
        return $out;
    }
}

require_once dirname(__DIR__) . '/includes/class-ves-lead-gate.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// ── 1. The lead form collects every required business field ──────────────────
$GLOBALS['__logged_in'] = false;
$form = VES_Lead_Gate::render_form();
foreach ([
    'ves_lead_first_name' => 'first name',
    'ves_lead_last_name'  => 'last name',
    'ves_lead_corp_email' => 'corporate email',
    'ves_lead_phone'      => 'phone',
    'ves_lead_linkedin'   => 'LinkedIn',
    'ves_lead_position'   => 'position',
] as $field => $label) {
    $ok(strpos($form, 'name="' . $field . '"') !== false, "1: lead form has the $label field");
}
$ok(strpos($form, 'name="action" value="ves_lead_register"') !== false, '1: form posts to the lead-register action');
$ok(strpos($form, 'ves_lead_nonce') !== false, '1: form carries a nonce field');
$ok(strpos($form, 'type="email"') !== false, '1: corporate email uses an email input');
$ok(strpos($form, 'left:-9999px') !== false, '1: form includes a honeypot anti-spam field');
$ok(strpos($form, 'ves-lead-shell') !== false, '1: form carries the readability wrapper class (light tokens / contrast fix)');

// ── 2. Demo CTA gating ───────────────────────────────────────────────────────
// Admin → never (unlimited).
$GLOBALS['__caps'][1] = true; $GLOBALS['__balance'][1] = 0.0;
$ok(VES_Lead_Gate::should_show_demo(1) === false, '2: admin never sees the demo CTA');
// Free user WITH credits → no CTA.
$GLOBALS['__caps'][2] = false; $GLOBALS['__balance'][2] = 10.0;
$ok(VES_Lead_Gate::should_show_demo(2) === false, '2: user with credits does not see the CTA');
// Free user OUT of credits → CTA.
$GLOBALS['__caps'][3] = false; $GLOBALS['__balance'][3] = 1.0; // < 2.5 (one request)
$ok(VES_Lead_Gate::should_show_demo(3) === true, '2: exhausted free user sees the CTA');
$ok(VES_Lead_Gate::should_show_demo(0) === false, '2: invalid user id is safe');

// ── 3. CTA content + configured booking link ─────────────────────────────────
$GLOBALS['__opts']['ves_demo_booking_url'] = 'https://calendly.com/team/30min';
$cta = VES_Lead_Gate::demo_cta_html(3);
$ok(strpos($cta, 'ves-demo-cta') !== false, '3: CTA renders the banner container');
$ok(strpos($cta, '30') !== false, '3: CTA mentions the 30-minute session');
$ok(strpos($cta, 'https://calendly.com/team/30min') !== false, '3: CTA uses the configured booking URL');
$ok(VES_Lead_Gate::booking_url() === 'https://calendly.com/team/30min', '3: booking_url() returns the saved option');

// ── 4. Logged-in visitors are not re-shown the form ──────────────────────────
$GLOBALS['__logged_in'] = true;
$ok(strpos(VES_Lead_Gate::render_form(), 'ves_lead_first_name') === false, '4: logged-in users do not see the lead form again');

// ── 5. (a) Corporate-email enforcement ───────────────────────────────────────
foreach (['x@gmail.com', 'y@outlook.com', 'z@yahoo.com', 'a@hotmail.com', 'b@icloud.com', 'c@mailinator.com'] as $free_email) {
    $ok(VES_Lead_Gate::is_free_email_domain($free_email) === true, "5: '$free_email' is rejected as a free/personal domain");
}
foreach (['ceo@acme.com', 'jane@startup.io', 'team@brand.co'] as $corp) {
    $ok(VES_Lead_Gate::is_free_email_domain($corp) === false, "5: corporate '$corp' is accepted");
}

// ── 6. (c) Requests counter ──────────────────────────────────────────────────
$GLOBALS['__caps'][5] = false; $GLOBALS['__balance'][5] = 25.0; // fresh: 25/2.5 = 10
$ok(VES_Lead_Gate::requests_remaining(5) === 10, '6: fresh free user shows 10 requests remaining');
$GLOBALS['__balance'][5] = 12.5; // 5 left
$ok(VES_Lead_Gate::requests_remaining(5) === 5, '6: partial balance maps to 5 requests remaining');
$ok(VES_Lead_Gate::should_show_requests_status(5) === true, '6: counter shows while requests remain');
$ok(VES_Lead_Gate::requests_total() === 10, '6: total advertised is 10');
$GLOBALS['__caps'][6] = true; $GLOBALS['__balance'][6] = 25.0;
$ok(VES_Lead_Gate::should_show_requests_status(6) === false, '6: admins never see the counter');
$pill = VES_Lead_Gate::requests_status_html(5);
$ok(strpos($pill, 'ves-requests-pill') !== false && strpos($pill, '5') !== false && strpos($pill, '10') !== false, '6: pill shows N / 10');

// ── 7. (b) Admin Users column + CSV export rows ──────────────────────────────
$cols = VES_Lead_Gate::add_users_columns(['username' => 'Username']);
$ok(isset($cols['ves_lead']), '7: a Lead column is added to the Users list');
$ok(VES_Lead_Gate::render_users_column('orig', 'other_col', 9) === 'orig', '7: unrelated columns are passed through untouched');
$header = VES_Lead_Gate::export_rows()[0];
$ok($header === ['name', 'corp_email', 'phone', 'linkedin', 'position', 'source', 'created_at', 'credit_balance'], '7: CSV export has the expected lead header row');

// ── 8. provision_free_plan rebases lead users onto FREE + 10 requests ────────
// Simulate the user_register hook having awarded the default plan's 100 credits.
$GLOBALS['__balance'][70] = 100.0;
$result = VES_Lead_Gate::provision_free_plan(70);
$ok(VES_Usage_Billing::$plan_set[70] === 'free', '8: lead user is forced onto the FREE plan (not the default starter)');
$ok($result === 25.0, '8: balance is re-based to the free allotment (25 = 10 social requests)');
$GLOBALS['__balance'][71] = 0.0; // fresh, nothing awarded yet
$ok(VES_Lead_Gate::provision_free_plan(71) === 25.0, '8: a fresh lead user ends up with exactly 25 credits');
$ok(VES_Lead_Gate::provision_free_plan(0) === -1.0, '8: invalid user id is handled safely');

// ── 9. Migrate existing users to the free tier (admin/free are untouched) ────
$GLOBALS['__plan'] = []; $GLOBALS['__user_ids'] = [80, 81, 82];
$GLOBALS['__caps'][80] = true;                       // 80 = admin
$GLOBALS['__plan'][81] = 'starter'; $GLOBALS['__balance'][81] = 100.0; // 81 = needs migration
$GLOBALS['__plan'][82] = 'free';    $GLOBALS['__balance'][82] = 5.0;   // 82 = already free
$res = VES_Lead_Gate::migrate_existing_to_free(500);
$ok($res['migrated'] === 1, '9: exactly one (the starter) user migrated');
$ok((VES_Usage_Billing::$plan_set[81] ?? '') === 'free', '9: starter user moved onto the free plan');
$ok($GLOBALS['__balance'][81] === 25.0, '9: migrated user rebased to the 10-request (25 credit) allotment');
$ok($GLOBALS['__balance'][82] === 5.0, '9: already-free user balance left untouched');
$ok(!isset(VES_Usage_Billing::$plan_set[80]), '9: admin is never migrated');

// ── 10. Recommended free-tier limits remove the daily-run blocker ────────────
$GLOBALS['__opts']['ves_plan_access_map'] = ['free' => ['limits' => ['max_daily_runs' => 2, 'max_monthly_runs' => 10], 'modules' => ['social' => 'enabled']]];
$ok(VES_Lead_Gate::apply_recommended_free_limits() === true, '10: apply_recommended_free_limits() succeeds');
$lim = $GLOBALS['__opts']['ves_plan_access_map']['free']['limits'];
$ok((int) $lim['max_daily_runs'] === 0, '10: max_daily_runs reset to 0 (the blocker is removed)');
$ok((int) $lim['max_monthly_runs'] === 10, '10: max_monthly_runs set to 10 (the request cap)');
$ok((int) $lim['signup_credits'] === 25, '10: signup_credits = 25 (10 social analyses)');
$ok((int) $lim['max_credits_per_run'] === 10, '10: max_credits_per_run leaves room for a heavy run');
$ok(($GLOBALS['__opts']['ves_plan_access_map']['free']['modules']['social'] ?? '') === 'enabled', '10: existing access-map sections are preserved');

// ── 11. SECURITY: the form must NOT auto-login an existing account ───────────
$src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-ves-lead-gate.php');
$ok(strpos($src, 'login_and_redirect($existing->ID)') === false, '11: existing-email branch no longer auto-logs-in (account-takeover fix)');
$ok(strpos($src, 'never auto-login an existing account') !== false, '11: explicit no-auto-login security note present');
$ok(strpos($src, "self::META_PREFIX . 'linkedin_norm'") !== false, '11: LinkedIn dedupe key is stored + checked');

// ── 12. normalize_linkedin canonicalizes for dedupe ──────────────────────────
$a = VES_Lead_Gate::normalize_linkedin('https://www.linkedin.com/in/Mahan-Emadian/');
$b = VES_Lead_Gate::normalize_linkedin('http://linkedin.com/in/mahan-emadian');
$c = VES_Lead_Gate::normalize_linkedin('linkedin.com/in/mahan-emadian/?utm=x');
$ok($a === 'linkedin.com/in/mahan-emadian', '12: scheme/www/trailing-slash/case normalized');
$ok($a === $b && $b === $c, '12: equivalent LinkedIn URLs normalize to the same key');

// ── 13. New-lead admin notification is sent (best-effort) ────────────────────
$GLOBALS['__mail'] = [];
$GLOBALS['__opts']['admin_email'] = 'ops@brand.com';
$m = new ReflectionMethod('VES_Lead_Gate', 'notify_new_lead');
$m->setAccessible(true);
$m->invoke(null, 999, ['name' => 'Mahan Em', 'email' => 'mahan@acme.com', 'phone' => '+34', 'linkedin' => 'https://linkedin.com/in/x', 'position' => 'CMO']);
$ok(count($GLOBALS['__mail']) === 1, '13: a notification email is sent on new lead');
$ok(($GLOBALS['__mail'][0]['to'] ?? '') === 'ops@brand.com', '13: sent to the admin email (filterable)');
$ok(strpos($GLOBALS['__mail'][0]['body'] ?? '', 'mahan@acme.com') !== false, '13: lead details included in the email');

// ── 14. CSV formula/DDE injection is neutralized in the lead export ──────────
$ok(VES_Lead_Gate::csv_safe_cell('=cmd|\'/c calc\'!A1') === "'=cmd|'/c calc'!A1", '14: a leading "=" cell is prefixed to defuse formula injection');
$ok(VES_Lead_Gate::csv_safe_cell('+1234567890') === "'+1234567890", '14: a leading "+" (phone) cell is defused');
$ok(VES_Lead_Gate::csv_safe_cell('-2+3') === "'-2+3", '14: a leading "-" cell is defused');
$ok(VES_Lead_Gate::csv_safe_cell('@SUM(A1)') === "'@SUM(A1)", '14: a leading "@" cell is defused');
$ok(VES_Lead_Gate::csv_safe_cell("\t=1") === "'\t=1", '14: a leading TAB cell is defused');
$ok(VES_Lead_Gate::csv_safe_cell('Mahan Emadian') === 'Mahan Emadian', '14: an ordinary value is left untouched');
$ok(VES_Lead_Gate::csv_safe_cell('') === '', '14: an empty cell stays empty');

// ── 15. Strict LinkedIn URL validation (host, not substring) ─────────────────
$ok(VES_Lead_Gate::is_linkedin_url('https://www.linkedin.com/in/mahan') === true, '15: a real linkedin.com profile URL is accepted');
$ok(VES_Lead_Gate::is_linkedin_url('https://es.linkedin.com/in/mahan') === true, '15: a linkedin.com subdomain is accepted');
$ok(VES_Lead_Gate::is_linkedin_url('https://evil.com/?x=linkedin.com') === false, '15: a hostile URL with "linkedin.com" only in the query is rejected');
$ok(VES_Lead_Gate::is_linkedin_url('https://linkedin.com.attacker.net/in/x') === false, '15: a look-alike host (linkedin.com.attacker.net) is rejected');
$ok(VES_Lead_Gate::is_linkedin_url('linkedin.com/in/x') === false, '15: a scheme-less string is rejected');
$ok(VES_Lead_Gate::is_linkedin_url('') === false, '15: an empty value is rejected');

echo "\nPublic lead gate (form + demo CTA): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
