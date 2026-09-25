<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Login;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Login page branding: the site's own logo, colour and links instead of WordPress'.
 */
final class LoginModule implements Module {
	private const LOGO_WIDTH = 320;
	private const LOGO_MAX_HEIGHT = 120;

	private string $logo = 'wordpress';
	private int $logoImage = 0;
	private string $background = '';
	private bool $hideBackLink = false;

	public function id(): string {
		return 'login';
	}

	public function title(): string {
		return __('Login page', 'wptb');
	}

	public function description(): string {
		return __('Show your site\'s branding on the login page instead of WordPress\'.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::choice(
				'logo',
				[
					'wordpress' => __('WordPress logo', 'wptb'),
					'site' => __('The site\'s logo (or the site icon if there is none)', 'wptb'),
					'custom' => __('The image chosen below', 'wptb'),
				],
				'wordpress',
				__('Logo', 'wptb'),
				__('The logo above the login form. "The site\'s logo" uses the logo set in the theme (Site Editor or Customizer), otherwise the site icon (Settings → General). If neither is set, the WordPress logo stays.', 'wptb'),
				why: __('Clients and users log in to their site, not to WordPress. Their own logo makes the page look familiar and trustworthy.', 'wptb'),
			),
			Field::attachment(
				'logo_image',
				__('Logo image', 'wptb'),
				__('Used when "Logo" is set to "The image chosen below". It is shown at most 320 pixels wide and 120 pixels high.', 'wptb'),
				why: __('For when the login page should show a different logo than the website, e.g. a version that works on the login background.', 'wptb'),
			),
			Field::bool(
				'logo_links_home',
				false,
				__('Logo links to the website', 'wptb'),
				__('Clicking the logo opens the website\'s home page instead of wordpress.org. Screen readers announce the site title instead of "Powered by WordPress".', 'wptb'),
				why: __('A link to wordpress.org is confusing for users who clicked the logo to get back to the website.', 'wptb'),
			),
			Field::color(
				'background_color',
				'',
				__('Background colour', 'wptb'),
				__('Background colour of the login page. Leave empty for WordPress\' light grey.', 'wptb'),
				why: __('Matches the login page to the site\'s colours.', 'wptb'),
				sideEffects: __('Choose a colour the links below the form ("Lost your password?") stay readable on; their colour doesn\'t change.', 'wptb'),
			),
			Field::bool(
				'hide_back_link',
				false,
				__('Hide the "← Go to site" link', 'wptb'),
				__('Removes the link back to the website below the login form.', 'wptb'),
				why: __('Useful when the login page is only used by editors who don\'t need it, or when the logo already links to the website.', 'wptb'),
			),
			Field::bool(
				'hide_language_switcher',
				false,
				__('Hide the language switcher', 'wptb'),
				__('Removes the language selection at the bottom of the login page (only shown if more than one language is installed). Users still see the login page in the site\'s or their own language.', 'wptb'),
				why: __('On single-language sites the switcher is unnecessary clutter.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$logo = $settings->get('login', 'logo');
		$this->logo = is_string($logo) ? $logo : 'wordpress';
		$image = $settings->get('login', 'logo_image');
		$this->logoImage = is_int($image) ? $image : 0;
		$background = $settings->get('login', 'background_color');
		// the schema already guarantees this; checked again because it ends up in a <style> block
		$this->background = is_string($background) && preg_match('/^#[0-9a-fA-F]{6}$/', $background) ? $background : '';
		$this->hideBackLink = $settings->get('login', 'hide_back_link') === true;

		if ($this->logo !== 'wordpress' || $this->background !== '' || $this->hideBackLink) {
			add_action('login_enqueue_scripts', [$this, 'addStyles']);
		}
		if ($settings->get('login', 'logo_links_home') === true) {
			add_filter('login_headerurl', static fn (): string => home_url('/'));
			add_filter('login_headertext', static fn (): string => get_bloginfo('name', 'display'));
		}
		if ($this->hideBackLink) {
			add_filter('login_site_html_link', '__return_empty_string');
		}
		if ($settings->get('login', 'hide_language_switcher') === true) {
			add_filter('login_display_language_dropdown', '__return_false');
		}
	}

	public function addStyles(): void {
		$css = '';

		$logo = $this->logoImage();
		if ($logo !== null) {
			[$url, $width, $height] = $logo;
			$boxHeight = $width > 0 && $height > 0 ? min(self::LOGO_MAX_HEIGHT, (int) round(self::LOGO_WIDTH * $height / $width)) : self::LOGO_MAX_HEIGHT;
			$css .= sprintf(
				'.login h1 a{background-image:url("%s");background-size:contain;background-position:center;width:%dpx;max-width:100%%;height:%dpx}',
				$url,
				self::LOGO_WIDTH,
				$boxHeight,
			);
		}
		if ($this->background !== '') {
			$css .= sprintf('body.login{background-color:%s}', $this->background);
		}
		if ($this->hideBackLink) {
			$css .= '#backtoblog{display:none}';
		}

		if ($css !== '') {
			wp_add_inline_style('login', $css);
		}
	}

	/**
	 * @return array{string, int, int}|null url (safe inside url("…")), width, height
	 */
	private function logoImage(): ?array {
		$id = match ($this->logo) {
			'site' => $this->siteLogo(),
			'custom' => $this->logoImage,
			default => 0,
		};
		if ($id <= 0 || !wp_attachment_is_image($id)) {
			return null;
		}

		$src = wp_get_attachment_image_src($id, 'medium_large');
		if ($src === false) {
			return null;
		}
		// esc_url_raw drops quotes, backslashes, spaces and angle brackets, so the URL can't end url("…") or the <style> block
		$url = esc_url_raw($src[0], ['http', 'https']);
		if ($url === '') {
			return null;
		}
		return [$url, (int) $src[1], (int) $src[2]];
	}

	private function siteLogo(): int {
		// the theme mod also returns the block themes' "site_logo" option (core filter)
		$logo = get_theme_mod('custom_logo');
		if (is_numeric($logo) && (int) $logo > 0) {
			return (int) $logo;
		}
		$icon = get_option('site_icon');
		return is_numeric($icon) ? (int) $icon : 0;
	}
}
