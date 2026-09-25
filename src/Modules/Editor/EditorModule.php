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
				true,
				__('Don\'t load patterns from wordpress.org', 'wptb'),
				what: __('The block editor no longer offers block patterns (ready-made layouts) from the WordPress pattern directory. Patterns from the theme, plugins and your own saved patterns stay available.', 'wptb'),
				how: __('Answers WordPress\' should_load_remote_block_patterns filter with no, so the pattern directory is never queried.', 'wptb'),
				why: __('The directory patterns rarely fit the site\'s design and clutter the inserter. Not loading them also saves requests to wordpress.org.', 'wptb'),
				sideEffects: __('Themes that list directory patterns in their theme.json lose those patterns too.', 'wptb'),
			),
			Field::bool(
				'disable_block_directory',
				true,
				__('Turn off block suggestions from wordpress.org', 'wptb'),
				what: __('When a search in the block inserter finds nothing, the editor no longer offers to install block plugins from the WordPress plugin directory.', 'wptb'),
				how: __('Removes the block directory script from the block editor.', 'wptb'),
				why: __('Editors shouldn\'t install plugins while writing; every installed block is another plugin that has to be maintained and can break.', 'wptb'),
			),
			Field::bool(
				'disable_openverse',
				true,
				__('Turn off Openverse images', 'wptb'),
				what: __('Removes the "Openverse" tab from the media section of the block inserter, which searches free images on openverse.org.', 'wptb'),
				how: __('Sets the block editor setting enableOpenverseMediaCategory to false.', 'wptb'),
				why: __('Openverse images come with licence conditions (often attribution) that editors easily overlook, and the search sends requests to openverse.org.', 'wptb'),
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
				what: __('Content of the selected types opens in the classic editor instead of the block editor. Pages built with Elementor are not affected: they open in Elementor either way.', 'wptb'),
				how: __('Answers WordPress\' use_block_editor_for_post_type filter with no for the selected types. If another plugin already turned the block editor off for a type, it stays off.', 'wptb'),
				why: __('Some content (e.g. simple entries of a custom post type) is easier to edit in the classic editor, and some plugins expect it.', 'wptb'),
				sideEffects: __('Content already made with blocks is shown as HTML in the classic editor; editing it there can break the blocks.', 'wptb'),
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
	}

	public function useBlockEditor(mixed $use, mixed $postType): bool {
		return $use !== false && !in_array($postType, $this->classicTypes, true);
	}
}
