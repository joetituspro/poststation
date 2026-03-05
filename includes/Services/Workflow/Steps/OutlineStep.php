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
		$research_items = (array) $context->get('research_items', []);
		$research_text = implode("\n\n", array_map(
			static fn($item) => (string) ($item['full_article'] ?? ''),
			$research_items
		));
		$system = $this->prompt_library->load('outline.system.txt');
		$user_template = $this->prompt_library->load('outline.user.txt');
		$prompt = $this->prompt_library->render($user_template, [
			"{{ $('Normalize Prompts').item.json.topic }}" => (string) ($payload['topic'] ?? ''),
			"{{ $('Normalize Prompts').item.json.keywords }}" => (string) ($payload['keywords'] ?? ''),
			"{{ $('Webhook').item.json.body.language.name }}" => (string) ($payload['language']['name'] ?? 'English'),
			"{{ $('Normalize Prompts').item.json.point_of_view }}" => (string) ($payload['point_of_view'] ?? 'none'),
			"{{ $('Normalize Prompts').item.json.tone_of_voice }}" => (string) ($payload['tone_of_voice'] ?? 'none'),
			"{{ $('Normalize Prompts').item.json.reading_level }}" => (string) ($payload['readability'] ?? 'grade_8'),
			'{{ $now }}' => $this->prompt_library->now_string(),
			"{{ $('Webhook').item.json.body.content_fields.body.prompt }}" => (string) ($payload['content_fields']['body']['prompt'] ?? ''),
			"{{ JSON.stringify($('Competitive Intelligence').item.json.output, null, 2) }}" => wp_json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
			"{{ $('Normalize Prompts').item.json.research }}" => $research_text,
		]);

		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $prompt],
		], (string) ($payload['content_fields']['body']['model_id'] ?? ''), true, [
			'type' => 'object',
			'properties' => [
				'outline' => [
					'type' => 'object',
					'additionalProperties' => true,
				],
			],
			'required' => ['outline'],
			'additionalProperties' => true,
		]);

		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$decoded = $this->openrouter->extract_json_content($response);
		if (is_wp_error($decoded)) {
			throw new \Exception($decoded->get_error_message());
		}

		$context->set('outline', $decoded);
	}
}
