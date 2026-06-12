<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Result_Normalizer {
    public function normalize( array $result ): array {
        $result['creative_summary'] = isset( $result['creative_summary'] ) ? sanitize_textarea_field( (string) $result['creative_summary'] ) : '';
        $result['confidence']       = isset( $result['confidence'] ) ? sanitize_key( (string) $result['confidence'] ) : 'medium';

        foreach ( array( 'detected_elements', 'detected_text', 'strengths', 'weaknesses', 'opportunities', 'recommended_next_actions', 'suggested_hooks', 'suggested_captions' ) as $list_key ) {
            if ( ! isset( $result[ $list_key ] ) || ! is_array( $result[ $list_key ] ) ) {
                $result[ $list_key ] = array();
                continue;
            }

            $result[ $list_key ] = array_values( array_map( 'sanitize_text_field', $result[ $list_key ] ) );
        }

        return $result;
    }
}
