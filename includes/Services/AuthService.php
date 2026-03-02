<?php

namespace PostStation\Services;

class AuthService
{
	public const TRANSIENT_AUTH_CHECK = 'poststation_auth_check';
	public const TRANSIENT_LAST_LICENSE_KEY = 'poststation_last_license_key';
	public const TRANSIENT_UPGRADE_CACHE = 'poststation_rankima_plugin_update_cache';

	public const LICENSE_KEY_OPTION = 'poststation_support_license_key';
	public const SITE_KEY_OPTION = 'poststation_rankima_site_key';
	public const LICENSE_STATUS_OPTION = 'poststation_support_license_status';
	public const LICENSE_CACHE_TRANSIENT = 'poststation_rankima_license_cache';

	private const LICENSE_CONTEXT = 'license';
	private const SITE_KEY_CONTEXT = 'rankima_site_key';
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	private static ?AuthService $instance = null;
	private RankimaClient $rankima_client;

	public static function instance(): AuthService
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct(?RankimaClient $rankima_client = null)
	{
		$this->rankima_client = $rankima_client ?? new RankimaClient();
	}

	public function get_license_key(): string
	{
		$license_data = get_option(self::LICENSE_STATUS_OPTION, []);
		if (is_array($license_data) && !empty($license_data)) {
			return sanitize_text_field((string) ($license_data['license_key'] ?? ''));
		}
		return '';
	}

	public function get_site_key(): string
	{
		return sanitize_text_field((string) get_option(self::SITE_KEY_OPTION, ''));
	}

