<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Maintenance\MaintenanceModule;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;
use WP_UnitTestCase;
use WPDieException;

final class MaintenanceModuleTest extends WP_UnitTestCase {
	private MaintenanceModule $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new MaintenanceModule();
		update_option('blogname', 'Hofladen');
		// feeds use WordPress' XML error page (also 503); the test library only catches the HTML/JSON ones
		add_filter('wp_die_xml_handler', fn (): array => [$this, 'wp_die_handler']);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function enable(array $values = []): void {
		$this->module->register(new Settings([$this->module], ['maintenance' => ['enabled' => true] + $values]));
	}

	private static function local(string $modify): string {
		return (new \DateTimeImmutable('now', wp_timezone()))->modify($modify)->format('Y-m-d\TH:i');
	}

	/**
	 * @return WPDieException|null the maintenance page, or null if the request went through
	 */
	private function visit(string $url = '/'): ?WPDieException {
		$this->go_to(home_url($url));
		if (has_action('template_redirect', [$this->module, 'maybeBlock']) === false) {
			return null;
		}
		try {
			$this->module->maybeBlock(); // not do_action(): core's canonical redirect would exit()
		} catch (WPDieException $e) {
			return $e;
		}
		return null;
	}

	public function test_off_changes_nothing(): void {
		$this->module->register(new Settings([$this->module], []));

		$this->assertNull($this->visit());
		$this->assertFalse(has_action('admin_bar_menu', [$this->module, 'addAdminBarNode']));
	}

	// --- visitors ----------------------------------------------------------------------------------------

	public function test_visitors_get_a_503_page(): void {
		$this->enable();

		$page = $this->visit();

		$this->assertNotNull($page);
		$this->assertSame(503, $page->getCode());
		$this->assertStringContainsString('Hofladen', $page->getMessage());
		$this->assertSame(PHP_INT_MIN, has_action('template_redirect', [$this->module, 'maybeBlock']), 'before any redirect could reveal content');
	}

	public function test_every_frontend_url_is_covered(): void {
		$post = self::factory()->post->create();
		$this->enable();

		foreach (['/?p=' . $post, '/?feed=rss2', '/?s=test', '/?page_id=999999'] as $url) {
			$this->assertNotNull($this->visit($url), $url);
		}
	}

	public function test_robots_txt_stays_reachable(): void {
		// search engines treat an unreachable robots.txt as "don't crawl anything"
		$this->enable();

		$this->assertNull($this->visit('/?robots=1'));
	}

	public function test_the_message_is_shown_escaped_with_line_breaks(): void {
		$this->enable(['message' => "Wir bauen um.\n<script>alert(1)</script>"]);

		$html = $this->visit()?->getMessage() ?? '';

		$this->assertStringContainsString('Wir bauen um.<br />', $html);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}

	public function test_a_default_message_is_shown(): void {
		$this->enable();

		$this->assertStringContainsString('maintenance', $this->visit()?->getMessage() ?? '');
	}

	// --- who sees the site ---------------------------------------------------------------------------------

	public function test_administrators_always_see_the_site(): void {
		$this->enable();
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

		$this->assertNull($this->visit());
	}

	public function test_selected_roles_see_the_site_others_dont(): void {
		$this->enable(['access_roles' => ['editor']]);

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->assertNull($this->visit(), 'editor');

		wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
		$this->assertNotNull($this->visit(), 'subscriber');
	}

	// --- end date -------------------------------------------------------------------------------------------

	public function test_end_date_is_shown_and_sent_as_retry_after(): void {
		$end = self::local('+2 hours');
		$this->enable(['end' => $end]);

		$this->assertEqualsWithDelta(2 * HOUR_IN_SECONDS, $this->module->retryAfter(), 60);
		$expected = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (new \DateTimeImmutable($end, wp_timezone()))->getTimestamp());
		$this->assertStringContainsString((string) $expected, $this->visit()?->getMessage() ?? '');
	}

	public function test_without_end_date_retry_after_is_one_hour(): void {
		$this->enable();

		$this->assertSame(HOUR_IN_SECONDS, $this->module->retryAfter());
	}

	public function test_maintenance_ends_automatically_after_the_end_date(): void {
		$this->enable(['end' => self::local('-1 minute')]);

		$this->assertNull($this->visit());
		$this->assertFalse(has_action('admin_bar_menu', [$this->module, 'addAdminBarNode']));
	}

	public function test_impossible_dates_count_as_no_end_date(): void {
		// the settings pattern accepts 31 February; it must neither end maintenance nor break anything
		$this->enable(['end' => '2026-02-31T10:00']);

		$this->assertNotNull($this->visit());
		$this->assertSame(HOUR_IN_SECONDS, $this->module->retryAfter());
	}

	// --- admin bar --------------------------------------------------------------------------------------------

	public function test_admin_bar_shows_that_maintenance_is_on(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->enable();
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$bar = new WP_Admin_Bar();

		$this->module->addAdminBarNode($bar);

		$node = $bar->get_node('wptb-maintenance');
		$this->assertNotNull($node);
		$this->assertStringContainsString('Maintenance', (string) $node->title);
		$this->assertStringContainsString('page=wp-toolbox', (string) $node->href);
	}

	public function test_the_admin_bar_node_links_nowhere_for_users_who_cant_change_it(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->enable(['access_roles' => ['editor']]);
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$bar = new WP_Admin_Bar();

		$this->module->addAdminBarNode($bar);

		$this->assertFalse($bar->get_node('wptb-maintenance')?->href);
	}

	public function test_every_setting_is_explained(): void {
		foreach ($this->module->fields() as $field) {
			$this->assertNotEmpty($field->description, $field->key);
			$this->assertNotEmpty($field->why, $field->key);
		}
	}
}
