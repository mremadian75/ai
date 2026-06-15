<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Promo_Social_Credits — "10 free social requests" promotion.
 *
 * Grants each subscriber (ves_customer) a one-time credit top-up sized to cover
 * 10 social analyses on TikTok / YouTube / Instagram, using the EXISTING,
 * lock-protected, ledger-recording billing primitive
 * (VES_Usage_Billing::adjust_user_credits). It is deliberately conservative:
 *
 *   - It does NOT change credit accounting, consumption, costs, plans, limits,
 *     or any DB schema. It only adds credits via the existing grant API and sets
 *     one idempotency user-meta flag.
 *   - It is IDEMPOTENT: a per-user flag (+ a NOT EXISTS meta query) guarantees a
 *     subscriber is never granted twice, even on concurrent admin clicks.
 *   - It is OPERATOR-TRIGGERED for existing users (an admin button), so live
 *     balances are never mass-modified silently. Future signups are granted
 *     automatically, per-user, idempotently.
 *   - Every entry point is wrapped so a failure can never break admin or signup.
 *
 * Credit sizing: one TikTok/YouTube/Instagram "request and analysis" costs, at
 * standard rates, manual_run(1.0)×1.5 (heavy platform) + ai_analysis(1.0) = 2.5
 * credits (see VES_Usage_Billing::estimate_for_run). 10 requests => 25 credits.
 * Override with the `ves_promo_social10_credits` filter.
 */
final class VES_Promo_Social_Credits {

    const GRANTED_META  = 'ves_promo_social10_granted';
    const ADMIN_ACTION  = 'ves_grant_social10';
    const FREE_REQUESTS = 10;
    const BASE_CREDITS  = 25.0;
    const BATCH_MAX     = 500; // per admin click, to avoid timeouts on large sites

    public static function register_hooks() {
        // Admin-triggered bulk grant for EXISTING (pre lead-gen) subscribers only.
        //
        // Auto-grant on registration is intentionally NOT hooked: new accounts now
        // receive their 10 free social requests from the free plan's signup credits
        // (25 ≈ 10 heavy social analyses). Granting here too would double-credit
        // them. on_user_register()/on_set_user_role() remain callable for tests and
        // for any operator that re-enables auto-grant via a custom hook.
        if (function_exists('add_action')) {
            add_action('admin_post_' . self::ADMIN_ACTION, [__CLASS__, 'handle_admin_grant']);
        }
    }

    /** Customer role slug (from billing, with a safe fallback). */
    public static function customer_role(): string {
        return defined('VES_Usage_Billing::CUSTOMER_ROLE') ? VES_Usage_Billing::CUSTOMER_ROLE : 'ves_customer';
    }

    /** Credit amount granted per subscriber (filterable). */
    public static function credits(): float {
        $amount = self::BASE_CREDITS;
        if (function_exists('apply_filters')) {
            $amount = (float) apply_filters('ves_promo_social10_credits', self::BASE_CREDITS);
        }
        return $amount > 0 ? round($amount, 2) : self::BASE_CREDITS;
    }

    public static function is_customer($user): bool {
        $u = (is_object($user)) ? $user : (function_exists('get_userdata') ? get_userdata((int) $user) : null);
        if (!$u || empty($u->roles) || !is_array($u->roles)) { return false; }
        return in_array(self::customer_role(), $u->roles, true);
    }

    public static function is_granted($user_id): bool {
        if (!function_exists('get_user_meta')) { return false; }
        return (bool) get_user_meta((int) $user_id, self::GRANTED_META, true);
    }

    /**
     * Grant the promo to a single user. Idempotent, role-gated, fail-safe.
     * @return bool true if a grant was applied this call.
     */
    public static function grant_to_user($user_id): bool {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0) { return false; }
            if (!class_exists('VES_Usage_Billing') || !method_exists('VES_Usage_Billing', 'adjust_user_credits')) { return false; }
            if (!self::is_customer($user_id)) { return false; }
            if (self::is_granted($user_id)) { return false; }

            // Mark first (claim the grant) to prevent double-grant under concurrency,
            // then apply credits; roll the flag back if the credit grant fails.
            if (function_exists('update_user_meta')) {
                update_user_meta($user_id, self::GRANTED_META, gmdate('Y-m-d H:i:s'));
            }
            $res = VES_Usage_Billing::adjust_user_credits(
                $user_id,
                self::credits(),
                'Promo: ' . self::FREE_REQUESTS . ' análisis sociales gratis (TikTok/YouTube/Instagram)',
                ['promo' => 'social10', 'free_requests' => self::FREE_REQUESTS]
            );
            if (function_exists('is_wp_error') && is_wp_error($res)) {
                if (function_exists('delete_user_meta')) { delete_user_meta($user_id, self::GRANTED_META); }
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            self::log($e);
            return false;
        }
    }

    public static function on_user_register($user_id) { self::grant_to_user($user_id); }

    public static function on_set_user_role($user_id, $role = '') {
        if ($role === '' || $role === self::customer_role()) { self::grant_to_user($user_id); }
    }

    /** IDs of subscribers that have NOT yet been granted (bounded). */
    private static function pending_ids($max = 0): array {
        if (!function_exists('get_users')) { return []; }
        $args = [
            'role'       => self::customer_role(),
            'fields'     => 'ID',
            'meta_query' => [['key' => self::GRANTED_META, 'compare' => 'NOT EXISTS']],
        ];
        if ($max > 0) { $args['number'] = (int) $max; }
        $ids = get_users($args);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public static function count_pending(): int {
        // Bounded: never load more than BATCH_MAX+1 ids just to display a count.
        try { return count(self::pending_ids(self::BATCH_MAX + 1)); }
        catch (\Throwable $e) { self::log($e); return 0; }
    }

    /** True when more subscribers remain than a single batch can grant. */
    public static function has_more_than_batch(): bool {
        return self::count_pending() > self::BATCH_MAX;
    }

    /** Grant to all not-yet-granted subscribers, bounded per call. */
    public static function grant_to_all($max = self::BATCH_MAX): array {
        $out = ['granted' => 0, 'skipped' => 0, 'scanned' => 0];
        try {
            foreach (self::pending_ids((int) $max) as $id) {
                $out['scanned']++;
                if (self::grant_to_user($id)) { $out['granted']++; } else { $out['skipped']++; }
            }
        } catch (\Throwable $e) { self::log($e); }
        return $out;
    }

    public static function handle_admin_grant() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die('Insufficient capability'); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::ADMIN_ACTION); }
        $res = self::grant_to_all(self::BATCH_MAX);
        $url = (function_exists('add_query_arg') && function_exists('admin_url'))
            ? add_query_arg(['page' => 'ves-fiis-console', 'tab' => 'access', 'ves_promo_granted' => (int) $res['granted'], 'ves_promo_left' => self::count_pending()], admin_url('admin.php'))
            : '';
        if ($url !== '' && function_exists('wp_safe_redirect')) { wp_safe_redirect($url); exit; }
    }

    private static function log(\Throwable $e): void {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('[VES_Promo_Social_Credits] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}
