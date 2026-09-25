<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Media\MediaModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\DefaultsOff;
use WP_UnitTestCase;

final class MediaModuleTest extends WP_UnitTestCase {
	private MediaModule $module;
	/** @var list<string> */
	private array $files = [];

	public function set_up(): void {
		parent::set_up();
		$this->module = new MediaModule();
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	public function tear_down(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['media' => DefaultsOff::with('media', $values)]));
	}

	/**
	 * Uploads through WordPress' own sideload handler (same checks as the media library).
	 *
	 * @return array<string, mixed>
	 */
	private function upload(string $name, string $content): array {
		$tmp = wp_tempnam($name);
		file_put_contents($tmp, $content);
		$file = ['name' => $name, 'tmp_name' => $tmp, 'type' => '', 'size' => strlen($content), 'error' => 0];
		$result = wp_handle_sideload($file, ['test_form' => false]);
		if (isset($result['file'])) {
			$this->files[] = $result['file'];
		}
		return $result;
	}

	public function test_off_changes_nothing(): void {
		$core = sanitize_file_name('Größe Übersicht.JPG');
		$this->enable([]);

		$this->assertSame($core, sanitize_file_name('Größe Übersicht.JPG'));
		$this->assertFalse(has_filter('intermediate_image_sizes_advanced'));
	}

	// --- file names -------------------------------------------------------------------------------------------

	public function test_uploaded_file_names_are_cleaned(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$this->enable(['clean_filenames' => true]);

		$this->assertSame('groesse-uebersicht-1.jpg', sanitize_file_name('Größe Übersicht (1).JPG'));
		$this->assertSame('strasse.pdf', sanitize_file_name('Straße.pdf'));

		$result = $this->upload('Mein Foto Ä.PNG', (string) file_get_contents(DIR_TESTDATA . '/images/test-image.png'));
		$this->assertSame('mein-foto-ae.png', basename($result['file']));
	}

	public function test_cores_double_extension_guard_is_kept(): void {
		// WordPress renames "shell.php.jpg" so servers that execute any ".php." file don't run it
		$this->enable(['clean_filenames' => true]);

		$this->assertSame('shell.php_.jpg', sanitize_file_name('Shell.php.jpg'));
	}

	public function test_svgs_are_not_allowed_by_this_plugin(): void {
		// SVG uploads are Elementor's job ("Enable Unfiltered File Uploads", sanitized)
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$this->enable(['clean_filenames' => true]);

		$this->assertArrayNotHasKey('svg', get_allowed_mime_types());
	}

	// --- image sizes -------------------------------------------------------------------------------------------

	public function test_selected_image_sizes_are_not_generated(): void {
		$this->enable(['disable_image_sizes' => ['medium_large', '1536x1536']]);

		$sizes = apply_filters('intermediate_image_sizes_advanced', array_fill_keys(['thumbnail', 'medium', 'medium_large', 'large', '1536x1536'], []), [], 0);

		$this->assertSame(['thumbnail', 'medium', 'large'], array_keys($sizes));
	}

	public function test_size_options_list_the_registered_sizes(): void {
		add_image_size('wptb_hero', 1600, 600, true);
		$options = $this->module->fields()[1]->options();
		remove_image_size('wptb_hero');

		$this->assertArrayHasKey('thumbnail', $options);
		$this->assertArrayHasKey('2048x2048', $options);
		$this->assertStringContainsString('1600 × 600', $options['wptb_hero']);
	}
}
