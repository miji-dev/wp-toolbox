<?php

declare(strict_types=1);

namespace Miji\Toolbox\Admin;

use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Presets;
use Miji\Toolbox\Settings\Settings;

/**
 * Settings → Toolbox. The page itself is a React app (assets/src/settings) rendered from data().
 */
final class SettingsPage {
	public const SLUG = 'wp-toolbox';
	public const HANDLE = 'wptb-settings';

	private ?string $hook = null;

	public function __construct(private readonly Settings $settings, private readonly string $pluginFile) {
	}

	public function register(): void {
		add_action('admin_menu', [$this, 'addPage']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_filter('plugin_action_links_' . plugin_basename($this->pluginFile), [$this, 'actionLinks']);
	}

	public function addPage(): void {
		$hook = add_options_page(__('Toolbox', 'wptb'), __('Toolbox', 'wptb'), 'manage_options', self::SLUG, [$this, 'render']);
		$this->hook = is_string($hook) ? $hook : null;
	}

	public function render(): void {
		printf(
			'<div class="wrap"><h1>%s</h1><div id="wptb-settings"><p>%s</p></div></div>',
			esc_html__('Toolbox', 'wptb'),
			esc_html__('Loading…', 'wptb'),
		);
	}

	public function enqueue(mixed $hook): void {
		if ($hook === null || $hook !== ($this->hook ?? 'settings_page_' . self::SLUG)) {
			return;
		}

		$dir = plugin_dir_path($this->pluginFile) . 'assets/build/';
		[$deps, $version] = self::asset($dir . 'settings.asset.php');
		$url = plugin_dir_url($this->pluginFile) . 'assets/build/';

		wp_enqueue_script(self::HANDLE, $url . 'settings.js', $deps, $version, true);
		wp_set_script_translations(self::HANDLE, 'wptb', plugin_dir_path($this->pluginFile) . 'languages');
		// JSON_HEX_TAG: labels and translations can't close the <script> element
		wp_add_inline_script(self::HANDLE, 'window.wptbSettings = ' . wp_json_encode($this->data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';', 'before');

		wp_enqueue_style('wp-components');
		if (is_readable($dir . 'settings.css')) {
			wp_enqueue_style(self::HANDLE, $url . 'settings.css', ['wp-components'], $version);
		}
		wp_enqueue_media(); // media picker for image settings
	}

	/**
	 * Script dependencies and version written by the build (@wordpress/scripts).
	 *
	 * @return array{list<non-empty-string>, string|null}
	 */
	private static function asset(string $file): array {
		$asset = is_readable($file) ? require $file : null;
		$deps = ['wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n'];
		if (is_array($asset) && is_array($asset['dependencies'] ?? null)) {
			$deps = array_values(array_filter($asset['dependencies'], static fn ($dep): bool => is_string($dep) && $dep !== ''));
		}
		$version = is_array($asset) && is_string($asset['version'] ?? null) ? $asset['version'] : null;
		return [$deps, $version];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$modules = [];
		foreach ($this->settings->modules() as $id => $module) {
			$modules[] = [
				'id' => $id,
				'title' => $module->title(),
				'description' => $module->description(),
				'available' => $module->isAvailable(),
				'fields' => array_map(static fn (Field $field): array => $field->toArray(), $module->fields()),
			];
		}

		return [
			'restPath' => SettingsController::NAMESPACE . SettingsController::ROUTE,
			'modules' => $modules,
			'state' => (new SettingsController($this->settings))->state(),
			'recommended' => Presets::recommended(),
		];
	}

	/**
	 * @param mixed $links
	 * @return mixed
	 */
	public function actionLinks(mixed $links): mixed {
		if (!is_array($links) || !current_user_can('manage_options')) {
			return $links;
		}
		$settings = sprintf('<a href="%s">%s</a>', esc_url(admin_url('options-general.php?page=' . self::SLUG)), esc_html__('Settings', 'wptb'));
		return ['settings' => $settings] + $links;
	}
}
