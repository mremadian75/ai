<?php
/**
 * VES_Provider_Outcome — Standardized classification of provider run outcomes.
 *
 * v0.9.26.0 — Phase 7. Previously, modules sometimes labeled a successful-but-
 * empty actor run as "access failed", which misled users. This class is the
 * single source of truth for translating raw run state into the canonical
 * outcome model, and for producing user-safe messages.
 *
 * Outcome states:
 *   - success_with_results       provider succeeded, ≥1 usable row
 *   - success_empty              provider succeeded, 0 rows returned
 *   - success_all_filtered       provider returned rows but all dropped by local filters
 *   - partial_success            multi-source: some sources usable, others not
 *   - provider_failed            run failed with no usable signal
 *   - invalid_input              local payload contract failed before dispatch
 *   - access_failed              401/403/rental/auth issue
 *   - rate_limited               429/quota exceeded
 *   - timeout                    provider timed out
 *   - unsupported_mode           mode not supported by configured actor
 *   - preflight_blocked          blocked by plan/feature/access gate
 */

if (!defined('ABSPATH')) { exit; }

final class VES_Provider_Outcome {

    const SUCCESS_WITH_RESULTS  = 'success_with_results';
    const SUCCESS_EMPTY         = 'success_empty';
    const SUCCESS_ALL_FILTERED  = 'success_all_filtered';
    const PARTIAL_SUCCESS       = 'partial_success';
    const PROVIDER_FAILED       = 'provider_failed';
    const INVALID_INPUT         = 'invalid_input';
    const ACCESS_FAILED         = 'access_failed';
    const RATE_LIMITED          = 'rate_limited';
    const TIMEOUT               = 'timeout';
    const UNSUPPORTED_MODE      = 'unsupported_mode';
    const PREFLIGHT_BLOCKED     = 'preflight_blocked';

    /**
     * Classify a run from its raw signals.
     *
     * $signals = [
     *   'http_status'       => int|null,
     *   'actor_status'      => string,  // SUCCEEDED, FAILED, TIMED-OUT, ABORTED, etc.
     *   'raw_count'         => int,     // rows the provider returned
     *   'usable_count'      => int,     // rows after local filters
     *   'error_code'        => string,  // ves error code if any
     *   'multi_source'      => bool,    // trend finder fan-out
     *   'sources_succeeded' => int,
     *   'sources_failed'    => int,
     * ]
     */
    public static function classify(array $signals) {
        $http        = (int) ($signals['http_status'] ?? 0);
        $actor       = strtoupper((string) ($signals['actor_status'] ?? ''));
        $raw         = (int) ($signals['raw_count'] ?? 0);
        $usable      = (int) ($signals['usable_count'] ?? 0);
        $error_code  = (string) ($signals['error_code'] ?? '');
        $multi       = !empty($signals['multi_source']);
        $sources_ok  = (int) ($signals['sources_succeeded'] ?? 0);
        $sources_bad = (int) ($signals['sources_failed'] ?? 0);

        // Preflight / access-control blocks come pre-stamped.
        if ($error_code === 'feature_locked' || $error_code === 'preflight_blocked') {
            return self::PREFLIGHT_BLOCKED;
        }
        if ($error_code === 'invalid_input' || $error_code === 'linkedin_missing_input'
            || $error_code === 'linkedin_unsupported_objective' || $error_code === 'skipped_invalid_input') {
            return self::INVALID_INPUT;
        }
        if ($error_code === 'unsupported_mode' || $error_code === 'unsupported') {
            return self::UNSUPPORTED_MODE;
        }
        if ($http === 401 || $http === 403 || strpos($error_code, 'access') !== false || strpos($error_code, 'rental') !== false) {
            return self::ACCESS_FAILED;
        }
        if ($http === 429 || strpos($error_code, 'rate') !== false) {
            return self::RATE_LIMITED;
        }
        if ($actor === 'TIMED-OUT' || strpos($error_code, 'timeout') !== false) {
            return self::TIMEOUT;
        }

        // Multi-source first: distinct partial success state.
        if ($multi && $sources_ok > 0 && $sources_bad > 0 && $usable > 0) {
            return self::PARTIAL_SUCCESS;
        }
        if ($multi && $sources_ok > 0 && $usable === 0) {
            return self::SUCCESS_ALL_FILTERED;
        }
        if ($multi && $sources_ok === 0 && $sources_bad > 0) {
            return self::PROVIDER_FAILED;
        }

        // Single-source classification.
        if ($actor === 'SUCCEEDED' || $http === 200) {
            if ($usable > 0) { return self::SUCCESS_WITH_RESULTS; }
            if ($raw > 0)    { return self::SUCCESS_ALL_FILTERED; }
            return self::SUCCESS_EMPTY;
        }
        if ($actor === 'FAILED' || ($http >= 500 && $http < 600)) {
            return self::PROVIDER_FAILED;
        }
        if ($actor === 'ABORTED') {
            return self::PROVIDER_FAILED;
        }
        // Default: if we have usable rows, success regardless of vague status.
        if ($usable > 0) { return self::SUCCESS_WITH_RESULTS; }
        if ($raw > 0)    { return self::SUCCESS_ALL_FILTERED; }
        return self::PROVIDER_FAILED;
    }

