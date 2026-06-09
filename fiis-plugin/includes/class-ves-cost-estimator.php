<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Cost_Estimator — Phase 1 cost spine.
 *
 * Single, conservative place to translate (model, tokens) -> provider USD cost
 * and provider USD cost -> internal credits. This is the "estimator" half of the
 * cost spine: it never charges, never blocks, and never makes network calls.
 *
 * IMPORTANT — Phase 1 scope:
 *  - The pricing map below is an INITIAL, approximate, *filterable* table. It is
 *    not an authoritative billing source. Override it in one place via the
 *    `ves_ai_model_pricing` filter when real provider prices are confirmed.
 *  - estimated_credits is ADVISORY telemetry only. Actual wallet deduction stays
 *    100% in VES_Billing and is unchanged by this class (see PHASE-1 report).
 *
 * Prices are USD per 1,000,000 tokens (input / output), matching how providers
 * publish them. Unknown models fall back to a conservative blended rate and are
 * flagged so callers/telemetry can see the estimate was a fallback.
 */
final class VES_Cost_Estimator {

    /** Conservative chars-per-token ratio used only when exact token counts are absent. */
    const CHARS_PER_TOKEN = 4.0;

    /** Safety multiplier applied to character-based token estimates (never under-estimate). */
    const TOKEN_SAFETY_MULTIPLIER = 1.15;