	public function get_truncated_license_key(): string
	{
		$license_key = $this->get_license_key();
		if ($license_key === '') {
			return '';
		}

		$length = strlen($license_key);
		if ($length <= 10) {
			return substr($license_key, 0, 2) . '...' . substr($license_key, -2);
		}

		return substr($license_key, 0, 6) . '...' . substr($license_key, -4);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_license_status(bool $force_refresh = false): array
	{
		if (!$force_refresh) {
			$cached = get_transient(self::LICENSE_CACHE_TRANSIENT);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$stored = get_option(self::LICENSE_STATUS_OPTION, []);
		if (is_array($stored) && !empty($stored)) {
			if (!$force_refresh) {
				set_transient(self::LICENSE_CACHE_TRANSIENT, $stored, self::CACHE_TTL);
			}
			return $stored;
		}

		return [
			'valid' => false,
			'plan_name' => '',
			'expires_at' => '',
			'manage_url' => '',
			'entitlements' => [],
			'checked_at' => 0,
		];
	}


	private function update_license_data($license_key, $data)
    {
        $existing_license_data = get_option(self::LICENSE_STATUS_OPTION, []);

        $license_data = [
			'license_key' => $license_key,
			'status' => sanitize_text_field((string) ($data['status'] ?? '')),
			'plan_name' => sanitize_text_field((string) ($data['planName'] ?? $data['plan_name'] ?? '')),
			'expires_at' => sanitize_text_field((string) ($data['expiration'] ?? $data['expiredAt'] ?? 'Never')),
			'message' => sanitize_text_field((string) ($data['message'] ?? '')),
			'checked_at' => current_time('timestamp'),
        ];

        update_option(self::LICENSE_STATUS_OPTION, $license_data);
        update_option(self::SITE_KEY_OPTION, $data['siteKey'] ?? get_option(self::SITE_KEY_OPTION));
    }

	/**
	 * Handle support page license actions (activate/deactivate/refresh) and return
	 * a payload suitable for updating the license UI.
	 *
	 * @param string $action_type 'activate'|'deactivate'|'refresh'
	 * @param string $raw_license_key Optional license key from the request
	 * @return array<string,mixed>|\WP_Error
	 */
	public function handle_support_license_action(string $action_type, string $raw_license_key = '')
	{
		if ($action_type === 'activate') {
			$license_key = trim((string) $raw_license_key);

			if (empty($license_key)) {
				return new \WP_Error('poststation_missing_license', 'No license key provided.');
			}
	
			$response = $this->rankima_client->post('/api/license/activate', [
				'license_key' => $license_key,
			]);

			$data = $this->extract_payload($response);

			if ($data['status'] !== 'active') {
				return $this->handle_failed_activation($license_key, $data['status'], $data['message']);
			}

			$this->update_license_data($license_key, $data);
            $this->clear_transients();

			return [
				'status' => $data['status'],
				'message' => sanitize_text_field((string) ($data['message'] ?? '')),
			];
		}

		if ($action_type === 'deactivate') {

			$license_key = $this->get_license_key();
			if (empty($license_key)) {
				return new \WP_Error('poststation_missing_license', 'No license key provided.');
			}

			$response = $this->rankima_client->post('/api/license/deactivate', [
				'license_key' => $license_key,
			]);
			if (is_wp_error($response)) {
				return $response;
			}
			$data = $this->extract_payload($response);

			if ($data['status'] !== 'active') {
				$this->dash($license_key);
				return [
					'status' => $data['status'],
					'message' => sanitize_text_field((string) ($data['message'] ?? '')),
				];
			} else {
				return new \WP_Error('poststation_license_deactivation_failed', 'Host Response: ' . $data['message']);
			}
		}

		if ($action_type === 'refresh') {
			$response = $this->verify();

			if (is_wp_error($response)) {
				return $response;
			}

			if ($response['status'] === 'connection_error') {
				return new \WP_Error('poststation_license_refresh_failed', 'Host Response: ' . $response['message']);
			}

			if ($response['status'] === 'active') {
				$this->update_license_data($this->get_license_key(), $response);
				$this->clear_transients();
				$message = 'Your license connection has been refreshed successfully and your license status is active!';
				return [
					'status' => $response['status'],
					'message' => $message,
				];
			} else {
				$this->dash();
				return [
					'status' => $response['status'],
					'message' => sanitize_text_field((string) ('Host Response: ' . $response['message'] ?? '')),
				];
			}
		}

		return new \WP_Error('poststation_invalid_license_action', 'Invalid license action_type.');
	}

	private function handle_failed_activation($license_key, $status, $message)
    {
        delete_transient(self::TRANSIENT_AUTH_CHECK);
        $this->dash($license_key);
        return new \WP_Error('poststation_license_activation_failed', 'Host Response: ' . $message);
    }


	/**
	 * @return array<string,mixed>
	 */
	public function verify(string $license_key = ''): array
	{
		if ($license_key === '') {
			$license_key = $this->get_license_key();
		}

		if (empty($license_key)) {
			return [
				'status' => 'inactive',
				'message' => 'No license key provided.',
			];
		}

		$response = $this->rankima_client->post('/api/license/refresh', [
			'license_key' => $license_key,
		]);
		if (is_wp_error($response)) {
			return [
				'status' => 'connection_error',
				'message' => $response->get_error_message(),
			];
		}

		$data = $this->extract_payload($response);

		if ($data['status'] === 'connection_error') {
			return [
				'status' => 'connection_error',
				'message' => $data['message'],
			];
		}


		if (isset($data['success']) && $data['success']) {
			if ($data['status'] === 'active') {
				return [
					'status' => 'active',
					'message' => sanitize_text_field((string) ($data['message'] ?? '')),
				];
			} else {
				return [
					'status' => 'inactive',
					'message' => sanitize_text_field((string) ($data['message'] ?? '')),
				];
			}
			$this->update_license_data($license_key, $data);
		} else {
			return [
				'status' => 'error',
				'message' => sanitize_text_field((string) ($data['message'] ?? '')),
			];
		}
	}
	public function get_status(): string
	{
		$cached = get_transient(self::TRANSIENT_AUTH_CHECK);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$license_key = $this->get_license_key();
		if ($license_key === '') {
			set_transient(self::TRANSIENT_AUTH_CHECK, 'inactive', DAY_IN_SECONDS);
			return 'inactive';
		}

		$stored = $this->get_license_status();
		$status = (string) ($stored['status'] ?? ($stored['valid'] ? 'active' : 'inactive'));
		if ($status === '') {
			$status = 'inactive';
		}

		set_transient(self::TRANSIENT_AUTH_CHECK, $status, DAY_IN_SECONDS);
		return $status;
	}


	public function clear_transients(): void
	{
		delete_transient(self::TRANSIENT_AUTH_CHECK);
		delete_transient(self::TRANSIENT_UPGRADE_CACHE);
		delete_transient(self::LICENSE_CACHE_TRANSIENT);
	}

	public function dash(string $license_key = ''): void
	{
		delete_option(self::LICENSE_KEY_OPTION);
		delete_option(self::LICENSE_STATUS_OPTION);
		$this->clear_transients();
		if ($license_key !== '') {
			set_transient(self::TRANSIENT_LAST_LICENSE_KEY, $license_key, DAY_IN_SECONDS);
		}
	}
	
	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function extract_payload(array $response): array
	{
		if (isset($response['data']) && is_array($response['data'])) {
			$response['data']['success'] = $response['status'] === 'success';
			$response['data']['message'] = $response['message'];
			return $response['data'];
		}
		return $response;
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function remote_manage_license(array $params = []): bool
	{
		$action = isset($params['action']) ? sanitize_text_field((string) $params['action']) : '';
		$license_key = isset($params['license_key']) ? sanitize_text_field((string) $params['license_key']) : '';
		if ($action === 'refresh') {
			if ($license_key !== '') {
				$this->save_license_key($license_key);
			}
			$this->verify($license_key);
			return true;
		}
		if ($action === 'deactivate') {
			$this->dash($license_key);
			return true;
		}

		return false;
	}

}
