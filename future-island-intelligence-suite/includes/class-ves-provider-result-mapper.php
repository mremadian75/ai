<?php
if (!defined('ABSPATH')) { exit; }

/** Maps provider rows into canonical Source/Signal/Evidence/Insight/Brief records. */
final class VES_Provider_Result_Mapper {
    public static function map_row(string $family, array $row, array $ctx): array {
        if ($family === 'aeo_answer') { return self::map_aeo_row($row, $ctx); }
        if ($family === 'creative_asset_analysis') { return self::map_creative_row($row, $ctx); }
        return self::map_social_row($row, $ctx);
    }

    private static function map_social_row(array $row, array $ctx): array {
        $workspace_id = (int) ($ctx['workspace_id'] ?? 0); $run_id = (int) ($ctx['run_id'] ?? 0);
        $platform = self::clean_key((string) ($row['platform'] ?? 'social'), 40);
        $text = self::first_text([$row['text'] ?? '', $row['caption'] ?? '', $row['title'] ?? '', $row['source_url'] ?? '']);
        $title = self::clean_text((string) ($row['title'] ?? ucfirst($platform) . ' social signal'), 255);
        $source_id = self::create_source($workspace_id, $run_id, [
            'source_type' => 'social', 'provider' => self::provider_enum((string) ($ctx['provider_key'] ?? 'other'), $platform ?? ''),
            'source_url' => (string) ($row['source_url'] ?? ''), 'source_title' => $title,
            'external_id' => (string) ($row['origin_ref'] ?? ($row['provider_ref'] ?? '')), 'language' => (string) ($row['language'] ?? ''), 'region' => (string) ($row['market'] ?? ''),
            'metadata' => ['provider_family' => $ctx['provider_family'] ?? '', 'platform' => $platform, 'provider_ref' => $row['provider_ref'] ?? ''],
        ]);
        $signal_id = self::create_signal($workspace_id, $run_id, [
            'source_id' => $source_id, 'signal_type' => 'content_pattern', 'title' => $title, 'summary' => self::trim($text, 1000), 'value_text' => $text,
            'occurred_at' => self::dt($row['published_at'] ?? null), 'confidence_score' => self::confidence($row['relevance_label'] ?? ''),
            'metadata' => ['social_signal' => VES_Secret_Redactor::redact($row), 'source_family' => 'social', 'quality_flags' => $row['quality_flags'] ?? []],
        ]);
        $evidence_id = self::create_evidence($workspace_id, $run_id, [
            'source_id' => $source_id, 'signal_id' => $signal_id, 'evidence_type' => 'observation',
            'text' => self::trim('Provider social row: ' . $text, 2000), 'source_url' => (string) ($row['source_url'] ?? ''), 'source_label' => $platform,
            'observed_at' => self::dt($row['captured_at'] ?? null), 'confidence_score' => self::confidence($row['relevance_label'] ?? ''),
            'metadata' => ['source_family' => 'social', 'provider_ref' => $row['provider_ref'] ?? '', 'platform' => $platform],
        ]);
        $insight_id = self::create_insight($workspace_id, $run_id, [
            'insight_type' => 'content', 'title' => 'Social signal: ' . self::trim($title, 160),
            'summary' => self::trim('A public/provider-approved social signal suggests: ' . $text, 2000),
            'recommendation' => 'Review this signal before turning it into a brief. Treat weak/viral-only signals as hypotheses until supported by more source families.',
            'confidence_score' => self::confidence($row['relevance_label'] ?? ''), 'status' => 'draft',
            'evidence_ids' => [$evidence_id], 'signal_ids' => [$signal_id],
            'metadata' => ['created_via' => 'provider_ingestion', 'provider_family' => 'social_signal', 'platform' => $platform, 'assumption_label' => self::is_weak($row) ? 'hypothesis' : 'evidence_backed_candidate'],
        ]);
        self::edges($workspace_id, $run_id, $source_id, $signal_id, $evidence_id, $insight_id, 0, 0);
        return compact('source_id','signal_id','evidence_id','insight_id') + ['brief_id' => 0, 'draft_id' => 0];
    }

