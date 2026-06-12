<?php
/**
 * VES_Stripe_Billing — opt-in Stripe monetization foundation.
 *
 * Exercises the security- and money-critical logic without any network or DB:
 *   - inert-by-default (no key => disabled, no behavior)
 *   - mode inference from the key prefix (test vs live)
 *   - pack price derivation (explicit cents override + price_label parsing)
 *   - webhook signature verification (valid, tampered, stale, malformed)
 *   - idempotent grant ledger (first event grants, redelivery is a no-op)
 *   - fulfillment resolves user + credits from session metadata and grants once
 *
 * Run: php tests/test-ves-stripe-billing.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('WP_DEBUG')) { define('WP_DEBUG', false); }

// ── Option store + WP shims ──────────────────────────────────────────────────
$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('add_action')) { function add_action() { return true; } }
if (!function_exists('add_shortcode')) { function add_shortcode() { return true; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t\0<>]+/', '', (string) $s)); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); } }

// User-meta store + meta-based get_users (for Stripe customer ↔ user linking).
$GLOBALS['__umeta'] = [];
if (!function_exists('update_user_meta')) { function update_user_meta($id, $k, $v) { $GLOBALS['__umeta'][(int) $id][$k] = $v; return true; } }
if (!function_exists('get_user_meta')) { function get_user_meta($id, $k, $single = true) { return $GLOBALS['__umeta'][(int) $id][$k] ?? ''; } }
if (!function_exists('get_users')) {
    function get_users($args = []) {
        $mk = $args['meta_key'] ?? ''; $mv = $args['meta_value'] ?? '';
        $hits = [];
        foreach ($GLOBALS['__umeta'] as $uid => $meta) {
            if (($meta[$mk] ?? null) === $mv) { $hits[] = (int) $uid; }
        }
        return $hits;
    }
}

// Capture grants instead of touching a ledger.
$GLOBALS['__grants'] = [];
if (!class_exists('VES_Usage_Billing')) {
    class VES_Usage_Billing {
        public static function adjust_user_credits($user_id, $amount, $message = '', $context = []) {
            $GLOBALS['__grants'][] = ['user_id' => (int) $user_id, 'amount' => (float) $amount, 'context' => $context];
            return (float) $amount;
        }
        public static function get_credit_pack($id) {
            $packs = self::packs();
            return $packs[$id] ?? null;
        }
        public static function get_credit_packs() { return self::packs(); }
        private static function packs() {
            return [
                'pack_50'  => ['id' => 'pack_50',  'label' => 'Pack 50',  'credits' => 50,  'price_label' => '€15', 'status' => 'active'],
                'pack_100' => ['id' => 'pack_100', 'label' => 'Pack 100', 'credits' => 100, 'price_label' => '€25', 'status' => 'active'],
            ];
        }
        public static function is_unlimited_user($uid) { return false; }
        public static function get_summary($uid) { return ['balance' => 0]; }
        // Reentrant per-user critical section (trivial in a single-threaded test).
        public static function with_user_lock($uid, $cb) { return call_user_func($cb); }
        public static function set_user_plan($uid, $slug, $rec = true) { $GLOBALS['__plan_set'][(int) $uid] = $slug; return $slug; }
    }
}

// Fake $wpdb simulating the UNIQUE(event_id) idempotency table.
class _StripeWPDB {
    public $prefix = 'wp_';
    public $rows_affected = 0;
    public $insert_attempts = 0;
    private $seen = [];
    public function prepare($q, ...$a) {
        foreach ($a as $v) {
            $pos = strpos($q, '%s');
            $posd = strpos($q, '%d');
            if ($pos === false && $posd === false) { break; }
            if ($posd === false || ($pos !== false && $pos < $posd)) {
                $q = substr_replace($q, "'" . $v . "'", $pos, 2);
            } else {
                $q = substr_replace($q, (string) (int) $v, $posd, 2);
            }
        }
        return $q;
    }
    public function query($q) {
        if (stripos($q, 'INSERT IGNORE') !== false) {
            $this->insert_attempts++;
            if (preg_match("/VALUES \\('([^']*)'/", $q, $m)) {
                $id = $m[1];
                if (isset($this->seen[$id])) { $this->rows_affected = 0; }
                else { $this->seen[$id] = true; $this->rows_affected = 1; }
            }
            return $this->rows_affected;
        }
        return 0;
    }
    public function get_var($q) {
        if (preg_match("/event_id = '([^']*)'/", $q, $m)) {
            return isset($this->seen[$m[1]]) ? 1 : 0;
        }
        return 0;
    }
}

require_once dirname(__DIR__) . '/includes/class-ves-stripe-billing.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$set = function (array $patch) { $GLOBALS['__opts'][VES_Stripe_Billing::OPTION] = $patch; };

// ── 1. Inert by default: no settings => disabled ─────────────────────────────
$GLOBALS['__opts'] = [];
$ok(VES_Stripe_Billing::is_enabled() === false, '1: disabled by default (no key, no flag)');
$ok(VES_Stripe_Billing::mode() === 'unknown', '1: mode is unknown without a key');

// ── 2. Enabled flag without a key is still inert ─────────────────────────────
$set(['enabled' => 1, 'secret_key' => '']);
$ok(VES_Stripe_Billing::is_enabled() === false, '2: enabled flag alone does NOT activate Stripe (key required)');

// ── 3. Mode inference from the key prefix ────────────────────────────────────
$set(['enabled' => 1, 'secret_key' => 'sk_test_abc123']);
$ok(VES_Stripe_Billing::is_enabled() === true && VES_Stripe_Billing::mode() === 'test', '3: sk_test_ key => enabled, test mode');
$set(['enabled' => 1, 'secret_key' => 'sk_live_abc123']);
$ok(VES_Stripe_Billing::mode() === 'live', '3: sk_live_ key => live mode');

// ── 4. Pack price: explicit cents override wins ──────────────────────────────
$set(['enabled' => 1, 'secret_key' => 'sk_test_x', 'pack_prices' => ['pack_50' => 1234]]);
$ok(VES_Stripe_Billing::pack_price_cents(['id' => 'pack_50', 'price_label' => '€15']) === 1234, '4: explicit per-pack price override is used');

// ── 5. Pack price: parses the price_label when no override ───────────────────
$set(['enabled' => 1, 'secret_key' => 'sk_test_x']);
$ok(VES_Stripe_Billing::pack_price_cents(['id' => 'pack_50', 'price_label' => '€15']) === 1500, '5: "€15" label parses to 1500 cents');
$ok(VES_Stripe_Billing::pack_price_cents(['id' => 'p', 'price_label' => '€9,99']) === 999, '5: "€9,99" label parses to 999 cents');
$ok(VES_Stripe_Billing::pack_price_cents(['id' => 'p', 'price_label' => 'Free']) === 0, '5: a label with no number yields 0 (unsellable)');

// ── 6. Webhook signature: valid signature within tolerance ───────────────────
$secret = 'whsec_testsecret';
$payload = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed']);
$t = time();
$valid_sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
$header = 't=' . $t . ',v1=' . $valid_sig;
$ok(VES_Stripe_Billing::verify_signature($payload, $header, $secret) === true, '6: a correctly-signed, fresh payload verifies');

// ── 7. Webhook signature: tampered payload fails ─────────────────────────────
$ok(VES_Stripe_Billing::verify_signature($payload . 'X', $header, $secret) === false, '7: a tampered payload fails verification');

// ── 8. Webhook signature: wrong secret fails ─────────────────────────────────
$ok(VES_Stripe_Billing::verify_signature($payload, $header, 'whsec_other') === false, '8: a signature from a different secret fails');

// ── 9. Webhook signature: stale timestamp (replay) fails ─────────────────────
$old = $t - 100000;
$old_sig = hash_hmac('sha256', $old . '.' . $payload, $secret);
$ok(VES_Stripe_Billing::verify_signature($payload, 't=' . $old . ',v1=' . $old_sig, $secret) === false, '9: a stale timestamp (outside tolerance) is rejected as replay');

// ── 10. Webhook signature: malformed header fails safely ─────────────────────
$ok(VES_Stripe_Billing::verify_signature($payload, 'garbage', $secret) === false, '10: a malformed signature header is rejected');
$ok(VES_Stripe_Billing::verify_signature($payload, '', $secret) === false, '10: an empty signature header is rejected');

// ── 11. Idempotent grant: first event grants, redelivery is a no-op ──────────
$GLOBALS['__opts'][VES_Stripe_Billing::PROCESSED_EVENTS_OPTION] = [];
$GLOBALS['__grants'] = [];
$first = VES_Stripe_Billing::grant_credits(7, 100, 'evt_unique_1', ['source' => 'stripe']);
$again = VES_Stripe_Billing::grant_credits(7, 100, 'evt_unique_1', ['source' => 'stripe']);
$ok($first === true && $again === false, '11: same event grants once, redelivery returns false');
$ok(count($GLOBALS['__grants']) === 1 && $GLOBALS['__grants'][0]['amount'] === 100.0, '11: exactly one credit grant of the right amount reached the ledger');

// ── 12. Idempotent grant: rejects bad inputs ─────────────────────────────────
$ok(VES_Stripe_Billing::grant_credits(0, 100, 'evt_x') === false, '12: grant with no user is rejected');
$ok(VES_Stripe_Billing::grant_credits(7, 0, 'evt_y') === false, '12: grant of zero credits is rejected');
$ok(VES_Stripe_Billing::grant_credits(7, 100, '') === false, '12: grant with no dedup key is rejected');

// ── 13. Fulfillment: resolves user + credits from metadata, grants once ──────
$GLOBALS['__opts'][VES_Stripe_Billing::PROCESSED_EVENTS_OPTION] = [];
$GLOBALS['__grants'] = [];
$session = [
    'id' => 'cs_test_1',
    'payment_status' => 'paid',
    'metadata' => ['ves_user_id' => '42', 'ves_pack_id' => 'pack_100', 'ves_credits' => '100'],
    'amount_total' => 2500,
    'currency' => 'eur',
];
$res = VES_Stripe_Billing::fulfill_from_session($session, 'evt_fulfil_1');
$ok($res === true && count($GLOBALS['__grants']) === 1, '13: a paid session fulfils exactly one grant');
$ok($GLOBALS['__grants'][0]['user_id'] === 42 && $GLOBALS['__grants'][0]['amount'] === 100.0, '13: grant carries the right user and credit amount');
$ok(VES_Stripe_Billing::fulfill_from_session($session, 'evt_fulfil_1') === false, '13: re-fulfilling the same event is a no-op');

// ── 14. Fulfillment: recovers credits from the pack when metadata lacks them ─
$GLOBALS['__opts'][VES_Stripe_Billing::PROCESSED_EVENTS_OPTION] = [];
$GLOBALS['__grants'] = [];
$session2 = ['id' => 'cs_2', 'payment_status' => 'paid', 'metadata' => ['ves_user_id' => '9', 'ves_pack_id' => 'pack_50']];
VES_Stripe_Billing::fulfill_from_session($session2, 'evt_fulfil_2');
$ok(!empty($GLOBALS['__grants']) && $GLOBALS['__grants'][0]['amount'] === 50.0, '14: missing credits metadata is recovered from the pack definition');

// ── 15. Atomic table-based idempotency (UNIQUE event_id) ─────────────────────
$GLOBALS['__opts'][VES_Stripe_Billing::EVENTS_DB_OPTION] = VES_Stripe_Billing::EVENTS_DB_VERSION; // skip table creation
$GLOBALS['wpdb'] = new _StripeWPDB();
$first = VES_Stripe_Billing::mark_event_processed('evt_table_1');
$dup = VES_Stripe_Billing::mark_event_processed('evt_table_1');
$ok($first === true && $dup === false, '15: the table path is exactly-once (INSERT IGNORE on UNIQUE event_id)');
$ok($GLOBALS['wpdb']->insert_attempts === 2, '15: each delivery attempts the atomic insert (DB decides the winner)');
$other = VES_Stripe_Billing::mark_event_processed('evt_table_2');
$ok($other === true, '15: a different event id is still accepted once');
$GLOBALS['wpdb'] = null; // restore no-DB shape for any later use

// ── 16. Subscriptions: enablement requires a plan map ────────────────────────
$set(['enabled' => 1, 'secret_key' => 'sk_test_x', 'subscriptions_enabled' => 1, 'plan_prices' => []]);
$ok(VES_Stripe_Billing::subscriptions_enabled() === false, '16: subscriptions stay off until at least one Price→plan mapping exists');
$set(['enabled' => 1, 'secret_key' => 'sk_test_x', 'subscriptions_enabled' => 1,
      'plan_prices' => ['price_pro' => ['plan_slug' => 'pro', 'monthly_credits' => 200, 'label' => 'Pro']]]);
$ok(VES_Stripe_Billing::subscriptions_enabled() === true, '16: subscriptions enable once a mapping is configured');

// ── 17. resolve_plan_for_price / price_id_from_invoice ───────────────────────
$plan = VES_Stripe_Billing::resolve_plan_for_price('price_pro');
$ok(is_array($plan) && $plan['plan_slug'] === 'pro' && (int) $plan['monthly_credits'] === 200, '17: a mapped Price resolves to its plan + credits');
$ok(VES_Stripe_Billing::resolve_plan_for_price('price_unknown') === null, '17: an unmapped Price resolves to null');
$inv = ['id' => 'in_1', 'subscription' => 'sub_1', 'customer' => 'cus_1', 'lines' => ['data' => [['price' => ['id' => 'price_pro']]]]];
$ok(VES_Stripe_Billing::price_id_from_invoice($inv) === 'price_pro', '17: the Price id is extracted from the invoice line items');

// ── 18. resolve_user_from_object: metadata / nested / client_reference_id ─────
$ok(VES_Stripe_Billing::resolve_user_from_object(['metadata' => ['ves_user_id' => '42']]) === 42, '18: resolves user from top-level metadata');
$ok(VES_Stripe_Billing::resolve_user_from_object(['subscription_details' => ['metadata' => ['ves_user_id' => '7']]]) === 7, '18: resolves user from subscription_details metadata');
$ok(VES_Stripe_Billing::resolve_user_from_object(['client_reference_id' => '9']) === 9, '18: falls back to client_reference_id');
$ok(VES_Stripe_Billing::resolve_user_from_object(['foo' => 'bar']) === 0, '18: returns 0 when no user can be resolved');
// Hardening: malformed payloads (string where an object is expected) must not crash.
$ok(VES_Stripe_Billing::resolve_user_from_object(['subscription_details' => 'oops', 'metadata' => 'nope', 'parent' => 'x', 'lines' => 'y']) === 0, '18: malformed (string-shaped) nested fields are tolerated, not fatal');
$ok(VES_Stripe_Billing::price_id_from_invoice(['lines' => ['data' => [['price' => 'oops', 'plan' => 'nope']]]]) === '', '18: a line with string-shaped price/plan does not crash and yields no id');

// ── 19. invoice.paid: applies plan + grants monthly credits once ─────────────
$GLOBALS['__opts'][VES_Stripe_Billing::PROCESSED_EVENTS_OPTION] = [];
$GLOBALS['__grants'] = [];
$GLOBALS['__plan_set'] = [];
$GLOBALS['wpdb'] = null; // use option-based dedup for this block
$invoice = ['id' => 'in_42', 'subscription' => 'sub_9', 'customer' => 'cus_9',
    'metadata' => ['ves_user_id' => '42'],
    'lines' => ['data' => [['price' => ['id' => 'price_pro']]]]];
$r = VES_Stripe_Billing::handle_invoice_paid($invoice);
$ok($r === 'subscription_renewed', '19: a paid subscription invoice renews the subscription');
$ok(($GLOBALS['__plan_set'][42] ?? '') === 'pro', '19: the subscriber is placed on the mapped plan');
$ok(count($GLOBALS['__grants']) === 1 && $GLOBALS['__grants'][0]['amount'] === 200.0, '19: the monthly credit allotment is granted');
$ok(VES_Stripe_Billing::handle_invoice_paid($invoice) === 'subscription_duplicate', '19: a redelivered invoice.paid does NOT double-grant (idempotent on invoice id)');
$ok(count($GLOBALS['__grants']) === 1, '19: still exactly one grant after redelivery');

// ── 20. invoice.paid guards: non-subscription + unmapped price ───────────────
$ok(VES_Stripe_Billing::handle_invoice_paid(['id' => 'in_x', 'lines' => ['data' => []]]) === 'not_subscription', '20: a non-subscription invoice is ignored');
$ok(VES_Stripe_Billing::handle_invoice_paid(['id' => 'in_y', 'subscription' => 'sub_y', 'lines' => ['data' => [['price' => ['id' => 'price_zzz']]]]]) === 'unmapped_price', '20: an invoice for an unmapped price is ignored');

// ── 21. subscription canceled/deleted reverts the user to free ───────────────
$GLOBALS['__plan_set'] = [];
$r = VES_Stripe_Billing::handle_subscription_deleted(['metadata' => ['ves_user_id' => '55'], 'customer' => 'cus_55']);
$ok($r === 'subscription_canceled' && ($GLOBALS['__plan_set'][55] ?? '') === 'free', '21: a deleted subscription reverts the user to the free plan');
$GLOBALS['__plan_set'] = [];
$r = VES_Stripe_Billing::handle_subscription_updated(['status' => 'canceled', 'metadata' => ['ves_user_id' => '56']]);
$ok($r === 'reverted_canceled' && ($GLOBALS['__plan_set'][56] ?? '') === 'free', '21: a canceled status update reverts the user to free');
$r = VES_Stripe_Billing::handle_subscription_updated(['status' => 'active', 'metadata' => ['ves_user_id' => '57']]);
$ok($r === 'status_active', '21: an active status update keeps the entitlement');

// ── 22. customer ↔ user linking + reverse lookup ─────────────────────────────
$GLOBALS['__umeta'] = [];
VES_Stripe_Billing::link_customer(77, 'cus_link');
$ok(VES_Stripe_Billing::user_id_for_customer('cus_link') === 77, '22: a linked Stripe customer resolves back to its WP user');
$ok(VES_Stripe_Billing::user_id_for_customer('cus_missing') === 0, '22: an unknown customer resolves to 0');
$ok(VES_Stripe_Billing::customer_id_for_user(77) === 'cus_link', '22: customer_id_for_user returns the linked customer');
$ok(VES_Stripe_Billing::customer_id_for_user(78) === '', '22: a user with no link returns an empty customer id');

// ── 22b. Billing Portal availability gating ──────────────────────────────────
$set(['enabled' => 1, 'secret_key' => 'sk_test_x']);
$ok(VES_Stripe_Billing::portal_available(77) === true, '22: portal is offered to a user with a linked customer');
$ok(VES_Stripe_Billing::portal_available(78) === false, '22: portal is NOT offered to a user without a linked customer');
$set(['enabled' => 0, 'secret_key' => '']);
$ok(VES_Stripe_Billing::portal_available(77) === false, '22: portal is hidden entirely when Stripe is disabled');

// ── 23. process_event routing dispatches to the right handler ────────────────
$set(['enabled' => 1, 'secret_key' => 'sk_test_x', 'subscriptions_enabled' => 1,
      'plan_prices' => ['price_pro' => ['plan_slug' => 'pro', 'monthly_credits' => 200, 'label' => 'Pro']]]);
$GLOBALS['__opts'][VES_Stripe_Billing::PROCESSED_EVENTS_OPTION] = [];
$GLOBALS['__grants'] = [];
$GLOBALS['__plan_set'] = [];
$r = VES_Stripe_Billing::process_event('invoice.paid', 'evt_r1', $invoice);
$ok($r === 'subscription_renewed', '23: process_event routes invoice.paid to the renewal handler');
$r = VES_Stripe_Billing::process_event('customer.subscription.deleted', 'evt_r2', ['metadata' => ['ves_user_id' => '58']]);
$ok($r === 'subscription_canceled', '23: process_event routes subscription.deleted to the cancel handler');
$ok(VES_Stripe_Billing::process_event('some.unhandled.event', 'evt_r3', []) === 'ignored', '23: unhandled event types are ignored');

echo "\nVES_Stripe_Billing (opt-in monetization): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
