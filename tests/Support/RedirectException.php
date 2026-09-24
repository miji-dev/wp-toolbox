<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Support;

use RuntimeException;

/**
 * Thrown from a wp_redirect filter in tests, so code that redirects and exits can be tested.
 */
final class RedirectException extends RuntimeException {
	public function __construct(public readonly string $location) {
		parent::__construct("Redirect to $location");
	}
}
