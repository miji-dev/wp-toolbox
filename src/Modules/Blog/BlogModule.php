<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Blog;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Support\HiddenBlocks;
use Miji\Toolbox\Support\NotFound;
use WP_Post_Type;
use WP_Taxonomy;

/**
 * Removes the classic blog machinery: posts, categories/tags, archive pages, attachment pages, feeds, search.
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
	private bool $search = false;
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
				__('Hides the "Posts" post type: it disappears from the admin menu, the admin bar, menus, search, sitemaps and the REST API. Single posts and the posts page return "not found". Pages and custom post types are not affected.', 'wptb'),
				why: __('Sites built from pages only still show an empty "Posts" section everywhere, which confuses editors and exposes an unused part of the site.', 'wptb'),
				sideEffects: __('Existing posts stay in the database and come back when you switch this off. The Latest Posts, Archives and Calendar blocks and widgets and the Quick Draft dashboard box are removed. If your homepage shows your latest posts (Settings → Reading), it still does.', 'wptb'),
			),
			Field::multi(
				'remove_taxonomies',
				['category' => __('Categories', 'wptb'), 'post_tag' => __('Tags', 'wptb')],
				[],
				__('Remove categories and tags', 'wptb'),
				__('Removes the selected taxonomies from posts, the editor, menus, the REST API and the admin. Their archive pages return "not found" (or redirect, see below).', 'wptb'),
				why: __('Categories and tags only make sense for a blog. Without one they are just clutter in the editor.', 'wptb'),
				sideEffects: __('Existing terms and their assignments stay in the database. The Categories and Tag Cloud blocks and widgets are removed.', 'wptb'),
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
				__('The selected archive pages no longer exist. The matching sitemaps are removed as well, so search engines are not sent to pages that are gone.', 'wptb'),
				why: __('WordPress generates these pages automatically. On most sites they are thin duplicate content. Author archives also reveal login names: example.com/?author=1 redirects to /author/<login name>/.', 'wptb'),
				sideEffects: __('Links to these pages that your theme prints (e.g. an author name linking to the author archive) will lead to the "not found" page or the homepage.', 'wptb'),
			),
			Field::choice(
				'archive_response',
				['404' => __('Show "not found" (404)', 'wptb'), 'home' => __('Redirect to the homepage (301)', 'wptb')],
				'404',
				__('Removed archive pages', 'wptb'),
				__('What visitors and search engines get when they open a removed archive page.', 'wptb'),
				why: __('"Not found" is the honest answer and makes search engines drop the pages. A redirect is friendlier if old archive links are still around.', 'wptb'),
			),
			Field::bool(
				'disable_attachment_pages',
				false,
				__('Disable attachment pages', 'wptb'),
				__('Every uploaded file gets its own page in WordPress. With this on, those addresses lead straight to the file itself. This is WordPress\' own setting, which has no switch in the admin.', 'wptb'),
				why: __('Attachment pages are empty pages with just an image. Search engines index them as low-quality content.', 'wptb'),
				sideEffects: __('Files attached to content that isn\'t public (e.g. to posts while posts are disabled) show "not found" instead, so the address doesn\'t reveal anything about that content.', 'wptb'),
			),
			Field::bool(
				'disable_feeds',
				false,
				__('Disable RSS feeds', 'wptb'),
				__('All RSS and Atom feeds (posts, comments, categories, …) return "not found", and the feed links are removed from the page header.', 'wptb'),
				why: __('Feeds are only useful if people subscribe to a blog. Otherwise they are another copy of your content for scrapers.', 'wptb'),
			),
			Field::bool(
				'disable_search',
				false,
				__('Disable frontend search', 'wptb'),
				__('Search on the website returns "not found", and the search form, block and widget are removed. The search in the admin and in the editor keep working.', 'wptb'),
				why: __('Small sites don\'t need a search, and WordPress\' built-in search results are rarely helpful. The search URL can also be abused for spam links.', 'wptb'),
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
		$this->search = $settings->get('blog', 'disable_search') === true;

		if ($settings->get('blog', 'disable_attachment_pages') === true) {
			$this->attachmentPages = false;
			add_filter('pre_option_wp_attachment_pages_enabled', static fn (): string => '0');
			add_action('template_redirect', [$this, 'handleAttachmentPages'], 11);
		}

		if (!$this->posts && !$this->taxonomies && !$this->archives && !$this->feeds && !$this->search) {
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
		}
		if ($this->archives) {
			add_filter('wp_sitemaps_add_provider', [$this, 'filterSitemapProvider'], 10, 2);
			add_filter('wp_sitemaps_taxonomies', [$this, 'filterSitemapTaxonomies']);
		}
		if ($this->feeds) {
			remove_action('wp_head', 'feed_links', 2);
			remove_action('wp_head', 'feed_links_extra', 3);
		}
		if ($this->search) {
			add_filter('get_search_form', '__return_empty_string', PHP_INT_MAX);
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

		if ($this->posts && (is_singular('post') || (is_home() && !is_front_page()) || is_post_type_archive('post'))) {
			NotFound::send();
			return;
		}
		if ($this->feeds && is_feed()) {
			NotFound::send();
			return;
		}
		if ($this->search && is_search()) {
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
		if ($this->search) {
			$widgets[] = 'WP_Widget_Search';
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
		if ($this->search) {
			$names[] = 'core/search';
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
