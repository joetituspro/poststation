<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\WorkflowContext;

class OutlineStep
{
	private OpenRouterClient $openrouter;
	private N8nPromptLibrary $prompt_library;

	public function __construct(?OpenRouterClient $openrouter = null, ?N8nPromptLibrary $prompt_library = null)
	{
		$this->openrouter = $openrouter ?? new OpenRouterClient();
		$this->prompt_library = $prompt_library ?? new N8nPromptLibrary();
	}

	/**
	 * @param array<string,mixed> $spec
	 */
	public function run(WorkflowContext $context, array $spec): void
	{
		$payload = (array) $context->get('payload', []);
		$analysis = (array) $context->get('analysis', []);
		$preliminary_plan = (array) $context->get('preliminary_plan', []);
		$research_items = (array) $context->get('research_items', []);
		$research_text = implode("\n\n", array_map(
			static fn($item) => (string) ($item['full_article'] ?? ''),
			$research_items
		));
		$research_mode = strtolower(trim((string) ($payload['content_fields']['body']['research_mode'] ?? 'perplexity')));
		$user_template = $this->prompt_library->load('outline.user.txt');
		$flags = [
			'realtime_none' => $research_mode === 'none',
			'has_research' => trim($research_text) !== '',
			'has_preliminary_plan' => !empty($preliminary_plan),
		];
		$system_template = $this->prompt_library->load('outline.system.txt');
		$system = $this->prompt_library->render_with_context($system_template, [
			'flags' => $flags,
		]);
		$prompt = $this->prompt_library->render_with_context($user_template, [
			'payload' => $payload,
			'flags' => $flags,
			'analysis' => [
				'json' => wp_json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
			],
			'preliminary_plan' => [
				'json' => wp_json_encode($preliminary_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
			],
			'research' => [
				'data' => $research_text,
			],
			'now' => $this->prompt_library->now_string(),
		]);

		error_log('Outline prompt: ' . $prompt);
		error_log('Outline system: ' . $system);

		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $prompt],
		], (string) ($payload['content_fields']['body']['model_id'] ?? ''), true, [
			'type' => 'object',
			'properties' => [
				'outline' => [
					'type' => 'object',
					'properties' => [
						'introduction' => ['type' => 'string'],
						'body' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'section_heading' => ['type' => 'string'],
									'section_content' => ['type' => 'string'],
								],
								'required' => ['section_heading', 'section_content'],
								'additionalProperties' => true,
							],
						],
						'conclusion' => ['type' => 'string'],
					],
					'required' => ['introduction', 'body', 'conclusion'],
					'additionalProperties' => true,
				],
				'key_takeaways' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
				],
				'faq' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'q' => ['type' => 'string'],
							'a' => ['type' => 'string'],
						],
						'required' => ['q', 'a'],
						'additionalProperties' => true,
					],
				],
			],
			'required' => ['outline', 'key_takeaways', 'faq'],
			'additionalProperties' => true,
		]);

		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$decoded = $this->openrouter->extract_json_content($response);
		if (is_wp_error($decoded)) {
			throw new \Exception($decoded->get_error_message());
		}

		$this->validate_outline_payload($decoded);
		$context->set('outline', $decoded);
	}

	/**
	 * @param array<string,mixed> $decoded
	 */
	private function validate_outline_payload(array $decoded): void
	{
		if (!isset($decoded['outline']) || !is_array($decoded['outline'])) {
			throw new \Exception('Outline schema validation failed: "outline" must be an object.');
		}
		$outline = (array) $decoded['outline'];
		if (!isset($outline['introduction']) || !is_string($outline['introduction']) || trim($outline['introduction']) === '') {
			throw new \Exception('Outline schema validation failed: "outline.introduction" must be a non-empty string.');
		}
		if (!isset($outline['body']) || !is_array($outline['body'])) {
			throw new \Exception('Outline schema validation failed: "outline.body" must be an array.');
		}
		foreach ((array) $outline['body'] as $index => $row) {
			if (!is_array($row)) {
				throw new \Exception('Outline schema validation failed: each "outline.body" item must be an object.');
			}
			$heading = $row['section_heading'] ?? null;
			$content = $row['section_content'] ?? null;
			if (!is_string($heading) || !is_string($content) || trim($content) === '') {
				throw new \Exception('Outline schema validation failed: each body item needs string "section_heading" and non-empty "section_content" at index ' . $index . '.');
			}
		}
		if (!isset($outline['conclusion']) || !is_string($outline['conclusion']) || trim($outline['conclusion']) === '') {
			throw new \Exception('Outline schema validation failed: "outline.conclusion" must be a non-empty string.');
		}

		if (!isset($decoded['key_takeaways']) || !is_array($decoded['key_takeaways'])) {
			throw new \Exception('Outline schema validation failed: "key_takeaways" must be an array of strings.');
		}
		foreach ((array) $decoded['key_takeaways'] as $index => $takeaway) {
			if (!is_string($takeaway) || trim($takeaway) === '') {
				throw new \Exception('Outline schema validation failed: "key_takeaways" must contain non-empty strings at index ' . $index . '.');
			}
		}

		if (!isset($decoded['faq']) || !is_array($decoded['faq'])) {
			throw new \Exception('Outline schema validation failed: "faq" must be an array.');
		}
		foreach ((array) $decoded['faq'] as $index => $faq) {
			if (!is_array($faq)) {
				throw new \Exception('Outline schema validation failed: each "faq" item must be an object.');
			}
			$q = $faq['q'] ?? null;
			$a = $faq['a'] ?? null;
			if (!is_string($q) || trim($q) === '' || !is_string($a) || trim($a) === '') {
				throw new \Exception('Outline schema validation failed: each FAQ item must include non-empty "q" and "a" at index ' . $index . '.');
			}
		}
	}
}
