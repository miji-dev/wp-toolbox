<?php

declare(strict_types=1);

namespace Miji\Toolbox\Admin;

use Miji\Toolbox\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoint of the settings page: wptb/v1/settings (administrators only).
 *
 * GET returns the current state, POST validates and saves (partial) values and returns the new state.
 * Validation is the same as everywhere else (Settings::update), locked values can't be changed.
 */
final class SettingsController {
	public const NAMESPACE = 'wptb/v1';
	public const ROUTE = '/settings';

	public function __construct(private readonly Settings $settings) {
	}

	public function register(): void {
		add_action('rest_api_init', [$this, 'registerRoutes']);
	}

	public function registerRoutes(): void {
		register_rest_route(self::NAMESPACE, self::ROUTE, [
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => [$this, 'read'],
				'permission_callback' => [$this, 'canManage'],
			],
			[
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => [$this, 'save'],
				'permission_callback' => [$this, 'canManage'],
				'args' => [
					'values' => ['type' => 'object', 'required' => true],
				],
			],
		]);
	}

	public function canManage(): bool {
		return current_user_can('manage_options');
	}

	public function read(): WP_REST_Response {
		return new WP_REST_Response($this->state());
	}

	public function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$values = $request->get_param('values');
		$result = $this->settings->update(is_array($values) ? $values : []);
		if ($result instanceof WP_Error) {
			$result->add_data(['status' => 400]);
			return $result;
		}
		return $this->read();
	}

	/**
	 * @return array{values: array<string, array<string, mixed>>, locked: array<string, list<string>>, invalidOverrides: list<string>}
	 */
	public function state(): array {
		return [
			'values' => $this->settings->all(),
			'locked' => $this->settings->locked(),
			'invalidOverrides' => $this->settings->invalidOverrides(),
		];
	}
}
