<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Admin;

use Miji\Toolbox\Admin\SettingsPage;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\FakeModule;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {
	private const FILE = WP_PLUGIN_DIR . '/wp-toolbox/wp-toolbox.php';

	private SettingsPage $page;

	public function set_up(): void {
		parent::set_up();
		$settings = new Settings([
			new FakeModule('comments', [Field::bool('disable', false, 'Disable comments', 'Turns </script><script>alert(1)</script> off.')]),
			new FakeModule('elementor', [Field::bool('open', false, 'Open', 'D.')], available: false),
		], ['comments' => ['disable' => true]]);
		$this->page = new SettingsPage($settings, self::FILE);
		$this->page->register();
	}

	public function tear_down(): void {
		wp_dequeue_script(SettingsPage::HANDLE);
		wp_deregister_script(SettingsPage::HANDLE);
		unset($GLOBALS['submenu'], $GLOBALS['_wp_submenu_nopriv'], $GLOBALS['_registered_pages'], $GLOBALS['_parent_pages']);
		set_current_screen('front');
		parent::tear_down();
	}

	private function loginAs(string $role): void {
		wp_set_current_user(self::factory()->user->create(['role' => $role]));
	}

	public function test_page_is_under_settings_for_administrators(): void {
		$this->loginAs('administrator');
		set_current_screen('dashboard');

		do_action('admin_menu');

		$this->assertSame(admin_url('options-general.php?page=wp-toolbox'), menu_page_url(SettingsPage::SLUG, false));
	}

	public function test_page_is_not_added_for_other_roles(): void {
		$this->loginAs('editor');
		set_current_screen('dashboard');

		do_action('admin_menu');

		$this->assertSame('', menu_page_url(SettingsPage::SLUG, false));
	}

	public function test_render_prints_the_app_container(): void {
		$this->loginAs('administrator');

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('<div id="wptb-settings"', $html);
		$this->assertStringContainsString('<h1>', $html, 'title is visible before the script has loaded');
	}

	public function test_script_and_data_are_only_loaded_on_the_page(): void {
		$this->loginAs('administrator');
		set_current_screen('dashboard');
		do_action('admin_menu');

		$this->page->enqueue('index.php');
		$this->assertFalse(wp_script_is(SettingsPage::HANDLE));

		$hook = get_plugin_page_hookname(SettingsPage::SLUG, 'options-general.php'); // "settings_page_wp-toolbox" in a real admin
		set_current_screen($hook);
		$this->page->enqueue($hook);
		$this->assertTrue(wp_script_is(SettingsPage::HANDLE));
		$this->assertSame(10, has_action('admin_enqueue_scripts', [$this->page, 'enqueue']));
		$this->assertTrue(wp_style_is('wp-components'));
	}

	public function test_data_describes_modules_fields_and_state(): void {
		$data = $this->page->data();

		$this->assertSame('wptb/v1/settings', $data['restPath']);
		$this->assertSame(\Miji\Toolbox\Plugin::VERSION, $data['version']);
		$this->assertSame(['comments', 'elementor'], array_column($data['modules'], 'id'));
		$this->assertTrue($data['modules'][0]['available']);
		$this->assertFalse($data['modules'][1]['available']);
		$this->assertSame('disable', $data['modules'][0]['fields'][0]['key']);
		$this->assertSame(['comments' => ['disable' => true], 'elementor' => ['open' => false]], $data['state']['values']);
		$this->assertSame(['comments' => ['disable']], $data['state']['locked']);
		$this->assertArrayHasKey('recommended', $data);
	}

	public function test_inline_data_cannot_close_the_script_tag(): void {
		$this->loginAs('administrator');
		set_current_screen('settings_page_wp-toolbox');
		$this->page->enqueue('settings_page_wp-toolbox');

		$inline = implode('', (array) wp_scripts()->get_data(SettingsPage::HANDLE, 'before'));

		$this->assertStringContainsString('wptbSettings', $inline);
		$this->assertStringNotContainsString('</script>', $inline);
		$this->assertStringNotContainsString('<script>', $inline);
	}

	public function test_plugin_list_links_to_the_page(): void {
		$this->loginAs('administrator');

		$links = apply_filters('plugin_action_links_' . plugin_basename(self::FILE), ['deactivate' => 'x']);

		$this->assertStringContainsString('options-general.php?page=wp-toolbox', (string) reset($links), 'first link');
		$this->assertCount(2, $links);
	}
}
