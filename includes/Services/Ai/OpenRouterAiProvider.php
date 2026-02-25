<?php

namespace PostStation\Services\Ai;

use PostStation\Services\OpenRouterService;

class OpenRouterAiProvider implements AiProviderInterface
{
	private const DESCRIPTION_MAX_LENGTH = 80;
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
	public function generate_instruction_preset(string $brief, array $context = [], array $options = [])
	{
		$api_key = $this->openrouter_service->resolve_api_key();
		if ($api_key === '') {
			return new \WP_Error('missing_openrouter_key', 'OpenRouter API key is missing.');
		}

		$model = trim((string) ($options['model'] ?? ''));
		if ($model === '') {
			$model = trim((string) get_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, ''));
		}
		if ($model === '') {
			$model = 'openai/gpt-4.1-mini';
		}

		$article_url = trim((string) ($context['article_url'] ?? ''));
		$article_excerpt = trim((string) ($context['article_excerpt'] ?? ''));

		$system_prompt = <<<'PROMPT'
You are an Article Structure & Style Architect.

Goal:
- Analyze the user's requested format, preferences, and optional reference content.
- Produce a reusable structure-and-style blueprint for AI article generation.
- Write instructions as a generalized template for future articles in the same format, not as directions for one specific article.

Possible user inputs:
- Format description (for example: Amazon-style review, comparison, listicle).
- Structural preferences (for example: short paragraphs, strong hook, FAQ).
- Full reference article text (plain text or HTML).
- A reference URL.
- Any combination of the above.

Input handling:
1) Format description only:
- Build a professional, SEO-optimized structure aligned to search intent.
- Ensure logical section progression.

2) Reference article text:
- Analyze heading hierarchy (H1/H2/H3), section flow, intro style, paragraph length, formatting patterns (bullets/tables/FAQ), tone, authority signals, and CTA placement.
- Generalize patterns. Do not copy wording.

3) Reference URL:
- Treat URL as a reference article and abstract structural/stylistic patterns.
- Focus on framework, formatting logic, tone consistency, and sequencing.
- Do not mention the source brand/site and do not replicate phrasing.

4) Description + reference:
- Combine best practices with reference analysis.
- Preserve strong patterns and improve weak parts.

Output requirements:
- Return only JSON.
- No markdown, no prose, no code fences, no preface/suffix text.
- Must be valid JSON object with exactly this shape:
{
  "key": "snake_case_key",
  "name": "Human readable name",
  "description": "Short description",
  "instructions": {
    "title": "Instruction for generating the post title",
    "body": "Instruction for generating the post body"
  }
}

Field guidance:
- key: lowercase snake_case, descriptive, 3-60 chars.
- name: clean human-readable format name.
- description: strict hard limit of 80 characters total.
- description must be concise, reusable, and preferably 60-75 characters.
- before returning, count description characters; if > 80, rewrite until <= 80.
- instructions.title: include SEO positioning, keyword placement, emotional trigger guidance (if relevant), length guidance, and title pattern examples.
- instructions.body: focus only on body content elements. Do not include H1/title-writing guidance.
- instructions.body must start with introduction guidance, then section flow (H2/H3 and body content patterns), and end with conclusion guidance.
- instructions.body should include tone/voice, paragraph length guidance, formatting rules (bullets/tables/FAQ/bold), comparison-benefit logic (if relevant), SEO guidance, internal linking guidance (if relevant), CTA placement strategy, and any distinctive generalized pattern from references.
- Do not include table-of-contents instructions.
- Assume the writer will receive a separate topic/keyword input later; do not hard-code specific companies, brands, organizations, products, or proper nouns in reusable instructions.

If user input is vague, infer the most logical professional structure from best practices.
All instructions must be evergreen and reusable across future articles, while allowing topic-specific details to be filled later.
PROMPT;

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
				'response_format' => [
					'type' => 'json_object',
				],
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

		$normalized = $this->normalize_ai_payload($parsed);
		if (is_wp_error($normalized)) {
			return $normalized;
		}

		return [
			'provider' => $this->get_key(),
			'model' => $model,
			'key' => $normalized['key'],
			'name' => $normalized['name'],
			'description' => $normalized['description'],
			'instructions' => [
				'title' => $normalized['instructions']['title'],
				'body' => $normalized['instructions']['body'],
			],
		];
	}

	/**
	 * @return array|\WP_Error
	 */
	private function normalize_ai_payload(array $parsed)
	{
		$key = sanitize_key((string) ($parsed['key'] ?? ''));
		$name = sanitize_text_field((string) ($parsed['name'] ?? ''));
		$description = sanitize_textarea_field((string) ($parsed['description'] ?? ''));
		if (function_exists('mb_substr')) {
			$description = (string) mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
		} else {
			$description = substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
		}
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
