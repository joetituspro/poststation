<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\AiUsageAggregator;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\WorkflowContext;

class PreliminaryPlanStep
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
		$topic = $this->resolve_topic($payload, $context);

		if ($topic === '') {
			throw new \Exception('Unable to build preliminary plan because topic is empty.');
		}

		$system = $this->prompt_library->load('preliminary_plan.system.txt');
		$user_template = $this->prompt_library->load('preliminary_plan.user.txt');
		$prompt = $this->prompt_library->render_with_context($user_template, [
			'payload' => array_merge($payload, ['topic' => $topic]),
			'now' => $this->prompt_library->now_string(),
		]);

		$response = $this->openrouter->chat(
			[
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => $prompt],
			],
			'perplexity/sonar-pro',
			true,
			[
				'type' => 'object',
				'properties' => [
					'topic' => ['type' => 'string'],
					'structure_type' => ['type' => 'string'],
					'search_intent' => ['type' => 'string'],
					'intent_rationale' => ['type' => 'string'],
					'search_intent_summary' => ['type' => 'string'],
					'recommended_sections' => ['type' => 'array', 'items' => ['type' => 'string']],
					'dominant_ranking_angles' => ['type' => 'array', 'items' => ['type' => 'string']],
					'google_rewards' => ['type' => 'array', 'items' => ['type' => 'string']],
					'semantic_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
					'data_points_and_statistics' => ['type' => 'array', 'items' => ['type' => 'string']],
					'common_user_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
					'content_gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
					'differentiation_opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
				],
				'required' => [
					'topic',
					'structure_type',
					'search_intent',
					'intent_rationale',
					'search_intent_summary',
					'recommended_sections',
					'dominant_ranking_angles',
					'google_rewards',
					'semantic_keywords',
					'data_points_and_statistics',
					'common_user_questions',
					'content_gaps',
					'differentiation_opportunities',
				],
				'additionalProperties' => true,
			],
			'json_schema'
		);
		AiUsageAggregator::append($context, 'preliminary_plan', $this->openrouter->get_last_usage_metrics());
		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$decoded = $this->openrouter->extract_json_content($response);
		if (is_wp_error($decoded)) {
			throw new \Exception($decoded->get_error_message());
		}

		$context->set('preliminary_plan', $decoded);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function resolve_topic(array $payload, WorkflowContext $context): string
	{
		$topic = trim((string) ($payload['topic'] ?? ''));
		if ($topic !== '') {
			return $topic;
		}

		$research_items = (array) $context->get('research_items', []);
		if (!empty($research_items) && is_array($research_items[0])) {
			$item_title = trim((string) ($research_items[0]['title'] ?? ''));
			if ($item_title !== '') {
				return $item_title;
			}
		}

		$targets = (array) $context->get('research_targets', []);
		if (!empty($targets) && is_array($targets[0])) {
			$target_title = trim((string) ($targets[0]['title'] ?? ''));
			if ($target_title !== '') {
				return $target_title;
			}
			$url = trim((string) ($targets[0]['url'] ?? ''));
			if ($url !== '') {
				return $url;
			}
		}

		return '';
	}
}
