<?php
if (!defined('ABSPATH')) { exit; }

// Shared string compatibility helpers are loaded here because v0.4 module smoke tests
// include module classes directly, bypassing the root plugin bootstrap.
$fi_string_compat = dirname(__DIR__) . '/class-ves-string-compat.php';
if (file_exists($fi_string_compat)) { require_once $fi_string_compat; }

/**
 * FI_Abstract_Module — contract for a semi-independent Future Island SaaS
 * module living inside this plugin (the Deep Trend Finder pattern, made
 * uniform). A module declares identity, capability, navigation, status and
 * actions; heavy logic stays in its service class, markup in its renderer.
 *
 * Modules NEVER claim to run when they cannot: status() must return
 * configuration_needed/unavailable when dependencies are missing, and the
 * registry renders those entries as not-runnable.
 */
abstract class FI_Abstract_Module {

    /** Unique module id (sanitize_key form). */
    abstract public function id(): string;

    /** Human navigation/page label. */
    abstract public function label(): string;

    /** One-sentence purpose shown on the module index. */
    abstract public function description(): string;

    /** Capability required to see/use the module. */
    public function capability(): string { return 'manage_options'; }

    /**
     * Navigation entry. Two shapes:
     *  ['type' => 'page', 'slug' => 'fi-asset-studio']           — registry registers the page, render() is the callback
     *  ['type' => 'link', 'url'  => 'tools.php?page=fi-intake']  — existing surface; the registry links to it (no duplicate page)
     */
    abstract public function nav(): array;

    /**
     * Honest module status:
     *  ['state' => 'available'|'configuration_needed'|'read_only'|'unavailable', 'detail' => string]
     */
    public function status(): array { return ['state' => 'available', 'detail' => '']; }

    /** admin_post action slugs this module owns (registered by the module itself). */
    public function actions(): array { return []; }

    /** Service class name backing the module ('' when the module is a link shell). */
    public function service_class(): string { return ''; }

    /** Renderer class name ('' when the module is a link shell). */
    public function renderer_class(): string { return ''; }

    /** Register hooks/actions. Called once at boot for every module. */
    public function register(): void {}

    /** Render the module page (only used when nav type is 'page'). */
    public function render(): void {}

    /** Convenience: metadata array used by the index page and tests. */
    final public function describe(): array {
        return [
            'id'          => $this->id(),
            'label'       => $this->label(),
            'description' => $this->description(),
            'capability'  => $this->capability(),
            'nav'         => $this->nav(),
            'status'      => $this->status(),
            'actions'     => $this->actions(),
            'service'     => $this->service_class(),
            'renderer'    => $this->renderer_class(),
        ];
    }
}
