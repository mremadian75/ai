<?php
require_once __DIR__ . '/test-fidtf-v0335-live-gpt-error-and-analysis-hardening.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.36
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.36
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.36

// Live QA sample: TikTok Clockworks returns cleanItemCount=0 but valid raw rows.
$tiktok_live = [
    'id' => '7494819820823743752',
    'text' => 'Usa la regla de 3. En marketing, lo simple funciona.',
    'webVideoUrl' => 'https://www.tiktok.com/@conexion.irracional/video/7494819820823743752',
    'diggCount' => 161600,
    'shareCount' => 38300,
    'playCount' => 2200000,
    'commentCount' => 367,
    'collectCount' => 61859,
    'searchQuery' => 'marketing',
    'authorMeta' => ['name' => 'conexion.irracional', 'profileUrl' => 'https://www.tiktok.com/@conexion.irracional'],
    'videoMeta' => ['coverUrl' => 'https://example.com/cover.jpg'],
];
$tiktok_norm = FIDTF_Normalizer::normalize('tiktok', $tiktok_live);
ok(($tiktok_norm['metrics']['views'] ?? 0) === 2200000.0, 'TikTok Clockworks live row maps playCount to views');
ok(($tiktok_norm['metrics']['likes'] ?? 0) === 161600.0, 'TikTok Clockworks live row maps diggCount to likes');
ok(($tiktok_norm['provider_query'] ?? '') === 'marketing', 'TikTok Clockworks live row preserves searchQuery');

// Live QA sample: Instagram official direct hashtag URL returns raw rows with cleanItemCount=0.
$instagram_live = [
    'id' => '3905443992859566989',
    'type' => 'Image',
    'shortCode' => 'DYy7J6ck-ON',
    'caption' => 'Master the 7P’s of Marketing and build strategies that drive growth.',
    'url' => 'https://www.instagram.com/p/DYy7J6ck-ON/',
    'commentsCount' => 0,
    'likesCount' => 0,
    'displayUrl' => 'https://example.com/ig.jpg',
    'timestamp' => '2026-05-26T08:47:05.000Z',
    'ownerUsername' => 'dssegreenpark',
    'hashtags' => ['Marketing', 'DigitalMarketing', 'MarketingStrategy'],
];
$ig_norm = FIDTF_Normalizer::normalize('instagram', $instagram_live);
ok(($ig_norm['external_id'] ?? '') === '3905443992859566989', 'Instagram live post row keeps external id');
ok(($ig_norm['author'] ?? '') === 'dssegreenpark', 'Instagram live post row maps ownerUsername');
ok(($ig_norm['media']['thumbnail_url'] ?? '') === 'https://example.com/ig.jpg', 'Instagram live post row maps displayUrl thumbnail');

// Live QA sample: Reddit can return relevant-looking but off-topic community rows; keep source-specific limitations clean.
$reddit_live = [
    'id' => 't3_1tavijn',
    'url' => 'https://www.reddit.com/r/SaintMeghanMarkle/comments/1tavijn/example/',
    'username' => 'Feisty_Energy_107',
    'title' => 'Netflix strategy branded annoying',
    'communityName' => 'r/SaintMeghanMarkle',
    'body' => 'The brand narrative is being discussed as a calculated production.',
    'numberOfComments' => 209,
    'upVotes' => 372,
    'createdAt' => '2026-05-12T08:38:45.000Z',
    'thumbnailUrl' => 'self',
    'dataType' => 'post',
];
$reddit_norm = FIDTF_Normalizer::normalize('reddit', $reddit_live);
ok(($reddit_norm['metrics']['likes'] ?? 0) === 372.0, 'Reddit live row maps upVotes to likes');
ok(($reddit_norm['metrics']['comments'] ?? 0) === 209.0, 'Reddit live row maps numberOfComments to comments');
ok(!in_array('No transcript was available.', $reddit_norm['evidence_quality']['limitations'] ?? [], true), 'Reddit limitations do not include irrelevant transcript warning');

// Live QA sample: Google News raw rows often have cleanItemCount=0 but valid article rows.
$news_live = [
    'title' => 'La IA se consolida como el nuevo motor del marketing en DES 2026',
    'url' => 'https://news.google.com/read/example',
    'source' => 'Marketing Directo',
    'publishedAt' => '2026-05-26T07:22:42Z',
    'image' => 'https://news.google.com/api/attachments/example',
    'metadata' => ['keyword' => 'marketing', 'timeframe' => '1d'],
];
$news_norm = FIDTF_Normalizer::normalize('google_news', $news_live);
ok(($news_norm['author'] ?? '') === 'Marketing Directo', 'Google News live row maps source as author');
ok(($news_norm['provider_query'] ?? '') === 'marketing', 'Google News live row maps metadata.keyword as provider query');
ok(!in_array('No transcript was available.', $news_norm['evidence_quality']['limitations'] ?? [], true), 'Google News limitations do not include irrelevant transcript warning');

