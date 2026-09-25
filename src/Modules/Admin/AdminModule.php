<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Admin;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;
use WP_Error;

/**
 * Less noise in the admin: admin bar, footer, update notices, notification emails.
 */
final class AdminModule implements Module {
	/** @var list<string> */
	private array $hideAdminBarFor = [];
	/** @var list<string> */
	private array $adminBarItems = [];
	/** @var list<string> */
	private array $noUpdateEmails = [];

	public function id(): string {
		return 'admin';
	}

	public function title(): string {
		return __('Admin', 'wptb');
	}

	public function description(): string {
		return __('A calmer admin: fewer menus, notices and emails that nobody acts on.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::multi(
				'hide_admin_bar_for',
				static fn (): array => array_map('translate_user_role', wp_roles()->get_names()),
				[],
				__('Hide the admin bar on the website for', 'wptb'),
				__('Users with the selected roles don\'t see the black admin bar at the top of the website. The admin area is not affected.', 'wptb'),
				why: __('The admin bar shifts the layout and confuses users who only log in to e.g. read protected content. Editors often prefer to see the site as visitors see it.', 'wptb'),
			),
			Field::multi(
				'admin_bar_items',
				[
					'wp-logo' => __('WordPress logo menu (links to wordpress.org)', 'wptb'),
					'new-content' => __('"+ New" menu', 'wptb'),
					'customize' => __('"Customize" link', 'wptb'),
					'updates' => __('Updates counter', 'wptb'),
					'search' => __('Search field (on the website)', 'wptb'),
				],
				[],
				__('Remove admin bar items', 'wptb'),
				__('Removes the selected items from the admin bar for everyone.', 'wptb'),
				why: __('Items nobody uses make the bar harder to read. The WordPress logo menu only links to wordpress.org.', 'wptb'),
			),
			Field::bool(
				'hide_footer',
				false,
				__('Remove the admin footer text', 'wptb'),
				__('Removes "Thank you for creating with WordPress" and the version number from the bottom of every admin page.', 'wptb'),
				why: __('Less clutter, and the WordPress version isn\'t shown to every user who can log in.', 'wptb'),
			),
			Field::bool(
				'hide_update_notices_for_non_admins',
				false,
				__('Hide update notices from non-administrators', 'wptb'),
				__('Users who can\'t install updates no longer see "WordPress X is available! Please notify the site administrator." Administrators still see all update notices.', 'wptb'),
				why: __('Editors can\'t act on these notices, and they worry clients who think something is broken.', 'wptb'),
			),
			Field::bool(
				'disable_admin_email_check',
				false,
				__('Don\'t ask to confirm the admin email address', 'wptb'),
				__('WordPress regularly interrupts the login of administrators with "Is this still your email address?". This turns that screen off.', 'wptb'),
				why: __('When a site is looked after by an agency, the question interrupts the wrong people and the answer is always the same.', 'wptb'),
				sideEffects: __('Keep the admin email address (Settings → General) correct yourself: WordPress sends security notices there.', 'wptb'),
			),
			Field::multi(
				'disable_update_emails',
				[
					'core' => __('WordPress updates', 'wptb'),
					'plugins' => __('Plugin updates', 'wptb'),
					'themes' => __('Theme updates', 'wptb'),
				],
				[],
				__('No emails about successful automatic updates', 'wptb'),
				__('WordPress sends an email after every automatic update. For the selected types, emails about successful updates are no longer sent. Emails about failed updates are always sent.', 'wptb'),
				why: __('With automatic updates on, success emails arrive all the time and teach people to ignore them, including the important ones about failures.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$this->hideAdminBarFor = self::strings($settings->get('admin', 'hide_admin_bar_for'));
		$this->adminBarItems = self::strings($settings->get('admin', 'admin_bar_items'));
		$this->noUpdateEmails = self::strings($settings->get('admin', 'disable_update_emails'));

		if ($this->hideAdminBarFor) {
			add_filter('show_admin_bar', [$this, 'showAdminBar'], PHP_INT_MAX);
		}
		if ($this->adminBarItems) {
			add_action('admin_bar_menu', [$this, 'removeAdminBarItems'], PHP_INT_MAX);
		}
		if ($settings->get('admin', 'hide_footer') === true) {
			add_filter('admin_footer_text', '__return_empty_string', PHP_INT_MAX);
			add_filter('update_footer', '__return_empty_string', PHP_INT_MAX);
		}
		if ($settings->get('admin', 'hide_update_notices_for_non_admins') === true) {
			// the notices are added by admin-filters.php, after init
			add_action('admin_init', [$this, 'removeUpdateNotices']);
		}
		if ($settings->get('admin', 'disable_admin_email_check') === true) {
			add_filter('admin_email_check_interval', '__return_zero');
		}
		if (in_array('core', $this->noUpdateEmails, true)) {
			add_filter('auto_core_update_send_email', [$this, 'coreUpdateEmail'], 10, 2);
		}
		if (in_array('plugins', $this->noUpdateEmails, true)) {
			add_filter('auto_plugin_update_send_email', [$this, 'updateEmail'], 10, 2);
		}
		if (in_array('themes', $this->noUpdateEmails, true)) {
			add_filter('auto_theme_update_send_email', [$this, 'updateEmail'], 10, 2);
		}
	}

	public function showAdminBar(mixed $show): mixed {
		$user = wp_get_current_user();
		return $user->exists() && array_intersect($user->roles, $this->hideAdminBarFor) ? false : $show;
	}

	public function removeAdminBarItems(WP_Admin_Bar $bar): void {
		foreach ($this->adminBarItems as $id) {
			$bar->remove_node($id);
		}
	}

	public function removeUpdateNotices(): void {
		if (!current_user_can('update_core')) {
			remove_action('admin_notices', 'update_nag', 3);
			remove_action('admin_notices', 'maintenance_nag', 10);
		}
	}

	public function coreUpdateEmail(mixed $send, mixed $type): mixed {
		return $type === 'success' ? false : $send;
	}

	/**
	 * Only when every update in the batch succeeded; failures are always reported.
	 *
	 * @param mixed $results list of update result objects with ->result (true or WP_Error)
	 */
	public function updateEmail(mixed $send, mixed $results): mixed {
		if (!is_array($results)) {
			return $send;
		}
		foreach ($results as $result) {
			if (!is_object($result) || !isset($result->result) || $result->result instanceof WP_Error || $result->result !== true) {
				return $send;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
