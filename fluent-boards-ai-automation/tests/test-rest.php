<?php
/**
 * REST suggestions task_id filter + slim projection (used by the in-board AI panel).
 * Run via tests/run.php.
 */

require_once dirname(__DIR__) . '/includes/class-fbaia-suggestion-store.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-internal-actions.php';
require_once dirname(__DIR__) . '/includes/class-fbaia-rest-controller.php';

echo "REST suggestions task_id filter\n";

fbaia_reset_options();

// Seed three suggestions across two tasks.
FBAIA_Suggestion_Store::record('external_event', ['task_id' => 10], [
    'summary' => 'Task 10 first', 'confidence' => 0.6,
    'decision_quality' => ['risk_score' => 0.2],
]);
FBAIA_Suggestion_Store::record('external_event', ['task_id' => 20], [
    'summary' => 'Task 20', 'confidence' => 0.9, 'recommended_priority' => 'high',
    'decision_quality' => ['risk_score' => 0.8],
]);
FBAIA_Suggestion_Store::record('external_event', ['task_id' => 10], [
    'summary' => 'Task 10 newest', 'confidence' => 0.7,
    'decision_quality' => ['risk_score' => 0.3],
]);

$controller = new FBAIA_REST_Controller();

// Filter to task 10 -> only its two suggestions, newest first.
$res = $controller->suggestions(new WP_REST_Request(['task_id' => 10]));
$items = $res['suggestions'];
fbaia_assert('filter returns only matching task', count($items) === 2);
fbaia_assert('all returned items belong to task 10', $items[0]['task_id'] === 10 && $items[1]['task_id'] === 10);
fbaia_assert('newest suggestion is first', $items[0]['summary'] === 'Task 10 newest');

// Slim projection: display fields present, raw payload absent.
fbaia_assert('slim item exposes summary', isset($items[0]['summary']));
fbaia_assert('slim item exposes confidence', array_key_exists('confidence', $items[0]));
fbaia_assert('slim item does NOT leak ai_payload', !array_key_exists('ai_payload', $items[0]));
fbaia_assert('slim item does NOT leak raw payload', !array_key_exists('payload', $items[0]));

// task 20 isolation.
$res20 = $controller->suggestions(new WP_REST_Request(['task_id' => 20]));
fbaia_assert('task 20 returns its single suggestion', count($res20['suggestions']) === 1);
fbaia_assert('task 20 priority projected', $res20['suggestions'][0]['recommended_priority'] === 'high');

// No filter -> full (non-slim) list still returned for the admin tables.
$resAll = $controller->suggestions(new WP_REST_Request([]));
fbaia_assert('no task_id -> returns all suggestions', count($resAll['suggestions']) === 3);
fbaia_assert('unfiltered items keep full shape (ai_payload present)', array_key_exists('ai_payload', $resAll['suggestions'][0]));
