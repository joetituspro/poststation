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
		if ($license_key === '') {
			return new \WP_Error('poststation_missing_license', 'License key is required before deployment.');
		}

		$config = $this->support_service->get_n8n_config(true);

		$base_url = rtrim((string) ($config['base_url'] ?? ''), '/');
		$n8n_api_key = (string) ($config['n8n_api_key'] ?? '');
		if ($base_url === '' || $n8n_api_key === '') {
			return new \WP_Error('poststation_missing_n8n_config', 'n8n base URL and API key are required.');
		}

		// $probe = $this->n8n_request($base_url, $n8n_api_key, 'GET', '/api/v1');
		// if (is_wp_error($probe)) {
		// 	return $probe;
		// }

		// Generate workflow API key if not exists
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

		$blueprint_meta = $this->rankima_client->request('/api/downloads/latest', ['product_id' => 'n8n-workflow'], 'POST');
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

		$workflow_result = $this->import_workflow($base_url, $n8n_api_key, $blueprint_payload, $credentials);
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
			'rapidapi' => ['rim_name' => 'RIM-RAPIDAPI', 'type' => 'httpHeaderAuth', 'header' => 'X-RapidAPI-Key'],
			'firecrawl' => ['rim_name' => 'RIM-FIRECRAWL', 'type' => 'httpHeaderAuth', 'header' => 'Authorization'],
			'openrouter' => ['rim_name' => 'RIM-OPENROUTER', 'type' => 'openRouterApi', 'header' => ''],
			'poststation' => ['rim_name' => 'RIM-POSTSTATION', 'type' => 'httpHeaderAuth', 'header' => 'X-API-Key'],
		];

		$list = $this->list_credentials($base_url, $api_key);
		if (is_wp_error($list)) {
			return $this->n8n_405_error($list);
		}

		$existing_by_name = [];
		$existing_by_name_and_type = [];
		if (isset($list['data']) && is_array($list['data'])) {
			foreach ($list['data'] as $item) {
				if (!is_array($item) || !isset($item['name'])) {
					continue;
				}
				$name = trim((string) $item['name']);
				$type = isset($item['type']) ? (string) $item['type'] : '';
				if ($name === '') {
					continue;
				}
				$existing_by_name[$name][] = $item;
				if ($type !== '') {
					$existing_by_name_and_type[strtolower($name) . '|' . $type] = $item;
				}
			}
		}

		$created = [];
		foreach ($map as $key => $meta) {
			$value = trim((string) ($keys[$key] ?? ''));
			if ($value === '') {
				continue;
			}

			$rim_name = $meta['rim_name'];
			$lookup_key = strtolower($rim_name) . '|' . (string) $meta['type'];
			if (isset($existing_by_name_and_type[$lookup_key]) && is_array($existing_by_name_and_type[$lookup_key])) {
				$existing = $existing_by_name_and_type[$lookup_key];
				$existing_id = isset($existing['id']) ? (string) $existing['id'] : '';
				if ($existing_id !== '') {
					$created[$key] = [
						'id' => $existing_id,
						'name' => $rim_name,
						'type' => (string) $meta['type'],
					];
					continue;
				}
			}

			$payload = [
				'name' => $rim_name,
				'type' => $meta['type'],
			];
			if ($meta['type'] === 'openRouterApi') {
				$payload['data'] = [
					'apiKey' => $value,
				];
			} else {
				$data_value = $value;
				if (in_array($key, ['firecrawl'], true)) {
					$data_value = 'Bearer ' . $value;
				}
				$payload['data'] = [
					'name' => $meta['header'],
					'value' => $data_value,
				];
			}

			$result = $this->create_credential($base_url, $api_key, $payload);
			if (is_wp_error($result)) {
				// Fallback for instances where credential names must be unique.
				// If a same-name credential exists with a different type, try updating it.
				if (isset($existing_by_name[$rim_name]) && is_array($existing_by_name[$rim_name])) {
					$same_name_items = $existing_by_name[$rim_name];
					$existing = is_array($same_name_items) ? reset($same_name_items) : false;
					if (is_array($existing)) {
						$existing_id = isset($existing['id']) ? (string) $existing['id'] : '';
						if ($existing_id !== '') {
							$update_result = $this->n8n_request($base_url, $api_key, 'PATCH', '/api/v1/credentials/' . $existing_id, $payload);
							if (is_wp_error($update_result)) {
								$update_result = $this->n8n_request($base_url, $api_key, 'PUT', '/api/v1/credentials/' . $existing_id, $payload);
							}
							if (!is_wp_error($update_result)) {
								$created[$key] = [
									'id' => $existing_id,
									'name' => $rim_name,
									'type' => (string) $meta['type'],
								];
								continue;
							}
						}
					}
				}
				return $result;
			}
			$created[$key] = $result;
		}

		// Re-fetch credentials after create to ensure we use authoritative current IDs.
		$latest = $this->list_credentials($base_url, $api_key);
		if (!is_wp_error($latest) && isset($latest['data']) && is_array($latest['data'])) {
			$latest_by_name = [];
			$latest_by_name_and_type = [];
			foreach ($latest['data'] as $item) {
				if (!is_array($item) || !isset($item['name'])) {
					continue;
				}
				$name = trim((string) $item['name']);
				$type = isset($item['type']) ? (string) $item['type'] : '';
				if ($name === '') {
					continue;
				}
				$latest_by_name[$name][] = $item;
				if ($type !== '') {
					$latest_by_name_and_type[strtolower($name) . '|' . $type] = $item;
				}
			}

			foreach ($created as $key => $meta) {
				$name = isset($meta['name']) ? (string) $meta['name'] : '';
				$type = isset($meta['type']) ? (string) $meta['type'] : '';
				if ($name === '') {
					continue;
				}
				$item = null;
				if ($type !== '') {
					$typed_key = strtolower($name) . '|' . $type;
					$item = $latest_by_name_and_type[$typed_key] ?? null;
				}
				if (!is_array($item) && isset($latest_by_name[$name]) && is_array($latest_by_name[$name]) && count($latest_by_name[$name]) === 1) {
					$item = $latest_by_name[$name][0];
				}
				if (!is_array($item)) {
					continue;
				}
				$created[$key]['id'] = isset($item['id']) ? (string) $item['id'] : '';
				$created[$key]['type'] = isset($item['type']) ? (string) $item['type'] : (string) ($meta['type'] ?? '');
			}
		}

		return $created;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|\WP_Error
	 */
	private function upsert_credential(string $base_url, string $api_key, array $payload)
	{
		// Kept for backwards compatibility; may update or create a credential.
		$list = $this->n8n_request($base_url, $api_key, 'GET', '/api/v1/credentials');
		$credential_id = null;
		if (!is_wp_error($list) && isset($list['data']) && is_array($list['data'])) {
			foreach ($list['data'] as $item) {
				if (!is_array($item)) {
					continue;
				}
				if (($item['name'] ?? '') === ($payload['name'] ?? '') && ($item['type'] ?? '') === ($payload['type'] ?? '')) {
					$credential_id = isset($item['id']) ? (string) $item['id'] : null;
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
				'id' => (string) $credential_id,
				'name' => $payload['name'],
				'type' => $payload['type'],
			];
		}

		$result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/credentials', $payload);
		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'id' => isset($result['id']) ? (string) $result['id'] : '',
			'name' => $payload['name'],
			'type' => $payload['type'],
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|\WP_Error
	 */
	private function create_credential(string $base_url, string $api_key, array $payload)
	{
		$result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/credentials', $payload);
		if (is_wp_error($result) && $this->n8n_request_status($result) === 405) {
			$result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v2/credentials', $payload);
		}
		if (is_wp_error($result)) {
			return $this->n8n_405_error($result);
		}

		return [
			'id' => isset($result['id']) ? (string) $result['id'] : '',
			'name' => $payload['name'],
			'type' => $payload['type'],
		];
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function list_credentials(string $base_url, string $api_key)
	{
		$list = $this->n8n_request($base_url, $api_key, 'GET', '/api/v1/credentials');
		if (is_wp_error($list) && $this->n8n_request_status($list) === 405) {
			$list = $this->n8n_request($base_url, $api_key, 'GET', '/api/v2/credentials');
		}
		return $list;
	}

	/**
	 * @return int|null HTTP status from WP_Error from n8n_request, or null
	 */
	private function n8n_request_status(\WP_Error $err): ?int
	{
		$data = $err->get_error_data('poststation_n8n_request_failed');
		return is_array($data) && isset($data['status']) ? (int) $data['status'] : null;
	}

	/**
	 * Return a clearer WP_Error when n8n returns 405 (POST method not allowed).
	 *
	 * @see https://docs.n8n.io/api/
	 * @see https://docs.n8n.io/hosting/securing/disable-public-api/
	 */
	private function n8n_405_error(\WP_Error $err): \WP_Error
	{
		if ($this->n8n_request_status($err) === 405) {
			return new \WP_Error(
				$err->get_error_code(),
				'POST method not allowed. Ensure the n8n public API is enabled (N8N_PUBLIC_API_DISABLED must not be true), the base URL is the instance root (e.g. https://your-n8n.example.com), and that your n8n version supports the API. See: https://docs.n8n.io/api/',
				$err->get_error_data()
			);
		}
		return $err;
	}

	/**
	 * Build request body for n8n workflow create. API allows only specific properties;
	 * exported blueprints include id, active, etc. which must be omitted.
	 *
	 * @see https://docs.n8n.io/api/api-reference/#tag/Workflow
	 * @param array<string,mixed> $workflow_data
	 * @return array<string,mixed>
	 */
	private function workflow_create_body(array $workflow_data, array $provisioned_credentials = []): array
	{
		// Keep create payload strict for compatibility across n8n versions:
		// older/newer schemas may reject optional top-level properties.
		$allowed = ['name', 'nodes', 'connections', 'settings'];
		$body = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $workflow_data)) {
				$body[$key] = $workflow_data[$key];
			}
		}

		// Required fields for POST /workflows.
		if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
			$body['name'] = 'PostStation Workflow';
		}
		if (!isset($body['nodes']) || !is_array($body['nodes'])) {
			$body['nodes'] = [];
		}
		if (!isset($body['connections']) || !is_array($body['connections'])) {
			$body['connections'] = [];
		}
		$body['nodes'] = $this->normalize_workflow_nodes($body['nodes'], $provisioned_credentials);
		$body = $this->normalize_workflow_settings($body);

		return $body;
	}

	/**
	 * @param array<int,mixed> $nodes
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_workflow_nodes(array $nodes, array $provisioned_credentials = []): array
	{
		$credentials_by_name = [];
		$credentials_by_name_and_type = [];
		$credentials_by_name_and_type_lower = [];
		foreach ($provisioned_credentials as $credential) {
			if (!is_array($credential) || !isset($credential['name'])) {
				continue;
			}
			$name = trim((string) $credential['name']);
			$id = trim((string) ($credential['id'] ?? ''));
			$type = isset($credential['type']) ? (string) $credential['type'] : '';
			if ($name === '' || $id === '') {
				continue;
			}
			$entry = [
				'id' => $id,
				'type' => $type,
			];
			$credentials_by_name[$name][] = $entry;
			if ($type !== '') {
				$key = $name . '|' . $type;
				$key_lower = strtolower($name) . '|' . $type;
				$credentials_by_name_and_type[$key] = $id;
				$credentials_by_name_and_type_lower[$key_lower] = $id;
			}
		}

		$normalized = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}

			// n8n requires node.parameters to be an object; in PHP JSON this is an associative array.
			if (!isset($node['parameters']) || !is_array($node['parameters'])) {
				$node['parameters'] = (object) [];
			} elseif (array_is_list($node['parameters'])) {
				// Empty list ("[]") or numeric lists are invalid for `parameters`; enforce JSON object.
				$node['parameters'] = (object) $node['parameters'];
			}

			if (isset($node['credentials']) && is_array($node['credentials']) && ($credentials_by_name !== [] || $credentials_by_name_and_type !== [])) {
				foreach ($node['credentials'] as $credential_type => $credential_ref) {
					if (!is_array($credential_ref)) {
						continue;
					}
					$name = isset($credential_ref['name']) ? trim((string) $credential_ref['name']) : '';
					if ($name === '') {
						continue;
					}
					$resolved_id = '';
					$typed_key = $name . '|' . (string) $credential_type;
					$typed_key_lower = strtolower($name) . '|' . (string) $credential_type;
					if (isset($credentials_by_name_and_type[$typed_key])) {
						$resolved_id = (string) $credentials_by_name_and_type[$typed_key];
					} elseif (isset($credentials_by_name_and_type_lower[$typed_key_lower])) {
						$resolved_id = (string) $credentials_by_name_and_type_lower[$typed_key_lower];
					} else {
						$same_name = $credentials_by_name[$name] ?? [];
						if (is_array($same_name) && count($same_name) === 1 && is_array($same_name[0]) && !empty($same_name[0]['id'])) {
							$resolved_id = (string) $same_name[0]['id'];
						}
					}
					if ($resolved_id === '') {
						continue;
					}
					$node['credentials'][$credential_type]['id'] = $resolved_id;
					$node['credentials'][$credential_type]['name'] = $name;
				}
			}

			$normalized[] = $node;
		}

		return $normalized;
	}

	/**
	 * n8n workflow create schema is strict for `settings`.
	 * Keep only well-supported keys and drop the field if nothing remains.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function normalize_workflow_settings(array $body): array
	{
		if (!isset($body['settings'])) {
			return $body;
		}

		if (!is_array($body['settings'])) {
			unset($body['settings']);
			return $body;
		}

		$allowed_settings = [
			'executionOrder',
			'saveDataErrorExecution',
			'saveDataSuccessExecution',
			'saveExecutionProgress',
			'saveManualExecutions',
			'timezone',
			'errorWorkflow',
			'executionTimeout',
		];

		$normalized_settings = [];
		foreach ($allowed_settings as $key) {
			if (array_key_exists($key, $body['settings'])) {
				$normalized_settings[$key] = $body['settings'][$key];
			}
		}

		if ($normalized_settings === []) {
			unset($body['settings']);
			return $body;
		}

		$body['settings'] = $normalized_settings;
		return $body;
	}

	private function is_n8n_additional_properties_error(\WP_Error $err): bool
	{
		$message = $err->get_error_message();
		return stripos($message, 'must NOT have additional properties') !== false;
	}

	/**
	 * @param array<string,mixed> $blueprint_payload
	 * @return array<string,mixed>|null
	 */
	private function extract_workflow_data(array $blueprint_payload): ?array
	{
		$candidates = [$blueprint_payload];
		if (isset($blueprint_payload['workflow']) && is_array($blueprint_payload['workflow'])) {
			$candidates[] = $blueprint_payload['workflow'];
		}
		if (isset($blueprint_payload['data']) && is_array($blueprint_payload['data'])) {
			$candidates[] = $blueprint_payload['data'];
			if (isset($blueprint_payload['data']['workflow']) && is_array($blueprint_payload['data']['workflow'])) {
				$candidates[] = $blueprint_payload['data']['workflow'];
			}
		}

		foreach ($candidates as $candidate) {
			if (!is_array($candidate)) {
				continue;
			}
			if (isset($candidate['nodes']) && is_array($candidate['nodes'])) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $blueprint_payload
	 * @return array<string,mixed>|\WP_Error
	 */
	private function import_workflow(string $base_url, string $api_key, array $blueprint_payload, array $provisioned_credentials = [])
	{
		$workflow_data = $this->extract_workflow_data($blueprint_payload);
		if (!is_array($workflow_data)) {
			return new \WP_Error('poststation_invalid_blueprint_payload', 'Blueprint payload is invalid.');
		}

		error_log('Provisioned Credentials: ' . print_r($provisioned_credentials, true));
		error_log('BEFORE Body: ' . print_r($workflow_data, true));

		$create_body = $this->workflow_create_body($workflow_data, $provisioned_credentials);
		error_log('AFTER Body: ' . print_r($create_body, true));
		$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows', $create_body);
		if (is_wp_error($create_result) && $this->is_n8n_additional_properties_error($create_result)) {
			$create_body = [
				'name' => isset($create_body['name']) ? (string) $create_body['name'] : 'PostStation Workflow',
				'nodes' => isset($create_body['nodes']) && is_array($create_body['nodes']) ? $create_body['nodes'] : [],
				'connections' => isset($create_body['connections']) && is_array($create_body['connections']) ? $create_body['connections'] : [],
			];
			$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows', $create_body);
			if (is_wp_error($create_result) && $this->n8n_request_status($create_result) === 405) {
				$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v2/workflows', $create_body);
			}
		}
		if (is_wp_error($create_result)) {
			$status = $this->n8n_request_status($create_result);
			// Legacy import endpoint fallback is only useful when create endpoint is unavailable.
			if ($status !== 404 && $status !== 405) {
				return $create_result;
			}

			$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/import', [
				'workflow' => $create_body,
			]);
			if (is_wp_error($create_result) && $this->n8n_request_status($create_result) === 405) {
				$create_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v2/workflows/import', [
					'workflow' => $create_body,
				]);
			}
			if (is_wp_error($create_result)) {
				return $this->n8n_405_error($create_result);
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
