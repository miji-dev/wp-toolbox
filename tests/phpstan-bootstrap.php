<?php
// Constants the plugin reads from wp-config.php (all optional).
define('WPTB_SETTINGS', []);
define('WPTB_UPDATE_CHANNEL', 'stable');

// WP-Optimize (only called if it exists)
if (!function_exists('wpo_cache_flush')) {
	function wpo_cache_flush(): void {
	}
}

// WPML String Translation (only called if it exists)
if (!function_exists('icl_unregister_string')) {
	function icl_unregister_string(string $context, string $name): void {
	}
}
