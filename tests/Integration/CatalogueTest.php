<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration;

use Miji\Toolbox\Plugin;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\FieldType;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_UnitTestCase;

/**
 * Rules for all settings of all modules together.
 */
final class CatalogueTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		parent::tear_down();
	}

	/**
	 * @return iterable<string, array{string, Field}>
	 */
	private static function fields(): iterable {
		foreach (Plugin::modules() as $module) {
			foreach ($module->fields() as $field) {
				yield $module->id() . '.' . $field->key => [$module->id(), $field];
			}
		}
	}

	public function test_every_setting_explains_what_how_and_why_in_full_sentences(): void {
		foreach (self::fields() as $name => [, $field]) {
			foreach (['what' => $field->what, 'how' => $field->how, 'why' => $field->why, 'sideEffects' => $field->sideEffects ?? '.'] as $text => $value) {
				$this->assertMatchesRegularExpression('/[.!?)"]$/u', $value, "$name: $text");
			}
		}
	}

	public function test_modules_explain_themselves_and_labels_are_unique(): void {
		foreach (Plugin::modules() as $module) {
			$this->assertNotSame('', $module->title());
			$this->assertNotSame('', $module->description());
			$labels = array_map(static fn (Field $f): string => $f->label, $module->fields());
			$this->assertSame($labels, array_unique($labels), $module->id());
		}
	}

	public function test_defaults_are_valid_settings(): void {
		$this->isolateSettingsRegistration();
		$settings = new Settings(Plugin::modules());
		$defaults = [];
		foreach (self::fields() as [$module, $field]) {
			$defaults[$module][$field->key] = $field->default;
		}

		$this->assertTrue($settings->update($defaults));
	}

	public function test_what_is_on_out_of_the_box(): void {
		// cleanups that are right for almost every site; everything that depends on the project is off
		$on = [];
		foreach (self::fields() as $name => [, $field]) {
			$isOn = match ($field->type) {
				FieldType::Bool => $field->default === true,
				FieldType::Multi => $field->default !== [],
				default => false,
			};
			if ($isOn) {
				$on[] = $name;
			}
		}

		$this->assertSame([
			'head.remove_generator',
			'head.remove_rsd',
			'head.remove_shortlink',
			'head.disable_emojis',
			'security.disable_xmlrpc',
			'security.disable_file_editor',
			'security.security_headers',
			'admin.hide_update_notices_for_non_admins',
			'admin.disable_admin_email_check',
			'dashboard.hide_widgets',
			'media.clean_filenames',
			'login.logo_links_home',
			'environment.noindex',
			'editor.disable_remote_patterns',
			'editor.disable_block_directory',
			'editor.disable_openverse',
			'elementor.open_in_elementor',
		], $on);
		$this->assertSame(['welcome_panel', 'dashboard_primary'], (new \Miji\Toolbox\Modules\Dashboard\DashboardModule())->fields()[0]->default);
	}

	public function test_the_test_bootstrap_switches_every_default_off(): void {
		// otherwise the real plugin, loaded by the bootstrap, would change WordPress' behaviour in every test
		$off = require dirname(__DIR__) . '/defaults-off.php';

		foreach (self::fields() as $name => [$module, $field]) {
			$changesSomething = match ($field->type) {
				FieldType::Bool => $field->default === true,
				FieldType::Multi => $field->default !== [],
				FieldType::Choice => !in_array($field->default, ['send', '404', 'off', 'wordpress'], true),
				default => $field->default !== '' && $field->default !== 0,
			};
			if ($changesSomething) {
				$this->assertArrayHasKey($field->key, $off[$module] ?? [], $name);
			}
		}
		$this->assertSame([], (new Settings(Plugin::modules(), $off))->invalidOverrides());
	}
}
