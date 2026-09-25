<?php

declare(strict_types=1);

namespace Miji\Toolbox\Migration;

/**
 * The shortcodes of wp-toolbox 3.x and sw-toolbox render nothing, instead of showing their raw text to visitors
 * (e.g. "[wptb-yt id=…]"). MigrationNotice lists the pages that still contain them.
 */
final class LegacyShortcodes {
	public function register(): void {
		foreach (['wptb', 'swtb'] as $prefix) {
			foreach (['yt', 'gm', 'ga-optout', 'settings-button'] as $name) {
				if (!shortcode_exists("$prefix-$name")) {
					add_shortcode("$prefix-$name", '__return_empty_string');
				}
			}
		}
	}
}
