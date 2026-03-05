<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\WorkflowContext;

class AnalysisStep
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
		$topic = trim((string) ($payload['topic'] ?? ''));
		$research_items = (array) $context->get('research_items', []);
		$research_text = implode("\n\n", array_map(static function ($item): string {
			return (string) ($item['full_article'] ?? '');
		}, $research_items));

		$system = $this->prompt_library->render_with_context(
			$this->prompt_library->load('analysis.system.txt'),
			['now' => $this->prompt_library->now_string()]
		);
		// n8n analysis agent gets structured competitor data as its direct input payload.
		$prompt = wp_json_encode([
			'topic' => $topic,
			'articles' => $research_items,
			'research' => $research_text,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $prompt],
		], null, true, [
			'type' => 'object',
			'properties' => [
				'summary' => ['type' => 'string'],
				'insights' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
				],
			],
			'additionalProperties' => true,
		]);
		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$decoded = $this->openrouter->extract_json_content($response);
		if (is_wp_error($decoded)) {
			throw new \Exception($decoded->get_error_message());
		}

		$context->set('analysis', $decoded);
	}
}
