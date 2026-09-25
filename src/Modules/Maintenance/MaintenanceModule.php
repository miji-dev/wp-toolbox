<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Maintenance;

use DateTimeImmutable;
use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;

/**
 * Maintenance mode: visitors get a "back soon" page with status 503, logged-in team members see the site.
 */
final class MaintenanceModule implements Module {
	private const DEFAULT_RETRY = HOUR_IN_SECONDS;

	/** @var list<string> */
	private array $roles = [];
	private string $message = '';
	private ?DateTimeImmutable $end = null;

	public function id(): string {
		return 'maintenance';
	}

	public function title(): string {
		return __('Maintenance mode', 'wptb');
	}

	public function description(): string {
		return __('Hide the website from visitors while you work on it.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'enabled',
				false,
				__('Maintenance mode', 'wptb'),
				__('Visitors see a short "back soon" page instead of the website. Logged-in administrators (and the roles chosen below) see the website as usual, with a red "Maintenance mode" notice in the toolbar. The login page and the admin area keep working.', 'wptb'),
				why: __('For relaunches and bigger changes that shouldn\'t be seen half-finished. The page is sent with status 503 ("temporarily unavailable"), which tells search engines to come back later instead of dropping the pages from their index.', 'wptb'),
				sideEffects: __('Leave it on for hours or a few days, not weeks: search engines start removing pages that stay unavailable for long. Feeds and the sitemap are unavailable too; robots.txt and the REST API (used by the block editor, apps and some forms) keep working.', 'wptb'),
			),
			Field::multi(
				'access_roles',
				static fn (): array => array_map('translate_user_role', wp_roles()->get_names()),
				[],
				__('Also show the website to', 'wptb'),
				__('Logged-in users with these roles see the website during maintenance. Administrators always see it, so you can\'t lock yourself out.', 'wptb'),
				why: __('Lets editors or the client check the new site before it goes live.', 'wptb'),
			),
			Field::textarea(
				'message',
				'',
				__('Message', 'wptb'),
				__('Text on the maintenance page, below the site title. Plain text; line breaks are kept. Leave empty for a standard message.', 'wptb'),
				why: __('Tell visitors what\'s happening and how to reach you in the meantime.', 'wptb'),
			),
			Field::datetime(
				'end',
				__('Planned end', 'wptb'),
				__('Date and time (in the site\'s time zone) when maintenance is expected to be over. It is shown on the page and tells search engines when to come back. When it has passed, maintenance mode ends automatically.', 'wptb'),
				why: __('Visitors know when to come back, and a forgotten maintenance mode can\'t keep the site offline.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		if ($settings->get('maintenance', 'enabled') !== true) {
			return;
		}

		$end = $settings->get('maintenance', 'end');
		$this->end = is_string($end) ? self::parseDate($end) : null;
		if ($this->end !== null && $this->end->getTimestamp() <= time()) {
			return;
		}

		$roles = $settings->get('maintenance', 'access_roles');
		$this->roles = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
		$message = $settings->get('maintenance', 'message');
		$this->message = is_string($message) ? $message : '';

		add_action('template_redirect', [$this, 'maybeBlock'], PHP_INT_MIN);
		add_action('admin_bar_menu', [$this, 'addAdminBarNode'], 100);
		add_action('wp_enqueue_scripts', [$this, 'addAdminBarStyle']);
		add_action('admin_enqueue_scripts', [$this, 'addAdminBarStyle']);
	}

	/**
	 * Runs first on template_redirect, i.e. for every front-end request (pages, feeds, search, 404s)
	 * but not for wp-login.php, wp-admin, AJAX, cron or the REST API.
	 */
	public function maybeBlock(): void {
		if ($this->canSeeSite() || is_robots()) {
			return;
		}

		if (!headers_sent()) {
			header('Retry-After: ' . $this->retryAfter());
		}

		$title = get_bloginfo('name', 'display');
		$message = $this->message !== '' ? $this->message : __('This website is currently undergoing maintenance. Please check back soon.', 'wptb');
		$html = sprintf('<h1>%s</h1><p>%s</p>', esc_html($title), nl2br(esc_html($message)));
		if ($this->end !== null) {
			$html .= sprintf(
				'<p>%s</p>',
				esc_html(sprintf(
					/* translators: %s: date and time */
					__('Expected to be back: %s', 'wptb'),
					wp_date(self::dateTimeFormat(), $this->end->getTimestamp()),
				)),
			);
		}

		wp_die($html, esc_html($title), ['response' => 503]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above
	}

	/**
	 * Seconds until the planned end, for the Retry-After header.
	 */
	public function retryAfter(): int {
		if ($this->end === null) {
			return self::DEFAULT_RETRY;
		}
		return max(60, $this->end->getTimestamp() - time());
	}

	public function addAdminBarNode(WP_Admin_Bar $bar): void {
		$node = ['id' => 'wptb-maintenance', 'title' => esc_html__('Maintenance mode', 'wptb')];
		if (current_user_can('manage_options')) {
			$node['href'] = admin_url('options-general.php?page=wp-toolbox');
		}
		$bar->add_node($node);
	}

	public function addAdminBarStyle(): void {
		if (is_admin_bar_showing()) {
			wp_add_inline_style('admin-bar', '#wpadminbar #wp-admin-bar-wptb-maintenance>.ab-item{background:#b32d2e;color:#fff}');
		}
	}

	private function canSeeSite(): bool {
		$user = wp_get_current_user();
		if (!$user->exists()) {
			return false;
		}
		return user_can($user, 'manage_options') || (bool) array_intersect($user->roles, $this->roles);
	}

	private static function dateTimeFormat(): string {
		$date = get_option('date_format');
		$time = get_option('time_format');
		return (is_string($date) && $date !== '' ? $date : 'Y-m-d') . ' ' . (is_string($time) && $time !== '' ? $time : 'H:i');
	}

	/**
	 * Strict: "2026-02-31T10:00" (which the settings pattern lets through) is not a date.
	 */
	private static function parseDate(string $value): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
		return $date !== false && $date->format('Y-m-d\TH:i') === $value ? $date : null;
	}
}
