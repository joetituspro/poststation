<?php

namespace PostStation\Services;

class N8nDeploymentService
{
	private AuthService $auth_service;
	private RankimaClient $rankima_client;
	private SupportService $support_service;
	private SettingsService $settings_service;

	public function __construct(
		?AuthService $auth_service = null,
		?RankimaClient $rankima_client = null,
		?SupportService $support_service = null,
		?SettingsService $settings_service = null
	) {
		$this->auth_service = $auth_service ?? AuthService::instance();
		$this->rankima_client = $rankima_client ?? new RankimaClient();
		$this->support_service = $support_service ?? new SupportService();
		$this->settings_service = $settings_service ?? new SettingsService();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deploy_blueprint()
	{
		$license_key = $this->auth_service->get_license_key();
		$site_key = $this->auth_service->get_site_key();
		if ($license_key === '') {
			return new \WP_Error('poststation_missing_license', 'License key is required before deployment.');
		}

		$config = $this->support_service->get_n8n_config(true);
		$base_url = rtrim((string) ($config['base_url'] ?? ''), '/');
		$n8n_api_key = (string) ($config['n8n_api_key'] ?? '');
		if ($base_url === '' || $n8n_api_key === '') {
			return new \WP_Error('poststation_missing_n8n_config', 'n8n base URL and API key are required.');
		}

		$probe = $this->n8n_request($base_url, $n8n_api_key, 'GET', '/api/v1/me');
		if (is_wp_error($probe)) {
			return $probe;
		}

		$workflow_api_key = (string) get_option(SettingsService::WORKFLOW_API_KEY_OPTION, '');
		if ($workflow_api_key === '') {
			$workflow_api_key = wp_generate_password(32, false);
			$this->settings_service->save_workflow_api_key($workflow_api_key);
		}

		$credentials = $this->provision_credentials($base_url, $n8n_api_key, [
			'rapidapi' => (string) ($config['rapidapi_key'] ?? ''),
			'firecrawl' => (string) ($config['firecrawl_key'] ?? ''),
			'openrouter' => (string) ($config['openrouter_key'] ?? ''),
			'poststation' => $workflow_api_key,
		]);
		if (is_wp_error($credentials)) {
			$this->support_service->set_n8n_last_error($credentials->get_error_message());
			return $credentials;
		}

		$payload = [
			'licenseKey' => $license_key,
			'siteKey' => $site_key,
		];
		$download_id = (string) apply_filters('poststation_rankima_blueprint_download_id', '');
		$download_slug = (string) apply_filters('poststation_rankima_blueprint_download_slug', 'poststation-n8n-blueprint');
		if ($download_id !== '') {
			$payload['downloadId'] = sanitize_text_field($download_id);
		} else {
			$payload['downloadSlug'] = sanitize_text_field($download_slug);
		}

		$blueprint_meta = $this->rankima_client->post('/api/downloads/latest', $payload);
		if (is_wp_error($blueprint_meta)) {
			$this->support_service->set_n8n_last_error($blueprint_meta->get_error_message());
			return $blueprint_meta;
		}

		$data = isset($blueprint_meta['data']) && is_array($blueprint_meta['data']) ? $blueprint_meta['data'] : [];
		$latest = isset($blueprint_meta['latest']) && is_array($blueprint_meta['latest']) ? $blueprint_meta['latest'] : [];
		$grant_url = esc_url_raw((string) ($data['url'] ?? ''));
		if ($grant_url === '') {
			$error = new \WP_Error('poststation_missing_blueprint_url', 'Blueprint download URL was not returned.');
			$this->support_service->set_n8n_last_error($error->get_error_message());
			return $error;
		}

		$blueprint_payload = $this->download_blueprint_payload($grant_url);
		if (is_wp_error($blueprint_payload)) {
			$this->support_service->set_n8n_last_error($blueprint_payload->get_error_message());
			return $blueprint_payload;
		}

		$workflow_result = $this->import_workflow($base_url, $n8n_api_key, $blueprint_payload);
		if (is_wp_error($workflow_result)) {
			$this->support_service->set_n8n_last_error($workflow_result->get_error_message());
			return $workflow_result;
		}

		$deploy_data = [
			'workflow' => $workflow_result,
			'credentials' => $credentials,
			'blueprint_version' => sanitize_text_field((string) ($latest['version'] ?? '')),
			'blueprint_hash' => sanitize_text_field((string) ($latest['hash'] ?? '')),
			'blueprint_file_name' => sanitize_text_field((string) ($latest['fileName'] ?? '')),
			'blueprint_release_date' => sanitize_text_field((string) ($latest['releaseDate'] ?? '')),
			'deployed_at' => current_time('timestamp'),
		];
		$this->support_service->save_n8n_last_deploy($deploy_data);
		$this->support_service->set_n8n_autodeploy_enabled(true);

		return $deploy_data;
	}

	/**
	 * @param array<string,string> $keys
	 * @return array<string,mixed>|\WP_Error
	 */
	private function provision_credentials(string $base_url, string $api_key, array $keys)
	{
		$map = [
			'rapidapi' => ['name' => 'PostStation RapidAPI', 'type' => 'httpHeaderAuth', 'header' => 'X-RapidAPI-Key'],
			'firecrawl' => ['name' => 'PostStation Firecrawl', 'type' => 'httpHeaderAuth', 'header' => 'Authorization'],
			'openrouter' => ['name' => 'PostStation OpenRouter', 'type' => 'httpHeaderAuth', 'header' => 'Authorization'],
			'poststation' => ['name' => 'PostStation Workflow', 'type' => 'httpHeaderAuth', 'header' => 'X-API-Key'],
		];

		$created = [];
		foreach ($map as $key => $meta) {
			$value = trim((string) ($keys[$key] ?? ''));
			if ($value === '') {
				continue;
			}

			$data_value = $value;
			if (in_array($key, ['firecrawl', 'openrouter'], true)) {
				$data_value = 'Bearer ' . $value;
			}

			$payload = [
				'name' => $meta['name'],
				'type' => $meta['type'],
				'data' => [
					'name' => $meta['header'],
					'value' => $data_value,
				],
			];

			$result = $this->upsert_credential($base_url, $api_key, $payload);
			if (is_wp_error($result)) {
				return $result;
			}
			$created[$key] = $result;
		}

		return $created;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|\WP_Error
	 */
	private function upsert_credential(string $base_url, string $api_key, array $payload)
	{
		$list = $this->n8n_request($base_url, $api_key, 'GET', '/api/v1/credentials');
		$credential_id = null;
		if (!is_wp_error($list) && isset($list['data']) && is_array($list['data'])) {
			foreach ($list['data'] as $item) {
				if (!is_array($item)) {
					continue;
				}
				if (($item['name'] ?? '') === ($payload['name'] ?? '') && ($item['type'] ?? '') === ($payload['type'] ?? '')) {
					$credential_id = isset($item['id']) ? (int) $item['id'] : null;
					break;
				}
			}
		}

		if ($credential_id) {
			$result = $this->n8n_request($base_url, $api_key, 'PATCH', '/api/v1/credentials/' . $credential_id, $payload);
			if (is_wp_error($result)) {
				// Older n8n versions may not support PATCH.
				$result = $this->n8n_request($base_url, $api_key, 'PUT', '/api/v1/credentials/' . $credential_id, $payload);
			}
			if (is_wp_error($result)) {
				return $result;
			}

			return [
				'id' => $credential_id,
				'name' => $payload['name'],
				'type' => $payload['type'],
			];
		}

		$result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/credentials', $payload);
		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'id' => isset($result['id']) ? (int) $result['id'] : 0,
			'name' => $payload['name'],
			'type' => $payload['type'],
		];
	}

	/**
	 * @param array<string,mixed> $blueprint_payload
	 * @return array<string,mixed>|\WP_Error
	 */
	private function import_workflow(string $base_url, string $api_key, array $blueprint_payload)
	{
		$workflow_data = $blueprint_payload['workflow'] ?? $blueprint_payload;
		if (!is_array($workflow_data)) {
			return new \WP_Error('poststation_invalid_blueprint_payload', 'Blueprint payload is invalid.');
		}

		$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows', $workflow_data);
		if (is_wp_error($create_result)) {
			$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/import', [
				'workflow' => $workflow_data,
			]);
			if (is_wp_error($create_result)) {
				return $create_result;
			}
		}

