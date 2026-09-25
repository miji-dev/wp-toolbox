<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Security\HeadersFile;
use Miji\Toolbox\Modules\Security\SecurityModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\DefaultsOff;
use WP_UnitTestCase;

final class SecurityModuleTest extends WP_UnitTestCase {
	private SecurityModule $module;
	private string $htaccess;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->htaccess = (string) wp_tempnam('htaccess');
		$this->module = new SecurityModule(new HeadersFile($this->htaccess, apache: true));
		$this->admin = self::factory()->user->create(['role' => 'administrator']);
	}

	public function tear_down(): void {
		@unlink($this->htaccess);
		parent::tear_down();
	}

	/**
	 * @param array<string, bool> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['security' => DefaultsOff::with('security', $values)]));
	}

	public function test_off_changes_nothing(): void {
		$this->enable([]);
		$this->module->syncHeadersFile();

		$this->assertTrue(apply_filters('xmlrpc_enabled', true));
		$this->assertTrue(user_can($this->admin, 'edit_plugins'));
		$this->assertSame([], apply_filters('wp_headers', []));
		$this->assertStringNotContainsString('wp-toolbox', (string) file_get_contents($this->htaccess));
	}

	// --- XML-RPC -------------------------------------------------------------------------------------

	public function test_xmlrpc_is_disabled_completely(): void {
		$this->enable(['disable_xmlrpc' => true]);

		$this->assertFalse(apply_filters('xmlrpc_enabled', true));
		$this->assertSame([], apply_filters('xmlrpc_methods', ['system.multicall' => 'x', 'pingback.ping' => 'y', 'demo.sayHello' => 'z']));
		$this->assertSame(['Content-Type' => 'text/html'], apply_filters('wp_headers', ['X-Pingback' => 'x', 'Content-Type' => 'text/html']));
		$this->assertSame(1, has_action('init', [$this->module, 'blockXmlrpcRequest']), 'xmlrpc.php requests are answered with 403 right after boot (init 0)');
	}

	public function test_xmlrpc_requests_get_a_plain_403_page(): void {
		// at init the XML-RPC server doesn't exist yet, so WordPress' XML-RPC die handler would print nothing (200)
		$this->enable(['disable_xmlrpc' => true]);

		$this->assertSame('_default_wp_die_handler', apply_filters('wp_die_xmlrpc_handler', '_xmlrpc_wp_die_handler'));
	}

	// --- file editor ------------------------------------------------------------------------------------

	public function test_file_editors_are_removed_for_everyone(): void {
		$this->enable(['disable_file_editor' => true]);

		foreach (['edit_plugins', 'edit_themes', 'edit_files'] as $cap) {
			$this->assertFalse(user_can($this->admin, $cap), $cap);
		}
		$this->assertTrue(user_can($this->admin, 'install_plugins'), 'installing and updating plugins stays possible');
		$this->assertTrue(user_can($this->admin, 'update_themes'));
	}

	// --- headers -------------------------------------------------------------------------------------------

	public function test_security_headers_are_sent_from_php(): void {
		$this->enable(['security_headers' => true]);

		$headers = apply_filters('wp_headers', ['Content-Type' => 'text/html']);

		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
		$this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
		$this->assertArrayNotHasKey('Content-Security-Policy', $headers, 'never set: it could replace a site\'s real policy');
		$this->assertNotFalse(has_action('send_headers', [$this->module, 'removePoweredByHeader']));
	}

	public function test_our_values_replace_other_values_like_the_server_rules_do(): void {
		$this->enable(['security_headers' => true]);

		$headers = apply_filters('wp_headers', ['X-Frame-Options' => 'ALLOW-FROM x', 'Content-Security-Policy' => "default-src 'self'"]);

		$this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
		$this->assertSame("default-src 'self'", $headers['Content-Security-Policy'], 'other headers are untouched');
	}

	public function test_server_rules_are_written_in_the_admin_and_after_saving(): void {
		$this->enable(['security_headers' => true]);

		$this->module->syncHeadersFile();
		$this->assertStringContainsString('# BEGIN wp-toolbox', (string) file_get_contents($this->htaccess));

		foreach (['admin_init', 'add_option_' . Settings::OPTION, 'update_option_' . Settings::OPTION] as $hook) {
			$this->assertNotFalse(has_action($hook, [$this->module, 'syncHeadersFile']), $hook);
		}
	}

	public function test_server_rules_are_removed_when_switched_off(): void {
		(new HeadersFile($this->htaccess, apache: true))->sync(true);
		$this->enable([]);

		$this->module->syncHeadersFile();

		$this->assertStringNotContainsString('wp-toolbox', (string) file_get_contents($this->htaccess));
	}
}
