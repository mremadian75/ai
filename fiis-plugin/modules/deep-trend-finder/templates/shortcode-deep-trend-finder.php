<?php
if (!defined('ABSPATH')) { exit; }
$enabled_sources = class_exists('FIDTF_Settings') && method_exists('FIDTF_Settings', 'dedupe_source_list')
    ? FIDTF_Settings::dedupe_source_list((array) ($settings['enabled_sources'] ?? []), ['tiktok'])
    : array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($settings['enabled_sources'] ?? [])))));
if (empty($enabled_sources)) { $enabled_sources = ['tiktok']; }
$preflight = FIDTF_Settings::live_preflight_status($enabled_sources);
$live_sources = array_values(array_unique(array_map('sanitize_key', (array) ($preflight['live_sources'] ?? []))));
$planned_only_sources = array_values(array_unique(array_map('sanitize_key', (array) ($preflight['planned_only_sources'] ?? []))));
$has_live_sources = !empty($live_sources);
$tiktok_live = !empty($preflight['tiktok_live_ready']);
$multi_live = count($live_sources) > 1 || (!empty($live_sources) && !in_array('tiktok', $live_sources, true));
$core_connected = FIDTF_Dependency::mother_active();
$credit_mode = !empty($settings['enable_credit_reservation']) ? __('Credit reservation enabled', 'future-island-deep-trend-finder-addon') : __('Planning estimate only', 'future-island-deep-trend-finder-addon');
$uid = 'fidtf-' . wp_generate_uuid4();
$source_labels = [
    'tiktok'        => 'TikTok',
    'instagram'     => 'Instagram',
    'reddit'        => 'Reddit',
    'google_trends' => 'Google Trends',
    'google_news'   => 'Google News',
    'ai_research'   => 'AI Research',
];
$reason_labels = [
    'global_live_disabled'           => __('Global live dispatch is off.', 'future-island-deep-trend-finder-addon'),
    'tiktok_live_bridge_disabled'    => __('Enable TikTok bridge in add-on settings to start live TikTok collection.', 'future-island-deep-trend-finder-addon'),
    'source_live_bridge_disabled'    => __('Enable the multi-source live bridge and map a tested actor for this source.', 'future-island-deep-trend-finder-addon'),
    'core_apify_unavailable'         => __('Core Apify client is not ready.', 'future-island-deep-trend-finder-addon'),
    'direct_token_missing'           => __('Direct Apify token is missing.', 'future-island-deep-trend-finder-addon'),
    'external_filter_missing'        => __('External TikTok provider filter is missing.', 'future-island-deep-trend-finder-addon'),
];
$first_reason = sanitize_key((string) (($preflight['blocking_reasons'][0] ?? '') ?: ''));
$live_labels = array_map(static function($source) use ($source_labels) {
    return $source_labels[$source] ?? ucfirst(str_replace('_', ' ', (string) $source));
}, $live_sources);
$preflight_message = $has_live_sources
    ? sprintf(__('Live collection is enabled for: %s. Planned-only sources will stay transparent and will not fake evidence.', 'future-island-deep-trend-finder-addon'), implode(', ', $live_labels))
    : ($reason_labels[$first_reason] ?? __('No live source will run with the current settings.', 'future-island-deep-trend-finder-addon'));
