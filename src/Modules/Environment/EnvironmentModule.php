<?php

declare(strict_types=1);

namespace Miji\Toolbox\Modules\Environment;

use Miji\Toolbox\Module;
use Miji\Toolbox\Settings\Field;
use Miji\Toolbox\Settings\Settings;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Admin_Bar;

/**
 * Keeps staging and development copies from being mistaken for the live site: a badge, no indexing, no mail to real people.
 */
final class EnvironmentModule implements Module {
	/** background, text colour */
	private const COLORS = [
		'production' => ['#b32d2e', '#fff'],
		'staging' => ['#dba617', '#1d2327'],
		'development' => ['#2271b1', '#fff'],
		'local' => ['#008a20', '#fff'],
	];

	private string $mailTo = '';

	/**
	 * @param string|null $environment for tests; WordPress caches its value for the whole request
	 */
	public function __construct(private readonly ?string $environment = null) {
	}

	public function id(): string {
		return 'environment';
	}

	public function title(): string {
		return __('Environment', 'wptb');
	}

	public function description(): string {
		return __('Tell live, staging and development copies of the site apart. WordPress knows which one it is from the WP_ENVIRONMENT_TYPE setting in wp-config.php (many hosts set it for staging sites automatically); without it, every site counts as live.', 'wptb');
	}

	public function fields(): array {
		return [
			Field::choice(
				'badge',
				[
					'off' => __('Off', 'wptb'),
					'non_production' => __('Only on staging, development and local sites', 'wptb'),
					'all' => __('On every site, including the live one', 'wptb'),
				],
				'non_production',
				__('Environment badge in the toolbar', 'wptb'),
				what: __('Shows a coloured label in the toolbar: "Live" (red), "Staging" (yellow), "Development" (blue) or "Local" (green).', 'wptb'),
				how: __('Adds a toolbar item with the environment WordPress reports (from WP_ENVIRONMENT_TYPE) and colours it with a small inline style, in the admin and on the website.', 'wptb'),
				why: __('With the live site and a staging copy open side by side, it\'s easy to change the wrong one. The badge makes it obvious at a glance.', 'wptb'),
			),
			Field::bool(
				'noindex',
				true,
				__('Keep non-live sites out of search engines', 'wptb'),
				what: __('On staging, development and local sites, every page tells search engines not to index it or follow its links, regardless of the "Search engine visibility" setting. The live site is never affected.', 'wptb'),
				how: __('Sets the robots meta tag to "noindex, nofollow" (also the one Yoast SEO prints) and sends the same as an X-Robots-Tag HTTP header, only when WordPress\' environment type is not "production".', 'wptb'),
				why: __('Staging copies that end up in Google compete with the real site (duplicate content) and show unfinished pages to customers. The WordPress setting is easily forgotten when copying a site back and forth.', 'wptb'),
			),
			Field::choice(
				'mail',
				[
					'send' => __('Send as usual', 'wptb'),
					'block' => Field::option(__('Don\'t send any emails', 'wptb'), __('Every email is stopped; forms and shops still report success.', 'wptb')),
					'redirect' => Field::option(__('Send all emails to the address below instead', 'wptb'), __('Every email goes only to that address, with the original recipients in the subject.', 'wptb')),
				],
				'send',
				__('Emails on non-live sites', 'wptb'),
				what: __('What happens to emails sent by staging, development and local sites: form notifications, orders, password resets, Wordfence alerts and so on. The live site is never affected.', 'wptb'),
				how: __('"Don\'t send" stops every email before it is sent and reports it as sent. "Send to" replaces all recipients with the address below, removes CC and BCC, and adds the original recipients to the subject, e.g. "[Staging] New order (to: customer@example.com)". Both apply only when WordPress\' environment type is not "production".', 'wptb'),
				why: __('A staging copy contains real customers and form recipients. Without this, testing a form on staging sends real emails to real people.', 'wptb'),
				sideEffects: __('Blocked emails are reported as sent, so forms don\'t show errors. Mail plugins that replace WordPress\' mail function entirely are not affected.', 'wptb'),
			),
			Field::text(
				'mail_redirect_to',
				'',
				__('Redirect emails to', 'wptb'),
				what: __('The address that receives all emails when "Send all emails to the address below" is chosen.', 'wptb'),
				how: __('Used as the only recipient. If it isn\'t a valid email address, no emails are sent at all, never to the original recipients.', 'wptb'),
				why: __('See what the site sends (and check it looks right) without anyone else receiving it.', 'wptb'),
				maxLength: 254,
			),
		];
	}

