<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/css/fiis-ui-system.css');
foreach (['--fi-bg','--fi-bg-paper','--fi-surface','--fi-surface-elevated','--fi-text','--fi-muted','--fi-border','--fi-blue','--fi-sand','--fi-lime','--fi-orange','--fi-danger','--fi-warning','--fi-success','--fi-focus'] as $token) {
    fi_spine_ok(strpos($css, $token) !== false, 'design token exists ' . $token);
}
foreach (['fi-command-layout','fi-command-sidebar','fi-command-canvas','fi-command-drawer','fi-command-timeline','fi-decision-map','fi-report-preview','fi-provider-filters','fi-run-diagnostics-box','fi-usage-strip','fi-progress','fi-onboarding-steps'] as $class) {
    fi_spine_ok(strpos($css, $class) !== false, 'UI component class styled ' . $class);
}
fi_spine_ok(strpos($css, '@media (max-width: 1024px)') !== false, 'desktop/tablet responsive breakpoint exists');
fi_spine_ok(strpos($css, '@media (max-width: 768px)') !== false, 'tablet/mobile responsive breakpoint exists');
fi_spine_ok(strpos($css, '@media (max-width: 430px)') !== false, 'small phone responsive breakpoint exists');
fi_spine_ok(strpos($css, ':focus-visible') !== false, 'visible focus states exist');
fi_spine_ok(strpos($css, 'prefers-reduced-motion') !== false, 'reduced motion rule exists');
fi_spine_ok(stripos($css, 'glassmorphism') === false && stripos($css, 'gradient') === false, 'no forbidden generic visual language in new UI layer');

$renderer = file_get_contents($root . '/includes/modules/command-room/class-fi-command-room-renderer.php');
foreach (['Command canvas','Playbook launcher','Evidence / object drawer','Run timeline','Decision Map','Insight Review Queue','Usage:','Next recommended action','Why did this run fail?'] as $copy) {
    fi_spine_ok(strpos($renderer, $copy) !== false, 'Command Room renders product copy: ' . $copy);
}
fi_spine_ok(strpos($renderer, 'blank chat') !== false, 'Command Room explicitly avoids blank chat framing');
fi_spine_ok(stripos($renderer, 'raw provider payloads') !== false, 'Command Room says raw provider payloads are not shown');
fi_spine_ok(strpos($renderer, 'auto-publish') !== false, 'draft review says no auto-publish behavior');

$onboarding = file_get_contents($root . '/includes/class-ves-workspace-onboarding-service.php');
foreach (['Workspace','Brand profile','Market / language','Competitors','First playbook','Provider mode','Usage mode','Review defaults','What happens next:'] as $copy) {
    fi_spine_ok(strpos($onboarding, $copy) !== false, 'onboarding UX includes ' . $copy);
}
fi_spine_ok(strpos($onboarding, 'role="progressbar"') !== false, 'onboarding progress indicator renders');
fi_spine_ok(strpos($onboarding, 'approved_external_orchestrator') !== false, 'onboarding includes approved external orchestrator mode');

$provider_admin = file_get_contents($root . '/includes/class-ves-provider-admin-page.php');
foreach (['Provider Ingestions Ledger','fi-provider-filters','fi-status-chip','Redacted details','Why rejected','Raw callback bodies, signatures, cookies, and provider tokens are never shown'] as $copy) {
    fi_spine_ok(strpos($provider_admin, $copy) !== false, 'provider ledger UX includes ' . $copy);
}

$report = file_get_contents($root . '/includes/class-ves-decision-report-service.php');
foreach (['1. Executive summary','2. Source coverage','3. Key signals and evidence summary','4. Insights','5. Review state','6. Brief outputs','7. Draft outputs','8. Usage summary','9. Outcome receipts','10. Pattern observations','11. Timeline summary','12. Proof needed / weak evidence','13. Appendix','HTML preview','Markdown export','Private JSON export'] as $copy) {
    fi_spine_ok(strpos($report, $copy) !== false, 'Decision Report preview includes ' . $copy);
}
fi_spine_ok(strpos($report, 'no public share link') !== false, 'Decision Report keeps no-public-share boundary');

$qa = file_get_contents($root . '/includes/class-ves-operator-qa-service.php');
fi_spine_ok(strpos($qa, 'Future Island Operator QA') !== false && strpos($qa, 'approved external orchestrator') !== false, 'Operator QA screen is orchestration-agnostic');
fi_spine_ok(strpos($qa, 'No provider secrets or signatures are displayed') !== false, 'Operator QA secrets boundary visible');
fi_spine_finish('v0.9.1 UI/UX private beta upgrade checks');
