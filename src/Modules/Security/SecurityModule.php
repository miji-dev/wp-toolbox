<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Security;

use Closure;
use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;

/**
 * Closes attack surface that WordPress leaves open by default.
 *
 * Login protection (brute force, hidden usernames, generic login errors, application passwords) is left to
 * Wordfence, which does all of that by default.
 */
final class SecurityModule implements Module {
	private const FILE_EDIT_CAPS = ['edit_themes', 'edit_plugins', 'edit_files'];

	/** @var Closure(): bool */
	private Closure $headersEnabled;

	public function __construct(private readonly ?HeadersFile $headersFile = null) {
		$this->headersEnabled = static fn (): bool => false;
	}

	public function id(): string {
		return 'security';
	}

	public function title(): string {
		return __('Security', 'wptb');
	}

	public function description(): string {
		return __('Closes doors WordPress leaves open by default. Login protection (hiding usernames, login errors, brute force) is best left to Wordfence, which does it by default.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::bool(
				'disable_xmlrpc',
				true,
				__('Disable XML-RPC', 'wptb'),
				what: __('Switches off the old XML-RPC interface (xmlrpc.php) completely.', 'wptb'),
				how: __('Answers every request to xmlrpc.php with 403 "forbidden" right after WordPress has loaded, disables all XML-RPC methods, and removes the X-Pingback header that advertises the interface.', 'wptb'),
				why: __('XML-RPC predates the REST API and is hardly used anymore, but it is a favourite target: attackers use it to try hundreds of passwords in a single request and to misuse sites for attacks on others.', 'wptb'),
				sideEffects: __('Tools that still use XML-RPC stop working with this site, e.g. Jetpack and some older publishing apps.', 'wptb'),
			),
			Field::bool(
				'disable_file_editor',
				true,
				__('Disable the theme and plugin file editor', 'wptb'),
				what: __('Removes the code editors under Appearance → Theme File Editor and Tools → Plugin File Editor for everyone, including administrators. Installing and updating plugins and themes keeps working.', 'wptb'),
				how: __('Denies the edit_themes, edit_plugins and edit_files capabilities to all users, which is what WordPress\' DISALLOW_FILE_EDIT constant does, but switchable.', 'wptb'),
				why: __('Anyone who gets into an admin account could use the editor to run their own code on the server. Editing live code is also an easy way to break a site.', 'wptb'),
				sideEffects: __('Changes to a child theme (e.g. Hello Elementor\'s functions.php) have to be made via FTP or a deployment instead.', 'wptb'),
			),
			Field::bool(
				'security_headers',
				true,
				__('Send security headers', 'wptb'),
				what: __('Tells browsers to be stricter with your site: other sites may not show your pages in a frame, file types are not guessed, and only your domain (not the full address) is passed on when visitors follow a link to another site. The header that reveals the PHP version is removed.', 'wptb'),
				how: __('Sends X-Frame-Options: SAMEORIGIN, X-Content-Type-Options: nosniff and Referrer-Policy: strict-origin-when-cross-origin, and removes X-Powered-By. On Apache and LiteSpeed servers the same rules are also written to .htaccess (in a block marked "wp-toolbox", removed again when you switch this off or deactivate the plugin), so they also reach pages served from a page cache like WP-Optimize, and static files.', 'wptb'),
				why: __('These headers protect visitors against clickjacking (your page invisibly framed by another site) and content sniffing attacks, and give other sites less information about your visitors.', 'wptb'),
				sideEffects: __('Other websites can no longer show your pages in an iframe; the Elementor editor is not affected (it frames pages of the same site). These values replace values for the same headers set by the server or other plugins.', 'wptb'),
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function register(Settings $settings): void {
		$on = static fn (string $key): bool => $settings->get('security', $key) === true;

		if ($on('disable_xmlrpc')) {
			add_filter('xmlrpc_enabled', '__return_false');
			add_filter('xmlrpc_methods', '__return_empty_array', PHP_INT_MAX);
			add_filter('wp_headers', [$this, 'removePingbackHeader']);
			// the plugin boots on init 0, this runs right after
			add_action('init', [$this, 'blockXmlrpcRequest'], 1);
			// at init the XML-RPC server doesn't exist yet; its die handler would send an empty 200
			add_filter('wp_die_xmlrpc_handler', static fn (): string => '_default_wp_die_handler');
		}

		if ($on('disable_file_editor')) {
			add_filter('map_meta_cap', [$this, 'denyFileEditing'], 10, 2);
		}

		if ($on('security_headers')) {
			add_filter('wp_headers', [$this, 'addSecurityHeaders']);
			add_action('send_headers', [$this, 'removePoweredByHeader']);
		}

		// .htaccess is only written from the admin (where file access is expected): on every admin page, so it also
		// happens after an update, and right after the settings were saved
		$this->headersEnabled = static fn (): bool => $on('security_headers');
		add_action('admin_init', [$this, 'syncHeadersFile']);
		add_action('add_option_' . Settings::OPTION, [$this, 'syncHeadersFile']);
		add_action('update_option_' . Settings::OPTION, [$this, 'syncHeadersFile']);
	}

	/**
	 * Reads the setting when called: after saving, it already has the new value.
	 */
	public function syncHeadersFile(): void {
		($this->headersFile ?? new HeadersFile())->sync(($this->headersEnabled)());
	}

	public function blockXmlrpcRequest(): void {
		if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
			wp_die(esc_html__('XML-RPC services are disabled on this site.', 'wptb'), '', ['response' => 403]);
		}
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public function removePingbackHeader(array $headers): array {
		unset($headers['X-Pingback']);
		return $headers;
	}

	/**
	 * @param list<string> $caps
	 * @return list<string>
	 */
	public function denyFileEditing(array $caps, string $cap): array {
		return in_array($cap, self::FILE_EDIT_CAPS, true) ? ['do_not_allow'] : $caps;
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public function addSecurityHeaders(array $headers): array {
		return array_merge($headers, HeadersFile::HEADERS);
	}

	public function removePoweredByHeader(): void {
		if (!headers_sent()) {
			header_remove('X-Powered-By');
		}
	}
}
