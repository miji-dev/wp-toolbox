<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Support;

/**
 * Settings that are on by default, switched off (tests/defaults-off.php). Module tests lay the values they test
 * over these, so every test starts from WordPress' own behaviour.
 */
final class DefaultsOff {
	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function with(string $module, array $values): array {
		$off = require dirname(__DIR__) . '/defaults-off.php';
		return $values + ($off[$module] ?? []);
	}
}
