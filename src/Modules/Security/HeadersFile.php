<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Security;

/**
 * The security headers as Apache/LiteSpeed rules in .htaccess.
 *
 * Headers sent from PHP never reach pages that a page cache (e.g. WP-Optimize) serves without running WordPress,
 * nor static files. The server adds these to every response. Written with WordPress' own marker functions, like
 * WP-Optimize and WordPress itself do.
 */
final class HeadersFile {
	public const MARKER = 'wp-toolbox';

	public const HEADERS = [
		'X-Frame-Options' => 'SAMEORIGIN',
		'X-Content-Type-Options' => 'nosniff',
		'Referrer-Policy' => 'strict-origin-when-cross-origin',
	];

	/**
	 * @param string|null $path .htaccess file (default: the site's root)
	 * @param bool|null $apache whether the server reads .htaccess (default: WordPress' detection)
	 */
	public function __construct(private readonly ?string $path = null, private readonly ?bool $apache = null) {
	}

	/**
	 * Rules without the marker comments.
	 *
	 * Each header is removed from both of Apache's header tables and set once: PHP's own copy sits in one table or
	 * the other depending on how PHP runs (module or FastCGI), and a doubled X-Frame-Options is ignored by browsers.
	 * Without mod_headers the block does nothing.
	 *
	 * @return list<string>
	 */
	public static function rules(): array {
		$rules = ['<IfModule mod_headers.c>'];
		foreach (self::HEADERS as $name => $value) {
			$rules[] = "\tHeader unset $name";
			$rules[] = "\tHeader always set $name \"$value\"";
		}
		$rules[] = "\tHeader unset X-Powered-By";
		$rules[] = "\tHeader always unset X-Powered-By";
		$rules[] = '</IfModule>';
		return $rules;
	}

	/**
	 * Writes or removes the block, only if it differs from what's there.
	 */
	public function sync(bool $enabled): void {
		if (!$this->serverReadsHtaccess()) {
			return;
		}
		$file = $this->file();
		if (!$enabled) {
			$this->remove();
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$current = array_values(array_filter(extract_from_markers($file, self::MARKER), static fn (string $line): bool => !str_starts_with($line, '#')));
		if ($current !== self::rules()) {
			insert_with_markers($file, self::MARKER, self::rules());
		}
	}

	/**
	 * Removes the block including its markers (on deactivation and uninstall too), leaving the rest untouched.
	 */
	public function remove(): void {
		$file = $this->file();
		if (!is_file($file) || !wp_is_writable($file)) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- local file next to WordPress, same as insert_with_markers()
		$content = (string) file_get_contents($file);
		$marker = preg_quote(self::MARKER, '/');
		// the block with its line break, and the blank line insert_with_markers() puts in front of it
		$cleaned = preg_replace("/(?:^|(?<=\\n))\\n?# BEGIN $marker\\n.*?# END $marker(?:\\n|$)/s", '', $content, -1, $count);
		if ($count > 0 && is_string($cleaned)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- see above
			file_put_contents($file, $cleaned, LOCK_EX);
		}
	}

	private function file(): string {
		if ($this->path !== null) {
			return $this->path;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return get_home_path() . '.htaccess';
	}

	private function serverReadsHtaccess(): bool {
		// set by WordPress for Apache and LiteSpeed
		global $is_apache;
		return $this->apache ?? (bool) $is_apache;
	}
}
