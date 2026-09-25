<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Unit\Settings;

use InvalidArgumentException;
use Miji\Toolbox\Settings\Field;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase {
	public function test_bool_field_schema(): void {
		$field = Field::bool('disable', true, 'Disable it', 'What.', 'How.', 'Why.');

		$this->assertSame('disable', $field->key);
		$this->assertTrue($field->default);
		$this->assertSame(['type' => 'boolean', 'default' => true], $field->schema());
	}

	public function test_choice_field_schema_lists_the_options(): void {
		$field = Field::choice('mode', ['404' => 'Not found', '301' => 'Redirect'], '404', 'Mode', 'What.', 'How.', 'Why.');

		$this->assertSame(['type' => 'string', 'enum' => ['404', '301'], 'default' => '404'], $field->schema());
		$this->assertSame(['404' => 'Not found', '301' => 'Redirect'], $field->options());
	}

	public function test_choice_default_must_be_one_of_the_options(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::choice('mode', ['a' => 'A'], 'b', 'Mode', 'What.', 'How.', 'Why.');
	}

	public function test_multi_field_schema_is_a_unique_list_of_options(): void {
		$field = Field::multi('roles', ['editor' => 'Editor', 'author' => 'Author'], ['editor'], 'Roles', 'What.', 'How.', 'Why.');

		$this->assertSame([
			'type' => 'array',
			'items' => ['type' => 'string', 'enum' => ['editor', 'author']],
			'uniqueItems' => true,
			'default' => ['editor'],
		], $field->schema());
	}

	public function test_multi_default_must_be_options(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::multi('roles', ['editor' => 'Editor'], ['admin'], 'Roles', 'What.', 'How.', 'Why.');
	}

	public function test_multi_options_can_be_resolved_lazily(): void {
		$calls = 0;
		$field = Field::multi('types', static function () use (&$calls): array {
			$calls++;
			return ['page' => 'Pages'];
		}, [], 'Types', 'What.', 'How.', 'Why.');

		$this->assertSame(0, $calls, 'options are not resolved on construction (post types are not registered yet)');
		$this->assertSame(['page' => 'Pages'], $field->options());
		$this->assertSame(['page' => 'Pages'], $field->options());
		$this->assertSame(1, $calls, 'options are resolved once');
	}

	public function test_text_color_and_attachment_schemas(): void {
		$this->assertSame(['type' => 'string', 'maxLength' => 500, 'default' => ''], Field::text('msg', '', 'Msg', 'What.', 'How.', 'Why.')->schema());
		$this->assertSame(['type' => 'string', 'pattern' => '^(#[0-9a-fA-F]{6})?$', 'default' => ''], Field::color('bg', '', 'Bg', 'What.', 'How.', 'Why.')->schema());
		$this->assertSame(['type' => 'integer', 'minimum' => 0, 'default' => 0], Field::attachment('logo', 'Logo', 'What.', 'How.', 'Why.')->schema());
	}

	public function test_color_default_must_be_a_hex_colour(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::color('bg', 'red', 'Bg', 'What.', 'How.', 'Why.');
	}

	public function test_to_array_describes_the_field_for_the_settings_page(): void {
		$field = Field::choice('mode', ['404' => 'Not found', 'home' => 'Home'], '404', 'Mode', 'What.', 'How.', 'Why.', 'Else.');

		$this->assertSame([
			'key' => 'mode',
			'type' => 'choice',
			'label' => 'Mode',
			'what' => 'What.',
			'how' => 'How.',
			'why' => 'Why.',
			'sideEffects' => 'Else.',
			'default' => '404',
			// a list, so the order survives JSON (numeric keys would be reordered by browsers)
			'options' => [['value' => '404', 'label' => 'Not found'], ['value' => 'home', 'label' => 'Home']],
		], $field->toArray());
	}

	public function test_to_array_includes_the_text_limit(): void {
		$this->assertSame(254, Field::text('t', '', 'T', 'What.', 'How.', 'Why.', maxLength: 254)->toArray()['maxLength']);
		$this->assertArrayNotHasKey('options', Field::bool('b', false, 'B', 'What.', 'How.', 'Why.')->toArray());
		$this->assertNull(Field::bool('b', false, 'B', 'What.', 'How.', 'Why.')->toArray()['sideEffects']);
	}

	public function test_keys_are_restricted_to_snake_case(): void {
		$this->expectException(InvalidArgumentException::class);
		Field::bool('Bad-Key', false, 'L', 'What.', 'How.', 'Why.');
	}

	/**
	 * @dataProvider missingTexts
	 */
	public function test_every_field_must_explain_what_how_and_why(string $what, string $how, string $why): void {
		$this->expectException(InvalidArgumentException::class);
		Field::bool('x', false, 'Label', $what, $how, $why);
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function missingTexts(): array {
		return [
			'what' => ['', 'How.', 'Why.'],
			'how' => ['What.', ' ', 'Why.'],
			'why' => ['What.', 'How.', ''],
		];
	}

	public function test_explanations_are_part_of_the_definition(): void {
		$field = Field::bool('x', false, 'Label', 'What it does.', 'How it works.', 'Why you want it.', 'What else changes.');

		$this->assertSame('Label', $field->label);
		$this->assertSame('What it does.', $field->what);
		$this->assertSame('How it works.', $field->how);
		$this->assertSame('Why you want it.', $field->why);
		$this->assertSame('What else changes.', $field->sideEffects);
	}
}
