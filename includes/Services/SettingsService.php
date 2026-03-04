<?php

namespace PostStation\Services;

use PostStation\Utils\Environment;

class SettingsService
{
	public const OPTIONS_KEY = 'poststation_options';
	public const ENABLE_TUNNEL_URL_OPTION = 'poststation_enable_tunnel_url';
	public const TUNNEL_URL_OPTION = 'poststation_tunnel_url';
	public const SEND_API_TO_WEBHOOK_OPTION = 'poststation_send_api_to_webhook';
	public const API_KEY_OPTION = 'poststation_api_key';
	public const N8N_BASE_URL_OPTION = 'poststation_n8n_base_url';
	public const N8N_WORKFLOW_ID_OPTION = 'poststation_n8n_workflow_id';
	public const N8N_API_KEY_OPTION_ENC = 'poststation_n8n_api_key_enc';
	public const RAPIDAPI_KEY_OPTION_ENC = 'poststation_rapidapi_key_enc';
	public const FIRECRAWL_KEY_OPTION_ENC = 'poststation_firecrawl_key_enc';

	private OpenRouterService $openrouter_service;
	private SupportService $support_service;

	public function __construct(?OpenRouterService $openrouter_service = null, ?SupportService $support_service = null)
	{
		$this->openrouter_service = $openrouter_service ?? new OpenRouterService();
		$this->support_service = $support_service ?? new SupportService();
	}

	public function get_settings_data(): ?array
	{
		if (!current_user_can('manage_options')) {
			return null;
		}
		$n8n = $this->support_service->get_n8n_config(true);

		return [
			'api_key' => $this->get_api_key(),
			'send_api_to_webhook' => self::should_send_api_to_webhook(),
			'n8n_base_url' => (string) ($n8n['base_url'] ?? ''),
			'n8n_workflow_id' => (string) ($n8n['workflow_id'] ?? ''),
			'n8n_api_key_set' => ((string) ($n8n['n8n_api_key'] ?? '')) !== '',
			'rapidapi_key_set' => ((string) ($n8n['rapidapi_key'] ?? '')) !== '',
			'firecrawl_key_set' => ((string) ($n8n['firecrawl_key'] ?? '')) !== '',
			'n8n_openrouter_key_set' => ((string) ($n8n['openrouter_key'] ?? '')) !== '',
			'openrouter_api_key_set' => $this->openrouter_service->resolve_api_key() !== '',
			'openrouter_default_text_model' => $this->get_openrouter_default_text_model(),
			'openrouter_default_image_model' => $this->get_openrouter_default_image_model(),
			'is_local' => Environment::is_local(),
			'enable_tunnel_url' => self::is_tunnel_enabled(),
			'tunnel_url' => self::get_tunnel_url(),
		];
	}

	public function save_api_key(string $api_key): void
	{
		$this->set_option_value(self::API_KEY_OPTION, sanitize_text_field($api_key));
	}

	public function get_api_key(): string
	{
		$current = trim((string) $this->get_option_value(self::API_KEY_OPTION, ''));
		if ($current !== '') {
			return $current;
		}

		$legacy = trim((string) get_option(self::API_KEY_OPTION, ''));
		if ($legacy !== '') {
			$this->set_option_value(self::API_KEY_OPTION, $legacy);
			return $legacy;
		}

		return '';
	}

	public function save_send_api_to_webhook(bool $send_api_to_webhook): void
	{
		$this->set_option_value(self::SEND_API_TO_WEBHOOK_OPTION, $send_api_to_webhook ? '1' : '0');
	}

	public function save_openrouter_api_key(string $api_key): bool
	{
		return $this->openrouter_service->save_api_key(sanitize_text_field($api_key));
	}

	public function save_openrouter_defaults(string $default_text_model, string $default_image_model): void
	{
		$this->set_option_value(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, sanitize_text_field($default_text_model));
		$this->set_option_value(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, sanitize_text_field($default_image_model));
	}

