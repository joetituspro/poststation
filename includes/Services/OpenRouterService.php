<?php

namespace PostStation\Services;

class OpenRouterService
{
	public const KEY_OPTION = 'poststation_openrouter_api_key';
	public const KEY_OPTION_ENC = 'poststation_openrouter_api_key_enc';
	public const DEFAULT_TEXT_MODEL_OPTION = 'poststation_openrouter_default_text_model';
	public const DEFAULT_IMAGE_MODEL_OPTION = 'poststation_openrouter_default_image_model';

	public function resolve_api_key(): string
	{
		$filtered = apply_filters('poststation_openrouter_api_key', '');
		if (is_string($filtered) && $filtered !== '') {
			return trim($filtered);
		}

		if (defined('POSTSTATION_OPENROUTER_API_KEY') && is_string(POSTSTATION_OPENROUTER_API_KEY) && POSTSTATION_OPENROUTER_API_KEY !== '') {
			return trim(POSTSTATION_OPENROUTER_API_KEY);
		}

		$env_key = getenv('OPENROUTER_API_KEY');
		if (is_string($env_key) && $env_key !== '') {
			return trim($env_key);
		}

		$encrypted_option = get_option(self::KEY_OPTION_ENC, '');
		if (is_string($encrypted_option) && $encrypted_option !== '') {
			$decrypted = $this->decrypt_api_key($encrypted_option);
			if ($decrypted !== '') {
				return $decrypted;
			}
		}

		$option_key = get_option(self::KEY_OPTION, '');
		if (is_string($option_key) && $option_key !== '') {
			return trim($option_key);
		}

		return '';
	}

	public function clear_api_key(): void
	{
		delete_option(self::KEY_OPTION_ENC);
		delete_option(self::KEY_OPTION);
	}

	public function save_api_key(string $api_key): bool
	{
		$api_key = trim($api_key);
		if ($api_key === '') {
			$this->clear_api_key();
			return true;
		}

		$encrypted = $this->encrypt_api_key($api_key);
		if ($encrypted === '') {
			return false;
		}

		update_option(self::KEY_OPTION_ENC, $encrypted);
		delete_option(self::KEY_OPTION);
		return true;
	}

	public function get_models(bool $force_refresh = false, bool $suppress_errors = false)
	{
		$cache_key = 'poststation_openrouter_models_v2';
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$api_key = $this->resolve_api_key();
		if ($api_key === '') {
			if ($suppress_errors) {
				return [];
			}
			return new \WP_Error('missing_openrouter_key', 'OpenRouter API key is missing.');
		}

		$response = wp_remote_get('https://openrouter.ai/api/v1/models', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			if ($suppress_errors) {
				return [];
			}
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($status < 200 || $status >= 300 || !is_array($body)) {
			if ($suppress_errors) {
				return [];
			}
			return new \WP_Error('openrouter_request_failed', 'Failed to fetch OpenRouter models.');
		}

		$models = $this->normalize_models((array) ($body['data'] ?? []));
		set_transient($cache_key, $models, 30 * MINUTE_IN_SECONDS);
		return $models;
	}

	private function get_encryption_secret(): string
	{
		return hash('sha256', wp_salt('auth') . '|poststation|openrouter', true);
	}

	private function encrypt_api_key(string $plain_text): string
	{
		if ($plain_text === '' || !function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
			return '';
		}

		$key = $this->get_encryption_secret();
		$iv = openssl_random_pseudo_bytes(12);
		$tag = '';
		$ciphertext = openssl_encrypt($plain_text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
		if (!is_string($ciphertext) || $ciphertext === '') {
			return '';
		}

		return base64_encode($iv . $tag . $ciphertext);
	}

	private function decrypt_api_key(string $encoded): string
	{
		if ($encoded === '' || !function_exists('openssl_decrypt')) {
			return '';
		}

		$payload = base64_decode($encoded, true);
		if (!is_string($payload) || strlen($payload) <= 28) {
			return '';
		}

		$iv = substr($payload, 0, 12);
		$tag = substr($payload, 12, 16);
		$ciphertext = substr($payload, 28);
		$key = $this->get_encryption_secret();
		$decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		return is_string($decrypted) ? trim($decrypted) : '';
	}

	private function normalize_models(array $models): array
	{
		$normalized = [];
		foreach ($models as $model) {
			if (!is_array($model)) {
				continue;
			}

			$id = trim((string) ($model['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$normalized_id = strtolower($id);
			if ($normalized_id === 'openrouter/auto' || str_starts_with($normalized_id, 'openrouter/auto-')) {
				continue;
			}

			$modalities = array_map(
				static fn($value) => strtolower((string) $value),
				(array) ($model['architecture']['output_modalities'] ?? [])
			);

			$normalized[] = [
				'id' => $id,
				'name' => trim((string) ($model['name'] ?? $id)) ?: $id,
				'supportsImage' => in_array('image', $modalities, true),
				'supportsAudio' => in_array('audio', $modalities, true),
				'supportsText' => in_array('text', $modalities, true)
					&& !in_array('image', $modalities, true)
					&& !in_array('audio', $modalities, true),
			];
		}

		return $normalized;
	}
}
