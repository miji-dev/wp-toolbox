<?php

declare(strict_types=1);

namespace Miji\Toolbox\Settings;

use Closure;
use InvalidArgumentException;

/**
 * Definition of one setting: its type and default, plus the texts the settings page shows.
 *
 * Every field explains itself, and the texts are required: `what` it does (in the user's terms), `how` it does it
 * (what exactly changes technically), `why` you'd want it; `sideEffects` whenever something else changes too.
 */
final class Field {
	/** @var array<string|int, string|array{label: string, description: string}>|null */
	private ?array $resolvedOptions = null;

	/**
	 * @param array<string|int, string|array{label: string, description: string}>|Closure(): array<string|int, string|array{label: string, description: string}>|null $options value => label, or Field::option()
	 */
	private function __construct(
		public readonly string $key,
		public readonly FieldType $type,
		public readonly mixed $default,
		public readonly string $label,
		public readonly string $what,
		public readonly string $how,
		public readonly string $why,
		public readonly ?string $sideEffects,
		private readonly array|Closure|null $options = null,
		public readonly ?int $maxLength = null,
	) {
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
			throw new InvalidArgumentException("Invalid field key \"$key\": use snake_case.");
		}
		foreach (['label' => $label, 'what' => $what, 'how' => $how, 'why' => $why] as $name => $text) {
			if (trim($text) === '') {
				throw new InvalidArgumentException("Field \"$key\" needs a $name text.");
			}
		}
	}

	public static function bool(string $key, bool $default, string $label, string $what, string $how, string $why, ?string $sideEffects = null): self {
		return new self($key, FieldType::Bool, $default, $label, $what, $how, $why, $sideEffects);
	}

	/**
	 * @param array<string|int, string|array{label: string, description: string}> $options value => label, or Field::option()
	 */
	public static function choice(string $key, array $options, string $default, string $label, string $what, string $how, string $why, ?string $sideEffects = null): self {
		if (!in_array($default, self::stringKeys($options), true)) {
			throw new InvalidArgumentException("Default \"$default\" of \"$key\" is not one of its options.");
		}
		return new self($key, FieldType::Choice, $default, $label, $what, $how, $why, $sideEffects, $options);
	}

	/**
	 * @param array<string|int, string|array{label: string, description: string}>|Closure(): array<string|int, string|array{label: string, description: string}> $options value => label or Field::option(); a closure is resolved on first use
	 * @param list<string> $default
	 */
	public static function multi(string $key, array|Closure $options, array $default, string $label, string $what, string $how, string $why, ?string $sideEffects = null): self {
		if (is_array($options) && array_diff($default, self::stringKeys($options))) {
			throw new InvalidArgumentException("Default of \"$key\" contains values that are not options.");
		}
		return new self($key, FieldType::Multi, $default, $label, $what, $how, $why, $sideEffects, $options);
	}

	public static function text(string $key, string $default, string $label, string $what, string $how, string $why, ?string $sideEffects = null, int $maxLength = 500): self {
		return new self($key, FieldType::Text, $default, $label, $what, $how, $why, $sideEffects, null, $maxLength);
	}

	public static function color(string $key, string $default, string $label, string $what, string $how, string $why, ?string $sideEffects = null): self {
		if (!preg_match('/^(#[0-9a-fA-F]{6})?$/', $default)) {
			throw new InvalidArgumentException("Default of \"$key\" is not a hex colour.");
		}
		return new self($key, FieldType::Color, $default, $label, $what, $how, $why, $sideEffects);
	}

	public static function attachment(string $key, string $label, string $what, string $how, string $why, ?string $sideEffects = null): self {
		return new self($key, FieldType::Attachment, 0, $label, $what, $how, $why, $sideEffects);
	}

	/**
	 * An option with an explanation of what it is, for lists where the label alone isn't clear.
	 *
	 * @return array{label: string, description: string}
	 */
	public static function option(string $label, string $description): array {
		return ['label' => $label, 'description' => $description];
	}

	/**
	 * @return array<string|int, string> value => label ([] for types without options)
	 */
	public function options(): array {
		return array_map(static fn (string|array $option): string => is_array($option) ? $option['label'] : $option, $this->resolvedOptions());
	}

	/**
	 * @return array<string|int, string|array{label: string, description: string}>
	 */
	private function resolvedOptions(): array {
		if ($this->resolvedOptions === null) {
			$this->resolvedOptions = $this->options instanceof Closure ? ($this->options)() : ($this->options ?? []);
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
			FieldType::Text => ['type' => 'string', 'maxLength' => $this->maxLength, 'default' => $this->default],
			FieldType::Color => ['type' => 'string', 'pattern' => '^(#[0-9a-fA-F]{6})?$', 'default' => $this->default],
			FieldType::Attachment => ['type' => 'integer', 'minimum' => 0, 'default' => $this->default],
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
			'what' => $this->what,
			'how' => $this->how,
			'why' => $this->why,
			'sideEffects' => $this->sideEffects,
			'default' => $this->default,
		];
		if ($this->type === FieldType::Choice || $this->type === FieldType::Multi) {
			// a list, so the order survives JSON (numeric keys would be reordered by browsers)
			$data['options'] = [];
			foreach ($this->resolvedOptions() as $value => $option) {
				$data['options'][] = is_array($option)
					? ['value' => (string) $value, 'label' => $option['label'], 'description' => $option['description']]
					: ['value' => (string) $value, 'label' => $option];
			}
		}
		if ($this->type === FieldType::Text) {
			$data['maxLength'] = $this->maxLength;
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
	 * @param array<string|int, mixed> $options
	 * @return list<string>
	 */
	private static function stringKeys(array $options): array {
		return array_map('strval', array_keys($options));
	}
}
