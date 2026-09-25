<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Editor\EditorModule;
use Miji\Toolbox\Settings\Settings;
use WP_UnitTestCase;

final class EditorModuleTest extends WP_UnitTestCase {
	private EditorModule $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new EditorModule();
	}

	public function tear_down(): void {
		add_theme_support('core-block-patterns');
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['editor' => $values]));
	}

	public function test_off_changes_nothing(): void {
		$this->enable([]);

		$this->assertTrue(apply_filters('should_load_remote_block_patterns', true));
		$this->assertSame(10, has_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets'));
		$this->assertArrayNotHasKey('enableOpenverseMediaCategory', apply_filters('block_editor_settings_all', [], null));
		$this->assertTrue(use_block_editor_for_post_type('page'));
		$this->assertTrue(wp_use_widgets_block_editor());
		$this->assertFalse(has_action('init', [$this->module, 'removeCorePatterns']));
	}

	/**
	 * @return list<string> URLs WordPress tries to download remote patterns from
	 */
	private function patternRequests(): array {
		// the pattern directory endpoint is only open to users who can edit posts (it runs in the editor)
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$requests = [];
		add_filter('pre_http_request', static function ($pre, array $args, string $url) use (&$requests) {
			$requests[] = $url;
			return new \WP_Error('blocked', 'no network in tests');
		}, 10, 3);
		_load_remote_block_patterns();
		_load_remote_featured_patterns();
		return $requests;
	}

	public function test_without_the_setting_patterns_are_downloaded(): void {
		$this->enable([]);

		$this->assertNotEmpty($this->patternRequests(), 'control: proves the next test would catch a download');
	}

	public function test_remote_patterns_are_not_downloaded(): void {
		$this->enable(['disable_remote_patterns' => true]);

		$this->assertSame([], $this->patternRequests());
	}

	public function test_core_patterns_are_removed_before_core_registers_them(): void {
		$this->enable(['disable_core_patterns' => true]);

		$priority = has_action('init', [$this->module, 'removeCorePatterns']);
		$this->assertIsInt($priority);
		$this->assertLessThan(has_action('init', '_register_core_block_patterns_and_categories'), $priority);

		$this->module->removeCorePatterns();
		$this->assertFalse(current_theme_supports('core-block-patterns'));
	}

	public function test_block_directory_is_disabled(): void {
		$this->enable(['disable_block_directory' => true]);

		$this->assertFalse(has_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets'));
	}

	public function test_openverse_is_disabled(): void {
		$this->enable(['disable_openverse' => true]);

		$this->assertFalse(apply_filters('block_editor_settings_all', ['enableOpenverseMediaCategory' => true], null)['enableOpenverseMediaCategory']);
	}

	public function test_classic_editor_for_selected_post_types(): void {
		$this->enable(['classic_editor_for' => ['page']]);

		$this->assertFalse(use_block_editor_for_post_type('page'));
		$this->assertTrue(use_block_editor_for_post_type('post'));
	}

	public function test_a_no_from_elsewhere_stays_a_no(): void {
		$this->enable(['classic_editor_for' => ['page']]);
		add_filter('use_block_editor_for_post_type', static fn (bool $use, string $type): bool => $type === 'post' ? false : $use, 5, 2);

		$this->assertFalse(use_block_editor_for_post_type('post'));
	}

	public function test_post_type_options_are_the_editable_content_types(): void {
		register_post_type('wptb_event', ['show_ui' => true, 'show_in_rest' => true, 'label' => 'Events']);
		register_post_type('wptb_hidden', ['show_ui' => false, 'show_in_rest' => true]);
		$options = $this->module->fields()[4]->options();
		unregister_post_type('wptb_event');
		unregister_post_type('wptb_hidden');

		$this->assertSame('Events', $options['wptb_event'] ?? null);
		$this->assertArrayHasKey('post', $options);
		$this->assertArrayHasKey('page', $options);
		$this->assertArrayNotHasKey('wptb_hidden', $options);
		$this->assertArrayNotHasKey('attachment', $options);
		$this->assertArrayNotHasKey('wp_block', $options, 'core\'s own types always need the block editor');
	}

	public function test_classic_widgets(): void {
		$this->enable(['classic_widgets' => true]);

		$this->assertFalse(wp_use_widgets_block_editor());
	}

	public function test_every_setting_is_explained(): void {
		foreach ($this->module->fields() as $field) {
			$this->assertFalse(is_bool($field->default) && $field->default, $field->key);
			$this->assertNotEmpty($field->description, $field->key);
			$this->assertNotEmpty($field->why, $field->key);
		}
	}
}
