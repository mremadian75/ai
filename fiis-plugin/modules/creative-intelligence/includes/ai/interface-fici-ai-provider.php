<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface FICI_AI_Provider {
    public function get_name(): string;
    public function supports( string $task_type ): bool;
    public function analyze_asset( array $payload );
}
