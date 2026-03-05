<?php

namespace PostStation\Services\Workflow;

use PostStation\Services\OpenRouterService;
use PostStation\Services\SettingsService;

class OpenRouterClient
{
	private OpenRouterService $openrouter_service;
	private SettingsService $settings_service;

	public function __construct(?OpenRouterService $openrouter_service = null, ?SettingsService $settings_service = null)
	{
		$this->openrouter_service = $openrouter_service ?? new OpenRouterService();
		$this->settings_service = $settings_service ?? new SettingsService();
	}

	/**
	 * @param array<int,array<string,string>> $messages
	 * @return array<string,mixed>|\WP_Error
	 */
	public function chat(
		array $messages,
		?string $model = null,
		bool $json = false,
		?array $json_schema = null,
		string $json_mode = 'json_schema'
	)
	{
		$api_key = $this->openrouter_service->resolve_api_key();
		if ($api_key === '') {
			$this->log('chat:missing_api_key');
			return new \WP_Error('poststation_missing_openrouter_key', 'OpenRouter API key is missing for local workflow mode.');
		}

		$model = trim((string) $model);
		if ($model === '') {
			$model = trim($this->settings_service->get_openrouter_default_text_model());
		}
		if ($model === '') {
			$model = 'openai/gpt-4.1-mini';
		}
		$this->log('chat:request', [
			'model' => $model,
			'json_mode' => $json ? 1 : 0,
			'message_count' => count($messages),
		]);

		$body = [
			'model' => $model,
			'messages' => $messages,
			'temperature' => 0.3,
		];
		if ($json) {
			if ($json_mode === 'json_object') {
				$body['response_format'] = ['type' => 'json_object'];
			} else {
				$schema = is_array($json_schema) && !empty($json_schema)
					? $json_schema
					: [
						'type' => 'object',
						'additionalProperties' => true,
					];
				$body['response_format'] = [
					'type' => 'json_schema',
					'json_schema' => [
						'schema' => $schema,
					],
				];
			}
		}

		$response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
			'timeout' => 120,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode($body),
		]);

		if (is_wp_error($response)) {
			$this->log('chat:wp_error', ['error' => $response->get_error_message()]);
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		$decoded = json_decode($raw_body, true);
		if ($json && ($status === 400 || $status === 422)) {
			$error_text = strtolower((string) ($decoded['error']['message'] ?? $raw_body));
			$schema_not_supported = str_contains($error_text, 'response_format')
				|| str_contains($error_text, 'json_schema')
				|| str_contains($error_text, 'unsupported');
			if ($schema_not_supported) {
				$this->log('chat:structured_output_fallback_json_object', ['status' => $status, 'model' => $model]);
				$body['response_format'] = ['type' => 'json_object'];
				$response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
					'timeout' => 120,
					'headers' => [
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type' => 'application/json',
					],
					'body' => wp_json_encode($body),
				]);
				if (is_wp_error($response)) {
					$this->log('chat:fallback_wp_error', ['error' => $response->get_error_message()]);
					return $response;
				}
				$status = (int) wp_remote_retrieve_response_code($response);
				$raw_body = (string) wp_remote_retrieve_body($response);
				$decoded = json_decode($raw_body, true);
			}
		}
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$preview = trim(preg_replace('/\s+/', ' ', substr($raw_body, 0, 200)) ?: '');
			$this->log('chat:http_or_decode_error', ['status' => $status, 'body_preview' => $preview]);
			return new \WP_Error('poststation_openrouter_failed', 'OpenRouter request failed. HTTP ' . $status . '. ' . $preview);
		}
		$this->log('chat:ok', ['status' => $status, 'model' => $model]);

		$decoded['resolved_model'] = $model;
		return $decoded;
	}

	/**
	 * Parse model output into a JSON object/array with robust fallback extraction.
	 *
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>|\WP_Error
	 */
	public function extract_json_content(array $response)
	{
		$parsed = $response['choices'][0]['message']['parsed'] ?? null;
		if (is_array($parsed)) {
			return $parsed;
		}

		$content = $this->extract_text_content($response);
		if ($content === '') {
			return new \WP_Error('poststation_openrouter_empty_content', 'OpenRouter returned empty structured output.');
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		$stripped = $this->strip_code_fences($content);
		if ($stripped !== $content) {
			$decoded = json_decode($stripped, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		$candidate = $this->extract_first_json_candidate($stripped);
		if ($candidate !== '') {
			$decoded = json_decode($candidate, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		$preview = trim(preg_replace('/\s+/', ' ', substr($content, 0, 220)) ?: '');
		$this->log('json_parse_error', ['preview' => $preview]);
		return new \WP_Error(
			'poststation_openrouter_invalid_json',
			'OpenRouter returned non-JSON structured output: ' . $preview
		);
	}

	/**
	 * @param array<string,mixed> $response
	 */
	public function extract_text_content(array $response): string
	{
		$content = $response['choices'][0]['message']['content'] ?? '';
		if (is_string($content)) {
			return trim($content);
		}

		if (is_array($content)) {
			$parts = [];
			foreach ($content as $part) {
				if (is_string($part)) {
					$parts[] = $part;
					continue;
				}
				if (!is_array($part)) {
					continue;
				}
				$text = $part['text'] ?? $part['content'] ?? '';
				if (is_string($text) && $text !== '') {
					$parts[] = $text;
				}
			}
			return trim(implode("\n", $parts));
		}

		return '';
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function generate_image(string $prompt, ?string $model = null)
	{
		$api_key = $this->openrouter_service->resolve_api_key();
		if ($api_key === '') {
			$this->log('image:missing_api_key');
			return new \WP_Error('poststation_missing_openrouter_key', 'OpenRouter API key is missing for image generation.');
		}

		$model = trim((string) $model);
		if ($model === '') {
			$model = trim($this->settings_service->get_openrouter_default_image_model());
		}
		if ($model === '') {
			$model = 'sourceful/riverflow-v2-fast';
		}
		$this->log('image:request', ['model' => $model, 'prompt_len' => strlen($prompt)]);

		$response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
			'timeout' => 180,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode([
				'model' => $model,
				'messages' => [
					['role' => 'user', 'content' => $prompt],
				],
				'modalities' => ['image'],
			]),
		]);

		if (is_wp_error($response)) {
			$this->log('image:wp_error', ['error' => $response->get_error_message()]);
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		$decoded = json_decode($raw_body, true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$preview = trim(preg_replace('/\s+/', ' ', substr($raw_body, 0, 200)) ?: '');
			$this->log('image:http_or_decode_error', ['status' => $status, 'body_preview' => $preview]);
			return new \WP_Error('poststation_openrouter_image_failed', 'OpenRouter image generation failed. HTTP ' . $status . '. ' . $preview);
		}
		$this->log('image:ok', ['status' => $status, 'model' => $model]);

		$decoded['resolved_model'] = $model;
		return $decoded;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function log(string $event, array $context = []): void
	{
		if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
			return;
		}

		$line = '[PostStation][OpenRouterClient] ' . $event;
		if (!empty($context)) {
			$line .= ' ' . (wp_json_encode($context) ?: '');
		}
		error_log($line);
	}

	private function strip_code_fences(string $content): string
	{
		$trimmed = trim($content);
		if (!preg_match('/^```/m', $trimmed)) {
			return $trimmed;
		}

		$trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?: $trimmed;
		$trimmed = preg_replace('/\s*```$/', '', $trimmed) ?: $trimmed;
		return trim($trimmed);
	}

	private function extract_first_json_candidate(string $content): string
	{
		$content = trim($content);
		$start_object = strpos($content, '{');
		$start_array = strpos($content, '[');
		$starts = array_values(array_filter([$start_object, $start_array], static fn($v) => $v !== false));
		if (empty($starts)) {
			return '';
		}

		$start = min($starts);
		$open = $content[$start];
		$close = $open === '{' ? '}' : ']';
		$depth = 0;
		$in_string = false;
		$escape = false;
		$length = strlen($content);

		for ($i = $start; $i < $length; $i++) {
			$char = $content[$i];
			if ($in_string) {
				if ($escape) {
					$escape = false;
					continue;
				}
				if ($char === '\\') {
					$escape = true;
					continue;
				}
				if ($char === '"') {
					$in_string = false;
				}
				continue;
			}

			if ($char === '"') {
				$in_string = true;
				continue;
			}
			if ($char === $open) {
				$depth++;
				continue;
			}
			if ($char === $close) {
				$depth--;
				if ($depth === 0) {
					return substr($content, $start, ($i - $start + 1));
				}
			}
		}

		return '';
	}
}