    private static function map_aeo_row(array $row, array $ctx): array {
        $workspace_id = (int) ($ctx['workspace_id'] ?? 0); $run_id = (int) ($ctx['run_id'] ?? 0);
        $prompt = self::trim((string) ($row['prompt'] ?? ''), 500);
        $answer = self::trim((string) ($row['answer'] ?? ''), 3000);
        $mentioned = !empty($row['brand_mentioned']) || !empty($row['mentioned_brand']);
        $source_id = self::create_source($workspace_id, $run_id, [
            'source_type' => 'ai_answer', 'provider' => self::provider_enum((string) ($ctx['provider_key'] ?? 'other'), $platform ?? ''), 'source_title' => 'AEO answer capture', 'external_id' => (string) ($row['provider_ref'] ?? ''),
            'language' => (string) ($row['language'] ?? ''), 'region' => (string) ($row['market'] ?? ''), 'metadata' => ['provider_family' => 'aeo_answer'],
        ]);
        $signal_id = self::create_signal($workspace_id, $run_id, [
            'source_id' => $source_id, 'signal_type' => 'ai_visibility', 'title' => 'AEO answer: ' . self::trim($prompt, 120),
            'summary' => self::trim($answer, 1000), 'value_text' => $answer, 'occurred_at' => self::dt($row['captured_at'] ?? null),
            'confidence_score' => $mentioned ? 74 : 52,
            'metadata' => ['source_family' => 'aeo', 'aeo_prompt' => $prompt, 'brand_mentioned' => $mentioned, 'brand_position' => $row['brand_position'] ?? null, 'sentiment' => $row['sentiment'] ?? '', 'competitor_mentions' => $row['competitor_mentions'] ?? [], 'citations' => $row['citations'] ?? ($row['cited_sources'] ?? [])],
        ]);
        $evidence_id = self::create_evidence($workspace_id, $run_id, [
            'source_id' => $source_id, 'signal_id' => $signal_id, 'evidence_type' => 'observation',
            'text' => 'AEO answer capture for prompt: ' . $prompt . ' — ' . $answer, 'source_label' => 'AEO/GEO answer capture', 'observed_at' => self::dt($row['captured_at'] ?? null),
            'confidence_score' => $mentioned ? 74 : 52, 'metadata' => ['source_family' => 'aeo', 'citations' => $row['citations'] ?? ($row['cited_sources'] ?? [])],
        ]);
        $insight_id = self::create_insight($workspace_id, $run_id, [
            'insight_type' => 'aeo', 'title' => $mentioned ? 'AEO visibility captured' : 'AEO visibility gap detected',
            'summary' => $mentioned ? 'The brand appears in the captured answer. Review its position, sentiment and cited sources before action.' : 'The brand was not clearly visible in the captured answer. Treat this as a corrective brief candidate, not a tracker-only result.',
            'recommendation' => 'Create a corrective evidence-backed brief for page, FAQ, social or article content that improves answer readiness.',
            'confidence_score' => $mentioned ? 74 : 52, 'status' => 'draft', 'evidence_ids' => [$evidence_id], 'signal_ids' => [$signal_id],
            'metadata' => ['created_via' => 'provider_ingestion', 'provider_family' => 'aeo_answer', 'sentiment' => $row['sentiment'] ?? '', 'unsupported_claims_label' => 'provider_answer_captured_not_truth'],
        ]);
        $brief_id = self::create_brief($workspace_id, $run_id, [
            'insight_id' => $insight_id, 'origin_type' => 'insight', 'origin_id' => $insight_id, 'brief_type' => 'aeo_fix', 'channel' => 'aeo_page', 'format' => 'landing_page_section',
            'title' => 'Corrective AEO brief: ' . self::trim($prompt, 120), 'objective' => 'Improve answer-readiness using evidence, not tracker-only visibility.', 'target_audience' => 'Search and AI-answer evaluators',
            'angle' => $mentioned ? 'Strengthen cited authority and position.' : 'Close the visibility gap with clearer owned content.', 'key_points_json' => [$prompt, self::trim($answer, 400)], 'source_evidence' => [$evidence_id], 'evidence_ids' => [$evidence_id], 'status' => 'draft',
            'metadata' => ['assumption_label' => 'provider_answer_based', 'created_via' => 'provider_ingestion'],
        ]);
        self::edges($workspace_id, $run_id, $source_id, $signal_id, $evidence_id, $insight_id, $brief_id, 0);
        return compact('source_id','signal_id','evidence_id','insight_id','brief_id') + ['draft_id' => 0];
    }

    private static function map_creative_row(array $row, array $ctx): array {
        $workspace_id = (int) ($ctx['workspace_id'] ?? 0); $run_id = (int) ($ctx['run_id'] ?? 0);
        $taxonomy = class_exists('VES_Creative_Taxonomy_Service') ? VES_Creative_Taxonomy_Service::normalize($row) : [];
        $summary = self::trim((string) ($row['summary'] ?? 'Creative asset analysis row.'), 3000);
        $source_id = self::create_source($workspace_id, $run_id, ['source_type' => 'file', 'provider' => self::provider_enum((string) ($ctx['provider_key'] ?? 'other'), $platform ?? ''), 'source_url' => (string) ($row['source_url'] ?? ''), 'source_title' => 'Creative asset analysis', 'external_id' => (string) ($row['asset_ref'] ?? ($row['provider_ref'] ?? '')), 'metadata' => ['provider_family' => 'creative_asset_analysis', 'creative_taxonomy' => $taxonomy]]);
        $signal_id = self::create_signal($workspace_id, $run_id, ['source_id' => $source_id, 'signal_type' => 'content_pattern', 'title' => 'Creative asset signal', 'summary' => $summary, 'value_text' => $summary, 'confidence_score' => 65, 'metadata' => ['source_family' => 'creative', 'creative_taxonomy' => $taxonomy, 'provider_ref' => $row['provider_ref'] ?? '']]);
        $evidence_id = self::create_evidence($workspace_id, $run_id, ['source_id' => $source_id, 'signal_id' => $signal_id, 'evidence_type' => 'observation', 'text' => $summary, 'source_url' => (string) ($row['source_url'] ?? ''), 'source_label' => 'Creative analysis', 'confidence_score' => 65, 'metadata' => ['source_family' => 'creative', 'creative_taxonomy' => $taxonomy]]);
        $insight_id = self::create_insight($workspace_id, $run_id, ['insight_type' => 'content', 'title' => 'Creative learning candidate', 'summary' => $summary, 'recommendation' => 'Review this creative signal before turning it into a pattern or iteration brief.', 'confidence_score' => 65, 'status' => 'draft', 'evidence_ids' => [$evidence_id], 'signal_ids' => [$signal_id], 'metadata' => ['created_via' => 'provider_ingestion', 'creative_taxonomy' => $taxonomy]]);
        self::edges($workspace_id, $run_id, $source_id, $signal_id, $evidence_id, $insight_id, 0, 0);
        return compact('source_id','signal_id','evidence_id','insight_id') + ['brief_id' => 0, 'draft_id' => 0, 'creative_taxonomy' => $taxonomy];
    }

