<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Code-backed field schema catalogue for source-specific SaaS modules.
 *
 * This is intentionally not a generic scraper schema. Each module declares
 * its own modes, fields, result renderer and AI output profile so the UI,
 * adapters, billing and audit report can reason about the product as separate
 * intelligence surfaces.
 */
final class VES_Module_Field_Schemas {
    public static function all() {
        return [
            'social_media' => [
                'label' => 'Social Media Intelligence',
                'renderer' => 'social_content_cards_or_compact_text_list',
                'ai_schema' => 'social_single_or_batch_content_analysis',
                'modes' => ['keyword','hashtag','profile','post_url','trend_discovery','competitor_content_scan'],
                'fields' => ['platform','objective_preset','targets','market_country','language','date_range','candidate_pool_size','desired_final_results','ranking_mode','min_views','min_likes','min_comments','min_engagement_rate','include_comments','include_author_metadata','include_transcript','brand_keywords','excluded_keywords','competitor_profiles','ai_analysis_goal'],
                'result_fields' => ['title','caption_or_text','author','platform','url','thumbnail_or_media_url','published_at','metrics','relevance_badge','why_matched','freshness','selection_key'],
                'billing_basis' => 'base_scan_fee + delivered_usable_results + optional AI analysis',
            ],
            'linkedin' => [
                'label' => 'LinkedIn Intelligence',
                'renderer' => 'linkedin_objective_specific_posts_or_setup_required_panel',
                'ai_schema' => 'b2b_content_or_company_analysis',
                'modes' => ['analyze_posts','scan_company_page','thought_leadership_topic_scan','competitor_company_scan','find_people_setup_required','scan_profile_setup_required','search_companies_setup_required','find_leads_setup_required'],
                'fields' => ['linkedin_objective','topic_or_url','company_url','competitors','date_range','post_type','engagement_threshold','industry','location','company_size','seniority','function','role','recently_posted','language','max_results','advanced_raw_provider_filters'],
                'result_fields' => ['post_text','author_or_company','url','published_at','engagement','topic_cluster','b2b_relevance','why_matched','setup_required_notice'],
                'billing_basis' => 'configured actor only; unsupported people/company modes do not start provider calls',
            ],
            'seo_sem_aeo' => [
                'label' => 'SEO / SEM / AEO Intelligence',
                'renderer' => 'seo_keyword_table',
                'ai_schema' => 'keyword_cluster_content_opportunity',
                'modes' => ['keyword_research','serp_analysis','competitor_seo_scan','aeo_opportunity','content_gap','domain_overview'],
                'fields' => ['seed_keyword','domain_or_competitor_url','market_country','language','keyword_type','intent','minimum_search_volume','maximum_difficulty','cpc_range','include_related_keywords','include_questions','include_comparisons','include_serp_urls','competitor_domains','target_domain','desired_final_keywords','candidate_pool_size','sort_by'],
                'result_fields' => ['keyword','cluster','intent','search_volume','cpc','difficulty','opportunity_score','serp_type','recommended_content_type','priority','why_it_matters','actions'],
                'billing_basis' => 'base SEO source fee + delivered keyword rows + optional brief generation',
            ],
            'trend_finder' => [
                'label' => 'Trend Finder',
                'renderer' => 'trend_opportunity_cards',
                'ai_schema' => 'trend_signal_business_opportunity',
                'modes' => ['emerging','viral','seasonal','competitor_driven','search_driven','social_driven'],
                'fields' => ['category_topic','market','language','time_range','trend_sources','trend_type','candidate_pool_size','desired_final_trends','source_budget_level','sort_by','business_goal','allow_provider_over_budget_admin_only'],
                'result_fields' => ['trend_title','source','evidence','freshness','relevance','confidence','why_it_matters','opportunity','campaign_angle','next_action'],
                'billing_basis' => 'transparent base scan fee + final usable delivered trends + optional AI/report fees',
            ],
            'brand_deep_audit' => [
                'label' => 'Brand Deep Audit',
                'renderer' => 'client_ready_report_schema_tabs',
                'ai_schema' => 'strict_brand_deep_audit_report',
                'modes' => ['light','standard','deep'],
                'fields' => ['brand_name','website','market','language','industry','audit_goal_preset','business_context','target_audience','social_urls','competitors','hashtags_keywords','output_type','depth','data_sources','budget_depth_estimate'],
                'result_fields' => ['executive_verdict','evidence_quality','what_we_know','what_we_do_not_know','market_perception','competitor_gap','search_social_demand','creative_gap','opportunities','recommended_actions','briefs','next_data_plan','evidence_appendix'],
                'billing_basis' => 'depth-based reservation + evidence normalization + final AI/report + delivered briefs',
            ],
            'creative_intelligence' => [
                'label' => 'Creative Intelligence',
                'renderer' => 'creative_asset_analysis_panel',
                'ai_schema' => 'creative_asset_strategy_review',
                'modes' => ['analyze_my_creative','analyze_competitor_creative','improve_ad_creative','extract_hooks','generate_variations','compare_creatives','build_creative_brief'],
                'fields' => ['asset_url','upload','platform','format','campaign_goal','audience','brand_rules','competitor_references','output_goal'],
                'result_fields' => ['hook_score','brand_score','platform_fit','visual_pattern','first_3_seconds','CTA_score','emotional_trigger','weaknesses','improvement_recommendations','reusable_creative_formula','variants','ab_tests'],
                'billing_basis' => 'asset analysis fee + optional variant generation',
            ],
            'google_intelligence' => [
                'label' => 'Google Intelligence',
                'renderer' => 'google_source_specific_results',
                'ai_schema' => 'google_search_news_maps_trends_interpretation',
                'modes' => ['search','news','trends','maps','maps_reviews','images','paa_questions'],
                'fields' => ['mode','query_or_topic','market','language','max_results','time_range','location','radius','rating_filter','review_date_range','image_type','safe_filters','sort_relevance'],
                'result_fields' => ['title','url','source','snippet','published_at','rating','location','trend_score','image_url','why_matched'],
                'billing_basis' => 'source-specific base fee + delivered usable source rows',
            ],
            'ads_intelligence' => [
                'label' => 'Ads Intelligence',
                'renderer' => 'ads_creative_theme_cards',
                'ai_schema' => 'advertising_competitor_creative_pattern',
                'modes' => ['facebook_ad_library','google_ads','competitor_domain_scan','creative_pattern_scan'],
                'fields' => ['advertiser_or_domain','competitor_domains','region','format','date_range','campaign_goal','funnel_stage'],
                'result_fields' => ['advertiser','domain','headline','body','format','cta','creative_url','landing_url','first_seen','themes','offer','funnel_stage','recommended_angle'],
                'billing_basis' => 'provider scan fee + delivered ad creatives + optional creative strategy analysis',
            ],
        ];
    }

    public static function get($module) {
        $module = sanitize_key((string) $module);
        $all = self::all();
        return $all[$module] ?? null;
    }

    public static function rows_for_admin() {
        $rows = [];
        foreach (self::all() as $key => $schema) {
            $rows[] = array_merge(['module' => $key], $schema);
        }
        return $rows;
    }
}
