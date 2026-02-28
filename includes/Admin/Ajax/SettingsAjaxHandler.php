<?php

namespace PostStation\Admin\Ajax;

use PostStation\Services\SettingsService;
use PostStation\Utils\Environment;

class SettingsAjaxHandler
{
	private SettingsService $settings_service;

	public function __construct(?SettingsService $settings_service = null)
	{
		$this->settings_service = $settings_service ?? new SettingsService();
	}

	public function get_settings(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$settings = $this->settings_service->get_settings_data();
		wp_send_json_success($settings ?? []);
	}

	public function save_api_key(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$this->settings_service->save_api_key((string) ($_POST['api_key'] ?? ''));
		wp_send_json_success(['message' => 'API key saved']);
	}

	public function regenerate_api_key(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$new_key = $this->settings_service->regenerate_api_key();
		wp_send_json_success(['api_key' => $new_key]);
	}

	public function save_workflow_api_key(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$this->settings_service->save_workflow_api_key((string) ($_POST['workflow_api_key'] ?? ''));
		wp_send_json_success(['message' => 'Workflow API key saved']);
	}

	public function save_send_api_to_webhook(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$send_api_to_webhook = !empty($_POST['send_api_to_webhook']) && $_POST['send_api_to_webhook'] !== 'false';
		$this->settings_service->save_send_api_to_webhook($send_api_to_webhook);
		wp_send_json_success(['message' => 'Webhook payload setting saved']);
	}

	public function save_openrouter_api_key(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$api_key = (string) ($_POST['api_key'] ?? '');
		if (trim($api_key) === '') {
			$this->settings_service->get_openrouter_service()->clear_api_key();
			wp_send_json_success(['message' => 'OpenRouter API key cleared']);
		}

		$saved = $this->settings_service->save_openrouter_api_key($api_key);
		if (!$saved) {
			wp_send_json_error(['message' => 'Unable to securely store OpenRouter API key on this server']);
		}

		wp_send_json_success(['message' => 'OpenRouter API key saved']);
	}

	public function save_openrouter_defaults(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$this->settings_service->save_openrouter_defaults(
			(string) ($_POST['default_text_model'] ?? ''),
			(string) ($_POST['default_image_model'] ?? '')
		);
		wp_send_json_success(['message' => 'OpenRouter default models saved']);
	}

	public function get_openrouter_models(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$force_refresh = !empty($_POST['force_refresh']) && $_POST['force_refresh'] !== 'false';
		$models = $this->settings_service->get_openrouter_service()->get_models($force_refresh);
		if (is_wp_error($models)) {
			wp_send_json_error(['message' => $models->get_error_message()]);
		}

		wp_send_json_success([
			'models' => $models,
			'updated_at' => current_time('timestamp'),
		]);
	}

	public function save_dev_settings(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}
		if (!Environment::is_local()) {
			wp_send_json_error(['message' => 'Dev settings are only available in local environments']);
		}

		$enable_tunnel_url = !empty($_POST['enable_tunnel_url']) && $_POST['enable_tunnel_url'] !== 'false';
		$tunnel_url = (string) ($_POST['tunnel_url'] ?? '');

		$this->settings_service->save_dev_settings($enable_tunnel_url, $tunnel_url);
		wp_send_json_success(['message' => 'Dev settings saved']);
	}
}
