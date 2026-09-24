<?php
/**
 * Config for the WordPress test library: WordPress core from Composer, SQLite instead of MySQL,
 * so the integration tests run anywhere PHP runs (no Docker, no database server).
 */

define('ABSPATH', dirname(__DIR__) . '/vendor/roots/wordpress-no-content/');
define('WP_CONTENT_DIR', require __DIR__ . '/content-dir.php');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');

// Read by the SQLite db.php drop-in.
define('DB_DIR', WP_CONTENT_DIR . '/database/');
define('DB_FILE', 'tests.sqlite');

define('DB_NAME', 'wp_tests');
define('DB_USER', 'unused');
define('DB_PASSWORD', 'unused');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'wp-toolbox tests');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');
define('WP_DEBUG', true);
