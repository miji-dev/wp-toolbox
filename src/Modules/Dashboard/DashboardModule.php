<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Dashboard;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Removes dashboard boxes nobody uses, including the ones of common plugins.
 */
final class DashboardModule implements Module {
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
				static fn (): array => self::widgets(),
				['welcome_panel', 'dashboard_primary'],
				__('Hide dashboard boxes', 'wptb'),
				what: __('The selected boxes are removed from the dashboard for everyone. Boxes of Yoast SEO, Wordfence, Elementor and WP-Optimize are listed while those plugins are active.', 'wptb'),
				how: __('Removes the boxes after WordPress and all plugins have registered theirs (and the welcome panel from its hook), in every dashboard column.', 'wptb'),
				why: __('Most boxes are meant for bloggers, advertise something or duplicate information available elsewhere. A clean start page helps editors find what matters.', 'wptb'),
				sideEffects: __('The information itself stays available: Site Health under Tools, Wordfence and WP-Optimize in their own menus.', 'wptb'),
			),
		];
	}

	/**
	 * @return array<string, string> widget id => label
	 */
	private static function widgets(): array {
		$widgets = [
			'welcome_panel' => __('Welcome panel ("Welcome to WordPress!")', 'wptb'),
			'dashboard_primary' => __('WordPress Events and News', 'wptb'),
			'dashboard_quick_press' => __('Quick Draft', 'wptb'),
			'dashboard_activity' => __('Activity (recent posts and comments)', 'wptb'),
			'dashboard_right_now' => __('At a Glance', 'wptb'),
			'dashboard_site_health' => __('Site Health Status', 'wptb'),
		];
		if (defined('WPSEO_VERSION')) {
			$widgets['wpseo-dashboard-overview'] = __('Yoast SEO: Posts Overview', 'wptb');
			$widgets['wpseo-wincher-dashboard-overview'] = __('Yoast SEO / Wincher: Top Keyphrases', 'wptb');
		}
		if (defined('WORDFENCE_VERSION')) {
			$widgets['wordfence_activity_report_widget'] = __('Wordfence: activity report', 'wptb');
		}
		if (defined('ELEMENTOR_VERSION')) {
			$widgets['e-dashboard-overview'] = __('Elementor Overview', 'wptb');
		}
		if (defined('WPO_VERSION')) {
			$widgets['wp_optimize_performance'] = __('WP-Optimize: Performance', 'wptb');
		}
		return $widgets;
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
		foreach ($this->hidden as $id) {
			// plugins don't always register their box where core does, and users can drag boxes around
			foreach (['normal', 'side', 'column3', 'column4'] as $context) {
				remove_meta_box($id, 'dashboard', $context);
			}
		}
	}

	public function removeWelcomePanel(): void {
		remove_action('welcome_panel', 'wp_welcome_panel');
	}
}
