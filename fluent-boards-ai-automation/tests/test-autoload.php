<?php
/**
 * Guards for the lazy autoloader and the expanded trigger set. Run via tests/run.php.
 *
 * The autoloader maps FBAIA_Foo_Bar -> includes/class-fbaia-foo-bar.php. If a class file is
 * ever renamed without updating the class name (or vice versa), lazy loading silently breaks
 * in production; these checks fail fast instead.
 */

echo "Autoloader mapping & triggers\n";

$classes = [
    'FBAIA_Helpers', 'FBAIA_Logger', 'FBAIA_Knowledge_Library', 'FBAIA_Suggestion_Store',
    'FBAIA_Audit_Trail', 'FBAIA_Usage_Tracker', 'FBAIA_Automation_Playbooks', 'FBAIA_Health_Check',
    'FBAIA_Insights', 'FBAIA_Exporter', 'FBAIA_Context_Builder', 'FBAIA_OpenAI_Client',
    'FBAIA_FluentBoards_Adapter', 'FBAIA_Internal_Actions', 'FBAIA_Runner', 'FBAIA_REST_Controller',
    'FBAIA_Admin', 'FBAIA_Board_UI', 'FBAIA_Recipes', 'FBAIA_Recipe_Queue', 'FBAIA_Command', 'FBAIA_Plugin',
];

$inc = dirname(__DIR__) . '/includes/';
$all_ok = true;
foreach ($classes as $class) {
    $slug = strtolower(str_replace('_', '-', substr($class, strlen('FBAIA_'))));
    $file = $inc . 'class-fbaia-' . $slug . '.php';
    if (!is_readable($file)) {
        $all_ok = false;
        echo "  (missing: $class -> $file)\n";
    }
}
fbaia_assert('every FBAIA_* class maps to an existing include file (autoloader contract)', $all_ok);

// Spot-check the trickiest mappings explicitly.
fbaia_assert('FBAIA_OpenAI_Client -> class-fbaia-openai-client.php', is_readable($inc . 'class-fbaia-openai-client.php'));
fbaia_assert('FBAIA_FluentBoards_Adapter -> class-fbaia-fluentboards-adapter.php', is_readable($inc . 'class-fbaia-fluentboards-adapter.php'));
fbaia_assert('FBAIA_REST_Controller -> class-fbaia-rest-controller.php', is_readable($inc . 'class-fbaia-rest-controller.php'));

// New dependency triggers are available for selection.
$triggers = FBAIA_Helpers::available_triggers();
fbaia_assert('task_dependency_added trigger available', isset($triggers['task_dependency_added']));
fbaia_assert('task_dependency_removed trigger available', isset($triggers['task_dependency_removed']));

// Dependency triggers are NOT enabled by default (opt-in, safe).
$default_triggers = FBAIA_Helpers::defaults()['triggers'];
fbaia_assert('dependency triggers are opt-in (off by default)', !in_array('task_dependency_added', $default_triggers, true));

// New triggers still belong to the automations section via the shared 'triggers' key.
fbaia_assert('triggers key still mapped to automations section', in_array('triggers', FBAIA_Helpers::section_keys('automations'), true));
