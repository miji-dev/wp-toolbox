<?php

declare(strict_types=1);

namespace Miji\Toolbox;

use Miji\Toolbox\Modules\Admin\AdminModule;
use Miji\Toolbox\Modules\Blog\BlogModule;
use Miji\Toolbox\Modules\Comments\CommentsModule;
use Miji\Toolbox\Modules\Dashboard\DashboardModule;
use Miji\Toolbox\Modules\Environment\EnvironmentModule;
use Miji\Toolbox\Modules\Head\HeadModule;
use Miji\Toolbox\Modules\Login\LoginModule;
use Miji\Toolbox\Modules\Maintenance\MaintenanceModule;
use Miji\Toolbox\Modules\Media\MediaModule;
use Miji\Toolbox\Modules\Security\SecurityModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Updater\GitHubUpdater;

final class Plugin {
	public const VERSION = '4.0.0-alpha.1';
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
	 * Entry point, called from the main plugin file on init (priority 0): translations can be loaded,
	 * other plugins and the theme are set up, and post types registered at the default priority come later.
	 */
	public static function boot(string $file): void {
		load_plugin_textdomain('wptb', false, dirname(plugin_basename($file)) . '/languages');

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
		return [
			new CommentsModule(),
			new BlogModule(),
			new HeadModule(),
			new SecurityModule(),
			new AdminModule(),
			new DashboardModule(),
			new MediaModule(),
			new LoginModule(),
			new MaintenanceModule(),
			new EnvironmentModule(),
		];
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
