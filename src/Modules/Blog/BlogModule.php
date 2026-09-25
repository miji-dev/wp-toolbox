<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Blog;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Support\HiddenBlocks;
use Miji\Toolbox\Support\NotFound;
use WP_Post_Type;
use WP_Query;
use WP_Taxonomy;

/**
 * Removes the classic blog machinery: posts, categories/tags, archive pages, attachment pages, feeds.
 */
final class BlogModule implements Module {
	public const EDITOR_SCRIPT = 'wptb-hide-blog-blocks';

	private bool $posts = false;
	/** @var list<string> */
	private array $taxonomies = [];
	/** @var list<string> */
	private array $archives = [];
	private string $archiveResponse = '404';
	private bool $feeds = false;
	private bool $attachmentPages = true;

	public function id(): string {
		return 'blog';
	}

	public function title(): string {
		return __('Blog features', 'wptb');
	}

	public function description(): string {
		return __('The classic blog machinery that most company and project sites never use.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'disable_posts',
				false,
				__('Disable posts', 'wptb'),
				what: __('Hides the "Posts" content type: it disappears from the admin, the toolbar, menus, search, sitemaps and the REST API, and single posts and the posts page show "not found". Pages and other content types are not affected.', 'wptb'),
				how: __('Marks the "post" post type as not public and hidden in the admin and REST API (it stays registered), answers post URLs with 404, redirects the post list and "Add new" in the admin to the dashboard, removes the Latest Posts, Archives and Calendar blocks and widgets and the Quick Draft box, and excludes posts from Yoast SEO\'s sitemap.', 'wptb'),
				why: __('Sites built from pages only still show an empty "Posts" section everywhere, which confuses editors and exposes an unused part of the site.', 'wptb'),
				sideEffects: __('Existing posts stay in the database and come back when you switch this off. If your homepage shows your latest posts (Settings → Reading), it still does.', 'wptb'),
			),
			Field::multi(
				'remove_taxonomies',
				['category' => __('Categories', 'wptb'), 'post_tag' => __('Tags', 'wptb')],
				[],
				__('Remove categories and tags', 'wptb'),
				what: __('Removes the selected taxonomies from posts, the editor, menus and the admin. Their archive pages are removed too (see below).', 'wptb'),
				how: __('Detaches the taxonomies from posts and marks them as not public and hidden in the admin and REST API, and removes the Categories and Tag Cloud blocks and widgets.', 'wptb'),
				why: __('Categories and tags only make sense for a blog. Without one they are just clutter in the editor.', 'wptb'),
				sideEffects: __('Existing terms and their assignments stay in the database.', 'wptb'),
			),
			Field::multi(
				'remove_archives',
				[
					'author' => __('Author archives', 'wptb'),
					'date' => __('Date archives', 'wptb'),
					'category' => __('Category archives', 'wptb'),
					'post_tag' => __('Tag archives', 'wptb'),
					'post_format' => __('Post format archives', 'wptb'),
				],
				[],
				__('Remove archive pages', 'wptb'),
				what: __('The selected automatic archive pages no longer exist, and they are removed from the sitemaps, so search engines are not sent to pages that are gone.', 'wptb'),
				how: __('Answers archive URLs with 404 or a redirect (see below) before WordPress\' own redirects run, and removes them from WordPress\' sitemap and from Yoast SEO\'s sitemap.', 'wptb'),
				why: __('WordPress generates these pages automatically. On most sites they are thin duplicate content that search engines rate poorly. Author archives also reveal login names (example.com/?author=1 leads to /author/<login name>/).', 'wptb'),
				sideEffects: __('Links to these pages that the theme or an SEO plugin prints (e.g. an author name linking to the author archive) lead to the "not found" page or the homepage.', 'wptb'),
			),
			Field::choice(
				'archive_response',
				['404' => __('Show "not found" (404)', 'wptb'), 'home' => __('Redirect to the homepage (301)', 'wptb')],
				'404',
				__('Removed archive pages', 'wptb'),
				what: __('What visitors and search engines get when they open a removed archive page.', 'wptb'),
				how: __('Sends status 404 with the theme\'s "not found" page, or a permanent redirect (301) to the homepage.', 'wptb'),
				why: __('"Not found" is the honest answer and makes search engines drop the pages. A redirect is friendlier if old archive links are still around.', 'wptb'),
			),
			Field::bool(
				'disable_attachment_pages',
				false,
				__('Disable attachment pages', 'wptb'),
				what: __('Every uploaded file has its own page in WordPress. With this on, those addresses lead straight to the file itself.', 'wptb'),
				how: __('Switches off WordPress\' own "attachment pages" option (which has no switch in the admin), so WordPress redirects to the file. Attachments of content that isn\'t public get a 404 instead.', 'wptb'),
				why: __('Attachment pages are empty pages with just an image, which search engines index as low-quality content. Sites set up before WordPress 6.4 still have them.', 'wptb'),
				sideEffects: __('Yoast SEO does the same with its "Media pages" setting (on by default); both together are fine.', 'wptb'),
			),
			Field::bool(
				'disable_feeds',
				false,
				__('Disable RSS feeds', 'wptb'),
				what: __('All RSS and Atom feeds (posts, comments, categories, …) show "not found", and the feed links are removed from the page header.', 'wptb'),
				how: __('Answers every feed request with 404 and removes the feed link tags that WordPress prints in the page header.', 'wptb'),
				why: __('Feeds are only useful if people subscribe to a blog. Otherwise they are another copy of your content for scrapers.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$this->posts = $settings->get('blog', 'disable_posts') === true;
		$this->taxonomies = self::strings($settings->get('blog', 'remove_taxonomies'));
		$this->archives = array_values(array_unique([...self::strings($settings->get('blog', 'remove_archives')), ...$this->taxonomies]));
		$this->archiveResponse = $settings->get('blog', 'archive_response') === 'home' ? 'home' : '404';
		$this->feeds = $settings->get('blog', 'disable_feeds') === true;

		if ($settings->get('blog', 'disable_attachment_pages') === true) {
			$this->attachmentPages = false;
			add_filter('pre_option_wp_attachment_pages_enabled', static fn (): string => '0');
			add_action('template_redirect', [$this, 'handleAttachmentPages'], 11);
		}

		if (!$this->posts && !$this->taxonomies && !$this->archives && !$this->feeds) {
			return;
		}

		// core registers post types and taxonomies on init 0
		add_action('init', [$this, 'hideContentTypes'], 1);
		// before redirect_canonical (priority 10), which would e.g. redirect ?author=1 to /author/<login>/
		add_action('template_redirect', [$this, 'handleRequest'], 0);
		add_action('widgets_init', [$this, 'unregisterWidgets'], PHP_INT_MAX);
		$this->blocks()->register();

		if ($this->posts) {
			add_action('admin_init', [$this, 'blockAdminPages']);
			add_action('wp_dashboard_setup', [$this, 'removeDashboardWidgets'], PHP_INT_MAX);
			add_filter('wpseo_sitemap_exclude_post_type', static fn (mixed $excluded, mixed $type): bool => $type === 'post' || $excluded === true, 10, 2);
		}
		if ($this->archives) {
			add_filter('wp_sitemaps_add_provider', [$this, 'filterSitemapProvider'], 10, 2);
			add_filter('wp_sitemaps_taxonomies', [$this, 'filterSitemapTaxonomies']);
			// Yoast SEO replaces WordPress' sitemap with its own
			add_filter('wpseo_sitemap_exclude_taxonomy', fn (mixed $excluded, mixed $taxonomy): bool => in_array($taxonomy, $this->archives, true) || $excluded === true, 10, 2);
			if (in_array('author', $this->archives, true)) {
				add_filter('wpseo_sitemap_exclude_author', '__return_empty_array');
			}
		}
		if ($this->feeds) {
			remove_action('wp_head', 'feed_links', 2);
			remove_action('wp_head', 'feed_links_extra', 3);
		}
	}

	public function hideContentTypes(): void {
		$type = get_post_type_object('post');
		if ($this->posts && $type instanceof WP_Post_Type) {
			foreach (['public', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_admin_bar', 'show_in_rest', 'has_archive'] as $flag) {
				$type->$flag = false;
			}
			$type->exclude_from_search = true;
		}

		foreach ($this->taxonomies as $name) {
			$taxonomy = get_taxonomy($name);
			if (!$taxonomy instanceof WP_Taxonomy) {
				continue;
			}
			foreach (['public', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_rest', 'show_tagcloud', 'show_in_quick_edit', 'show_admin_column'] as $flag) {
				$taxonomy->$flag = false;
			}
			unregister_taxonomy_for_object_type($name, 'post');
		}
	}

	public function handleRequest(): void {
		if (is_admin()) {
			return;
		}

		if ($this->posts && (is_singular('post') || (is_home() && !is_front_page()) || is_post_type_archive('post') || self::listsOnlyPosts())) {
			NotFound::send();
			return;
		}
		if ($this->feeds && is_feed()) {
			NotFound::send();
			return;
		}
		if ($this->isRemovedArchive()) {
			if ($this->archiveResponse === 'home') {
				wp_safe_redirect(home_url('/'), 301);
				exit;
			}
			NotFound::send();
		}
	}

	/**
	 * With attachment pages off, redirect_canonical (priority 10) sends visitors to the file. It skips attachments
	 * of posts that aren't publicly viewable (e.g. when posts are disabled), so those pages would still show.
	 */
	public function handleAttachmentPages(): void {
		if (!$this->attachmentPages && !is_admin() && is_attachment()) {
			NotFound::send();
		}
	}

	public function blockAdminPages(): void {
		global $pagenow, $typenow;
		if (in_array($pagenow, ['edit.php', 'post-new.php'], true) && ($typenow ?: 'post') === 'post') {
			wp_safe_redirect(admin_url());
			exit;
		}
	}

	public function removeDashboardWidgets(): void {
		remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
	}

	public function unregisterWidgets(): void {
		$widgets = [];
		if ($this->posts) {
			$widgets = ['WP_Widget_Recent_Posts', 'WP_Widget_Archives', 'WP_Widget_Calendar'];
		}
		if (in_array('category', $this->taxonomies, true)) {
			$widgets[] = 'WP_Widget_Categories';
		}
		if (in_array('post_tag', $this->taxonomies, true)) {
			$widgets[] = 'WP_Widget_Tag_Cloud';
		}
		foreach ($widgets as $widget) {
			unregister_widget($widget);
		}
	}

	public function hideBlocksInEditor(): void {
		$this->blocks()->enqueueEditorScript();
	}

	/**
	 * @param mixed $provider
	 * @return mixed
	 */
	public function filterSitemapProvider(mixed $provider, string $name): mixed {
		// the users sitemap lists nothing but author archives
		return $name === 'users' && in_array('author', $this->archives, true) ? false : $provider;
	}

	/**
	 * @param array<string, WP_Taxonomy> $taxonomies
	 * @return array<string, WP_Taxonomy>
	 */
	public function filterSitemapTaxonomies(array $taxonomies): array {
		return array_diff_key($taxonomies, array_flip($this->archives));
	}

	/**
	 * Author, date, category, tag and post format archives and the main feed list posts, even when the post type
	 * isn't public. Listings that a plugin extended to other types (post_type set in the query) are left alone.
	 */
	private static function listsOnlyPosts(): bool {
		global $wp_query;
		$type = $wp_query instanceof WP_Query ? $wp_query->get('post_type') : '';
		if (!in_array($type, ['', 'post', ['post']], true)) {
			return false;
		}
		return is_author() || is_date() || is_category() || is_tag() || is_tax('post_format') || (is_feed() && !is_comment_feed());
	}

	private function isRemovedArchive(): bool {
		foreach ($this->archives as $archive) {
			$match = match ($archive) {
				'author' => is_author(),
				'date' => is_date(),
				'category' => is_category(),
				'post_tag' => is_tag(),
				'post_format' => is_tax('post_format'),
				default => false,
			};
			if ($match) {
				return true;
			}
		}
		return false;
	}

	private function blocks(): HiddenBlocks {
		$names = [];
		if ($this->posts) {
			$names = ['core/latest-posts', 'core/archives', 'core/calendar'];
		}
		if (in_array('category', $this->taxonomies, true)) {
			$names[] = 'core/categories';
		}
		if (in_array('post_tag', $this->taxonomies, true)) {
			$names[] = 'core/tag-cloud';
		}
		return new HiddenBlocks(self::EDITOR_SCRIPT, static fn (string $name): bool => in_array($name, $names, true));
	}

	/**
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
