<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Migration;

use Miji\Toolbox\Migration\Migration;
use Miji\Toolbox\Plugin;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_UnitTestCase;

/**
 * Update from 3.x: tested against the options of a real wp-toolbox 3.2.5 install (tests/fixtures).
 */
final class MigrationTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	private Settings $settings;
	/** @var list<string> */
	private array $unregisteredStrings = [];

	public function set_up(): void {
		parent::set_up();
		$this->isolateSettingsRegistration();
		$this->settings = new Settings(Plugin::modules());
		delete_option(Migration::VERSION_OPTION);
	}

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		parent::tear_down();
	}

	private function migration(): Migration {
		return new Migration($this->settings, function (string $context, string $name): void {
			$this->unregisteredStrings[] = "$context/$name";
		});
	}

	/**
	 * Writes the captured 3.2.5 options into the database as they were stored (raw, serialized).
	 *
	 * @param array<string, string> $replace option => value to use instead
	 * @return array<string, mixed> the fixture
	 */
	private function loadLegacyInstall(array $replace = [], string $prefix = 'wptb'): array {
		global $wpdb;
		$fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/legacy-3.2.5.json'), true);
		foreach ($fixture['options'] as $name => $row) {
			$name = $prefix === 'wptb' ? $name : str_replace(['wptb_', '-wptb'], ["{$prefix}_", "-$prefix"], $name);
			$wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $replace[$name] ?? $row['value'], 'autoload' => $row['autoload']]);
		}
		foreach ($fixture['cron'] as $hook) {
			wp_schedule_event(time(), 'twicedaily', str_replace('-wptb', "-$prefix", $hook));
		}
		wp_cache_flush();
		return $fixture;
	}

	private function image(): int {
		$id = self::factory()->attachment->create_object(['file' => 'logo.png', 'post_mime_type' => 'image/png']);
		wp_update_attachment_metadata($id, ['width' => 128, 'height' => 128, 'file' => 'logo.png']);
		return $id;
	}

	/**
	 * @return list<string> legacy option names still in the database
	 */
	private static function leftovers(): array {
		global $wpdb;
		return $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name IN ('adminbar_position','Background_Color_color','disable_comments','disable_gutenberg','disable_posts','enable_maintenance','Favicon_media','Logo_media','only_elementor')
			OR option_name LIKE 'external\\_updates-%'
			OR option_name LIKE 'swtb\\_%'
			OR (option_name LIKE 'wptb\\_%' AND option_name NOT IN ('wptb_settings', 'wptb_db_version', 'wptb_migration_notice'))"
		);
	}

	// --- settings ------------------------------------------------------------------------------------

	public function test_settings_that_were_on_are_carried_over(): void {
		$logo = $this->image();
		$this->loadLegacyInstall(['Logo_media' => (string) $logo]);

		$this->migration()->run();

		$this->assertTrue($this->settings->get('comments', 'disable'));
		$this->assertTrue($this->settings->get('blog', 'disable_posts'));
		$this->assertSame('custom', $this->settings->get('login', 'logo'));
		$this->assertSame($logo, $this->settings->get('login', 'logo_image'));
		$this->assertSame('#123abc', $this->settings->get('login', 'background_color'));
		$this->assertTrue($this->settings->get('elementor', 'open_in_elementor'));
		// "Disable Gutenberg" applied to all content
		$classic = $this->settings->get('editor', 'classic_editor_for');
		$this->assertIsArray($classic);
		$this->assertContains('post', $classic);
		$this->assertContains('page', $classic);
	}

	public function test_settings_that_were_off_keep_the_new_defaults(): void {
		$this->loadLegacyInstall(['disable_comments' => '', 'disable_gutenberg' => '', 'only_elementor' => '', 'Background_Color_color' => '']);

		$this->migration()->run();

		$this->assertFalse($this->settings->get('comments', 'disable'));
		$this->assertSame([], $this->settings->get('editor', 'classic_editor_for'));
		$this->assertTrue($this->settings->get('elementor', 'open_in_elementor'), 'v4 default');
		$this->assertSame('', $this->settings->get('login', 'background_color'));
	}

	public function test_a_logo_that_is_gone_or_not_an_image_is_ignored(): void {
		$pdf = self::factory()->attachment->create_object(['file' => 'x.pdf', 'post_mime_type' => 'application/pdf']);
		$this->loadLegacyInstall(['Logo_media' => (string) $pdf]);

		$this->migration()->run();

		$this->assertSame('site', $this->settings->get('login', 'logo'), 'v4 default: the site\'s own logo');
		$this->assertSame(0, $this->settings->get('login', 'logo_image'));
	}

	public function test_locked_settings_are_not_overwritten(): void {
		$this->settings = new Settings(Plugin::modules(), ['comments' => ['disable' => false]]);
		$this->loadLegacyInstall();

		$this->migration()->run();

		$this->assertFalse($this->settings->get('comments', 'disable'));
		$this->assertTrue($this->settings->get('blog', 'disable_posts'), 'the rest is migrated');
	}

	// --- cleanup ---------------------------------------------------------------------------------------

	public function test_nothing_of_3x_is_left_behind(): void {
		$this->loadLegacyInstall();
		$this->assertNotEmpty(self::leftovers());
		$this->assertNotFalse(wp_next_scheduled('puc_cron_check_updates-wptb'));

		$this->migration()->run();

		$this->assertSame([], self::leftovers());
		$this->assertFalse(wp_next_scheduled('puc_cron_check_updates-wptb'), 'the old update checker\'s cron job');
		$this->assertSame(Migration::VERSION, (int) get_option(Migration::VERSION_OPTION));
	}

	public function test_sw_toolbox_leftovers_are_removed_too(): void {
		// sites that went sw-toolbox → wp-toolbox 3.x can still have the old names
		$this->loadLegacyInstall([], 'swtb');

		$this->migration()->run();

		$this->assertSame([], self::leftovers());
		$this->assertFalse(wp_next_scheduled('puc_cron_check_updates-swtb'));
		$this->assertTrue($this->settings->get('comments', 'disable'), 'the unprefixed settings are the same in sw-toolbox');
	}

	public function test_translations_of_the_cookie_banner_texts_are_removed_from_wpml(): void {
		$this->loadLegacyInstall();

		$this->migration()->run();

		$this->assertContains('admin_texts_wptb_cookie_banner_text/wptb_cookie_banner_text', $this->unregisteredStrings);
		$this->assertContains('admin_texts_swtb_cookie_banner_text/swtb_cookie_banner_text', $this->unregisteredStrings, 'sw-toolbox registered the same 13 texts');
		$this->assertCount(26, $this->unregisteredStrings);
	}

	// --- safety -----------------------------------------------------------------------------------------

	public function test_runs_only_once(): void {
		$this->loadLegacyInstall();
		$this->migration()->run();
		$this->settings->update(['comments' => ['disable' => false]]);

		// e.g. a backup of the old options restored by someone: not migrated again over newer settings
		update_option('disable_comments', '1');
		$this->migration()->run();

		$this->assertFalse($this->settings->get('comments', 'disable'));
	}

	public function test_a_site_without_3x_is_left_alone(): void {
		// generic names like these may belong to another plugin; without traces of 3.x they are not touched
		update_option('disable_comments', '1');
		update_option('disable_posts', 'yes');

		$this->migration()->run();

		$this->assertSame('1', get_option('disable_comments'));
		$this->assertSame('yes', get_option('disable_posts'));
		$this->assertFalse($this->settings->get('comments', 'disable'));
		$this->assertFalse(get_option(Migration::NOTICE_OPTION));
		$this->assertSame(Migration::VERSION, (int) get_option(Migration::VERSION_OPTION), 'fresh installs are marked as done');
	}

	public function test_runs_after_everything_is_loaded(): void {
		// post types (for "Disable Gutenberg") are registered on init
		$migration = $this->migration();
		$migration->register();

		$this->assertSame(10, has_action('wp_loaded', [$migration, 'run']));
	}

	// --- what needs attention -----------------------------------------------------------------------------

	public function test_removed_features_that_were_in_use_are_reported(): void {
		$this->loadLegacyInstall();
		$old = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<p>x</p>[wptb-yt id="dQw4w9WgXcQ"]']);
		$map = self::factory()->post->create(['post_type' => 'page']);
		update_post_meta($map, '_elementor_data', wp_slash('[{"id":"a1","elType":"widget","widgetType":"wptb-gm","settings":[]}]'));
		$sw = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_content' => '[swtb-ga-optout]']);
		self::factory()->post->create(['post_type' => 'page', 'post_content' => 'Nothing old']);
		$trashed = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'trash', 'post_content' => '[wptb-gm]']);

		$this->migration()->run();

		$notice = get_option(Migration::NOTICE_OPTION);
		$this->assertIsArray($notice);
		$this->assertTrue($notice['maintenance'], 'maintenance mode was on: the site is public now');
		$this->assertTrue($notice['analytics'], 'Google Analytics and the cookie banner were on');
		$this->assertEqualsCanonicalizing([$old, $map, $sw], $notice['pages']);
		$this->assertNotContains($trashed, $notice['pages']);
	}

	public function test_no_notice_when_nothing_needs_attention(): void {
		$this->loadLegacyInstall(['enable_maintenance' => '', 'wptb_ga_activated' => '']);

		$this->migration()->run();

		$this->assertFalse(get_option(Migration::NOTICE_OPTION));
	}
}