// Live QA sample: Google Trends keyword mode nested timeseries.
$trends_live = [
    'keyword' => 'marketing',
    'timeframe' => 'today 1-m',
    'geo' => 'ES',
    'language' => 'es-ES',
    'trends_url' => 'https://trends.google.com/trends/explore?date=today+1-m&geo=ES&q=marketing&hl=es-ES',
    'timeline_data' => [
        'marketing' => ['2026-05-05' => 100, '2026-05-26' => 64],
        'isPartial' => ['2026-05-26' => true],
    ],
];
$trends_norm = FIDTF_Normalizer::normalize('google_trends', $trends_live);
ok(($trends_norm['metrics']['trend_interest'] ?? 0) === 100.0, 'Google Trends live keyword row maps peak timeseries interest');
ok(!in_array('No transcript was available.', $trends_norm['evidence_quality']['limitations'] ?? [], true), 'Google Trends limitations do not include irrelevant transcript warning');
ok(strpos($normalizer_src, 'avoid polluting non-video source cards') !== false, 'normalizer contains source-specific limitation cleanup');

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Plan source collection for marketing signals in Spain.',
    'objective' => 'source planning only',
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => ['marketing'],
    'channels' => ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news'],
]);
$parsed_plan = [
    'schema_version' => 'fidtf.plan.v1',
    'brief_summary' => 'Structured output parsed payload.',
    'sources' => [
        'google_trends' => ['source_key' => 'google_trends', 'seed_keywords' => ['marketing'], 'geo' => 'ES', 'time_range' => 'last_30_days', 'max_items' => 2, 'sort_strategy' => 'keyword_interest'],
        'google_news' => ['source_key' => 'google_news', 'queries' => ['marketing Spain'], 'geo' => 'ES', 'language' => 'es', 'time_range' => 'last_24_hours', 'max_items' => 2, 'sort_strategy' => 'freshness'],
    ],
    'limitations' => ['Live provider confirmation required.'],
    'request_context' => ['market' => 'Spain'],
];
VES_AI_Planner_Bridge::$result = ['model' => 'gpt-structured-output', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'parsed' => $parsed_plan]]]]];
$parsed_response = FIDTF_AI_Bridge::plan($request, []);
ok(is_array($parsed_response) && ($parsed_response['model_label'] ?? '') === 'gpt-structured-output', 'AI bridge accepts OpenAI SDK parsed structured-output payload');
ok(!is_wp_error(FIDTF_AI_Planner::validate_plan($parsed_response, $request)), 'parsed structured-output payload validates as source plan');
ok(strpos($bridge_src, 'parsed_payload_from_response') !== false, 'AI bridge contains parsed payload extraction helper');

$sanitize = new ReflectionMethod('FIDTF_Report_Service', 'sanitize_external_report');
$sanitize->setAccessible(true);
$parsed_report = $sanitize->invoke(null, ['model' => 'gpt-report-parsed', 'parsed' => ['executive_summary' => 'Parsed report synthesis.', 'warnings' => ['safe warning'], 'unsafe' => 'drop']]);
ok(($parsed_report['executive_summary'] ?? '') === 'Parsed report synthesis.', 'report synthesis accepts parsed structured-output payload');
ok(!isset($parsed_report['unsafe']), 'parsed report still uses allowlist');
ok(strpos($report_src, 'external_parsed_payload') !== false, 'report service contains parsed payload extraction helper');

$secret_error = FIDTF_AI_Bridge::plan($request, []);
VES_AI_Planner_Bridge::$result = ['error' => ['message' => 'OPENAI_API_KEY=sk-proj-SECRETSECRETSECRETSECRET']];
$secret_error = FIDTF_AI_Bridge::plan($request, []);
ok(strpos((string) ($secret_error['raw_text'] ?? ''), 'sk-proj-SECRET') === false, 'OpenAI diagnostics redact env-style API keys');

fwrite(STDOUT, "FIDTF v0.3.36 full live actor and GPT QA hardening checks passed: {$checks} / {$checks}\n");
