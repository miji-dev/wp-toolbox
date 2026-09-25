<?php

declare(strict_types=1);

namespace Miji\Toolbox\Settings;

use InvalidArgumentException;
use Miji\Toolbox\Module;
use WP_Error;

/**
 * All settings of all modules, stored in one option as [module_id => [field_key => value]].
 *
 * - Values are read with get(); anything missing or malformed falls back to the field default.
 * - Overrides (from the WPTB_SETTINGS constant in wp-config.php) win over stored values and lock them.
 * - Writes go through validation against the generated JSON schema, both via update() and via the REST API.
 */
final class Settings {
	public const OPTION = 'wptb_settings';
	public const GROUP = 'wptb';

	/** @var array<string, Module> */
	private array $modules = [];

	/** @var array<string, array<string, Field>> */
	private array $fields = [];

	/** @var array<string, array<string, mixed>> */
	private array $overrides = [];

	/** @var list<string> */
	private array $invalidOverrides = [];

	private mixed $loadedRaw = null;

	/** @var array<string, array<string, mixed>>|null */
	private ?array $stored = null;

	/**
	 * @param iterable<Module> $modules
	 * @param array<mixed> $overrides [module_id => [field_key => value]]
	 */
	public function __construct(iterable $modules, array $overrides = []) {
		foreach ($modules as $module) {
			$id = $module->id();
			if (isset($this->modules[$id])) {
				throw new InvalidArgumentException("Duplicate module id \"$id\".");
			}
			$this->modules[$id] = $module;
			$this->fields[$id] = [];
			foreach ($module->fields() as $field) {
				if (isset($this->fields[$id][$field->key])) {
					throw new InvalidArgumentException("Duplicate field \"$id.{$field->key}\".");
				}
				$this->fields[$id][$field->key] = $field;
			}
		}

		$this->setOverrides($overrides);
	}

	/** @return array<string, Module> */
	public function modules(): array {
		return $this->modules;
	}

	public function has(string $module, string $key): bool {
		return isset($this->fields[$module][$key]);
	}

	public function field(string $module, string $key): Field {
		if (!$this->has($module, $key)) {
			throw new InvalidArgumentException("Unknown setting \"$module.$key\".");
		}
		return $this->fields[$module][$key];
	}

	public function get(string $module, string $key): mixed {
		$field = $this->field($module, $key);

		if (array_key_exists($key, $this->overrides[$module] ?? [])) {
			return $this->overrides[$module][$key];
		}

		$stored = $this->stored();
		return array_key_exists($key, $stored[$module] ?? []) ? $stored[$module][$key] : $field->default;
	}

	/**
	 * Effective value of every setting.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$all = [];
		foreach ($this->fields as $module => $fields) {
			foreach (array_keys($fields) as $key) {
				$all[$module][$key] = $this->get($module, $key);
			}
		}
		return $all;
	}

	/**
	 * Keys set in wp-config.php (and therefore not changeable), per module.
	 *
	 * @return array<string, list<string>>
	 */
	public function locked(): array {
		return array_map(static fn (array $values): array => array_map('strval', array_keys($values)), $this->overrides);
	}

	public function isLocked(string $module, string $key): bool {
		$this->field($module, $key);
		return array_key_exists($key, $this->overrides[$module] ?? []);
	}

	/**
	 * Overrides that were ignored because the setting doesn't exist or the value is invalid.
	 *
	 * @return list<string> "module.key" or "module"
	 */
	public function invalidOverrides(): array {
		return $this->invalidOverrides;
	}

	/**
	 * JSON schema of the whole option.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		$properties = [];
		foreach ($this->fields as $module => $fields) {
			$properties[$module] = [
				'type' => 'object',
				'properties' => array_map(static fn (Field $f): array => $f->schema(), $fields),
				'additionalProperties' => false,
			];
		}

		return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
	}

	/**
	 * Validate and store (partial) settings. Unmentioned values are kept, locked values can't be changed.
	 *
	 * @param array<mixed> $input [module_id => [field_key => value]]
	 */
	public function update(array $input): true|WP_Error {
		$valid = $this->validate($input);
		if ($valid instanceof WP_Error) {
			return $valid;
		}

		update_option(self::OPTION, $this->prepare($input));
		return true;
	}

	/**
	 * sanitize_callback of the registered option: covers the REST API and direct update_option() calls.
	 * Invalid input keeps the current value.
	 */
	public function sanitizeOption(mixed $input): mixed {
		if (!is_array($input) || $this->validate($input) instanceof WP_Error) {
			return get_option(self::OPTION);
		}
		return $this->prepare($input);
	}

