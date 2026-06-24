<?php
/**
 * AI output validation, comment-loop prevention, and graceful dependency-missing behavior.
 * Run via tests/run.php.
 */

echo "AI validation, loop prevention & dependencies\n";

$client = new FBAIA_OpenAI_Client(FBAIA_Helpers::defaults());
$ref = new ReflectionClass($client);

$parse = $ref->getMethod('parse_model_json');
$parse->setAccessible(true);
$fallback = $ref->getMethod('fallback_json');
$fallback->setAccessible(true);
$sanitize = $ref->getMethod('sanitize_ai_result');
$sanitize->setAccessible(true);

// parse_model_json: plain JSON, fenced JSON, embedded JSON, and unparseable text.
$plain = $parse->invoke($client, '{"summary":"hello","confidence":0.5}');
fbaia_assert('parse plain JSON', is_array($plain) && ($plain['summary'] ?? '') === 'hello');

$fenced = $parse->invoke($client, "Here you go:\n```json\n{\"summary\":\"fenced\"}\n```\nThanks");
fbaia_assert('parse fenced JSON', is_array($fenced) && ($fenced['summary'] ?? '') === 'fenced');

$embedded = $parse->invoke($client, 'noise before {"summary":"embedded"} noise after');
fbaia_assert('parse embedded JSON', is_array($embedded) && ($embedded['summary'] ?? '') === 'embedded');

$garbage = $parse->invoke($client, 'this is not json at all');
fbaia_assert('unparseable text returns null', $garbage === null);

// fallback_json: returns a complete, safe structure when AI returns junk.
$fb = $fallback->invoke($client, 'totally invalid');
fbaia_assert('fallback marks automation unsafe', $fb['automation_safe'] === false);
fbaia_assert('fallback has risk flag', !empty($fb['risk_flags']));
fbaia_assert('fallback has F task quality', ($fb['task_quality']['grade'] ?? '') === 'F');

// sanitize_ai_result: untrusted model output is clamped and type-checked.
$dirty = [
    'summary' => 'ok',
    'recommended_priority' => 'banana', // invalid -> null
    'confidence' => 5,                  // clamp to 1
    'impact' => 'nonsense',             // -> medium
    'automation_safe' => 'false',       // string false -> bool false
    'skip_comment' => 'yes',            // -> true
    'next_actions' => ['do x', 'do x', ''], // dedupe + drop empty
    'decision_quality' => ['risk_score' => 2], // clamp to 1
];
$clean = $sanitize->invoke($client, $dirty);
fbaia_assert('invalid priority coerced to null', $clean['recommended_priority'] === null);
fbaia_assert('confidence clamped to 1', $clean['confidence'] === 1.0 || $clean['confidence'] === 1);
fbaia_assert('invalid impact -> medium', $clean['impact'] === 'medium');
fbaia_assert('string "false" -> bool false (automation_safe)', $clean['automation_safe'] === false);
fbaia_assert('string "yes" -> bool true (skip_comment)', $clean['skip_comment'] === true);
fbaia_assert('next_actions deduped + emptied removed', $clean['next_actions'] === ['do x']);
fbaia_assert('risk_score clamped to 1', (float) $clean['decision_quality']['risk_score'] === 1.0);

// to_bool: the v0.9.2 fix — string falsey values must not be treated as true.
fbaia_assert('to_bool("false") === false', FBAIA_Helpers::to_bool('false') === false);
fbaia_assert('to_bool("no") === false', FBAIA_Helpers::to_bool('no') === false);
fbaia_assert('to_bool("0") === false', FBAIA_Helpers::to_bool('0') === false);
fbaia_assert('to_bool("yes") === true', FBAIA_Helpers::to_bool('yes') === true);
fbaia_assert('to_bool(true) === true', FBAIA_Helpers::to_bool(true) === true);

// Comment-loop prevention: a payload carrying a generated marker must be recognised.
$with_html_marker = ['comment' => ['description' => FBAIA_Helpers::GENERATED_MARKER . ' AI text']];
fbaia_assert('detects HTML generated marker', FBAIA_Helpers::payload_contains_generated_marker($with_html_marker) === true);

$with_text_marker = ['comment' => ['description' => 'AI text ' . FBAIA_FluentBoards_Adapter::GENERATED_TEXT_MARKER]];
fbaia_assert('detects text generated marker', FBAIA_Helpers::payload_contains_generated_marker($with_text_marker) === true);

$human = ['comment' => ['description' => 'A normal human comment about the task.']];
fbaia_assert('human comment not flagged as generated', FBAIA_Helpers::payload_contains_generated_marker($human) === false);

// Dependency missing: with no Fluent Boards classes loaded, the adapter degrades gracefully.
$adapter = new FBAIA_FluentBoards_Adapter();
fbaia_assert('adapter reports unavailable without Fluent Boards', $adapter->is_available() === false);
fbaia_assert('get_task returns null without Fluent Boards', $adapter->get_task(123) === null);
fbaia_assert('update_task_priority returns WP_Error without Fluent Boards', is_wp_error($adapter->update_task_priority(1, 'high')));
fbaia_assert('create_subtask returns WP_Error without Fluent Boards', is_wp_error($adapter->create_subtask(1, 'Sub')));
