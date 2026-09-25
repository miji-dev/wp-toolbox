<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Admin;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;
use WP_Error;

/**
 * Less noise in the admin: toolbar, update notices, notification emails.
 */
final class AdminModule implements Module {
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
				'admin_bar_items',
				[
					'wp-logo' => Field::option(__('WordPress logo menu (links to wordpress.org)', 'wptb'), __('The WordPress logo on the far left, with links to wordpress.org, the documentation and the support forums.', 'wptb')),
					'new-content' => Field::option(__('"+ New" menu', 'wptb'), __('Shortcut to create a new post, page, media file or user.', 'wptb')),
					'customize' => Field::option(__('"Customize" link', 'wptb'), __('Shown on the website with classic themes; opens the Customizer, WordPress\' live editor for theme options. Elementor sites and block themes don\'t need it.', 'wptb')),
					'updates' => Field::option(__('Updates counter', 'wptb'), __('The circular arrow with the number of available updates. The updates themselves stay under Dashboard → Updates.', 'wptb')),
					'search' => Field::option(__('Search field (on the website)', 'wptb'), __('The magnifier on the right of the toolbar while viewing the website; it searches the website.', 'wptb')),
					'command-palette' => Field::option(__('Command palette (in the admin)', 'wptb'), __('The search field with ⌘K / Ctrl+K in the admin toolbar, which jumps to admin pages and actions. The keyboard shortcut keeps working.', 'wptb')),
				],
				[],
				__('Remove toolbar items', 'wptb'),
				what: __('Removes the selected items from the black toolbar at the top, for everyone, in the admin and on the website.', 'wptb'),
				how: __('Removes the items\' nodes from the toolbar after WordPress and all plugins have added theirs.', 'wptb'),
				why: __('Items nobody uses make the toolbar harder to read. The WordPress logo menu only links to wordpress.org.', 'wptb'),
			),
			Field::bool(
				'hide_update_notices_for_non_admins',
				true,
				__('Hide update notices from non-administrators', 'wptb'),
				what: __('Users who can\'t install updates (e.g. editors) no longer see "WordPress X is available! Please notify the site administrator." Administrators still see all update notices.', 'wptb'),
				how: __('Removes WordPress\' update and maintenance notices from the admin for users without the update_core capability.', 'wptb'),
				why: __('Editors can\'t act on these notices, and they worry clients who think something is broken.', 'wptb'),
			),
			Field::bool(
				'disable_admin_email_check',
				true,
				__('Don\'t ask to confirm the admin email address', 'wptb'),
				what: __('WordPress regularly interrupts the login of administrators with "Is this still your email address?". This turns that screen off.', 'wptb'),
				how: __('Sets WordPress\' admin_email_check_interval to 0, which disables the check.', 'wptb'),
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
				what: __('For the selected types, WordPress no longer emails after every successful automatic update. Emails about failed updates are always sent.', 'wptb'),
				how: __('Answers WordPress\' "send update email?" filters with no when every update in the batch succeeded.', 'wptb'),
				why: __('With automatic updates on, success emails arrive all the time and teach people to ignore them, including the important ones about failures.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$this->adminBarItems = self::strings($settings->get('admin', 'admin_bar_items'));
		$this->noUpdateEmails = self::strings($settings->get('admin', 'disable_update_emails'));

		if ($this->adminBarItems) {
			add_action('admin_bar_menu', [$this, 'removeAdminBarItems'], PHP_INT_MAX);
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
