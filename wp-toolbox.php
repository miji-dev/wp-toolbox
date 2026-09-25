<?php
/**
 * Plugin Name:       wp toolbox
 * Plugin URI:        https://github.com/miji-dev/wp-toolbox
 * Description:       Removes WordPress bloat and adds small, sensible enhancements. Everything switchable and explained.
 * Version:           4.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Michael Stahl
 * Author URI:        https://www.miji.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wptb
 * Domain Path:       /languages
 * Update URI:        https://github.com/miji-dev/wp-toolbox
 */

defined('ABSPATH') || exit;

// Keep this file parseable by old PHP versions, so a site on too old a PHP gets a notice instead of a fatal error.
if (version_compare(PHP_VERSION, '8.3', '<') || version_compare(get_bloginfo('version'), '7.1', '<')) {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p>'
			. esc_html(sprintf('wp toolbox needs PHP 8.3+ and WordPress 7.1+ (this site: PHP %s, WordPress %s). The plugin is inactive until then.', PHP_VERSION, get_bloginfo('version')))
			. '</p></div>';
	});
	return;
}

// the plugin has no runtime dependencies, so it needs no Composer autoloader
spl_autoload_register(function ($class) {
	$prefix = 'Miji\\Toolbox\\';
	if (strncmp($class, $prefix, strlen($prefix)) === 0) {
		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_readable($file)) {
			require $file;
		}
	}
});

// server rules written by the plugin (security headers in .htaccess) must not outlive it
register_deactivation_hook(__FILE__, function () {
	(new \Miji\Toolbox\Modules\Security\HeadersFile())->remove();
});

add_action('init', function () {
	\Miji\Toolbox\Plugin::boot(__FILE__);
}, 0);