	public function save_dev_settings(bool $enable_tunnel_url, string $tunnel_url): void
	{
		if (!Environment::is_local()) {
			return;
		}

		$this->set_option_value(self::ENABLE_TUNNEL_URL_OPTION, $enable_tunnel_url ? '1' : '0');

		$sanitized_tunnel_url = trim(esc_url_raw($tunnel_url));
		$this->set_option_value(self::TUNNEL_URL_OPTION, rtrim($sanitized_tunnel_url, '/'));
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function save_n8n_connection(array $data): void
	{
		$this->support_service->save_n8n_config($data);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function save_all_settings(array $data): void
	{
		$api_key = trim((string) ($data['api_key'] ?? ''));
		if ($api_key !== '') {
			$this->save_api_key($api_key);
		}

		$this->save_send_api_to_webhook(!empty($data['send_api_to_webhook']) && $data['send_api_to_webhook'] !== 'false');
		$this->save_openrouter_defaults(
			(string) ($data['default_text_model'] ?? ''),
			(string) ($data['default_image_model'] ?? '')
		);

		if (array_key_exists('openrouter_api_key', $data)) {
			$openrouter_api_key = trim((string) $data['openrouter_api_key']);
			if ($openrouter_api_key !== '') {
				$this->save_openrouter_api_key($openrouter_api_key);
			}
		}

		if (Environment::is_local()) {
			$this->save_dev_settings(
				!empty($data['enable_tunnel_url']) && $data['enable_tunnel_url'] !== 'false',
				(string) ($data['tunnel_url'] ?? '')
			);
		}

		$this->save_n8n_connection([
			'base_url' => (string) ($data['base_url'] ?? ''),
			'workflow_id' => (string) ($data['workflow_id'] ?? ''),
			'n8n_api_key' => (string) ($data['n8n_api_key'] ?? ''),
			'rapidapi_key' => (string) ($data['rapidapi_key'] ?? ''),
			'firecrawl_key' => (string) ($data['firecrawl_key'] ?? ''),
			'openrouter_key' => (string) ($data['openrouter_key'] ?? ''),
		]);
	}

	public static function is_tunnel_enabled(): bool
	{
		$current = (string) self::get_option_value_static(self::ENABLE_TUNNEL_URL_OPTION, '');
		if ($current !== '') {
			return $current === '1';
		}

		return get_option(self::ENABLE_TUNNEL_URL_OPTION, '0') === '1';
	}

	public static function get_tunnel_url(): string
	{
		if (!Environment::is_local() || !self::is_tunnel_enabled()) {
			return '';
		}

		$tunnel_url = trim((string) self::get_option_value_static(self::TUNNEL_URL_OPTION, ''));
		if ($tunnel_url === '') {
			$tunnel_url = trim((string) get_option(self::TUNNEL_URL_OPTION, ''));
		}
		if ($tunnel_url === '') {
			return '';
		}

		return rtrim((string) esc_url_raw($tunnel_url), '/');
	}

	public static function should_send_api_to_webhook(): bool
	{
		$current = (string) self::get_option_value_static(self::SEND_API_TO_WEBHOOK_OPTION, '');
		if ($current !== '') {
			return $current !== '0';
		}

		return get_option(self::SEND_API_TO_WEBHOOK_OPTION, '1') !== '0';
	}

	public function get_openrouter_service(): OpenRouterService
	{
		return $this->openrouter_service;
	}

	public function get_openrouter_default_text_model(): string
	{
		$current = (string) $this->get_option_value(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, '');
		if ($current !== '') {
			return $current;
		}

		$legacy = (string) get_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, '');
		if ($legacy !== '') {
			$this->set_option_value(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, $legacy);
		}

		return $legacy;
	}

	public function get_openrouter_default_image_model(): string
	{
		$current = (string) $this->get_option_value(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, '');
		if ($current !== '') {
			return $current;
		}

		$legacy = (string) get_option(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, '');
		if ($legacy !== '') {
			$this->set_option_value(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, $legacy);
		}

		return $legacy;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_options(): array
	{
		$options = get_option(self::OPTIONS_KEY, []);
		return is_array($options) ? $options : [];
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function save_options(array $options): void
	{
		update_option(self::OPTIONS_KEY, $options);
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	private function get_option_value(string $key, $default = '')
	{
		$options = $this->get_options();
		return array_key_exists($key, $options) ? $options[$key] : $default;
	}

	/**
	 * @param mixed $value
	 */
	private function set_option_value(string $key, $value): void
	{
		$options = $this->get_options();
		$options[$key] = $value;
		$this->save_options($options);
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	private static function get_option_value_static(string $key, $default = '')
	{
		$options = get_option(self::OPTIONS_KEY, []);
		if (!is_array($options)) {
			return $default;
		}

		return array_key_exists($key, $options) ? $options[$key] : $default;
	}
}
