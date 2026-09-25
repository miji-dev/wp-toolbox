<?php

declare(strict_types=1);

namespace Miji\Toolbox\Cli;

use InvalidArgumentException;
use WP_CLI;

/**
 * Shows and changes the wp toolbox settings.
 *
 * Settings are named <module>.<setting>, e.g. comments.disable. `wp toolbox settings list` shows all of them.
 * Values set in wp-config.php (WPTB_SETTINGS) can't be changed here.
 *
 * ## EXAMPLES
 *
 *     wp toolbox settings list
 *     wp toolbox settings set comments.disable true
 *     wp toolbox settings set blog.remove_archives author,date
 *     wp toolbox settings export --file=toolbox.json
 *     wp toolbox settings import toolbox.json
 */
final class SettingsCommand {
	public function __construct(private readonly SettingsTool $tool) {
	}

	/**
	 * Lists all settings with their value, default and where the value comes from.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param list<string> $args
	 * @param array<string, string> $assoc
	 */
	public function list_(array $args, array $assoc): void {
		WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $this->tool->rows(), ['setting', 'value', 'default', 'source']);
	}

	/**
	 * Prints the value of a setting.
	 *
	 * ## OPTIONS
	 *
	 * <setting>
	 * : e.g. comments.disable
	 *
	 * @param list<string> $args
	 */
	public function get(array $args): void {
		$this->run(fn () => WP_CLI::line($this->tool->get($args[0])));
	}

	/**
	 * Changes a setting.
	 *
	 * ## OPTIONS
	 *
	 * <setting>
	 * : e.g. comments.disable
	 *
	 * <value>
	 * : true/false for switches; a comma-separated list for multiple choice (empty for none); the option's value
	 * for single choice; the image ID for images; text otherwise.
	 *
	 * @param list<string> $args
	 */
	public function set(array $args): void {
		$this->run(function () use ($args): void {
			$this->tool->set($args[0], $args[1]);
			WP_CLI::success("$args[0] = " . $this->tool->get($args[0]));
		});
	}

	/**
	 * Resets settings to their defaults.
	 *
	 * ## OPTIONS
	 *
	 * <setting>...
	 * : Settings (comments.disable) or whole modules (comments).
	 *
	 * @param list<string> $args
	 */
	public function reset(array $args): void {
		$this->run(function () use ($args): void {
			$this->tool->reset($args);
			WP_CLI::success('Reset to defaults: ' . implode(', ', $args));
		});
	}

	/**
	 * Exports the settings as JSON, in the same format as the settings page. Values from wp-config.php are left out.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Write to this file instead of the terminal.
	 *
	 * @param list<string> $args
	 * @param array<string, string> $assoc
	 */
	public function export(array $args, array $assoc): void {
		$json = (string) wp_json_encode($this->tool->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!isset($assoc['file'])) {
			WP_CLI::line($json);
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- a file the user asked for on the command line
		if (file_put_contents($assoc['file'], $json . "\n") === false) {
			WP_CLI::error("Could not write {$assoc['file']}.");
		}
		WP_CLI::success("Exported to {$assoc['file']}.");
	}

	/**
	 * Imports settings from a JSON file (an export of the settings page or of this command). Everything is checked
	 * first: if one value is invalid, nothing is saved.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The file, or - to read from STDIN.
	 *
	 * @param list<string> $args
	 */
	public function import(array $args): void {
		$this->run(function () use ($args): void {
			$file = $args[0] === '-' ? 'php://stdin' : $args[0];
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- a file the user named on the command line
			$json = is_readable($file) || $file === 'php://stdin' ? file_get_contents($file) : false;
			if ($json === false) {
				throw new InvalidArgumentException("Could not read $args[0].");
			}
			$data = json_decode($json, true);
			if (!is_array($data)) {
				throw new InvalidArgumentException('This is not a wp-toolbox settings file.');
			}
			$result = $this->tool->import($data);
			foreach ($result['skipped'] as $skipped) {
				WP_CLI::warning("Skipped $skipped.");
			}
			WP_CLI::success("Imported {$result['applied']} settings.");
		});
	}

	private function run(callable $command): void {
		try {
			$command();
		} catch (InvalidArgumentException $e) {
			WP_CLI::error($e->getMessage());
		}
	}
}
