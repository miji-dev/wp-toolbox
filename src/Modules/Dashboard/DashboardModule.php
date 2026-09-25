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
	 * @return array<string, string|array{label: string, description: string}> widget id => label
	 */
	private static function widgets(): array {
		$widgets = [
			'welcome_panel' => Field::option(__('Welcome panel ("Welcome to WordPress!")', 'wptb'), __('Large box with links for getting started with WordPress.', 'wptb')),
			'dashboard_primary' => Field::option(__('WordPress Events and News', 'wptb'), __('News from wordpress.org and WordPress meetups near the site\'s location.', 'wptb')),
			'dashboard_quick_press' => Field::option(__('Quick Draft', 'wptb'), __('Form for writing a draft post directly on the dashboard.', 'wptb')),
			'dashboard_activity' => Field::option(__('Activity (recent posts and comments)', 'wptb'), __('Recently published and scheduled posts and the latest comments.', 'wptb')),
			'dashboard_right_now' => Field::option(__('At a Glance', 'wptb'), __('Number of posts, pages and comments, plus the WordPress version and theme.', 'wptb')),
			'dashboard_site_health' => Field::option(__('Site Health Status', 'wptb'), __('Summary of WordPress\' technical self-check (details under Tools → Site Health).', 'wptb')),
		];
		if (defined('WPSEO_VERSION')) {
			$widgets['wpseo-dashboard-overview'] = Field::option(__('Yoast SEO: Posts Overview', 'wptb'), __('SEO and readability scores of the posts and pages.', 'wptb'));
			$widgets['wpseo-wincher-dashboard-overview'] = Field::option(__('Yoast SEO / Wincher: Top Keyphrases', 'wptb'), __('Search rankings from the Wincher service; only useful with a Wincher account.', 'wptb'));
		}
		if (defined('WORDFENCE_VERSION')) {
			$widgets['wordfence_activity_report_widget'] = Field::option(__('Wordfence: activity report', 'wptb'), __('Blocked attacks, failed logins and updates of the last days. Wordfence can also email this report.', 'wptb'));
		}
		if (defined('ELEMENTOR_VERSION')) {
			$widgets['e-dashboard-overview'] = Field::option(__('Elementor Overview', 'wptb'), __('Recently edited Elementor pages, plus news and offers from Elementor.', 'wptb'));
		}
		if (defined('WPO_VERSION')) {
			$widgets['wp_optimize_performance'] = Field::option(__('WP-Optimize: Performance', 'wptb'), __('Performance tips and offers from WP-Optimize.', 'wptb'));
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
