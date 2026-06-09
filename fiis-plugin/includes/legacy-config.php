<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('ves_get_token')) {
    function ves_get_token() { return VES_Config::get_token(); }
}
if (!function_exists('ves_get_actor_slug')) {
    function ves_get_actor_slug($platform, $context = []) { return VES_Config::get_actor_slug($platform, $context); }
}
if (!function_exists('ves_get_openai_api_key')) {
    function ves_get_openai_api_key() { return VES_Config::get_openai_api_key(); }
}
if (!function_exists('ves_get_openai_model')) {
    function ves_get_openai_model() { return VES_Config::get_openai_model(); }
}
if (!function_exists('ves_require_login')) {
    function ves_require_login() { return VES_Config::require_login(); }
}
if (!function_exists('ves_rate_limit_seconds')) {
    function ves_rate_limit_seconds() { return VES_Config::rate_limit_seconds(); }
}
if (!function_exists('ves_max_items')) {
    function ves_max_items() { return VES_Config::max_items(); }
}
if (!function_exists('ves_hard_max_charge_usd')) {
    function ves_hard_max_charge_usd() { return VES_Config::hard_max_charge_usd(); }
}
