<?php

namespace PostStation\Services;

class OpenRouterService
{
	public const KEY_OPTION = 'poststation_openrouter_api_key';
	public const KEY_OPTION_ENC = 'poststation_openrouter_api_key_enc';
	public const DEFAULT_TEXT_MODEL_OPTION = 'poststation_openrouter_default_text_model';
	public const DEFAULT_IMAGE_MODEL_OPTION = 'poststation_openrouter_default_image_model';

	private const FORCED_IMAGE_MODELS = [
		'sourceful/riverflow-v2-pro',
		'sourceful/riverflow-v2-fast',
		'black-forest-labs/flux.2-klein-4b',
		'bytedance-seed/seedream-4.5',
		'black-forest-labs/flux.2-max',
		'black-forest-labs/flux.2-flex',
		'black-forest-labs/flux.2-pro',
	];

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
		$models = $this->ensure_forced_image_models($models, $api_key);

		set_transient($cache_key, $models, 30 * MINUTE_IN_SECONDS);
		return $models;
	}

	/**
	 * Ensures that all models in FORCED_IMAGE_MODELS are present in the model list.
	 * For any that are missing, fetches their details via the endpoints API
	 * (GET /api/v1/models/:author/:slug/endpoints) and falls back to a minimal
	 * placeholder if the fetch fails.
	 */
	private function ensure_forced_image_models(array $models, string $api_key): array
	{
		$existing_ids_lower = array_map('strtolower', array_column($models, 'id'));

		foreach (self::FORCED_IMAGE_MODELS as $forced_id) {
			if (in_array(strtolower($forced_id), $existing_ids_lower, true)) {
				continue;
			}

			$fetched = $this->fetch_single_model($forced_id, $api_key);
			if ($fetched !== null) {
				$models[] = $fetched;
			} else {
				// Fallback: register with minimal info so the model remains selectable.
				$models[] = [
					'id'            => $forced_id,
					'name'          => $forced_id,
					'supportsImage' => true,
					'supportsAudio' => false,
					'supportsText'  => false,
				];
			}
		}

		return $models;
	}

	/**
	 * Fetches a single model's details from the OpenRouter endpoints API.
	 *
	 * Endpoint: GET https://openrouter.ai/api/v1/models/:author/:slug/endpoints
	 * e.g. black-forest-labs/flux.2-pro  →  /api/v1/models/black-forest-labs/flux.2-pro/endpoints
	 *
	 * The response shape is:
	 *   { "data": { "id": "...", "name": "...", "architecture": { "output_modalities": [...] }, "endpoints": [...] } }
	 *
	 * Returns a normalized model array on success, or null on failure.
	 */
	private function fetch_single_model(string $model_id, string $api_key): ?array
	{
		// model_id is already in "author/slug" form — encode each segment separately.
		$parts = explode('/', $model_id, 2);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			error_log('PostStation: Invalid forced model ID format "' . $model_id . '" — expected author/slug.');
			return null;
		}

		$url = 'https://openrouter.ai/api/v1/models/'
			. rawurlencode($parts[0]) . '/'
			. rawurlencode($parts[1]) . '/endpoints';

		$response = wp_remote_get($url, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			error_log('PostStation: Failed to fetch OpenRouter model "' . $model_id . '": ' . $response->get_error_message());
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body   = json_decode((string) wp_remote_retrieve_body($response), true);

		if ($status < 200 || $status >= 300 || !is_array($body) || !isset($body['data'])) {
			error_log('PostStation: Unexpected response fetching OpenRouter model "' . $model_id . '" (HTTP ' . $status . ').');
			return null;
		}

		$raw = $body['data'];
		if (!is_array($raw) || empty($raw['id'])) {
			return null;
		}

		// normalize_models expects an array of model objects.
		$normalized = $this->normalize_models([$raw]);
		if (empty($normalized)) {
			return null;
		}

		// These are explicitly forced image models — guarantee the flags are correct
		// regardless of what output_modalities the API returned.
		$normalized[0]['supportsImage'] = true;
		$normalized[0]['supportsText']  = false;
		$normalized[0]['supportsAudio'] = false;

		return $normalized[0];
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