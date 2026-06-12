<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Code-backed module schema summary for admin diagnostics and renderer contracts.
 *
 * This complements VES_Module_Field_Schemas. It does not claim that every legacy
 * backend path has been fully rewritten; it gives each SaaS section an explicit
 * product contract so forms, actor mapping, AI schemas and result renderers stop
 * collapsing into one generic scraper model.
 */
final class VES_Module_Schemas {
    public static function all() {
        return [
            'social_media' => [
                'label' => 'Social Media Intelligence',
                'fields' => ['platform','mode','targets','searchContext','country','language','datePreset','candidatePoolSize','desiredFinalResults','rankingMode','minViews','minLikes','minComments','minEngagementRate','includeComments','includeAuthorMetadata','includeTranscript','brandKeywords','excludedKeywords','competitorProfiles','analysisGoal'],
                'required' => ['platform','mode','targets'],
                'renderer' => 'social_content_cards_or_text_list',
                'ai_schema' => ['executive_read','hook_retention','creative_pattern','metrics','audience_comments','brand_fit','recommendations','ab_tests','missing_data'],
                'notes' => 'Uses media cards only when a real thumbnail/media URL exists. Text-only sources render as compact lists/tables.',
            ],
            'linkedin' => [
                'label' => 'LinkedIn Intelligence',
                'fields' => ['linkedinObjective','targets','companyUrl','profileUrl','role','seniority','function','industry','location','companySize','yearsExperience','recentlyChangedJobs','recentlyPosted','profileKeywords','language','datePreset','limit','advancedProviderFilters'],
                'required' => ['linkedinObjective'],
                'renderer' => 'linkedin_objective_specific_results',
                'ai_schema' => ['positioning_read','audience_signal','content_pattern','company_or_profile_context','recommendations','missing_actor_setup'],
                'notes' => 'People/company/contact-style objectives are visible as setup-required unless a compatible actor is configured.',
            ],
            'seo_sem_aeo' => [
                'label' => 'SEO / SEM / AEO Intelligence',
                'fields' => ['seoProvider','mode','targets','searchContext','country','language','seoIntent','minSearchVolume','maxDifficulty','minCpc','maxCpc','includeRelatedKeywords','includeQuestions','includeComparisons','includeSerpUrls','competitorDomains','targetDomain','candidatePoolSize','limit','sortPriority'],
                'required' => ['seoProvider','mode','targets','country','language'],
                'renderer' => 'seo_keyword_table',
                'ai_schema' => ['keyword','cluster','intent','search_volume','cpc','difficulty','opportunity_score','serp_type','recommended_content_type','priority','why_it_matters','actions'],
                'notes' => 'No image preview, views, likes or comments. Empty results include fallback source actions.',
            ],
            'trend_finder' => [
                'label' => 'Trend Finder',
                'fields' => ['category','market','language','timeRange','trendSources','trendType','candidatePoolSize','desiredFinalTrends','sourceBudgetLevel','sortBy','businessGoal'],
                'required' => ['category','market','language','timeRange'],
                'renderer' => 'trend_opportunity_cards',
                'ai_schema' => ['trend_title','source','evidence','freshness','relevance','confidence','why_it_matters','opportunity','suggested_angle','next_action'],
                'notes' => 'Supports low-cost/balanced/deep scan pricing and should not silently hard-block expensive sources when admin budget allows.',
            ],
            'brand_deep_audit' => [
                'label' => 'Brand Deep Audit',
                'fields' => ['brandName','website','market','language','industry','auditGoalPreset','businessContext','targetAudience','socialUrls','competitors','hashtags','keywords','outputType','depth','dataSources','budgetDepthEstimate'],
                'required' => ['brandName','market','language','depth'],
                'renderer' => 'client_ready_report_schema',
                'ai_schema' => ['report_meta','executive_verdict','evidence_quality','what_we_know','what_we_do_not_know','key_patterns','strategic_diagnosis','competitor_implications','opportunities','recommended_actions','briefs','next_data_collection_plan','evidence_appendix'],
                'notes' => 'Uses strict report schema. Raw diagnostics are admin-only.',
            ],
            'creative_intelligence' => [
                'label' => 'Creative Intelligence',
                'fields' => ['creativeMode','assetUrl','platform','format','campaignGoal','audience','brandRules','competitorReferences','outputGoal'],
                'required' => ['platform','format','campaignGoal'],
                'renderer' => 'creative_analysis_cards',
                'ai_schema' => ['hook_score','brand_score','platform_fit','visual_pattern','first_3_seconds','CTA_score','emotional_trigger','weaknesses','improvement_recommendations','reusable_creative_formula','variants','ab_tests'],
                'notes' => 'Focused on creative and asset support, not publishing.',
            ],
            'google_intelligence' => [
                'label' => 'Google Intelligence',
                'fields' => ['mode','query','topic','businessCategory','location','radius','country','language','dateRange','ratingFilter','imageType','safeFilter','maxResults','sortRelevance'],
                'required' => ['mode'],
                'renderer' => 'google_source_specific_results',
                'ai_schema' => ['source_summary','evidence','demand_signal','local_or_serp_context','recommendations','next_actions'],
                'notes' => 'Search, News, Trends, Maps, Reviews and Images use mode-specific fields.',
            ],
            'ads_intelligence' => [
                'label' => 'Ads Intelligence',
                'fields' => ['mode','advertiser','domain','competitorDomains','region','format','dateRange','campaignGoal'],
                'required' => ['mode','region'],
                'renderer' => 'ads_creative_intelligence_cards',
                'ai_schema' => ['ad_themes','offers','ctas','creative_pattern','funnel_stage','competitor_gap','recommended_ad_angles'],
                'notes' => 'Ads sources are interpreted into themes and angles, not raw payload dumps.',
            ],
        ];
    }

    public static function get($key) {
        $key = sanitize_key((string) $key);
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function admin_rows() {
        $rows = [];
        foreach (self::all() as $key => $schema) {
            $rows[] = [
                'key' => $key,
                'label' => (string) ($schema['label'] ?? $key),
                'field_count' => count((array) ($schema['fields'] ?? [])),
                'required' => implode(', ', (array) ($schema['required'] ?? [])),
                'renderer' => (string) ($schema['renderer'] ?? ''),
                'ai_schema' => implode(', ', array_slice((array) ($schema['ai_schema'] ?? []), 0, 8)),
                'notes' => (string) ($schema['notes'] ?? ''),
            ];
        }
        return $rows;
    }
}
