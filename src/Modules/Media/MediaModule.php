<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Media;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use WP_User;

/**
 * Uploads: clean file names, image processing, safe SVG uploads.
 */
final class MediaModule implements Module {
	private const WP_THRESHOLD = 2560;
	private const WP_QUALITY = 82;

	/** @var list<string> */
	private array $svgRoles = [];

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
				false,
				__('Clean up file names on upload', 'wptb'),
				__('Uploaded files get simple, web-safe names: lowercase, umlauts and accents written out, spaces and special characters replaced by dashes. "Größe Übersicht (1).JPG" becomes "groesse-uebersicht-1.jpg". Files already in the media library are not renamed.', 'wptb'),
				why: __('Names with umlauts, spaces or special characters cause broken links and encoding problems (e.g. when moving the site or sharing links), and look bad in URLs.', 'wptb'),
			),
			Field::int(
				'big_image_threshold',
				self::WP_THRESHOLD,
				__('Maximum image size (pixels)', 'wptb'),
				__('Uploaded images larger than this (width or height) are scaled down for use on the site; the original is kept. 2560 is WordPress\' default. 0 turns scaling off.', 'wptb'),
				why: __('Photos straight from a camera are huge. Scaling them down keeps pages fast. Raise the value for sites that need very large images (e.g. full-screen photography).', 'wptb'),
				min: 0,
				max: 20000,
			),
			Field::int(
				'image_quality',
				self::WP_QUALITY,
				__('Image quality (1–100)', 'wptb'),
				__('Compression quality of the image sizes WordPress creates (JPEG and WebP). 82 is WordPress\' default. Only affects images uploaded after the change.', 'wptb'),
				why: __('Lower values mean smaller files and faster pages, higher values better quality. Between 70 and 85 the difference is hardly visible.', 'wptb'),
				min: 1,
				max: 100,
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
				__('WordPress creates several resized copies of every uploaded image. The selected sizes are no longer created for new uploads. Existing files are not deleted.', 'wptb'),
				why: __('Sizes nothing uses only fill up the server. 1536×1536 and 2048×2048 in particular are rarely needed.', 'wptb'),
				sideEffects: __('If the theme or a plugin asks for a size that doesn\'t exist, WordPress uses the next larger one. Only disable sizes you know are unused.', 'wptb'),
			),
			Field::multi(
				'svg_upload_roles',
				static fn (): array => array_map('translate_user_role', wp_roles()->get_names()),
				[],
				__('Allow SVG uploads for', 'wptb'),
				__('Users with the selected roles can upload SVG graphics (e.g. logos and icons). Every SVG is cleaned on upload: scripts, event handlers, links to scripts and references to other servers are removed. Files that aren\'t valid SVGs are rejected.', 'wptb'),
				why: __('SVGs are the best format for logos and icons, but WordPress blocks them because an SVG can contain code that runs in the browser. Cleaning every upload removes that risk.', 'wptb'),
				sideEffects: __('Only allow roles you trust. Compressed SVGs (.svgz) are not allowed.', 'wptb'),
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

		$threshold = $settings->get('media', 'big_image_threshold');
		if (is_int($threshold) && $threshold !== self::WP_THRESHOLD) {
			add_filter('big_image_size_threshold', static fn (): int|false => $threshold === 0 ? false : $threshold, PHP_INT_MAX);
		}

		$quality = $settings->get('media', 'image_quality');
		if (is_int($quality) && $quality !== self::WP_QUALITY) {
			add_filter('wp_editor_set_quality', static fn (): int => $quality, PHP_INT_MAX);
		}

		$sizes = self::strings($settings->get('media', 'disable_image_sizes'));
		if ($sizes) {
			add_filter('intermediate_image_sizes_advanced', static fn (array $all): array => array_diff_key($all, array_flip($sizes)), PHP_INT_MAX);
		}

		$this->svgRoles = self::strings($settings->get('media', 'svg_upload_roles'));
		if ($this->svgRoles) {
			add_filter('upload_mimes', [$this, 'allowSvg'], 10, 2);
			add_filter('wp_check_filetype_and_ext', [$this, 'fixSvgFiletype'], 10, 5);
			add_filter('wp_handle_upload_prefilter', [$this, 'sanitizeSvgUpload']);
			add_filter('wp_handle_sideload_prefilter', [$this, 'sanitizeSvgUpload']);
			add_filter('wp_generate_attachment_metadata', [$this, 'svgMetadata'], 10, 2);
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

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public function allowSvg(array $mimes, mixed $user = null): array {
		if ($this->canUploadSvg($user)) {
			$mimes['svg'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Servers don't always recognise SVGs (e.g. "text/plain" without XML declaration). Accept them if the
	 * user may upload SVGs and the file really starts like one; the content is sanitized in the prefilter.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function fixSvgFiletype(array $data, mixed $file, mixed $filename, mixed $mimes = null, mixed $realMime = null): array {
		if (!empty($data['type']) || !is_string($filename) || !is_string($file) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'svg') {
			return $data;
		}
		if (!$this->canUploadSvg(null) || !is_readable($file)) {
			return $data;
		}
		$head = (string) file_get_contents($file, false, null, 0, 4096); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload file (not a URL); WP_Filesystem is not meant for uploads
		if (!preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $head)) {
			return $data;
		}
		return ['ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false];
	}

	/**
	 * @param mixed $file upload array (name, tmp_name, …)
	 * @return mixed
	 */
	public function sanitizeSvgUpload(mixed $file): mixed {
		if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)) {
			return $file;
		}
		if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'svg' || !$this->canUploadSvg(null)) {
			// other roles: WordPress rejects the file type itself
			return $file;
		}

		$clean = SvgSanitizer::sanitize((string) file_get_contents($file['tmp_name'])); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload file (not a URL); WP_Filesystem is not meant for uploads
		if ($clean === null || file_put_contents($file['tmp_name'], $clean) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload file (not a URL); WP_Filesystem is not meant for uploads
			$file['error'] = __('This SVG file was rejected because it is not a valid SVG or contains content that can\'t be made safe.', 'wptb');
			return $file;
		}
		$file['size'] = strlen($clean);
		return $file;
	}

	/**
	 * WordPress can't read SVG dimensions; blocks and the media library need them.
	 *
	 * @param mixed $metadata
	 * @return mixed
	 */
	public function svgMetadata(mixed $metadata, mixed $attachmentId): mixed {
		if (!is_int($attachmentId) || get_post_mime_type($attachmentId) !== 'image/svg+xml') {
			return $metadata;
		}
		$path = get_attached_file($attachmentId);
		$size = is_string($path) && is_readable($path) ? SvgSanitizer::dimensions((string) file_get_contents($path)) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload file (not a URL); WP_Filesystem is not meant for uploads
		if ($size === null) {
			return $metadata;
		}

		$metadata = is_array($metadata) ? $metadata : [];
		$metadata['width'] = $size[0];
		$metadata['height'] = $size[1];
		$metadata['file'] = _wp_relative_upload_path((string) $path);
		return $metadata;
	}

	private function canUploadSvg(mixed $user): bool {
		$user = $user instanceof WP_User ? $user : (is_int($user) && $user > 0 ? get_userdata($user) : wp_get_current_user());
		return $user instanceof WP_User && $user->exists() && (bool) array_intersect($user->roles, $this->svgRoles);
	}

	/**
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}
}
