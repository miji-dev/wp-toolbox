<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Elementor;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;
use WP_Post;

/**
 * Elementor: content built with Elementor opens in Elementor.
 *
 * Only relies on Elementor's "elementor/loaded" action and the "_elementor_edit_mode" post meta it sets to
 * "builder" for content built with it, so nothing breaks if Elementor's PHP classes change.
 */
final class ElementorModule implements Module {
	public function id(): string {
		return 'elementor';
	}

	public function title(): string {
		return __('Elementor', 'wptb');
	}

	public function description(): string {
		return __('Settings for sites built with Elementor. Only shown while Elementor is active.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'open_in_elementor',
				false,
				__('Open Elementor pages in Elementor', 'wptb'),
				__('For content built with Elementor, the title in the page and post lists and "Edit" in the toolbar open Elementor directly. In the lists, "Edit with Elementor" is replaced by a "WordPress editor" link. Content not built with Elementor keeps opening in the WordPress editor.', 'wptb'),
				why: __('Editors of Elementor sites almost always want Elementor. Opening the WordPress editor first is an extra step and confusing, because the page content isn\'t editable there.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return did_action('elementor/loaded') > 0;
	}

	public function register(Settings $settings): void {
		if ($settings->get('elementor', 'open_in_elementor') !== true) {
			return;
		}
		add_filter('get_edit_post_link', [$this, 'listEditLink'], 10, 3);
		add_filter('page_row_actions', [$this, 'rowActions'], 99, 2);
		add_filter('post_row_actions', [$this, 'rowActions'], 99, 2);
		add_action('admin_bar_menu', [$this, 'toolbarEditLink'], 81);
	}

	/**
	 * Only on the post list screens: everywhere else (redirects after saving, the block editor, REST) the link must stay as it is.
	 */
	public function listEditLink(mixed $link, mixed $postId, mixed $context): mixed {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!is_string($link) || !is_int($postId) || $screen?->base !== 'edit' || !self::builtWithElementor($postId)) {
			return $link;
		}
		$url = self::elementorUrl($postId);
		return $context === 'display' ? esc_url($url) : $url;
	}

	/**
	 * @param mixed $actions row action name => HTML
	 * @return mixed
	 */
	public function rowActions(mixed $actions, mixed $post): mixed {
		if (!is_array($actions) || !$post instanceof WP_Post || !self::builtWithElementor($post->ID) || !current_user_can('edit_post', $post->ID)) {
			return $actions;
		}
		unset($actions['edit_with_elementor']);
		$actions['edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(admin_url('post.php?post=' . $post->ID . '&action=edit')),
			esc_html__('WordPress editor', 'wptb'),
		);
		return $actions;
	}

	/**
	 * "Edit Page" in the toolbar on the website, added by core at priority 80.
	 */
	public function toolbarEditLink(WP_Admin_Bar $bar): void {
		$post = get_queried_object();
		$node = $bar->get_node('edit');
		if (is_admin() || $node === null || !$post instanceof WP_Post || !self::builtWithElementor($post->ID) || !current_user_can('edit_post', $post->ID)) {
			return;
		}
		$bar->add_node(['id' => 'edit', 'href' => self::elementorUrl($post->ID)]);
	}

	private static function builtWithElementor(int $postId): bool {
		return get_post_meta($postId, '_elementor_edit_mode', true) === 'builder';
	}

	private static function elementorUrl(int $postId): string {
		return admin_url('post.php?post=' . $postId . '&action=elementor');
	}
}
