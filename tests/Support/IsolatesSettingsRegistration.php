<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Support;

use Miji\Toolbox\Settings\Settings;

/**
 * The test bootstrap loads the real plugin, which registers wptb_settings with its own schema.
 * Tests that build their own Settings remove that registration first and put it back afterwards.
 * The WP test case restores hooks after every test, but not the settings registry.
 */
trait IsolatesSettingsRegistration {
	/** @var array<string, mixed>|null */
	private ?array $registeredSettingsBackup = null;

	protected function isolateSettingsRegistration(): void {
		global $wp_registered_settings;
		$this->registeredSettingsBackup ??= $wp_registered_settings;
		unset($wp_registered_settings[Settings::OPTION]);
		remove_all_filters('sanitize_option_' . Settings::OPTION);
		remove_all_filters('default_option_' . Settings::OPTION);
	}

	protected function restoreSettingsRegistration(): void {
		global $wp_registered_settings;
		if ($this->registeredSettingsBackup !== null) {
			$wp_registered_settings = $this->registeredSettingsBackup;
			$this->registeredSettingsBackup = null;
		}
	}
}
