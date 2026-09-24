<?php

declare(strict_types=1);

namespace Miji\Toolbox;

use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Updater\GitHubUpdater;

final class Plugin {
	public const VERSION = '4.0.0-dev';
	public const REPO = 'miji-dev/wp-toolbox';

	private readonly Settings $settings;

	/**
	 * @param list<Module> $modules
	 * @param array<mixed> $overrides locked settings, see Settings
	 */
	public function __construct(private readonly array $modules, array $overrides = []) {
		$this->settings = new Settings($modules, $overrides);
	}

	/**
	 * Entry point, called from the main plugin file on plugins_loaded.
	 */
	public static function boot(string $file): void {
		$overrides = defined('WPTB_SETTINGS') && is_array(WPTB_SETTINGS) ? WPTB_SETTINGS : [];

		(new self(self::modules(), $overrides))->register();

		(new GitHubUpdater(
			plugin_basename($file),
			self::REPO,
			beta: defined('WPTB_UPDATE_CHANNEL') && WPTB_UPDATE_CHANNEL === 'beta',
		))->register();
	}

	/**
	 * @return list<Module>
	 */
	public static function modules(): array {
		return [];
	}

	public function register(): void {
		foreach ($this->modules as $module) {
			if ($module->isAvailable()) {
				$module->register($this->settings);
			}
		}

		add_action('init', [$this->settings, 'registerSetting']);
	}

	public function settings(): Settings {
		return $this->settings;
	}
}