	/**
	 * Register the option with WordPress, which exposes it at /wp/v2/settings for users with manage_options.
	 */
	public function registerSetting(): void {
		register_setting(self::GROUP, self::OPTION, [
			'type' => 'object',
			'description' => 'wp toolbox settings',
			'sanitize_callback' => [$this, 'sanitizeOption'],
			'show_in_rest' => ['schema' => $this->schema()],
		]);
	}

	/**
	 * @param array<mixed> $input
	 */
	private function validate(array $input): true|WP_Error {
		$result = rest_validate_value_from_schema($input, $this->schema(), self::OPTION);
		if ($result !== true) {
			return $result;
		}
		foreach ($this->fields as $module => $fields) {
			foreach ($fields as $key => $field) {
				$values = $input[$module] ?? null;
				if (is_array($values) && array_key_exists($key, $values) && self::endsInLineBreak($field, $values[$key])) {
					/* translators: %s: setting name, e.g. "login.background_color" */
					return new WP_Error('rest_invalid_pattern', sprintf(__('%s has an invalid format.', 'wptb'), self::OPTION . "[$module][$key]"));
				}
			}
		}
		return true;
	}

	/**
	 * Schema patterns end in "$", which in PHP also matches before a final line break ("#123456\n").
	 */
	private static function endsInLineBreak(Field $field, mixed $value): bool {
		return in_array($field->type, [FieldType::Color, FieldType::DateTime], true) && is_string($value) && str_contains($value, "\n");
	}

	/**
	 * Merge validated input into the stored values and return the complete set to store (without locked keys).
	 *
	 * @param array<mixed> $input
	 * @return array<string, array<string, mixed>>
	 */
	private function prepare(array $input): array {
		$stored = $this->stored();
		$result = [];

		foreach ($this->fields as $module => $fields) {
			$result[$module] = [];
			foreach ($fields as $key => $field) {
				if (array_key_exists($key, $this->overrides[$module] ?? [])) {
					continue;
				}
				if (is_array($input[$module] ?? null) && array_key_exists($key, $input[$module])) {
					$value = rest_sanitize_value_from_schema($input[$module][$key], $field->schema(), "$module.$key");
					if ($field->type === FieldType::Text) {
						$value = is_string($value) ? sanitize_text_field($value) : $field->default;
					} elseif ($field->type === FieldType::Textarea) {
						$value = is_string($value) ? sanitize_textarea_field($value) : $field->default;
					}
				} else {
					$value = array_key_exists($key, $stored[$module] ?? []) ? $stored[$module][$key] : $field->default;
				}
				$result[$module][$key] = $value;
			}
		}

		return $result;
	}

	/**
	 * Stored values that are still valid, re-read whenever the option changed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stored(): array {
		$raw = get_option(self::OPTION, []);
		if ($this->stored !== null && $raw === $this->loadedRaw) {
			return $this->stored;
		}

		$this->loadedRaw = $raw;
		$this->stored = [];
		if (!is_array($raw)) {
			return $this->stored;
		}

		foreach ($this->fields as $module => $fields) {
			if (!is_array($raw[$module] ?? null)) {
				continue;
			}
			foreach ($fields as $key => $field) {
				if (!array_key_exists($key, $raw[$module])) {
					continue;
				}
				$value = $this->normalize($field, $raw[$module][$key], $field->storedSchema());
				if ($value !== null) {
					$this->stored[$module][$key] = $value;
				}
			}
		}

		return $this->stored;
	}

	/**
	 * @param array<mixed> $overrides
	 */
	private function setOverrides(array $overrides): void {
		foreach ($overrides as $module => $values) {
			if (!is_string($module) || !isset($this->fields[$module]) || !is_array($values)) {
				$this->invalidOverrides[] = (string) $module;
				continue;
			}
			foreach ($values as $key => $value) {
				$field = is_string($key) ? ($this->fields[$module][$key] ?? null) : null;
				$normalized = $field ? $this->normalize($field, $value, $field->schema()) : null;
				if ($normalized === null) {
					$this->invalidOverrides[] = "$module.$key";
					continue;
				}
				$this->overrides[$module][$key] = $normalized;
			}
		}
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return mixed the value coerced to the field type, or null if invalid
	 */
	private function normalize(Field $field, mixed $value, array $schema): mixed {
		if (rest_validate_value_from_schema($value, $schema) !== true || self::endsInLineBreak($field, $value)) {
			return null;
		}
		$value = rest_sanitize_value_from_schema($value, $schema);
		return $value instanceof WP_Error ? null : $value;
	}
}
