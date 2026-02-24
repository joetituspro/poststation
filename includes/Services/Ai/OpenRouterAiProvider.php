<?php

namespace PostStation\Services\Ai;

use PostStation\Services\OpenRouterService;

class OpenRouterAiProvider implements AiProviderInterface
{
	private OpenRouterService $openrouter_service;

	public function __construct(?OpenRouterService $openrouter_service = null)
	{
		$this->openrouter_service = $openrouter_service ?? new OpenRouterService();
	}

	public function get_key(): string
	{
		return 'openrouter';
	}

	/**
	 * @return array|\WP_Error
	 */
	public function generate_instruction_preset(string $brief, array $context = [])
	{
		$api_key = $this->openrouter_service->resolve_api_key();
		if ($api_key === '') {
			return new \WP_Error('missing_openrouter_key', 'OpenRouter API key is missing.');
		}

		$model = trim((string) get_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, ''));
		if ($model === '') {
			$model = 'openai/gpt-4.1-mini';
		}

		$article_url = trim((string) ($context['article_url'] ?? ''));
		$article_excerpt = trim((string) ($context['article_excerpt'] ?? ''));

		$system_prompt = implode("\n", [
			'You generate WordPress content instruction presets.',
			'Return only valid JSON with this exact schema:',
			'{',
			'  "key": "snake_case_key",',
			'  "name": "Human readable name",',
			'  "description": "Short description",',
			'  "instructions": {',
			'    "title": "Instruction for generating the post title",',
			'    "body": "Instruction for generating the post body"',
			'  }',
			'}',
			'Rules:',
			'- key must be lowercase snake_case and 3-40 chars.',
			'- Title/body instructions must be concrete and production-ready.',
			'- Keep description under 180 chars.',
			'- No markdown, no extra keys, no commentary.',
		]);

		$user_prompt = "User request:\n" . $brief . "\n";
		if ($article_url !== '') {
			$user_prompt .= "\nSample URL:\n" . $article_url . "\n";
		}
		if ($article_excerpt !== '') {
			$user_prompt .= "\nSample article excerpt:\n" . $article_excerpt . "\n";
		}

		$response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode([
				'model' => $model,
				'temperature' => 0.4,
				'messages' => [
					['role' => 'system', 'content' => $system_prompt],
					['role' => 'user', 'content' => $user_prompt],
				],
			]),
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($status < 200 || $status >= 300 || !is_array($body)) {
			return new \WP_Error('openrouter_request_failed', 'Failed to generate preset with OpenRouter.');
		}

		$content = (string) ($body['choices'][0]['message']['content'] ?? '');
		if ($content === '') {
			return new \WP_Error('invalid_ai_response', 'AI returned an empty response.');
		}

		$parsed = $this->parse_json_from_text($content);
		if (!is_array($parsed)) {
			return new \WP_Error('invalid_ai_json', 'AI response was not valid JSON.');
		}

		$key = sanitize_key((string) ($parsed['key'] ?? ''));
		$name = sanitize_text_field((string) ($parsed['name'] ?? ''));
		$description = sanitize_textarea_field((string) ($parsed['description'] ?? ''));
		$title_instruction = sanitize_textarea_field((string) ($parsed['instructions']['title'] ?? ''));
		$body_instruction = sanitize_textarea_field((string) ($parsed['instructions']['body'] ?? ''));

		if ($key === '') {
			$key = sanitize_key($name);
		}
		if ($key === '') {
			$key = 'custom_instruction';
		}
		if ($name === '') {
			$name = ucwords(str_replace('_', ' ', $key));
		}
		if ($title_instruction === '' || $body_instruction === '') {
			return new \WP_Error('invalid_ai_fields', 'AI response is missing title/body instructions.');
		}

		return [
			'provider' => $this->get_key(),
			'model' => $model,
			'key' => $key,
			'name' => $name,
			'description' => $description,
			'instructions' => [
				'title' => $title_instruction,
				'body' => $body_instruction,
			],
		];
	}

	/**
	 * @return array|null
	 */
	private function parse_json_from_text(string $text): ?array
	{
		$decoded = json_decode($text, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		if ($start === false || $end === false || $end <= $start) {
			return null;
		}

		$snippet = substr($text, $start, $end - $start + 1);
		$decoded = json_decode($snippet, true);
		return is_array($decoded) ? $decoded : null;
	}
}

