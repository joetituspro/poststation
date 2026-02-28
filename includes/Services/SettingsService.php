<?php

namespace PostStation\Services;

use PostStation\Utils\Environment;

class SettingsService
{
	public const ENABLE_TUNNEL_URL_OPTION = 'poststation_enable_tunnel_url';
	public const TUNNEL_URL_OPTION = 'poststation_tunnel_url';
	public const WORKFLOW_API_KEY_OPTION = 'poststation_workflow_api_key';
	public const SEND_API_TO_WEBHOOK_OPTION = 'poststation_send_api_to_webhook';

	private OpenRouterService $openrouter_service;

	public function __construct(?OpenRouterService $openrouter_service = null)
	{
		$this->openrouter_service = $openrouter_service ?? new OpenRouterService();
	}

	public function get_settings_data(): ?array
	{
		if (!current_user_can('manage_options')) {
			return null;
		}

		return [
			'api_key' => get_option('poststation_api_key', ''),
			'send_api_to_webhook' => self::should_send_api_to_webhook(),
			'workflow_api_key' => get_option(self::WORKFLOW_API_KEY_OPTION, ''),
			'openrouter_api_key_set' => $this->openrouter_service->resolve_api_key() !== '',
			'openrouter_default_text_model' => get_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, ''),
			'openrouter_default_image_model' => get_option(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, ''),
			'is_local' => Environment::is_local(),
			'enable_tunnel_url' => self::is_tunnel_enabled(),
			'tunnel_url' => (string) get_option(self::TUNNEL_URL_OPTION, ''),
		];
	}

	public function save_api_key(string $api_key): void
	{
		update_option('poststation_api_key', sanitize_text_field($api_key));
	}

	public function save_workflow_api_key(string $workflow_api_key): void
	{
		update_option(self::WORKFLOW_API_KEY_OPTION, sanitize_text_field($workflow_api_key));
	}

	public function save_send_api_to_webhook(bool $send_api_to_webhook): void
	{
		update_option(self::SEND_API_TO_WEBHOOK_OPTION, $send_api_to_webhook ? '1' : '0');
	}

	/** Generate a new API key, save it, and return the new key. */
	public function regenerate_api_key(): string
	{
		$new_key = wp_generate_password(32, false);
		$this->save_api_key($new_key);
		return $new_key;
	}

	public function save_openrouter_api_key(string $api_key): bool
	{
		return $this->openrouter_service->save_api_key(sanitize_text_field($api_key));
	}

	public function save_openrouter_defaults(string $default_text_model, string $default_image_model): void
	{
		update_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, sanitize_text_field($default_text_model));
		update_option(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, sanitize_text_field($default_image_model));
	}

	public function save_dev_settings(bool $enable_tunnel_url, string $tunnel_url): void
	{
		if (!Environment::is_local()) {
			return;
		}

		update_option(self::ENABLE_TUNNEL_URL_OPTION, $enable_tunnel_url ? '1' : '0');

		$sanitized_tunnel_url = trim(esc_url_raw($tunnel_url));
		update_option(self::TUNNEL_URL_OPTION, rtrim($sanitized_tunnel_url, '/'));
	}

	public static function is_tunnel_enabled(): bool
	{
		return get_option(self::ENABLE_TUNNEL_URL_OPTION, '0') === '1';
	}

	public static function get_tunnel_url(): string
	{
		if (!Environment::is_local() || !self::is_tunnel_enabled()) {
			return '';
		}

		$tunnel_url = trim((string) get_option(self::TUNNEL_URL_OPTION, ''));
		if ($tunnel_url === '') {
			return '';
		}

		return rtrim((string) esc_url_raw($tunnel_url), '/');
	}

	public static function should_send_api_to_webhook(): bool
	{
		return get_option(self::SEND_API_TO_WEBHOOK_OPTION, '1') !== '0';
	}

	public function get_openrouter_service(): OpenRouterService
	{
		return $this->openrouter_service;
	}
}
