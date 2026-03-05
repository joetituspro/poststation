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
		$system = $this->prompt_library->load('outline.system.txt');
		$research_mode = strtolower(trim((string) ($payload['content_fields']['body']['research_mode'] ?? 'perplexity')));
		$use_plan_prompt = $research_mode === 'none';
		$user_template = $this->prompt_library->load($use_plan_prompt ? 'outline.user.plan.txt' : 'outline.user.research.txt');
		$prompt = $this->prompt_library->render_with_context($user_template, [
			'payload' => $payload,
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
