<?php
/**
 * Settings repository: non-destructive, section-aware saving and API-key preservation.
 * Run via tests/run.php.
 */

function fbaia_seed_settings()
{
    fbaia_reset_options();
    $s = FBAIA_Helpers::defaults();
    $s['api_key'] = 'sk-SECRET-EXISTING';
    $s['company_profile'] = 'Acme Co, SaaS for dentists';
    $s['team_directory'] = 'Sara | sara@acme.com | Marketing Manager';
    $s['board_profiles'] = "board_id: 12\nname: Marketing";
    $s['model'] = 'gpt-4o';
    $s['enabled'] = 'yes';
    $s['action_update_priority'] = 'yes';
    $s['debug'] = 'no';
    update_option(FBAIA_OPTION, $s, false);
    return $s;
}

echo "Settings repository\n";

// Partial whole-form POST must NOT wipe untouched fields (the original v0.9.2 bug).
fbaia_seed_settings();
FBAIA_Helpers::update_settings(['enabled' => 'yes', 'mode' => 'internal_ai_actions', 'api_base' => 'https://api.openai.com/v1']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('partial full-form save preserves company_profile', $a['company_profile'] === 'Acme Co, SaaS for dentists');
fbaia_assert('partial full-form save preserves team_directory', $a['team_directory'] === 'Sara | sara@acme.com | Marketing Manager');
fbaia_assert('partial full-form save preserves model', $a['model'] === 'gpt-4o');
fbaia_assert('partial full-form save preserves api_key', $a['api_key'] === 'sk-SECRET-EXISTING');

// Section save only touches its own section.
fbaia_seed_settings();
FBAIA_Helpers::update_settings_section('memory', ['company_profile' => 'Updated profile']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('section save updates its field', $a['company_profile'] === 'Updated profile');
fbaia_assert('section save keeps team_directory (same tab, not submitted -> preserved)', $a['team_directory'] === 'Sara | sara@acme.com | Marketing Manager');
fbaia_assert('section save keeps model (other tab)', $a['model'] === 'gpt-4o');
fbaia_assert('section save keeps api_key (other tab)', $a['api_key'] === 'sk-SECRET-EXISTING');

// Unchecking a checkbox within its submitted section turns it off.
fbaia_seed_settings();
FBAIA_Helpers::update_settings_section('automations', ['enabled' => 'yes', 'mode' => 'internal_ai_actions']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('absent checkbox in submitted section -> no', $a['action_update_priority'] === 'no');
fbaia_assert('present checkbox -> yes', $a['enabled'] === 'yes');

// A checkbox in a different section is NOT reset by an unrelated section save.
fbaia_seed_settings();
FBAIA_Helpers::update_settings_section('memory', ['company_profile' => 'x']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('checkbox outside submitted section preserved', $a['action_update_priority'] === 'yes');

// API key: blank submit preserves stored key; new value overwrites.
fbaia_seed_settings();
FBAIA_Helpers::update_settings_section('provider', ['model' => 'gpt-4o-mini', 'api_key' => '']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('blank api_key preserves stored key', $a['api_key'] === 'sk-SECRET-EXISTING');
fbaia_assert('provider save updates model', $a['model'] === 'gpt-4o-mini');
FBAIA_Helpers::update_settings_section('provider', ['api_key' => 'sk-NEWKEY']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('new api_key overwrites', $a['api_key'] === 'sk-NEWKEY');

// Numeric clamping.
fbaia_seed_settings();
FBAIA_Helpers::update_settings_section('provider', ['max_tokens' => '999999', 'temperature' => '9']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('max_tokens clamped to 8000', $a['max_tokens'] === '8000');
fbaia_assert('temperature clamped to 2', $a['temperature'] === '2');

// Enum falls back to default on invalid input.
fbaia_seed_settings();
FBAIA_Helpers::update_settings_section('automations', ['mode' => 'totally_invalid']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('invalid enum falls back to default', $a['mode'] === FBAIA_Helpers::defaults()['mode']);

// Unknown section rejected.
fbaia_assert('unknown section returns WP_Error', is_wp_error(FBAIA_Helpers::update_settings_section('nope', ['x' => 'y'])));

// Legacy webhook keys are preserved and never editable via UI.
fbaia_reset_options();
$s = FBAIA_Helpers::defaults();
$s['webhook_secret'] = 'legacy-secret';
update_option(FBAIA_OPTION, $s, false);
FBAIA_Helpers::update_settings_section('provider', ['model' => 'x']);
$a = get_option(FBAIA_OPTION);
fbaia_assert('legacy webhook_secret preserved across save', $a['webhook_secret'] === 'legacy-secret');

// Section map: every editable spec key belongs to exactly one section; triggers + api_key present.
$specs = array_keys(FBAIA_Helpers::field_specs());
$assigned = [];
$dupes = 0;
foreach (FBAIA_Helpers::sections() as $keys) {
    foreach ($keys as $k) {
        if (isset($assigned[$k])) {
            $dupes++;
        }
        $assigned[$k] = true;
    }
}
$missing = array_diff($specs, array_keys($assigned));
fbaia_assert('no duplicate keys across sections', $dupes === 0);
fbaia_assert('every field_specs key is in a section', count($missing) === 0);
fbaia_assert('triggers assigned to a section', isset($assigned['triggers']));
fbaia_assert('api_key assigned to a section', isset($assigned['api_key']));

// Form sentinel constant exists (truncation guard relies on it).
fbaia_assert('FORM_SENTINEL defined', defined('FBAIA_Helpers::FORM_SENTINEL') && FBAIA_Helpers::FORM_SENTINEL !== '');
