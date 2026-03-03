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

		$blueprint_meta = $this->rankima_client->request('/api/downloads/latest', ['product_id' => 'n8n-workflow'], 'POST');
		if (is_wp_error($blueprint_meta)) {
			$this->support_service->set_n8n_last_error($blueprint_meta->get_error_message());
			return $blueprint_meta;
		}

		$release = $this->extract_release_metadata($blueprint_meta);
		$blueprint_version = isset($release['version']) ? sanitize_text_field((string) $release['version']) : '';
		$blueprint_changelog = isset($release['changelog']) ? (string) $release['changelog'] : '';

		$resolved_workflow = $this->resolve_existing_workflow(
			$base_url,
			$n8n_api_key,
			$this->get_saved_workflow_id(),
			'RANKIMA'
		);
		if (is_wp_error($resolved_workflow)) {
			$this->support_service->set_n8n_last_error($resolved_workflow->get_error_message());
			return $resolved_workflow;
		}

		$current_remote_version = '';
		$resolved_id = isset($resolved_workflow['id']) ? trim((string) $resolved_workflow['id']) : '';
		if ($resolved_id !== '' && $blueprint_version !== '') {
			$current_remote_version = $this->get_current_workflow_version($resolved_workflow);
			if ($current_remote_version !== '' && version_compare($current_remote_version, $blueprint_version, '=')) {
				$up_to_date = new \WP_Error(
					'poststation_workflow_up_to_date',
					sprintf('Workflow is already up to date (version %s).', $blueprint_version),
					[
						'workflow_id' => $resolved_id,
						'version' => $blueprint_version,
					]
				);
				$this->support_service->set_n8n_last_error($up_to_date->get_error_message());
				return $up_to_date;
			}
		}

		$data = isset($blueprint_meta['data']) && is_array($blueprint_meta['data']) ? $blueprint_meta['data'] : [];
		$grant_url = esc_url_raw((string) ($data['url'] ?? ''));
		if ($grant_url === '') {
			$error = new \WP_Error('poststation_missing_blueprint_url', 'Blueprint download URL was not returned.');
			$this->support_service->set_n8n_last_error($error->get_error_message());
			return $error;
		}

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

		$blueprint_payload = $this->download_blueprint_payload($grant_url);
		if (is_wp_error($blueprint_payload)) {
			$this->support_service->set_n8n_last_error($blueprint_payload->get_error_message());
			return $blueprint_payload;
		}

		$workflow_result = $this->import_workflow(
			$base_url,
			$n8n_api_key,
			$blueprint_payload,
			$credentials,
			$blueprint_version,
			$blueprint_changelog,
			$resolved_workflow
		);
		if (is_wp_error($workflow_result)) {
			$this->support_service->set_n8n_last_error($workflow_result->get_error_message());
			return $workflow_result;
		}

		$deploy_data = [
			'workflow' => $workflow_result,
			'workflow_id' => (string) ($workflow_result['id'] ?? ''),
			'credentials' => $credentials,
			'blueprint_version' => $blueprint_version,
			'blueprint_changelog' => sanitize_textarea_field($blueprint_changelog),
			'blueprint_hash' => sanitize_text_field((string) ($release['hash'] ?? '')),
			'blueprint_file_name' => sanitize_text_field((string) ($release['fileName'] ?? '')),
			'blueprint_release_date' => sanitize_text_field((string) ($release['releaseDate'] ?? '')),
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
		$allowed = ['name', 'nodes', 'connections', 'settings', 'meta'];
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
	private function import_workflow(
		string $base_url,
		string $api_key,
		array $blueprint_payload,
		array $provisioned_credentials = [],
		string $release_version = '',
		string $release_changelog = '',
		array $existing_workflow = []
	)
	{
		$workflow_data = $this->extract_workflow_data($blueprint_payload);
		if (!is_array($workflow_data)) {
			return new \WP_Error('poststation_invalid_blueprint_payload', 'Blueprint payload is invalid.');
		}

		$create_body = $this->workflow_create_body($workflow_data, $provisioned_credentials);

		$normalized_body = $create_body;
		$action = 'created';

		$workflow_id = isset($existing_workflow['id']) ? trim((string) $existing_workflow['id']) : '';

		$workflow_result = [];
		if ($workflow_id !== '') {
			$action = 'updated';
			$workflow_result = $this->update_workflow($base_url, $api_key, $workflow_id, $normalized_body);
			error_log('Update Result: ' . print_r($workflow_result, true));
			if (is_wp_error($workflow_result)) {
				return $workflow_result;
			}
		} else {
			$workflow_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows', $normalized_body);
			error_log('Create Result: ' . print_r($workflow_result, true));
			if (is_wp_error($workflow_result) && $this->is_n8n_additional_properties_error($workflow_result)) {
				$normalized_body = [
					'name' => isset($normalized_body['name']) ? (string) $normalized_body['name'] : 'PostStation Workflow',
					'nodes' => isset($normalized_body['nodes']) && is_array($normalized_body['nodes']) ? $normalized_body['nodes'] : [],
					'connections' => isset($normalized_body['connections']) && is_array($normalized_body['connections']) ? $normalized_body['connections'] : [],
				];
				$workflow_result = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows', $normalized_body);
			}
			if (is_wp_error($workflow_result)) {
				return $workflow_result;
			}
		}

		$resolved_id = (string) ($workflow_result['id'] ?? $workflow_id);
		$activation = $resolved_id !== '' ? $this->activate_workflow_release($base_url, $api_key, $resolved_id, $release_version, $release_changelog) : ['active' => false];
		if (is_wp_error($activation)) {
			return $activation;
		}

		return [
			'id' => $resolved_id,
			'name' => sanitize_text_field((string) ($workflow_result['name'] ?? ($normalized_body['name'] ?? 'PostStation Workflow'))),
			'active' => !empty($activation['active']),
			'action' => $action,
		];
	}


	private function get_saved_workflow_id(): string
	{
		$last = get_option(SupportService::N8N_LAST_DEPLOY_OPTION, []);
		if (!is_array($last)) {
			return '';
		}

		$workflow_id = trim((string) ($last['workflow_id'] ?? ''));
		if ($workflow_id !== '') {
			return $workflow_id;
		}

		$workflow = $last['workflow'] ?? null;
		if (is_array($workflow)) {
			return trim((string) ($workflow['id'] ?? ''));
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $blueprint_meta
	 * @return array<string,mixed>
	 */
	private function extract_release_metadata(array $blueprint_meta): array
	{
		$data = isset($blueprint_meta['data']) && is_array($blueprint_meta['data']) ? $blueprint_meta['data'] : [];
		$release = isset($data['release']) && is_array($data['release']) ? $data['release'] : [];
		$latest = isset($blueprint_meta['latest']) && is_array($blueprint_meta['latest']) ? $blueprint_meta['latest'] : [];

		$version = trim((string) ($release['version'] ?? $latest['version'] ?? ''));
		$changelog = (string) ($release['changelog'] ?? $latest['changelog'] ?? '');
		$file_name = (string) ($release['fileName'] ?? $latest['fileName'] ?? '');
		$release_date = (string) ($release['releaseDate'] ?? $latest['releaseDate'] ?? '');
		$hash = (string) ($release['hash'] ?? $latest['hash'] ?? '');

		return [
			'version' => $version,
			'changelog' => $changelog,
			'fileName' => $file_name,
			'releaseDate' => $release_date,
			'hash' => $hash,
		];
	}

	/**
	 * Resolve the target workflow once (id first, then name fallback) to avoid duplicate lookups.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function resolve_existing_workflow(string $base_url, string $api_key, string $preferred_id, string $fallback_name)
	{
		$workflow_id = trim($preferred_id);
		if ($workflow_id !== '') {
			$by_id = $this->n8n_request($base_url, $api_key, 'GET', '/api/v1/workflows/' . rawurlencode($workflow_id));
			if (!is_wp_error($by_id)) {
				return is_array($by_id) ? $by_id : ['id' => $workflow_id];
			}
			if ($this->n8n_request_status($by_id) !== 404) {
				return $by_id;
			}
		}

		$by_name = $this->find_workflow_by_name($base_url, $api_key, $fallback_name);
		if (is_wp_error($by_name)) {
			return $by_name;
		}
		if (!is_array($by_name) || empty($by_name['id'])) {
			return [];
		}

		// Hydrate full workflow once so version/meta checks do not trigger extra lookups later.
		$full = $this->n8n_request($base_url, $api_key, 'GET', '/api/v1/workflows/' . rawurlencode((string) $by_name['id']));
		if (is_wp_error($full)) {
			return $full;
		}
		return is_array($full) ? $full : $by_name;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function find_workflow_by_name(string $base_url, string $api_key, string $name)
	{
		$target_name = trim($name);
		if ($target_name === '') {
			return [];
		}

		$cursor = '';
		$max_pages = 20;
		for ($page = 0; $page < $max_pages; $page++) {
			$path = '/api/v1/workflows?limit=100';
			if ($cursor !== '') {
				$path .= '&cursor=' . rawurlencode($cursor);
			}

			$list = $this->n8n_request($base_url, $api_key, 'GET', $path);
			if (is_wp_error($list)) {
				return $list;
			}

			$items = isset($list['data']) && is_array($list['data']) ? $list['data'] : [];
			$first_partial = [];
			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$item_name = trim((string) ($item['name'] ?? ''));
				$item_id = trim((string) ($item['id'] ?? ''));
				if ($item_name === '' || $item_id === '') {
					continue;
				}
				if (strcasecmp($item_name, $target_name) === 0) {
					return $item;
				}
				if ($first_partial === [] && stripos($item_name, $target_name) !== false && is_array($item)) {
					$first_partial = $item;
				}
			}
			if ($first_partial !== []) {
				return $first_partial;
			}

			$cursor = isset($list['nextCursor']) ? trim((string) $list['nextCursor']) : '';
			if ($cursor === '') {
				break;
			}
		}

		return [];
	}

	/**
	 * @param array<string,mixed> $workflow
	 * @return string
	 */
	private function get_current_workflow_version(array $workflow = []): string
	{
		$direct_candidates = [
			$workflow['versionName'] ?? '',
			$workflow['meta']['versionName'] ?? '',
			$workflow['meta']['poststationBlueprintVersion'] ?? '',
			$workflow['settings']['poststation_blueprint_version'] ?? '',
		];
		foreach ($direct_candidates as $candidate) {
			$value = trim((string) $candidate);
			if ($value !== '') {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $workflow_body
	 * @return array<string,mixed>|\WP_Error
	 */
	private function update_workflow(string $base_url, string $api_key, string $workflow_id, array $workflow_body)
	{
		$id = rawurlencode($workflow_id);
		$update_body = $this->workflow_update_body($workflow_body);
		$result = $this->n8n_request($base_url, $api_key, 'PATCH', '/api/v1/workflows/' . $id, $update_body);
		if (is_wp_error($result) && $this->is_n8n_additional_properties_error($result)) {
			$update_body = [
				'name' => isset($update_body['name']) ? (string) $update_body['name'] : 'PostStation Workflow',
				'nodes' => isset($update_body['nodes']) && is_array($update_body['nodes']) ? $update_body['nodes'] : [],
				'connections' => isset($update_body['connections']) && is_array($update_body['connections']) ? $update_body['connections'] : [],
			];
			$result = $this->n8n_request($base_url, $api_key, 'PATCH', '/api/v1/workflows/' . $id, $update_body);
		}
		if (is_wp_error($result)) {
			$result = $this->n8n_request($base_url, $api_key, 'PUT', '/api/v1/workflows/' . $id, $update_body);
		}
		if (is_wp_error($result)) {
			return $result;
		}

		return $result;
	}

	/**
	 * Build a strict body for workflow update to avoid schema rejections.
	 *
	 * @param array<string,mixed> $workflow_body
	 * @return array<string,mixed>
	 */
	private function workflow_update_body(array $workflow_body): array
	{
		$allowed = ['name', 'nodes', 'connections', 'settings', 'staticData', 'tags'];
		$body = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $workflow_body)) {
				$body[$key] = $workflow_body[$key];
			}
		}

		if (!isset($body['name']) || !is_string($body['name']) || trim($body['name']) === '') {
			$body['name'] = 'PostStation Workflow';
		}
		if (!isset($body['nodes']) || !is_array($body['nodes'])) {
			$body['nodes'] = [];
		}
		if (!isset($body['connections']) || !is_array($body['connections'])) {
			$body['connections'] = [];
		}

		if (isset($body['settings'])) {
			$tmp = $this->normalize_workflow_settings(['settings' => $body['settings']]);
			if (isset($tmp['settings']) && is_array($tmp['settings'])) {
				$body['settings'] = $tmp['settings'];
			} else {
				unset($body['settings']);
			}
		}

		return $body;
	}

	/**
	 * @return array{active:bool}|\WP_Error
	 */
	private function activate_workflow_release(
		string $base_url,
		string $api_key,
		string $workflow_id,
		string $release_version = '',
		string $release_changelog = ''
	)
	{
		$id = rawurlencode($workflow_id);
		$activation_payload = [
			'name' => trim($release_version) !== '' ? sanitize_text_field($release_version) : 'PostStation release',
			'description' => sanitize_textarea_field($release_changelog),
		];

		$activate = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/' . $id . '/activate', $activation_payload);
		if (is_wp_error($activate) && $this->is_n8n_additional_properties_error($activate)) {
			// Some n8n builds do not accept body fields for /activate.
			$activate = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/' . $id . '/activate');
		}
		if (is_wp_error($activate)) {
			$status = $this->n8n_request_status($activate);
			// If workflow is already active, force a republish by deactivating and activating again.
			if ($status !== null && in_array($status, [400, 409], true)) {
				$deactivate = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/' . $id . '/deactivate');
				if (is_wp_error($deactivate)) {
					return $deactivate;
				}
				$activate = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/' . $id . '/activate', $activation_payload);
				if (is_wp_error($activate) && $this->is_n8n_additional_properties_error($activate)) {
					$activate = $this->n8n_request($base_url, $api_key, 'POST', '/api/v1/workflows/' . $id . '/activate');
				}
			}
		}
		if (is_wp_error($activate)) {
			return $activate;
		}

		return ['active' => true];
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