    /**
     * Initial, filterable model pricing map (USD per 1M tokens).
     * Keys are matched case-insensitively and by longest known prefix, so
     * "gpt-4.1-mini-2025-04-14" resolves to the "gpt-4.1-mini" row.
     */
    public static function pricing_map(): array {
        $map = [
            // OpenAI Responses-API models referenced by VES_Config defaults.
            'gpt-4.1'       => ['input' => 2.00, 'output' => 8.00],
            'gpt-4.1-mini'  => ['input' => 0.40, 'output' => 1.60],
            'gpt-4.1-nano'  => ['input' => 0.10, 'output' => 0.40],
            'gpt-4o'        => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini'   => ['input' => 0.15, 'output' => 0.60],
            'gpt-5'         => ['input' => 1.25, 'output' => 10.00],
            'gpt-5-mini'    => ['input' => 0.25, 'output' => 2.00],
            'gpt-5-nano'    => ['input' => 0.05, 'output' => 0.40],
            'o4-mini'       => ['input' => 1.10, 'output' => 4.40],
        ];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('ves_ai_model_pricing', $map);
            if (is_array($filtered) && !empty($filtered)) {
                $map = $filtered;
            }
        }
        return $map;
    }

    /** Conservative blended fallback price (USD per 1M tokens) for unknown models. */
    public static function fallback_pricing(): array {
        $fallback = ['input' => 1.00, 'output' => 3.00];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('ves_ai_model_pricing_fallback', $fallback);
            if (is_array($filtered) && isset($filtered['input'], $filtered['output'])) {
                $fallback = $filtered;
            }
        }
        return $fallback;
    }

    /**
     * Resolve per-1M pricing for a model.
     * @return array{input:float,output:float,source:string,matched:string}
     */
    public static function model_pricing($model): array {
        $model_l = strtolower(trim((string) $model));
        $map = self::pricing_map();

        if ($model_l !== '' && isset($map[$model_l])) {
            return [
                'input'   => (float) $map[$model_l]['input'],
                'output'  => (float) $map[$model_l]['output'],
                'source'  => 'known',
                'matched' => $model_l,
            ];
        }

        // Longest-prefix match so dated/suffixed model ids resolve to their base.
        $best = '';
        foreach ($map as $key => $_price) {
            $key_l = strtolower((string) $key);
            if ($model_l !== '' && strpos($model_l, $key_l) === 0 && strlen($key_l) > strlen($best)) {
                $best = $key_l;
            }
        }
        if ($best !== '') {
            return [
                'input'   => (float) $map[$best]['input'],
                'output'  => (float) $map[$best]['output'],
                'source'  => 'prefix',
                'matched' => $best,
            ];
        }

        $fb = self::fallback_pricing();
        return [
            'input'   => (float) $fb['input'],
            'output'  => (float) $fb['output'],
            'source'  => 'fallback',
            'matched' => '',
        ];
    }

    /** Conservative token estimate from raw text (ceil, with safety multiplier). */
    public static function estimate_tokens_from_text($text): int {
        if (is_array($text)) { $text = wp_json_encode($text); }
        $len = strlen((string) $text);
        if ($len <= 0) { return 0; }
        $tokens = ($len / self::CHARS_PER_TOKEN) * self::TOKEN_SAFETY_MULTIPLIER;
        return (int) ceil($tokens);
    }

    /**
     * Provider USD cost for a known token split.
     * @return float USD, rounded to 6 decimals.
     */
    public static function estimate_provider_cost($model, $input_tokens, $output_tokens): float {
        $pricing = self::model_pricing($model);
        $input_tokens  = max(0, (int) $input_tokens);
        $output_tokens = max(0, (int) $output_tokens);
        $cost = ($input_tokens / 1000000.0) * $pricing['input']
              + ($output_tokens / 1000000.0) * $pricing['output'];
        return round($cost, 6);
    }

    /** USD price of one internal credit (advisory; filterable). Default: 1 credit = $0.02. */
    public static function usd_per_credit(): float {
        $value = 0.02;
        if (function_exists('apply_filters')) {
            $value = (float) apply_filters('ves_ai_usd_per_credit', $value);
        }
        return $value > 0 ? $value : 0.02;
    }

    /** Margin multiplier applied to credit estimates (advisory; filterable). */
    public static function credit_margin_multiplier(): float {
        $value = 1.0;
        if (function_exists('apply_filters')) {
            $value = (float) apply_filters('ves_ai_credit_margin_multiplier', $value);
        }
        return $value > 0 ? $value : 1.0;
    }

    /**
     * Advisory credit estimate for a provider USD cost.
     * NOTE: telemetry only — does not charge. Rounded up to 2 decimals.
     */
    public static function estimate_credits_from_usd($usd): float {
        $usd = max(0.0, (float) $usd);
        if ($usd <= 0.0) { return 0.0; }
        $credits = ($usd / self::usd_per_credit()) * self::credit_margin_multiplier();
        return round(ceil($credits * 100) / 100, 2);
    }

    /**
     * Pre-call estimate for an operation.
     *
     * @param array $args {
     *   @type string     $model
     *   @type int        $input_tokens   Exact input tokens if known.
     *   @type string|array $input_text   Used to estimate input tokens if count absent.
     *   @type int        $max_output_tokens Expected upper bound on output tokens.
     * }
     * @return array{model:string,input_tokens:int,output_tokens:int,
     *   estimated_provider_cost:float,estimated_credits:float,pricing_source:string}
     */
    public static function estimate_operation(array $args): array {
        $model = (string) ($args['model'] ?? '');
        $input_tokens = isset($args['input_tokens']) ? max(0, (int) $args['input_tokens']) : 0;
        if ($input_tokens === 0 && isset($args['input_text'])) {
            $input_tokens = self::estimate_tokens_from_text($args['input_text']);
        }
        $output_tokens = isset($args['max_output_tokens']) ? max(0, (int) $args['max_output_tokens']) : 0;

        $pricing = self::model_pricing($model);
        $provider_cost = self::estimate_provider_cost($model, $input_tokens, $output_tokens);
        $credits = self::estimate_credits_from_usd($provider_cost);

        return [
            'model'                   => $model,
            'input_tokens'            => $input_tokens,
            'output_tokens'           => $output_tokens,
            'estimated_provider_cost' => $provider_cost,
            'estimated_credits'       => $credits,
            'pricing_source'          => $pricing['source'],
        ];
    }
}
