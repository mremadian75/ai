<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Admin {
    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_action('admin_post_fidtf_recreate_page', [__CLASS__, 'handle_recreate_page']);
    }

    public static function handle_recreate_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'future-island-deep-trend-finder-addon'));
        }
        check_admin_referer('fidtf_recreate_page');
        if (class_exists('FIDTF_Plugin')) {
            FIDTF_Plugin::recreate_frontend_page(true);
        }
        wp_safe_redirect(add_query_arg(['page' => 'future-island-deep-trend-finder', 'fidtf_page_recreated' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function menu(): void {
        add_options_page(
            __('Deep Trend Finder', 'future-island-deep-trend-finder-addon'),
            __('Deep Trend Finder', 'future-island-deep-trend-finder-addon'),
            'manage_options',
            'future-island-deep-trend-finder',
            [__CLASS__, 'render']
        );
    }

    public static function settings(): void {
        register_setting('fidtf_settings_group', FIDTF_Settings::OPTION, ['sanitize_callback' => ['FIDTF_Settings', 'sanitize']]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'future-island-deep-trend-finder-addon'));
        }
        $settings = FIDTF_Settings::get();
        $defaults = FIDTF_Settings::defaults();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Deep Trend Finder', 'future-island-deep-trend-finder-addon'); ?></h1>
            <p><?php echo esc_html__('Deep Trend Finder v0.3.53 — multi-source live trend discovery with TikTok, Instagram, Reddit, Google Trends, Google News and AI research channels. Part of the unified Future Island Intelligence Suite.', 'future-island-deep-trend-finder-addon'); ?></p>
            <?php $module_info = class_exists('FIDTF_Module_Info') ? FIDTF_Module_Info::get() : []; ?>
            <div class="notice notice-info inline"><p>
                <strong><?php echo esc_html__('Frontend page', 'future-island-deep-trend-finder-addon'); ?>:</strong>
                <?php if (!empty($module_info['url'])): ?>
                    <a href="<?php echo esc_url((string) $module_info['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View page', 'future-island-deep-trend-finder-addon'); ?></a>
                <?php endif; ?>
                <code>[future_island_deep_trend_finder]</code>
                <?php if (get_option('fidtf_page_shortcode_missing')): ?>
                    <span style="color:#b45309"><?php echo esc_html__('The saved page exists but the shortcode is missing. It was not overwritten automatically.', 'future-island-deep-trend-finder-addon'); ?></span>
                <?php endif; ?>
            </p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fidtf_recreate_page'), 'fidtf_recreate_page')); ?>"><?php echo esc_html__('Recreate/update page', 'future-island-deep-trend-finder-addon'); ?></a>
                <input type="text" class="regular-text" readonly value="[future_island_deep_trend_finder]" aria-label="<?php echo esc_attr__('Shortcode', 'future-island-deep-trend-finder-addon'); ?>">
            </p></div>
            <form method="post" action="options.php">
                <?php settings_fields('fidtf_settings_group'); ?>
                <h2><?php echo esc_html__('Sources', 'future-island-deep-trend-finder-addon'); ?></h2>
                <table class="widefat striped" style="max-width:980px">
                    <thead><tr><th><?php echo esc_html__('Enabled', 'future-island-deep-trend-finder-addon'); ?></th><th><?php echo esc_html__('Source', 'future-island-deep-trend-finder-addon'); ?></th><th><?php echo esc_html__('Default limit', 'future-island-deep-trend-finder-addon'); ?></th><th><?php echo esc_html__('Actor/provider mapping', 'future-island-deep-trend-finder-addon'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($defaults['source_limits'] as $source => $limit): ?>
                        <tr>
                            <td><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enabled_sources][]" value="<?php echo esc_attr($source); ?>" <?php checked(in_array($source, (array) $settings['enabled_sources'], true)); ?>></td>
                            <td><code><?php echo esc_html($source); ?></code></td>
                            <td><input type="number" min="1" max="<?php echo esc_attr((int) ($settings['source_max_caps'][$source] ?? 100)); ?>" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[source_limits][<?php echo esc_attr($source); ?>]" value="<?php echo esc_attr((int) ($settings['source_limits'][$source] ?? $limit)); ?>"></td>
                            <td><?php if (isset($defaults['actor_map'][$source])): ?><input type="text" class="regular-text" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[actor_map][<?php echo esc_attr($source); ?>]" value="<?php echo esc_attr((string) ($settings['actor_map'][$source] ?? '')); ?>"><?php else: ?><span class="description"><?php echo esc_html__('AI provider, no scraper actor.', 'future-island-deep-trend-finder-addon'); ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h2><?php echo esc_html__('AI model aliases', 'future-island-deep-trend-finder-addon'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php foreach (['planner_model_alias', 'relevance_model_alias', 'synthesis_model_alias'] as $alias): ?>
                        <tr><th scope="row"><label for="<?php echo esc_attr($alias); ?>"><?php echo esc_html($alias); ?></label></th><td><input id="<?php echo esc_attr($alias); ?>" type="text" class="regular-text" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[<?php echo esc_attr($alias); ?>]" value="<?php echo esc_attr((string) $settings[$alias]); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php echo esc_html__('Execution safety', 'future-island-deep-trend-finder-addon'); ?></h2>

                <?php if (empty($settings['enable_ai_planner_bridge'])): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('AI planner bridge is disabled. Local fallback planner will be used and no planner model API call will be made.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php else: ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html__('AI planner bridge may call configured model APIs and consume AI/API costs.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <?php if (empty($settings['enable_live_dispatch'])): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('Live dispatch is disabled. Runs are planned only and no provider calls will be made.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php else: ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html__('Live dispatch is enabled. Provider bridge hooks may start external jobs and consume provider/API costs.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <div class="notice notice-info inline"><p><?php echo esc_html__('Deep video is hard-disabled in this build. The setting alone will not download or analyze video/audio without the external worker.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php if (!empty($settings['enable_deep_video']) && (!defined('FI_DTF_ENABLE_DEEP_VIDEO') || !FI_DTF_ENABLE_DEEP_VIDEO)): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('Deep video setting is on, but the hard feature flag is off.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <?php if (empty($settings['enable_credit_reservation'])): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('Credit reservation is disabled. Runs use planning estimates only.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_ai_planner_bridge]" value="1" <?php checked(!empty($settings['enable_ai_planner_bridge'])); ?>> <?php echo esc_html__('Enable AI planner bridge only after a configured model bridge is installed and tested.', 'future-island-deep-trend-finder-addon'); ?></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_live_dispatch]" value="1" <?php checked(!empty($settings['enable_live_dispatch'])); ?>> <?php echo esc_html__('Enable live source dispatch only when a provider bridge is installed and tested.', 'future-island-deep-trend-finder-addon'); ?></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_multi_source_live_bridge]" value="1" <?php checked(!empty($settings['enable_multi_source_live_bridge'])); ?>> <?php echo esc_html__('Enable Instagram, Reddit, Google Trends and Google News Apify live bridges after actor mappings are tested.', 'future-island-deep-trend-finder-addon'); ?></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_ai_research_bridge]" value="1" <?php checked(!empty($settings['enable_ai_research_bridge'])); ?>> <?php echo esc_html__('Enable AI research bridge filter only after a trusted bridge is installed.', 'future-island-deep-trend-finder-addon'); ?></label><br>

                <h2><?php echo esc_html__('TikTok live bridge', 'future-island-deep-trend-finder-addon'); ?></h2>
                <?php
                    $tiktok_status = FIDTF_Settings::tiktok_provider_config_status();
                    $sample_actor_input = FIDTF_Provider_TikTok::build_actor_input_from_payload([
                        'source_key' => 'tiktok',
                        'source_plan' => ['queries' => ['beverage trends'], 'max_items' => FIDTF_Settings::tiktok_default_limit(), 'sort_strategy' => 'relevance_then_engagement'],
                        'request_context' => ['market' => 'ES', 'language' => 'es', 'keywords' => ['beverage']],
                        'limits' => ['max_items' => FIDTF_Settings::tiktok_default_limit()],
                    ]);
                    unset($sample_actor_input['token'], $sample_actor_input['api_key'], $sample_actor_input['password']);
                    $sample_actor_input_state = empty($settings['enable_live_dispatch']) ? 'preview only / blocked by global_live_disabled' : 'ready to dispatch when TikTok bridge is enabled';
                ?>
                <?php $preflight_status = FIDTF_Settings::live_preflight_status(['tiktok']); ?>
                <?php foreach ((array) ($tiktok_status['warnings'] ?? []) as $warning): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div>
                <?php endforeach; ?>
                <?php if (empty($settings['enable_tiktok_live_bridge'])): ?>
                    <div class="notice notice-warning inline"><p><strong><?php echo esc_html__('TikTok live bridge is disabled.', 'future-island-deep-trend-finder-addon'); ?></strong> <?php echo esc_html__('TikTok jobs will remain planned-only and no Apify request will be sent.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php elseif (empty($settings['enable_live_dispatch'])): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('TikTok bridge is enabled, but global live dispatch is disabled. No TikTok provider job will start.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php elseif (empty($tiktok_status['provider_ready'])): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('TikTok bridge is enabled, but no usable provider is configured.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php else: ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html__('TikTok live readiness is ready. Submitting a TikTok run can start through the configured provider.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <?php if (!empty($settings['enable_live_dispatch']) && empty($settings['enable_tiktok_live_bridge'])): ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html__('Live dispatch is enabled globally, but TikTok is still disabled.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <?php if (!empty($tiktok_status['core_complete']) && empty($settings['enable_tiktok_live_bridge'])): ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html__('Core Apify client is available. Enable TikTok bridge to send live TikTok requests.', 'future-island-deep-trend-finder-addon'); ?></p></div>
                <?php endif; ?>
                <h3><?php echo esc_html__('TikTok Live Readiness', 'future-island-deep-trend-finder-addon'); ?></h3>
                <table class="widefat striped" style="max-width:900px"><tbody>
                    <tr><td><?php echo esc_html__('Global live dispatch', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($preflight_status['global_live_dispatch_enabled']) ? 'yes' : 'no'; ?></td></tr>
                    <tr><td><?php echo esc_html__('TikTok bridge', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($preflight_status['tiktok_live_bridge_enabled']) ? 'yes' : 'no'; ?></td></tr>
                    <tr><td><?php echo esc_html__('Provider mode', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo esc_html((string) ($preflight_status['tiktok_provider_mode'] ?? '')); ?></td></tr>
                    <tr><td><?php echo esc_html__('Core Apify client', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($preflight_status['core_apify_methods_available']) ? 'ready' : 'not ready'; ?></td></tr>
                    <tr><td><?php echo esc_html__('Direct token', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($preflight_status['direct_token_available']) ? 'available' : 'missing'; ?></td></tr>
                    <tr><td><?php echo esc_html__('External filter handler', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($preflight_status['external_filter_available']) ? 'available' : 'missing'; ?></td></tr>
                    <tr><td><strong><?php echo esc_html__('Final readiness', 'future-island-deep-trend-finder-addon'); ?></strong></td><td><strong><?php echo !empty($preflight_status['tiktok_live_ready']) ? 'ready' : 'not ready'; ?></strong></td></tr>
                    <tr><td><?php echo esc_html__('Blocking reasons', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo esc_html(implode(', ', (array) ($preflight_status['blocking_reasons'] ?? [])) ?: 'none'); ?></td></tr>
                </tbody></table>
                <?php $all_preflight_status = FIDTF_Settings::live_preflight_status((array) ($settings['enabled_sources'] ?? [])); ?>
                <h3><?php echo esc_html__('Multi-source live readiness', 'future-island-deep-trend-finder-addon'); ?></h3>
                <table class="widefat striped" style="max-width:900px"><tbody>
                    <tr><td><?php echo esc_html__('Multi-source live bridge', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($all_preflight_status['multi_source_live_bridge_enabled']) ? 'yes' : 'no'; ?></td></tr>
                    <tr><td><?php echo esc_html__('Generic Apify provider', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($all_preflight_status['generic_apify_ready']) ? 'ready' : 'not ready'; ?></td></tr>
                    <tr><td><?php echo esc_html__('Live sources', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo esc_html(implode(', ', (array) ($all_preflight_status['live_sources'] ?? [])) ?: 'none'); ?></td></tr>
                    <tr><td><?php echo esc_html__('Planned-only sources', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo esc_html(implode(', ', (array) ($all_preflight_status['planned_only_sources'] ?? [])) ?: 'none'); ?></td></tr>
                </tbody></table>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><?php echo esc_html__('Enable TikTok bridge', 'future-island-deep-trend-finder-addon'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_tiktok_live_bridge]" value="1" <?php checked(!empty($settings['enable_tiktok_live_bridge'])); ?>> <?php echo esc_html__('Allow TikTok jobs to start a live provider run. Other live bridges are controlled separately.', 'future-island-deep-trend-finder-addon'); ?></label></td></tr>
                    <tr><th scope="row"><label for="fidtf_tiktok_discovery_actor_id"><?php echo esc_html__('TikTok discovery actor ID', 'future-island-deep-trend-finder-addon'); ?></label></th><td><input id="fidtf_tiktok_discovery_actor_id" type="text" class="regular-text" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_discovery_actor_id]" value="<?php echo esc_attr((string) ($settings['tiktok_discovery_actor_id'] ?? FIDTF_Settings::tiktok_discovery_actor_id())); ?>"><p class="description"><?php echo esc_html__('Primary collection/search actor. Default: clockworks/tiktok-scraper. Clockworks actors receive searchQueries, hashtags, resultsPerPage, searchSection, videoSearchSorting, videoSearchDateFilter, and proxyCountryCode. Apidojo/tiktok-scraper receives keywords/startUrls, dateRange, location, maxItems, sortType, includeSearchKeywords, and customMapFunction. Scraptik endpoint fields are not sent here.', 'future-island-deep-trend-finder-addon'); ?></p></td></tr>
                    <tr><th scope="row"><label for="fidtf_tiktok_enrichment_actor_id"><?php echo esc_html__('TikTok enrichment actor ID', 'future-island-deep-trend-finder-addon'); ?></label></th><td><input id="fidtf_tiktok_enrichment_actor_id" type="text" class="regular-text" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_enrichment_actor_id]" value="<?php echo esc_attr((string) ($settings['tiktok_enrichment_actor_id'] ?? FIDTF_Settings::tiktok_enrichment_actor_id())); ?>"><p class="description"><?php echo esc_html__('Optional selected-post enrichment actor. Default: scraptik/tiktok-api. It is called only after discovery returns candidate posts, using post_awemeId, listComments_awemeId, listComments_count, and listComments_cursor.', 'future-island-deep-trend-finder-addon'); ?></p></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('TikTok item limits', 'future-island-deep-trend-finder-addon'); ?></th><td><label><?php echo esc_html__('Default', 'future-island-deep-trend-finder-addon'); ?> <input type="number" min="1" max="300" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_default_limit]" value="<?php echo esc_attr((int) $settings['tiktok_default_limit']); ?>"></label> <label style="margin-left:1em"><?php echo esc_html__('Admin max', 'future-island-deep-trend-finder-addon'); ?> <input type="number" min="1" max="300" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_max_limit]" value="<?php echo esc_attr((int) $settings['tiktok_max_limit']); ?>"></label> <label style="margin-left:1em"><?php echo esc_html__('Minimum views', 'future-island-deep-trend-finder-addon'); ?> <input type="number" min="0" max="100000000" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_min_views]" value="<?php echo esc_attr((int) ($settings['tiktok_min_views'] ?? 10000)); ?>"></label><p class="description"><?php echo esc_html__('Discovery actors may not enforce minimum views at source. This threshold is applied after normalization so low-view TikTok posts are hidden by default.', 'future-island-deep-trend-finder-addon'); ?></p></td></tr>
                    <tr><th scope="row"><label for="fidtf_tiktok_provider_mode"><?php echo esc_html__('Provider mode', 'future-island-deep-trend-finder-addon'); ?></label></th><td><select id="fidtf_tiktok_provider_mode" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_provider_mode]">
                        <?php foreach (['core_apify_client' => 'Mother/core Apify client', 'apify_bridge' => 'Direct Apify bridge', 'external_filter' => 'External WordPress filter'] as $mode => $label): ?>
                            <option value="<?php echo esc_attr($mode); ?>" <?php selected($settings['tiktok_provider_mode'], $mode); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select><p class="description"><?php echo esc_html(sprintf(__('Recommended: %s', 'future-island-deep-trend-finder-addon'), (string) ($tiktok_status['recommended_provider_mode'] ?? 'core_apify_client'))); ?></p></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Safe provider diagnostics', 'future-island-deep-trend-finder-addon'); ?></th><td><table class="widefat striped" style="max-width:720px"><tbody>
                        <tr><td><?php echo esc_html__('Core active', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($tiktok_status['core_active']) ? 'yes' : 'no'; ?></td></tr>
                        <tr><td><?php echo esc_html__('VES_Apify_Client available', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($tiktok_status['ves_apify_client_available']) ? 'yes' : 'no'; ?></td></tr>
                        <?php foreach ((array) ($tiktok_status['core_methods'] ?? []) as $method => $available): ?>
                            <tr><td><?php echo esc_html('Core method: ' . $method); ?></td><td><?php echo !empty($available) ? 'yes' : 'no'; ?></td></tr>
                        <?php endforeach; ?>
                        <tr><td><?php echo esc_html__('External filter handler', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($tiktok_status['external_filter_available']) ? 'yes' : 'no'; ?></td></tr>
                        <tr><td><?php echo esc_html__('Direct token available', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($tiktok_status['direct_token_available']) ? 'yes' : 'no'; ?></td></tr>
                        <tr><td><?php echo esc_html__('TikTok bridge enabled', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($tiktok_status['tiktok_bridge_enabled']) ? 'yes' : 'no'; ?></td></tr>
                        <tr><td><?php echo esc_html__('Live dispatch enabled', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo !empty($tiktok_status['live_dispatch_enabled']) ? 'yes' : 'no'; ?></td></tr>
                        <tr><td><?php echo esc_html__('Discovery actor input state', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo esc_html($sample_actor_input_state); ?></td></tr>
                        <tr><td><?php echo esc_html__('Sanitized discovery actor input preview', 'future-island-deep-trend-finder-addon'); ?></td><td><code><?php echo esc_html(wp_json_encode($sample_actor_input)); ?></code></td></tr>
                        <tr><td><?php echo esc_html__('Flattened output counts', 'future-island-deep-trend-finder-addon'); ?></td><td><?php echo esc_html__('Visible in admin run diagnostics as provider_dataset_rows, flattened_raw_items, normalized_items, and relevant_items after a TikTok run completes.', 'future-island-deep-trend-finder-addon'); ?></td></tr>
                    </tbody></table></td></tr>
                    <tr><th scope="row"><label for="fidtf_tiktok_apify_token"><?php echo esc_html__('Apify token', 'future-island-deep-trend-finder-addon'); ?></label></th><td><input id="fidtf_tiktok_apify_token" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_apify_token]" value=""><p class="description"><?php echo esc_html(!empty($tiktok_status['has_token']) ? 'Token configured. Leave blank to keep it unchanged. A wp-config.php constant named FIDTF_TIKTOK_APIFY_TOKEN overrides this setting.' : 'No token configured. Required only for Direct Apify bridge mode.'); ?></p></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Polling', 'future-island-deep-trend-finder-addon'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[tiktok_polling_enabled]" value="1" <?php checked(!empty($settings['tiktok_polling_enabled'])); ?>> <?php echo esc_html__('Poll queued/running TikTok jobs when a run is refreshed.', 'future-island-deep-trend-finder-addon'); ?></label></td></tr>
                </table>

                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_credit_reservation]" value="1" <?php checked(!empty($settings['enable_credit_reservation'])); ?>> <?php echo esc_html__('Reserve credits on run creation.', 'future-island-deep-trend-finder-addon'); ?></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[enable_deep_video]" value="1" <?php checked(!empty($settings['enable_deep_video'])); ?>> <?php echo esc_html__('Enable deep video/audio setting only when the hard flag and external worker exist.', 'future-island-deep-trend-finder-addon'); ?></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[debug_mode]" value="1" <?php checked(!empty($settings['debug_mode'])); ?>> <?php echo esc_html__('Debug mode for admins.', 'future-island-deep-trend-finder-addon'); ?></label>
                <p><label><?php echo esc_html__('Max relevance batch size', 'future-island-deep-trend-finder-addon'); ?> <input type="number" min="5" max="100" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[max_relevance_batch_size]" value="<?php echo esc_attr((int) $settings['max_relevance_batch_size']); ?>"></label></p>
                <p><label><?php echo esc_html__('Retention days', 'future-island-deep-trend-finder-addon'); ?> <input type="number" min="1" max="365" name="<?php echo esc_attr(FIDTF_Settings::OPTION); ?>[retention_days]" value="<?php echo esc_attr((int) $settings['retention_days']); ?>"></label></p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
