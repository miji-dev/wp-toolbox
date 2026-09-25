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
		return __('Tags, links and scripts WordPress adds to every page for features most sites don\'t use.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'remove_generator',
				false,
				__('Hide the WordPress version', 'wptb'),
				__('Removes the "generator" tag that states the exact WordPress version from pages and feeds.', 'wptb'),
				why: __('The exact version tells attackers which known vulnerabilities to try. Visitors and search engines don\'t need it.', 'wptb'),
			),
			Field::bool(
				'remove_rsd',
				false,
				__('Remove the RSD link', 'wptb'),
				__('Removes the "Really Simple Discovery" link, which points old desktop blogging apps to the XML-RPC interface.', 'wptb'),
				why: __('Those apps are long gone. The link only advertises XML-RPC, a common target for attacks.', 'wptb'),
			),
			Field::bool(
				'remove_shortlink',
				false,
				__('Remove shortlinks', 'wptb'),
				__('Removes the short "?p=123" link from the page header and the HTTP headers.', 'wptb'),
				why: __('Nothing uses it anymore, and it reveals internal post IDs.', 'wptb'),
			),
			Field::bool(
				'remove_rest_links',
				false,
				__('Remove REST API links', 'wptb'),
				__('Removes the links that point to the REST API (the site\'s data interface) from the page header and the HTTP headers. The REST API itself keeps working: the block editor and plugins need it.', 'wptb'),
				why: __('These links advertise the data interface to every visitor and bot without any benefit for the site.', 'wptb'),
			),
			Field::bool(
				'disable_emojis',
				false,
				__('Disable emoji scripts', 'wptb'),
				__('Removes the script and styles WordPress loads on every page (and in the admin) to replace emojis with images. Emojis still show, using the visitor\'s own system font.', 'wptb'),
				why: __('Every modern browser shows emojis by itself. The extra script and styles slow down every page.', 'wptb'),
				sideEffects: __('Emojis look like the visitor\'s system emojis instead of the same images everywhere. Emojis in feeds and emails are no longer converted to images.', 'wptb'),
			),
			Field::bool(
				'disable_embeds',
				false,
				__('Don\'t let other sites embed your pages', 'wptb'),
				__('Other WordPress sites can show your pages as embedded preview cards. This removes the discovery links, the embed interface and the special embed versions of your pages. Embedding YouTube videos and the like in your own content keeps working.', 'wptb'),
				why: __('Few sites want to be embedded as a card elsewhere. It adds links to every page and an extra version of every page to the site.', 'wptb'),
				sideEffects: __('Links to your pages pasted into other WordPress sites show as plain links instead of preview cards.', 'wptb'),
			),
			Field::bool(
				'disable_speculative_loading',
				false,
				__('Disable speculative loading', 'wptb'),
				__('Since WordPress 6.8, browsers load a page in advance as soon as a visitor starts clicking a link. This turns that off.', 'wptb'),
				why: __('It makes navigation feel faster, but causes page loads that never get seen. That means extra server load and can skew statistics.', 'wptb'),
				sideEffects: __('Navigating between pages may feel a little slower.', 'wptb'),
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

		if ($on('remove_rest_links')) {
			remove_action('wp_head', 'rest_output_link_wp_head', 10);
			remove_action('template_redirect', 'rest_output_link_header', 11);
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

		if ($on('disable_speculative_loading')) {
			add_filter('wp_speculation_rules_configuration', '__return_null');
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
