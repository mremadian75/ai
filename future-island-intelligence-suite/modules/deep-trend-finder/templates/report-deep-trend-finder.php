<?php
if (!defined('ABSPATH')) { exit; }
/**
 * v0.3.55 — Deep Trend Finder report as an intelligence artifact.
 *
 * Reading order (evidence before recommendation, proof-needed near risk):
 *   1. Title + status            7. Interpretation / key insights
 *   2. Decision summary          8. Briefing route
 *   3. Claim-readiness gate      9. Platform-ready outputs
 *   4. Evidence map             10. Proof still needed
 *   5. Source execution truth   11. Limitations
 *   6. Signal clusters          12. Trace + diagnostics
 */
$source_summary = (array) ($report['source_summary'] ?? []);
$stats = (array) ($report['statistical_summary'] ?? []);
$live_sources = (array) ($report['sources_with_provider_rows'] ?? []);
$evidence_sources = (array) ($report['sources_with_evidence'] ?? []);
$intel = (array) ($report['evidence_intelligence'] ?? []);
$source_confidence = (array) ($report['source_confidence'] ?? []);
$marketing_decision = (array) ($report['marketing_decision_summary'] ?? []);
$confidence_breakdown = (array) ($report['confidence_breakdown'] ?? []);
$source_role_matrix = (array) ($report['source_role_matrix'] ?? []);
$xpi = (array) ($report['cross_platform_intelligence'] ?? []);
$claim_readiness = (array) ($xpi['claim_readiness'] ?? []);
$google_ads_block = (array) ($report['platform_asset_blocks']['google_ads'] ?? []);

// Confidence level helper — maps string value to CSS class and progress %
$confidence_raw = strtolower(trim((string) ($report['confidence'] ?? 'low')));
if (in_array($confidence_raw, ['high', 'very_high'], true)) {
    $confidence_class = 'is-high';
} elseif (in_array($confidence_raw, ['medium', 'mid', 'moderate'], true)) {
    $confidence_class = 'is-mid';
} else {
    $confidence_class = 'is-low';
}
switch ($confidence_raw) {
    case 'very_high': $confidence_pct = 92; break;
    case 'high': $confidence_pct = 76; break;
    case 'medium':
    case 'mid':
    case 'moderate': $confidence_pct = 52; break;
    default: $confidence_pct = 24; break;
}
$src_conf_level = strtolower((string) ($source_confidence['level'] ?? 'low'));

