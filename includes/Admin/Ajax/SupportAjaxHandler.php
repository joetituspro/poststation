<?php

namespace PostStation\Admin\Ajax;

use PostStation\Services\AuthService;
use PostStation\Services\PluginUpdateService;
use PostStation\Services\SupportService;

class SupportAjaxHandler
{
	private SupportService $support_service;
	private AuthService $auth_service;
	private PluginUpdateService $plugin_update_service;

	public function __construct(
		?SupportService $support_service = null,
		?AuthService $auth_service = null,
		?PluginUpdateService $plugin_update_service = null
	) {
		$this->support_service = $support_service ?? new SupportService();
		$this->auth_service = $auth_service ?? AuthService::instance();
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

	public function check_plugin_update(): void
	{
		$this->require_manage_options();
		$this->plugin_update_service->get_update_info(true);

		wp_send_json_success([
			'message' => 'Plugin update check completed.',
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
