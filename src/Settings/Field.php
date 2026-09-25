<?php

declare(strict_types=1);

namespace Miji\Toolbox\Settings;

use Closure;
use InvalidArgumentException;

/**
 * Definition of one setting: its type and default, plus the texts the settings page shows.
 *
 * Every field explains itself: `description` says what it does, `why` why you'd want it,
 * `sideEffects` what else changes when you turn it on.
 */
final class Field {
	/** @var array<string|int, string>|null */
	private ?array $resolvedOptions = null;

	/**
	 * @param array<string|int, string>|Closure(): array<string|int, string>|null $options value => label
	 */
	private function __construct(
		public readonly string $key,
		public readonly FieldType $type,
		public readonly mixed $default,
		public readonly string $label,
		public readonly string $description,
		public readonly ?string $why,
		public readonly ?string $sideEffects,
		private readonly array|Closure|null $options = null,
		public readonly ?int $min = null,
		public readonly ?int $max = null,
	) {
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
			throw new InvalidArgumentException("Invalid field key \"$key\": use snake_case.");
		}
	}

	public static function bool(string $key, bool $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null): self {
		return new self($key, FieldType::Bool, $default, $label, $description, $why, $sideEffects);
	}

	/**
	 * @param array<string|int, string> $options value => label
	 */
	public static function choice(string $key, array $options, string $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null): self {
		if (!in_array($default, self::stringKeys($options), true)) {
			throw new InvalidArgumentException("Default \"$default\" of \"$key\" is not one of its options.");
		}
		return new self($key, FieldType::Choice, $default, $label, $description, $why, $sideEffects, $options);
	}

	/**
	 * @param array<string|int, string>|Closure(): array<string|int, string> $options value => label; a closure is resolved on first use
	 * @param list<string> $default
	 */
	public static function multi(string $key, array|Closure $options, array $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null): self {
		if (is_array($options) && array_diff($default, self::stringKeys($options))) {
			throw new InvalidArgumentException("Default of \"$key\" contains values that are not options.");
		}
		return new self($key, FieldType::Multi, $default, $label, $description, $why, $sideEffects, $options);
	}

	public static function int(string $key, int $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null, ?int $min = null, ?int $max = null): self {
		if (($min !== null && $default < $min) || ($max !== null && $default > $max)) {
			throw new InvalidArgumentException("Default of \"$key\" is out of bounds.");
		}
		return new self($key, FieldType::Int, $default, $label, $description, $why, $sideEffects, null, $min, $max);
	}

	public static function text(string $key, string $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null, int $maxLength = 500): self {
		return new self($key, FieldType::Text, $default, $label, $description, $why, $sideEffects, null, null, $maxLength);
	}

	public static function textarea(string $key, string $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null, int $maxLength = 2000): self {
		return new self($key, FieldType::Textarea, $default, $label, $description, $why, $sideEffects, null, null, $maxLength);
	}

	public static function color(string $key, string $default, string $label, string $description, ?string $why = null, ?string $sideEffects = null): self {
		if (!preg_match('/^(#[0-9a-fA-F]{6})?$/', $default)) {
			throw new InvalidArgumentException("Default of \"$key\" is not a hex colour.");
		}
		return new self($key, FieldType::Color, $default, $label, $description, $why, $sideEffects);
	}

	public static function attachment(string $key, string $label, string $description, ?string $why = null, ?string $sideEffects = null): self {
		return new self($key, FieldType::Attachment, 0, $label, $description, $why, $sideEffects, null, 0);
	}

	public static function datetime(string $key, string $label, string $description, ?string $why = null, ?string $sideEffects = null): self {
		return new self($key, FieldType::DateTime, '', $label, $description, $why, $sideEffects);
	}

	/**
	 * @return array<string|int, string> value => label ([] for types without options)
	 */
	public function options(): array {
		if ($this->resolvedOptions === null) {
			$options = $this->options instanceof Closure ? ($this->options)() : ($this->options ?? []);
			$this->resolvedOptions = $options;
		}
		return $this->resolvedOptions;
	}

	/**
	 * JSON schema of the value, used for REST validation.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		return match ($this->type) {
			FieldType::Bool => ['type' => 'boolean', 'default' => $this->default],
			FieldType::Choice => ['type' => 'string', 'enum' => self::stringKeys($this->options()), 'default' => $this->default],
			FieldType::Multi => [
				'type' => 'array',
				'items' => ['type' => 'string', 'enum' => self::stringKeys($this->options())],
				'uniqueItems' => true,
				'default' => $this->default,
			],
			FieldType::Int => array_filter(
				['type' => 'integer', 'minimum' => $this->min, 'maximum' => $this->max, 'default' => $this->default],
				static fn ($v): bool => $v !== null,
			),
			FieldType::Text, FieldType::Textarea => ['type' => 'string', 'maxLength' => $this->max, 'default' => $this->default],
			FieldType::Color => ['type' => 'string', 'pattern' => '^(#[0-9a-fA-F]{6})?$', 'default' => $this->default],
			FieldType::Attachment => ['type' => 'integer', 'minimum' => 0, 'default' => $this->default],
			// day 31 in a 30-day month still passes; users of the value parse it strictly
			FieldType::DateTime => ['type' => 'string', 'pattern' => '^(\\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\\d|3[01])T([01]\\d|2[0-3]):[0-5]\\d)?$', 'default' => $this->default],
		};
	}

	/**
	 * Everything the settings page needs to render the field.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'key' => $this->key,
			'type' => $this->type->value,
			'label' => $this->label,
			'description' => $this->description,
			'why' => $this->why,
			'sideEffects' => $this->sideEffects,
			'default' => $this->default,
		];
		if ($this->type === FieldType::Choice || $this->type === FieldType::Multi) {
			// a list, so the order survives JSON (numeric keys would be reordered by browsers)
			$data['options'] = [];
			foreach ($this->options() as $value => $label) {
				$data['options'][] = ['value' => (string) $value, 'label' => $label];
			}
		}
		if ($this->type === FieldType::Int) {
			$data['min'] = $this->min;
			$data['max'] = $this->max;
		}
		if ($this->type === FieldType::Text || $this->type === FieldType::Textarea) {
			$data['maxLength'] = $this->max;
		}
		return $data;
	}

	/**
	 * Schema for checking values that are already stored. Same as schema(), except that runtime-resolved
	 * option lists are not enforced: they may not be complete yet this early (post types register on init),
	 * and a stale entry (e.g. a removed post type) must not throw away the rest of the list.
	 *
	 * @return array<string, mixed>
	 */
	public function storedSchema(): array {
		if ($this->type === FieldType::Multi && $this->options instanceof Closure) {
			return ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true];
		}
		return $this->schema();
	}

	/**
	 * @param array<string|int, string> $options
	 * @return list<string>
	 */
	private static function stringKeys(array $options): array {
		return array_map('strval', array_keys($options));
	}
}
