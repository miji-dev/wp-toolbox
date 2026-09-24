<?php

declare(strict_types=1);

namespace Miji\Toolbox;

use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * One feature area (comments, head cleanup, …). A module declares its settings and hooks into
 * WordPress in register(). Nothing may happen on construction.
 */
interface Module {
	/** Stable snake_case ID, used as settings key. Never change it once released. */
	public function id(): string;

	public function title(): string;

	/** One or two sentences for the settings page. */
	public function description(): string;

	/** @return list<Field> */
	public function fields(): array;

	/** False if the module can't work here, e.g. a required plugin isn't active. Its settings are kept anyway. */
	public function isAvailable(): bool;

	/** Add hooks according to the settings. Called once, early on init (priority 0). */
	public function register(Settings $settings): void;
}
