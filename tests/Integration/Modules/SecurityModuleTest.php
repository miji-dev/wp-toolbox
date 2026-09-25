<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Security\SecurityModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\RedirectException;
use WP_Error;
use WP_REST_Request;
use WP_User;
use WP_UnitTestCase;

final class SecurityModuleTest extends WP_UnitTestCase {
	private SecurityModule $module;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->module = new SecurityModule();
		$this->admin = self::factory()->user->create(['role' => 'administrator', 'user_login' => 'boss', 'user_pass' => 'correct-horse', 'user_email' => 'boss@example.org']);
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		unset($GLOBALS['pagenow']);
		parent::tear_down();
	}

	/**
	 * @param array<string, bool> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['security' => $values]));
	}

	private function catchRedirect(callable $fn): string {
		add_filter('wp_redirect', static function (string $location): never {
			throw new RedirectException($location);
		});
		try {
			$fn();
		} catch (RedirectException $e) {
			return $e->location;
		}
		$this->fail('no redirect');
	}

	public function test_off_changes_nothing(): void {
		// WordPress only offers application passwords over HTTPS or on local sites
		add_filter('wp_is_application_passwords_available', '__return_true', 5);
		$this->enable([]);

		$this->assertTrue(apply_filters('xmlrpc_enabled', true));
		$this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'))->get_status());
		$this->assertSame('incorrect_password', wp_authenticate('boss', 'wrong')->get_error_code());
		$this->assertTrue(user_can($this->admin, 'edit_plugins'));
		$this->assertTrue(wp_is_application_passwords_available());
		$this->assertSame([], apply_filters('wp_headers', []));
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

	// --- user enumeration -----------------------------------------------------------------------------

	public function test_rest_user_list_is_closed_for_visitors(): void {
		$this->enable(['block_user_enumeration' => true]);

		foreach (['/wp/v2/users', '/wp/v2/users/' . $this->admin, '/wp/v2/users/me'] as $route) {
			$this->assertSame(401, rest_do_request(new WP_REST_Request('GET', $route))->get_status(), $route);
		}
		$this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'))->get_status(), 'other routes are unaffected');
	}

	public function test_logged_in_users_can_still_list_users(): void {
		// the block editor loads the author list from /wp/v2/users
		$this->enable(['block_user_enumeration' => true]);
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

		$this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'))->get_status());
	}

	public function test_author_id_urls_are_404_for_visitors(): void {
		self::factory()->post->create(['post_author' => $this->admin]);
		$this->enable(['block_user_enumeration' => true]);

		$this->go_to(home_url('/?author=' . $this->admin));
		$this->module->blockAuthorQuery();

		$this->assertTrue(is_404());
		$this->assertSame(0, has_action('template_redirect', [$this->module, 'blockAuthorQuery']), 'before redirect_canonical reveals /author/<login>/');
	}

	public function test_author_archives_by_name_still_work(): void {
		$this->set_permalink_structure('/%postname%/'); // plain permalinks use ?author=ID for archives too
		self::factory()->post->create(['post_author' => $this->admin]);
		$this->enable(['block_user_enumeration' => true]);

		$this->go_to(get_author_posts_url($this->admin));
		$this->module->blockAuthorQuery();

		$this->assertTrue(is_author());
		$this->assertFalse(is_404());
	}

	// --- login -----------------------------------------------------------------------------------------

	public function test_wrong_username_and_wrong_password_look_the_same(): void {
		$this->enable(['generic_login_errors' => true]);

		$unknown = wp_authenticate('nobody', 'whatever');
		$wrong = wp_authenticate('boss', 'wrong');
		$wrong_email = wp_authenticate('boss@example.org', 'wrong');

		$this->assertInstanceOf(WP_Error::class, $unknown);
		$this->assertSame($unknown->get_error_code(), $wrong->get_error_code());
		$this->assertSame($unknown->get_error_message(), $wrong->get_error_message());
		$this->assertSame($unknown->get_error_message(), $wrong_email->get_error_message());
		$this->assertStringNotContainsString('boss', $wrong->get_error_message());
	}

	public function test_correct_logins_and_other_errors_are_untouched(): void {
		$this->enable(['generic_login_errors' => true]);

		$this->assertInstanceOf(WP_User::class, wp_authenticate('boss', 'correct-horse'));
		$this->assertSame('empty_password', wp_authenticate('boss', '')->get_error_code());
	}

	public function test_lost_password_does_not_reveal_whether_an_account_exists(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->enable(['generic_login_errors' => true]);

		foreach (['nobody', 'nobody@example.org'] as $login) {
			$location = $this->catchRedirect(static fn () => retrieve_password($login));
			$this->assertSame(add_query_arg('checkemail', 'confirm', wp_login_url()), $location, $login);
		}
	}

	public function test_lost_password_for_existing_accounts_works_as_usual(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->enable(['generic_login_errors' => true]);
		reset_phpmailer_instance();

		$this->assertTrue(retrieve_password('boss'));
		$this->assertStringContainsString('boss', tests_retrieve_phpmailer_instance()->get_sent()->body);
	}

	public function test_lost_password_outside_wp_login_is_left_alone(): void {
		// e.g. WooCommerce' own form: it has its own handling and must not be redirected to wp-login.php
		$GLOBALS['pagenow'] = 'index.php';
		$this->enable(['generic_login_errors' => true]);

		$this->assertSame('invalidcombo', retrieve_password('nobody')->get_error_code());
	}

	// --- application passwords, file editor --------------------------------------------------------------

	public function test_application_passwords_can_be_disabled(): void {
		add_filter('wp_is_application_passwords_available', '__return_true', 5);
		$this->enable(['disable_application_passwords' => true]);

		$this->assertFalse(wp_is_application_passwords_available());
	}

	public function test_file_editors_are_removed_for_everyone(): void {
		$this->enable(['disable_file_editor' => true]);

		foreach (['edit_plugins', 'edit_themes', 'edit_files'] as $cap) {
			$this->assertFalse(user_can($this->admin, $cap), $cap);
		}
		$this->assertTrue(user_can($this->admin, 'install_plugins'), 'installing and updating plugins stays possible');
		$this->assertTrue(user_can($this->admin, 'update_themes'));
	}

	// --- headers -------------------------------------------------------------------------------------------

	public function test_security_headers_are_added(): void {
		$this->enable(['security_headers' => true]);

		$headers = apply_filters('wp_headers', ['Content-Type' => 'text/html']);

		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
		$this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
		$this->assertSame("frame-ancestors 'self'", $headers['Content-Security-Policy']);
		$this->assertNotFalse(has_action('send_headers', [$this->module, 'removePoweredByHeader']));
	}

	public function test_existing_headers_are_not_overwritten(): void {
		$this->enable(['security_headers' => true]);

		$headers = apply_filters('wp_headers', ['Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'self'"]);

		$this->assertSame('no-referrer', $headers['Referrer-Policy']);
		$this->assertSame("default-src 'self'", $headers['Content-Security-Policy'], 'a CSP set by someone else stays as it is');
	}

	public function test_every_setting_is_explained(): void {
		foreach ($this->module->fields() as $field) {
			$this->assertFalse($field->default, $field->key);
			$this->assertNotEmpty($field->description, $field->key);
			$this->assertNotEmpty($field->why, $field->key);
		}
	}
}
