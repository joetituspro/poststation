<?php

namespace PostStation\Services;

class UpdateService
{
	public const UPDATE_CACHE_TRANSIENT = 'poststation_rankima_update_cache';
	public const PLUGIN_UPDATE_CACHE_TRANSIENT = 'poststation_rankima_plugin_update_cache';

	private const CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;

	private SupportService $support_service;
	private AuthService $auth_service;
	private RankimaClient $rankima_client;
	private N8nDeploymentService $n8n_deployment_service;

	public function __construct(
		?SupportService $support_service = null,
		?AuthService $auth_service = null,
		?RankimaClient $rankima_client = null,
		?N8nDeploymentService $n8n_deployment_service = null
	) {
		$this->support_service = $support_service ?? new SupportService();
		$this->auth_service = $auth_service ?? AuthService::instance();
		$this->rankima_client = $rankima_client ?? new RankimaClient();
		$this->n8n_deployment_service = $n8n_deployment_service ?? new N8nDeploymentService();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_for_updates(bool $force = false)
	{
		if (!$force) {
			$cached = get_transient(self::UPDATE_CACHE_TRANSIENT);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$license_key = $this->auth_service->get_license_key();
		if ($license_key === '') {
			$empty = $this->build_empty_result('inactive');
			$this->persist_states($empty);
			return $empty;
		}

		$response = $this->rankima_client->post('/api/license/check-update', [
			'products' => ['n8n-workflow', 'poststation-plugin'],
		]);
		if (is_wp_error($response)) {
			return $response;
		}
		if (!is_array($response)) {
			return new \WP_Error('poststation_update_check_invalid_response', 'Invalid update response from Rankima.');
		}

		$status = sanitize_text_field((string) ($response['status'] ?? ''));
		$message = sanitize_text_field((string) ($response['message'] ?? ''));
		$data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
		if ($status !== 'success' && $status !== 'active') {
			$fallback_status = sanitize_text_field((string) ($data['status'] ?? 'inactive'));
			$empty = $this->build_empty_result($fallback_status);
			$empty['message'] = $message !== '' ? $message : 'Unable to check updates.';
			$this->persist_states($empty);
			return $empty;
		}

		$downloads = isset($data['downloads']) && is_array($data['downloads']) ? $data['downloads'] : [];
		$plugin_download = isset($downloads['poststation-plugin']) && is_array($downloads['poststation-plugin']) ? $downloads['poststation-plugin'] : [];
		$workflow_download = isset($downloads['n8n-workflow']) && is_array($downloads['n8n-workflow']) ? $downloads['n8n-workflow'] : [];

		$plugin = $this->build_plugin_update_data($plugin_download);
		$workflow = $this->build_workflow_update_data($workflow_download);

		$result = [
			'checked_at' => current_time('timestamp'),
			'message' => $message,
			'license' => [
				'status' => sanitize_text_field((string) ($data['status'] ?? 'inactive')),
				'plan_code' => sanitize_text_field((string) ($data['planCode'] ?? '')),
				'plan_name' => sanitize_text_field((string) ($data['planName'] ?? '')),
				'expiration' => sanitize_text_field((string) ($data['expiration'] ?? '')),
				'domain' => sanitize_text_field((string) ($data['domain'] ?? '')),
				'domain_active' => !empty($data['domainActive']),
			],
			'plugin' => $plugin,
			'workflow' => $workflow,
		];

		$this->persist_states($result);
		return $result;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_plugin_update_info(bool $force = false)
	{
		if (!$force) {
			$cached = get_transient(self::PLUGIN_UPDATE_CACHE_TRANSIENT);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$result = $this->check_for_updates($force);
		if (is_wp_error($result)) {
			return $result;
		}

		$cached = get_transient(self::PLUGIN_UPDATE_CACHE_TRANSIENT);
		if (is_array($cached)) {
			return $cached;
		}

		$plugin = isset($result['plugin']) && is_array($result['plugin']) ? $result['plugin'] : [];
		return $this->build_plugin_cache_state($plugin);
	}

	/**
	 * @param array<string,mixed> $plugin_download
	 * @return array<string,mixed>
	 */
	private function build_plugin_update_data(array $plugin_download): array
	{
		$current = POSTSTATION_VERSION;
		$latest = sanitize_text_field((string) ($plugin_download['version'] ?? ''));
		$download_url = esc_url_raw((string) ($plugin_download['download_url'] ?? ''));

		return [
			'name' => sanitize_text_field((string) ($plugin_download['name'] ?? 'Post Station')),
			'update_available' => $this->is_update_available($current, $latest),
			'host_version' => $latest,
			'latest_version' => $latest,
			'current_version' => sanitize_text_field((string) $current),
			'changelog' => wp_kses_post((string) ($plugin_download['changelog'] ?? '')),
			'download_url' => $download_url,
		];
	}

	/**
	 * @param array<string,mixed> $workflow_download
	 * @return array<string,mixed>
	 */
	private function build_workflow_update_data(array $workflow_download): array
	{
		$current_version = '';
		$current_name = '';
		$current_workflow_id = '';
		$current_error = '';
		$current_release = $this->n8n_deployment_service->get_current_workflow_release();
		if (is_wp_error($current_release)) {
			$current_error = $current_release->get_error_message();
		} elseif (is_array($current_release)) {
			$current_version = sanitize_text_field((string) ($current_release['version'] ?? ''));
			$current_name = sanitize_text_field((string) ($current_release['name'] ?? ''));
			$current_workflow_id = sanitize_text_field((string) ($current_release['workflow_id'] ?? ''));
		}

		$latest = sanitize_text_field((string) ($workflow_download['version'] ?? ''));
		$download_url = esc_url_raw((string) ($workflow_download['download_url'] ?? ''));
		$update_available = $this->is_update_available($current_version, $latest);

		return [
			'name' => sanitize_text_field((string) ($workflow_download['name'] ?? 'N8N Workflow')),
			'update_available' => $update_available,
			'host_version' => $latest,
			'latest_version' => $latest,
			'current_version' => $current_version,
			'current_name' => $current_name,
			'workflow_id' => $current_workflow_id,
			'changelog' => wp_kses_post((string) ($workflow_download['changelog'] ?? '')),
			'download_url' => $download_url,
			'current_lookup_error' => sanitize_text_field($current_error),
		];
	}

	private function is_update_available(string $current_version, string $latest_version): bool
	{
		$current = trim($current_version);
		$latest = trim($latest_version);
		if ($latest === '') {
			return false;
		}
		if ($current === '') {
			return true;
		}
		return version_compare($latest, $current, '>');
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function persist_states(array $result): void
	{
		$workflow = isset($result['workflow']) && is_array($result['workflow']) ? $result['workflow'] : [];
		$plugin = isset($result['plugin']) && is_array($result['plugin']) ? $result['plugin'] : [];

		$blueprint_state = [
			'update_available' => !empty($workflow['update_available']),
			'host_version' => sanitize_text_field((string) ($workflow['host_version'] ?? '')),
			'latest_version' => sanitize_text_field((string) ($workflow['latest_version'] ?? '')),
			'current_version' => sanitize_text_field((string) ($workflow['current_version'] ?? '')),
			'download_url' => esc_url_raw((string) ($workflow['download_url'] ?? '')),
			'current_name' => sanitize_text_field((string) ($workflow['current_name'] ?? '')),
			'workflow_id' => sanitize_text_field((string) ($workflow['workflow_id'] ?? '')),
			'changelog' => wp_kses_post((string) ($workflow['changelog'] ?? '')),
			'checked_at' => (int) ($result['checked_at'] ?? current_time('timestamp')),
		];
		$this->support_service->set_blueprint_update_state($blueprint_state);

		$plugin_state = $this->build_plugin_cache_state($plugin);
		set_transient(self::PLUGIN_UPDATE_CACHE_TRANSIENT, $plugin_state, self::CHECK_INTERVAL);
		update_option(SupportService::PLUGIN_UPDATE_LAST_CHECK_OPTION, current_time('timestamp'));
		set_transient(self::UPDATE_CACHE_TRANSIENT, $result, self::CHECK_INTERVAL);
	}

	/**
	 * @param array<string,mixed> $plugin
	 * @return array<string,mixed>
	 */
	private function build_plugin_cache_state(array $plugin): array
	{
		return [
			'new_version' => sanitize_text_field((string) ($plugin['latest_version'] ?? '')),
			'package' => esc_url_raw((string) ($plugin['download_url'] ?? '')),
			'url' => (string) apply_filters('poststation_rankima_plugin_homepage', 'https://rankima.com/poststation'),
			'tested' => '',
			'requires_php' => '7.4',
			'requires' => '5.8',
			'sections' => [
				'description' => sanitize_text_field((string) ($plugin['name'] ?? 'Post Station by Rankima')),
				'changelog' => wp_kses_post((string) ($plugin['changelog'] ?? '')),
			],
			'banners' => [],
			'release_date' => '',
			'file_name' => '',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_empty_result(string $license_status): array
	{
		$status = sanitize_text_field($license_status !== '' ? $license_status : 'inactive');
		return [
			'checked_at' => current_time('timestamp'),
			'message' => '',
			'license' => [
				'status' => $status,
				'plan_code' => '',
				'plan_name' => '',
				'expiration' => '',
				'domain' => '',
				'domain_active' => false,
			],
			'plugin' => [
				'name' => 'Post Station',
				'update_available' => false,
				'host_version' => '',
				'latest_version' => '',
				'current_version' => sanitize_text_field((string) POSTSTATION_VERSION),
				'changelog' => '',
				'download_url' => '',
			],
			'workflow' => [
				'name' => 'N8N Workflow',
				'update_available' => false,
				'host_version' => '',
				'latest_version' => '',
				'current_version' => '',
				'current_name' => '',
				'workflow_id' => '',
				'changelog' => '',
				'download_url' => '',
				'current_lookup_error' => '',
			],
		];
	}
}
