<?php
require_once __DIR__ . '/test-fidtf-v0324-durable-diagnostics-and-counts.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$settings = file_get_contents($root . '/includes/class-fidtf-settings.php');
$provider = file_get_contents($root . '/includes/providers/class-fidtf-provider-apify.php');
$generic_adapter = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$source_job = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$run_service = file_get_contents($root . '/includes/class-fidtf-run-service.php');
$report_service = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$report_template = file_get_contents($root . '/templates/report-deep-trend-finder.php');
$shortcode_template = file_get_contents($root . '/templates/shortcode-deep-trend-finder.php');
$frontend = file_get_contents($root . '/assets/js/fidtf-frontend.js');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$admin = file_get_contents($root . '/includes/class-fidtf-admin.php');
$rest = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.25
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.25
ok(strpos($plugin, 'class-fidtf-generic-apify-live-adapter.php') !== false, 'generic Apify live adapter is loaded');

ok(strpos($settings, "'enable_multi_source_live_bridge' => true") !== false, 'multi-source live bridge setting defaults on');
ok(strpos($settings, 'source_live_ready') !== false, 'settings expose source_live_ready gate');
ok(strpos($settings, 'generic_apify_provider_ready') !== false, 'settings expose generic Apify provider readiness');
ok(strpos($settings, 'live_sources') !== false && strpos($settings, 'planned_only_sources') !== false, 'preflight returns live and planned-only source lists');

ok(strpos($generic_adapter, 'class FIDTF_Generic_Apify_Live_Adapter') !== false, 'generic Apify adapter class exists');
ok(strpos($generic_adapter, "case 'instagram_apify_official'") !== false || strpos($generic_adapter, "instagram_apify_official") !== false, 'generic adapter builds Instagram input');
ok(strpos($generic_adapter, "case 'reddit_trudax_lite'") !== false || strpos($generic_adapter, "reddit_trudax_lite") !== false, 'generic adapter builds Reddit input');
ok(strpos($generic_adapter, "case 'google_trends_data_xplorer'") !== false || strpos($generic_adapter, "google_trends_data_xplorer") !== false, 'generic adapter builds Google Trends input');
ok(strpos($generic_adapter, "case 'google_news_data_xplorer'") !== false || strpos($generic_adapter, "google_news_data_xplorer") !== false, 'generic adapter builds Google News input');
ok(strpos($provider, 'FIDTF_Generic_Apify_Live_Adapter') !== false, 'Apify provider dispatch uses generic adapter');
ok(strpos($source_job, 'FIDTF_Generic_Apify_Live_Adapter') !== false, 'source job refresh uses generic adapter for non-TikTok live sources');
ok(strpos($run_service, 'live_multi_source_collection') !== false, 'run intent supports multi-source live collection');

ok(strpos($report_service, 'Professional evidence synthesis') !== false, 'report status label upgraded to professional synthesis');
ok(strpos($report_service, 'content_angle_briefs') !== false, 'report generates content angle briefs');
ok(strpos($report_service, 'recommended_validation_plan') !== false, 'report generates validation plan');
ok(strpos($report_service, 'cannot claim market-wide representativeness') !== false, 'report has live-aware limitations instead of false no-live claim');
ok(strpos($report_template, 'Strategic readout') !== false, 'report template renders strategic readout');
ok(strpos($report_template, 'Content angle briefs') !== false, 'report template renders content angle briefs');
ok(strpos($report_template, 'Recommended validation plan') !== false, 'report template renders validation plan');

ok(strpos($shortcode_template, 'array_unique') !== false, 'shortcode deduplicates enabled source cards');
ok(strpos($shortcode_template, 'Live collection enabled') !== false, 'shortcode copy supports multi-source live collection');
ok(strpos($frontend, 'sourceLiveReady') !== false, 'frontend supports source-level live readiness');
ok(strpos($frontend, 'fidtf-evidence-card has-thumb') !== false, 'frontend renders evidence cards with thumbnails');
ok(strpos($frontend, 'metricChips') !== false, 'frontend renders metric chips on evidence cards');
ok(strpos($css, '.fidtf-report-v3') !== false, 'CSS includes v0.3.25 report styling');
ok(strpos($css, '.fidtf-evidence-card.has-thumb') !== false, 'CSS includes evidence thumbnail layout');
ok(strpos($css, '.fidtf-hero h2,\n.fidtf-header h2,\n.fidtf-report h3') === false, 'CSS no longer applies huge hero heading to every report h3');
ok(strpos($admin, 'enable_multi_source_live_bridge') !== false, 'admin exposes multi-source live bridge setting');
ok(strpos($rest, 'FIDTF_Settings::actor_for($source_key)') !== false, 'REST diagnostics exposes generic actor id for non-TikTok sources');

fwrite(STDOUT, "FIDTF v0.3.25 multi-source live and professional report checks passed: {$checks} / {$checks}\n");
