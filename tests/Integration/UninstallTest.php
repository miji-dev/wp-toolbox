<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration;

use Miji\Toolbox\Settings\Settings;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {
	public function test_uninstall_removes_everything_the_plugin_stored(): void {
		update_option(Settings::OPTION, ['x' => ['y' => true]]);
		update_option('wptb_db_version', 1);
		update_option('wptb_migration_notice', ['maintenance' => true]);
		set_site_transient('wptb_update_stable', ['release' => null]);
		set_site_transient('wptb_update_beta', ['release' => null]);
		update_option('unrelated_option', 'keep');

		if (!defined('WP_UNINSTALL_PLUGIN')) {
			define('WP_UNINSTALL_PLUGIN', 'wp-toolbox/wp-toolbox.php');
		}
		require WP_PLUGIN_DIR . '/wp-toolbox/uninstall.php';

		$this->assertFalse(get_option(Settings::OPTION));
		$this->assertFalse(get_option('wptb_db_version'));
		$this->assertFalse(get_option('wptb_migration_notice'));
		$this->assertFalse(get_site_transient('wptb_update_stable'));
		$this->assertFalse(get_site_transient('wptb_update_beta'));
		$this->assertSame('keep', get_option('unrelated_option'));
	}
}
