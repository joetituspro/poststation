<?php

namespace PostStation\Services;

class SupportService
{
	public const ONBOARDING_REQUIRED_OPTION = 'poststation_support_onboarding_required';
	public const ONBOARDING_SEEN_AT_OPTION = 'poststation_support_onboarding_seen_at';
	public const ONBOARDING_REDIRECT_OPTION = 'poststation_support_pending_redirect';

	public const N8N_BASE_URL_OPTION = 'poststation_n8n_base_url';
	public const N8N_WORKFLOW_ID_OPTION = 'poststation_n8n_workflow_id';
	public const N8N_API_KEY_OPTION_ENC = 'poststation_n8n_api_key_enc';
	public const RAPIDAPI_KEY_OPTION_ENC = 'poststation_rapidapi_key_enc';
	public const FIRECRAWL_KEY_OPTION_ENC = 'poststation_firecrawl_key_enc';
	public const N8N_AUTODEPLOY_ENABLED_OPTION = 'poststation_n8n_autodeploy_enabled';
	public const BLUEPRINT_UPDATE_STATE_OPTION = 'poststation_n8n_blueprint_update_state';

	public const PLUGIN_AUTO_UPDATE_ENABLED_OPTION = 'poststation_plugin_auto_update_enabled';
	public const PLUGIN_UPDATE_LAST_CHECK_OPTION = 'poststation_rankima_last_plugin_update_check';
	public const BLUEPRINT_LAST_CHECK_OPTION = 'poststation_rankima_last_blueprint_check';

	private CryptoService $crypto_service;
	private AuthService $auth_service;

