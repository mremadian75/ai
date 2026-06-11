<?php
if (!defined('ABSPATH')) { exit; }
$source_summary = (array) ($report['source_summary'] ?? []);
$stats = (array) ($report['statistical_summary'] ?? []);
$live_sources = (array) ($report['sources_with_provider_rows'] ?? []);
$evidence_sources = (array) ($report['sources_with_evidence'] ?? []);
$intel = (array) ($report['evidence_intelligence'] ?? []);
$source_confidence = (array) ($report['source_confidence'] ?? []);
$marketing_decision = (array) ($report['marketing_decision_summary'] ?? []);
$confidence_breakdown = (array) ($report['confidence_breakdown'] ?? []);
$source_role_matrix = (array) ($report['source_role_matrix'] ?? []);

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

// Source confidence class helper
$src_conf_level = strtolower((string) ($source_confidence['level'] ?? 'low'));
if (in_array($src_conf_level, ['high', 'very_high'], true)) {
    $src_conf_class = 'is-high';
} elseif (in_array($src_conf_level, ['medium', 'mid', 'moderate'], true)) {
    $src_conf_class = 'is-mid';
} else {
    $src_conf_class = 'is-low';
}

// Source status class helper
if (!function_exists('fidtf_source_status_class')) {
    function fidtf_source_status_class(string $status): string {
        $s = strtolower(trim($status));
        if (strpos($s, 'live') !== false || strpos($s, 'done') !== false || strpos($s, 'complete') !== false) return 'is-live';
        if (strpos($s, 'error') !== false || strpos($s, 'fail') !== false) return 'is-err';
        if (strpos($s, 'plan') !== false || strpos($s, 'skip') !== false) return 'is-warn';
        return 'is-done';
    }
}
?>
<div class="fidtf-report fidtf-report-v3">
    <div class="fidtf-report-head">
        <p class="fidtf-kicker"><?php echo esc_html__('Deep Trend Finder Report', 'future-island-deep-trend-finder-addon'); ?></p>
        <h3><?php echo esc_html((string) ($report['status_label'] ?? 'Report')); ?></h3>
        <?php if (!empty($report['executive_summary'])): ?>
            <p class="fidtf-report-lead"><?php echo esc_html((string) $report['executive_summary']); ?></p>
        <?php endif; ?>

        <div class="fidtf-metric-grid">
            <span><strong><?php echo esc_html((string) ($report['usable_evidence_count'] ?? 0)); ?></strong><?php echo esc_html__('usable signals', 'future-island-deep-trend-finder-addon'); ?></span>
            <span><strong><?php echo esc_html((string) ($stats['total_raw_items'] ?? 0)); ?></strong><?php echo esc_html__('raw rows', 'future-island-deep-trend-finder-addon'); ?></span>
            <span><strong><?php echo esc_html((string) count($evidence_sources)); ?></strong><?php echo esc_html__('source families', 'future-island-deep-trend-finder-addon'); ?></span>
            <?php if (!empty($intel)): ?>
                <span><strong><?php echo esc_html((string) ($intel['direct_count'] ?? 0)); ?></strong><?php echo esc_html__('direct signals', 'future-island-deep-trend-finder-addon'); ?></span>
                <span><strong><?php echo esc_html((string) ($intel['adjacent_count'] ?? 0)); ?></strong><?php echo esc_html__('adjacent signals', 'future-island-deep-trend-finder-addon'); ?></span>
            <?php endif; ?>
            <?php if (!empty($report['credit_breakdown']) && is_array($report['credit_breakdown'])): ?>
                <span><strong><?php echo esc_html((string) ($report['credit_breakdown']['local_planning'] ?? $report['credit_breakdown']['planning'] ?? 0)); ?></strong><?php echo esc_html__('Local planning credit', 'future-island-deep-trend-finder-addon'); ?></span>
                <span><strong><?php echo esc_html((string) ($report['credit_breakdown']['ai_planner'] ?? 0)); ?></strong><?php echo esc_html__('AI planner credit', 'future-island-deep-trend-finder-addon'); ?></span>
            <?php endif; ?>
        </div>

        <!-- Confidence row: badge + progress bar -->
        <div class="fidtf-report-head-meta">
            <span class="fidtf-confidence-score <?php echo esc_attr($confidence_class); ?>">
                <?php echo esc_html__('Confidence:', 'future-island-deep-trend-finder-addon'); ?>
                <?php echo esc_html($confidence_raw); ?>
            </span>
            <?php if (!empty($src_conf_level)): ?>
                <span class="fidtf-confidence-score <?php echo esc_attr($src_conf_class); ?>">
                    <?php echo esc_html__('Source confidence:', 'future-island-deep-trend-finder-addon'); ?>
                    <?php echo esc_html($src_conf_level); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="fidtf-progress-wrap" aria-label="<?php echo esc_attr__('Overall confidence', 'future-island-deep-trend-finder-addon'); ?>">
            <div class="fidtf-progress-meta">
                <span><?php echo esc_html__('Signal confidence', 'future-island-deep-trend-finder-addon'); ?></span>
                <span><?php echo esc_html($confidence_pct); ?>%</span>
            </div>
            <div class="fidtf-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr($confidence_pct); ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="fidtf-progress-bar-fill <?php echo esc_attr($confidence_class); ?>" style="width:<?php echo esc_attr($confidence_pct); ?>%"></div>
            </div>
        </div>
    </div>

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

    <?php if (!empty($marketing_decision)): ?>
        <div class="fidtf-section fidtf-report-card fidtf-decision-summary-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Decision layer', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Marketing decision summary', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-pattern-grid">
                <?php
                $decision_labels = [
                    'research_mode'                     => __('Research mode', 'future-island-deep-trend-finder-addon'),
                    'best_current_opportunity'          => __('Best current opportunity', 'future-island-deep-trend-finder-addon'),
                    'strongest_audience_or_occasion'    => __('Strongest audience / occasion', 'future-island-deep-trend-finder-addon'),
                    'recommended_channel_to_test'       => __('Recommended channel to test', 'future-island-deep-trend-finder-addon'),
                    'recommended_creative_route'        => __('Recommended creative route', 'future-island-deep-trend-finder-addon'),
                    'main_risk'                         => __('Main risk', 'future-island-deep-trend-finder-addon'),
                    'proof_still_needed'                => __('Proof still needed', 'future-island-deep-trend-finder-addon'),
                    'decision_status'                   => __('Decision status', 'future-island-deep-trend-finder-addon'),
                ];
                foreach ($decision_labels as $key => $label):
                    if (!isset($marketing_decision[$key]) || $marketing_decision[$key] === '') { continue; }
                ?>
                    <article class="fidtf-report-card"><strong><?php echo esc_html($label); ?></strong><p><?php echo esc_html((string) $marketing_decision[$key]); ?></p></article>
                <?php endforeach; ?>
            </div>
            <ul class="fidtf-inline-list">
                <li><?php echo esc_html__('Decision-ready rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($marketing_decision['decision_ready_rows'] ?? 0)); ?></li>
                <li><?php echo esc_html__('Creative-only rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($marketing_decision['creative_only_rows'] ?? 0)); ?></li>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($confidence_breakdown)): ?>
        <div class="fidtf-section fidtf-report-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Signal quality', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Confidence breakdown', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-mini-bars">
                <?php foreach ($confidence_breakdown as $confidence_key => $confidence_value): ?>
                    <span><b><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $confidence_key))); ?></b><em><?php echo esc_html((string) $confidence_value); ?></em></span>
                <?php endforeach; ?>
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

    <div class="fidtf-section fidtf-report-grid">
        <section class="fidtf-report-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Collection', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Source execution', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-status-grid">
                <?php foreach ($source_summary as $source => $summary):
                    $src_status = (string) ($summary['status'] ?? 'unknown');
                    $src_class = fidtf_source_status_class($src_status);
                ?>
                    <div class="fidtf-status-card <?php echo esc_attr($src_class); ?>">
                        <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $source))); ?></strong>
                        <span><?php echo esc_html($src_status); ?></span>
                        <small><?php echo esc_html__('Provider rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($summary['provider_dataset_rows'] ?? $summary['raw_count'] ?? 0)); ?></small>
                        <small><?php echo esc_html__('Normalized:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($summary['normalized_count'] ?? 0)); ?></small>
                        <small><?php echo esc_html__('Relevant:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($summary['relevant_count'] ?? 0)); ?></small>
                        <?php if (!empty($summary['error_code'])): ?><small><?php echo esc_html((string) $summary['error_code']); ?></small><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if (!empty($report['evidence_quality_summary'])): ?>
            <section class="fidtf-report-card">
                <span class="fidtf-section-label"><?php echo esc_html__('Quality gate', 'future-island-deep-trend-finder-addon'); ?></span>
                <h4><?php echo esc_html__('Evidence quality', 'future-island-deep-trend-finder-addon'); ?></h4>
                <p><?php echo esc_html((string) ($report['evidence_quality_summary']['summary'] ?? '')); ?></p>
                <ul class="fidtf-inline-list">
                    <li><?php echo esc_html__('Live:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html(implode(', ', $live_sources) ?: 'none'); ?></li>
                    <li><?php echo esc_html__('Credit mode:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($report['credit_mode'] ?? 'planning_only')); ?></li>
                    <?php if (!empty($report['evidence_quality_summary']['tier_counts'])): ?>
                        <?php foreach ((array) $report['evidence_quality_summary']['tier_counts'] as $tier_label => $tier_count): ?>
                            <li><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $tier_label))); ?>: <?php echo esc_html((string) $tier_count); ?></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>

    <?php if (!empty($source_role_matrix)): ?>
        <div class="fidtf-section fidtf-report-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Source roles', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Source role matrix', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-status-grid">
                <?php foreach ($source_role_matrix as $row):
                    $rm_class = fidtf_source_status_class((string) ($row['role'] ?? ''));
                ?>
                    <div class="fidtf-status-card <?php echo esc_attr($rm_class); ?>">
                        <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) ($row['source'] ?? 'source')))); ?></strong>
                        <span><?php echo esc_html((string) ($row['role'] ?? 'supporting evidence')); ?></span>
                        <small><strong><?php echo esc_html__('Can support:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($row['can_support'] ?? '')); ?></small>
                        <small><strong><?php echo esc_html__('Cannot support:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($row['cannot_support'] ?? '')); ?></small>
                        <small><?php echo esc_html__('Relevant rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($row['relevant_rows'] ?? 0)); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['evidence_fit_diagnosis'])): ?>
        <div class="fidtf-section fidtf-report-grid">
            <section class="fidtf-report-card fidtf-fit-card">
                <span class="fidtf-section-label"><?php echo esc_html__('Fit analysis', 'future-island-deep-trend-finder-addon'); ?></span>
                <h4><?php echo esc_html__('Evidence fit diagnosis', 'future-island-deep-trend-finder-addon'); ?></h4>
                <?php foreach ((array) $report['evidence_fit_diagnosis'] as $label => $value): ?>
                    <p><strong><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $label))); ?>:</strong> <?php echo esc_html((string) $value); ?></p>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($report['briefing_recommendations'])): ?>
                <section class="fidtf-report-card fidtf-fit-card">
                    <span class="fidtf-section-label"><?php echo esc_html__('Recommendations', 'future-island-deep-trend-finder-addon'); ?></span>
                    <h4><?php echo esc_html__('Briefing recommendations', 'future-island-deep-trend-finder-addon'); ?></h4>
                    <ol class="fidtf-validation-list">
                        <?php foreach ((array) $report['briefing_recommendations'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['cross_platform_intelligence'])): ?>
        <?php $xpi = (array) $report['cross_platform_intelligence']; ?>
        <div class="fidtf-section fidtf-report-card fidtf-cross-platform-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Cross-platform', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Cross-platform intelligence', 'future-island-deep-trend-finder-addon'); ?></h4>
            <p><strong><?php echo esc_html__('Analysis level:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($xpi['analysis_level'] ?? 'cross-platform readout')); ?></p>
            <p><strong><?php echo esc_html__('Convergence:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($xpi['convergence_level'] ?? 'low')); ?></p>
            <?php if (!empty($xpi['signal_balance']) && is_array($xpi['signal_balance'])): ?>
                <p><strong><?php echo esc_html__('Signal balance:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) ($xpi['signal_balance']['interpretation'] ?? '')); ?></p>
            <?php endif; ?>
            <?php if (!empty($xpi['statistical_scorecard']) && is_array($xpi['statistical_scorecard'])): ?>
                <?php $scorecard = (array) $xpi['statistical_scorecard']; ?>
                <div class="fidtf-warning-box">
                    <div class="fidtf-preflight-inner">
                        <span class="fidtf-preflight-icon" aria-hidden="true">📊</span>
                        <div class="fidtf-preflight-body">
                            <strong><?php echo esc_html__('Data scientist scorecard:', 'future-island-deep-trend-finder-addon'); ?></strong>
                            <p style="margin:4px 0 0; font-size:13px;">
                                <?php echo esc_html__('Confidence', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($scorecard['analysis_confidence'] ?? 'low')); ?> ·
                                <?php echo esc_html__('Dominant source', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($scorecard['dominant_source'] ?? 'none')); ?> (<?php echo esc_html((string) ($scorecard['dominant_source_share'] ?? '0%')); ?>) ·
                                <?php echo esc_html__('Direct evidence', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($scorecard['direct_evidence_share'] ?? '0%')); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php if (!empty($scorecard['coverage_by_evidence_family'])): ?>
                    <ul class="fidtf-inline-list">
                        <?php foreach ((array) $scorecard['coverage_by_evidence_family'] as $family => $count): ?>
                            <li><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $family))); ?>: <?php echo esc_html((string) $count); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($xpi['source_roles'])): ?>
                <div class="fidtf-status-grid">
                    <?php foreach ((array) $xpi['source_roles'] as $role):
                        $xpi_class = fidtf_source_status_class((string) ($role['role'] ?? ''));
                    ?>
                        <div class="fidtf-status-card <?php echo esc_attr($xpi_class); ?>">
                            <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) ($role['source'] ?? 'source')))); ?></strong>
                            <span><?php echo esc_html((string) ($role['role'] ?? 'supporting evidence')); ?></span>
                            <small><?php echo esc_html__('Provider rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($role['provider_rows'] ?? 0)); ?></small>
                            <small><?php echo esc_html__('Relevant rows:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) ($role['relevant_rows'] ?? 0)); ?></small>
                            <?php if (!empty($role['dominant_terms'])): ?><small><?php echo esc_html__('Dominant terms:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) $role['dominant_terms']); ?></small><?php endif; ?>
                            <?php if (!empty($role['quality_note'])): ?><small><?php echo esc_html((string) $role['quality_note']); ?></small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($xpi['claim_readiness']) && is_array($xpi['claim_readiness'])): ?>
                <?php $readiness = (array) $xpi['claim_readiness']; ?>
                <div class="fidtf-warning-box fidtf-claim-readiness-box">
                    <div class="fidtf-preflight-inner">
                        <span class="fidtf-preflight-icon" aria-hidden="true">⚠</span>
                        <div class="fidtf-preflight-body">
                            <strong><?php echo esc_html__('Claim-readiness gate:', 'future-island-deep-trend-finder-addon'); ?></strong>
                            <p style="margin:4px 0 0; font-size:13px;">
                                <?php echo esc_html__('Score', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($readiness['score'] ?? 0)); ?>/100 ·
                                <?php echo esc_html__('Level', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($readiness['level'] ?? 'do_not_claim')); ?> ·
                                <?php echo esc_html__('Direct ratio', 'future-island-deep-trend-finder-addon'); ?>: <?php echo esc_html((string) ($readiness['direct_ratio'] ?? '0%')); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php if (!empty($readiness['claims_blocked'])): ?>
                    <h5><?php echo esc_html__('Claims blocked until validated', 'future-island-deep-trend-finder-addon'); ?></h5>
                    <ul><?php foreach ((array) $readiness['claims_blocked'] as $blocked_claim): ?><li><?php echo esc_html((string) $blocked_claim); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($xpi['shared_language'])): ?>
                <h5><?php echo esc_html__('Shared language across sources', 'future-island-deep-trend-finder-addon'); ?></h5>
                <ul class="fidtf-inline-list">
                    <?php foreach ((array) $xpi['shared_language'] as $row): ?>
                        <li><?php echo esc_html((string) ($row['term'] ?? '')); ?> — <?php echo esc_html(implode(', ', (array) ($row['sources'] ?? []))); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($xpi['hypotheses_to_test'])): ?>
                <h5><?php echo esc_html__('Hypotheses to test', 'future-island-deep-trend-finder-addon'); ?></h5>
                <ol class="fidtf-validation-list">
                    <?php foreach ((array) $xpi['hypotheses_to_test'] as $hypothesis): ?><li><?php echo esc_html((string) $hypothesis); ?></li><?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if (!empty($xpi['platform_biases'])): ?>
                <h5><?php echo esc_html__('Platform bias warnings', 'future-island-deep-trend-finder-addon'); ?></h5>
                <ul><?php foreach ((array) $xpi['platform_biases'] as $bias): ?><li><?php echo esc_html((string) $bias); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if (!empty($xpi['decision_rule'])): ?><p><strong><?php echo esc_html__('Decision rule:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $xpi['decision_rule']); ?></p><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['signal_clusters'])): ?>
        <div class="fidtf-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Pattern analysis', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Signal clusters', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-pattern-grid">
                <?php foreach ((array) $report['signal_clusters'] as $cluster): ?>
                    <article class="fidtf-report-card fidtf-cluster-card">
                        <div class="fidtf-evidence-group-head">
                            <strong><?php echo esc_html((string) ($cluster['label'] ?? 'Cluster')); ?></strong>
                            <span class="fidtf-count-badge"><?php echo esc_html((string) ($cluster['count'] ?? 0)); ?></span>
                        </div>
                        <?php if (!empty($cluster['examples'])): ?>
                            <ul><?php foreach ((array) $cluster['examples'] as $example): ?><li><?php echo esc_html((string) $example); ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['evidence_intelligence'])): ?>
        <div class="fidtf-section fidtf-report-grid">
            <?php if (!empty($intel['signal_mix'])): ?>
                <section class="fidtf-report-card">
                    <span class="fidtf-section-label"><?php echo esc_html__('Signal breakdown', 'future-island-deep-trend-finder-addon'); ?></span>
                    <h4><?php echo esc_html__('Evidence map', 'future-island-deep-trend-finder-addon'); ?></h4>
                    <div class="fidtf-mini-bars">
                        <?php foreach ((array) $intel['signal_mix'] as $row): ?>
                            <span><b><?php echo esc_html((string) ($row['label'] ?? 'signal')); ?></b><em><?php echo esc_html((string) ($row['count'] ?? 0)); ?></em></span>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if (!empty($intel['hook_families'])): ?>
                <section class="fidtf-report-card">
                    <span class="fidtf-section-label"><?php echo esc_html__('Content hooks', 'future-island-deep-trend-finder-addon'); ?></span>
                    <h4><?php echo esc_html__('Hook mechanics detected', 'future-island-deep-trend-finder-addon'); ?></h4>
                    <div class="fidtf-mini-bars">
                        <?php foreach ((array) $intel['hook_families'] as $row): ?>
                            <span><b><?php echo esc_html((string) ($row['label'] ?? 'hook')); ?></b><em><?php echo esc_html((string) ($row['count'] ?? 0)); ?></em></span>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['key_patterns'])): ?>
        <div class="fidtf-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Trend patterns', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Key patterns', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-pattern-grid">
                <?php foreach ((array) $report['key_patterns'] as $pattern): ?>
                    <article class="fidtf-report-card">
                        <strong><?php echo esc_html((string) ($pattern['label'] ?? 'Pattern')); ?></strong>
                        <p><?php echo esc_html((string) ($pattern['value'] ?? '')); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['strategic_readout'])): ?>
        <div class="fidtf-section fidtf-report-card fidtf-strategic-readout">
            <span class="fidtf-section-label"><?php echo esc_html__('Strategic layer', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Strategic readout', 'future-island-deep-trend-finder-addon'); ?></h4>
            <?php foreach ((array) $report['strategic_readout'] as $label => $value): ?>
                <p><strong><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $label))); ?>:</strong> <?php echo esc_html((string) $value); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['insights'])): ?>
        <div class="fidtf-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Professional synthesis', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Key insights', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-insight-stack">
                <?php foreach ((array) $report['insights'] as $insight): ?>
                    <article class="fidtf-report-card fidtf-insight-card">
                        <h5><?php echo esc_html((string) ($insight['title'] ?? 'Insight')); ?></h5>
                        <?php if (!empty($insight['evidence'])): ?><p><strong><?php echo esc_html__('Evidence:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $insight['evidence']); ?></p><?php endif; ?>
                        <?php if (!empty($insight['implication'])): ?><p><strong><?php echo esc_html__('Implication:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $insight['implication']); ?></p><?php endif; ?>
                        <?php if (!empty($insight['confidence'])): ?>
                            <span class="fidtf-confidence-score <?php echo in_array(strtolower((string)$insight['confidence']), ['high','very_high'], true) ? 'is-high' : (in_array(strtolower((string)$insight['confidence']), ['medium','mid','moderate'], true) ? 'is-mid' : 'is-low'); ?>">
                                <?php echo esc_html__('Confidence:', 'future-island-deep-trend-finder-addon'); ?> <?php echo esc_html((string) $insight['confidence']); ?>
                            </span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['creative_territories'])): ?>
        <div class="fidtf-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Creative strategy', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Creative territories', 'future-island-deep-trend-finder-addon'); ?></h4>
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
        </div>
    <?php endif; ?>

    <?php if (!empty($report['content_angle_briefs'])): ?>
        <div class="fidtf-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Content briefs', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Content angle briefs', 'future-island-deep-trend-finder-addon'); ?></h4>
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
        </div>
    <?php endif; ?>

    <?php if (!empty($report['recommended_validation_plan'])): ?>
        <div class="fidtf-section fidtf-report-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Next steps', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Recommended validation plan', 'future-island-deep-trend-finder-addon'); ?></h4>
            <ol class="fidtf-validation-list">
                <?php foreach ((array) $report['recommended_validation_plan'] as $step): ?>
                    <li><?php echo esc_html((string) $step); ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>

    <div class="fidtf-section fidtf-report-grid">
        <?php if (!empty($report['can_say'])): ?>
            <section class="fidtf-report-card fidtf-muted-list">
                <span class="fidtf-section-label"><?php echo esc_html__('Scope boundary', 'future-island-deep-trend-finder-addon'); ?></span>
                <h4><?php echo esc_html__('What this report can say', 'future-island-deep-trend-finder-addon'); ?></h4>
                <ul><?php foreach ((array) $report['can_say'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
            </section>
        <?php endif; ?>
        <?php if (!empty($report['cannot_say'])): ?>
            <section class="fidtf-report-card fidtf-muted-list">
                <span class="fidtf-section-label"><?php echo esc_html__('Limitations', 'future-island-deep-trend-finder-addon'); ?></span>
                <h4><?php echo esc_html__('What this report cannot say', 'future-island-deep-trend-finder-addon'); ?></h4>
                <ul><?php foreach ((array) $report['cannot_say'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
            </section>
        <?php endif; ?>
    </div>

    <?php if (!empty($report['opportunities'])): ?>
        <div class="fidtf-section">
            <span class="fidtf-section-label"><?php echo esc_html__('Actions', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Recommended next actions', 'future-island-deep-trend-finder-addon'); ?></h4>
            <div class="fidtf-pattern-grid">
                <?php foreach ((array) $report['opportunities'] as $opportunity): ?>
                    <article class="fidtf-report-card">
                        <h5><?php echo esc_html((string) ($opportunity['title'] ?? 'Next action')); ?></h5>
                        <?php if (!empty($opportunity['why'])): ?><p><?php echo esc_html((string) $opportunity['why']); ?></p><?php endif; ?>
                        <?php if (!empty($opportunity['next_action'])): ?><p><strong><?php echo esc_html__('Action:', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html((string) $opportunity['next_action']); ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['risk_notes'])): ?>
        <div class="fidtf-section fidtf-report-card fidtf-risk-card">
            <span class="fidtf-section-label"><?php echo esc_html__('Risk layer', 'future-island-deep-trend-finder-addon'); ?></span>
            <h4><?php echo esc_html__('Strategic risk notes', 'future-island-deep-trend-finder-addon'); ?></h4>
            <ul><?php foreach ((array) $report['risk_notes'] as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="fidtf-section fidtf-muted-list fidtf-report-card">
        <span class="fidtf-section-label"><?php echo esc_html__('Disclosure', 'future-island-deep-trend-finder-addon'); ?></span>
        <h4><?php echo esc_html__('Not claimed by this report', 'future-island-deep-trend-finder-addon'); ?></h4>
        <ul><?php foreach ((array) ($report['not_claimed'] ?? []) as $item): ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul>
    </div>
</div>
