<?php

// Wird von WordPress automatisch aufgerufen, wenn das Plugin über
// "Plugins → Installierte Plugins → Löschen" entfernt wird (nicht bei reiner Deaktivierung).

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('swb_settings');
