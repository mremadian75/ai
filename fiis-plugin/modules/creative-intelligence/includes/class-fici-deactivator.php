<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Deactivator {
    public static function deactivate(): void {
        // Intentionally keep data. Permanent cleanup lives in uninstall.php.
    }
}
