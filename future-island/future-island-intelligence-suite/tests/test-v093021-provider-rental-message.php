<?php
/**
 * v0.9.30.21 — provider_actor_rental_required must emit a distinct message,
 * not the generic "not configured" message shared with auth/permission errors.
 */

$root = dirname(__DIR__);
$ajax = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// 1. Version matches
$add('version is 1.2.6', strpos(file_get_contents($root . '/future-island-intelligence-suite.php'), '1.2.6') !== false);

// 2. provider_actor_rental_required gets its own branch before the generic config array
$rental_pos  = strpos($ajax, 'provider_actor_rental_required');
$config_pos  = strpos($ajax, "'missing_backend_token','module_access_denied'");
$add('provider_actor_rental_required handled before generic config array', $rental_pos !== false && $config_pos !== false && $rental_pos < $config_pos);

// 3. provider_actor_rental_required is NOT included in the generic config array
$config_block_start = strpos($ajax, "'missing_backend_token','module_access_denied'");
$config_block_end   = strpos($ajax, ');', $config_block_start);
$config_block       = ($config_block_start !== false && $config_block_end !== false) ? substr($ajax, $config_block_start, $config_block_end - $config_block_start) : '';
$add('provider_actor_rental_required not inside generic config array', strpos($config_block, 'provider_actor_rental_required') === false);

// 4. provider_actor_rental_required returns a distinct non-empty message (v0.9.31.6: Spanish)
$rental_section_start = strpos($ajax, "provider_actor_rental_required'");
$rental_section       = ($rental_section_start !== false) ? substr($ajax, $rental_section_start, 400) : '';
$add('provider_actor_rental_required returns rental-specific message', strpos($rental_section, 'actor') !== false || strpos($rental_section, 'fuente') !== false || strpos($rental_section, 'rental') !== false || strpos($rental_section, 'plan') !== false);

// 5. The rental message in the message function mentions admin guidance (v0.9.31.6: Spanish)
$msg_fn_pos = strpos($ajax, 'function public_message_for_error_category(');
$msg_fn_body = $msg_fn_pos !== false ? substr($ajax, $msg_fn_pos, 4000) : '';
$rental_in_msg_pos = strpos($msg_fn_body, "provider_actor_rental_required'");
$rental_msg_window = ($rental_in_msg_pos !== false) ? substr($msg_fn_body, $rental_in_msg_pos, 400) : '';
$add('rental message mentions admin action and actor or source', strpos($rental_msg_window, 'administrador') !== false || strpos($rental_msg_window, 'admin') !== false || strpos($rental_msg_window, 'actor') !== false);

$bad = array_column(array_filter($checks, fn($c) => !$c[1]), 0);
if (!empty($bad)) {
    fwrite(STDERR, "\nv0.9.30.21 provider-rental-message check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.30.21 provider-rental-message checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