		return [
			'id' => isset($create_result['id']) ? (string) $create_result['id'] : '',
			'name' => sanitize_text_field((string) ($create_result['name'] ?? ($workflow_data['name'] ?? 'PostStation Workflow'))),
		];
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function download_blueprint_payload(string $url)
	{
		$response = wp_remote_get($url, [
			'timeout' => 40,
			'redirection' => 5,
			'headers' => [
				'Accept' => 'application/json',
			],
		]);
		if (is_wp_error($response)) {
			return new \WP_Error('poststation_blueprint_download_error', $response->get_error_message());
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		if ($status < 200 || $status >= 300) {
			return new \WP_Error('poststation_blueprint_download_failed', 'Failed to download blueprint file.');
		}

		$decoded = json_decode($raw_body, true);
		if (!is_array($decoded)) {
			return new \WP_Error('poststation_blueprint_invalid_json', 'Blueprint file is not valid JSON.');
		}

		return $decoded;
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>|\WP_Error
	 */
	private function n8n_request(string $base_url, string $api_key, string $method, string $path, ?array $body = null)
	{
		$url = rtrim($base_url, '/') . $path;
		$args = [
			'method' => strtoupper($method),
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'X-N8N-API-KEY' => $api_key,
			],
		];
		if ($body !== null) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request($url, $args);
		if (is_wp_error($response)) {
			return new \WP_Error('poststation_n8n_request_error', $response->get_error_message());
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		$data = $raw_body !== '' ? json_decode($raw_body, true) : [];
		if ($status < 200 || $status >= 300) {
			$message = 'n8n request failed.';
			if (is_array($data) && !empty($data['message'])) {
				$message = (string) $data['message'];
			}
			return new \WP_Error('poststation_n8n_request_failed', $message, ['status' => $status]);
		}

		return is_array($data) ? $data : [];
	}
}
