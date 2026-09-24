<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Support;

use Miji\Toolbox\Settings\Settings;

/**
 * The test bootstrap loads the real plugin, which registers wptb_settings with its own schema.
 * Tests that build their own Settings remove that registration first. The WP test case restores
 * hooks (but not the settings registry) after every test, so both are cleared explicitly.
 */
trait IsolatesSettingsRegistration {
	protected function isolateSettingsRegistration(): void {
		global $wp_registered_settings;
		unset($wp_registered_settings[Settings::OPTION]);
		remove_all_filters('sanitize_option_' . Settings::OPTION);
		remove_all_filters('default_option_' . Settings::OPTION);
	}
}
