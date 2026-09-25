<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Editor;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Block editor: fewer external requests and suggestions, classic editor where wanted.
 */
final class EditorModule implements Module {
	/** @var list<string> */
	private array $classicTypes = [];

	public function id(): string {
		return 'editor';
	}

	public function title(): string {
		return __('Editor', 'wptb');
	}

	public function description(): string {
		return __('Trim the block editor down to what the site uses.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'disable_remote_patterns',
				false,
				__('Don\'t load patterns from wordpress.org', 'wptb'),
				__('The editor no longer downloads block patterns (ready-made layouts) from the WordPress pattern directory. Patterns from the theme, plugins and your own synced patterns stay available.', 'wptb'),
				why: __('The directory patterns rarely fit the site\'s design and clutter the inserter. Not loading them also saves a request to wordpress.org.', 'wptb'),
				sideEffects: __('Themes that list directory patterns in their theme.json lose those patterns too.', 'wptb'),
			),
			Field::bool(
				'disable_core_patterns',
				false,
				__('Hide WordPress\' built-in patterns', 'wptb'),
				__('Removes the patterns that come with WordPress itself (e.g. query loops, "social links" layouts). Patterns from the theme and plugins stay.', 'wptb'),
				why: __('Leaves only the patterns made for this site, so editors pick layouts that fit.', 'wptb'),
			),
			Field::bool(
				'disable_block_directory',
				false,
				__('Turn off block suggestions from wordpress.org', 'wptb'),
				__('When a search in the inserter finds nothing, the editor no longer suggests installing blocks from the WordPress plugin directory.', 'wptb'),
				why: __('Editors shouldn\'t install plugins while writing a post; each installed block is a plugin that has to be maintained.', 'wptb'),
			),
			Field::bool(
				'disable_openverse',
				false,
				__('Turn off Openverse images', 'wptb'),
				__('Removes the "Openverse" tab from the media section of the inserter, which searches free images on openverse.org.', 'wptb'),
				why: __('Images from Openverse come with licence conditions (often attribution) that editors easily overlook. It also avoids requests to openverse.org.', 'wptb'),
			),
			Field::multi(
				'classic_editor_for',
				static function (): array {
					$options = [];
					foreach (get_post_types(['show_ui' => true, 'show_in_rest' => true], 'objects') as $name => $type) {
						// attachments have their own screen; core's wp_* types only work in the block editor
						if ($name !== 'attachment' && !str_starts_with($name, 'wp_')) {
							$options[$name] = $type->label;
						}
					}
					return $options;
				},
				[],
				__('Use the classic editor for', 'wptb'),
				__('Content of the selected types is edited in the classic editor instead of the block editor. Existing content is not changed.', 'wptb'),
				why: __('Some content types (e.g. simple entries for a custom post type) are easier to edit in the classic editor, or a page builder expects it.', 'wptb'),
				sideEffects: __('Content already made with blocks is shown as HTML in the classic editor; editing it there can break the blocks.', 'wptb'),
			),
			Field::bool(
				'classic_widgets',
				false,
				__('Classic widgets screen', 'wptb'),
				__('Appearance → Widgets and the Customizer use the classic widget screen instead of the block-based one. Only relevant for themes with widget areas (classic themes).', 'wptb'),
				why: __('The classic screen is simpler and some widget plugins only work there.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		if ($settings->get('editor', 'disable_remote_patterns') === true) {
			add_filter('should_load_remote_block_patterns', '__return_false');
		}
		if ($settings->get('editor', 'disable_core_patterns') === true) {
			add_action('init', [$this, 'removeCorePatterns'], 9);
		}
		if ($settings->get('editor', 'disable_block_directory') === true) {
			remove_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
		}
		if ($settings->get('editor', 'disable_openverse') === true) {
			add_filter('block_editor_settings_all', static function (array $editorSettings): array {
				$editorSettings['enableOpenverseMediaCategory'] = false;
				return $editorSettings;
			});
		}

		$types = $settings->get('editor', 'classic_editor_for');
		$this->classicTypes = is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
		if ($this->classicTypes) {
			add_filter('use_block_editor_for_post_type', [$this, 'useBlockEditor'], 10, 2);
		}

		if ($settings->get('editor', 'classic_widgets') === true) {
			add_filter('use_widgets_block_editor', '__return_false');
		}
	}

	/**
	 * Before core registers its patterns on init 10.
	 */
	public function removeCorePatterns(): void {
		remove_theme_support('core-block-patterns');
	}

	public function useBlockEditor(mixed $use, mixed $postType): bool {
		return $use !== false && !in_array($postType, $this->classicTypes, true);
	}
}
