<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Admin;

use Miji\Toolbox\Admin\SettingsController;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\FakeModule;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class SettingsControllerTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->isolateSettingsRegistration();
		$this->settings = new Settings([
			new FakeModule('comments', [
				Field::bool('disable', false, 'Disable', 'What.', 'How.', 'Why.'),
				Field::choice('mode', ['404' => 'Not found', 'home' => 'Home'], '404', 'Mode', 'What.', 'How.', 'Why.'),
			]),
			new FakeModule('media', [Field::attachment('logo', 'Logo', 'What.', 'How.', 'Why.')]),
		], ['comments' => ['disable' => true]]);

		// only this test's routes: the real plugin registers the same route on rest_api_init
		remove_all_actions('rest_api_init');
		(new SettingsController($this->settings))->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function request(string $method, ?array $body = null): WP_REST_Response {
		$request = new WP_REST_Request($method, '/wptb/v1/settings');
		if ($body !== null) {
			$request->set_header('Content-Type', 'application/json');
			$request->set_body((string) wp_json_encode($body));
		}
		return rest_do_request($request);
	}

	private function loginAs(string $role): void {
		wp_set_current_user(self::factory()->user->create(['role' => $role]));
	}

	public function test_admins_get_values_and_locked_keys(): void {
		$this->loginAs('administrator');

		$response = $this->request('GET');

		$this->assertSame(200, $response->get_status());
		$this->assertSame([
			'values' => ['comments' => ['disable' => true, 'mode' => '404'], 'media' => ['logo' => 0]],
			'locked' => ['comments' => ['disable']],
			'invalidOverrides' => [],
		], $response->get_data());
	}

	public function test_admins_can_save_and_get_the_new_state_back(): void {
		$this->loginAs('administrator');

		$response = $this->request('POST', ['values' => ['comments' => ['mode' => 'home'], 'media' => ['logo' => 7]]]);

		$this->assertSame(200, $response->get_status());
		$this->assertSame('home', $response->get_data()['values']['comments']['mode']);
		$this->assertSame(7, $this->settings->get('media', 'logo'));
	}

	public function test_locked_values_cannot_be_changed(): void {
		$this->loginAs('administrator');

		$response = $this->request('POST', ['values' => ['comments' => ['disable' => false]]]);

		$this->assertSame(200, $response->get_status());
		$this->assertTrue($response->get_data()['values']['comments']['disable']);
	}

	public function test_invalid_values_are_rejected_with_a_message_and_nothing_is_stored(): void {
		$this->loginAs('administrator');

		$response = $this->request('POST', ['values' => ['media' => ['logo' => -5], 'comments' => ['mode' => 'home']]]);

		$this->assertSame(400, $response->get_status());
		$this->assertNotEmpty($response->as_error()?->get_error_message());
		$this->assertFalse(get_option(Settings::OPTION));
	}

	public function test_unknown_settings_are_rejected(): void {
		$this->loginAs('administrator');

		$this->assertSame(400, $this->request('POST', ['values' => ['nope' => ['x' => true]]])->get_status());
		$this->assertSame(400, $this->request('POST', ['values' => 'x'])->get_status());
		$this->assertSame(400, $this->request('POST', [])->get_status());
		$this->assertSame(400, $this->request('POST', ['values' => [true, false]])->get_status(), 'a list instead of modules');
		$this->assertSame(400, $this->request('POST', ['values' => ['comments' => ['disable' => ['nested' => true]]]])->get_status());
		$this->assertFalse(get_option(Settings::OPTION));
	}

	/**
	 * @dataProvider forbidden
	 */
	public function test_only_administrators_have_access(?string $role, int $status): void {
		if ($role !== null) {
			$this->loginAs($role);
		}

		$this->assertSame($status, $this->request('GET')->get_status());
		$this->assertSame($status, $this->request('POST', ['values' => ['media' => ['logo' => 7]]])->get_status());
		$this->assertFalse(get_option(Settings::OPTION));
	}

	/**
	 * @return array<string, array{string|null, int}>
	 */
	public static function forbidden(): array {
		return [
			'visitor' => [null, 401],
			'editor' => ['editor', 403],
		];
	}
}
