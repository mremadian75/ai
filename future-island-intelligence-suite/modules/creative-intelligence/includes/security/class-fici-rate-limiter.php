<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Rate_Limiter {
    public function hit( int $user_id, string $action, int $limit = 20, int $window_seconds = HOUR_IN_SECONDS ) {
        $key   = 'fici_rl_' . md5( $user_id . '|' . $action );
        $count = get_transient( $key );

        if ( false === $count ) {
            set_transient( $key, 1, $window_seconds );
            return true;
        }

        $count = absint( $count );
        if ( $count >= $limit ) {
            return new WP_Error( 'fici_rate_limited', __( 'Too many requests. Please try again later.', FICI_TEXT_DOMAIN ), array( 'status' => 429 ) );
        }

        set_transient( $key, $count + 1, $window_seconds );
        return true;
    }
}
