<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Settings;

use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\FakeModule;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_Error;
use WP_UnitTestCase;

final class SettingsTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	public function set_up(): void {
		parent::set_up();
		$this->isolateSettingsRegistration();
	}

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		parent::tear_down();
	}

	private function settings(array $overrides = []): Settings {
		return new Settings([
			new FakeModule('comments', [
				Field::bool('disable', false, 'Disable comments', 'Desc.'),
				Field::choice('mode', ['404' => 'Not found', '301' => 'Redirect'], '404', 'Mode', 'Desc.'),
			]),
			new FakeModule('media', [
				Field::int('quality', 82, 'Quality', 'Desc.', min: 1, max: 100),
				Field::color('bg', '', 'Background', 'Desc.'),
				Field::multi('roles', ['editor' => 'Editor', 'author' => 'Author'], [], 'Roles', 'Desc.'),
				Field::text('message', 'Hello', 'Message', 'Desc.'),
				Field::textarea('notice', '', 'Notice', 'Desc.'),
				Field::datetime('until', 'Until', 'Desc.'),
			]),
		], $overrides);
	}

	public function test_defaults_apply_when_nothing_is_stored(): void {
		$s = $this->settings();

		$this->assertFalse($s->get('comments', 'disable'));
		$this->assertSame('404', $s->get('comments', 'mode'));
		$this->assertSame(82, $s->get('media', 'quality'));
		$this->assertSame([], $s->get('media', 'roles'));
	}

	public function test_stored_values_are_returned(): void {
		update_option(Settings::OPTION, ['comments' => ['disable' => true], 'media' => ['quality' => 70]]);
		$s = $this->settings();

		$this->assertTrue($s->get('comments', 'disable'));
		$this->assertSame(70, $s->get('media', 'quality'));
		$this->assertSame('404', $s->get('comments', 'mode'), 'unset keys fall back to their default');
	}

	public function test_stored_values_of_the_wrong_shape_fall_back_to_defaults(): void {
		update_option(Settings::OPTION, ['comments' => ['disable' => 'yes please', 'mode' => '500'], 'media' => 'garbage']);
		$s = $this->settings();

		$this->assertFalse($s->get('comments', 'disable'));
		$this->assertSame('404', $s->get('comments', 'mode'));
		$this->assertSame(82, $s->get('media', 'quality'));
	}

	public function test_unknown_setting_throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->settings()->get('comments', 'nope');
	}

	public function test_overrides_win_and_are_reported_as_locked(): void {
		update_option(Settings::OPTION, ['comments' => ['disable' => false]]);
		$s = $this->settings(['comments' => ['disable' => true]]);

		$this->assertTrue($s->get('comments', 'disable'));
		$this->assertTrue($s->isLocked('comments', 'disable'));
		$this->assertFalse($s->isLocked('comments', 'mode'));
	}

	public function test_invalid_overrides_are_ignored_with_a_notice(): void {
		$s = $this->settings(['comments' => ['mode' => '500', 'unknown' => true], 'nope' => []]);

		$this->assertSame('404', $s->get('comments', 'mode'));
		$this->assertFalse($s->isLocked('comments', 'mode'));
		$this->assertSame(['comments.mode', 'comments.unknown', 'nope'], $s->invalidOverrides());
	}

	public function test_overrides_with_a_trailing_line_break_are_invalid(): void {
		$s = $this->settings(['media' => ['bg' => "#123456\n"]]);

		$this->assertSame('', $s->get('media', 'bg'));
		$this->assertSame(['media.bg'], $s->invalidOverrides());
	}

	public function test_update_validates_and_stores_the_full_set(): void {
		$s = $this->settings();

		$result = $s->update(['comments' => ['disable' => true], 'media' => ['roles' => ['editor']]]);

		$this->assertTrue($result);
		$stored = get_option(Settings::OPTION);
		$this->assertTrue($stored['comments']['disable']);
		$this->assertSame('404', $stored['comments']['mode'], 'untouched keys are kept');
		$this->assertSame(['editor'], $stored['media']['roles']);
		$this->assertTrue($s->get('comments', 'disable'), 'the instance sees the new value');
	}

	/**
	 * @dataProvider invalidInput
	 */
	public function test_update_rejects_invalid_input_without_storing_anything(array $input): void {
		$s = $this->settings();

		$result = $s->update($input);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertFalse(get_option(Settings::OPTION));
	}

	public static function invalidInput(): array {
		return [
			'wrong type' => [['comments' => ['disable' => 'yes']]],
			'not an option' => [['comments' => ['mode' => '500']]],
			'out of range' => [['media' => ['quality' => 101]]],
			'not a colour' => [['media' => ['bg' => 'red;}</style>']]],
			'unknown role' => [['media' => ['roles' => ['administrator']]]],
			'duplicate items' => [['media' => ['roles' => ['editor', 'editor']]]],
			'text too long' => [['media' => ['message' => str_repeat('x', 501)]]],
			'textarea too long' => [['media' => ['notice' => str_repeat('x', 2001)]]],
			'not a date' => [['media' => ['until' => 'tomorrow']]],
			// PHP's "$" also matches before a final line break
			'colour with trailing line break' => [['media' => ['bg' => "#123456\n"]]],
			'date with trailing line break' => [['media' => ['until' => "2026-10-01T10:00\n"]]],
			'impossible month' => [['media' => ['until' => '2026-13-01T10:00']]],
			'date with injected text' => [['media' => ['until' => "2026-10-01T10:00\n<script>"]]],
			'unknown key' => [['comments' => ['nope' => true]]],
			'unknown module' => [['nope' => ['x' => true]]],
			'module not an object' => [['comments' => 'x']],
		];
	}

	public function test_text_is_sanitized_on_update(): void {
		$s = $this->settings();

		$s->update(['media' => ['message' => "<script>alert(1)</script>Hi\n there"]]);

		$this->assertSame('Hi there', $s->get('media', 'message'));
	}

	public function test_textarea_keeps_line_breaks_but_no_markup(): void {
		$s = $this->settings();

		$s->update(['media' => ['notice' => "<script>alert(1)</script>Line one\nLine <b>two</b>"]]);

		$this->assertSame("Line one\nLine two", $s->get('media', 'notice'));
	}

	public function test_dates_are_stored_as_given(): void {
		$s = $this->settings();

		$this->assertTrue($s->update(['media' => ['until' => '2026-10-01T18:30']]));
		$this->assertSame('2026-10-01T18:30', $s->get('media', 'until'));
		$this->assertTrue($s->update(['media' => ['until' => '']]), 'empty = no date');
	}

	public function test_update_cannot_change_locked_values(): void {
		$s = $this->settings(['comments' => ['disable' => true]]);

		$s->update(['comments' => ['disable' => false, 'mode' => '301']]);

		$this->assertTrue($s->get('comments', 'disable'));
		$this->assertSame('301', $s->get('comments', 'mode'));
		$this->assertArrayNotHasKey('disable', get_option(Settings::OPTION)['comments'], 'locked values are not written to the database');
	}

	public function test_all_returns_every_effective_value(): void {
		update_option(Settings::OPTION, ['media' => ['quality' => 70]]);
		$s = $this->settings(['comments' => ['disable' => true]]);

		$all = $s->all();

		$this->assertSame(['disable' => true, 'mode' => '404'], $all['comments']);
		$this->assertSame(70, $all['media']['quality']);
		$this->assertSame('', $all['media']['until']);
	}

	public function test_locked_lists_the_overridden_keys_per_module(): void {
		$s = $this->settings(['comments' => ['disable' => true], 'media' => ['bg' => '#000000', 'quality' => 500]]);

		$this->assertSame(['comments' => ['disable'], 'media' => ['bg']], $s->locked(), 'invalid overrides are not locked');
	}

	public function test_schema_describes_every_module_and_field(): void {
		$schema = $this->settings()->schema();

		$this->assertSame('object', $schema['type']);
		$this->assertFalse($schema['additionalProperties']);
		$this->assertSame(['disable', 'mode'], array_keys($schema['properties']['comments']['properties']));
		$this->assertFalse($schema['properties']['comments']['additionalProperties']);
		$this->assertSame(['type' => 'boolean', 'default' => false], $schema['properties']['comments']['properties']['disable']);
	}

	public function test_duplicate_module_ids_are_rejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		new Settings([new FakeModule('a', []), new FakeModule('a', [])]);
	}

	public function test_duplicate_field_keys_are_rejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		new Settings([new FakeModule('a', [Field::bool('x', false, 'L', 'D'), Field::bool('x', true, 'L', 'D')])]);
	}
}
