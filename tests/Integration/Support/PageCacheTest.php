<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Support;

use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Support\PageCache;
use WP_UnitTestCase;

final class PageCacheTest extends WP_UnitTestCase {
	public function test_page_cache_is_emptied_when_the_settings_change(): void {
		$flushed = 0;
		(new PageCache(static function () use (&$flushed): void {
			$flushed++;
		}))->register();

		update_option(Settings::OPTION, ['comments' => ['disable' => true]]);
		$this->assertSame(1, $flushed, 'first save');

		update_option(Settings::OPTION, ['comments' => ['disable' => false]]);
		$this->assertSame(2, $flushed, 'changed');

		update_option(Settings::OPTION, ['comments' => ['disable' => false]]);
		$this->assertSame(2, $flushed, 'unchanged: WordPress doesn\'t save, nothing to flush');
	}

	public function test_nothing_happens_without_a_page_cache_plugin(): void {
		// WP-Optimize not active: its function doesn't exist
		(new PageCache())->register();

		update_option(Settings::OPTION, ['comments' => ['disable' => true]]);

		$this->assertFalse(function_exists('wpo_cache_flush'));
	}
}
