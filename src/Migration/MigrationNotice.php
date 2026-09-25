<?php

declare(strict_types=1);

namespace Miji\Toolbox\Migration;

use WP_Post;

/**
 * After the update from 3.x: tells administrators which removed features were in use, until they dismiss it.
 */
final class MigrationNotice {
	public const ACTION = 'wptb_dismiss_migration_notice';
	private const MAX_PAGES = 20;

	public function register(): void {
		add_action('admin_notices', [$this, 'render']);
		add_action('admin_post_' . self::ACTION, [$this, 'dismiss']);
	}

	public function render(): void {
		$notice = get_option(Migration::NOTICE_OPTION);
		if (!is_array($notice) || !current_user_can('manage_options')) {
			return;
		}

		$items = [];
		if (!empty($notice['maintenance'])) {
			$items[] = esc_html__('Maintenance mode was on, but is no longer part of wp toolbox: the site is visible to visitors again. Elementor has its own maintenance mode (Elementor → Tools → Maintenance Mode).', 'wptb');
		}
		if (!empty($notice['analytics'])) {
			$items[] = esc_html__('Google Analytics and the cookie banner are no longer part of wp toolbox: tracking has stopped and the banner is gone. Use a consent management tool (CMP) with Google Tag Manager or Consent Mode instead.', 'wptb');
		}
		$pages = is_array($notice['pages'] ?? null) ? array_values(array_filter($notice['pages'], 'is_int')) : [];
		if ($pages) {
			$links = [];
			foreach (array_slice($pages, 0, self::MAX_PAGES) as $id) {
				$post = get_post($id);
				if ($post instanceof WP_Post) {
					$links[] = sprintf('<a href="%s">%s</a>', esc_url((string) get_edit_post_link($id)), esc_html(get_the_title($post) ?: "#$id"));
				}
			}
			$more = count($pages) - self::MAX_PAGES;
			$items[] = esc_html__('These pages still use shortcodes or the map widget of wp toolbox 3 (YouTube, Google Maps, Analytics opt-out, cookie settings button), which now show nothing:', 'wptb')
				. ' ' . implode(', ', $links)
				/* translators: %d: number of further pages */
				. ($more > 0 ? ' ' . esc_html(sprintf(__('and %d more.', 'wptb'), $more)) : '');
		}
		if (!$items) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p><ul style="list-style:disc;padding-left:2em">%s</ul><p><a href="%s">%s</a></p></div>',
			esc_html__('wp toolbox 4 has replaced version 3. Please check:', 'wptb'),
			implode('', array_map(static fn (string $item): string => "<li>$item</li>", $items)), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- items are escaped above
			esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION)),
			esc_html__('Done, hide this notice', 'wptb'),
		);
	}

	public function dismiss(): void {
		check_admin_referer(self::ACTION);
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to do that.', 'wptb'), '', ['response' => 403]);
		}
		delete_option(Migration::NOTICE_OPTION);
		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}
}
