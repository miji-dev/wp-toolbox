<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration;

use Miji\Toolbox\Plugin;
use Miji\Toolbox\Settings\Settings;
use WP_UnitTestCase;

/**
 * The plugin as WordPress loads it in the test bootstrap (main file, plugins_loaded).
 */
final class BootTest extends WP_UnitTestCase {
	public function test_runs_on_the_expected_wordpress(): void {
		$this->assertSame('7.1', substr(get_bloginfo('version'), 0, 3));
		$this->assertSame('wp-toolbox/wp-toolbox.php', plugin_basename(WP_PLUGIN_DIR . '/wp-toolbox/wp-toolbox.php'));
	}

	public function test_header_version_matches_the_code(): void {
		$header = get_file_data(WP_PLUGIN_DIR . '/wp-toolbox/wp-toolbox.php', ['Version' => 'Version', 'UpdateURI' => 'Update URI', 'RequiresPHP' => 'Requires PHP']);

		$this->assertSame(Plugin::VERSION, $header['Version']);
		$this->assertSame('https://github.com/' . Plugin::REPO, $header['UpdateURI']);
		$this->assertSame('8.3', $header['RequiresPHP']);
	}

	public function test_updater_and_settings_are_wired_up(): void {
		$this->assertNotFalse(has_filter('update_plugins_github.com'));
		$this->assertNotFalse(has_filter('plugins_api'));
		$this->assertArrayHasKey(Settings::OPTION, get_registered_settings());
	}

	public function test_boots_early_on_init(): void {
		// after plugins_loaded/after_setup_theme (translations, other plugins), before post types register on init 10
		$this->assertNotFalse(has_action('init'));
		$this->assertTrue(class_exists(Plugin::class, false));
		$this->assertSame(1, did_action('init'));
	}

	public function test_all_modules_are_part_of_the_settings(): void {
		$ids = array_map(static fn ($m) => $m->id(), Plugin::modules());

		$this->assertSame(['comments', 'blog', 'head', 'security', 'admin', 'dashboard', 'media', 'login', 'maintenance', 'environment'], $ids);
		$this->assertSame($ids, array_unique($ids));
	}
}
