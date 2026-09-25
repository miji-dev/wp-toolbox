<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Admin\AdminModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\DefaultsOff;
use WP_Admin_Bar;
use WP_Error;
use WP_UnitTestCase;

final class AdminModuleTest extends WP_UnitTestCase {
	private AdminModule $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new AdminModule();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['admin' => DefaultsOff::with('admin', $values)]));
	}

	private function bar(): WP_Admin_Bar {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		foreach (['wp-logo', 'about', 'site-name', 'new-content', 'new-page', 'customize', 'updates', 'search', 'command-palette', 'my-account'] as $id) {
			$bar->add_node(['id' => $id, 'title' => $id, 'parent' => in_array($id, ['about'], true) ? 'wp-logo' : (in_array($id, ['new-page'], true) ? 'new-content' : false)]);
		}
		return $bar;
	}

	/**
	 * @param array<string, bool> $results
	 * @return list<object>
	 */
	private static function updateResults(array $results): array {
		return array_map(static fn (bool $ok): object => (object) ['result' => $ok ? true : new WP_Error('failed', 'x'), 'name' => 'x'], array_values($results));
	}

	public function test_off_changes_nothing(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->enable([]);

		$bar = $this->bar();
		$this->module->removeAdminBarItems($bar);
		$this->assertNotNull($bar->get_node('wp-logo'));
		$this->assertSame(6 * MONTH_IN_SECONDS, apply_filters('admin_email_check_interval', 6 * MONTH_IN_SECONDS));
		$this->assertTrue(apply_filters('auto_core_update_send_email', true, 'success', null, null));
	}

	// --- admin bar ------------------------------------------------------------------------------------




	public function test_selected_admin_bar_items_are_removed(): void {
		$this->enable(['admin_bar_items' => ['wp-logo', 'new-content', 'customize', 'search', 'command-palette']]);
		$bar = $this->bar();

		$this->module->removeAdminBarItems($bar);

		// command-palette: WordPress 7's "⌘K" search in the admin (not the website's "search")
		foreach (['wp-logo', 'new-content', 'customize', 'search', 'command-palette'] as $id) {
			$this->assertNull($bar->get_node($id), $id);
		}
		$this->assertNotNull($bar->get_node('site-name'));
		$this->assertNotNull($bar->get_node('updates'));
		$this->assertSame(PHP_INT_MAX, has_action('admin_bar_menu', [$this->module, 'removeAdminBarItems']));
	}

	// --- notices -----------------------------------------------------------------------------------------


	public function test_update_notices_are_hidden_from_non_admins_only(): void {
		$this->enable(['hide_update_notices_for_non_admins' => true]);

		add_action('admin_notices', 'update_nag', 3);
		add_action('admin_notices', 'maintenance_nag', 10);
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->module->removeUpdateNotices();
		$this->assertFalse(has_action('admin_notices', 'update_nag'));
		$this->assertFalse(has_action('admin_notices', 'maintenance_nag'));

		add_action('admin_notices', 'update_nag', 3);
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$this->module->removeUpdateNotices();
		$this->assertSame(3, has_action('admin_notices', 'update_nag'), 'administrators still see them');
	}

	// --- emails -------------------------------------------------------------------------------------------

	public function test_admin_email_confirmation_screen_can_be_disabled(): void {
		$this->enable(['disable_admin_email_check' => true]);

		$this->assertSame(0, apply_filters('admin_email_check_interval', 6 * MONTH_IN_SECONDS));
	}

	public function test_successful_update_emails_are_switched_off_failures_are_kept(): void {
		$this->enable(['disable_update_emails' => ['core', 'plugins', 'themes']]);

		$this->assertFalse(apply_filters('auto_core_update_send_email', true, 'success', null, null));
		$this->assertTrue(apply_filters('auto_core_update_send_email', true, 'fail', null, null));
		$this->assertTrue(apply_filters('auto_core_update_send_email', true, 'critical', null, null));

		$this->assertFalse(apply_filters('auto_plugin_update_send_email', true, self::updateResults(['a' => true, 'b' => true])));
		$this->assertTrue(apply_filters('auto_plugin_update_send_email', true, self::updateResults(['a' => true, 'b' => false])), 'any failure is reported');
		$this->assertFalse(apply_filters('auto_theme_update_send_email', true, self::updateResults(['a' => true])));
		$this->assertTrue(apply_filters('auto_theme_update_send_email', true, self::updateResults(['a' => false])));
	}

	public function test_update_emails_can_be_chosen_per_type(): void {
		$this->enable(['disable_update_emails' => ['plugins']]);

		$this->assertTrue(apply_filters('auto_core_update_send_email', true, 'success', null, null));
		$this->assertFalse(apply_filters('auto_plugin_update_send_email', true, self::updateResults(['a' => true])));
		$this->assertTrue(apply_filters('auto_theme_update_send_email', true, self::updateResults(['a' => true])));
	}

	public function test_a_previous_no_is_never_turned_into_a_yes(): void {
		$this->enable(['disable_update_emails' => ['plugins']]);

		$this->assertFalse(apply_filters('auto_plugin_update_send_email', false, self::updateResults(['a' => false])));
	}
}
