<?php

declare(strict_types=1);

namespace Miji\Toolbox\Updater;

/**
 * Updates the plugin from GitHub releases through WordPress' own update mechanism.
 *
 * The plugin header "Update URI: https://github.com/…" makes WordPress skip wordpress.org and ask the
 * update_plugins_github.com filter instead. WordPress compares versions and installs the package itself.
 */
final class GitHubUpdater {
	private const CACHE_TTL = HOUR_IN_SECONDS;
	private const FAILURE_TTL = HOUR_IN_SECONDS;

	public function __construct(
		private readonly string $pluginFile,
		private readonly string $repo,
		private readonly bool $beta = false,
	) {
	}

	public function register(): void {
		add_filter('update_plugins_github.com', [$this, 'check'], 10, 3);
		add_filter('plugins_api', [$this, 'info'], 20, 3);
		add_action('upgrader_process_complete', [$this, 'flush']);
	}

	/**
	 * @param mixed $update result of earlier callbacks (false if none)
	 * @param array<mixed> $pluginData
	 * @return mixed update data for WordPress, or $update if this isn't our plugin / nothing is known
	 */
	public function check(mixed $update, array $pluginData, string $pluginFile): mixed {
		if ($pluginFile !== $this->pluginFile) {
			return $update;
		}

		$release = $this->latest();
		if ($release === null) {
			return $update;
		}

		return array_filter([
			'slug' => $this->slug(),
			'version' => $release->version,
			'url' => $release->url,
			'package' => $release->package,
			'requires_php' => $release->requiresPhp,
			'requires' => $release->requiresWp,
		], static fn ($v): bool => $v !== null);
	}

	/**
	 * Data for the "View details" popup.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @return mixed
	 */
	public function info(mixed $result, string $action, mixed $args): mixed {
		if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? null) !== $this->slug()) {
			return $result;
		}

		$release = $this->latest();
		if ($release === null) {
			return $result;
		}

		return (object) [
			'name' => 'wp toolbox',
			'slug' => $this->slug(),
			'version' => $release->version,
			'author' => 'Michael Stahl',
			'homepage' => 'https://github.com/' . $this->repo,
			'requires_php' => $release->requiresPhp,
			'requires' => $release->requiresWp,
			'last_updated' => $release->publishedAt,
			'download_link' => $release->package,
			'sections' => [
				'changelog' => wpautop(esc_html($release->notes)),
			],
		];
	}

	public function flush(): void {
		delete_site_transient($this->cacheKey());
	}

	public function latest(): ?Release {
		$cached = get_site_transient($this->cacheKey());
		if (is_array($cached) && array_key_exists('release', $cached)) {
			return is_array($cached['release']) ? Release::fromArray($cached['release']) : null;
		}

		$release = $this->fetch();
		set_site_transient($this->cacheKey(), ['release' => $release?->toArray()], $release ? self::CACHE_TTL : self::FAILURE_TTL);

		return $release;
	}

	private function fetch(): ?Release {
		$url = 'https://api.github.com/repos/' . $this->repo . ($this->beta ? '/releases?per_page=10' : '/releases/latest');
		$response = wp_remote_get($url, [
			'timeout' => 10,
			'headers' => ['Accept' => 'application/vnd.github+json'],
		]);

		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			return null;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($data)) {
			return null;
		}

		if (!$this->beta) {
			return Release::fromGitHub($data, $this->repo);
		}

		// newest version among all usable releases, prereleases included
		$best = null;
		foreach ($data as $item) {
			$release = is_array($item) ? Release::fromGitHub($item, $this->repo) : null;
			if ($release && (!$best || version_compare($release->version, $best->version, '>'))) {
				$best = $release;
			}
		}
		return $best;
	}

	private function slug(): string {
		return dirname($this->pluginFile);
	}

	private function cacheKey(): string {
		return 'wptb_update_' . ($this->beta ? 'beta' : 'stable');
	}
}
