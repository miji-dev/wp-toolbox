<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Environment\EnvironmentModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\DefaultsOff;
use WP_Admin_Bar;
use WP_UnitTestCase;

final class EnvironmentModuleTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function module(string $environment, array $values = []): EnvironmentModule {
		$module = new EnvironmentModule($environment);
		$module->register(new Settings([$module], ['environment' => DefaultsOff::with('environment', $values)]));
		return $module;
	}

	/**
	 * @return array{to: string, subject: string, cc: int, bcc: int}|null the mail as PHPMailer would send it
	 */
	private static function sent(): ?array {
		$mailer = tests_retrieve_phpmailer_instance();
		$mail = $mailer->get_sent();
		if ($mail === false) {
			return null;
		}
		return [
			'to' => implode(',', array_column($mailer->getToAddresses(), 0)),
			'subject' => $mail->subject,
			'cc' => count($mailer->getCcAddresses()),
			'bcc' => count($mailer->getBccAddresses()),
		];
	}

	public function test_default_environment_is_the_one_wordpress_reports(): void {
		$this->assertSame(wp_get_environment_type(), (new EnvironmentModule())->environment());
	}

	public function test_off_changes_nothing(): void {
		$this->module('staging');

		$this->assertTrue(wp_mail('client@example.org', 'Hello', 'Body'));
		$this->assertSame('client@example.org', self::sent()['to'] ?? null);
		$this->assertArrayNotHasKey('noindex', apply_filters('wp_robots', []));
		$bar = new WP_Admin_Bar();
		do_action('admin_bar_menu', $bar);
		$this->assertNull($bar->get_node('wptb-environment'));
	}

	// --- badge -------------------------------------------------------------------------------------------

	/**
	 * @dataProvider environments
	 */
	public function test_badge_names_the_environment(string $environment, string $label): void {
		$module = $this->module($environment, ['badge' => 'all']);
		$bar = new WP_Admin_Bar();

		$module->addBadge($bar);

		$this->assertSame($label, $bar->get_node('wptb-environment')?->title);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function environments(): array {
		return [
			'production' => ['production', 'Live'],
			'staging' => ['staging', 'Staging'],
			'development' => ['development', 'Development'],
			'local' => ['local', 'Local'],
		];
	}

	public function test_badge_can_be_limited_to_non_production(): void {
		$module = $this->module('production', ['badge' => 'non_production']);

		$this->assertFalse(has_action('admin_bar_menu', [$module, 'addBadge']), 'nothing hooked on production');
		$this->assertNotFalse(has_action('admin_bar_menu', [$this->module('staging', ['badge' => 'non_production']), 'addBadge']));
	}

	public function test_badge_colours(): void {
		$this->module('staging', ['badge' => 'all']);
		wp_enqueue_style('admin-bar');
		add_filter('show_admin_bar', '__return_true');

		do_action('wp_enqueue_scripts');

		$css = implode('', (array) wp_styles()->get_data('admin-bar', 'after'));
		$this->assertStringContainsString('#wp-admin-bar-wptb-environment>.ab-item{background:#dba617;color:#1d2327}', $css);
		wp_styles()->registered['admin-bar']->extra = [];
	}

	// --- search engines ------------------------------------------------------------------------------------

	public function test_non_production_sites_are_never_indexed(): void {
		$this->module('staging', ['noindex' => true]);

		$this->assertSame(['noindex' => true, 'nofollow' => true], apply_filters('wp_robots', ['max-image-preview' => 'large']));
		$this->assertSame('noindex, nofollow', apply_filters('wp_headers', [])['X-Robots-Tag']);
	}

	public function test_production_is_not_touched_by_noindex(): void {
		$this->module('production', ['noindex' => true]);

		$this->assertSame(['max-image-preview' => 'large'], apply_filters('wp_robots', ['max-image-preview' => 'large']));
		$this->assertArrayNotHasKey('X-Robots-Tag', apply_filters('wp_headers', []));
	}

	// --- mail --------------------------------------------------------------------------------------------

	public function test_mail_can_be_blocked_without_errors_for_the_sender(): void {
		$this->module('staging', ['mail' => 'block']);

		$this->assertTrue(wp_mail('client@example.org', 'Order', 'Body'), 'forms and shops must not show "could not send"');
		$this->assertNull(self::sent());
	}

	public function test_blocked_mail_stays_blocked_if_another_plugin_overrides_the_block(): void {
		// e.g. a mail plugin that answers pre_wp_mail itself, ignoring what came before
		$this->module('staging', ['mail' => 'block']);
		add_filter('pre_wp_mail', '__return_null', 10);

		wp_mail('client@example.org', 'Order', 'Body', ['Cc: cc@example.org', 'Bcc: b@example.org']);

		// the real PHPMailer refuses to send without recipients; the test double records the attempt
		$sent = self::sent();
		$this->assertSame('', $sent['to'] ?? '');
		$this->assertSame(0, $sent['cc'] ?? 0);
		$this->assertSame(0, $sent['bcc'] ?? 0);
	}

	public function test_mail_can_be_redirected_to_one_address(): void {
		$this->module('development', ['mail' => 'redirect', 'mail_redirect_to' => 'dev@example.org']);

		wp_mail(['client@example.org', 'boss@example.org'], 'Order', 'Body', ['Cc: cc@example.org', 'BCC: b@example.org', 'Reply-To: r@example.org']);

		$sent = self::sent();
		$this->assertSame('dev@example.org', $sent['to'] ?? null);
		$this->assertSame('[Development] Order (to: client@example.org, boss@example.org)', $sent['subject'] ?? null);
		$this->assertSame(0, $sent['cc'] ?? null);
		$this->assertSame(0, $sent['bcc'] ?? null);
	}

	public function test_cc_in_a_header_string_is_removed_too(): void {
		$this->module('staging', ['mail' => 'redirect', 'mail_redirect_to' => 'dev@example.org']);

		wp_mail('client@example.org', 'Hi', 'Body', "From: shop@example.org\r\ncc: cc@example.org\nBcc: b@example.org");

		$this->assertSame(0, self::sent()['cc'] ?? null);
		$this->assertSame(0, self::sent()['bcc'] ?? null);
	}

	public function test_an_invalid_redirect_address_blocks_mail_instead(): void {
		// fail safe: never fall back to sending to the real recipients
		$this->module('staging', ['mail' => 'redirect', 'mail_redirect_to' => 'not-an-address']);

		wp_mail('client@example.org', 'Order', 'Body');

		$this->assertNull(self::sent());
	}

	public function test_production_mail_is_never_touched(): void {
		$this->module('production', ['mail' => 'block']);

		wp_mail('client@example.org', 'Order', 'Body');

		$this->assertSame('client@example.org', self::sent()['to'] ?? null);
	}
}
