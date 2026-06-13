<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Permissions {
    public function can_use_addon(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $allowed = current_user_can( 'read' );
        $allowed = (bool) apply_filters( 'fici_core_user_can_use_addon', $allowed, get_current_user_id(), 0 );
        return (bool) apply_filters( 'fici_user_can_use_addon', $allowed, get_current_user_id() );
    }
}
