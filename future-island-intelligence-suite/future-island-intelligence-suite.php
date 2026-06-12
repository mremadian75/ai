<?php
/**
 * Plugin Name: Future Island Intelligence Suite
 * Description: Unified marketing intelligence platform. Includes the full Social Scraper Suite (VES Core) and the Deep Trend Finder module in a single WordPress plugin — no separate add-on required.
 * Version: 1.3.0
 * Requires PHP: 7.4
 * Author: Future Island / Vietnam Estudio
 * Text Domain: future-island-intelligence-suite
 */

if (!defined('ABSPATH')) {
    exit;
}

// -----------------------------------------------------------------------
// Suite-level constants
// -----------------------------------------------------------------------
define('FIS_VERSION',     '1.3.0');
// Product-level release label: Future Island v0.1 Release Candidate.
define('FIS_RC_LABEL',    'v0.1-rc6');
define('FIS_PLUGIN_FILE', __FILE__);
define('FIS_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('FIS_PLUGIN_URL',  plugin_dir_url(__FILE__));

// -----------------------------------------------------------------------
// VES Core constants (keep all existing VES code working unchanged)
// -----------------------------------------------------------------------
if (!defined('VES_PLUGIN_VERSION'))         { define('VES_PLUGIN_VERSION',         '1.3.0'); }
if (!defined('VES_PLUGIN_FILE'))            { define('VES_PLUGIN_FILE',            FIS_PLUGIN_FILE); }
if (!defined('VES_PLUGIN_DIR'))             { define('VES_PLUGIN_DIR',             FIS_PLUGIN_DIR); }
if (!defined('VES_PLUGIN_URL'))             { define('VES_PLUGIN_URL',             FIS_PLUGIN_URL); }
if (!defined('VES_PRODUCTION_MVP'))         { define('VES_PRODUCTION_MVP',         false); }
if (!defined('VES_ENABLE_DEEP_VIDEO_ANALYSIS')) { define('VES_ENABLE_DEEP_VIDEO_ANALYSIS', false); }

// -----------------------------------------------------------------------
// Deep Trend Finder module constants (module lives under modules/deep-trend-finder/)
// -----------------------------------------------------------------------
if (!defined('FIDTF_VERSION'))      { define('FIDTF_VERSION',     '0.3.55'); }
if (!defined('FIDTF_PLUGIN_FILE'))  { define('FIDTF_PLUGIN_FILE', FIS_PLUGIN_FILE); }
if (!defined('FIDTF_PLUGIN_DIR'))   { define('FIDTF_PLUGIN_DIR',  FIS_PLUGIN_DIR  . 'modules/deep-trend-finder/'); }
if (!defined('FIDTF_PLUGIN_URL'))   { define('FIDTF_PLUGIN_URL',  FIS_PLUGIN_URL  . 'modules/deep-trend-finder/'); }
if (!defined('FI_DTF_ENABLE_DEEP_VIDEO')) { define('FI_DTF_ENABLE_DEEP_VIDEO', false); }

// -----------------------------------------------------------------------
// VES Core bootstrap — exact same load order as the standalone Core plugin
// -----------------------------------------------------------------------
$fis_ves_files = [
    FIS_PLUGIN_DIR . 'includes/class-ves-config.php',
    // Phase 0/1 foundation — loaded right after config so any subsystem can use
    // structured logging, cost estimation, and AI usage tracking.
    FIS_PLUGIN_DIR . 'includes/class-ves-log.php',
    // Phase 9 production rails — security log first so every later subsystem can
    // record guardrail trips; guard/rails/ledgers before the services that use them.
    FIS_PLUGIN_DIR . 'includes/class-ves-security-event-log.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-workspace-guard.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-job-rails.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-review-decision-ledger.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-usage-settlement.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-rc-evidence-pack.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-external-egress-inventory.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-cost-estimator.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-ai-usage-tracker.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-health-check.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-intelligence-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-waterfall-sourcing.php',
    // Phase 4 — Deep Trend Finder V2 statistical engine (deterministic, no LLM).
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-observation-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-series-builder.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-scorer.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-record-store.php',
    // Phase 4B — trend backfill, rebuild, and golden-set calibration.
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-golden-set.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-backfill-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-rebuild-service.php',
    // Phase 5 — evidence-first insight engine (deterministic, no LLM).
    FIS_PLUGIN_DIR . 'includes/class-ves-insight-quality-scorer.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-insight-evidence-validator.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-insight-lifecycle-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-insight-builder.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-insight-brief-builder.php',
    // Phase 5B — staging validation + batch insight reassessment (read-only/dry-run-first).
    FIS_PLUGIN_DIR . 'includes/class-ves-threshold-audit-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-insight-reassessment-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-staging-validation-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-branding.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-deep-trend-addon-bridge.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-access-control.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-access-control-admin.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-provider-outcome.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-apify-client.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-source-adapter-registry.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-apify-actor-registry.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-query-planner.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-module-field-schemas.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-query-expander.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-source-planner.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-signal-normalizer.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-fusion-engine.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-label-guard.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-openai-client.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-ai-prompt-templates.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-projects.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-report-schema.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-confidence.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-evidence-quality.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-claim-readiness.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-intelligence-map.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-intelligence-map-admin.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-migrations.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-admin-console.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-evidence-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-memory-records.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-semantic-index.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-feedback-learning.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-knowledge-graph.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-knowledge-consolidator.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-assistant-threads.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-metric-snapshots.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-pattern-candidates.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-insight-records.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-opportunity-records.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brief-records.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-workflow-events.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-backfill-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-google-intel.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-assets.php',
    FIS_PLUGIN_DIR . 'includes/reports/class-ves-report-template-registry.php',
    FIS_PLUGIN_DIR . 'includes/reports/class-ves-report-schema-validator.php',
    FIS_PLUGIN_DIR . 'includes/reports/class-ves-report-data-builder.php',
    FIS_PLUGIN_DIR . 'includes/reports/class-ves-report-renderer.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-market-signal-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-market-signal-commercial.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-market-signal-interface.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-admin.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-source-intake.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-billing.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-stripe-billing.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-promo-social-credits.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-lead-gate.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-billing-admin.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-queue.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-run-lock-manager.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-run-log-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-usage-reconciliation-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-action-scheduler-jobs.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-run-execution-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-memory-cleanup-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-run-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-insight-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-query-planner.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-source-evaluator.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-scoring.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-run-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-query-planner.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-source-evaluator.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-scoring.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-audit-execution-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-creative-intelligence.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-temp-memory.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-workspace-profile.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-project-context.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-ai-context-builder.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-workspace-knowledge.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-monitor-store.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-monitor-runner.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-auth.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-app-router.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-shortcode.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-ajax-controller.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-legacy-trend-report-normalizer.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-trend-backfill-runner.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-cli-trend-backfill.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-cli-schema.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-cli-trends.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-brand-context-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-generation-context-resolver.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-generation-prompt-package-builder.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-review-state.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-evidence-binder.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-operator-queue-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-signal-room.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-workbench.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-cli-brand-context.php',
    // v0.1 RC — whole-product readiness gate + read-only Release Candidate page.
    FIS_PLUGIN_DIR . 'includes/class-ves-rc-readiness-service.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-cli-rc-readiness.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-release-candidate-page.php',
    FIS_PLUGIN_DIR . 'includes/class-ves-plugin.php',
    FIS_PLUGIN_DIR . 'includes/legacy-config.php',
    FIS_PLUGIN_DIR . 'includes/helpers.php',
    FIS_PLUGIN_DIR . 'includes/platform-input.php',
    FIS_PLUGIN_DIR . 'includes/analysis.php',
    FIS_PLUGIN_DIR . 'includes/trend-finder.php',
];

foreach ($fis_ves_files as $fis_file) {
    if (file_exists($fis_file)) {
        require_once $fis_file;
    }
}

// -----------------------------------------------------------------------
// Deep Trend Finder module bootstrap
// Loaded after VES Core so FIDTF classes can reference VES_* classes safely.
// -----------------------------------------------------------------------
$fis_fidtf_files = [
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-db.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-settings.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-dependency.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-module-info.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-credit-service.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-ai-bridge.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-ai-planner.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-normalizer.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-relevance-filter.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-memory-bridge.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-apify.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-ai.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-core-apify-client-adapter.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-tiktok-live-adapter.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-generic-apify-live-adapter.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-tiktok.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-instagram.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-reddit.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-google-trends.php',
    FIDTF_PLUGIN_DIR . 'includes/providers/class-fidtf-provider-google-news.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-source-dispatcher.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-source-job-service.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-report-service.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-run-service.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-rest-controller.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-admin.php',
    FIDTF_PLUGIN_DIR . 'includes/class-fidtf-plugin.php',
];

foreach ($fis_fidtf_files as $fis_file) {
    if (file_exists($fis_file)) {
        require_once $fis_file;
    }
}

// -----------------------------------------------------------------------
// Text domain registration
// All three legacy domains are forwarded to the languages/ directory so
// existing translation files still work without modification.
// -----------------------------------------------------------------------
add_action('init', function () {
    load_plugin_textdomain('future-island-intelligence-suite', false, dirname(plugin_basename(FIS_PLUGIN_FILE)) . '/languages');
    load_plugin_textdomain('ves',                              false, dirname(plugin_basename(FIS_PLUGIN_FILE)) . '/languages');
    load_plugin_textdomain('future-island-deep-trend-finder-addon', false, dirname(plugin_basename(FIS_PLUGIN_FILE)) . '/languages');
}, 1);

// -----------------------------------------------------------------------
// Boot both subsystems
// -----------------------------------------------------------------------
VES_Plugin::boot();
FIDTF_Plugin::boot();

// -----------------------------------------------------------------------
// Activation / deactivation
// -----------------------------------------------------------------------
register_activation_hook(FIS_PLUGIN_FILE, 'fis_activate');
register_deactivation_hook(FIS_PLUGIN_FILE, 'fis_deactivate');

function fis_activate(): void {
    VES_Plugin::activate();
    FIDTF_Plugin::activate();
}

function fis_deactivate(): void {
    VES_Plugin::deactivate();
    FIDTF_Plugin::deactivate();
}
