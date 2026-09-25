<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Settings;

use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\FakeModule;
use Miji\Toolbox\Tests\Support\IsolatesSettingsRegistration;
use WP_UnitTestCase;

/**
 * Lists whose options come from the site (post types, roles, image sizes) can hold values that no longer exist,
 * e.g. the custom post type of a deactivated plugin. They must not block saving other settings.
 */
final class StaleValuesTest extends WP_UnitTestCase {
	use IsolatesSettingsRegistration;

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->isolateSettingsRegistration();
		$this->settings = new Settings([
			new FakeModule('editor', [
				Field::multi('types', static fn (): array => ['post' => 'Posts', 'page' => 'Pages'], [], 'Types', 'W.', 'H.', 'Y.'),
				Field::bool('other', false, 'Other', 'W.', 'H.', 'Y.'),
			]),
		]);
		// saved while a plugin still registered the "event" post type (so before the check that would reject it now)
		update_option(Settings::OPTION, ['editor' => ['types' => ['page', 'event'], 'other' => false]]);
		// registered like the real plugin does, so WordPress runs the same sanitizing on update_option()
		$this->settings->registerSetting();
	}

	public function tear_down(): void {
		$this->restoreSettingsRegistration();
		parent::tear_down();
	}

	public function test_other_settings_can_still_be_saved(): void {
		$this->assertTrue($this->settings->update(['editor' => ['other' => true]]));

		$this->assertTrue($this->settings->get('editor', 'other'), 'actually stored, not just reported');
		$this->assertSame(['page'], $this->settings->get('editor', 'types'), 'the stale value is dropped on the way');
	}

	public function test_explicitly_saving_an_unknown_value_is_still_rejected(): void {
		$this->assertInstanceOf(\WP_Error::class, $this->settings->update(['editor' => ['types' => ['page', 'event']]]));
	}
}
