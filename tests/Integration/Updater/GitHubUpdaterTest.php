<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Updater;

use Miji\Toolbox\Tests\Unit\Updater\ReleaseTest;
use Miji\Toolbox\Updater\GitHubUpdater;
use WP_UnitTestCase;

final class GitHubUpdaterTest extends WP_UnitTestCase {
	private const FILE = 'wp-toolbox/wp-toolbox.php';

	/** @var list<string> */
	private array $requested = [];

	/** @var array{0:int,1:string}|\WP_Error */
	private $response;

	private GitHubUpdater $updater;

	public function set_up(): void {
		parent::set_up();
		$this->requested = [];
		$this->response = [200, (string) wp_json_encode(ReleaseTest::apiRelease())];

		add_filter('pre_http_request', function ($pre, $args, $url) {
			if (!str_starts_with($url, 'https://api.github.com/')) {
				return $pre;
			}
			$this->requested[] = $url;
			if ($this->response instanceof \WP_Error) {
				return $this->response;
			}
			return ['headers' => [], 'body' => $this->response[1], 'response' => ['code' => $this->response[0], 'message' => ''], 'cookies' => [], 'filename' => null];
		}, 10, 3);

		$this->updater = new GitHubUpdater(self::FILE, 'miji-dev/wp-toolbox');
		$this->updater->register();
	}

	private function check(string $file = self::FILE): mixed {
		return apply_filters('update_plugins_github.com', false, ['Version' => '4.0.0', 'UpdateURI' => 'https://github.com/miji-dev/wp-toolbox'], $file, []);
	}

	public function test_reports_the_latest_release_for_this_plugin(): void {
		$update = $this->check();

		$this->assertIsArray($update);
		$this->assertSame('4.1.0', $update['version']);
		$this->assertSame('wp-toolbox', $update['slug']);
		$this->assertSame('https://github.com/miji-dev/wp-toolbox/releases/download/v4.1.0/wp-toolbox.zip', $update['package']);
		$this->assertSame('https://github.com/miji-dev/wp-toolbox/releases/tag/v4.1.0', $update['url']);
		$this->assertSame(['https://api.github.com/repos/miji-dev/wp-toolbox/releases/latest'], $this->requested);
	}

	public function test_leaves_other_plugins_alone(): void {
		$this->assertFalse($this->check('other/other.php'));
		$this->assertSame([], $this->requested);
	}

	public function test_keeps_a_previous_filter_result_for_other_plugins(): void {
		$other = ['version' => '9.9'];
		$this->assertSame($other, apply_filters('update_plugins_github.com', $other, [], 'other/other.php', []));
	}

	public function test_caches_the_result(): void {
		$this->check();
		$this->check();

		$this->assertCount(1, $this->requested);
	}

	public function test_failures_are_cached_too_and_report_no_update(): void {
		$this->response = [403, '{"message":"API rate limit exceeded"}'];

		$this->assertFalse($this->check());
		$this->assertFalse($this->check());
		$this->assertCount(1, $this->requested, 'a failing GitHub API is not hammered on every check');
	}

	public function test_network_errors_report_no_update(): void {
		$this->response = new \WP_Error('http_request_failed', 'timeout');

		$this->assertFalse($this->check());
	}

	public function test_invalid_json_reports_no_update(): void {
		$this->response = [200, '<html>not json'];

		$this->assertFalse($this->check());
	}

	public function test_beta_channel_uses_the_newest_release_including_prereleases(): void {
		$updater = new GitHubUpdater(self::FILE, 'miji-dev/wp-toolbox', beta: true);
		$this->response = [200, (string) wp_json_encode([
			ReleaseTest::apiRelease(['tag_name' => 'v4.2.0-beta.1', 'prerelease' => true, 'assets' => [['name' => 'wp-toolbox.zip', 'browser_download_url' => 'https://github.com/miji-dev/wp-toolbox/releases/download/v4.2.0-beta.1/wp-toolbox.zip']]]),
			ReleaseTest::apiRelease(),
		])];

		$update = $updater->check(false, ['Version' => '4.0.0'], self::FILE);

		$this->assertSame('4.2.0-beta.1', $update['version']);
		$this->assertSame(['https://api.github.com/repos/miji-dev/wp-toolbox/releases?per_page=10'], $this->requested);
	}

	public function test_wordpress_offers_the_update(): void {
		$plugins = ['wp-toolbox/wp-toolbox.php' => ['Name' => 'wp toolbox', 'Version' => '4.0.0', 'UpdateURI' => 'https://github.com/miji-dev/wp-toolbox']];
		wp_cache_set('plugins', ['' => $plugins], 'plugins');
		delete_site_transient('update_plugins');
		// plugins with an Update URI are left out of the wordpress.org request; answer it offline with "nothing"
		add_filter('pre_http_request', static fn ($pre, $args, $url) => str_contains($url, 'api.wordpress.org')
			? ['headers' => [], 'body' => '{"plugins":[],"translations":[],"no_update":[]}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]
			: $pre, 10, 3);

		wp_update_plugins();

		$transient = get_site_transient('update_plugins');
		$this->assertArrayHasKey(self::FILE, $transient->response);
		$this->assertSame('4.1.0', $transient->response[self::FILE]->new_version);
		$this->assertSame('https://github.com/miji-dev/wp-toolbox/releases/download/v4.1.0/wp-toolbox.zip', $transient->response[self::FILE]->package);
	}

	public function test_details_popup_shows_the_release_notes_escaped(): void {
		$this->response = [200, (string) wp_json_encode(ReleaseTest::apiRelease(['body' => "## New\n- <script>alert(1)</script> safe"]))];

		$info = apply_filters('plugins_api', false, 'plugin_information', (object) ['slug' => 'wp-toolbox']);

		$this->assertSame('4.1.0', $info->version);
		$this->assertStringNotContainsString('<script>', $info->sections['changelog']);
		$this->assertStringContainsString('&lt;script&gt;', $info->sections['changelog']);
		$this->assertFalse(apply_filters('plugins_api', false, 'plugin_information', (object) ['slug' => 'akismet']));
	}
}
