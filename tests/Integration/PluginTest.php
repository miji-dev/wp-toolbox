<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration;

use Miji\Toolbox\Plugin;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\FakeModule;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_REST_Request;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	private FakeModule $available;
	private FakeModule $unavailable;
	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->available = new FakeModule('comments', [
			Field::bool('disable', false, 'Disable comments', 'What.', 'How.', 'Why.'),
			Field::choice('mode', ['404' => 'Not found', '301' => 'Redirect'], '404', 'Mode', 'What.', 'How.', 'Why.'),
		]);
		$this->unavailable = new FakeModule('elementor', [Field::bool('edit_links', false, 'Edit links', 'What.', 'How.', 'Why.')], available: false);

		$this->isolateSettingsRegistration();
		$this->plugin = new Plugin([$this->available, $this->unavailable]);
		$this->plugin->register();
		// not do_action('init'): that would also re-run the real plugin's registration from the bootstrap
		$this->plugin->settings()->registerSetting();
		do_action('rest_api_init');
	}

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		parent::tear_down();
	}

	public function test_available_modules_are_registered_with_the_settings(): void {
		$this->assertInstanceOf(Settings::class, $this->available->registeredWith);
		$this->assertNull($this->unavailable->registeredWith);
	}

	public function test_the_setting_is_registered_on_init(): void {
		$this->assertSame(10, has_action('init', [$this->plugin->settings(), 'registerSetting']));
		$this->assertArrayHasKey(Settings::OPTION, get_registered_settings());
	}

	public function test_settings_of_unavailable_modules_are_still_part_of_the_schema(): void {
		// so stored values survive while e.g. Elementor is temporarily deactivated
		$this->assertTrue($this->available->registeredWith->has('elementor', 'edit_links'));
	}

	public function test_settings_are_readable_via_rest_by_admins(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

		$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/settings'));

		$this->assertSame(200, $response->get_status());
		$this->assertArrayHasKey(Settings::OPTION, $response->get_data());
	}

	public function test_admins_can_update_settings_via_rest(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

		$request = new WP_REST_Request('POST', '/wp/v2/settings');
		$request->set_body_params([Settings::OPTION => ['comments' => ['disable' => true]]]);
		$response = rest_do_request($request);

		$this->assertSame(200, $response->get_status(), print_r($response->get_data(), true));
		$this->assertTrue($this->available->registeredWith->get('comments', 'disable'));
		$this->assertSame('404', $this->available->registeredWith->get('comments', 'mode'), 'partial update keeps other values');
	}

	public function test_invalid_rest_updates_are_rejected(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

		$request = new WP_REST_Request('POST', '/wp/v2/settings');
		$request->set_body_params([Settings::OPTION => ['comments' => ['mode' => '500']]]);
		$response = rest_do_request($request);

		$this->assertSame(400, $response->get_status());
		$this->assertFalse(get_option(Settings::OPTION));
	}

	public function test_non_admins_cannot_read_or_update_settings(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

		$this->assertSame(403, rest_do_request(new WP_REST_Request('GET', '/wp/v2/settings'))->get_status());

		$request = new WP_REST_Request('POST', '/wp/v2/settings');
		$request->set_body_params([Settings::OPTION => ['comments' => ['disable' => true]]]);
		$this->assertSame(403, rest_do_request($request)->get_status());
		$this->assertFalse(get_option(Settings::OPTION));
	}

	public function test_direct_update_option_calls_are_sanitized_too(): void {
		update_option(Settings::OPTION, ['comments' => ['mode' => '500', 'disable' => true]]);

		$this->assertFalse(get_option(Settings::OPTION), 'invalid input keeps the previous (empty) value');

		update_option(Settings::OPTION, ['comments' => ['disable' => true]]);
		$this->assertSame(['comments' => ['disable' => true, 'mode' => '404'], 'elementor' => ['edit_links' => false]], get_option(Settings::OPTION));
	}
}
