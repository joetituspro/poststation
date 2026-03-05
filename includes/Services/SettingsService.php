<?php

namespace PostStation\Services;

use PostStation\Utils\Environment;

class SettingsService
{
	public const OPTIONS_KEY = 'poststation_options';
	public const ENABLE_TUNNEL_URL_OPTION = 'poststation_enable_tunnel_url';
	public const TUNNEL_URL_OPTION = 'poststation_tunnel_url';
	public const API_KEY_OPTION = 'poststation_api_key';
	public const ARTICLE_SCRAPER_PROVIDER_OPTION = 'poststation_article_scraper_provider';
	public const RANKIMA_EXTRACTOR_KEY_OPTION_ENC = 'poststation_rankima_extractor_api_key_enc';
	public const FIRECRAWL_API_URL_OPTION = 'poststation_firecrawl_api_url';
	public const FIRECRAWL_API_KEY_OPTION_ENC = 'poststation_firecrawl_api_key_enc';
	public const RAPIDAPI_API_URL_OPTION = 'poststation_rapidapi_api_url';
	public const RAPIDAPI_API_KEY_OPTION_ENC = 'poststation_rapidapi_api_key_enc';
	public const CLEAN_DATA_WITH_AI_OPTION = 'poststation_clean_data_with_ai';
	public const CLEAN_DATA_MODEL_ID_OPTION = 'poststation_clean_data_model_id';

	private OpenRouterService $openrouter_service;
	private CryptoService $crypto_service;

	public function __construct(?OpenRouterService $openrouter_service = null, ?CryptoService $crypto_service = null)
	{
		$this->openrouter_service = $openrouter_service ?? new OpenRouterService();
		$this->crypto_service = $crypto_service ?? new CryptoService();
	}

	public function get_settings_data(): ?array
	{
		if (!current_user_can('manage_options')) {
			return null;
		}
		return [
			'api_key' => $this->get_api_key(),
			'article_scraper_provider' => $this->get_article_scraper_provider(),
			'rankima_extractor_api_key_set' => $this->get_rankima_extractor_api_key() !== '',
			'firecrawl_api_url' => $this->get_firecrawl_api_url(),
			'firecrawl_api_key_set' => $this->get_firecrawl_api_key() !== '',
			'rapidapi_api_url' => $this->get_rapidapi_api_url(),
			'rapidapi_api_key_set' => $this->get_rapidapi_api_key() !== '',
			'clean_data_with_ai' => $this->is_clean_data_with_ai_enabled(),
			'clean_data_model_id' => $this->get_clean_data_model_id(),
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
	public function save_all_settings(array $data): void
	{
		$api_key = trim((string) ($data['api_key'] ?? ''));
		if ($api_key !== '') {
			$this->save_api_key($api_key);
		}

		$this->save_openrouter_defaults(
			(string) ($data['default_text_model'] ?? ''),
			(string) ($data['default_image_model'] ?? '')
		);
		$this->save_article_scraper_settings($data);

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

	public function get_openrouter_service(): OpenRouterService
	{
		return $this->openrouter_service;
	}

	public function get_article_scraper_provider(): string
	{
		$value = strtolower(trim((string) $this->get_option_value(self::ARTICLE_SCRAPER_PROVIDER_OPTION, 'rankima')));
		return in_array($value, ['rankima', 'firecrawl', 'rapidapi'], true) ? $value : 'rankima';
	}

	public function get_rankima_extractor_api_key(): string
	{
		return $this->decrypt_key((string) $this->get_option_value(self::RANKIMA_EXTRACTOR_KEY_OPTION_ENC, ''), 'rankima_extractor');
	}

	public function get_firecrawl_api_url(): string
	{
		$value = trim((string) $this->get_option_value(self::FIRECRAWL_API_URL_OPTION, 'https://api.firecrawl.dev/v2/scrape'));
		return $value !== '' ? esc_url_raw($value) : 'https://api.firecrawl.dev/v2/scrape';
	}

	public function get_firecrawl_api_key(): string
	{
		return $this->decrypt_key((string) $this->get_option_value(self::FIRECRAWL_API_KEY_OPTION_ENC, ''), 'firecrawl_api');
	}

	public function get_rapidapi_api_url(): string
	{
		$value = trim((string) $this->get_option_value(self::RAPIDAPI_API_URL_OPTION, 'https://article-extractor2.p.rapidapi.com/article/parse'));
		return $value !== '' ? esc_url_raw($value) : 'https://article-extractor2.p.rapidapi.com/article/parse';
	}

	public function get_rapidapi_api_key(): string
	{
		return $this->decrypt_key((string) $this->get_option_value(self::RAPIDAPI_API_KEY_OPTION_ENC, ''), 'rapidapi_api');
	}

	public function is_clean_data_with_ai_enabled(): bool
	{
		return (string) $this->get_option_value(self::CLEAN_DATA_WITH_AI_OPTION, '1') !== '0';
	}

	public function get_clean_data_model_id(): string
	{
		$value = trim((string) $this->get_option_value(self::CLEAN_DATA_MODEL_ID_OPTION, 'google/gemini-2.5-flash-lite'));
		return $value !== '' ? $value : 'google/gemini-2.5-flash-lite';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function save_article_scraper_settings(array $data): void
	{
		$provider = strtolower(trim((string) ($data['article_scraper_provider'] ?? 'rankima')));
		if (!in_array($provider, ['rankima', 'firecrawl', 'rapidapi'], true)) {
			$provider = 'rankima';
		}
		$this->set_option_value(self::ARTICLE_SCRAPER_PROVIDER_OPTION, $provider);

		$this->set_option_value(self::FIRECRAWL_API_URL_OPTION, esc_url_raw((string) ($data['firecrawl_api_url'] ?? 'https://api.firecrawl.dev/v2/scrape')));
		$this->set_option_value(self::RAPIDAPI_API_URL_OPTION, esc_url_raw((string) ($data['rapidapi_api_url'] ?? 'https://article-extractor2.p.rapidapi.com/article/parse')));
		$this->set_option_value(
			self::CLEAN_DATA_WITH_AI_OPTION,
			(!empty($data['clean_data_with_ai']) && (string) $data['clean_data_with_ai'] !== '0' && (string) $data['clean_data_with_ai'] !== 'false') ? '1' : '0'
		);
		$this->set_option_value(self::CLEAN_DATA_MODEL_ID_OPTION, sanitize_text_field((string) ($data['clean_data_model_id'] ?? 'google/gemini-2.5-flash-lite')));

		$this->encrypt_and_store_key(self::RANKIMA_EXTRACTOR_KEY_OPTION_ENC, (string) ($data['rankima_extractor_api_key'] ?? ''), 'rankima_extractor');
		$this->encrypt_and_store_key(self::FIRECRAWL_API_KEY_OPTION_ENC, (string) ($data['firecrawl_api_key'] ?? ''), 'firecrawl_api');
		$this->encrypt_and_store_key(self::RAPIDAPI_API_KEY_OPTION_ENC, (string) ($data['rapidapi_api_key'] ?? ''), 'rapidapi_api');
	}

	private function encrypt_and_store_key(string $option, string $plain, string $context): void
	{
		$plain = trim($plain);
		if ($plain === '') {
			return;
		}
		$encrypted = $this->crypto_service->encrypt($plain, $context);
		if ($encrypted !== '') {
			$this->set_option_value($option, $encrypted);
		}
	}

	private function decrypt_key(string $encrypted, string $context): string
	{
		if ($encrypted === '') {
			return '';
		}
		return $this->crypto_service->decrypt($encrypted, $context);
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
