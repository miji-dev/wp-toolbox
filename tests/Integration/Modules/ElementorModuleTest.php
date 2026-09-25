<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Elementor\ElementorModule;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;
use WP_UnitTestCase;

final class ElementorModuleTest extends WP_UnitTestCase {
	private ElementorModule $module;
	private int $built;
	private int $plain;

	public function set_up(): void {
		parent::set_up();
		$this->module = new ElementorModule();
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->built = self::factory()->post->create(['post_type' => 'page']);
		update_post_meta($this->built, '_elementor_edit_mode', 'builder'); // what Elementor stores for pages built with it
		$this->plain = self::factory()->post->create(['post_type' => 'page']);
	}

	public function tear_down(): void {
		set_current_screen('front');
		parent::tear_down();
	}

	private function enable(bool $on = true): void {
		$this->module->register(new Settings([$this->module], ['elementor' => ['open_in_elementor' => $on]]));
	}

	private function elementorUrl(int $id): string {
		return admin_url('post.php?post=' . $id . '&action=elementor');
	}

	public function test_only_available_when_elementor_is_active(): void {
		$this->assertFalse($this->module->isAvailable());

		do_action('elementor/loaded');

		$this->assertTrue($this->module->isAvailable());
	}

	public function test_off_changes_nothing(): void {
		$this->enable(false);
		set_current_screen('edit-page');

		$this->assertStringContainsString('action=edit', (string) get_edit_post_link($this->built));
		$this->assertFalse(has_filter('page_row_actions', [$this->module, 'rowActions']));
	}

	// --- post lists ------------------------------------------------------------------------------------

	public function test_titles_in_the_list_open_elementor_for_pages_built_with_it(): void {
		$this->enable();
		set_current_screen('edit-page');

		$this->assertSame($this->elementorUrl($this->built), get_edit_post_link($this->built, 'raw'));
		$this->assertSame(esc_url($this->elementorUrl($this->built)), get_edit_post_link($this->built));
		$this->assertStringContainsString('action=edit', (string) get_edit_post_link($this->plain), 'pages not built with Elementor keep the WordPress editor');
	}

	public function test_links_elsewhere_are_not_changed(): void {
		// e.g. redirects after saving, the block editor, REST responses
		$this->enable();

		set_current_screen('post');
		$this->assertStringContainsString('action=edit', (string) get_edit_post_link($this->built));

		set_current_screen('front');
		$this->assertStringContainsString('action=edit', (string) get_edit_post_link($this->built));
	}

	public function test_row_actions_offer_the_wordpress_editor_instead_of_a_second_elementor_link(): void {
		$this->enable();
		set_current_screen('edit-page');
		$actions = ['edit' => '<a href="x">Edit</a>', 'edit_with_elementor' => '<a href="y">Edit with Elementor</a>', 'trash' => 't'];

		$built = apply_filters('page_row_actions', $actions, get_post($this->built));
		$plain = apply_filters('page_row_actions', $actions, get_post($this->plain));

		$this->assertArrayNotHasKey('edit_with_elementor', $built);
		$this->assertStringContainsString(esc_url(admin_url('post.php?post=' . $this->built . '&action=edit')), $built['edit']);
		$this->assertStringContainsString('WordPress editor', $built['edit']);
		$this->assertSame('t', $built['trash']);
		$this->assertSame($actions, $plain);
	}

	public function test_row_actions_need_edit_permission(): void {
		$this->enable();
		wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
		$actions = ['edit_with_elementor' => 'y'];

		$this->assertSame($actions, apply_filters('post_row_actions', $actions, get_post($this->built)));
	}

	// --- toolbar -------------------------------------------------------------------------------------------

	public function test_toolbar_edit_link_opens_elementor_on_the_website(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->enable();
		$this->go_to(get_permalink($this->built));
		$bar = new WP_Admin_Bar();
		$bar->add_node(['id' => 'edit', 'title' => 'Edit Page', 'href' => 'x']);

		$this->module->toolbarEditLink($bar);

		$this->assertSame($this->elementorUrl($this->built), $bar->get_node('edit')?->href);
		$this->assertSame(81, has_action('admin_bar_menu', [$this->module, 'toolbarEditLink']), 'after core adds the node (80)');
	}

	public function test_toolbar_edit_link_is_unchanged_for_other_pages(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->enable();
		$this->go_to(get_permalink($this->plain));
		$bar = new WP_Admin_Bar();
		$bar->add_node(['id' => 'edit', 'title' => 'Edit Page', 'href' => 'x']);

		$this->module->toolbarEditLink($bar);

		$this->assertSame('x', $bar->get_node('edit')?->href);
	}
}
