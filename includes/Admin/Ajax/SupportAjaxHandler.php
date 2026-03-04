<?php

namespace PostStation\Admin\Ajax;

use PostStation\Services\AuthService;
use PostStation\Services\BlueprintUpdateService;
use PostStation\Services\N8nDeploymentService;
use PostStation\Services\PluginUpdateService;
use PostStation\Services\RankimaClient;
use PostStation\Services\SupportService;

class SupportAjaxHandler
{
	private SupportService $support_service;
	private AuthService $auth_service;
	private RankimaClient $rankima_client;
	private N8nDeploymentService $n8n_deployment_service;
	private BlueprintUpdateService $blueprint_update_service;
	private PluginUpdateService $plugin_update_service;

	public function __construct(
		?SupportService $support_service = null,
		?AuthService $auth_service = null,
		?RankimaClient $rankima_client = null,
		?N8nDeploymentService $n8n_deployment_service = null,
		?BlueprintUpdateService $blueprint_update_service = null,
		?PluginUpdateService $plugin_update_service = null
	) {
		$this->support_service = $support_service ?? new SupportService();
		$this->auth_service = $auth_service ?? AuthService::instance();
		$this->rankima_client = $rankima_client ?? new RankimaClient();
		$this->n8n_deployment_service = $n8n_deployment_service ?? new N8nDeploymentService();
		$this->blueprint_update_service = $blueprint_update_service ?? new BlueprintUpdateService();
		$this->plugin_update_service = $plugin_update_service ?? new PluginUpdateService();
	}

	public function get_state(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Your session has expired. Please reload the page and try again.']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		wp_send_json_success($this->support_service->get_support_state());
	}

	public function handle_license(): void
	{
		$this->require_manage_options();
		$action_type = isset($_POST['action_type']) ? sanitize_text_field((string) $_POST['action_type']) : '';
		$license_key = isset($_POST['license_key']) ? (string) $_POST['license_key'] : '';

		$result = $this->auth_service->handle_support_license_action($action_type, $license_key);
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success($result);
	}

	public function save_n8n_config(): void
	{
		$this->require_manage_options();
		$this->support_service->save_n8n_config([
			'base_url' => (string) ($_POST['base_url'] ?? ''),
			'workflow_id' => (string) ($_POST['workflow_id'] ?? ''),
			'n8n_api_key' => (string) ($_POST['n8n_api_key'] ?? ''),
			'rapidapi_key' => (string) ($_POST['rapidapi_key'] ?? ''),
			'firecrawl_key' => (string) ($_POST['firecrawl_key'] ?? ''),
			'openrouter_key' => (string) ($_POST['openrouter_key'] ?? ''),
		]);

		wp_send_json_success([
			'message' => 'n8n config saved.',
			'support' => $this->support_service->get_support_state(),
		]);
	}

	public function deploy_n8n_blueprint(): void
	{
		$this->require_manage_options();
		$create_or_update_webhook = !isset($_POST['create_or_update_webhook']) || ($_POST['create_or_update_webhook'] !== 'false' && $_POST['create_or_update_webhook'] !== '0');
		$create_or_update_credentials = !isset($_POST['create_or_update_credentials']) || ($_POST['create_or_update_credentials'] !== 'false' && $_POST['create_or_update_credentials'] !== '0');

		$result = $this->n8n_deployment_service->deploy_blueprint([
			'create_or_update_webhook' => $create_or_update_webhook,
			'create_or_update_credentials' => $create_or_update_credentials,
		]);
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		$this->support_service->complete_onboarding();
		wp_send_json_success([
			'deploy' => $result,
			'support' => $this->support_service->get_support_state(),
		]);
	}

	public function get_manual_blueprint(): void
	{
		$this->require_manage_options();
		$license_key = $this->auth_service->get_license_key();
		$site_key = $this->auth_service->get_site_key();
		if ($license_key === '') {
			wp_send_json_error(['message' => 'License key is required.']);
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

		$response = $this->rankima_client->post('/api/downloads/latest', $payload);
		if (is_wp_error($response)) {
			wp_send_json_error(['message' => $response->get_error_message()]);
		}

		$data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
		$latest = isset($response['latest']) && is_array($response['latest']) ? $response['latest'] : [];
		wp_send_json_success([
			'download_url' => esc_url_raw((string) ($data['url'] ?? '')),
			'version' => sanitize_text_field((string) ($latest['version'] ?? '')),
			'release_date' => sanitize_text_field((string) ($latest['releaseDate'] ?? '')),
			'file_name' => sanitize_text_field((string) ($latest['fileName'] ?? '')),
			'manual_instructions' => wp_kses_post((string) ($latest['manualInstructions'] ?? '')),
		]);
	}

	public function check_blueprint_update(): void
	{
		$this->require_manage_options();
		$force = !empty($_POST['force']) && $_POST['force'] !== 'false';
		$response = $this->blueprint_update_service->check_for_updates($force);
		if (is_wp_error($response)) {
			wp_send_json_error(['message' => $response->get_error_message()]);
		}
		wp_send_json_success([
			'blueprint_update' => $response,
			'support' => $this->support_service->get_support_state(),
		]);
	}

	public function set_auto_update_plugin(): void
	{
		$this->require_manage_options();
		$enabled = !empty($_POST['enabled']) && $_POST['enabled'] !== 'false';
		$this->support_service->set_plugin_auto_update_enabled($enabled);
		$this->plugin_update_service->get_update_info(true);

		wp_send_json_success([
			'message' => 'Plugin auto-update setting saved.',
			'support' => $this->support_service->get_support_state(),
		]);
	}

	public function complete_onboarding(): void
	{
		$this->require_manage_options();
		$this->support_service->complete_onboarding();
		wp_send_json_success([
			'support' => $this->support_service->get_support_state(),
		]);
	}

	private function require_manage_options(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Your session has expired. Please reload the page and try again.']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}
	}
}
