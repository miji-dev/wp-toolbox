<?php

declare(strict_types=1);

namespace Miji\Toolbox\Support;

use WP_Query;

/**
 * Turns the current frontend request into a regular 404 (theme's 404 template, 404 status, not cached,
 * no canonical redirect). Call it on template_redirect before priority 10.
 */
final class NotFound {
	public static function send(): void {
		global $wp_query;
		if (!$wp_query instanceof WP_Query) {
			return;
		}

		$wp_query->set_404();
		// set_404() keeps the queried posts; a theme's 404 template must not be able to show them
		$wp_query->posts = [];
		$wp_query->post_count = 0;
		$wp_query->found_posts = 0;
		$wp_query->post = null;
		// set_404() keeps the feed flags, and WordPress would render the feed anyway
		$wp_query->is_feed = false;
		$wp_query->is_comment_feed = false;
		status_header(404);
		nocache_headers();

		// redirect_canonical (template_redirect 10) would otherwise treat this as a broken URL, "guess" the
		// intended one and 301 there, e.g. /some-page/embed/ -> /some-page/
		add_filter('redirect_canonical', '__return_false', PHP_INT_MAX);
		add_filter('do_redirect_guess_404_permalink', '__return_false', PHP_INT_MAX);
	}
}
