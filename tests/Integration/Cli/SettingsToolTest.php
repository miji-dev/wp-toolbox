<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Cli;

use InvalidArgumentException;
use Miji\Toolbox\Cli\SettingsTool;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\FakeModule;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_UnitTestCase;

final class SettingsToolTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	private Settings $settings;
	private SettingsTool $tool;

	public function set_up(): void {
		parent::set_up();
		$this->isolateSettingsRegistration();
		$this->settings = new Settings([
			new FakeModule('comments', [
				Field::bool('disable', false, 'Disable', 'W.', 'H.', 'Y.'),
				Field::choice('mode', ['404' => 'Not found', 'home' => 'Home'], '404', 'Mode', 'W.', 'H.', 'Y.'),
			]),
			new FakeModule('media', [
				Field::multi('sizes', ['large' => 'Large', 'medium' => 'Medium'], [], 'Sizes', 'W.', 'H.', 'Y.'),
				Field::text('to', '', 'To', 'W.', 'H.', 'Y.'),
				Field::color('bg', '', 'Bg', 'W.', 'H.', 'Y.'),
				Field::attachment('logo', 'Logo', 'W.', 'H.', 'Y.'),
			]),
		], ['comments' => ['mode' => 'home']]);
		$this->tool = new SettingsTool($this->settings, '4.0.0');
	}

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		parent::tear_down();
	}

	public function test_rows_list_every_setting_with_value_default_and_where_it_comes_from(): void {
		$this->tool->set('comments.disable', 'true');

		$rows = $this->tool->rows();

		$this->assertSame(['setting' => 'comments.disable', 'value' => 'true', 'default' => 'false', 'source' => 'changed'], $rows[0]);
		$this->assertSame(['setting' => 'comments.mode', 'value' => 'home', 'default' => '404', 'source' => 'wp-config.php'], $rows[1]);
		$this->assertSame(['setting' => 'media.sizes', 'value' => '[]', 'default' => '[]', 'source' => 'default'], $rows[2]);
		$this->assertCount(6, $rows);
	}

	/**
	 * @dataProvider values
	 */
	public function test_values_are_parsed_by_type(string $setting, string $input, mixed $stored): void {
		$this->tool->set($setting, $input);

		[$module, $key] = explode('.', $setting);
		$this->assertSame($stored, $this->settings->get($module, $key));
	}

	/**
	 * @return array<string, array{string, string, mixed}>
	 */
	public static function values(): array {
		return [
			'bool true' => ['comments.disable', 'true', true],
			'bool on' => ['comments.disable', 'ON', true],
			'bool 1' => ['comments.disable', '1', true],
			'bool no' => ['comments.disable', 'no', false],
			'list, commas' => ['media.sizes', 'large, medium', ['large', 'medium']],
			'list, JSON' => ['media.sizes', '["medium"]', ['medium']],
			'list, empty' => ['media.sizes', '', []],
			'text' => ['media.to', 'dev@example.org', 'dev@example.org'],
			'colour' => ['media.bg', '#1a2b3c', '#1a2b3c'],
			'attachment' => ['media.logo', '12', 12],
		];
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_invalid_input_is_rejected_with_a_message(string $setting, string $input, string $message): void {
		try {
			$this->tool->set($setting, $input);
			$this->fail('no exception');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
		$this->assertFalse(get_option(Settings::OPTION), 'nothing stored');
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid(): array {
		return [
			'unknown setting' => ['comments.nope', 'true', 'Unknown setting "comments.nope"'],
			'no module' => ['disable', 'true', 'Unknown setting "disable"'],
			'not a bool' => ['comments.disable', 'maybe', 'true or false'],
			'not an option' => ['media.sizes', 'large, huge', 'huge'],
			'not a colour' => ['media.bg', 'red', 'media.bg'],
			'not an id' => ['media.logo', '12abc', 'number'],
			'list with non-text items' => ['media.sizes', '["large", 5]', 'JSON array of strings'],
			'broken JSON list' => ['media.sizes', '["large"', 'JSON array of strings'],
			'locked' => ['comments.mode', '404', 'wp-config.php'],
		];
	}

	public function test_get_formats_values_for_the_terminal(): void {
		$this->tool->set('media.sizes', 'large,medium');

		$this->assertSame('false', $this->tool->get('comments.disable'));
		$this->assertSame('["large","medium"]', $this->tool->get('media.sizes'));
		$this->assertSame('home', $this->tool->get('comments.mode'));
		$this->assertSame('0', $this->tool->get('media.logo'));
	}

	public function test_reset_restores_defaults(): void {
		$this->tool->set('comments.disable', 'true');
		$this->tool->set('media.logo', '5');

		$this->tool->reset(['comments.disable']);
		$this->assertFalse($this->settings->get('comments', 'disable'));
		$this->assertSame(5, $this->settings->get('media', 'logo'), 'others untouched');

		$this->tool->reset(['media']);
		$this->assertSame(0, $this->settings->get('media', 'logo'), 'a whole module');
	}

	public function test_choices_must_be_one_of_the_options(): void {
		$tool = new SettingsTool(new Settings([new FakeModule('blog', [
			Field::choice('response', ['404' => 'Not found', 'home' => 'Home'], '404', 'Response', 'W.', 'H.', 'Y.'),
		])]), '4.0.0');

		$tool->set('blog.response', 'home');
		$this->expectExceptionMessage('blog.response: "gone" is not an option. Options: 404, home.');
		$tool->set('blog.response', 'gone');
	}

	public function test_resetting_an_unknown_module_is_an_error(): void {
		$this->expectExceptionMessage('Unknown module "nope"');
		$this->tool->reset(['nope']);
	}

	public function test_export_has_the_settings_page_format_without_locked_values(): void {
		$this->tool->set('comments.disable', 'true');

		$export = $this->tool->export();

		$this->assertSame('wp-toolbox', $export['plugin']);
		$this->assertSame('4.0.0', $export['version']);
		$this->assertSame(['disable' => true], $export['settings']['comments']);
		$this->assertSame([], $export['settings']['media']['sizes']);
	}

	public function test_import_applies_known_values_and_reports_the_rest(): void {
		$result = $this->tool->import([
			'plugin' => 'wp-toolbox',
			'settings' => [
				'comments' => ['disable' => true, 'mode' => '404', 'nope' => 1],
				'gone' => ['x' => 1],
				'media' => ['sizes' => ['large']],
			],
		]);

		$this->assertSame(2, $result['applied']);
		$this->assertSame(['comments.mode (set in wp-config.php)', 'comments.nope (unknown)', 'gone (unknown)'], $result['skipped']);
		$this->assertTrue($this->settings->get('comments', 'disable'));
		$this->assertSame(['large'], $this->settings->get('media', 'sizes'));
	}

	public function test_import_is_all_or_nothing(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->tool->import(['comments' => ['disable' => true], 'media' => ['bg' => 'red']]);
		} finally {
			$this->assertFalse(get_option(Settings::OPTION));
		}
	}

	public function test_import_rejects_anything_but_a_settings_object(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->tool->import(['settings' => 'x']);
	}
}
