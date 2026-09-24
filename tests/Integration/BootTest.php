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

	public function test_boot_happens_after_other_plugins_are_loaded(): void {
		// modules may depend on other plugins (Elementor) that initialise on plugins_loaded
		$this->assertSame(1, did_action('plugins_loaded'));
		$this->assertTrue(class_exists(Plugin::class, false));
	}
}
