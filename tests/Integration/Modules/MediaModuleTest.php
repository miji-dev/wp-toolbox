<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Media\MediaModule;
use Miji\Toolbox\Settings\Settings;
use WP_UnitTestCase;

final class MediaModuleTest extends WP_UnitTestCase {
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40" onload="alert(1)"><script>alert(2)</script><rect width="120" height="40" fill="#123456"/></svg>';

	private MediaModule $module;
	/** @var list<string> */
	private array $files = [];

	public function set_up(): void {
		parent::set_up();
		$this->module = new MediaModule();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
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
		$this->module->register(new Settings([$this->module], ['media' => $values]));
	}

	private function loginAs(string $role): void {
		wp_set_current_user(self::factory()->user->create(['role' => $role]));
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

	// --- off ------------------------------------------------------------------------------------------------

	public function test_off_changes_nothing(): void {
		$this->loginAs('administrator');
		$core = sanitize_file_name('Größe Übersicht.JPG');
		$this->enable([]);

		$this->assertSame($core, sanitize_file_name('Größe Übersicht.JPG'));
		$this->assertSame(2560, apply_filters('big_image_size_threshold', 2560, [3000, 2000], '', 0));
		$this->assertSame(82, apply_filters('wp_editor_set_quality', 82, 'image/jpeg'));
		$this->assertArrayNotHasKey('svg', get_allowed_mime_types());
		$this->assertArrayHasKey('error', $this->upload('logo.svg', self::SVG));
	}

	// --- file names -------------------------------------------------------------------------------------------

	public function test_uploaded_file_names_are_cleaned(): void {
		$this->loginAs('administrator');
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

	// --- images -----------------------------------------------------------------------------------------------

	public function test_big_image_threshold_can_be_changed_or_disabled(): void {
		$this->enable(['big_image_threshold' => 4000]);
		$this->assertSame(4000, apply_filters('big_image_size_threshold', 2560, [5000, 3000], '', 0));
	}

	public function test_big_image_scaling_can_be_turned_off(): void {
		$this->enable(['big_image_threshold' => 0]);
		$this->assertFalse(apply_filters('big_image_size_threshold', 2560, [5000, 3000], '', 0));
	}

	public function test_image_quality_applies_to_generated_images(): void {
		$this->enable(['image_quality' => 70]);

		$editor = wp_get_image_editor(DIR_TESTDATA . '/images/canola.jpg');
		$this->assertNotInstanceOf(\WP_Error::class, $editor);
		$this->assertSame(70, $editor->get_quality());
	}

	public function test_default_values_add_no_filters(): void {
		$this->enable(['big_image_threshold' => 2560, 'image_quality' => 82]);

		$this->assertFalse(has_filter('big_image_size_threshold'));
		$this->assertFalse(has_filter('wp_editor_set_quality'));
	}

	public function test_selected_image_sizes_are_not_generated(): void {
		$this->enable(['disable_image_sizes' => ['medium_large', '1536x1536']]);

		$sizes = apply_filters('intermediate_image_sizes_advanced', array_fill_keys(['thumbnail', 'medium', 'medium_large', 'large', '1536x1536'], []), [], 0);

		$this->assertSame(['thumbnail', 'medium', 'large'], array_keys($sizes));
	}

	public function test_size_options_list_the_registered_sizes(): void {
		add_image_size('wptb_hero', 1600, 600, true);
		$options = $this->module->fields()[3]->options();
		remove_image_size('wptb_hero');

		$this->assertArrayHasKey('thumbnail', $options);
		$this->assertArrayHasKey('2048x2048', $options);
		$this->assertStringContainsString('1600 × 600', $options['wptb_hero']);
	}

	// --- SVG ----------------------------------------------------------------------------------------------------

	public function test_selected_roles_can_upload_svgs_which_are_sanitized(): void {
		$this->loginAs('administrator');
		$this->enable(['svg_upload_roles' => ['administrator']]);

		$result = $this->upload('Logo.svg', self::SVG);

		$this->assertArrayNotHasKey('error', $result, print_r($result, true));
		$this->assertSame('image/svg+xml', $result['type']);
		$stored = (string) file_get_contents($result['file']);
		$this->assertStringNotContainsString('script', $stored);
		$this->assertStringNotContainsString('onload', $stored);
		$this->assertStringContainsString('fill="#123456"', $stored);
	}

	public function test_other_roles_cannot_upload_svgs(): void {
		$this->loginAs('editor');
		$this->enable(['svg_upload_roles' => ['administrator']]);

		$this->assertArrayNotHasKey('svg', get_allowed_mime_types());
		$this->assertArrayHasKey('error', $this->upload('logo.svg', self::SVG));
	}

	public function test_files_that_are_not_really_svgs_are_rejected(): void {
		$this->loginAs('administrator');
		$this->enable(['svg_upload_roles' => ['administrator']]);

		$result = $this->upload('evil.svg', '<html><body><script>alert(1)</script></body></html>');

		$this->assertArrayHasKey('error', $result);
	}

	public function test_compressed_svgz_is_never_allowed(): void {
		$this->loginAs('administrator');
		$this->enable(['svg_upload_roles' => ['administrator']]);

		$this->assertArrayNotHasKey('svgz', get_allowed_mime_types());
	}

	public function test_svgs_that_the_server_does_not_recognise_are_still_accepted(): void {
		// some servers report SVGs without XML declaration as text/plain or text/html
		$this->loginAs('administrator');
		$this->enable(['svg_upload_roles' => ['administrator']]);
		$tmp = wp_tempnam('logo.svg');
		file_put_contents($tmp, self::SVG);
		$this->files[] = $tmp;

		$checked = apply_filters('wp_check_filetype_and_ext', ['ext' => false, 'type' => false, 'proper_filename' => false], $tmp, 'logo.svg', null, 'text/plain');

		$this->assertSame(['ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false], $checked);
	}

	public function test_the_filetype_fallback_only_applies_to_real_svgs_of_allowed_users(): void {
		$this->enable(['svg_upload_roles' => ['administrator']]);
		$tmp = wp_tempnam('x.svg');
		file_put_contents($tmp, '<?php echo 1;');
		$this->files[] = $tmp;
		$empty = ['ext' => false, 'type' => false, 'proper_filename' => false];

		$this->loginAs('administrator');
		$this->assertSame($empty, apply_filters('wp_check_filetype_and_ext', $empty, $tmp, 'x.svg', null, 'text/x-php'), 'not an svg');

		file_put_contents($tmp, self::SVG);
		$this->loginAs('editor');
		$this->assertSame($empty, apply_filters('wp_check_filetype_and_ext', $empty, $tmp, 'x.svg', null, 'text/plain'), 'role not allowed');
	}

	public function test_svg_attachments_get_width_and_height(): void {
		$this->loginAs('administrator');
		$this->enable(['svg_upload_roles' => ['administrator']]);
		$upload = $this->upload('logo.svg', self::SVG);
		$id = self::factory()->attachment->create(['file' => $upload['file'], 'post_mime_type' => 'image/svg+xml']);
		update_attached_file($id, $upload['file']);

		$meta = wp_generate_attachment_metadata($id, $upload['file']);

		$this->assertSame(120, $meta['width']);
		$this->assertSame(40, $meta['height']);
	}

	public function test_other_uploads_are_not_touched_by_the_svg_handling(): void {
		$this->loginAs('administrator');
		$this->enable(['svg_upload_roles' => ['administrator']]);
		$png = (string) file_get_contents(DIR_TESTDATA . '/images/test-image.png');

		$result = $this->upload('image.png', $png);

		$this->assertSame($png, file_get_contents($result['file']));
	}

	public function test_every_setting_is_explained(): void {
		foreach ($this->module->fields() as $field) {
			$this->assertNotEmpty($field->description, $field->key);
			$this->assertNotEmpty($field->why, $field->key);
		}
	}
}
