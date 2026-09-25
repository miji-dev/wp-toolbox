<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Modules;

use Miji\Toolbox\Modules\Login\LoginModule;
use Miji\Toolbox\Settings\Settings;
use Miji\Toolbox\Tests\Support\DefaultsOff;
use WP_UnitTestCase;

final class LoginModuleTest extends WP_UnitTestCase {
	private LoginModule $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new LoginModule();
		wp_enqueue_style('login'); // what wp-login.php does before login_enqueue_scripts
	}

	public function tear_down(): void {
		wp_dequeue_style('login');
		wp_styles()->registered['login']->extra = [];
		remove_theme_mod('custom_logo');
		delete_option('site_logo');
		delete_option('site_icon');
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function enable(array $values): void {
		$this->module->register(new Settings([$this->module], ['login' => DefaultsOff::with('login', $values)]));
	}

	private function css(): string {
		do_action('login_enqueue_scripts');
		$after = wp_styles()->get_data('login', 'after');
		return is_array($after) ? implode("\n", $after) : '';
	}

	private function image(int $width, int $height, string $name = 'logo.png'): int {
		$id = self::factory()->attachment->create_object(['file' => $name, 'post_mime_type' => 'image/png']);
		wp_update_attachment_metadata($id, ['width' => $width, 'height' => $height, 'file' => $name]);
		return $id;
	}

	public function test_off_changes_nothing(): void {
		$this->enable([]);

		$this->assertSame('', $this->css());
		$this->assertSame('https://wordpress.org/', apply_filters('login_headerurl', 'https://wordpress.org/'));
		$this->assertSame('Powered by WordPress', apply_filters('login_headertext', 'Powered by WordPress'));
		$this->assertTrue(apply_filters('login_display_language_dropdown', true));
	}

	// --- logo ---------------------------------------------------------------------------------------------

	public function test_site_logo_is_used(): void {
		$logo = $this->image(600, 200);
		set_theme_mod('custom_logo', $logo);
		$this->enable(['logo' => 'site']);

		$css = $this->css();

		$this->assertStringContainsString('url("' . wp_get_attachment_url($logo) . '")', $css);
		$this->assertStringContainsString('height:107px', $css, '320 px wide, keeps the 3:1 ratio');
	}

	public function test_block_theme_site_logo_is_used(): void {
		$logo = $this->image(100, 100);
		update_option('site_logo', $logo);
		$this->enable(['logo' => 'site']);

		$this->assertStringContainsString(wp_get_attachment_url($logo), $this->css());
	}

	public function test_site_icon_is_the_fallback(): void {
		$icon = $this->image(512, 512, 'icon.png');
		update_option('site_icon', $icon);
		$this->enable(['logo' => 'site']);

		$css = $this->css();

		$this->assertStringContainsString(wp_get_attachment_url($icon), $css);
		$this->assertStringContainsString('height:120px', $css, 'fits into 320 × 120');
	}

	public function test_without_logo_or_icon_the_wordpress_logo_stays(): void {
		$this->enable(['logo' => 'site']);

		$this->assertStringNotContainsString('background-image', $this->css());
	}

	public function test_a_chosen_image_is_used(): void {
		$image = $this->image(300, 300, 'chosen.png');
		set_theme_mod('custom_logo', $this->image(600, 200));
		$this->enable(['logo' => 'custom', 'logo_image' => $image]);

		$this->assertStringContainsString(wp_get_attachment_url($image), $this->css());
	}

	public function test_a_deleted_or_non_image_attachment_is_ignored(): void {
		$pdf = self::factory()->attachment->create_object(['file' => 'doc.pdf', 'post_mime_type' => 'application/pdf']);
		$this->enable(['logo' => 'custom', 'logo_image' => $pdf]);
		$this->assertStringNotContainsString('background-image', $this->css());

		$this->enable(['logo' => 'custom', 'logo_image' => 999999]);
		$this->assertStringNotContainsString('background-image', $this->css());
	}

	public function test_image_urls_cannot_break_out_of_the_css(): void {
		$logo = $this->image(100, 100);
		add_filter('wp_get_attachment_url', static fn (): string => 'https://example.org/a").x{}</style><script>alert(1)</script>.png');
		$this->enable(['logo' => 'custom', 'logo_image' => $logo]);

		$css = $this->css();

		$this->assertStringNotContainsString('<', $css);
		$this->assertStringNotContainsString('")', str_replace('.png")', '', $css), 'only the closing quote of url() itself');
	}

	public function test_image_urls_with_other_schemes_are_not_used(): void {
		$logo = $this->image(100, 100);
		add_filter('wp_get_attachment_url', static fn (): string => 'javascript:alert(1)');
		$this->enable(['logo' => 'custom', 'logo_image' => $logo]);

		$this->assertStringNotContainsString('background-image', $this->css());
	}

	public function test_logo_links_to_the_site(): void {
		$this->enable(['logo_links_home' => true]);

		$this->assertSame(home_url('/'), apply_filters('login_headerurl', 'https://wordpress.org/'));
		$this->assertSame(get_bloginfo('name', 'display'), apply_filters('login_headertext', 'Powered by WordPress'));
	}

	// --- colours, links, language --------------------------------------------------------------------------

	public function test_background_colour(): void {
		$this->enable(['background_color' => '#1a2b3c']);

		$this->assertStringContainsString('body.login{background-color:#1a2b3c}', $this->css());
	}

	public function test_language_switcher_can_be_hidden(): void {
		$this->enable(['hide_language_switcher' => true]);

		$this->assertFalse(apply_filters('login_display_language_dropdown', true));
	}

	public function test_invalid_colour_from_wp_config_is_not_printed(): void {
		// overrides that fail validation are ignored (default used); make sure nothing gets through
		$this->enable(['background_color' => 'red;}</style><script>']);

		$this->assertStringNotContainsString('script', $this->css());
	}
}