    private static function create_source(int $ws, int $run, array $d): int { $d = array_merge($d, ['workspace_id' => $ws, 'run_id' => $run]); $id = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::create_source($d) : 0; return is_wp_error($id) ? 0 : (int) $id; }
    private static function create_signal(int $ws, int $run, array $d): int { $d = array_merge($d, ['workspace_id' => $ws, 'run_id' => $run]); $id = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::create_signal($d) : 0; return is_wp_error($id) ? 0 : (int) $id; }
    private static function create_evidence(int $ws, int $run, array $d): int { $d = array_merge($d, ['workspace_id' => $ws, 'run_id' => $run]); $id = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::create_evidence($d) : 0; return is_wp_error($id) ? 0 : (int) $id; }
    private static function create_insight(int $ws, int $run, array $d): int { $d = array_merge($d, ['workspace_id' => $ws, 'run_id' => $run]); $id = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::create_insight($d) : 0; return is_wp_error($id) ? 0 : (int) $id; }
    private static function create_brief(int $ws, int $run, array $d): int { $d = array_merge($d, ['workspace_id' => $ws, 'run_id' => $run]); $id = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::create_brief($d) : 0; return is_wp_error($id) ? 0 : (int) $id; }

    private static function edges(int $ws, int $run, int $source, int $signal, int $evidence, int $insight, int $brief, int $draft): void {
        if (!class_exists('VES_Decision_Edge_Service')) { return; }
        if ($source && $signal) { VES_Decision_Edge_Service::add_edge($ws, $run, 'source', $source, 'signal', $signal, 'captured'); }
        if ($signal && $evidence) { VES_Decision_Edge_Service::add_edge($ws, $run, 'signal', $signal, 'evidence', $evidence, 'used_as_evidence'); }
        if ($evidence && $insight) { VES_Decision_Edge_Service::add_edge($ws, $run, 'evidence', $evidence, 'insight', $insight, 'supports'); }
        if ($insight && $brief) { VES_Decision_Edge_Service::add_edge($ws, $run, 'insight', $insight, 'brief', $brief, 'generated'); }
        if ($brief && $draft) { VES_Decision_Edge_Service::add_edge($ws, $run, 'brief', $brief, 'draft', $draft, 'generated'); }
    }

    private static function first_text(array $vals): string { foreach ($vals as $v) { $v = self::trim((string) $v, 5000); if ($v !== '') { return $v; } } return ''; }
    private static function is_weak(array $row): bool { return in_array((string) ($row['relevance_label'] ?? ''), ['weak','discarded'], true); }
    private static function confidence($label): float { $label = (string) $label; if ($label === 'direct_match') { return 78; } if ($label === 'semantic_fit' || $label === 'creative_mechanic') { return 66; } if ($label === 'weak' || $label === 'discarded') { return 35; } return 55; }
    private static function dt($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? $v : null; }
    private static function provider_enum(string $provider_key, string $platform = ''): string { $p = self::clean_key($platform ?: $provider_key, 40); return in_array($p, ['openai','apify','google','reddit','tiktok','instagram','linkedin','manual','other'], true) ? $p : 'other'; }
    private static function clean_key(string $s, int $max): string { $s=function_exists('sanitize_key')?sanitize_key($s):strtolower(preg_replace('/[^a-z0-9_\-]/i','',$s)); return substr($s,0,$max); }
    private static function clean_text(string $s, int $max): string { $s=function_exists('sanitize_text_field')?sanitize_text_field($s):trim(strip_tags($s)); return substr($s,0,$max); }
    private static function trim(string $s, int $max): string { $s=function_exists('sanitize_textarea_field')?sanitize_textarea_field($s):trim(strip_tags($s)); return substr($s,0,$max); }
}
