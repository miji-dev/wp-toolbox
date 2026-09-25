<?php
// The part of WP-CLI the plugin uses (php-stubs/wp-cli-stubs doesn't support the WordPress 7 stubs yet).

namespace {
	class WP_CLI {
		/**
		 * @param callable|object|string $callable
		 * @param array<string, mixed> $args
		 */
		public static function add_command(string $name, $callable, array $args = []): bool {
			return true;
		}

		public static function line(string $message = ''): void {
		}

		public static function success(string $message): void {
		}

		public static function warning(string $message): void {
		}

		/**
		 * @return never
		 */
		public static function error(string $message) {
			exit(1);
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param array<mixed> $items
	 * @param array<string>|string $fields
	 */
	function format_items(string $format, array $items, $fields): void {
	}
}
