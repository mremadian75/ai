<?php
require_once __DIR__ . '/test-fidtf-v0320-apidojo-output-repair-refresh.php';

class VES_Apify_Client {
    public static $requested_urls = [];
    public static function request($method, $url, $body = null, $attempt = 1) {
        self::$requested_urls[] = $method . ' ' . $url;
        if (strpos($url, '/datasets/ds_live_110/items') !== false) {
            return ['code' => 200, 'body' => [[
                'id' => '7639618414637485326',
                'title' => 'birthday matcha latte supremacy #beverages #baristalife',
                'views' => 22955,
                'likes' => 1569,
                'comments' => 8,
                'shares' => 22,
                'bookmarks' => 90,
                'uploadedAtFormatted' => '2026-05-24T14:35:45.000Z',
                'postPage' => 'https://www.tiktok.com/@demylora/video/7639618414637485326',
                'channel' => ['username' => 'demylora', 'name' => 'Demylora'],
            ]], 'raw' => '[]'];
        }
        return ['code' => 201, 'body' => ['data' => ['id' => 'enrichment-run', 'status' => 'READY']], 'raw' => '{}'];
    }
    public static function fetch_run($run_id, $wait_for_finish = 20) {
        return ['code' => 200, 'body' => ['data' => [
            'id' => $run_id,
            'status' => 'SUCCEEDED',
            'defaultDatasetId' => 'ds_live_110',
        ]], 'raw' => '{}'];
    }
    public static function fetch_items($run_id, $limit = 150) {
        // Simulate the production failure path: actor-runs/{runId}/dataset/items
        // is empty/stale even though the run metadata points to a populated dataset.
        return [];
    }
}

$adapter = new FIDTF_Core_Apify_Client_Adapter();
$result = $adapter->refresh([
    'provider_run_id' => 'DU5Ktv41hz9GUSZuP',
    'actor_input' => ['maxItems' => 300],
    'discovery_actor_id' => 'apidojo/tiktok-scraper',
], 300);

ok(is_array($result), 'core adapter refresh returns array');
ok(($result['status'] ?? '') === 'completed', 'dataset-id fallback converts succeeded zero-run endpoint into completed evidence');
ok((int) ($result['provider_dataset_rows'] ?? 0) === 1, 'dataset-id fallback records provider dataset rows');
ok((int) ($result['flattened_raw_items'] ?? 0) === 1, 'dataset-id fallback records flattened raw items');
ok(count((array) ($result['items'] ?? [])) === 1, 'dataset-id fallback returns usable TikTok item');
ok(strpos(implode("\n", VES_Apify_Client::$requested_urls), '/datasets/ds_live_110/items') !== false, 'core adapter requested canonical dataset endpoint fallback');

$source_code = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-source-job-service.php');
ok(strpos($source_code, 'intentionally no-op') !== false, 'zero-count repair marker no longer suppresses repeat safe repair');

$plugin = file_get_contents(dirname(__DIR__) . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version is numeric v0.3.21 or newer patch

fwrite(STDOUT, "FIDTF v0.3.21 dataset-id fallback and repeat repair checks passed: {$checks} / {$checks}\n");
