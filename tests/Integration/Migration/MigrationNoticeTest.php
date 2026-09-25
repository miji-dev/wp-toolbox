<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Migration;

use Miji\Toolbox\Migration\LegacyShortcodes;
use Miji\Toolbox\Migration\Migration;
use Miji\Toolbox\Migration\MigrationNotice;
use Miji\Toolbox\Tests\Support\RedirectException;
use WP_UnitTestCase;
use WPDieException;

final class MigrationNoticeTest extends WP_UnitTestCase {
	private MigrationNotice $notice;

	public function set_up(): void {
		parent::set_up();
		$this->notice = new MigrationNotice();
		$this->notice->register();
	}

	private function output(): string {
		ob_start();
		$this->notice->render();
		return (string) ob_get_clean();
	}

	private function loginAs(string $role): void {
		wp_set_current_user(self::factory()->user->create(['role' => $role]));
	}

	public function test_nothing_is_shown_without_notice(): void {
		$this->loginAs('administrator');

		$this->assertSame('', $this->output());
	}

	public function test_administrators_see_what_needs_attention(): void {
		$this->loginAs('administrator');
		$page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Anfahrt <script>']);
		update_option(Migration::NOTICE_OPTION, ['maintenance' => true, 'analytics' => true, 'pages' => [$page]]);

		$html = $this->output();

		$this->assertStringContainsString('Maintenance mode', $html);
		$this->assertStringContainsString('Elementor', $html);
		$this->assertStringContainsString('Google Analytics', $html);
		$this->assertStringContainsString(esc_url((string) get_edit_post_link($page)), $html);
		$this->assertStringContainsString('Anfahrt &lt;script&gt;', $html);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('action=wptb_dismiss_migration_notice', $html);
		$this->assertStringContainsString('_wpnonce=', $html);
	}

	public function test_only_the_parts_that_apply_are_shown(): void {
		$this->loginAs('administrator');
		update_option(Migration::NOTICE_OPTION, ['maintenance' => false, 'analytics' => true, 'pages' => []]);

		$html = $this->output();

		$this->assertStringContainsString('Google Analytics', $html);
		$this->assertStringNotContainsString('Maintenance mode', $html);
	}

	public function test_long_page_lists_are_shortened(): void {
		$this->loginAs('administrator');
		update_option(Migration::NOTICE_OPTION, ['maintenance' => false, 'analytics' => false, 'pages' => self::factory()->post->create_many(25, ['post_type' => 'page'])]);

		$html = $this->output();

		$this->assertSame(20, substr_count($html, 'post.php?post='));
		$this->assertStringContainsString('5 more', $html);
	}

	public function test_other_users_see_nothing(): void {
		$this->loginAs('editor');
		update_option(Migration::NOTICE_OPTION, ['maintenance' => true, 'analytics' => false, 'pages' => []]);

		$this->assertSame('', $this->output());
	}

	public function test_dismissing_needs_a_valid_nonce_and_removes_the_notice(): void {
		$this->loginAs('administrator');
		update_option(Migration::NOTICE_OPTION, ['maintenance' => true, 'analytics' => false, 'pages' => []]);

		$_REQUEST['_wpnonce'] = 'wrong';
		try {
			$this->notice->dismiss();
			$this->fail('no check');
		} catch (WPDieException) {
			$this->assertNotFalse(get_option(Migration::NOTICE_OPTION));
		}

		$_REQUEST['_wpnonce'] = wp_create_nonce(MigrationNotice::ACTION);
		add_filter('wp_redirect', static function (string $location): never {
			throw new RedirectException($location);
		});
		try {
			$this->notice->dismiss();
		} catch (RedirectException) {
		}
		unset($_REQUEST['_wpnonce']);

		$this->assertFalse(get_option(Migration::NOTICE_OPTION));
		$this->assertNotFalse(has_action('admin_post_' . MigrationNotice::ACTION, [$this->notice, 'dismiss']));
	}

	public function test_editors_cannot_dismiss(): void {
		$this->loginAs('editor');
		update_option(Migration::NOTICE_OPTION, ['maintenance' => true, 'analytics' => false, 'pages' => []]);
		$_REQUEST['_wpnonce'] = wp_create_nonce(MigrationNotice::ACTION);

		try {
			$this->notice->dismiss();
			$this->fail('no capability check');
		} catch (WPDieException) {
			$this->assertNotFalse(get_option(Migration::NOTICE_OPTION));
		} finally {
			unset($_REQUEST['_wpnonce']);
		}
	}

	public function test_old_shortcodes_render_nothing_instead_of_their_raw_text(): void {
		(new LegacyShortcodes())->register();

		$this->assertSame('<p>Hallo </p>', trim(do_shortcode('<p>Hallo [wptb-yt id="dQw4w9WgXcQ"]</p>')));
		$this->assertSame('', do_shortcode('[swtb-gm lat="1"][/swtb-gm]'));
		$this->assertSame('', do_shortcode('[wptb-ga-optout]'));
		$this->assertSame('', do_shortcode('[swtb-settings-button]'));
	}
}
