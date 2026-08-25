<?php

// Called automatically by WordPress when the plugin is removed via
// "Plugins → Installed Plugins → Delete" (not on a plain deactivation).

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('swb_settings');