	public function __construct(?CryptoService $crypto_service = null, ?AuthService $auth_service = null)
	{
		$this->crypto_service = $crypto_service ?? new CryptoService();
		$this->auth_service = $auth_service ?? AuthService::instance();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_support_state(): array
	{
		$license_status = $this->auth_service->get_license_status();
		$n8n_config = $this->get_n8n_config(false);
		$blueprint_update_state = get_option(self::BLUEPRINT_UPDATE_STATE_OPTION, []);

		return [
			'onboarding_required' => $this->is_onboarding_required(),
			'onboarding_seen_at' => (int) get_option(self::ONBOARDING_SEEN_AT_OPTION, 0),
			'license' => [
				'key_set' => $this->auth_service->get_license_key() !== '',
				'truncated_key' => $this->auth_service->get_truncated_license_key(),
				'site_key_set' => $this->auth_service->get_site_key() !== '',
				'cached_status' => $this->auth_service->get_status(),
				'status' => $license_status,
			],
			'n8n' => [
				'base_url' => $n8n_config['base_url'],
				'workflow_id' => $n8n_config['workflow_id'],
				'n8n_api_key_set' => $n8n_config['n8n_api_key'] !== '',
				'rapidapi_key_set' => $n8n_config['rapidapi_key'] !== '',
				'firecrawl_key_set' => $n8n_config['firecrawl_key'] !== '',
				'openrouter_key_set' => $n8n_config['openrouter_key'] !== '',
				'autodeploy_enabled' => $this->is_n8n_autodeploy_enabled(),
				'blueprint_update' => is_array($blueprint_update_state) ? $blueprint_update_state : [],
			],
			'updates' => [
				'plugin_current_version' => POSTSTATION_VERSION,
				'plugin_latest' => $this->get_plugin_update_snapshot(),
				'plugin_auto_update_enabled' => $this->is_plugin_auto_update_enabled(),
				'last_plugin_update_check_at' => (int) get_option(self::PLUGIN_UPDATE_LAST_CHECK_OPTION, 0),
				'last_blueprint_check_at' => (int) get_option(self::BLUEPRINT_LAST_CHECK_OPTION, 0),
			],
		];
	}

	/**
	 * @return array<string,string>
	 */
	public function get_n8n_config(bool $include_secrets = false): array
	{
		$n8n_api_key = $this->read_encrypted_option(self::N8N_API_KEY_OPTION_ENC, 'n8n_api');
		$rapidapi_key = $this->read_encrypted_option(self::RAPIDAPI_KEY_OPTION_ENC, 'rapidapi');
		$firecrawl_key = $this->read_encrypted_option(self::FIRECRAWL_KEY_OPTION_ENC, 'firecrawl');
		$openrouter_key = $this->read_encrypted_option(OpenRouterService::KEY_OPTION_ENC, 'openrouter');
		$base_url = (string) $this->get_option_value(self::N8N_BASE_URL_OPTION, '');
		$workflow_id = (string) $this->get_option_value(self::N8N_WORKFLOW_ID_OPTION, '');
		if ($base_url === '') {
			$base_url = (string) get_option(self::N8N_BASE_URL_OPTION, '');
			if ($base_url !== '') {
				$this->set_option_value(self::N8N_BASE_URL_OPTION, $base_url);
			}
		}
		if ($workflow_id === '') {
			$workflow_id = (string) get_option(self::N8N_WORKFLOW_ID_OPTION, '');
			if ($workflow_id !== '') {
				$this->set_option_value(self::N8N_WORKFLOW_ID_OPTION, $workflow_id);
			}
		}

		return [
			'base_url' => $base_url,
			'workflow_id' => $workflow_id,
			'n8n_api_key' => $include_secrets ? $n8n_api_key : '',
			'rapidapi_key' => $include_secrets ? $rapidapi_key : '',
			'firecrawl_key' => $include_secrets ? $firecrawl_key : '',
			'openrouter_key' => $include_secrets ? $openrouter_key : '',
		];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function save_n8n_config(array $data): void
	{
		$base_url = esc_url_raw((string) ($data['base_url'] ?? ''));
		$this->set_option_value(self::N8N_BASE_URL_OPTION, rtrim($base_url, '/'));
		$this->set_option_value(self::N8N_WORKFLOW_ID_OPTION, sanitize_text_field((string) ($data['workflow_id'] ?? '')));

		$this->save_secret_value(self::N8N_API_KEY_OPTION_ENC, (string) ($data['n8n_api_key'] ?? ''), 'n8n_api');
		$this->save_secret_value(self::RAPIDAPI_KEY_OPTION_ENC, (string) ($data['rapidapi_key'] ?? ''), 'rapidapi');
		$this->save_secret_value(self::FIRECRAWL_KEY_OPTION_ENC, (string) ($data['firecrawl_key'] ?? ''), 'firecrawl');
		$this->save_secret_value(OpenRouterService::KEY_OPTION_ENC, (string) ($data['openrouter_key'] ?? ''), 'openrouter');
		delete_option(OpenRouterService::KEY_OPTION);
	}

	public function set_blueprint_update_state(array $state): void
	{
		update_option(self::BLUEPRINT_UPDATE_STATE_OPTION, $state);
		update_option(self::BLUEPRINT_LAST_CHECK_OPTION, current_time('timestamp'));
	}

	public function is_onboarding_required(): bool
	{
		return get_option(self::ONBOARDING_REQUIRED_OPTION, '0') === '1';
	}

	public function mark_onboarding_required(): void
	{
		update_option(self::ONBOARDING_REQUIRED_OPTION, '1');
		update_option(self::ONBOARDING_REDIRECT_OPTION, '1');
	}

	public function complete_onboarding(): void
	{
		update_option(self::ONBOARDING_REQUIRED_OPTION, '0');
		update_option(self::ONBOARDING_SEEN_AT_OPTION, current_time('timestamp'));
		delete_option(self::ONBOARDING_REDIRECT_OPTION);
	}

	public function should_redirect_to_support(): bool
	{
		return get_option(self::ONBOARDING_REDIRECT_OPTION, '0') === '1';
	}

	public function clear_support_redirect_flag(): void
	{
		delete_option(self::ONBOARDING_REDIRECT_OPTION);
	}

	public function is_n8n_autodeploy_enabled(): bool
	{
		return get_option(self::N8N_AUTODEPLOY_ENABLED_OPTION, '0') === '1';
	}

	public function set_n8n_autodeploy_enabled(bool $enabled): void
	{
		update_option(self::N8N_AUTODEPLOY_ENABLED_OPTION, $enabled ? '1' : '0');
	}

	public function is_plugin_auto_update_enabled(): bool
	{
		return get_option(self::PLUGIN_AUTO_UPDATE_ENABLED_OPTION, '0') === '1';
	}

	public function set_plugin_auto_update_enabled(bool $enabled): void
	{
		update_option(self::PLUGIN_AUTO_UPDATE_ENABLED_OPTION, $enabled ? '1' : '0');
	}

	private function save_secret_value(string $option, string $value, string $context): void
	{
		$value = trim($value);
		if ($value === '') {
			// Keep existing value if an empty input is sent, to avoid accidental clearing.
			return;
		}

		$encrypted = $this->crypto_service->encrypt($value, $context);
		if ($encrypted !== '') {
			$this->set_option_value($option, $encrypted);
		}
	}

	private function read_encrypted_option(string $option, string $context): string
	{
		$raw = (string) $this->get_option_value($option, '');
		if ($raw === '') {
			$raw = (string) get_option($option, '');
			if ($raw !== '') {
				$this->set_option_value($option, $raw);
			}
		}
		if ($raw === '') {
			return '';
		}

		$decrypted = $this->crypto_service->decrypt($raw, $context);
		if ($decrypted !== '') {
			return $decrypted;
		}

		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_poststation_options(): array
	{
		$options = get_option(SettingsService::OPTIONS_KEY, []);
		return is_array($options) ? $options : [];
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	private function get_option_value(string $key, $default = '')
	{
		$options = $this->get_poststation_options();
		return array_key_exists($key, $options) ? $options[$key] : $default;
	}

	/**
	 * @param mixed $value
	 */
	private function set_option_value(string $key, $value): void
	{
		$options = $this->get_poststation_options();
		$options[$key] = $value;
		update_option(SettingsService::OPTIONS_KEY, $options);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_plugin_update_snapshot(): array
	{
		$cached = get_transient(UpdateService::PLUGIN_UPDATE_CACHE_TRANSIENT);
		if (!is_array($cached)) {
			return [];
		}

		return [
			'new_version' => sanitize_text_field((string) ($cached['new_version'] ?? '')),
			'url' => esc_url_raw((string) ($cached['url'] ?? '')),
		];
	}
}
