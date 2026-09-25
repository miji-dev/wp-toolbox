<?php
/**
 * Runs when the plugin is deleted in wp-admin: removes everything the plugin stored.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('wptb_settings');
delete_option('wptb_db_version');
delete_option('wptb_migration_notice');
delete_site_transient('wptb_update_stable');
delete_site_transient('wptb_update_beta');

// normally already gone (removed on deactivation)
require_once __DIR__ . '/src/Modules/Security/HeadersFile.php';
(new \Miji\Toolbox\Modules\Security\HeadersFile())->remove();