    /**
     * Generate a user-safe message for a given outcome + module/platform.
     * Strictly non-technical. Admin diagnostics belong elsewhere.
     */
    public static function user_message($outcome, $context = []) {
        $platform  = strtolower((string) ($context['platform'] ?? ''));
        $module    = strtolower((string) ($context['module']   ?? ''));
        $raw       = (int) ($context['raw_count'] ?? 0);
        $is_x      = ($platform === 'twitter' || $platform === 'x');
        $is_sem    = ($platform === 'semrush' || $module === 'seo');
        $is_li     = ($platform === 'linkedin' || $module === 'linkedin');

        switch ($outcome) {
            case self::SUCCESS_WITH_RESULTS:
                return 'Resultados disponibles.';
            case self::SUCCESS_EMPTY:
                if ($is_sem) {
                    return 'La fuente SEO no devolvió filas para esta semilla y mercado. Prueba una semilla más amplia, otro país/idioma, o usa Search como fallback.';
                }
                return 'La fuente no devolvió resultados para esta consulta. Prueba con términos más amplios, otro idioma o un rango de fechas más extenso.';
            case self::SUCCESS_ALL_FILTERED:
                if ($is_x) {
                    return 'X devolvió ' . $raw . ' resultado(s), pero ninguno coincidió con los filtros de idioma/relevancia/engagement. Prueba con variantes en español e inglés, hashtags concretos, o reduce el mínimo de engagement.';
                }
                return 'La fuente devolvió ' . $raw . ' resultado(s), pero los filtros locales los descartaron. Reduce el mínimo de likes/views, cambia el orden, o amplía la consulta.';
            case self::PARTIAL_SUCCESS:
                return 'Algunas fuentes proporcionaron señal y otras no. El reporte se completa con confianza media.';
            case self::INVALID_INPUT:
                if ($is_li) {
                    return 'LinkedIn necesita un tema, URL de empresa/perfil, o URL de post antes de ejecutarse.';
                }
                return 'Faltan datos obligatorios para ejecutar este modo. Completa los campos requeridos antes de continuar.';
            case self::ACCESS_FAILED:
                return 'La fuente requiere acceso/autorización adicional. Contacta con el administrador.';
            case self::RATE_LIMITED:
                return 'Se alcanzó el límite de peticiones a la fuente. Espera unos minutos antes de reintentar.';
            case self::TIMEOUT:
                return 'La fuente tardó demasiado en responder. Reintenta o reduce el alcance de la consulta.';
            case self::UNSUPPORTED_MODE:
                return 'Este modo no está soportado por la fuente configurada actualmente.';
            case self::PREFLIGHT_BLOCKED:
                return 'Función bloqueada por tu plan actual.';
            case self::PROVIDER_FAILED:
            default:
                return 'No se pudo completar la consulta a la fuente. No se han cargado créditos.';
        }
    }

    /**
     * Whether a given outcome should result in zero credits charged.
     */
    public static function should_refund($outcome) {
        return in_array($outcome, [
            self::SUCCESS_EMPTY,         // optionally refund — admin policy
            self::INVALID_INPUT,
            self::ACCESS_FAILED,
            self::PREFLIGHT_BLOCKED,
            self::UNSUPPORTED_MODE,
            self::PROVIDER_FAILED,
            self::TIMEOUT,
            self::RATE_LIMITED,
        ], true);
    }
}
