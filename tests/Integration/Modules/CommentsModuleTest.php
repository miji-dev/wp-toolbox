<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Comments\CommentsModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\RedirectException;
use WP_Admin_Bar;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class CommentsModuleTest extends WP_UnitTestCase {
	private CommentsModule $module;
	private int $post;

	public function set_up(): void {
		parent::set_up();
		$this->module = new CommentsModule();
		$this->post = self::factory()->post->create(['comment_status' => 'open', 'ping_status' => 'open']);
	}

	public function tear_down(): void {
		// widgets, post types and their supports are global and not restored by the test case
		register_widget('WP_Widget_Recent_Comments');
		unregister_post_type('wptb_book');
		create_initial_post_types();
		parent::tear_down();
	}

	private function enable(bool $on = true): void {
		$this->module->register(new Settings([$this->module], ['comments' => ['disable' => $on]]));
	}

	// --- off: WordPress behaves as usual --------------------------------------------------------

	public function test_off_changes_nothing(): void {
		$this->enable(false);

		$this->assertTrue(comments_open($this->post));
		$this->assertTrue(pings_open($this->post));
		$this->assertTrue(post_type_supports('post', 'comments'));
		$this->assertArrayHasKey('/wp/v2/comments', rest_get_server()->get_routes());
		$this->assertArrayHasKey('pingback.ping', apply_filters('xmlrpc_methods', ['pingback.ping' => 'x']));
		$this->assertSame('open', get_option('default_comment_status'));
		$this->assertTrue(apply_filters('feed_links_show_comments_feed', true));
	}

	// --- on -------------------------------------------------------------------------------------

	public function test_comments_and_pings_are_closed_on_every_post(): void {
		$this->enable();

		$this->assertFalse(comments_open($this->post));
		$this->assertFalse(pings_open($this->post));
	}

	public function test_no_post_type_supports_comments_or_trackbacks(): void {
		register_post_type('wptb_book', ['public' => true, 'supports' => ['title', 'comments', 'trackbacks']]);
		$this->enable();

		$this->module->removePostTypeSupport();

		foreach (['post', 'page', 'attachment', 'wptb_book'] as $type) {
			$this->assertFalse(post_type_supports($type, 'comments'), $type);
			$this->assertFalse(post_type_supports($type, 'trackbacks'), $type);
		}
		$this->assertSame(PHP_INT_MAX, has_action('init', [$this->module, 'removePostTypeSupport']), 'runs after all post types are registered');
	}

	public function test_existing_comments_are_hidden(): void {
		$comment = self::factory()->comment->create_and_get(['comment_post_ID' => $this->post, 'comment_approved' => '1']);
		$this->enable();

		$this->assertSame(0, get_comments_number($this->post));
		$this->assertSame([], apply_filters('comments_array', [$comment], $this->post));
	}

	public function test_new_comments_are_rejected(): void {
		$this->enable();

		$result = wp_handle_comment_submission([
			'comment_post_ID' => $this->post,
			'comment' => 'Buy cheap pills',
			'author' => 'Spammer',
			'email' => 'spam@example.com',
		]);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('comment_closed', $result->get_error_code());
	}

	public function test_new_posts_are_created_with_comments_closed(): void {
		$this->enable();

		$this->assertSame('closed', get_option('default_comment_status'));
		$this->assertSame('closed', get_option('default_ping_status'));
		$this->assertSame('0', get_option('default_pingback_flag'));
		$this->assertSame('closed', get_post(self::factory()->post->create())->comment_status);
	}

	public function test_comment_rest_routes_are_gone(): void {
		$this->enable();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey('/wp/v2/comments', $routes);
		$this->assertArrayNotHasKey('/wp/v2/comments/(?P<id>[\d]+)', $routes);
		$this->assertArrayHasKey('/wp/v2/posts', $routes);

		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$this->assertSame(404, rest_do_request(new WP_REST_Request('GET', '/wp/v2/comments'))->get_status());
		$request = new WP_REST_Request('POST', '/wp/v2/comments');
		$request->set_body_params(['post' => $this->post, 'content' => 'x']);
		$this->assertSame(404, rest_do_request($request)->get_status());
	}

	public function test_xmlrpc_comment_and_pingback_methods_are_gone(): void {
		$this->enable();

		$methods = apply_filters('xmlrpc_methods', array_fill_keys([
			'pingback.ping', 'pingback.extensions.getPingbacks', 'wp.getComments', 'wp.getComment', 'wp.newComment',
			'wp.editComment', 'wp.deleteComment', 'wp.getCommentCount', 'wp.getCommentStatusList', 'wp.getPosts',
		], 'callback'));

		$this->assertSame(['wp.getPosts'], array_keys($methods));
	}

	public function test_pingback_header_is_removed(): void {
		$this->enable();

		$this->assertSame(['Content-Type' => 'text/html'], apply_filters('wp_headers', ['X-Pingback' => 'https://example.org/xmlrpc.php', 'Content-Type' => 'text/html']));
	}

	public function test_no_pings_are_sent(): void {
		$this->enable();

		$links = ['https://example.org/own-post/', 'https://other.example/post/'];
		$pung = [];
		do_action_ref_array('pre_ping', [&$links, &$pung, $this->post]);

		$this->assertSame([], $links, 'no pingbacks, not even self-pings');
		$this->assertSame([], apply_filters('get_to_ping', ['https://other.example/trackback/']), 'no trackbacks');
	}

	public function test_comment_feeds_are_404(): void {
		$this->enable();

		$this->go_to(home_url('/?feed=comments-rss2'));
		$this->assertTrue(is_comment_feed());
		$this->module->blockCommentFeeds();
		$this->assertTrue(is_404());
		$this->assertFalse(is_feed(), 'otherwise WordPress would still render the feed');

		$this->go_to(get_post_comments_feed_link($this->post));
		$this->module->blockCommentFeeds();
		$this->assertTrue(is_404(), 'per-post comment feed');
	}

	public function test_the_regular_feed_keeps_working(): void {
		$this->enable();

		$this->go_to(home_url('/?feed=rss2'));
		$this->module->blockCommentFeeds();

		$this->assertTrue(is_feed());
		$this->assertFalse(is_404());
	}

	public function test_comment_feed_links_are_not_advertised(): void {
		$this->enable();

		$this->assertFalse(apply_filters('feed_links_show_comments_feed', true));
		$this->assertFalse(apply_filters('feed_links_extra_show_post_comments_feed', true));
	}

	public function test_comment_admin_pages_are_removed_from_the_menu(): void {
		global $menu, $submenu;
		$menu = [[__('Comments'), 'edit_posts', 'edit-comments.php'], [__('Posts'), 'edit_posts', 'edit.php']];
		$submenu = ['options-general.php' => [[__('General'), 'manage_options', 'options-general.php'], [__('Discussion'), 'manage_options', 'options-discussion.php']]];
		$this->enable();

		$this->module->removeAdminPages();

		$this->assertSame(['edit.php'], array_column(array_values($menu), 2));
		$this->assertSame(['options-general.php'], array_column(array_values($submenu['options-general.php']), 2));
	}

	/**
	 * @dataProvider blockedAdminPages
	 */
	public function test_comment_admin_pages_redirect_to_the_dashboard(string $page): void {
		global $pagenow;
		$pagenow = $page;
		$this->enable();
		add_filter('wp_redirect', static function (string $location): never {
			throw new RedirectException($location);
		});

		try {
			$this->module->blockAdminPages();
			$this->fail('no redirect');
		} catch (RedirectException $e) {
			$this->assertSame(admin_url(), $e->location);
		}
	}

	public static function blockedAdminPages(): array {
		return [['edit-comments.php'], ['comment.php'], ['options-discussion.php']];
	}

	public function test_other_admin_pages_are_not_redirected(): void {
		global $pagenow;
		$pagenow = 'edit.php';
		$this->enable();
		add_filter('wp_redirect', static function (string $location): never {
			throw new RedirectException($location);
		});

		$this->module->blockAdminPages();
		$this->assertSame('edit.php', $pagenow);
	}

	public function test_admin_bar_comments_item_is_removed(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		$bar->add_node(['id' => 'comments', 'title' => 'Comments']);
		$bar->add_node(['id' => 'new-content', 'title' => 'New']);
		$this->enable();

		$this->module->removeAdminBarItem($bar);

		$this->assertNull($bar->get_node('comments'));
		$this->assertNotNull($bar->get_node('new-content'));
	}

	public function test_comments_column_is_removed_from_post_lists(): void {
		$this->enable();

		$this->assertSame(['title' => 'Title'], apply_filters('manage_posts_columns', ['title' => 'Title', 'comments' => 'Comments']));
		$this->assertSame(['title' => 'Title'], apply_filters('manage_pages_columns', ['title' => 'Title', 'comments' => 'Comments']));
		$this->assertSame(['title' => 'Title'], apply_filters('manage_media_columns', ['title' => 'Title', 'comments' => 'Comments']));
	}

	public function test_recent_comments_widget_is_unregistered(): void {
		global $wp_widget_factory;
		$this->enable();

		$this->module->unregisterWidgets();

		$this->assertArrayNotHasKey('WP_Widget_Recent_Comments', $wp_widget_factory->widgets);
		$this->assertArrayHasKey('WP_Widget_Recent_Posts', $wp_widget_factory->widgets);
	}

	public function test_comment_blocks_render_nothing(): void {
		self::factory()->comment->create(['comment_post_ID' => $this->post, 'comment_approved' => '1', 'comment_content' => 'visible?']);
		$this->enable();

		$this->assertSame('', do_blocks('<!-- wp:latest-comments /-->'));
		$this->assertSame('', do_blocks('<!-- wp:post-comments-form /-->'));
		$this->assertStringContainsString('Hello', do_blocks('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'));
	}

	public function test_comment_blocks_are_removed_from_the_inserter(): void {
		$this->enable();

		$this->module->hideBlocksInEditor();

		$script = implode("\n", (array) wp_scripts()->get_data(CommentsModule::EDITOR_SCRIPT, 'after'));
		foreach (['core/comments', 'core/latest-comments', 'core/post-comments-form', 'core/comment-template'] as $block) {
			$this->assertStringContainsString('"' . $block . '"', str_replace('\\/', '/', $script), $block);
		}
		$this->assertStringNotContainsString('core/paragraph', $script);
		$this->assertTrue(wp_script_is(CommentsModule::EDITOR_SCRIPT, 'enqueued'));
	}

	public function test_off_by_default(): void {
		// whether a site has comments is decided per project
		$this->assertFalse($this->module->fields()[0]->default);
	}
}
