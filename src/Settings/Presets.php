<?php

declare(strict_types=1);

namespace Miji\Toolbox\Settings;

/**
 * Ready-made sets of values the settings page can fill in (nothing is saved until the user saves).
 */
final class Presets {
	/**
	 * Cleanups that suit almost every site and change nothing visitors or editors rely on.
	 *
	 * Left out on purpose: comments, blog features, the login page look, maintenance and Elementor (decisions per
	 * site), and noindex/mail blocking (a wrongly set environment type would hit the live site).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function recommended(): array {
		return [
			'head' => [
				'remove_generator' => true,
				'remove_rsd' => true,
				'remove_shortlink' => true,
				'disable_emojis' => true,
			],
			'security' => [
				'disable_xmlrpc' => true,
				'block_user_enumeration' => true,
				'generic_login_errors' => true,
				'disable_file_editor' => true,
				'security_headers' => true,
			],
			'admin' => [
				'hide_update_notices_for_non_admins' => true,
			],
			'dashboard' => [
				'hide_widgets' => ['welcome_panel', 'dashboard_primary'],
			],
			'media' => [
				'clean_filenames' => true,
			],
			'editor' => [
				'disable_remote_patterns' => true,
				'disable_block_directory' => true,
				'disable_openverse' => true,
			],
			'environment' => [
				'badge' => 'non_production',
			],
		];
	}
}
