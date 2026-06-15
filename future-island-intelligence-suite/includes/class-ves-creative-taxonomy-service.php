<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Creative_Taxonomy_Service {
    const DIMENSIONS = [
        'platform','content_type','asset_type','hook_type','message_angle','cta_type','format',
        'creative_style','audience_stage','funnel_stage','offer_type','emotional_territory','source_inspiration',
    ];

    public static function dimensions(): array { return self::DIMENSIONS; }

    public static function normalize(array $input): array {
        $out = [];
        foreach (self::DIMENSIONS as $dim) {
            $val = $input[$dim] ?? '';
            if (is_array($val)) { $val = implode(',', array_slice($val, 0, 8)); }
            $val = self::clean_text((string) $val, 120);
            if ($val !== '') { $out[$dim] = $val; }
        }
        return $out;
    }

    public static function attach_to_metadata(array $metadata, array $taxonomy): array {
        $metadata['creative_taxonomy'] = self::normalize($taxonomy);
        return $metadata;
    }

    private static function clean_text(string $s, int $max): string {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s));
        return substr($s, 0, $max);
    }
}
