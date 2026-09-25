<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Head;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Support\NotFound;

/**
 * Removes what WordPress adds to every page's <head> and HTTP headers without the site needing it.
 */
final class HeadModule implements Module {
	public function id(): string {
		return 'head';
	}

	public function title(): string {
		return __('Page header cleanup', 'wptb');
	}

	public function description(): string {
		return __('Tags, links and scripts WordPress adds to every page for features most sites don\'t use. Yoast SEO has similar optional switches under "Crawl optimization"; using both is fine.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'remove_generator',
				true,
				__('Hide the WordPress version', 'wptb'),
				what: __('Removes the tag that tells every visitor which WordPress version the site runs.', 'wptb'),
				how: __('Removes the "generator" meta tag from the page header and the generator line from feeds.', 'wptb'),
				why: __('The version number helps attackers pick known vulnerabilities; nobody else needs it.', 'wptb'),
			),
			Field::bool(
				'remove_rsd',
				true,
				__('Remove the RSD link', 'wptb'),
				what: __('Removes the "Really Simple Discovery" link from the page header.', 'wptb'),
				how: __('Removes the rsd_link output from the page header.', 'wptb'),
				why: __('It only helps old desktop blogging apps find the XML-RPC interface, which hardly anybody uses anymore.', 'wptb'),
			),
			Field::bool(
				'remove_shortlink',
				true,
				__('Remove the shortlink', 'wptb'),
				what: __('Removes the ?p=123 short address WordPress announces for every page.', 'wptb'),
				how: __('Removes the shortlink tag from the page header and the "Link: rel=shortlink" HTTP header.', 'wptb'),
				why: __('Nothing uses these addresses, and they show internal post IDs.', 'wptb'),
			),
			Field::bool(
				'disable_emojis',
				true,
				__('Disable the emoji script', 'wptb'),
				what: __('Stops loading WordPress\' emoji script and styles on the website and in the admin. Emojis still show up, drawn by the visitor\'s own device.', 'wptb'),
				how: __('Removes the emoji detection script, the emoji styles and the emoji conversion in feeds, emails and the classic editor.', 'wptb'),
				why: __('Every current browser and operating system displays emojis natively. The script is an extra request and inline code on every page for nothing.', 'wptb'),
			),
			Field::bool(
				'disable_embeds',
				false,
				__('Don\'t let other sites embed your pages', 'wptb'),
				what: __('Other WordPress sites can no longer show your pages as embedded preview cards. Embedding YouTube, Vimeo etc. in your own content keeps working.', 'wptb'),
				how: __('Removes the oEmbed discovery links and the host script from the page header, removes the /oembed/1.0/embed REST route and answers the /embed/ versions of your pages with 404.', 'wptb'),
				why: __('Few business sites want to be embedded elsewhere. Without it there are fewer tags on every page and fewer ways to scrape your content.', 'wptb'),
				sideEffects: __('Links to your site pasted into another WordPress site show as plain links instead of preview cards. Social media previews are not affected (they use Open Graph tags, e.g. from Yoast SEO).', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$on = static fn (string $key): bool => $settings->get('head', $key) === true;

		if ($on('remove_generator')) {
			remove_action('wp_head', 'wp_generator');
			// the generator also appears in feeds
			add_filter('the_generator', '__return_empty_string');
		}

		if ($on('remove_rsd')) {
			remove_action('wp_head', 'rsd_link');
		}

		if ($on('remove_shortlink')) {
			remove_action('wp_head', 'wp_shortlink_wp_head', 10);
			remove_action('template_redirect', 'wp_shortlink_header', 11);
		}

		if ($on('disable_emojis')) {
			remove_action('wp_head', 'print_emoji_detection_script', 7);
			remove_action('embed_head', 'print_emoji_detection_script');
			remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
			remove_action('enqueue_embed_scripts', 'wp_enqueue_emoji_styles');
			remove_action('wp_print_styles', 'print_emoji_styles');
			remove_filter('the_content_feed', 'wp_staticize_emoji');
			remove_filter('comment_text_rss', 'wp_staticize_emoji');
			remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
			add_filter('tiny_mce_plugins', [$this, 'removeTinyMceEmojiPlugin']);
			// the admin versions are only added after init
			add_action('admin_init', [$this, 'removeAdminEmojis']);
		}

		if ($on('disable_embeds')) {
			remove_action('wp_head', 'wp_oembed_add_discovery_links', 4);
			remove_action('wp_head', 'wp_oembed_add_discovery_links');
			remove_action('wp_head', 'wp_oembed_add_host_js');
			add_filter('rest_endpoints', [$this, 'removeOembedRoute']);
			add_action('template_redirect', [$this, 'blockEmbedPages'], 0);
		}
	}

	public function removeAdminEmojis(): void {
		remove_action('admin_print_scripts', 'print_emoji_detection_script');
		remove_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
		remove_action('admin_print_styles', 'print_emoji_styles');
	}

	/**
	 * @param array<int|string, string> $plugins
	 * @return array<int|string, string>
	 */
	public function removeTinyMceEmojiPlugin(array $plugins): array {
		return array_diff($plugins, ['wpemoji']);
	}

	/**
	 * Only the endpoint others use to embed this site. /oembed/1.0/proxy stays: the editor uses it to embed YouTube & co.
	 *
	 * @param array<string, mixed> $endpoints
	 * @return array<string, mixed>
	 */
	public function removeOembedRoute(array $endpoints): array {
		unset($endpoints['/oembed/1.0/embed']);
		return $endpoints;
	}

	public function blockEmbedPages(): void {
		if (is_embed()) {
			NotFound::send();
		}
	}
}
