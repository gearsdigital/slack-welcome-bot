<?php
/**
 * Plugin Name:       Slack Welcome Bot
 * Description:       Sendet neuen Slack-Workspace-Mitgliedern automatisch eine Direktnachricht mit den Team-Regeln aus einer WordPress-Seite.
 * Version:           1.1.0
 * Requires at least: 5.6
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Steffen Giers
 * Author URI:        mailto:developer@gearsdigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slack-welcome-bot
 */

if (!defined('ABSPATH')) {
    exit; // Kein direkter Zugriff
}

define('SWB_PLUGIN_VERSION', '1.1.0');
define('SWB_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SWB_PLUGIN_DIR . 'includes/class-swb-html-converter.php';
require_once SWB_PLUGIN_DIR . 'includes/class-swb-slack-client.php';
require_once SWB_PLUGIN_DIR . 'includes/class-swb-settings.php';
require_once SWB_PLUGIN_DIR . 'includes/class-swb-rest-controller.php';
require_once SWB_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

/**
 * Plugin initialisieren.
 */
function swb_init(): void
{
    SWB_Settings::instance();
    SWB_Rest_Controller::instance();
}
add_action('plugins_loaded', 'swb_init');

/**
 * Update-Prüfung gegen GitHub Releases, damit WordPress im Backend
 * auf neue Versionen hinweist (das Plugin ist nicht im wordpress.org-Verzeichnis).
 */
function swb_init_update_checker(): void
{
    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/gearsdigital/slack-welcome-bot/',
        __FILE__,
        'slack-welcome-bot'
    );
    $updateChecker->getVcsApi()->enableReleaseAssets();
}
add_action('init', 'swb_init_update_checker');

/**
 * Direkter Link zu den Plugin-Einstellungen in der Plugin-Liste.
 */
function swb_plugin_action_links(array $links): array
{
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('options-general.php?page=slack-welcome-bot')),
        esc_html__('Einstellungen', 'slack-welcome-bot')
    );

    array_unshift($links, $settings_link);

    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'swb_plugin_action_links');
