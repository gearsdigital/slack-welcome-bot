<?php
/**
 * Minimal test bootstrap.
 *
 * These tests target the plugin's pure logic (HTML→Slack conversion,
 * webhook signature verification) directly, without booting WordPress.
 * Only the handful of WP functions that logic actually calls are stubbed
 * below; anything hook-related (add_action, register_rest_route, ...)
 * never runs because it lives behind the classes' private constructors,
 * which these tests don't invoke.
 */

define('ABSPATH', __DIR__ . '/');

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        return trim(strip_tags($text));
    }
}

require_once __DIR__ . '/../includes/class-swb-html-converter.php';
require_once __DIR__ . '/../includes/class-swb-rest-controller.php';
