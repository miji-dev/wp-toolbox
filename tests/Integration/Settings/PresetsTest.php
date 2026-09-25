<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Settings;

use Miji\Toolbox\Plugin;
use Miji\Toolbox\Settings\Presets;
use Miji\Toolbox\Settings\Settings;
use WP_UnitTestCase;

final class PresetsTest extends WP_UnitTestCase {
	public function test_recommended_values_are_valid_settings(): void {
		$settings = new Settings(Plugin::modules());

		$this->assertTrue($settings->update(Presets::recommended()));
	}

	public function test_recommended_leaves_site_specific_decisions_alone(): void {
		// whether a site has comments, a blog or its own login look, and anything that could hide a live site
		$recommended = Presets::recommended();

		foreach (['comments', 'blog', 'login', 'maintenance', 'elementor'] as $module) {
			$this->assertArrayNotHasKey($module, $recommended, $module);
		}
		$this->assertSame(['badge' => 'non_production'], $recommended['environment'] ?? null, 'no noindex or mail blocking: a wrongly set environment type would hit the live site');
	}
}
