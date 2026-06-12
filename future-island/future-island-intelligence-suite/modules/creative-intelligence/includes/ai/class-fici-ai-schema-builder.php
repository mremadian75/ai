<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_AI_Schema_Builder {
    public function creative_analysis_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'creative_summary',
                'asset_type',
                'detected_elements',
                'detected_text',
                'brand_presence',
                'hook_analysis',
                'platform_fit',
                'strengths',
                'weaknesses',
                'opportunities',
                'recommended_next_actions',
                'suggested_brief',
                'suggested_hooks',
                'suggested_captions',
                'confidence'
            ),
            'properties'           => array(
                'creative_summary' => array( 'type' => 'string' ),
                'asset_type'       => array( 'type' => 'string', 'enum' => array( 'image', 'video', 'url', 'text', 'unknown' ) ),
                'detected_elements'=> array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'detected_text'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'brand_presence'   => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'score', 'notes' ),
                    'properties'           => array(
                        'score' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                        'notes' => array( 'type' => 'string' ),
                    ),
                ),
                'hook_analysis'    => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'score', 'hook_type', 'diagnosis' ),
                    'properties'           => array(
                        'score'     => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                        'hook_type' => array( 'type' => 'string' ),
                        'diagnosis' => array( 'type' => 'string' ),
                    ),
                ),
                'platform_fit'     => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'tiktok', 'instagram_reels', 'youtube_shorts', 'meta_ads', 'linkedin' ),
                    'properties'           => array(
                        'tiktok'          => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                        'instagram_reels' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                        'youtube_shorts'  => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                        'meta_ads'        => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                        'linkedin'        => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
                    ),
                ),
                'strengths'                => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'weaknesses'               => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'opportunities'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'recommended_next_actions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'suggested_brief'          => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'objective', 'target_audience', 'angle', 'key_points' ),
                    'properties'           => array(
                        'objective'       => array( 'type' => 'string' ),
                        'target_audience' => array( 'type' => 'string' ),
                        'angle'           => array( 'type' => 'string' ),
                        'key_points'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    ),
                ),
                'suggested_hooks'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'suggested_captions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'confidence'         => array( 'type' => 'string', 'enum' => array( 'low', 'medium', 'high' ) ),
            ),
        );
    }
}
