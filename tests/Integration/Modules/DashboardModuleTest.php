<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Dashboard\DashboardModule;
use Miji\Toolbox\Settings\Settings;
use WP_UnitTestCase;

final class DashboardModuleTest extends WP_UnitTestCase {
	private DashboardModule $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new DashboardModule();
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen('dashboard');
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
	}

	public function tear_down(): void {
		unset($GLOBALS['wp_meta_boxes']);
		set_current_screen('front');
		parent::tear_down();
	}

	/**
	 * @param list<string> $widgets
	 */
	private function enable(array $widgets): void {
		$this->module->register(new Settings([$this->module], ['dashboard' => ['hide_widgets' => $widgets]]));
	}

	/**
	 * @return list<string> widget ids still registered on the dashboard
	 */
	private function remaining(): array {
		global $wp_meta_boxes;
		$ids = [];
		foreach ($wp_meta_boxes['dashboard'] ?? [] as $context) {
			foreach ($context as $priority) {
				foreach ($priority as $id => $box) {
					if ($box) {
						$ids[] = $id;
					}
				}
			}
		}
		sort($ids);
		return $ids;
	}

	public function test_off_keeps_all_widgets(): void {
		$this->enable([]);

		wp_dashboard_setup();

		$this->assertContains('dashboard_primary', $this->remaining());
		$this->assertContains('dashboard_activity', $this->remaining());
		$this->assertFalse(has_action('wp_dashboard_setup', [$this->module, 'removeWidgets']));
		$this->assertFalse(has_action('admin_init', [$this->module, 'removeWelcomePanel']));
	}

	public function test_selected_widgets_are_removed_from_the_real_dashboard(): void {
		$this->enable(['dashboard_primary', 'dashboard_quick_press', 'dashboard_site_health']);

		wp_dashboard_setup();

		$remaining = $this->remaining();
		$this->assertNotContains('dashboard_primary', $remaining);
		$this->assertNotContains('dashboard_quick_press', $remaining);
		$this->assertNotContains('dashboard_site_health', $remaining);
		$this->assertContains('dashboard_activity', $remaining, 'not selected');
		$this->assertContains('dashboard_right_now', $remaining, 'not selected');
	}

	public function test_welcome_panel_can_be_removed(): void {
		add_action('welcome_panel', 'wp_welcome_panel');
		$this->enable(['welcome_panel']);

		$this->module->removeWelcomePanel();

		$this->assertFalse(has_action('welcome_panel', 'wp_welcome_panel'));
	}

	public function test_texts(): void {
		$field = $this->module->fields()[0];

		$this->assertSame('hide_widgets', $field->key);
		$this->assertSame([], $field->default);
		$this->assertArrayHasKey('welcome_panel', $field->options());
		$this->assertNotEmpty($field->why);
	}
}
