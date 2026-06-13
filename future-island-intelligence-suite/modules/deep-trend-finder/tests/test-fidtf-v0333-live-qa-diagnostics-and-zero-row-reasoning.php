<?php
require_once __DIR__ . '/test-fidtf-v0332-live-clean-fetch-and-instagram-confirmation.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$generic = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.34
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.34
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.34

ok(strpos($generic, "'flattened_empty'") !== false, 'generic adapter distinguishes provider rows that could not be flattened');
ok(strpos($generic, "'normalized_empty'") !== false, 'generic adapter distinguishes flattened rows that later produced no usable evidence');
ok(substr_count($generic, "'discovery_dataset_id'") >= 2, 'generic adapter persists discovery dataset id in running and completed responses');
ok(strpos($generic, "provider_dataset_id") !== false, 'generic adapter carries provider dataset id in context');
ok(strpos($generic, "interest_over_time") !== false, 'generic adapter recognizes Google Trends interest_over_time live shape');
ok(strpos($generic, "related_queries") !== false, 'generic adapter recognizes Google Trends related_queries live shape');
ok(strpos($normalizer_src, "interest_over_time") !== false, 'normalizer recognizes Google Trends interest_over_time live shape');

$adapter = new FIDTF_Generic_Apify_Live_Adapter('google_trends');
$ref = new ReflectionClass($adapter);
$completed = $ref->getMethod('completed_result'); $completed->setAccessible(true);
$running = $ref->getMethod('running_result'); $running->setAccessible(true);
$expand = $ref->getMethod('expand_source_item'); $expand->setAccessible(true);

$rows_prop = $ref->getProperty('last_provider_dataset_rows'); $rows_prop->setAccessible(true);
$total_prop = $ref->getProperty('last_provider_dataset_total_rows'); $total_prop->setAccessible(true);
$flat_prop = $ref->getProperty('last_flattened_raw_items'); $flat_prop->setAccessible(true);
$rows_prop->setValue($adapter, 3);
$total_prop->setValue($adapter, 3);
$flat_prop->setValue($adapter, 0);
$completed_zero = $completed->invoke($adapter, 'run123', [], ['actor_id' => 'data_xplorer/google-trends-fast-scraper', 'actor_input_profile' => 'google_trends_data_xplorer', 'provider_dataset_id' => 'dataset123'], ['keyword' => 'marketing']);
ok(($completed_zero['outcome_reason'] ?? '') === 'flattened_empty', 'completed generic result reports flattened_empty when provider rows exist but adapter produced no items');
ok(($completed_zero['discovery_dataset_id'] ?? '') === 'dataset123', 'completed generic result exposes discovery dataset id');
ok(($completed_zero['provider_dataset_total_rows'] ?? 0) === 3, 'completed generic result preserves provider total rows');

$running_result = $running->invoke($adapter, 'run456', ['actor_id' => 'data_xplorer/google-trends-fast-scraper', 'actor_input_profile' => 'google_trends_data_xplorer', 'provider_dataset_id' => 'dataset456'], ['keyword' => 'marketing'], 'running', 'run_running');
ok(($running_result['discovery_dataset_id'] ?? '') === 'dataset456', 'running generic result exposes discovery dataset id');
ok(array_key_exists('provider_dataset_total_rows', $running_result), 'running generic result exposes provider total row counter');
ok(($running_result['status'] ?? '') === 'running', 'running generic result keeps running status');

$expanded = $expand->invoke($adapter, [
  'keyword' => 'marketing',
  'interest_over_time' => [['date' => '2026-05-01', 'value' => 25], ['date' => '2026-05-02', 'value' => 50]],
  'related_queries' => [['query' => 'marketing automation', 'value' => 10]],
]);
ok(count($expanded) === 1, 'Google Trends keyword analysis row with interest_over_time expands as a single evidence row');
ok(($expanded[0]['provider_container_type'] ?? '') === 'google_trends_keyword_analysis', 'Google Trends keyword analysis row is tagged with provider container type');

$norm = FIDTF_Normalizer::normalize('google_trends', [
  'keyword' => 'marketing',
  'trends_url' => 'https://trends.google.com/trends/explore?q=marketing',
  'interest_over_time' => [['date' => '2026-05-01', 'value' => 25], ['date' => '2026-05-02', 'value' => 50]],
]);
ok(!empty($norm['trend_timeseries']), 'Normalizer keeps Google Trends interest_over_time as trend_timeseries');
ok(($norm['metrics']['trend_interest'] ?? null) == 50, 'Normalizer derives max trend_interest from interest_over_time');
ok(($norm['media']['type'] ?? '') === 'trend', 'Google Trends normalized media type remains trend');

fwrite(STDOUT, "FIDTF v0.3.34 live QA diagnostics and zero-row reasoning checks passed: {$checks} / {$checks}\n");
