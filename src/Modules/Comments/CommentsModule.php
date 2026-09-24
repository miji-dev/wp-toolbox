<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Comments;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use WP_Admin_Bar;
use WP_Block_Type_Registry;
use WP_Query;

/**
 * Turns comments, pingbacks and trackbacks off everywhere. Existing comments stay in the database.
 */
final class CommentsModule implements Module {
	public const EDITOR_SCRIPT = 'wptb-hide-comment-blocks';

	private const ADMIN_PAGES = ['edit-comments.php', 'comment.php', 'options-discussion.php'];

	private const XMLRPC_METHODS = [
		'pingback.ping',
		'pingback.extensions.getPingbacks',
		'wp.getComments',
		'wp.getComment',
		'wp.newComment',
		'wp.editComment',
		'wp.deleteComment',
		'wp.getCommentCount',
		'wp.getCommentStatusList',
	];

	public function id(): string {
		return 'comments';
	}

	public function title(): string {
		return __('Comments', 'wptb');
	}

	public function description(): string {
		return __('Comments, pingbacks and trackbacks.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'disable',
				false,
				__('Disable comments and pingbacks completely', 'wptb'),
				__('Turns off comments, pingbacks and trackbacks everywhere: on all post types, in the REST API and XML-RPC, in feeds, in the admin menu and admin bar, and in the block editor. Existing comments are hidden but stay in the database.', 'wptb'),
				why: __('Most business sites never use comments, but WordPress keeps the whole machinery running: open comment forms attract spam, pingbacks can be abused to attack other sites, and the admin shows menus nobody needs.', 'wptb'),
				sideEffects: __('Comment blocks render nothing and disappear from the block inserter. Comments and Settings → Discussion are no longer reachable. Posts created while this is on are saved with comments closed and stay closed if you switch it off again; everything else comes back, including existing comments.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		if ($settings->get('comments', 'disable') !== true) {
			return;
		}

		// closed everywhere
		add_action('init', [$this, 'removePostTypeSupport'], PHP_INT_MAX);
		add_filter('comments_open', '__return_false', PHP_INT_MAX);
		add_filter('pings_open', '__return_false', PHP_INT_MAX);
		foreach (['default_comment_status', 'default_ping_status'] as $option) {
			add_filter("pre_option_$option", static fn (): string => 'closed');
		}
		add_filter('pre_option_default_pingback_flag', static fn (): string => '0');

		// existing comments hidden
		add_filter('comments_array', '__return_empty_array', PHP_INT_MAX);
		add_filter('get_comments_number', '__return_zero', PHP_INT_MAX);

		// APIs
		add_filter('rest_endpoints', [$this, 'removeRestRoutes']);
		add_filter('xmlrpc_methods', [$this, 'removeXmlrpcMethods']);
		add_filter('wp_headers', [$this, 'removePingbackHeader']);

		// outgoing pings
		add_action('pre_ping', [$this, 'clearPingLinks']);
		add_filter('get_to_ping', '__return_empty_array');

		// feeds
		add_action('template_redirect', [$this, 'blockCommentFeeds'], 0);
		add_filter('feed_links_show_comments_feed', '__return_false');
		add_filter('feed_links_extra_show_post_comments_feed', '__return_false');

		// admin
		add_action('admin_menu', [$this, 'removeAdminPages'], PHP_INT_MAX);
		add_action('admin_init', [$this, 'blockAdminPages']);
		add_action('admin_bar_menu', [$this, 'removeAdminBarItem'], PHP_INT_MAX);
		foreach (['manage_posts_columns', 'manage_pages_columns', 'manage_media_columns'] as $hook) {
			add_filter($hook, [$this, 'removeCommentsColumn']);
		}
		add_action('widgets_init', [$this, 'unregisterWidgets'], PHP_INT_MAX);

		// blocks
		add_filter('pre_render_block', [$this, 'skipCommentBlocks'], 10, 2);
		add_action('enqueue_block_editor_assets', [$this, 'hideBlocksInEditor']);
	}

	public function removePostTypeSupport(): void {
		foreach (get_post_types() as $type) {
			remove_post_type_support($type, 'comments');
			remove_post_type_support($type, 'trackbacks');
		}
	}

	/**
	 * @param array<string, mixed> $endpoints
	 * @return array<string, mixed>
	 */
	public function removeRestRoutes(array $endpoints): array {
		foreach (array_keys($endpoints) as $route) {
			if ($route === '/wp/v2/comments' || str_starts_with($route, '/wp/v2/comments/')) {
				unset($endpoints[$route]);
			}
		}
		return $endpoints;
	}

	/**
	 * @param array<string, mixed> $methods
	 * @return array<string, mixed>
	 */
	public function removeXmlrpcMethods(array $methods): array {
		return array_diff_key($methods, array_flip(self::XMLRPC_METHODS));
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public function removePingbackHeader(array $headers): array {
		unset($headers['X-Pingback']);
		return $headers;
	}

	/**
	 * @param list<string> $links passed by reference by core
	 */
	public function clearPingLinks(array &$links): void {
		$links = [];
	}

	public function blockCommentFeeds(): void {
		global $wp_query;
		if (!$wp_query instanceof WP_Query || !is_comment_feed()) {
			return;
		}

		$wp_query->set_404();
		// set_404() keeps the feed flags, and WordPress would render the feed anyway
		$wp_query->is_feed = false;
		$wp_query->is_comment_feed = false;
		status_header(404);
		nocache_headers();
	}

	public function removeAdminPages(): void {
		remove_menu_page('edit-comments.php');
		remove_submenu_page('options-general.php', 'options-discussion.php');
	}

	public function blockAdminPages(): void {
		global $pagenow;
		if (in_array($pagenow, self::ADMIN_PAGES, true)) {
			wp_safe_redirect(admin_url());
			exit;
		}
	}

	public function removeAdminBarItem(WP_Admin_Bar $bar): void {
		$bar->remove_node('comments');
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function removeCommentsColumn(array $columns): array {
		unset($columns['comments']);
		return $columns;
	}

	public function unregisterWidgets(): void {
		unregister_widget('WP_Widget_Recent_Comments');
	}

	/**
	 * @param string|null $output
	 * @param array<string, mixed> $block
	 */
	public function skipCommentBlocks(?string $output, array $block): ?string {
		return is_string($block['blockName'] ?? null) && self::isCommentBlock($block['blockName']) ? '' : $output;
	}

	public function hideBlocksInEditor(): void {
		$blocks = array_values(array_filter(
			array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered()),
			[self::class, 'isCommentBlock'],
		));

		wp_register_script(self::EDITOR_SCRIPT, false, ['wp-blocks', 'wp-dom-ready'], false, true);
		wp_add_inline_script(self::EDITOR_SCRIPT, sprintf(
			'wp.domReady(function () { %s.forEach(function (name) { if (wp.blocks.getBlockType(name)) { wp.blocks.unregisterBlockType(name); } }); });',
			wp_json_encode($blocks),
		));
		wp_enqueue_script(self::EDITOR_SCRIPT);
	}

	public static function isCommentBlock(string $name): bool {
		return (bool) preg_match('#^core/(comment-|comments$|comments-|latest-comments$|post-comments)#', $name);
	}
}
