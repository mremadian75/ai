<?php
/**
 * v0.9.33 — Real browser revalidation sprint regression test.
 *
 * Locks the defects that real-browser (Chromium/Playwright) rendering of the UI
 * snippets surfaced after the v0.9.32 patch:
 *   1. `.fi-status-chip` had no context-independent base style, so the Provider
 *      Ingestions Ledger / Operator QA admin screens (rendered as
 *      `class="wrap fi-provider-admin"` / `class="wrap fi-beta-qa ..."`, i.e. with
 *      NO `.ves-wrap` ancestor) showed statuses like "partial" as unstyled text.
 *   2. `.fi-timeline-filters` had no spacing rule, so Command Room run-timeline
 *      filter chips rendered touching ("all eventswarnings...").
 *   3. Evidence-snippet fidelity drift: the lineage snippet used a table class that
 *      the live JS renderer never emits, and the run-timeline / command-room
 *      snippets omitted the real `ves-wrap fi-command-room` wrapper, so rendering
 *      them under-represented the shipped UI.
 */
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);

$ui      = file_get_contents($root . '/assets/css/fiis-ui-system.css');
$imap_js = file_get_contents($root . '/assets/js/fiis-intelligence-map.js');

$snip = function (string $rel) use ($root): string {
    $path = $root . '/ui-snippets/' . $rel;
    return is_file($path) ? (string) file_get_contents($path) : '';
};

// 1. Context-independent base chip style (additive; scoped rules stay authoritative).
fi_spine_ok(strpos($ui, "\n.fi-status-chip {") !== false, 'base .fi-status-chip style exists un-scoped (works on admin .wrap screens)');
fi_spine_ok(strpos($ui, 'border-radius: 999px;') !== false, 'base chip renders as a pill, not plain text');
fi_spine_ok(strpos($ui, '.fi-ledger-status-partial { color: #9a6700; }') !== false, 'ledger status colours degrade gracefully outside .ves-wrap');

// 2. Timeline filters get real spacing.
fi_spine_ok(strpos($ui, "\n.fi-timeline-filters {") !== false, '.fi-timeline-filters has its own rule');
$filters_block = strstr($ui, '.fi-timeline-filters {');
fi_spine_ok($filters_block !== false && strpos($filters_block, 'gap: 8px;') !== false && strpos($filters_block, 'display: flex;') !== false, 'timeline filter chips are spaced (flex + gap)');

// 3a. Lineage snippet matches the class the live JS renderer actually emits.
fi_spine_ok(strpos($imap_js, "class: 'fiis-imap-table'") !== false, 'live Intelligence Map JS renders <table class="fiis-imap-table">');
$lineage = $snip('real-browser-ui-bugfix/intelligence-map-lineage-list.html');
fi_spine_ok(strpos($lineage, 'class="fiis-imap-table"') !== false, 'lineage evidence snippet uses the real fiis-imap-table class');
fi_spine_ok(strpos($lineage, 'class="fiis-imap-lineage"') === false, 'lineage snippet no longer uses a table class the renderer never emits');

// 3b. Command Room snippets carry the real render wrapper.
fi_spine_ok(strpos($snip('run-timeline.html'), 'ves-wrap fi-command-room') !== false, 'run-timeline snippet wrapped in the real ves-wrap fi-command-room context');
fi_spine_ok(strpos($snip('command-room.html'), 'ves-wrap fi-command-room') !== false, 'command-room snippet carries the real ves-wrap co-class');
fi_spine_ok(strpos($snip('provider-ingestion-ledger.html'), 'class="wrap fi-provider-admin') !== false, 'provider ledger snippet matches the real wrap fi-provider-admin root');

// 4. The new CSS introduced no sensitive markers.
foreach (['secret', 'signature', 'Bearer ', 'OPENAI_API_KEY', 'apify_api_', 'token='] as $sensitive) {
    $new_block = strstr($ui, '/* v0.9.33');
    fi_spine_ok($new_block === false || strpos($new_block, $sensitive) === false, 'v0.9.33 CSS block has no sensitive marker: ' . $sensitive);
}

fi_spine_finish('v0.9.33 real browser revalidation checks');
