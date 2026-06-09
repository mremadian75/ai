<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_AI_Provider_Registry {
    /** @var array<string,FICI_AI_Provider> */
    private array $providers = array();

    public function register_provider( FICI_AI_Provider $provider ): void {
        $this->providers[ $provider->get_name() ] = $provider;
    }

    public function get_provider( string $name ) {
        $name = sanitize_key( $name );
        if ( ! isset( $this->providers[ $name ] ) ) {
            return new WP_Error( 'fici_provider_not_found', __( 'AI provider is not registered.', FICI_TEXT_DOMAIN ), array( 'status' => 500 ) );
        }
        return $this->providers[ $name ];
    }
}
