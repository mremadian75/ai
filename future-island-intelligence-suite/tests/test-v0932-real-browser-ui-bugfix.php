<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/css/ves-frontend.css');
$ui = file_get_contents($root . '/assets/css/fiis-ui-system.css');
$prod = file_get_contents($root . '/assets/js/fiis-signal-productization.js');
$imap_js = file_get_contents($root . '/assets/js/fiis-intelligence-map.js');
$imap_css = file_get_contents($root . '/assets/css/fiis-intelligence-map.css');
$imap_php = file_get_contents($root . '/includes/class-ves-intelligence-map.php');
$provider_admin = file_get_contents($root . '/includes/class-ves-provider-admin-page.php');
$access_admin = file_get_contents($root . '/includes/class-ves-access-control-admin.php');

fi_spine_ok(strpos($css, 'repeat(auto-fit, minmax(280px, 1fr))') !== false, 'social result cards use sane 280px minmax grid');
fi_spine_ok(strpos($css, '.ves-wrap .ves-title2') !== false && strpos($css, 'word-break: normal !important') !== false, 'social card prose explicitly avoids mid-word breaking');
fi_spine_ok(strpos($css, '.ves-wrap .ves-results .ves-item a[href]') !== false && strpos($css, 'word-break: break-word !important') !== false, 'URLs still wrap safely without forcing prose break-all');
fi_spine_ok(strpos($css, 'repeat(auto-fit, minmax(160px, 1fr))') !== false, 'metric cards use responsive min-width grid');
fi_spine_ok(strpos($css, '.ves-wrap .ves-kpi small') !== false && strpos($css, 'text-transform: none !important') !== false, 'metric labels are readable and not squeezed uppercase fragments');

fi_spine_ok(strpos($ui, '.ves-wrap .fiis-route-track') !== false && strpos($ui, 'minmax(160px, 1fr)') !== false, 'Object Flow route uses readable grid sizing');
fi_spine_ok(strpos($ui, '.ves-wrap .fiis-route-state') !== false && strpos($ui, 'white-space: nowrap !important') !== false, 'Object Flow status chips avoid mid-word breaks');
fi_spine_ok(strpos($ui, '.fiis-rec-cta.is-disabled::after') !== false, 'disabled recommendation CTAs expose inline reason text');
fi_spine_ok(strpos($ui, 'padding-bottom: 96px') !== false, 'long admin/product screens have bottom spacing for sticky/admin bars');

foreach (["gateTitle: 'Revisión de evidencia'", "draft: 'Borrador'", "high: 'alta'", "medium: 'media'", "low: 'baja'", "generateDraft: 'Generar borrador'"] as $needle) {
    fi_spine_ok(strpos($prod, $needle) !== false, 'Spanish UI label exists: ' . $needle);
}
fi_spine_ok(strpos($prod, 'stateLabel(g.confidence)') !== false, 'confidence value is localized before render');
fi_spine_ok(strpos($prod, 'title="' . "' + esc(a.reason) + '" . '"') === false || strpos($prod, 'fiis-act-reason') !== false, 'disabled strategic actions include explicit reason captions');

fi_spine_ok(strpos($imap_php, "'lineage' => $" . 'lineage') !== false, 'Intelligence Map API includes fallback lineage rows');
fi_spine_ok(strpos($imap_php, 'function lineage_rows') !== false, 'Intelligence Map builds lineage rows server-side');
fi_spine_ok(strpos($imap_js, 'renderOperatorSummary') !== false && strpos($imap_js, 'renderLineageTable') !== false, 'Intelligence Map renders operator summary plus lineage table');
fi_spine_ok(strpos($imap_js, "['Run', 'Insight', 'Brief', 'Memory', 'Status', 'Confidence', 'Last updated', 'Open']") !== false, 'lineage table has required operator columns');
fi_spine_ok(strpos($imap_css, '.fiis-imap-lineage') !== false && strpos($imap_css, '.fiis-imap-tablewrap') !== false, 'lineage table has readable/scrolled CSS');

fi_spine_ok(strpos($provider_admin, 'provider_key_label') !== false && strpos($provider_admin, 'Module / use case') !== false, 'Provider Contracts show human labels and module/use case');
fi_spine_ok(strpos($provider_admin, 'Token') !== false && strpos($provider_admin, 'missing') !== false, 'Provider Contracts show configured/missing token status without values');
fi_spine_ok(strpos($access_admin, 'human_key_label') !== false && strpos($access_admin, 'key: <code>') !== false, 'Plans & Access show human labels beside raw keys');
fi_spine_ok(strpos($access_admin, 'Manual workflows, local records, review screens, reports, and the signed callback simulator can still be tested') !== false, 'Overview missing-token warning explains what still works');
fi_spine_ok(strpos($access_admin, 'Token values are never rendered') !== false, 'Provider Settings declares no token values are shown');
fi_spine_ok(strpos($access_admin, 'Google Search') !== false && strpos($access_admin, 'key: <code><?php echo esc_html($p); ?></code>') !== false, 'Provider Settings show human labels and raw keys');

foreach (['secret_configured', 'secret', 'signature', 'Bearer ', 'OPENAI_API_KEY', 'apify_api_'] as $sensitive) {
    fi_spine_ok(strpos($css . $ui . $imap_js . $imap_css, $sensitive) === false, 'UI/CSS assets do not contain sensitive token markers: ' . $sensitive);
}
fi_spine_finish('v0.9.32 real browser UI bugfix checks');
