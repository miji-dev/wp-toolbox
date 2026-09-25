<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Head\HeadModule;
use Miji\Toolbox\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

final class HeadModuleTest extends WP_UnitTestCase {
	private HeadModule $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new HeadModule();
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * @param array<string, bool> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['head' => $values]));
	}

	private function output(callable $fn): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_off_changes_nothing(): void {
		$this->enable([]);

		foreach (['wp_generator', 'rsd_link', 'wp_shortlink_wp_head', 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links', 'print_emoji_detection_script'] as $callback) {
			$this->assertNotFalse(has_action('wp_head', $callback), $callback);
		}
		$this->assertSame(11, has_action('template_redirect', 'rest_output_link_header'));
		$this->assertSame(11, has_action('template_redirect', 'wp_shortlink_header'));
		$this->assertArrayHasKey('/oembed/1.0/embed', rest_get_server()->get_routes());
		$this->assertNotNull(apply_filters('wp_speculation_rules_configuration', ['mode' => 'auto', 'eagerness' => 'auto']));
	}

	public function test_generator_is_removed_from_pages_and_feeds(): void {
		$this->enable(['remove_generator' => true]);

		$this->assertFalse(has_action('wp_head', 'wp_generator'));
		// core echoes a newline after the (filtered) tag
		$this->assertSame('', trim($this->output(static fn () => the_generator('rss2'))));
		$this->assertSame('', trim($this->output(static fn () => the_generator('atom'))));
	}

	public function test_rsd_link_is_removed(): void {
		$this->enable(['remove_rsd' => true]);

		$this->assertFalse(has_action('wp_head', 'rsd_link'));
	}

	public function test_shortlink_is_removed_from_head_and_headers(): void {
		$this->enable(['remove_shortlink' => true]);

		$this->assertFalse(has_action('wp_head', 'wp_shortlink_wp_head'));
		$this->assertFalse(has_action('template_redirect', 'wp_shortlink_header'));
	}

	public function test_rest_links_are_removed_but_the_api_keeps_working(): void {
		$this->enable(['remove_rest_links' => true]);

		$this->assertFalse(has_action('wp_head', 'rest_output_link_wp_head'));
		$this->assertFalse(has_action('template_redirect', 'rest_output_link_header'));
		$this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'))->get_status());
	}

	public function test_emojis_are_removed_from_frontend_embeds_feeds_and_mail(): void {
		$this->enable(['disable_emojis' => true]);

		$this->assertFalse(has_action('wp_head', 'print_emoji_detection_script'));
		$this->assertFalse(has_action('embed_head', 'print_emoji_detection_script'));
		$this->assertFalse(has_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles'));
		$this->assertFalse(has_action('enqueue_embed_scripts', 'wp_enqueue_emoji_styles'));
		$this->assertFalse(has_action('wp_print_styles', 'print_emoji_styles'));
		$this->assertFalse(has_filter('the_content_feed', 'wp_staticize_emoji'));
		$this->assertFalse(has_filter('comment_text_rss', 'wp_staticize_emoji'));
		$this->assertFalse(has_filter('wp_mail', 'wp_staticize_emoji_for_email'));
		$this->assertSame(['lists', 'wordpress'], array_values(apply_filters('tiny_mce_plugins', ['lists', 'wpemoji', 'wordpress'])));
	}

	public function test_emojis_are_removed_from_the_admin(): void {
		// admin-filters.php adds these after init, so the module removes them on admin_init
		add_action('admin_print_scripts', 'print_emoji_detection_script');
		add_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
		add_action('admin_print_styles', 'print_emoji_styles');
		$this->enable(['disable_emojis' => true]);

		$this->module->removeAdminEmojis();

		$this->assertFalse(has_action('admin_print_scripts', 'print_emoji_detection_script'));
		$this->assertFalse(has_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles'));
		$this->assertFalse(has_action('admin_print_styles', 'print_emoji_styles'));
		$this->assertNotFalse(has_action('admin_init', [$this->module, 'removeAdminEmojis']));
	}

	public function test_others_can_no_longer_embed_the_site(): void {
		$post = self::factory()->post->create();
		$this->enable(['disable_embeds' => true]);

		$this->assertFalse(has_action('wp_head', 'wp_oembed_add_discovery_links'));
		$this->assertFalse(has_action('wp_head', 'wp_oembed_add_host_js'));
		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey('/oembed/1.0/embed', $routes);
		$this->assertArrayHasKey('/oembed/1.0/proxy', $routes, 'the editor needs the proxy to embed YouTube & co.');

		$this->go_to(get_post_embed_url($post));
		$this->assertTrue(is_embed());
		$this->module->blockEmbedPages();
		$this->assertTrue(is_404());
	}

	public function test_embeds_stay_when_not_disabled(): void {
		$this->enable(['remove_generator' => true]);

		$this->assertFalse(has_action('template_redirect', [$this->module, 'blockEmbedPages']));
		$this->assertNotFalse(has_action('wp_head', 'wp_oembed_add_discovery_links'));
	}

	public function test_speculative_loading_can_be_disabled(): void {
		$this->enable(['disable_speculative_loading' => true]);

		$this->assertNull(apply_filters('wp_speculation_rules_configuration', ['mode' => 'auto', 'eagerness' => 'auto']));
	}

	public function test_settings_are_independent(): void {
		$this->enable(['remove_rsd' => true]);

		$this->assertNotFalse(has_action('wp_head', 'wp_generator'));
		$this->assertNotFalse(has_action('wp_head', 'print_emoji_detection_script'));
		$this->assertFalse(has_action('wp_head', 'rsd_link'));
	}

	public function test_every_setting_is_explained(): void {
		foreach ($this->module->fields() as $field) {
			$this->assertFalse($field->default, $field->key);
			$this->assertNotEmpty($field->description, $field->key);
			$this->assertNotEmpty($field->why, $field->key);
		}
	}
}
