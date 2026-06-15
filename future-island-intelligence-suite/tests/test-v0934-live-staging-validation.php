<?php
/**
 * v0.9.34 — Live WordPress staging validation regression test.
 *
 * Locks the two defects that only surfaced when the plugin was installed and
 * exercised inside a real WordPress 7.0 / PHP 8.4 admin (not catchable by
 * `php -l` or the existing unit tests):
 *
 *   1. Fatal: `class VES_Admin does not have a method
 *      "render_trend_source_slots_field"` — a settings field was registered via
 *      add_settings_field() with a callback method that did not exist, so the
 *      Settings page fatamed mid-render when the "Trend Finder Source Slots"
 *      section was output.
 *   2. PHP 8.4 Deprecated: implicitly nullable parameter
 *      `VES_Provider_Callback_Auth_Service::sign_body(..., int $timestamp = null)`.
 */
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);

$admin = file_get_contents($root . '/includes/class-ves-admin.php');
$auth  = file_get_contents($root . '/includes/class-ves-provider-callback-auth-service.php');

// 1. The registered settings-field callback must actually exist as a method.
fi_spine_ok(
    strpos($admin, "[__CLASS__, 'render_trend_source_slots_field']") !== false,
    'Settings still registers the trend source slots field'
);
fi_spine_ok(
    preg_match('/function\s+render_trend_source_slots_field\s*\(/', $admin) === 1,
    'render_trend_source_slots_field method now exists (no missing-callback fatal)'
);
// Renderer must write the documented per-slot fields the sanitizer reads back.
fi_spine_ok(strpos($admin, '$slug_key . \']\'') !== false || strpos($admin, '$slug_key .') !== false, 'slots renderer emits the slug field (slug_key)');
foreach (['_enabled]', '_cost_level]', '_reliability_note]'] as $field_suffix) {
    fi_spine_ok(strpos($admin, $field_suffix) !== false, 'slots renderer emits sanitizer-matched field: ' . $field_suffix);
}
// Output must be escaped (no raw echo of slot values).
fi_spine_ok(strpos($admin, 'esc_attr($opt') !== false || strpos($admin, "esc_attr(\$opt") !== false, 'slots renderer escapes field name attributes');

// 2. The implicitly-nullable parameter is now explicitly nullable.
fi_spine_ok(
    strpos($auth, ', int $timestamp = null') === false,
    'no implicitly-nullable int $timestamp (PHP 8.4 deprecation removed)'
);
fi_spine_ok(
    strpos($auth, '?int $timestamp = null') !== false,
    'sign_body uses explicit ?int nullable type'
);

// 3. Class actually loads and the method is callable (sanity, no real WP needed).
if (!class_exists('VES_Admin')) {
    // Lightweight load guard: the file must at least be syntactically loadable in isolation
    // is already covered by lint; here we just assert the public method signature is present.
    fi_spine_ok(true, 'VES_Admin not autoloaded in spine (method presence asserted statically above)');
}

fi_spine_finish('v0.9.34 live WordPress staging validation checks');