?>
<section class="fidtf-shell fidtf-has-control-sidebar" data-fidtf-root data-fidtf-uid="<?php echo esc_attr($uid); ?>" aria-labelledby="<?php echo esc_attr($uid); ?>-title">
    <div class="fidtf-hero">
        <div>
            <p class="fidtf-kicker"><?php echo esc_html__('Future Island signal room', 'future-island-deep-trend-finder-addon'); ?></p>
            <h2 id="<?php echo esc_attr($uid); ?>-title"><?php echo esc_html__('Deep Trend Finder', 'future-island-deep-trend-finder-addon'); ?></h2>
            <p><?php echo esc_html__('Turn a research brief into live source collection, scored evidence, and a professional synthesis that separates signal, implication, limits, and next actions.', 'future-island-deep-trend-finder-addon'); ?></p>
        </div>
        <div class="fidtf-chip-row" aria-label="System status">
            <span class="fidtf-chip <?php echo $core_connected ? 'is-live' : 'is-warning'; ?>"><?php echo esc_html($core_connected ? __('Core connected', 'future-island-deep-trend-finder-addon') : __('Core missing', 'future-island-deep-trend-finder-addon')); ?></span>
            <span class="fidtf-chip <?php echo FIDTF_Settings::live_dispatch_enabled() ? 'is-warning' : ''; ?>"><?php echo esc_html(FIDTF_Settings::live_dispatch_enabled() ? __('Global live on', 'future-island-deep-trend-finder-addon') : __('Planning mode', 'future-island-deep-trend-finder-addon')); ?></span>
            <span class="fidtf-chip <?php echo $has_live_sources ? 'is-live' : 'is-warning'; ?>"><?php echo esc_html($has_live_sources ? sprintf(__('%d live source(s) ready', 'future-island-deep-trend-finder-addon'), count($live_sources)) : __('No live source ready', 'future-island-deep-trend-finder-addon')); ?></span>
            <span class="fidtf-chip"><?php echo esc_html($credit_mode); ?></span>
        </div>
    </div>

    <div class="fidtf-workspace-grid">
        <form class="fidtf-form fidtf-card-brief" id="<?php echo esc_attr($uid); ?>-setup" data-fidtf-form aria-labelledby="<?php echo esc_attr($uid); ?>-brief-title">
            <div class="fidtf-section-head">
                <div>
                    <p class="fidtf-kicker"><?php echo esc_html__('Research brief', 'future-island-deep-trend-finder-addon'); ?></p>
                    <h3 id="<?php echo esc_attr($uid); ?>-brief-title"><?php echo esc_html__('Define the question before collecting signals', 'future-island-deep-trend-finder-addon'); ?></h3>
                </div>
            </div>

            <label>
                <span><?php echo esc_html__('Research brief', 'future-island-deep-trend-finder-addon'); ?></span>
                <textarea name="user_brief" rows="4" required placeholder="<?php echo esc_attr__('Example: Trends around beer and beverage culture in Spain for young urban audiences', 'future-island-deep-trend-finder-addon'); ?>"></textarea>
            </label>

            <hr class="fidtf-divider">
            <span class="fidtf-form-group-label"><?php echo esc_html__('Research parameters', 'future-island-deep-trend-finder-addon'); ?></span>

            <div class="fidtf-grid">
                <label><span><?php echo esc_html__('Objective', 'future-island-deep-trend-finder-addon'); ?></span><input name="objective" type="text" placeholder="<?php echo esc_attr__('Find content angles, risks, and validation signals', 'future-island-deep-trend-finder-addon'); ?>"></label>
                <label><span><?php echo esc_html__('Research mode', 'future-island-deep-trend-finder-addon'); ?></span><select name="research_mode"><option value="creative_validation" selected><?php echo esc_html__('Creative validation', 'future-island-deep-trend-finder-addon'); ?></option><option value="category_trend_discovery"><?php echo esc_html__('Category trend discovery', 'future-island-deep-trend-finder-addon'); ?></option><option value="brand_opportunity"><?php echo esc_html__('Brand opportunity', 'future-island-deep-trend-finder-addon'); ?></option><option value="competitor_benchmark"><?php echo esc_html__('Competitor benchmark', 'future-island-deep-trend-finder-addon'); ?></option><option value="creative_mechanics_mining"><?php echo esc_html__('Creative mechanics mining', 'future-island-deep-trend-finder-addon'); ?></option><option value="campaign_validation"><?php echo esc_html__('Campaign validation', 'future-island-deep-trend-finder-addon'); ?></option></select></label>
                <label><span><?php echo esc_html__('Market', 'future-island-deep-trend-finder-addon'); ?></span><input name="market" type="text" value="Spain"></label>
                <label><span><?php echo esc_html__('Language', 'future-island-deep-trend-finder-addon'); ?></span><input name="language" type="text" value="es"></label>
                <label><span><?php echo esc_html__('Date range', 'future-island-deep-trend-finder-addon'); ?></span><select name="date_range"><option value="last_7_days"><?php echo esc_html__('Last 7 days', 'future-island-deep-trend-finder-addon'); ?></option><option value="last_14_days"><?php echo esc_html__('Last 14 days', 'future-island-deep-trend-finder-addon'); ?></option><option value="last_30_days" selected><?php echo esc_html__('Last 30 days', 'future-island-deep-trend-finder-addon'); ?></option><option value="last_90_days"><?php echo esc_html__('Last 90 days', 'future-island-deep-trend-finder-addon'); ?></option><option value="custom"><?php echo esc_html__('Custom later', 'future-island-deep-trend-finder-addon'); ?></option></select></label>
                <label><span><?php echo esc_html__('Keywords', 'future-island-deep-trend-finder-addon'); ?></span><input name="keywords" type="text" placeholder="<?php echo esc_attr__('Comma-separated', 'future-island-deep-trend-finder-addon'); ?>"></label>
            </div>

            <hr class="fidtf-divider">
            <span class="fidtf-form-group-label"><?php echo esc_html__('Context (optional)', 'future-island-deep-trend-finder-addon'); ?></span>

            <div class="fidtf-grid">
                <label><span><?php echo esc_html__('Brand', 'future-island-deep-trend-finder-addon'); ?></span><input name="brand" type="text" placeholder="<?php echo esc_attr__('Example: Future Island', 'future-island-deep-trend-finder-addon'); ?>"></label>
                <label><span><?php echo esc_html__('Company', 'future-island-deep-trend-finder-addon'); ?></span><input name="company" type="text" placeholder="<?php echo esc_attr__('Optional legal/company name', 'future-island-deep-trend-finder-addon'); ?>"></label>
                <label><span><?php echo esc_html__('Competitors', 'future-island-deep-trend-finder-addon'); ?></span><input name="competitors" type="text" placeholder="<?php echo esc_attr__('Comma-separated', 'future-island-deep-trend-finder-addon'); ?>"></label>
                <label><span><?php echo esc_html__('Audience', 'future-island-deep-trend-finder-addon'); ?></span><input name="audience" type="text" placeholder="<?php echo esc_attr__('Example: Spanish Gen Z beer consumers', 'future-island-deep-trend-finder-addon'); ?>"></label>
                <label><span><?php echo esc_html__('Exclude terms', 'future-island-deep-trend-finder-addon'); ?></span><input name="exclude_terms" type="text" placeholder="<?php echo esc_attr__('Comma-separated terms to filter out', 'future-island-deep-trend-finder-addon'); ?>"></label>
            </div>

            <hr class="fidtf-divider">

            <fieldset class="fidtf-sources">
                <legend><?php echo esc_html__('Channels', 'future-island-deep-trend-finder-addon'); ?></legend>
                <?php foreach ($enabled_sources as $source):
                    $is_live = in_array($source, $live_sources, true);
                    $checked = $is_live || $source === 'tiktok';
                    $badge = $is_live ? __('Ready for live', 'future-island-deep-trend-finder-addon') : __('Planned only', 'future-island-deep-trend-finder-addon');
                    $helper = $is_live
                        ? __('Can start live provider collection.', 'future-island-deep-trend-finder-addon')
                        : __('No provider call will be made unless its bridge and actor mapping are enabled.', 'future-island-deep-trend-finder-addon');
                ?>
                    <label><input type="checkbox" name="channels[]" value="<?php echo esc_attr($source); ?>" <?php checked($checked); ?>><span class="fidtf-channel-copy"><strong><?php echo esc_html($source_labels[$source] ?? ucfirst(str_replace('_', ' ', $source))); ?></strong><em class="fidtf-chip <?php echo $is_live ? 'is-live' : ''; ?>"><?php echo esc_html($badge); ?></em><small><?php echo esc_html($helper); ?></small></span></label>
                <?php endforeach; ?>
            </fieldset>

            <div data-fidtf-preflight-panel>
                <div class="fidtf-notice <?php echo $has_live_sources ? 'fidtf-success' : 'fidtf-warning'; ?>" data-fidtf-preflight-warning>
                    <div class="fidtf-preflight-inner">
                        <span class="fidtf-preflight-icon" aria-hidden="true"><?php echo $has_live_sources ? '✓' : '!'; ?></span>
                        <div class="fidtf-preflight-body">
                            <strong><?php echo esc_html($has_live_sources ? __('Live collection enabled.', 'future-island-deep-trend-finder-addon') : __('No live source will run with the current settings.', 'future-island-deep-trend-finder-addon')); ?></strong>
                            <p style="margin:4px 0 0; font-size:13px;"><?php echo esc_html($preflight_message); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fidtf-actions">
                <!-- Legacy CTA label retained for QA: Start TikTok trend run. No Apify request until TikTok bridge is ready. No provider call will be made in this release. -->
                <button type="submit" class="fidtf-primary"><?php echo esc_html($has_live_sources ? __('Start deep trend run', 'future-island-deep-trend-finder-addon') : __('Create planned trend brief', 'future-island-deep-trend-finder-addon')); ?></button>
                <span class="fidtf-muted" style="font-size:13px;"><?php echo esc_html__('No fake evidence. Planned-only sources stay clearly labelled.', 'future-island-deep-trend-finder-addon'); ?></span>
            </div>
        </form>

        <aside class="fidtf-card fidtf-control-sidebar" data-fidtf-sidebar aria-label="<?php echo esc_attr__('Run sidebar', 'future-island-deep-trend-finder-addon'); ?>">
            <div class="fidtf-section-head">
                <div>
                    <p class="fidtf-kicker"><?php echo esc_html__('Control panel', 'future-island-deep-trend-finder-addon'); ?></p>
                    <h3><?php echo esc_html__('Run workspace', 'future-island-deep-trend-finder-addon'); ?></h3>
                </div>
            </div>

            <span class="fidtf-section-label"><?php echo esc_html__('Navigation', 'future-island-deep-trend-finder-addon'); ?></span>
            <nav class="fidtf-sidebar-nav" aria-label="<?php echo esc_attr__('Deep Trend Finder sections', 'future-island-deep-trend-finder-addon'); ?>">
                <a href="#<?php echo esc_attr($uid); ?>-setup">
                    <svg class="fidtf-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span class="fidtf-nav-label"><?php echo esc_html__('Overview', 'future-island-deep-trend-finder-addon'); ?></span>
                </a>
                <a href="#<?php echo esc_attr($uid); ?>-sources">
                    <svg class="fidtf-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                    <span class="fidtf-nav-label"><?php echo esc_html__('Sources', 'future-island-deep-trend-finder-addon'); ?></span>
                </a>
                <a href="#<?php echo esc_attr($uid); ?>-evidence">
                    <svg class="fidtf-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <span class="fidtf-nav-label"><?php echo esc_html__('Evidence', 'future-island-deep-trend-finder-addon'); ?></span>
                </a>
                <a href="#<?php echo esc_attr($uid); ?>-report-shell">
                    <svg class="fidtf-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span class="fidtf-nav-label"><?php echo esc_html__('Report', 'future-island-deep-trend-finder-addon'); ?></span>
                </a>
                <a href="#<?php echo esc_attr($uid); ?>-full-output">
                    <svg class="fidtf-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 17 9 11 13 15 21 7"/><polyline points="14 7 21 7 21 14"/></svg>
                    <span class="fidtf-nav-label"><?php echo esc_html__('Full output', 'future-island-deep-trend-finder-addon'); ?></span>
                </a>
            </nav>

            <div class="fidtf-sidebar-block fidtf-sidebar-overview" data-fidtf-sidebar-summary>
                <strong><?php echo esc_html__('No run yet', 'future-island-deep-trend-finder-addon'); ?></strong>
                <p><?php echo esc_html__('Create a run, then use this sidebar to jump between sources, evidence, report, and final output.', 'future-island-deep-trend-finder-addon'); ?></p>
            </div>

            <div class="fidtf-sidebar-block fidtf-sidebar-actions" data-fidtf-sidebar-actions>
                <span class="fidtf-chip"><?php echo esc_html__('Actions appear after run creation', 'future-island-deep-trend-finder-addon'); ?></span>
            </div>

            <div class="fidtf-sidebar-block fidtf-source-plan">
                <span class="fidtf-section-label"><?php echo esc_html__('Selected sources', 'future-island-deep-trend-finder-addon'); ?></span>
                <div data-fidtf-source-plan-panel>
                    <div class="fidtf-source-readiness">
                        <?php foreach ($enabled_sources as $source):
                            $is_live = in_array($source, $live_sources, true);
                            $card_class = $is_live ? 'is-live' : 'is-warn';
                        ?>
                            <div class="fidtf-source-card <?php echo esc_attr($card_class); ?>">
                                <div class="fidtf-source-card-head">
                                    <strong><?php echo esc_html($source_labels[$source] ?? ucfirst(str_replace('_', ' ', $source))); ?></strong>
                                    <span class="fidtf-status <?php echo $is_live ? 'is-live' : 'is-warning'; ?>"><?php echo esc_html($is_live ? __('Live ready', 'future-island-deep-trend-finder-addon') : __('Planned only', 'future-island-deep-trend-finder-addon')); ?></span>
                                </div>
                                <p><?php echo esc_html($is_live ? __('The run can dispatch this source through its configured provider bridge.', 'future-island-deep-trend-finder-addon') : __('This source will remain a plan row until its provider bridge is enabled and mapped.', 'future-island-deep-trend-finder-addon')); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </aside>

        <section class="fidtf-card fidtf-progressive-rows" id="<?php echo esc_attr($uid); ?>-sources" aria-labelledby="<?php echo esc_attr($uid); ?>-progress-title">
            <div class="fidtf-section-head">
                <div>
                    <p class="fidtf-kicker"><?php echo esc_html__('Progressive rows', 'future-island-deep-trend-finder-addon'); ?></p>
                    <h3 id="<?php echo esc_attr($uid); ?>-progress-title"><?php echo esc_html__('Source execution state', 'future-island-deep-trend-finder-addon'); ?></h3>
                </div>
            </div>
            <div data-fidtf-progressive-rows>
                <p class="fidtf-empty"><?php echo esc_html__('Rows will appear after a run is created. Live sources update progressively; planned-only sources stay labelled.', 'future-island-deep-trend-finder-addon'); ?></p>
            </div>
        </section>

        <section class="fidtf-card fidtf-evidence-grid" id="<?php echo esc_attr($uid); ?>-evidence" aria-labelledby="<?php echo esc_attr($uid); ?>-evidence-title">
            <div class="fidtf-section-head">
                <div>
                    <p class="fidtf-kicker"><?php echo esc_html__('Evidence grid', 'future-island-deep-trend-finder-addon'); ?></p>
                    <h3 id="<?php echo esc_attr($uid); ?>-evidence-title"><?php echo esc_html__('Relevant signals', 'future-island-deep-trend-finder-addon'); ?></h3>
                </div>
            </div>
            <div data-fidtf-evidence-grid>
                <p class="fidtf-empty"><?php echo esc_html__('No evidence yet. Relevant items will appear only after source data is collected and filtered.', 'future-island-deep-trend-finder-addon'); ?></p>
            </div>
        </section>

        <section class="fidtf-card fidtf-report-shell" id="<?php echo esc_attr($uid); ?>-report-shell" aria-labelledby="<?php echo esc_attr($uid); ?>-report-title">
            <div class="fidtf-section-head">
                <div>
                    <p class="fidtf-kicker"><?php echo esc_html__('Report shell', 'future-island-deep-trend-finder-addon'); ?></p>
                    <h3 id="<?php echo esc_attr($uid); ?>-report-title"><?php echo esc_html__('Evidence-aware interpretation', 'future-island-deep-trend-finder-addon'); ?></h3>
                </div>
            </div>
            <div data-fidtf-report-shell>
                <div class="fidtf-warning-box">
                    <div class="fidtf-preflight-inner">
                        <span class="fidtf-preflight-icon" aria-hidden="true">!</span>
                        <div class="fidtf-preflight-body">
                            <?php echo esc_html__('Charts and recommendations will appear only after real evidence exists. Professional synthesis appears only after real evidence exists. Empty or planned-only runs stay clearly labelled.', 'future-island-deep-trend-finder-addon'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="fidtf-output" id="<?php echo esc_attr($uid); ?>-full-output" data-fidtf-output hidden></div>
    </div>
</section>
