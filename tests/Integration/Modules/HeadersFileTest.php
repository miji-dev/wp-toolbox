<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Security\HeadersFile;
use WP_UnitTestCase;

final class HeadersFileTest extends WP_UnitTestCase {
	private const EXISTING = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>\n# END WordPress\n\n# BEGIN WP-Optimize Browser Cache\nExpiresActive On\n# END WP-Optimize Browser Cache\n";

	private string $file;

	public function set_up(): void {
		parent::set_up();
		$this->file = (string) wp_tempnam('htaccess');
		file_put_contents($this->file, self::EXISTING);
	}

	public function tear_down(): void {
		@unlink($this->file);
		parent::tear_down();
	}

	private function content(): string {
		return (string) file_get_contents($this->file);
	}

	public function test_rules_set_each_header_once_whatever_way_php_runs(): void {
		$this->assertSame([
			'<IfModule mod_headers.c>',
			"\tHeader unset X-Frame-Options",
			"\tHeader always set X-Frame-Options \"SAMEORIGIN\"",
			"\tHeader unset X-Content-Type-Options",
			"\tHeader always set X-Content-Type-Options \"nosniff\"",
			"\tHeader unset Referrer-Policy",
			"\tHeader always set Referrer-Policy \"strict-origin-when-cross-origin\"",
			"\tHeader unset X-Powered-By",
			"\tHeader always unset X-Powered-By",
			'</IfModule>',
		], HeadersFile::rules());
	}

	public function test_block_is_added_next_to_other_blocks_which_stay_untouched(): void {
		(new HeadersFile($this->file, apache: true))->sync(true);

		$this->assertStringStartsWith(self::EXISTING, $this->content());
		$this->assertStringContainsString("# BEGIN wp-toolbox\n", $this->content());
		$this->assertStringContainsString("Header always set X-Frame-Options \"SAMEORIGIN\"\n", $this->content());
		$this->assertStringEndsWith("# END wp-toolbox", $this->content());
	}

	public function test_syncing_again_changes_nothing(): void {
		$file = new HeadersFile($this->file, apache: true);
		$file->sync(true);
		$first = $this->content();
		touch($this->file, time() - 60);
		clearstatcache();
		$mtime = filemtime($this->file);

		$file->sync(true);

		$this->assertSame($first, $this->content());
		clearstatcache();
		$this->assertSame($mtime, filemtime($this->file), 'not rewritten on every admin page');
	}

	public function test_removing_restores_the_original_file(): void {
		$file = new HeadersFile($this->file, apache: true);
		$file->sync(true);

		$file->sync(false);

		$this->assertSame(self::EXISTING, $this->content());
	}

	public function test_remove_also_works_without_apache_detection(): void {
		// deactivation and uninstall clean up regardless of the server
		(new HeadersFile($this->file, apache: true))->sync(true);

		(new HeadersFile($this->file, apache: false))->remove();

		$this->assertSame(self::EXISTING, $this->content());
	}

	public function test_nothing_is_written_on_servers_without_htaccess(): void {
		(new HeadersFile($this->file, apache: false))->sync(true);

		$this->assertSame(self::EXISTING, $this->content());
	}

	public function test_a_missing_file_is_created_only_when_needed(): void {
		unlink($this->file);

		(new HeadersFile($this->file, apache: true))->sync(false);
		$this->assertFileDoesNotExist($this->file);

		(new HeadersFile($this->file, apache: true))->sync(true);
		$this->assertStringContainsString('# BEGIN wp-toolbox', $this->content());
	}

	public function test_a_read_only_file_is_left_alone_without_errors(): void {
		chmod($this->file, 0444);
		try {
			(new HeadersFile($this->file, apache: true))->sync(true);
			$this->assertSame(self::EXISTING, $this->content());

			(new HeadersFile($this->file, apache: true))->remove();
			$this->assertSame(self::EXISTING, $this->content());
		} finally {
			chmod($this->file, 0644);
		}
	}
}
