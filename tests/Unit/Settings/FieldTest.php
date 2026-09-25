<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Unit\Settings;

use InvalidArgumentException;
use Miji\Toolbox\Settings\Field;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase {
	public function test_bool_field_schema(): void {
		$field = Field::bool('disable', true, 'Disable it', 'What it does.');

		$this->assertSame('disable', $field->key);
		$this->assertTrue($field->default);
		$this->assertSame(['type' => 'boolean', 'default' => true], $field->schema());
	}

	public function test_choice_field_schema_lists_the_options(): void {
		$field = Field::choice('mode', ['404' => 'Not found', '301' => 'Redirect'], '404', 'Mode', 'Desc.');

		$this->assertSame(['type' => 'string', 'enum' => ['404', '301'], 'default' => '404'], $field->schema());
		$this->assertSame(['404' => 'Not found', '301' => 'Redirect'], $field->options());
	}

	public function test_choice_default_must_be_one_of_the_options(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::choice('mode', ['a' => 'A'], 'b', 'Mode', 'Desc.');
	}

	public function test_multi_field_schema_is_a_unique_list_of_options(): void {
		$field = Field::multi('roles', ['editor' => 'Editor', 'author' => 'Author'], ['editor'], 'Roles', 'Desc.');

		$this->assertSame([
			'type' => 'array',
			'items' => ['type' => 'string', 'enum' => ['editor', 'author']],
			'uniqueItems' => true,
			'default' => ['editor'],
		], $field->schema());
	}

	public function test_multi_options_can_be_resolved_lazily(): void {
		$calls = 0;
		$field = Field::multi('types', static function () use (&$calls): array {
			$calls++;
			return ['page' => 'Pages'];
		}, [], 'Types', 'Desc.');

		$this->assertSame(0, $calls, 'options are not resolved on construction (post types are not registered yet)');
		$this->assertSame(['page' => 'Pages'], $field->options());
		$this->assertSame(['page' => 'Pages'], $field->options());
		$this->assertSame(1, $calls, 'options are resolved once');
	}

	public function test_int_field_schema_has_bounds(): void {
		$field = Field::int('quality', 82, 'Quality', 'Desc.', min: 1, max: 100);

		$this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 82], $field->schema());
	}

	public function test_int_default_must_be_within_bounds(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::int('quality', 0, 'Quality', 'Desc.', min: 1, max: 100);
	}

	public function test_text_color_and_attachment_schemas(): void {
		$this->assertSame(['type' => 'string', 'maxLength' => 500, 'default' => ''], Field::text('msg', '', 'Msg', 'Desc.')->schema());
		$this->assertSame(['type' => 'string', 'pattern' => '^(#[0-9a-fA-F]{6})?$', 'default' => ''], Field::color('bg', '', 'Bg', 'Desc.')->schema());
		$this->assertSame(['type' => 'integer', 'minimum' => 0, 'default' => 0], Field::attachment('logo', 'Logo', 'Desc.')->schema());
	}

	public function test_textarea_and_datetime_schemas(): void {
		$this->assertSame(['type' => 'string', 'maxLength' => 2000, 'default' => ''], Field::textarea('msg', '', 'Msg', 'Desc.')->schema());
		$this->assertSame(
			['type' => 'string', 'pattern' => '^(\\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\\d|3[01])T([01]\\d|2[0-3]):[0-5]\\d)?$', 'default' => ''],
			Field::datetime('until', 'Until', 'Desc.')->schema(),
		);
	}

	public function test_to_array_describes_the_field_for_the_settings_page(): void {
		$field = Field::choice('mode', ['404' => 'Not found', 'home' => 'Home'], '404', 'Mode', 'What.', why: 'Why.', sideEffects: 'Else.');

		$this->assertSame([
			'key' => 'mode',
			'type' => 'choice',
			'label' => 'Mode',
			'description' => 'What.',
			'why' => 'Why.',
			'sideEffects' => 'Else.',
			'default' => '404',
			// a list, so the order survives JSON (numeric keys would be reordered by browsers)
			'options' => [['value' => '404', 'label' => 'Not found'], ['value' => 'home', 'label' => 'Home']],
		], $field->toArray());
	}

	public function test_to_array_includes_limits(): void {
		$this->assertSame(['min' => 1, 'max' => 100], array_intersect_key(Field::int('q', 82, 'Q', 'D.', min: 1, max: 100)->toArray(), ['min' => 0, 'max' => 0]));
		$this->assertSame(500, Field::text('t', '', 'T', 'D.')->toArray()['maxLength']);
		$this->assertSame(2000, Field::textarea('t', '', 'T', 'D.')->toArray()['maxLength']);
		$this->assertArrayNotHasKey('options', Field::bool('b', false, 'B', 'D.')->toArray());
		$this->assertNull(Field::bool('b', false, 'B', 'D.')->toArray()['why']);
	}

	public function test_keys_are_restricted_to_snake_case(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::bool('Bad-Key', false, 'L', 'D');
	}

	public function test_explanations_are_part_of_the_definition(): void {
		$field = Field::bool('x', false, 'Label', 'What it does.', why: 'Why you want it.', sideEffects: 'What else changes.');

		$this->assertSame('Label', $field->label);
		$this->assertSame('What it does.', $field->description);
		$this->assertSame('Why you want it.', $field->why);
		$this->assertSame('What else changes.', $field->sideEffects);
	}
}