if (!function_exists('fidtf_source_status_class')) {
    function fidtf_source_status_class(string $status): string {
        $s = strtolower(trim($status));
        if (strpos($s, 'live') !== false || strpos($s, 'done') !== false || strpos($s, 'complete') !== false || strpos($s, 'usable') !== false) return 'is-live';
        if (strpos($s, 'error') !== false || strpos($s, 'fail') !== false || strpos($s, 'discard') !== false || strpos($s, 'invalid') !== false || strpos($s, 'allowlist') !== false || strpos($s, 'timed out') !== false) return 'is-err';
        if (strpos($s, 'plan') !== false || strpos($s, 'skip') !== false || strpos($s, 'zero') !== false || strpos($s, '0 provider') !== false || strpos($s, 'not proven') !== false) return 'is-warn';
        return 'is-done';
    }
}
if (!function_exists('fidtf_human_label')) {
    function fidtf_human_label(string $value): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        $map = [
            'ready_for_internal_test_not_market_claim' => 'Ready for internal test, not market claim',
            'ready_for_internal_test' => 'Ready for internal test',
            'json_only_unverified' => 'JSON-only, not file-verified',
            'provider_transport_error' => 'Provider connection issue',
            'actor_dispatch_failed' => 'Source actor failed to start',
            'provider_results_zero_usable_evidence' => 'Provider returned rows, 0 usable evidence rows',
            'provider_result_parsing_failed' => 'Provider result exists, parsing failed',
            'source_actor_not_allowlisted' => 'Source actor not allowlisted',
            'provider_timeout_stale' => 'Source run timed out (watchdog)',
            'blocked_insufficient_evidence' => 'Blocked — insufficient evidence',
            'not_proven' => 'Not proven',
            'hypothesis' => 'Hypothesis',
        ];
        $key = strtolower(str_replace([' ', '-'], '_', $value));
        if (isset($map[$key])) { return $map[$key]; }
        $label = ucfirst(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $value))));
        return str_replace(['Tiktok', 'Google trends', 'Google news'], ['TikTok', 'Google Trends', 'Google News'], $label);
    }
}
if (!function_exists('fidtf_source_label')) {
    function fidtf_source_label(string $source): string {
        $map = [
            'tiktok' => 'TikTok',
            'instagram' => 'Instagram',
            'google_trends' => 'Google Trends',
            'google_news' => 'Google News',
            'google_search' => 'Google Search',
            'reddit' => 'Reddit',
            'ai_research' => 'AI Research',
        ];
        $source = strtolower(trim($source));
        return $map[$source] ?? fidtf_human_label($source);
    }
}
if (!function_exists('fidtf_asset_valid_class')) {
    function fidtf_asset_valid_class(array $field): string {
        return !empty($field['valid']) ? 'is-valid' : 'is-invalid';
    }
}
?>
<div class="fidtf-report fidtf-report-v3 fidtf-artifact">

    <!-- 1 · Intelligence title + status -->
    <header class="fidtf-report-head fidtf-artifact-head">
        <p class="fidtf-kicker"><?php echo esc_html__('Deep Trend Finder · Intelligence artifact', 'future-island-deep-trend-finder-addon'); ?></p>
        <h3><?php echo esc_html((string) ($report['status_label'] ?? 'Report')); ?></h3>
        <div class="fidtf-report-head-meta">
            <span class="fidtf-confidence-score <?php echo esc_attr($confidence_class); ?>"><?php echo esc_html__('Confidence:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(fidtf_human_label($confidence_raw)); ?></span>
            <?php if (!empty($src_conf_level)): ?>
                <span class="fidtf-confidence-score <?php echo esc_attr(in_array($src_conf_level, ['high', 'very_high'], true) ? 'is-high' : (in_array($src_conf_level, ['medium', 'mid', 'moderate'], true) ? 'is-mid' : 'is-low')); ?>"><?php echo esc_html__('Source confidence:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(fidtf_human_label($src_conf_level)); ?></span>
            <?php endif; ?>
            <span class="fidtf-chip"><?php echo esc_html__('Credit mode:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(fidtf_human_label((string) ($report['credit_mode'] ?? 'planning_only'))); ?></span>
        </div>

        <!-- Editorial summary strip: one line of truth, not a tile wall -->
        <dl class="fidtf-summary-strip">
            <div><dt><?php echo esc_html__('Decision-ready rows', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($stats['total_decision_ready_items'] ?? $report['usable_evidence_count'] ?? 0)); ?></dd></div>
            <div><dt><?php echo esc_html__('Usable evidence', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($report['usable_evidence_count'] ?? 0)); ?></dd></div>
            <div><dt><?php echo esc_html__('Parsed provider rows', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($stats['total_parsed_items'] ?? $stats['total_raw_items'] ?? 0)); ?></dd></div>
            <div><dt><?php echo esc_html__('Source families', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) count($evidence_sources)); ?></dd></div>
            <?php if (!empty($intel)): ?>
                <div><dt><?php echo esc_html__('Direct matches', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($intel['direct_count'] ?? 0)); ?></dd></div>
                <div><dt><?php echo esc_html__('Discarded rows', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($stats['total_discarded_items'] ?? 0)); ?></dd></div>
            <?php endif; ?>
        </dl>
        <div class="fidtf-progress-wrap" aria-label="<?php echo esc_attr__('Overall confidence', 'future-island-deep-trend-finder-addon'); ?>">
            <div class="fidtf-progress-meta">
                <span><?php echo esc_html__('Signal confidence', 'future-island-deep-trend-finder-addon'); ?></span>
                <span><?php echo esc_html($confidence_pct); ?>%</span>
            </div>
            <div class="fidtf-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr($confidence_pct); ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="fidtf-progress-bar-fill <?php echo esc_attr($confidence_class); ?>" style="width:<?php echo esc_attr($confidence_pct); ?>%"></div>
            </div>
        </div>
    </header>

    <?php if (!empty($report['warnings'])): ?>
        <div class="fidtf-warning-box">
            <div class="fidtf-preflight-inner">
                <span class="fidtf-preflight-icon" aria-hidden="true">!</span>
                <div class="fidtf-preflight-body">
                    <?php foreach ((array) $report['warnings'] as $warning): ?>
                        <p style="margin:0 0 4px;"><?php echo esc_html((string) $warning); ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ((int) ($report['usable_evidence_count'] ?? 0) === 0): ?>
        <div class="fidtf-warning-box">
            <div class="fidtf-preflight-inner">
                <span class="fidtf-preflight-icon" aria-hidden="true">!</span>
                <div class="fidtf-preflight-body">
                    <p style="margin:0 0 4px;"><?php echo esc_html__('This is a planned run, not an evidence report yet.', 'future-island-deep-trend-finder-addon'); ?></p>
                    <p style="margin:0;"><?php echo esc_html__('Planning estimate only. No final credits were settled.', 'future-island-deep-trend-finder-addon'); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 2 · Decision summary -->
    <?php if (!empty($report['executive_summary']) || !empty($marketing_decision)): ?>
        <section class="fidtf-artifact-section fidtf-decision-summary-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Decision summary', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Marketing decision summary', 'future-island-deep-trend-finder-addon'); ?></h4>
            <?php if (!empty($report['executive_summary'])): ?>
                <p class="fidtf-report-lead"><?php echo esc_html((string) $report['executive_summary']); ?></p>
            <?php endif; ?>
            <?php if (!empty($marketing_decision)): ?>
                <dl class="fidtf-decision-grid">
                    <?php
                    $decision_labels = [
                        'research_mode'                  => __('Research mode', 'future-island-deep-trend-finder-addon'),
                        'best_current_opportunity'       => __('Best current opportunity', 'future-island-deep-trend-finder-addon'),
                        'strongest_audience_or_occasion' => __('Strongest audience / occasion', 'future-island-deep-trend-finder-addon'),
                        'recommended_channel_to_test'    => __('First test environment', 'future-island-deep-trend-finder-addon'),
                        'recommended_creative_route'     => __('Creative hypothesis route', 'future-island-deep-trend-finder-addon'),
                        'main_risk'                      => __('Main risk', 'future-island-deep-trend-finder-addon'),
                        'proof_still_needed'             => __('Proof still needed', 'future-island-deep-trend-finder-addon'),
                        'decision_status'                => __('Decision status', 'future-island-deep-trend-finder-addon'),
                    ];
                    foreach ($decision_labels as $key => $label):
                        if (!isset($marketing_decision[$key]) || $marketing_decision[$key] === '') { continue; }
                    ?>
                        <div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($key === 'decision_status' ? fidtf_human_label((string) $marketing_decision[$key]) : (string) $marketing_decision[$key]); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <ul class="fidtf-inline-list">
                    <li><?php echo esc_html__('Decision-ready rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($marketing_decision['decision_ready_rows'] ?? 0)); ?></li>
                    <li><?php echo esc_html__('Creative-only rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($marketing_decision['creative_only_rows'] ?? 0)); ?></li>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- 3 · Claim-readiness gate -->
    <?php if (!empty($claim_readiness)): ?>
        <section class="fidtf-artifact-section fidtf-gate fidtf-claim-readiness-box">
            <span class="fidtf-section-label"><?php echo esc_html__('Claim-readiness gate', 'future-island-deep-trend-finder-addon'); ?></span>
            <div class="fidtf-gate-row">
                <strong class="fidtf-gate-level"><?php echo esc_html(fidtf_human_label((string) ($claim_readiness['level'] ?? 'do_not_claim'))); ?></strong>
                <span><?php echo esc_html__('Score', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($claim_readiness['score'] ?? 0)); ?>/100</span>
                <span><?php echo esc_html__('Direct ratio', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($claim_readiness['direct_ratio'] ?? '0%')); ?></span>
            </div>
            <?php if (!empty($claim_readiness['claims_blocked'])): ?>
                <h5><?php echo esc_html__('Claims blocked until validated', 'future-island-deep-trend-finder-addon'); ?></h5>
                <ul><?php foreach ((array) $claim_readiness['claims_blocked'] as $blocked_claim): ?><li><?php echo esc_html((string) $blocked_claim); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($confidence_breakdown)): ?>
        <section class="fidtf-artifact-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Signal quality', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Confidence breakdown', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-mini-bars">
                <?php foreach ($confidence_breakdown as $confidence_key => $confidence_value): ?>
                    <span><b><?php echo esc_html(fidtf_human_label((string) $confidence_key)); ?></b><em><?php echo esc_html(fidtf_human_label((string) $confidence_value)); ?></em></span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- 4 · Evidence map -->
    <?php if (!empty($report['evidence_quality_summary']) || !empty($intel['signal_mix']) || !empty($intel['hook_families'])): ?>
        <section class="fidtf-artifact-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Evidence map', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Evidence map', 'future-island-deep-trend-finder-addon'); ?></h4>
            <?php if (!empty($report['evidence_quality_summary'])): ?>
                <p><?php echo esc_html((string) ($report['evidence_quality_summary']['summary'] ?? '')); ?></p>
                <ul class="fidtf-inline-list">
                    <li><?php echo esc_html__('Live:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(implode(', ', array_map('fidtf_source_label', $live_sources)) ?: 'none'); ?></li>
                    <?php if (!empty($report['evidence_quality_summary']['tier_counts'])): ?>
                        <?php foreach ((array) $report['evidence_quality_summary']['tier_counts'] as $tier_label => $tier_count): ?>
                            <li><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $tier_label))); ?>: <?php echo esc_html((string) $tier_count); ?></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <div class="fidtf-evidence-map-grid">
                <?php if (!empty($intel['signal_mix'])): ?>
                    <div>
                        <h5><?php echo esc_html__('Signal mix', 'future-island-deep-trend-finder-addon'); ?></h5>
                        <div class="fidtf-mini-bars">
                            <?php foreach ((array) $intel['signal_mix'] as $row): ?>
                                <span><b><?php echo esc_html((string) ($row['label'] ?? 'signal')); ?></b><em><?php echo esc_html((string) ($row['count'] ?? 0)); ?></em></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($intel['hook_families'])): ?>
                    <div>
                        <h5><?php echo esc_html__('Hook mechanics detected', 'future-island-deep-trend-finder-addon'); ?></h5>
                        <div class="fidtf-mini-bars">
                            <?php foreach ((array) $intel['hook_families'] as $row): ?>
                                <span><b><?php echo esc_html((string) ($row['label'] ?? 'hook')); ?></b><em><?php echo esc_html((string) ($row['count'] ?? 0)); ?></em></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- 5 · Source execution truth -->
    <section class="fidtf-artifact-section">
        <span class="fidtf-section-label"><?php echo esc_html__('Source execution truth', 'future-island-deep-trend-finder-addon'); ?></span>
        <h4><?php echo esc_html__('Source execution', 'future-island-deep-trend-finder-addon'); ?></h4>
        <div class="fidtf-source-rail">
            <?php foreach ($source_summary as $source => $summary):
                $state_label = (string) ($summary['source_state_label'] ?? $summary['status_label'] ?? $summary['status'] ?? 'unknown');
                $src_class = fidtf_source_status_class($state_label);
                $discard_reasons = array_map('fidtf_human_label', (array) ($summary['discard_reasons'] ?? []));
            ?>
                <div class="fidtf-source-row <?php echo esc_attr($src_class); ?> fidtf-source-truth-card">
                    <div class="fidtf-source-row-head">
                        <strong><?php echo esc_html(fidtf_source_label((string) $source)); ?></strong>
                        <span class="fidtf-chip"><?php echo esc_html(fidtf_human_label($state_label)); ?></span>
                    </div>
                    <dl class="fidtf-source-counts">
                        <div><dt><?php echo esc_html__('Provider returned:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['provider_returned_items'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Actor dataset items:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['actor_dataset_items'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Parsed:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['parsed_items'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Normalized:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['normalized_items'] ?? $summary['normalized_count'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Usable evidence:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['relevant_count'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Decision-ready:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['decision_ready_items'] ?? $summary['decision_ready_count'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Creative-only:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['creative_only_items'] ?? 0)); ?></dd></div>
                        <div><dt><?php echo esc_html__('Discarded:', 'future-island-deep-trend-finder-addon'); ?></dt><dd><?php echo esc_html((string) ($summary['discarded_items'] ?? 0)); ?></dd></div>
                    </dl>
                    <?php if (!empty($discard_reasons) || !empty($summary['error_code'])): ?>
                        <details class="fidtf-diagnostics"><summary><?php echo esc_html__('Technical diagnostics', 'future-island-deep-trend-finder-addon'); ?></summary>
                            <?php if (!empty($summary['error_code'])): ?><small><?php echo esc_html__('Code:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) $summary['error_code']); ?> <?php if (!empty($summary['error_label'])): ?>· <?php echo esc_html((string) $summary['error_label']); ?><?php endif; ?></small><?php endif; ?>
                            <?php if (!empty($discard_reasons)): ?><small><?php echo esc_html__('Discard reasons:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(implode(', ', $discard_reasons)); ?></small><?php endif; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($source_role_matrix)): ?>
            <details class="fidtf-diagnostics fidtf-role-matrix">
                <summary><?php echo esc_html__('Source role matrix (what each source can and cannot support)', 'future-island-deep-trend-finder-addon'); ?></summary>
                <div class="fidtf-source-rail">
                    <?php foreach ($source_role_matrix as $row): ?>
                        <div class="fidtf-source-row <?php echo esc_attr(fidtf_source_status_class((string) ($row['role'] ?? ''))); ?>">
                            <div class="fidtf-source-row-head">
                                <strong><?php echo esc_html(fidtf_source_label((string) ($row['source'] ?? 'source'))); ?></strong>
                                <span class="fidtf-chip"><?php echo esc_html((string) ($row['role'] ?? 'supporting evidence')); ?></span>
                            </div>
                            <small><strong><?php echo esc_html__('Can support:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($row['can_support'] ?? '')); ?></small>
                            <small><strong><?php echo esc_html__('Cannot support:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($row['cannot_support'] ?? '')); ?></small>
                            <small><?php echo esc_html__('Relevant rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($row['relevant_rows'] ?? 0)); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <!-- 6 · Signal clusters -->
    <?php if (!empty($report['signal_clusters'])): ?>
        <section class="fidtf-artifact-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Signal clusters', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Signal clusters', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-pattern-grid">
                <?php foreach ((array) $report['signal_clusters'] as $cluster): ?>
                    <article class="fidtf-report-card fidtf-cluster-card">
                        <div class="fidtf-evidence-group-head">
                            <strong><?php echo esc_html((string) ($cluster['label'] ?? 'Cluster')); ?></strong>
                            <span class="fidtf-count-badge"><?php echo esc_html((string) ($cluster['count'] ?? 0)); ?></span>
                        </div>
                        <?php if (!empty($cluster['raw_terms'])): ?><p class="fidtf-raw-terms"><strong><?php echo esc_html__('Raw terms:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html(implode(', ', (array) $cluster['raw_terms'])); ?></p><?php endif; ?>
                        <?php if (!empty($cluster['examples'])): ?>
                            <ul><?php foreach ((array) $cluster['examples'] as $example): ?><li><?php echo esc_html((string) $example); ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- 7 · Interpretation / key insights -->
    <?php if (!empty($report['insights']) || !empty($report['key_patterns']) || !empty($report['strategic_readout']) || !empty($report['evidence_fit_diagnosis']) || !empty($xpi)): ?>
        <section class="fidtf-artifact-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Interpretation', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Key insights', 'future-island-deep-trend-finder-addon'); ?></h4>
            <?php if (!empty($report['insights'])): ?>
                <div class="fidtf-insight-stack">
                    <?php foreach ((array) $report['insights'] as $insight): ?>
                        <article class="fidtf-report-card fidtf-insight-card">
                            <h5><?php echo esc_html((string) ($insight['title'] ?? 'Insight')); ?></h5>
                            <?php if (!empty($insight['evidence'])): ?><p><strong><?php echo esc_html__('Evidence:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $insight['evidence']); ?></p><?php endif; ?>
                            <?php if (!empty($insight['implication'])): ?><p><strong><?php echo esc_html__('Implication:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $insight['implication']); ?></p><?php endif; ?>
                            <?php if (!empty($insight['confidence'])): ?>
                                <span class="fidtf-confidence-score <?php echo in_array(strtolower((string) $insight['confidence']), ['high','very_high'], true) ? 'is-high' : (in_array(strtolower((string) $insight['confidence']), ['medium','mid','moderate'], true) ? 'is-mid' : 'is-low'); ?>">
                                    <?php echo esc_html__('Confidence:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) $insight['confidence']); ?>
                                </span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($report['key_patterns'])): ?>
                <h5 class="fidtf-sub-head"><?php echo esc_html__('Key patterns', 'future-island-deep-trend-finder-addon'); ?></h5>
                <div class="fidtf-pattern-grid">
                    <?php foreach ((array) $report['key_patterns'] as $pattern): ?>
                        <article class="fidtf-report-card">
                            <strong><?php echo esc_html((string) ($pattern['label'] ?? 'Pattern')); ?></strong>
                            <p><?php echo esc_html((string) ($pattern['value'] ?? '')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($report['strategic_readout'])): ?>
                <div class="fidtf-strategic-readout">
                    <h5 class="fidtf-sub-head"><?php echo esc_html__('Strategic readout', 'future-island-deep-trend-finder-addon'); ?></h5>
                    <?php foreach ((array) $report['strategic_readout'] as $label => $value): ?>
                        <p><strong><?php echo esc_html(fidtf_human_label((string) $label)); ?>:</strong> <?php echo esc_html((string) $value); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($report['evidence_fit_diagnosis'])): ?>
                <div class="fidtf-fit-card">
                    <h5 class="fidtf-sub-head"><?php echo esc_html__('Evidence fit diagnosis', 'future-island-deep-trend-finder-addon'); ?></h5>
                    <?php foreach ((array) $report['evidence_fit_diagnosis'] as $label => $value): ?>
                        <p><strong><?php echo esc_html(fidtf_human_label((string) $label)); ?>:</strong> <?php echo esc_html((string) $value); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($xpi)): ?>
                <div class="fidtf-cross-platform-card">
                    <h5 class="fidtf-sub-head"><?php echo esc_html__('Cross-platform intelligence', 'future-island-deep-trend-finder-addon'); ?></h5>
                    <p><strong><?php echo esc_html__('Analysis level:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($xpi['analysis_level'] ?? 'cross-platform readout')); ?></p>
                    <p><strong><?php echo esc_html__('Convergence:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($xpi['convergence_level'] ?? 'low')); ?></p>
                    <?php if (!empty($xpi['signal_balance']) && is_array($xpi['signal_balance'])): ?>
                        <p><strong><?php echo esc_html__('Signal balance:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($xpi['signal_balance']['interpretation'] ?? '')); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($xpi['statistical_scorecard']) && is_array($xpi['statistical_scorecard'])): ?>
                        <?php $scorecard = (array) $xpi['statistical_scorecard']; ?>
                        <p><strong><?php echo esc_html__('Data scientist scorecard:', 'future-island-deep-trend-finder-addon'); ?></strong>
                            <?php echo esc_html__('Confidence', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($scorecard['analysis_confidence'] ?? 'low')); ?> ·
                            <?php echo esc_html__('Dominant source', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html(fidtf_source_label((string) ($scorecard['dominant_source'] ?? 'none'))); ?> (<?php echo esc_html((string) ($scorecard['dominant_source_share'] ?? '0%')); ?>) ·
                            <?php echo esc_html__('Direct evidence', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($scorecard['direct_evidence_share'] ?? '0%')); ?>
                        </p>
                        <?php if (!empty($scorecard['coverage_by_evidence_family'])): ?>
                            <ul class="fidtf-inline-list">
                                <?php foreach ((array) $scorecard['coverage_by_evidence_family'] as $family => $count): ?>
                                    <li><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $family))); ?>: <?php echo esc_html((string) $count); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($xpi['shared_language'])): ?>
                        <h6><?php echo esc_html__('Shared language across sources', 'future-island-deep-trend-finder-addon'); ?></h6>
                        <ul class="fidtf-inline-list">
                            <?php foreach ((array) $xpi['shared_language'] as $row): ?>
                                <li><?php echo esc_html((string) ($row['term'] ?? '')); ?> — <?php echo esc_html(implode(', ', array_map('fidtf_source_label', (array) ($row['sources'] ?? [])))); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!empty($xpi['hypotheses_to_test'])): ?>
                        <h6><?php echo esc_html__('Hypotheses to test', 'future-island-deep-trend-finder-addon'); ?></h6>
                        <ol class="fidtf-validation-list">
                            <?php foreach ((array) $xpi['hypotheses_to_test'] as $hypothesis): ?><li><?php echo esc_html((string) $hypothesis); ?></li><?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                    <?php if (!empty($xpi['platform_biases'])): ?>
                        <h6><?php echo esc_html__('Platform bias warnings', 'future-island-deep-trend-finder-addon'); ?></h6>
                        <ul><?php foreach ((array) $xpi['platform_biases'] as $bias): ?><li><?php echo esc_html((string) $bias); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if (!empty($xpi['decision_rule'])): ?><p><strong><?php echo esc_html__('Decision rule:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $xpi['decision_rule']); ?></p><?php endif; ?>
                    <?php if (!empty($xpi['source_roles'])): ?>
                        <details class="fidtf-diagnostics">
                            <summary><?php echo esc_html__('Per-source role detail', 'future-island-deep-trend-finder-addon'); ?></summary>
                            <div class="fidtf-source-rail">
                                <?php foreach ((array) $xpi['source_roles'] as $role): ?>
                                    <div class="fidtf-source-row <?php echo esc_attr(fidtf_source_status_class((string) ($role['role'] ?? ''))); ?>">
                                        <div class="fidtf-source-row-head">
                                            <strong><?php echo esc_html(fidtf_source_label((string) ($role['source'] ?? 'source'))); ?></strong>
                                            <span class="fidtf-chip"><?php echo esc_html((string) ($role['role'] ?? 'supporting evidence')); ?></span>
                                        </div>
                                        <small><?php echo esc_html__('Provider rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($role['provider_rows'] ?? 0)); ?> · <?php echo esc_html__('Relevant rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($role['relevant_rows'] ?? 0)); ?></small>
                                        <?php if (!empty($role['dominant_terms'])): ?><small><?php echo esc_html__('Dominant terms:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) $role['dominant_terms']); ?></small><?php endif; ?>
                                        <?php if (!empty($role['quality_note'])): ?><small><?php echo esc_html((string) $role['quality_note']); ?></small><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- 8 · Briefing route -->
    <?php if (!empty($report['briefing_recommendations']) || !empty($report['content_angle_briefs']) || !empty($report['creative_territories'])): ?>
        <section class="fidtf-artifact-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Briefing route', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Briefing recommendations', 'future-island-deep-trend-finder-addon'); ?></h4>
            <?php if (!empty($report['briefing_recommendations'])): ?>
                <ol class="fidtf-validation-list">
                    <?php foreach ((array) $report['briefing_recommendations'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if (!empty($report['content_angle_briefs'])): ?>
                <h5 class="fidtf-sub-head"><?php echo esc_html__('Content angle briefs', 'future-island-deep-trend-finder-addon'); ?></h5>
                <div class="fidtf-pattern-grid">
                    <?php foreach ((array) $report['content_angle_briefs'] as $brief): ?>
                        <article class="fidtf-report-card">
                            <h5><?php echo esc_html((string) ($brief['angle'] ?? 'Angle')); ?></h5>
                            <p><strong><?php echo esc_html__('Hook:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($brief['hook'] ?? '')); ?></p>
                            <p><strong><?php echo esc_html__('Evidence basis:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($brief['evidence_basis'] ?? '')); ?></p>
                            <p><strong><?php echo esc_html__('Test output:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($brief['test_output'] ?? '')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($report['creative_territories'])): ?>
                <h5 class="fidtf-sub-head"><?php echo esc_html__('Creative territories', 'future-island-deep-trend-finder-addon'); ?></h5>
                <div class="fidtf-pattern-grid">
                    <?php foreach ((array) $report['creative_territories'] as $territory): ?>
                        <article class="fidtf-report-card fidtf-territory-card">
                            <h5><?php echo esc_html((string) ($territory['territory'] ?? 'Territory')); ?></h5>
                            <p><strong><?php echo esc_html__('Idea:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($territory['idea'] ?? '')); ?></p>
                            <p><strong><?php echo esc_html__('Why it matters:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($territory['why_it_matters'] ?? '')); ?></p>
                            <p><strong><?php echo esc_html__('Proof needed:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($territory['proof_needed'] ?? '')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- 9 · Platform-ready outputs -->
    <?php if (!empty($google_ads_block)): ?>
        <section class="fidtf-artifact-section fidtf-asset-block fidtf-google-ads-block">
            <span class="fidtf-section-label"><?php echo esc_html__('Platform-ready outputs', 'future-island-deep-trend-finder-addon'); ?></span>
            <div class="fidtf-evidence-group-head">
                <h4><?php echo esc_html__('Google Ads asset block', 'future-island-deep-trend-finder-addon'); ?></h4>
                <span class="fidtf-count-badge"><?php echo esc_html(fidtf_human_label((string) ($google_ads_block['status'] ?? 'draft'))); ?></span>
            </div>
            <?php if (!empty($google_ads_block['evidence_caveat'])): ?><p class="fidtf-asset-caveat"><strong><?php echo esc_html__('Evidence caveat:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $google_ads_block['evidence_caveat']); ?></p><?php endif; ?>
            <?php if (!empty($google_ads_block['proof_needed_note'])): ?><p class="fidtf-asset-caveat"><strong><?php echo esc_html__('Proof-needed note:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $google_ads_block['proof_needed_note']); ?></p><?php endif; ?>
            <p><strong><?php echo esc_html__('CTA suggestion:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($google_ads_block['cta_suggestion'] ?? 'Learn more')); ?></p>
            <?php
            $asset_groups = [
                'headlines' => __('Headlines', 'future-island-deep-trend-finder-addon'),
                'long_headlines' => __('Long headlines', 'future-island-deep-trend-finder-addon'),
                'descriptions' => __('Descriptions', 'future-island-deep-trend-finder-addon'),
            ];
            foreach ($asset_groups as $field_key => $field_label):
                if (empty($google_ads_block[$field_key]) || !is_array($google_ads_block[$field_key])) { continue; }
            ?>
                <div class="fidtf-asset-field-group">
                    <h5><?php echo esc_html($field_label); ?></h5>
                    <div class="fidtf-asset-field-list">
                        <?php foreach ((array) $google_ads_block[$field_key] as $asset_field): ?>
                            <div class="fidtf-asset-field <?php echo esc_attr(fidtf_asset_valid_class((array) $asset_field)); ?>">
                                <span><?php echo esc_html((string) ($asset_field['text'] ?? '')); ?></span>
                                <small><?php echo esc_html((string) ($asset_field['count'] ?? 0)); ?>/<?php echo esc_html((string) ($asset_field['limit'] ?? 0)); ?> <?php echo empty($asset_field['valid']) ? esc_html__('flagged', 'future-island-deep-trend-finder-addon') : esc_html__('OK', 'future-island-deep-trend-finder-addon'); ?></small>
                                <?php if (!empty($asset_field['valid'])): ?>
                                    <button type="button" class="fidtf-copy-btn" data-fidtf-copy="<?php echo esc_attr((string) ($asset_field['text'] ?? '')); ?>"><?php echo esc_html__('Copy', 'future-island-deep-trend-finder-addon'); ?></button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- 10 · Proof still needed -->
    <?php if (!empty($report['proof_still_needed']) || !empty($report['recommended_validation_plan']) || !empty($report['opportunities'])): ?>
        <section class="fidtf-artifact-section fidtf-gate fidtf-proof-needed-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Proof still needed', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Proof still needed', 'future-island-deep-trend-finder-addon'); ?></h4>
            <?php if (!empty($report['proof_still_needed'])): ?>
                <ul><?php foreach ((array) $report['proof_still_needed'] as $proof_item): ?><li><?php echo esc_html((string) $proof_item); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (!empty($report['recommended_validation_plan'])): ?>
                <h5 class="fidtf-sub-head"><?php echo esc_html__('Recommended validation plan', 'future-island-deep-trend-finder-addon'); ?></h5>
                <ol class="fidtf-validation-list">
                    <?php foreach ((array) $report['recommended_validation_plan'] as $step): ?>
                        <li><?php echo esc_html((string) $step); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if (!empty($report['opportunities'])): ?>
                <h5 class="fidtf-sub-head"><?php echo esc_html__('Recommended next actions', 'future-island-deep-trend-finder-addon'); ?></h5>
                <div class="fidtf-pattern-grid">
                    <?php foreach ((array) $report['opportunities'] as $opportunity): ?>
                        <article class="fidtf-report-card">
                            <h5><?php echo esc_html((string) ($opportunity['title'] ?? 'Next action')); ?></h5>
                            <?php if (!empty($opportunity['why'])): ?><p><?php echo esc_html((string) $opportunity['why']); ?></p><?php endif; ?>
                            <?php if (!empty($opportunity['next_action'])): ?><p><strong><?php echo esc_html__('Action:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $opportunity['next_action']); ?></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- 11 · Limitations -->
    <section class="fidtf-artifact-section fidtf-muted-list">
        <span class="fidtf-section-label"><?php echo esc_html__('Limitations', 'future-island-deep-trend-finder-addon'); ?></span>
        <div class="fidtf-limitations-grid">
            <?php if (!empty($report['can_say'])): ?>
                <div>
                    <h4><?php echo esc_html__('What this report can say', 'future-island-deep-trend-finder-addon'); ?></h4>
                    <ul><?php foreach ((array) $report['can_say'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($report['cannot_say'])): ?>
                <div>
                    <h4><?php echo esc_html__('What this report cannot say', 'future-island-deep-trend-finder-addon'); ?></h4>
                    <ul><?php foreach ((array) $report['cannot_say'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($report['risk_notes'])): ?>
            <h4 class="fidtf-sub-head"><?php echo esc_html__('Strategic risk notes', 'future-island-deep-trend-finder-addon'); ?></h4>
            <ul class="fidtf-risk-card"><?php foreach ((array) $report['risk_notes'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($report['not_claimed'])): ?>
            <h4 class="fidtf-sub-head"><?php echo esc_html__('Not claimed by this report', 'future-island-deep-trend-finder-addon'); ?></h4>
            <ul><?php foreach ((array) $report['not_claimed'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>

    <!-- 12 · Trace + diagnostics -->
    <section class="fidtf-artifact-section fidtf-diagnostics-card">
        <span class="fidtf-section-label"><?php echo esc_html__('Trace + diagnostics', 'future-island-deep-trend-finder-addon'); ?></span>
        <details class="fidtf-diagnostics">
            <summary><?php echo esc_html__('Show technical report metadata', 'future-island-deep-trend-finder-addon'); ?></summary>
            <ul>
                <li><?php echo esc_html__('Schema:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($report['schema_version'] ?? '')); ?></li>
                <li><?php echo esc_html__('Addon version:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($report['addon_version'] ?? '')); ?></li>
                <li><?php echo esc_html__('Synthesis mode:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(fidtf_human_label((string) ($report['synthesis_mode'] ?? ''))); ?></li>
                <li><?php echo esc_html__('Provider-returned source families:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(implode(', ', array_map('fidtf_source_label', $live_sources)) ?: 'none'); ?></li>
                <?php if (!empty($report['credit_breakdown']) && is_array($report['credit_breakdown'])): ?>
                    <li><?php echo esc_html__('Local planning credit:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($report['credit_breakdown']['local_planning'] ?? $report['credit_breakdown']['planning'] ?? 0)); ?></li>
                    <li><?php echo esc_html__('AI planner credit:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($report['credit_breakdown']['ai_planner'] ?? 0)); ?></li>
                <?php endif; ?>
            </ul>
        </details>
    </section>
</div>
