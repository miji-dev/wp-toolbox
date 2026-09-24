<?php

declare(strict_types=1);

namespace Miji\Toolbox\Support;

use WP_Query;

/**
 * Turns the current frontend request into a regular 404 (theme's 404 template, 404 status, not cached).
 * Call it on template_redirect.
 */
final class NotFound {
	public static function send(): void {
		global $wp_query;
		if (!$wp_query instanceof WP_Query) {
			return;
		}

		$wp_query->set_404();
		// set_404() keeps the feed flags, and WordPress would render the feed anyway
		$wp_query->is_feed = false;
		$wp_query->is_comment_feed = false;
		status_header(404);
		nocache_headers();
	}
}
