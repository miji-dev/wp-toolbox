<?php
/**
 * Runs when the plugin is deleted in wp-admin: removes everything the plugin stored.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('wptb_settings');
delete_site_transient('wptb_update_stable');
delete_site_transient('wptb_update_beta');
