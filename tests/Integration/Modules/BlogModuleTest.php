<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Blog\BlogModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\DefaultsOff;
use Miji\Toolbox\Tests\Support\RedirectException;
use WP_REST_Request;
use WP_UnitTestCase;

final class BlogModuleTest extends WP_UnitTestCase {
	private BlogModule $module;
	private int $author;
	private int $post;
	private int $page;
	private int $category;
	private int $tag;

	public function set_up(): void {
		parent::set_up();
		$this->module = new BlogModule();
		$this->author = self::factory()->user->create(['role' => 'editor', 'user_login' => 'secret-login', 'user_nicename' => 'secret-login']);
		$this->category = self::factory()->category->create(['slug' => 'news']);
		$this->tag = self::factory()->tag->create(['slug' => 'misc']);
		$this->post = self::factory()->post->create(['post_author' => $this->author, 'post_title' => 'Blog post findme', 'post_category' => [$this->category], 'tags_input' => ['misc']]);
		$this->page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About findme']);
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		// post type and taxonomy objects are global and not restored by the test case
		create_initial_post_types();
		create_initial_taxonomies();
		foreach (['WP_Widget_Recent_Posts', 'WP_Widget_Archives', 'WP_Widget_Calendar', 'WP_Widget_Categories', 'WP_Widget_Tag_Cloud', 'WP_Widget_Search'] as $widget) {
			register_widget($widget);
		}
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['blog' => DefaultsOff::with('blog', $values)]));
		// what the module hooks on init (the test boot already ran it)
		$this->module->hideContentTypes();
	}

	private function visit(string $url): void {
		$this->go_to($url);
		$this->module->handleRequest();
	}

	private function catchRedirect(callable $fn): RedirectException {
		add_filter('wp_redirect', static function (string $location, int $status): never {
			throw new RedirectException($location . ' ' . $status);
		}, 10, 2);
		try {
			$fn();
		} catch (RedirectException $e) {
			return $e;
		}
		$this->fail('no redirect');
	}

	// --- off --------------------------------------------------------------------------------------

	public function test_off_changes_nothing(): void {
		$attachment_pages = get_option('wp_attachment_pages_enabled');
		$this->enable([]);

		$this->assertTrue(get_post_type_object('post')->public);
		$this->assertTrue(get_taxonomy('category')->public);
		$this->assertArrayHasKey('/wp/v2/posts', rest_get_server()->get_routes());

		foreach ([get_author_posts_url($this->author), get_category_link($this->category), get_permalink($this->post), home_url('/?feed=rss2'), home_url('/?s=findme')] as $url) {
			$this->visit($url);
			$this->assertFalse(is_404(), $url);
		}
		$this->assertSame($attachment_pages, get_option('wp_attachment_pages_enabled'));
		$this->assertNotFalse(has_action('wp_head', 'feed_links'));
	}

	// --- posts -------------------------------------------------------------------------------------

	public function test_posts_disappear_from_admin_frontend_and_apis(): void {
		$this->enable(['disable_posts' => true]);

		$type = get_post_type_object('post');
		foreach (['public', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_admin_bar', 'show_in_rest'] as $flag) {
			$this->assertFalse((bool) $type->$flag, $flag);
		}
		$this->assertTrue($type->exclude_from_search);
		$this->assertArrayNotHasKey('/wp/v2/posts', rest_get_server()->get_routes());
		$this->assertArrayHasKey('/wp/v2/pages', rest_get_server()->get_routes());
	}

	public function test_single_posts_and_the_blog_index_are_404_but_pages_are_not(): void {
		update_option('show_on_front', 'page');
		update_option('page_on_front', $this->page);
		update_option('page_for_posts', self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Blog']));
		$this->enable(['disable_posts' => true]);

		$this->visit(get_permalink($this->post));
		$this->assertTrue(is_404(), 'single post');

		$this->visit(get_permalink((int) get_option('page_for_posts')));
		$this->assertTrue(is_404(), 'posts page');

		$this->visit(get_permalink($this->page));
		$this->assertFalse(is_404(), 'pages still work');
	}

	public function test_posts_are_excluded_from_search_and_sitemaps(): void {
		$this->enable(['disable_posts' => true]);

		$found = (new \WP_Query(['s' => 'findme', 'post_type' => 'any', 'fields' => 'ids']))->posts;
		$this->assertSame([$this->page], $found);

		$provider = wp_sitemaps_get_server()->registry->get_provider('posts');
		$this->assertArrayNotHasKey('post', $provider->get_object_subtypes());
		$this->assertArrayHasKey('page', $provider->get_object_subtypes());
	}

	/**
	 * @dataProvider postAdminPages
	 */
	public function test_post_admin_screens_redirect_to_the_dashboard(string $page, array $get): void {
		global $pagenow, $typenow;
		$pagenow = $page;
		$typenow = $get['post_type'] ?? 'post';
		$this->enable(['disable_posts' => true]);

		$e = $this->catchRedirect(fn () => $this->module->blockAdminPages());
		$this->assertSame(admin_url() . ' 302', $e->location);
	}

	public static function postAdminPages(): array {
		return [
			'posts list' => ['edit.php', []],
			'new post' => ['post-new.php', []],
			'explicit post type' => ['edit.php', ['post_type' => 'post']],
		];
	}

	public function test_page_admin_screens_are_not_redirected(): void {
		global $pagenow, $typenow;
		$pagenow = 'edit.php';
		$typenow = 'page';
		$this->enable(['disable_posts' => true]);
		add_filter('wp_redirect', static function (): never {
			throw new RedirectException('unexpected');
		});

		$this->module->blockAdminPages();
		$this->assertSame('page', $typenow);
	}

	public function test_post_widgets_blocks_and_quick_draft_are_removed(): void {
		global $wp_widget_factory, $wp_meta_boxes;
		$this->enable(['disable_posts' => true]);

		$this->module->unregisterWidgets();
		foreach (['WP_Widget_Recent_Posts', 'WP_Widget_Archives', 'WP_Widget_Calendar'] as $widget) {
			$this->assertArrayNotHasKey($widget, $wp_widget_factory->widgets, $widget);
		}
		$this->assertArrayHasKey('WP_Widget_Text', $wp_widget_factory->widgets);

		$this->assertSame('', do_blocks('<!-- wp:latest-posts /-->'));
		$this->assertSame('', do_blocks('<!-- wp:archives /-->'));
		$this->assertStringContainsString('core/latest-posts', str_replace('\\/', '/', $this->editorScript()));

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen('dashboard');
		add_meta_box('dashboard_quick_press', 'Quick Draft', '__return_null', 'dashboard', 'side');
		$this->module->removeDashboardWidgets();
		$this->assertFalse($wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press'] ?? false);
	}

	// --- taxonomies --------------------------------------------------------------------------------

	public function test_removed_taxonomies_disappear_everywhere(): void {
		$this->enable(['remove_taxonomies' => ['category', 'post_tag']]);

		foreach (['category', 'post_tag'] as $name) {
			$tax = get_taxonomy($name);
			foreach (['public', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_rest', 'show_tagcloud', 'show_admin_column'] as $flag) {
				$this->assertFalse((bool) $tax->$flag, "$name $flag");
			}
			$this->assertNotContains($name, get_object_taxonomies('post'), $name);
		}
		$routes = rest_get_server()->get_routes();
		$this->assertArrayNotHasKey('/wp/v2/categories', $routes);
		$this->assertArrayNotHasKey('/wp/v2/tags', $routes);
	}

	public function test_removing_only_tags_keeps_categories(): void {
		$this->enable(['remove_taxonomies' => ['post_tag']]);

		$this->assertTrue(get_taxonomy('category')->public);
		$this->assertContains('category', get_object_taxonomies('post'));
		$this->assertFalse(get_taxonomy('post_tag')->public);
	}

	public function test_archives_of_removed_taxonomies_are_gone_too(): void {
		$this->enable(['remove_taxonomies' => ['category']]);

		$this->visit(home_url('/?cat=' . $this->category));
		$this->assertTrue(is_404());
	}

	public function test_taxonomy_widgets_and_blocks_are_removed(): void {
		global $wp_widget_factory;
		$this->enable(['remove_taxonomies' => ['category', 'post_tag']]);

		$this->module->unregisterWidgets();
		$this->assertArrayNotHasKey('WP_Widget_Categories', $wp_widget_factory->widgets);
		$this->assertArrayNotHasKey('WP_Widget_Tag_Cloud', $wp_widget_factory->widgets);
		$this->assertSame('', do_blocks('<!-- wp:categories /-->'));
		$this->assertSame('', do_blocks('<!-- wp:tag-cloud /-->'));
	}

	// --- archives ----------------------------------------------------------------------------------

	/**
	 * @dataProvider archiveTypes
	 */
	public function test_selected_archives_are_404(string $type, string $url_template): void {
		$this->enable(['remove_archives' => [$type]]);

		$this->visit($this->archiveUrl($url_template));
		$this->assertTrue(is_404(), $type);
	}

	public static function archiveTypes(): array {
		return [
			'author' => ['author', 'author'],
			'date' => ['date', '/?m=' . gmdate('Y')],
			'category' => ['category', 'category'],
			'tag' => ['post_tag', 'tag'],
		];
	}

	private function archiveUrl(string $template): string {
		return match ($template) {
			'author' => get_author_posts_url($this->author),
			'category' => get_category_link($this->category),
			'tag' => get_tag_link($this->tag),
			default => home_url($template),
		};
	}

	public function test_post_format_archives_can_be_removed(): void {
		set_post_format($this->post, 'gallery');
		$this->enable(['remove_archives' => ['post_format']]);

		$this->visit(get_post_format_link('gallery'));
		$this->assertTrue(is_404());
	}

	public function test_only_selected_archives_are_affected(): void {
		$this->enable(['remove_archives' => ['author']]);

		$this->visit(get_category_link($this->category));
		$this->assertFalse(is_404(), 'category archive untouched');
		$this->visit(get_permalink($this->post));
		$this->assertFalse(is_404(), 'single post untouched');
	}

	public function test_author_ids_do_not_reveal_login_names(): void {
		$this->enable(['remove_archives' => ['author']]);

		$this->visit(home_url('/?author=' . $this->author));
		$this->assertTrue(is_404());
		$this->assertSame(0, has_action('template_redirect', [$this->module, 'handleRequest']), 'runs before redirect_canonical (priority 10), which would redirect to /author/<login>/');
	}

	public function test_archives_can_redirect_to_the_homepage_instead(): void {
		$this->enable(['remove_archives' => ['author'], 'archive_response' => 'home']);
		$this->go_to(get_author_posts_url($this->author));

		$e = $this->catchRedirect(fn () => $this->module->handleRequest());
		$this->assertSame(home_url('/') . ' 301', $e->location);
	}

	public function test_sitemaps_follow_the_removed_archives(): void {
		$this->enable(['remove_archives' => ['author', 'category']]);

		$this->assertFalse(apply_filters('wp_sitemaps_add_provider', 'provider', 'users'), 'users sitemap lists author archives');
		$this->assertSame('provider', apply_filters('wp_sitemaps_add_provider', 'provider', 'posts'));
		$taxonomies = apply_filters('wp_sitemaps_taxonomies', ['category' => get_taxonomy('category'), 'post_tag' => get_taxonomy('post_tag')]);
		$this->assertSame(['post_tag'], array_keys($taxonomies));
	}

	// --- attachments, feeds -------------------------------------------------------------------------

	public function test_attachment_pages_can_be_disabled(): void {
		update_option('wp_attachment_pages_enabled', '1'); // sites installed before WordPress 6.4
		$this->enable(['disable_attachment_pages' => true]);

		$this->assertSame('0', get_option('wp_attachment_pages_enabled'));
	}

	public function test_attachment_pages_that_wordpress_does_not_redirect_are_404(): void {
		// WordPress doesn't redirect attachments of posts that aren't publicly viewable, e.g. when posts are disabled
		$attachment = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $this->post);
		$this->enable(['disable_attachment_pages' => true, 'disable_posts' => true]);

		$this->go_to(home_url('/?attachment_id=' . $attachment));
		$this->assertTrue(is_attachment());
		$this->module->handleAttachmentPages();

		$this->assertTrue(is_404());
		$this->assertSame(11, has_action('template_redirect', [$this->module, 'handleAttachmentPages']), 'after redirect_canonical (10), which redirects all other attachment pages to the file');
	}

	public function test_attachment_pages_stay_when_not_disabled(): void {
		$attachment = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $this->post);
		update_option('wp_attachment_pages_enabled', '1');
		$this->enable(['disable_posts' => true]);

		$this->go_to(home_url('/?attachment_id=' . $attachment));
		$this->module->handleAttachmentPages();

		$this->assertFalse(is_404());
	}

	public function test_all_feeds_can_be_disabled(): void {
		$this->enable(['disable_feeds' => true]);

		foreach (['rss2', 'atom', 'rdf', 'comments-rss2'] as $feed) {
			$this->visit(home_url('/?feed=' . $feed));
			$this->assertTrue(is_404(), $feed);
			$this->assertFalse(is_feed(), $feed);
		}
		$this->assertFalse(has_action('wp_head', 'feed_links'));
		$this->assertFalse(has_action('wp_head', 'feed_links_extra'));
	}



	// --- texts ---------------------------------------------------------------------------------------


	private function editorScript(): string {
		$this->module->hideBlocksInEditor();
		return implode("\n", (array) wp_scripts()->get_data(BlogModule::EDITOR_SCRIPT, 'after'));
	}

	// --- Yoast SEO ------------------------------------------------------------------------------------------
	// Yoast replaces WordPress' sitemap with its own, which would still list the removed archives.

	public function test_removed_archives_are_not_in_yoasts_sitemap(): void {
		$this->enable(['remove_archives' => ['author', 'category']]);

		$this->assertTrue(apply_filters('wpseo_sitemap_exclude_taxonomy', false, 'category'));
		$this->assertFalse(apply_filters('wpseo_sitemap_exclude_taxonomy', false, 'post_tag'), 'not removed');
		$this->assertSame([], apply_filters('wpseo_sitemap_exclude_author', [new \WP_User()]));
	}

	public function test_removed_taxonomies_are_not_in_yoasts_sitemap(): void {
		$this->enable(['remove_taxonomies' => ['post_tag']]);

		$this->assertTrue(apply_filters('wpseo_sitemap_exclude_taxonomy', false, 'post_tag'));
	}

	public function test_disabled_posts_are_not_in_yoasts_sitemap(): void {
		$this->enable(['disable_posts' => true]);

		$this->assertTrue(apply_filters('wpseo_sitemap_exclude_post_type', false, 'post'));
		$this->assertFalse(apply_filters('wpseo_sitemap_exclude_post_type', false, 'page'));
	}

	public function test_yoasts_sitemap_is_untouched_when_off(): void {
		$this->enable([]);

		$this->assertFalse(apply_filters('wpseo_sitemap_exclude_taxonomy', false, 'category'));
		$this->assertFalse(apply_filters('wpseo_sitemap_exclude_post_type', false, 'post'));
		$this->assertCount(1, apply_filters('wpseo_sitemap_exclude_author', [new \WP_User()]));
		$this->assertTrue(apply_filters('wpseo_sitemap_exclude_taxonomy', true, 'category'), 'an exclusion by someone else stays');
	}
}