	public function isAvailable(): bool {
		return true;
	}

	public function environment(): string {
		return $this->environment ?? wp_get_environment_type();
	}

	public function register(Settings $settings): void {
		$live = $this->environment() === 'production';
		$badge = $settings->get('environment', 'badge');

		if ($badge === 'all' || ($badge === 'non_production' && !$live)) {
			add_action('admin_bar_menu', [$this, 'addBadge'], 1);
			add_action('wp_enqueue_scripts', [$this, 'addBadgeStyle']);
			add_action('admin_enqueue_scripts', [$this, 'addBadgeStyle']);
		}

		if ($live) {
			return;
		}

		if ($settings->get('environment', 'noindex') === true) {
			add_filter('wp_robots', static fn (): array => ['noindex' => true, 'nofollow' => true], PHP_INT_MAX);
			add_filter('wp_headers', static function (array $headers): array {
				$headers['X-Robots-Tag'] = 'noindex, nofollow';
				return $headers;
			});
		}

		$mail = $settings->get('environment', 'mail');
		$to = $settings->get('environment', 'mail_redirect_to');
		if ($mail === 'redirect' && is_string($to) && is_email($to) !== false) {
			$this->mailTo = $to;
			add_filter('wp_mail', [$this, 'redirectMail'], PHP_INT_MAX);
			add_action('phpmailer_init', [$this, 'enforceRecipient'], PHP_INT_MAX);
		} elseif ($mail === 'redirect' || $mail === 'block') {
			// before any mail plugin that sends from this filter itself
			add_filter('pre_wp_mail', '__return_true', PHP_INT_MIN);
			// in case another plugin overrides that: no recipients left, PHPMailer refuses to send
			add_action('phpmailer_init', [$this, 'removeRecipients'], PHP_INT_MAX);
		}
	}

	public function addBadge(WP_Admin_Bar $bar): void {
		$bar->add_node(['id' => 'wptb-environment', 'title' => esc_html($this->label())]);
	}

	public function addBadgeStyle(): void {
		if (!is_admin_bar_showing()) {
			return;
		}
		[$background, $color] = self::COLORS[$this->environment()] ?? self::COLORS['production'];
		wp_add_inline_style('admin-bar', sprintf('#wpadminbar #wp-admin-bar-wptb-environment>.ab-item{background:%s;color:%s}', $background, $color));
	}

	/**
	 * @param array<string, mixed> $mail wp_mail() arguments
	 * @return array<string, mixed>
	 */
	public function redirectMail(array $mail): array {
		$to = $mail['to'] ?? [];
		$to = is_array($to) ? implode(', ', array_filter($to, 'is_string')) : (is_string($to) ? $to : '');
		$subject = is_string($mail['subject'] ?? null) ? $mail['subject'] : '';

		$mail['to'] = $this->mailTo;
		/* translators: 1: environment, e.g. "Staging", 2: email subject, 3: original recipients */
		$mail['subject'] = sprintf(__('[%1$s] %2$s (to: %3$s)', 'wptb'), $this->label(), $subject, $to);
		$mail['headers'] = self::withoutCopies($mail['headers'] ?? []);
		return $mail;
	}

	public function removeRecipients(PHPMailer $mailer): void {
		$mailer->clearAllRecipients();
	}

	/**
	 * Last line of defence: recipients added after the wp_mail filter (e.g. by another plugin in phpmailer_init) are dropped.
	 */
	public function enforceRecipient(PHPMailer $mailer): void {
		$mailer->clearAllRecipients();
		$mailer->addAddress($this->mailTo);
	}

	private function label(): string {
		return match ($this->environment()) {
			'staging' => __('Staging', 'wptb'),
			'development' => __('Development', 'wptb'),
			'local' => __('Local', 'wptb'),
			default => __('Live', 'wptb'),
		};
	}

	/**
	 * @return list<string> headers without Cc and Bcc
	 */
	private static function withoutCopies(mixed $headers): array {
		$lines = is_string($headers) ? preg_split('/\r?\n/', $headers) : (is_array($headers) ? $headers : []);
		$lines = array_filter(is_array($lines) ? $lines : [], 'is_string');
		return array_values(array_filter($lines, static fn (string $line): bool => !preg_match('/^\s*b?cc\s*:/i', $line)));
	}
}
