<?php
/**
 * Promo "10 free social requests" — grant logic regression test.
 *
 * Verifies the promo grants exactly once per subscriber, only to subscribers,
 * reuses the existing credit-grant API, sizes the grant correctly, rolls back
 * its idempotency flag if the credit grant fails, and never throws on bad input.
 *
 * Run: php tests/test-ves-promo-social-credits.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }

// ── Shims ────────────────────────────────────────────────────────────────────
$GLOBALS['__users'] = [];
$GLOBALS['__meta'] = [];
$GLOBALS['__filters'] = [];
$GLOBALS['__adjust_calls'] = [];
$GLOBALS['__adjust_mode'] = 'ok'; // ok | wperror | throw

function get_userdata($id) { return $GLOBALS['__users'][(int) $id] ?? false; }
// Faithful enough get_users: filters by ves_customer role + NOT EXISTS meta + honors number cap.
function get_users($args = []) {
    $role = $args['role'] ?? '';
    $not_exists_key = '';
    if (!empty($args['meta_query'][0]['compare']) && $args['meta_query'][0]['compare'] === 'NOT EXISTS') {
        $not_exists_key = $args['meta_query'][0]['key'] ?? '';
    }
    $number = isset($args['number']) ? (int) $args['number'] : 0;
    $out = [];
    foreach ($GLOBALS['__users'] as $id => $u) {
        if ($role !== '' && (empty($u->roles) || !in_array($role, $u->roles, true))) { continue; }
        if ($not_exists_key !== '' && isset($GLOBALS['__meta'][$id][$not_exists_key]) && $GLOBALS['__meta'][$id][$not_exists_key] !== '') { continue; }
        $out[] = (int) $id;
        if ($number > 0 && count($out) >= $number) { break; }
    }
    return $out;
}
function get_user_meta($id, $k, $single = false) { return $GLOBALS['__meta'][(int) $id][$k] ?? ''; }
function update_user_meta($id, $k, $v) { $GLOBALS['__meta'][(int) $id][$k] = $v; return true; }
function delete_user_meta($id, $k, $v = '') { unset($GLOBALS['__meta'][(int) $id][$k]); return true; }
function apply_filters($tag, $value) { return array_key_exists($tag, $GLOBALS['__filters']) ? $GLOBALS['__filters'][$tag] : $value; }
function add_action() { return true; }
function is_wp_error($t) { return $t instanceof WP_Error; }
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } }

class VES_Usage_Billing {
    const CUSTOMER_ROLE = 'ves_customer';
    public static function adjust_user_credits($user_id, $amount, $message = '', $context = []) {
        $GLOBALS['__adjust_calls'][] = ['user' => (int) $user_id, 'amount' => (float) $amount, 'message' => $message, 'context' => $context];
        if ($GLOBALS['__adjust_mode'] === 'throw') { throw new RuntimeException('boom'); }
        if ($GLOBALS['__adjust_mode'] === 'wperror') { return new WP_Error('locked', 'fail'); }
        return 100.0 + (float) $amount;
    }
}

require_once dirname(__DIR__) . '/includes/class-ves-promo-social-credits.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) { if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; } };
$reset = function () { $GLOBALS['__users'] = []; $GLOBALS['__meta'] = []; $GLOBALS['__filters'] = []; $GLOBALS['__adjust_calls'] = []; $GLOBALS['__adjust_mode'] = 'ok'; };
$mk_customer = function ($id) { $GLOBALS['__users'][$id] = (object) ['ID' => $id, 'roles' => ['ves_customer']]; };
$mk_other = function ($id) { $GLOBALS['__users'][$id] = (object) ['ID' => $id, 'roles' => ['subscriber']]; };

// ── 1. Grants once to a subscriber, via the existing API, correct amount ─────
$reset(); $mk_customer(10);
$r = VES_Promo_Social_Credits::grant_to_user(10);
$ok($r === true, '1: grant_to_user returns true for an ungranted subscriber');
$ok(count($GLOBALS['__adjust_calls']) === 1, '1: exactly one credit grant performed');
$ok($GLOBALS['__adjust_calls'][0]['amount'] === 25.0, '1: grant amount = 25 credits (10 heavy social requests)');
$ok((string) get_user_meta(10, 'ves_promo_social10_granted', true) !== '', '1: idempotency flag is set');

// ── 2. Idempotent — never grants the same subscriber twice ───────────────────
$r2 = VES_Promo_Social_Credits::grant_to_user(10);
$ok($r2 === false, '2: second grant returns false');
$ok(count($GLOBALS['__adjust_calls']) === 1, '2: no second credit grant performed');

// ── 3. Only subscribers (ves_customer) are granted ───────────────────────────
$reset(); $mk_other(20);
$r3 = VES_Promo_Social_Credits::grant_to_user(20);
$ok($r3 === false && $GLOBALS['__adjust_calls'] === [], '3: non-subscriber is not granted');

// ── 4. Invalid input is safe ─────────────────────────────────────────────────
$reset();
$ok(VES_Promo_Social_Credits::grant_to_user(0) === false, '4: id 0 => false, no throw');
$ok(VES_Promo_Social_Credits::grant_to_user(-5) === false, '4: negative id => false');
$ok(VES_Promo_Social_Credits::grant_to_user('x') === false, '4: non-numeric id => false');

// ── 5. WP_Error from the grant rolls back the idempotency flag ───────────────
$reset(); $mk_customer(30); $GLOBALS['__adjust_mode'] = 'wperror';
$r5 = VES_Promo_Social_Credits::grant_to_user(30);
$ok($r5 === false, '5: grant returns false when credit API errors');
$ok((string) get_user_meta(30, 'ves_promo_social10_granted', true) === '', '5: idempotency flag rolled back so it can be retried');

// ── 6. Exception in the grant is caught (never breaks signup/admin) ──────────
$reset(); $mk_customer(31); $GLOBALS['__adjust_mode'] = 'throw';
$threw = false; try { $r6 = VES_Promo_Social_Credits::grant_to_user(31); } catch (\Throwable $e) { $threw = true; }
$ok($threw === false && $r6 === false, '6: a thrown error is caught and returns false');

// ── 7. Amount is filterable ──────────────────────────────────────────────────
$reset(); $mk_customer(40); $GLOBALS['__filters']['ves_promo_social10_credits'] = 50.0;
VES_Promo_Social_Credits::grant_to_user(40);
$ok($GLOBALS['__adjust_calls'][0]['amount'] === 50.0, '7: ves_promo_social10_credits filter overrides the amount');
$ok(VES_Promo_Social_Credits::FREE_REQUESTS === 10, '7: promo advertises 10 free requests');

// ── 8. Role-assignment hook grants for the customer role only ────────────────
$reset(); $mk_customer(50);
VES_Promo_Social_Credits::on_set_user_role(50, 'ves_customer');
$ok(count($GLOBALS['__adjust_calls']) === 1, '8: set_user_role(ves_customer) triggers a grant');
$reset(); $mk_other(51);
VES_Promo_Social_Credits::on_set_user_role(51, 'subscriber');
$ok($GLOBALS['__adjust_calls'] === [], '8: set_user_role(other) does not grant');

// ── 9. Grant context carries the promo marker (auditable in ledger) ──────────
$reset(); $mk_customer(60);
VES_Promo_Social_Credits::grant_to_user(60);
$ctx = $GLOBALS['__adjust_calls'][0]['context'];
$ok(($ctx['promo'] ?? '') === 'social10' && (int) ($ctx['free_requests'] ?? 0) === 10, '9: grant is tagged promo=social10, free_requests=10');

// ── 10. Bounded count + batched grant at scale (the count_pending fix) ───────
$reset();
for ($i = 1000; $i < 1000 + 600; $i++) { $mk_customer($i); } // 600 ungranted subscribers
$pending = VES_Promo_Social_Credits::count_pending();
$ok($pending === VES_Promo_Social_Credits::BATCH_MAX + 1, '10: count_pending is bounded to BATCH_MAX+1 (never loads all 600)');
$ok(VES_Promo_Social_Credits::has_more_than_batch() === true, '10: has_more_than_batch true when > one batch');
$res = VES_Promo_Social_Credits::grant_to_all(VES_Promo_Social_Credits::BATCH_MAX);
$ok($res['granted'] === VES_Promo_Social_Credits::BATCH_MAX, '10: one batch grants exactly BATCH_MAX subscribers');
$ok(VES_Promo_Social_Credits::count_pending() === 100, '10: remaining pending after one batch = 600 - 500 = 100');
$res2 = VES_Promo_Social_Credits::grant_to_all(VES_Promo_Social_Credits::BATCH_MAX);
$ok($res2['granted'] === 100 && VES_Promo_Social_Credits::count_pending() === 0, '10: second batch finishes the rest');
$ok(VES_Promo_Social_Credits::has_more_than_batch() === false, '10: has_more_than_batch false once drained');

echo "\nPromo 10 free social requests (grant logic): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
