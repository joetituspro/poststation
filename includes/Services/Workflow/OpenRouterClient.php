<?php

namespace PostStation\Services\Workflow;

use PostStation\Services\OpenRouterService;
use PostStation\Services\SettingsService;

class OpenRouterClient
{
	private OpenRouterService $openrouter_service;
	private SettingsService $settings_service;
	/** @var array<string,mixed> */
	private array $last_usage_metrics = [];

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
		$this->set_last_usage_metrics($this->blank_usage_metrics('chat', (string) $model));
		$api_key = $this->openrouter_service->resolve_api_key();
		if ($api_key === '') {
			$this->log('chat:missing_api_key');
			$this->set_last_usage_metrics($this->blank_usage_metrics('chat', (string) $model));
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
			$this->set_last_usage_metrics($this->blank_usage_metrics('chat', $model));
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
			$this->set_last_usage_metrics($this->blank_usage_metrics('chat', $model));
			return new \WP_Error('poststation_openrouter_failed', 'OpenRouter request failed. HTTP ' . $status . '. ' . $preview);
		}
		$this->log('chat:ok', ['status' => $status, 'model' => $model]);
		$this->set_last_usage_metrics($this->build_usage_metrics_for_chat($decoded, $messages, $model));

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
		$this->set_last_usage_metrics($this->blank_usage_metrics('image', (string) $model));
		$api_key = $this->openrouter_service->resolve_api_key();
		if ($api_key === '') {
			$this->log('image:missing_api_key');
			$this->set_last_usage_metrics($this->blank_usage_metrics('image', (string) $model));
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
			$this->set_last_usage_metrics($this->blank_usage_metrics('image', $model));
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		$decoded = json_decode($raw_body, true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$preview = trim(preg_replace('/\s+/', ' ', substr($raw_body, 0, 200)) ?: '');
			$this->log('image:http_or_decode_error', ['status' => $status, 'body_preview' => $preview]);
			$this->set_last_usage_metrics($this->blank_usage_metrics('image', $model));
			return new \WP_Error('poststation_openrouter_image_failed', 'OpenRouter image generation failed. HTTP ' . $status . '. ' . $preview);
		}
		$this->log('image:ok', ['status' => $status, 'model' => $model]);
		$this->set_last_usage_metrics($this->build_usage_metrics_for_image($decoded, $prompt, $model));

		$decoded['resolved_model'] = $model;
		return $decoded;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_last_usage_metrics(): array
	{
		return $this->last_usage_metrics;
	}

	public static function is_non_retryable_error_message(string $message): bool
	{
		$needle = strtolower(trim($message));
		if ($needle === '') {
			return false;
		}

		$patterns = [
			'openrouter api key is missing',
			'invalid api key',
			'unauthorized',
			'forbidden',
			'http 401',
			'http 402',
			'http 403',
			'account disabled',
			'billing',
			'payment required',
			'insufficient credits',
			'quota exceeded',
			'invalid structured output',
			'non-json structured output',
			'schema validation failed',
			'invalid request',
			'response_format',
			'json_schema',
		];
		foreach ($patterns as $pattern) {
			if (str_contains($needle, $pattern)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function log(string $event, array $context = []): void
	{
		// Disabled by design: LocalWorkflowRunner emits one consolidated log per step run.
		return;
	}

	/**
	 * @param array<string,mixed> $decoded
	 * @param array<int,array<string,string>> $messages
	 * @return array<string,mixed>
	 */
	private function build_usage_metrics_for_chat(array $decoded, array $messages, string $model): array
	{
		$usage = $this->extract_usage_payload($decoded);
		$prompt_tokens = $this->pick_int($usage, ['prompt_tokens', 'input_tokens']);
		$completion_tokens = $this->pick_int($usage, ['completion_tokens', 'output_tokens']);
		$total_tokens = $this->pick_int($usage, ['total_tokens']);
		$cost_usd = $this->pick_float($usage, ['cost', 'total_cost', 'cost_usd']);
		$tokens_estimated = false;

		if ($prompt_tokens === null) {
			$prompt_text = '';
			foreach ($messages as $msg) {
				$prompt_text .= (string) ($msg['role'] ?? '') . ': ' . (string) ($msg['content'] ?? '') . "\n";
			}
			$prompt_tokens = $this->estimate_tokens($prompt_text);
			$tokens_estimated = true;
		}

		if ($completion_tokens === null) {
			$completion_tokens = $this->estimate_tokens($this->extract_text_content($decoded));
			$tokens_estimated = true;
		}

		if ($total_tokens === null) {
			$total_tokens = (int) $prompt_tokens + (int) $completion_tokens;
			$tokens_estimated = true;
		}

		return [
			'provider' => 'openrouter',
			'type' => 'chat',
			'model' => $model,
			'prompt_tokens' => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens' => $total_tokens,
			'cost_usd' => $cost_usd,
			'tokens_estimated' => $tokens_estimated,
			'cost_estimated' => false,
			'raw_usage_present' => !empty($usage),
		];
	}

	/**
	 * @param array<string,mixed> $decoded
	 * @return array<string,mixed>
	 */
	private function build_usage_metrics_for_image(array $decoded, string $prompt, string $model): array
	{
		$usage = $this->extract_usage_payload($decoded);
		$prompt_tokens = $this->pick_int($usage, ['prompt_tokens', 'input_tokens']);
		$completion_tokens = $this->pick_int($usage, ['completion_tokens', 'output_tokens']);
		$total_tokens = $this->pick_int($usage, ['total_tokens']);
		$cost_usd = $this->pick_float($usage, ['cost', 'total_cost', 'cost_usd']);
		$tokens_estimated = false;

		if ($prompt_tokens === null) {
			$prompt_tokens = $this->estimate_tokens($prompt);
			$tokens_estimated = true;
		}
		if ($completion_tokens === null && $total_tokens === null) {
			$completion_tokens = 0;
			$tokens_estimated = true;
		}
		if ($total_tokens === null) {
			$total_tokens = (int) $prompt_tokens + (int) ($completion_tokens ?? 0);
			$tokens_estimated = true;
		}

		return [
			'provider' => 'openrouter',
			'type' => 'image',
			'model' => $model,
			'prompt_tokens' => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens' => $total_tokens,
			'cost_usd' => $cost_usd,
			'tokens_estimated' => $tokens_estimated,
			'cost_estimated' => false,
			'raw_usage_present' => !empty($usage),
		];
	}

	/**
	 * @param array<string,mixed> $usage
	 * @param array<int,string> $keys
	 */
	private function pick_int(array $usage, array $keys): ?int
	{
		foreach ($keys as $key) {
			if (!array_key_exists($key, $usage)) {
				continue;
			}
			if (is_numeric($usage[$key])) {
				return (int) $usage[$key];
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $usage
	 * @param array<int,string> $keys
	 */
	private function pick_float(array $usage, array $keys): ?float
	{
		foreach ($keys as $key) {
			if (!array_key_exists($key, $usage)) {
				continue;
			}
			if (is_numeric($usage[$key])) {
				return (float) $usage[$key];
			}
		}
		return null;
	}

	private function estimate_tokens(string $text): int
	{
		$text = trim($text);
		if ($text === '') {
			return 0;
		}
		$len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
		return (int) max(1, ceil($len / 4));
	}

	/**
	 * @param array<string,mixed> $decoded
	 * @return array<string,mixed>
	 */
	private function extract_usage_payload(array $decoded): array
	{
		$candidates = [];
		if (isset($decoded['usage']) && is_array($decoded['usage'])) {
			$candidates[] = (array) $decoded['usage'];
		}
		if (isset($decoded['choices'][0]['usage']) && is_array($decoded['choices'][0]['usage'])) {
			$candidates[] = (array) $decoded['choices'][0]['usage'];
		}
		if (isset($decoded['data']['usage']) && is_array($decoded['data']['usage'])) {
			$candidates[] = (array) $decoded['data']['usage'];
		}
		if (isset($decoded['meta']['usage']) && is_array($decoded['meta']['usage'])) {
			$candidates[] = (array) $decoded['meta']['usage'];
		}

		foreach ($candidates as $candidate) {
			if (!empty($candidate)) {
				return $candidate;
			}
		}
		return [];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function blank_usage_metrics(string $type, string $model): array
	{
		return [
			'provider' => 'openrouter',
			'type' => $type,
			'model' => $model,
			'prompt_tokens' => null,
			'completion_tokens' => null,
			'total_tokens' => null,
			'cost_usd' => null,
			'tokens_estimated' => false,
			'cost_estimated' => false,
			'raw_usage_present' => false,
		];
	}

	/**
	 * @param array<string,mixed> $metrics
	 */
	private function set_last_usage_metrics(array $metrics): void
	{
		$this->last_usage_metrics = $metrics;
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
