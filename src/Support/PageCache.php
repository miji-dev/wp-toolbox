<?php

declare(strict_types=1);

namespace Miji\Toolbox\Support;

use Closure;
use Miji\Toolbox\Settings\Settings;

/**
 * Empties the page cache when the settings change, so visitors don't get pages built with the old settings
 * (e.g. archive pages that were just removed, or pages without the new security headers).
 *
 * Supports WP-Optimize, the page cache of the usual setup.
 */
final class PageCache {
	/**
	 * @param Closure(): void|null $flush for tests; default: WP-Optimize's wpo_cache_flush() if it exists
	 */
	public function __construct(private readonly ?Closure $flush = null) {
	}

	public function register(): void {
		add_action('add_option_' . Settings::OPTION, [$this, 'flush']);
		add_action('update_option_' . Settings::OPTION, [$this, 'flush']);
	}

	public function flush(): void {
		if ($this->flush !== null) {
			($this->flush)();
		} elseif (function_exists('wpo_cache_flush')) {
			wpo_cache_flush();
		}
	}
}
