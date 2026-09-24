<?php
/**
 * PHPUnit bootstrap: prepares a throwaway wp-content (SQLite drop-in + the plugin symlinked into plugins/),
 * boots WordPress through the official test library and loads the plugin like WordPress would.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$content = require __DIR__ . '/content-dir.php';
$sqlite = $root . '/vendor/wp-plugins/sqlite-database-integration';

if (!is_dir($sqlite)) {
	fwrite(STDERR, "Run `composer install` first.\n");
	exit(1);
}

// fresh database + drop-in on every run
foreach (['/database', '/plugins', '/uploads'] as $dir) {
	@mkdir($content . $dir, 0777, true);
}
@unlink($content . '/database/tests.sqlite');
file_put_contents($content . '/db.php', str_replace(
	['{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '{SQLITE_PLUGIN}'],
	[$sqlite, 'sqlite-database-integration/load.php'],
	(string) file_get_contents($sqlite . '/db.copy')
));
if (!is_link($content . '/plugins/wp-toolbox')) {
	symlink($root, $content . '/plugins/wp-toolbox');
}

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $root . '/vendor/yoast/phpunit-polyfills');

require_once $root . '/vendor/autoload.php';
$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
	// load through the symlink like a real install, so plugin_basename() is "wp-toolbox/wp-toolbox.php"
	$file = WP_PLUGIN_DIR . '/wp-toolbox/wp-toolbox.php';
	wp_register_plugin_realpath($file);
	require $file;
});

require $tests_dir . '/includes/bootstrap.php';
