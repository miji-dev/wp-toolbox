<?php
// Constants the plugin reads from wp-config.php (all optional).
define('WPTB_SETTINGS', []);
define('WPTB_UPDATE_CHANNEL', 'stable');

// WP-Optimize (only called if it exists)
if (!function_exists('wpo_cache_flush')) {
	function wpo_cache_flush(): void {
	}
}
