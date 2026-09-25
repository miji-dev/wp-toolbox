<?php

declare(strict_types=1);

namespace Miji\Toolbox\Migration;

use Closure;
use Miji\Toolbox\Settings\Settings;
use wpdb;

/**
 * One-time update from wp-toolbox 3.x (and sw-toolbox, its predecessor): carries over the settings that were
 * switched on, deletes everything 3.x stored and notes what needs attention (see MigrationNotice).
 *
 * Runs on the first request after the update (WordPress replaces the plugin files, then loads the new code).
 */
final class Migration {
	public const VERSION_OPTION = 'wptb_db_version';
	public const NOTICE_OPTION = 'wptb_migration_notice';
	public const VERSION = 1;

	/** Unprefixed settings of 3.x (same names in sw-toolbox). Generic names: only touched when 3.x was installed. */
	private const SETTINGS = ['Logo_media', 'Favicon_media', 'Background_Color_color', 'only_elementor', 'disable_gutenberg', 'enable_maintenance', 'disable_posts', 'disable_comments', 'adminbar_position'];

	/** Names that only 3.x used: evidence that it was installed. */
	private const DISTINCTIVE = ['Logo_media', 'Favicon_media', 'Background_Color_color', 'adminbar_position', 'only_elementor'];

	/** Stored as wptb_… by wp-toolbox 3.x and swtb_… by sw-toolbox. */
	private const PREFIXED = [
		'styles_everywhere', 'crawling', 'ga_activated', 'ga_api_key',
		'cookie_banner_text', 'button_accept_text', 'button_reject_text', 'button_save_text', 'button_settings_text',
		'category_necessary', 'category_necessary_text', 'category_analytics', 'category_analytics_text',
		'imprint_url', 'imprint_text', 'privacy_url', 'privacy_text', 'show_banner_again_button', 'show_banner_again_text',
	];

	/** Registered for translation in WPML by 3.x (wpml-config.xml, admin-texts). */
	private const WPML_TEXTS = [
		'cookie_banner_text', 'button_reject_text', 'button_accept_text', 'button_settings_text', 'button_save_text',
		'category_necessary', 'category_analytics', 'imprint_url', 'imprint_text', 'privacy_url', 'privacy_text',
		'category_necessary_text', 'category_analytics_text',
	];

	private const PREFIXES = ['wptb', 'swtb'];

	/** @var Closure(string, string): void */
	private readonly Closure $unregisterString;

	/**
	 * @param (Closure(string, string): void)|null $unregisterString removes a WPML string (context, name); for tests
	 */
	public function __construct(private readonly Settings $settings, ?Closure $unregisterString = null) {
		$this->unregisterString = $unregisterString ?? static function (string $context, string $name): void {
			if (function_exists('icl_unregister_string')) {
				icl_unregister_string($context, $name);
			}
		};
	}

	public function register(): void {
		// after init: post types (for "Disable Gutenberg") and the settings are registered
		add_action('wp_loaded', [$this, 'run']);
	}

	public function run(): void {
		$done = get_option(self::VERSION_OPTION);
		if (is_numeric($done) && (int) $done >= self::VERSION) {
			return;
		}
		if ($this->wasInstalled()) {
			$this->carryOverSettings();
			$this->noteWhatNeedsAttention();
			$this->deleteLegacyData();
		}
		update_option(self::VERSION_OPTION, self::VERSION, true);
	}

	private function wasInstalled(): bool {
		foreach (self::PREFIXES as $prefix) {
			if (get_option("external_updates-$prefix") !== false || get_option("{$prefix}_styles_everywhere") !== false) {
				return true;
			}
		}
		foreach (self::DISTINCTIVE as $name) {
			if (get_option($name) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Only what was switched on: everything else keeps the (re-evaluated) v4 defaults. Locked settings are skipped
	 * by Settings::update(), invalid values (e.g. a deleted logo) are dropped one by one.
	 */
	private function carryOverSettings(): void {
		/** @var list<array{string, string, mixed}> $changes module, key, value */
		$changes = [];
		if (self::on('disable_comments')) {
			$changes[] = ['comments', 'disable', true];
		}
		if (self::on('disable_posts')) {
			$changes[] = ['blog', 'disable_posts', true];
		}
		if (self::on('disable_gutenberg')) {
			// 3.x switched the block editor off for all content
			$types = array_keys($this->settings->field('editor', 'classic_editor_for')->options());
			$changes[] = ['editor', 'classic_editor_for', array_map(static fn (int|string $type): string => (string) $type, $types)];
		}
		if (self::on('only_elementor')) {
			$changes[] = ['elementor', 'open_in_elementor', true];
		}
		$logo = get_option('Logo_media');
		if (is_numeric($logo) && wp_attachment_is_image((int) $logo)) {
			$changes[] = ['login', 'logo', 'custom'];
			$changes[] = ['login', 'logo_image', (int) $logo];
		}
		$color = get_option('Background_Color_color');
		if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}\z/', $color)) {
			$changes[] = ['login', 'background_color', strtolower($color)];
		}

		foreach ($changes as [$module, $key, $value]) {
			$this->settings->update([$module => [$key => $value]]);
		}
	}

	/**
	 * Features 3.x had and v4 doesn't: maintenance mode and Google Analytics/cookie banner (they stop working), and
	 * content that still uses the old shortcodes or the old map widget.
	 */
	private function noteWhatNeedsAttention(): void {
		$notice = [
			'maintenance' => self::on('enable_maintenance'),
			'analytics' => self::on('wptb_ga_activated') || self::on('swtb_ga_activated'),
			'pages' => self::pagesWithOldContent(),
		];
		if ($notice['maintenance'] || $notice['analytics'] || $notice['pages']) {
			update_option(self::NOTICE_OPTION, $notice, false);
		}
	}

	private function deleteLegacyData(): void {
		foreach (self::SETTINGS as $name) {
			delete_option($name);
		}
		foreach (self::PREFIXES as $prefix) {
			foreach (self::PREFIXED as $name) {
				delete_option("{$prefix}_$name");
			}
			foreach (self::WPML_TEXTS as $name) {
				($this->unregisterString)("admin_texts_{$prefix}_$name", "{$prefix}_$name");
			}
			// the old update checker (Plugin Update Checker, slug wptb/swtb)
			delete_option("external_updates-$prefix");
			delete_site_option("external_updates-$prefix");
			wp_clear_scheduled_hook("puc_cron_check_updates-$prefix");
		}
	}

	/**
	 * Posts of any type (not trashed, no revisions) with a [wptb-…]/[swtb-…] shortcode in the content or in Elementor's
	 * data, or with the old Google Maps widget.
	 *
	 * @return list<int>
	 */
	private static function pagesWithOldContent(): array {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if (!$wpdb instanceof wpdb) {
			return [];
		}
		$like = static fn (string $text): string => '%' . $wpdb->esc_like($text) . '%';
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID FROM %i p
			LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
			WHERE p.post_type NOT IN ('revision', 'nav_menu_item') AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
			AND (p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s)",
			$wpdb->posts,
			$wpdb->postmeta,
			$like('[wptb-'),
			$like('[swtb-'),
			$like('[wptb-'),
			$like('[swtb-'),
			$like('"widgetType":"wptb-gm"'),
			$like('"widgetType":"swtb-gm"'),
		));
		return array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));
	}

	private static function on(string $option): bool {
		return in_array(get_option($option), ['1', 1, true, 'on'], true);
	}
}
