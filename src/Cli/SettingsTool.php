<?php

declare(strict_types=1);

namespace Miji\Toolbox\Cli;

use InvalidArgumentException;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\FieldType;
use Miji\Toolbox\Settings\Settings;
use WP_Error;

/**
 * What `wp toolbox settings` does, without WP-CLI (so it can be tested). Settings are named "module.key".
 *
 * All writes go through Settings::update(): same validation as the settings page, locked values can't change.
 */
final class SettingsTool {
	public function __construct(private readonly Settings $settings, private readonly string $version) {
	}

	/**
	 * @return list<array{setting: string, value: string, default: string, source: string}> source: wp-config.php, changed or default
	 */
	public function rows(): array {
		$rows = [];
		foreach ($this->settings->all() as $module => $values) {
			foreach ($values as $key => $value) {
				$rows[] = [
					'setting' => "$module.$key",
					'value' => self::format($value),
					'default' => self::format($this->settings->field($module, $key)->default),
					'source' => match (true) {
						$this->settings->isLocked($module, $key) => 'wp-config.php',
						self::format($value) !== self::format($this->settings->field($module, $key)->default) => 'changed',
						default => 'default',
					},
				];
			}
		}
		return $rows;
	}

	public function get(string $setting): string {
		[$module, $key] = $this->split($setting);
		return self::format($this->settings->get($module, $key));
	}

	/**
	 * @throws InvalidArgumentException with a message for the user
	 */
	public function set(string $setting, string $input): void {
		[$module, $key] = $this->split($setting);
		if ($this->settings->isLocked($module, $key)) {
			throw new InvalidArgumentException("$setting is set in wp-config.php (WPTB_SETTINGS) and can't be changed here.");
		}
		$this->update([$module => [$key => $this->parse($setting, $this->settings->field($module, $key), $input)]]);
	}

	/**
	 * @param list<string> $targets "module.key" or "module"
	 */
	public function reset(array $targets): void {
		$input = [];
		foreach ($targets as $target) {
			$keys = str_contains($target, '.') ? [$this->split($target)] : $this->moduleKeys($target);
			foreach ($keys as [$module, $key]) {
				if (!$this->settings->isLocked($module, $key)) {
					$input[$module][$key] = $this->settings->field($module, $key)->default;
				}
			}
		}
		$this->update($input);
	}

	/**
	 * Same format as the settings page's export. Locked values belong to wp-config.php and are left out.
	 *
	 * @return array{plugin: string, version: string, settings: array<string, array<string, mixed>>}
	 */
	public function export(): array {
		$settings = [];
		foreach ($this->settings->all() as $module => $values) {
			$settings[$module] = array_diff_key($values, array_flip($this->settings->locked()[$module] ?? []));
		}
		return ['plugin' => 'wp-toolbox', 'version' => $this->version, 'settings' => $settings];
	}

	/**
	 * Applies an export file (or a plain settings object). Unknown and locked settings are skipped, everything else
	 * is validated together: if one value is invalid, nothing is saved.
	 *
	 * @param array<mixed> $data
	 * @return array{applied: int, skipped: list<string>}
	 */
	public function import(array $data): array {
		$incoming = array_key_exists('settings', $data) ? $data['settings'] : $data;
		if (!is_array($incoming) || ($incoming !== [] && array_is_list($incoming))) {
			throw new InvalidArgumentException('This is not a wp-toolbox settings file.');
		}

		$input = [];
		$skipped = [];
		foreach ($incoming as $module => $values) {
			if (!is_string($module) || !isset($this->settings->modules()[$module]) || !is_array($values)) {
				$skipped[] = "$module (unknown)";
				continue;
			}
			foreach ($values as $key => $value) {
				if (!is_string($key) || !$this->settings->has($module, $key)) {
					$skipped[] = "$module.$key (unknown)";
				} elseif ($this->settings->isLocked($module, $key)) {
					$skipped[] = "$module.$key (set in wp-config.php)";
				} else {
					$input[$module][$key] = $value;
				}
			}
		}

		$this->update($input);
		return ['applied' => array_sum(array_map('count', $input)), 'skipped' => $skipped];
	}

	/**
	 * @param array<string, array<string, mixed>> $input
	 */
	private function update(array $input): void {
		if ($input === []) {
			return;
		}
		$result = $this->settings->update($input);
		if ($result instanceof WP_Error) {
			throw new InvalidArgumentException('Nothing was saved: ' . $result->get_error_message());
		}
	}

	private function parse(string $setting, Field $field, string $input): mixed {
		$input = trim($input);
		switch ($field->type) {
			case FieldType::Bool:
				$bool = filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if ($bool === null || $input === '') {
					throw new InvalidArgumentException("$setting must be true or false (also: 1/0, yes/no, on/off).");
				}
				return $bool;
			case FieldType::Multi:
				$list = str_starts_with($input, '[') ? json_decode($input, true) : ($input === '' ? [] : array_map('trim', explode(',', $input)));
				$items = [];
				foreach (is_array($list) && array_is_list($list) ? $list : [null] as $item) {
					if (!is_string($item)) {
						throw new InvalidArgumentException("$setting takes a comma-separated list or a JSON array of strings.");
					}
					$items[] = $item;
				}
				$options = array_map(static fn (int|string $option): string => (string) $option, array_keys($field->options()));
				$unknown = array_diff($items, $options);
				if ($unknown) {
					throw new InvalidArgumentException(sprintf('%s: "%s" is not an option. Options: %s.', $setting, implode('", "', $unknown), implode(', ', $options)));
				}
				return $items;
			case FieldType::Choice:
				if (!array_key_exists($input, $field->options())) {
					throw new InvalidArgumentException(sprintf('%s: "%s" is not an option. Options: %s.', $setting, $input, implode(', ', array_keys($field->options()))));
				}
				return $input;
			case FieldType::Color:
				if (!preg_match('/^(#[0-9a-fA-F]{6})?\z/', $input)) {
					throw new InvalidArgumentException("$setting takes a colour like #1a2b3c, or an empty value for none.");
				}
				return strtolower($input);
			case FieldType::Attachment:
				if (!ctype_digit($input)) {
					throw new InvalidArgumentException("$setting takes the number (ID) of an image in the media library, or 0 for none.");
				}
				return (int) $input;
			default:
				return $input;
		}
	}

	/**
	 * @return array{string, string}
	 */
	private function split(string $setting): array {
		$parts = explode('.', $setting);
		if (count($parts) !== 2 || !$this->settings->has($parts[0], $parts[1])) {
			throw new InvalidArgumentException("Unknown setting \"$setting\". See `wp toolbox settings list`.");
		}
		return [$parts[0], $parts[1]];
	}

	/**
	 * @return list<array{string, string}>
	 */
	private function moduleKeys(string $module): array {
		$values = $this->settings->all()[$module] ?? null;
		if ($values === null) {
			throw new InvalidArgumentException("Unknown module \"$module\". See `wp toolbox settings list`.");
		}
		return array_map(static fn (string $key): array => [$module, $key], array_map('strval', array_keys($values)));
	}

	private static function format(mixed $value): string {
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			is_array($value) => (string) wp_json_encode($value),
			is_scalar($value) => (string) $value,
			default => '',
		};
	}
}
