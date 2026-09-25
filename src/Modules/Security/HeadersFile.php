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
	private const COMMENT = '# Added by the wp toolbox plugin (Settings → Toolbox → Security). Changes here are overwritten.';

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
		if (!$enabled) {
			$this->remove();
			return;
		}

		$file = $this->file();
		$content = is_file($file) ? (string) file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file next to WordPress
		$current = preg_match(self::pattern(), $content, $m) ? $m[0] : null;
		if ($current !== null && self::normalize($current) === self::block()) {
			return;
		}
		$updated = $current !== null
			? str_replace($current, (preg_match('/^\r?\n/', $current) ? "\n" : '') . self::block() . "\n", $content)
			: ($content === '' ? '' : rtrim($content, "\r\n") . "\n\n") . self::block() . "\n";
		$this->replace($file, $updated);
	}

	/**
	 * Removes the block including its markers (on deactivation and uninstall too), leaving the rest untouched.
	 */
	public function remove(): void {
		$file = $this->file();
		if (!is_file($file)) {
			return;
		}
		$content = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file next to WordPress
		$cleaned = preg_replace(self::pattern(), '', $content, -1, $count);
		if ($count > 0 && is_string($cleaned)) {
			$this->replace($file, $cleaned);
		}
	}

	/**
	 * The block as written: markers, a fixed comment (not translated, so a language switch changes nothing), rules.
	 */
	private static function block(): string {
		return implode("\n", ['# BEGIN ' . self::MARKER, self::COMMENT, ...self::rules(), '# END ' . self::MARKER]);
	}

	/**
	 * The block with its trailing line break and the blank line before it (also as written by earlier versions with
	 * insert_with_markers(), whose comment is translated).
	 */
	private static function pattern(): string {
		$marker = preg_quote(self::MARKER, '/');
		return "/(?:^|(?<=\\n))(?:\\r?\\n)?# BEGIN $marker\\r?\\n.*?# END $marker(?:\\r?\\n|$)/s";
	}

	private static function normalize(string $text): string {
		return trim(str_replace("\r\n", "\n", $text), "\n");
	}

	/**
	 * Writes the whole file next to it and renames it into place: Apache reads either the old or the new file,
	 * never a half-written one (which would answer requests with a server error while it lasts).
	 */
	private function replace(string $file, string $content): void {
		$dir = dirname($file);
		if (is_file($file) ? !wp_is_writable($file) : !wp_is_writable($dir)) {
			return;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- local file next to WordPress, like insert_with_markers()
		if (is_link($file)) {
			file_put_contents($file, $content, LOCK_EX);
			return;
		}
		// ".ht…" files are never served by Apache
		$temp = $dir . '/.htaccess.wptb-' . wp_generate_password(8, false);
		if (file_put_contents($temp, $content) === false) {
			return;
		}
		$perms = is_file($file) ? fileperms($file) : false;
		chmod($temp, $perms !== false ? $perms & 0777 : 0644);
		if (!rename($temp, $file)) {
			wp_delete_file($temp);
		}
		// phpcs:enable
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
