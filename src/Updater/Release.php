<?php

declare(strict_types=1);

namespace Miji\Toolbox\Updater;

/**
 * A usable GitHub release: published, with a proper version tag and the plugin zip attached.
 */
final class Release {
	public const ASSET = 'wp-toolbox.zip';

	private function __construct(
		public readonly string $version,
		public readonly string $package,
		public readonly string $url,
		public readonly string $notes,
		public readonly string $publishedAt,
		public readonly bool $prerelease,
		public readonly ?string $requiresPhp,
		public readonly ?string $requiresWp,
	) {
	}

	/**
	 * @param array<mixed> $data one release object of the GitHub REST API
	 */
	public static function fromGitHub(array $data, string $repo): ?self {
		$tag = $data['tag_name'] ?? null;
		if (!is_string($tag) || !preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/', $tag, $m)) {
			return null;
		}
		if (($data['draft'] ?? true) !== false) {
			return null;
		}

		// the zip must be exactly where our release workflow puts it: no other host, repo, tag or path
		$expected = 'https://github.com/' . $repo . '/releases/download/' . $tag . '/' . self::ASSET;
		$package = null;
		foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
			if (is_array($asset) && ($asset['name'] ?? null) === self::ASSET && ($asset['browser_download_url'] ?? null) === $expected) {
				$package = $expected;
			}
		}
		if ($package === null) {
			return null;
		}

		$url = is_string($data['html_url'] ?? null) && str_starts_with($data['html_url'], 'https://github.com/' . $repo . '/')
			? $data['html_url']
			: 'https://github.com/' . $repo . '/releases';
		$notes = is_string($data['body'] ?? null) ? $data['body'] : '';

		return new self(
			version: $m[1],
			package: $package,
			url: $url,
			notes: $notes,
			publishedAt: is_string($data['published_at'] ?? null) ? $data['published_at'] : '',
			prerelease: ($data['prerelease'] ?? false) === true,
			requiresPhp: self::requirement($notes, 'Requires PHP'),
			requiresWp: self::requirement($notes, 'Requires at least'),
		);
	}

	/**
	 * @param array<mixed> $data
	 */
	public static function fromArray(array $data): ?self {
		foreach (['version', 'package', 'url', 'notes', 'publishedAt'] as $key) {
			if (!is_string($data[$key] ?? null)) {
				return null;
			}
		}
		return new self(
			$data['version'],
			$data['package'],
			$data['url'],
			$data['notes'],
			$data['publishedAt'],
			($data['prerelease'] ?? false) === true,
			is_string($data['requiresPhp'] ?? null) ? $data['requiresPhp'] : null,
			is_string($data['requiresWp'] ?? null) ? $data['requiresWp'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'version' => $this->version,
			'package' => $this->package,
			'url' => $this->url,
			'notes' => $this->notes,
			'publishedAt' => $this->publishedAt,
			'prerelease' => $this->prerelease,
			'requiresPhp' => $this->requiresPhp,
			'requiresWp' => $this->requiresWp,
		];
	}

	/**
	 * The release workflow writes "Requires PHP: 8.3" and "Requires at least: 7.1" into the release notes.
	 */
	private static function requirement(string $notes, string $label): ?string {
		return preg_match('/^' . preg_quote($label, '/') . ':\s*(\d+(?:\.\d+){0,2})\s*$/mi', $notes, $m) ? $m[1] : null;
	}
}
