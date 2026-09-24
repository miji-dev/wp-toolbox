<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Unit\Updater;

use Miji\Toolbox\Updater\Release;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase {
	private const REPO = 'miji-dev/wp-toolbox';

	public static function apiRelease(array $overrides = []): array {
		return array_merge([
			'tag_name' => 'v4.1.0',
			'draft' => false,
			'prerelease' => false,
			'html_url' => 'https://github.com/miji-dev/wp-toolbox/releases/tag/v4.1.0',
			'published_at' => '2026-10-01T12:00:00Z',
			'body' => "## Changes\n- one\n- two",
			'assets' => [
				['name' => 'something-else.zip', 'browser_download_url' => 'https://github.com/miji-dev/wp-toolbox/releases/download/v4.1.0/something-else.zip'],
				['name' => 'wp-toolbox.zip', 'browser_download_url' => 'https://github.com/miji-dev/wp-toolbox/releases/download/v4.1.0/wp-toolbox.zip'],
			],
		], $overrides);
	}

	public function test_parses_a_regular_release(): void {
		$release = Release::fromGitHub(self::apiRelease(), self::REPO);

		$this->assertNotNull($release);
		$this->assertSame('4.1.0', $release->version);
		$this->assertSame('https://github.com/miji-dev/wp-toolbox/releases/download/v4.1.0/wp-toolbox.zip', $release->package);
		$this->assertSame('https://github.com/miji-dev/wp-toolbox/releases/tag/v4.1.0', $release->url);
		$this->assertSame("## Changes\n- one\n- two", $release->notes);
		$this->assertSame('2026-10-01T12:00:00Z', $release->publishedAt);
		$this->assertFalse($release->prerelease);
	}

	public static function assetFor(string $tag): array {
		return ['assets' => [['name' => 'wp-toolbox.zip', 'browser_download_url' => "https://github.com/miji-dev/wp-toolbox/releases/download/$tag/wp-toolbox.zip"]]];
	}

	public function test_accepts_tags_without_v_prefix_and_prerelease_suffixes(): void {
		$this->assertSame('4.1.0', Release::fromGitHub(self::apiRelease(['tag_name' => '4.1.0'] + self::assetFor('4.1.0')), self::REPO)?->version);
		$this->assertSame('4.0.0-alpha.1', Release::fromGitHub(self::apiRelease(['tag_name' => 'v4.0.0-alpha.1', 'prerelease' => true] + self::assetFor('v4.0.0-alpha.1')), self::REPO)?->version);
	}

	public function test_the_zip_must_belong_to_the_release_tag(): void {
		$this->assertNull(Release::fromGitHub(self::apiRelease(['tag_name' => 'v4.2.0']), self::REPO), 'asset of v4.1.0 attached to v4.2.0');
	}

	public function test_parses_requirements_from_the_notes(): void {
		$release = Release::fromGitHub(self::apiRelease(['body' => "Requires PHP: 8.4\nRequires at least: 7.2\n\n- change"]), self::REPO);

		$this->assertSame('8.4', $release?->requiresPhp);
		$this->assertSame('7.2', $release?->requiresWp);
		$this->assertNull(Release::fromGitHub(self::apiRelease(), self::REPO)?->requiresPhp);
	}

	/**
	 * @dataProvider unusable
	 */
	public function test_rejects_unusable_releases(array $overrides): void {
		$this->assertNull(Release::fromGitHub(self::apiRelease($overrides), self::REPO));
	}

	public static function unusable(): array {
		$asset = static fn (string $url): array => ['assets' => [['name' => 'wp-toolbox.zip', 'browser_download_url' => $url]]];

		return [
			'draft' => [['draft' => true]],
			'no zip asset' => [['assets' => []]],
			'garbage tag' => [['tag_name' => 'latest']],
			'tag with junk' => [['tag_name' => 'v4.1.0; rm -rf']],
			'asset on another host' => [$asset('https://evil.example/wp-toolbox.zip')],
			'asset of another repo' => [$asset('https://github.com/someone/wp-toolbox/releases/download/v4.1.0/wp-toolbox.zip')],
			'asset over http' => [$asset('http://github.com/miji-dev/wp-toolbox/releases/download/v4.1.0/wp-toolbox.zip')],
			'asset path traversal' => [$asset('https://github.com/miji-dev/wp-toolbox/releases/download/../../../evil/wp-toolbox.zip')],
			'missing fields' => [['tag_name' => null]],
		];
	}

	public function test_rejects_non_array_input(): void {
		$this->assertNull(Release::fromGitHub(['message' => 'API rate limit exceeded'], self::REPO));
	}
}
