<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Media;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Uploads: clean file names, fewer image copies.
 *
 * SVG uploads are left to Elementor ("Enable Unfiltered File Uploads"), image compression to WP-Optimize.
 */
final class MediaModule implements Module {
	private bool $inCoreSanitizer = false;

	public function id(): string {
		return 'media';
	}

	public function title(): string {
		return __('Media', 'wptb');
	}

	public function description(): string {
		return __('Uploads and images.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'clean_filenames',
				true,
				__('Clean up file names on upload', 'wptb'),
				what: __('Uploaded files get simple, web-safe names: lowercase, umlauts and accents written out, spaces and special characters replaced by dashes. "Größe Übersicht (1).JPG" becomes "groesse-uebersicht-1.jpg". Files already in the media library are not renamed.', 'wptb'),
				how: __('Writes out umlauts and accents (ä → ae, ß → ss, é → e) in the original file name, runs WordPress\' own file name cleanup on the result (which keeps its safety measures, e.g. for "file.php.jpg"), then lowercases it and replaces everything except letters, digits, dots and dashes.', 'wptb'),
				why: __('Names with umlauts, spaces or special characters break links, cause encoding problems when a site moves, and look bad in URLs. WordPress on its own turns "Größe" into "Grose".', 'wptb'),
			),
			Field::multi(
				'disable_image_sizes',
				static function (): array {
					$options = [];
					foreach (wp_get_registered_image_subsizes() as $name => $size) {
						$options[$name] = sprintf('%s (%d × %d)', $name, (int) $size['width'], (int) $size['height']);
					}
					return $options;
				},
				[],
				__('Don\'t create these image sizes', 'wptb'),
				what: __('WordPress creates several resized copies of every uploaded image. The selected sizes are no longer created for new uploads.', 'wptb'),
				how: __('Removes the selected sizes from the list WordPress generates after an upload. The list shows all sizes registered by WordPress, the theme and plugins. Existing files are not deleted.', 'wptb'),
				why: __('Sizes nothing uses only fill up the server and backups. 1536×1536 and 2048×2048 in particular are rarely needed; Elementor sites mostly use "full", "large" and "medium".', 'wptb'),
				sideEffects: __('If the theme or a plugin asks for a size that doesn\'t exist, WordPress uses the next larger one. Only disable sizes you know are unused.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		if ($settings->get('media', 'clean_filenames') === true) {
			add_filter('sanitize_file_name', [$this, 'cleanFilename'], 20, 2);
		}

		$sizes = $settings->get('media', 'disable_image_sizes');
		$sizes = is_array($sizes) ? array_values(array_filter($sizes, 'is_string')) : [];
		if ($sizes) {
			add_filter('intermediate_image_sizes_advanced', static fn (array $all): array => array_diff_key($all, array_flip($sizes)), PHP_INT_MAX);
		}
	}

	/**
	 * Core has already stripped accents ("Größe" -> "Grose") by the time this runs, so the raw name is
	 * transliterated first and run through core's sanitizer again: that keeps its safety measures, e.g.
	 * "shell.php.jpg" -> "shell.php_.jpg".
	 */
	public function cleanFilename(string $filename, mixed $raw = null): string {
		if ($this->inCoreSanitizer) {
			return $filename;
		}
		if (is_string($raw) && $raw !== '') {
			$this->inCoreSanitizer = true;
			try {
				$filename = sanitize_file_name(FilenameCleaner::transliterate($raw));
			} finally {
				$this->inCoreSanitizer = false;
			}
		}
		return FilenameCleaner::clean($filename);
	}
}
