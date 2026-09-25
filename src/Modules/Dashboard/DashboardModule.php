<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Dashboard;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Removes dashboard boxes nobody uses.
 */
final class DashboardModule implements Module {
	/** Widget id => context it is registered in by core. */
	private const WIDGETS = [
		'dashboard_primary' => 'side',
		'dashboard_quick_press' => 'side',
		'dashboard_activity' => 'normal',
		'dashboard_right_now' => 'normal',
		'dashboard_site_health' => 'normal',
	];

	/** @var list<string> */
	private array $hidden = [];

	public function id(): string {
		return 'dashboard';
	}

	public function title(): string {
		return __('Dashboard', 'wptb');
	}

	public function description(): string {
		return __('The start page of the admin area.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::multi(
				'hide_widgets',
				[
					'welcome_panel' => __('Welcome panel ("Welcome to WordPress!")', 'wptb'),
					'dashboard_primary' => __('WordPress Events and News', 'wptb'),
					'dashboard_quick_press' => __('Quick Draft', 'wptb'),
					'dashboard_activity' => __('Activity (recent posts and comments)', 'wptb'),
					'dashboard_right_now' => __('At a Glance', 'wptb'),
					'dashboard_site_health' => __('Site Health Status', 'wptb'),
				],
				[],
				__('Hide dashboard boxes', 'wptb'),
				__('The selected boxes are removed from the dashboard for everyone. Boxes added by other plugins are not affected.', 'wptb'),
				why: __('Most of the default boxes are meant for bloggers or advertise wordpress.org. A cleaner start page helps editors find what matters.', 'wptb'),
				sideEffects: __('Site Health stays available under Tools → Site Health.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$value = $settings->get('dashboard', 'hide_widgets');
		$this->hidden = is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
		if (!$this->hidden) {
			return;
		}

		add_action('wp_dashboard_setup', [$this, 'removeWidgets'], PHP_INT_MAX);
		if (in_array('welcome_panel', $this->hidden, true)) {
			// added by admin-filters.php, after init
			add_action('admin_init', [$this, 'removeWelcomePanel']);
		}
	}

	public function removeWidgets(): void {
		foreach (self::WIDGETS as $id => $context) {
			if (in_array($id, $this->hidden, true)) {
				remove_meta_box($id, 'dashboard', $context);
			}
		}
	}

	public function removeWelcomePanel(): void {
		remove_action('welcome_panel', 'wp_welcome_panel');
	}
}
