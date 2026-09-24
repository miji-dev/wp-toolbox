<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Support;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Configurable module for tests: records whether and with which settings it was registered.
 */
final class FakeModule implements Module {
	public ?Settings $registeredWith = null;

	/**
	 * @param list<Field> $fields
	 */
	public function __construct(
		private readonly string $id,
		private readonly array $fields,
		private readonly bool $available = true,
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function title(): string {
		return ucfirst($this->id);
	}

	public function description(): string {
		return 'Fake module ' . $this->id;
	}

	public function fields(): array {
		return $this->fields;
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function register(Settings $settings): void {
		$this->registeredWith = $settings;
	}
}
