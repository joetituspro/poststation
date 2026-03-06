<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\AiUsageAggregator;
use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\StepDeferredException;
use PostStation\Services\Workflow\WorkflowContext;

class CustomFieldsStep
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
		$fields = array_values(array_filter((array) ($payload['content_fields']['custom_fields'] ?? []), 'is_array'));
		$topic = (string) ($payload['topic'] ?? '');
		$article = (string) $context->get('post_content_html', '');

		$results = (array) $context->get('custom_fields', []);
		$state = (array) $context->get('custom_fields_state', []);
		$current_index = isset($state['current_index']) ? (int) $state['current_index'] : 0;
		$total = count($fields);
		if ($total === 0) {
			$context->set('custom_fields', $results);
			$context->remove('custom_fields_state');
			return;
		}

		if ($current_index >= $total) {
			$context->set('custom_fields', $results);
			$context->remove('custom_fields_state');
			return;
		}

		$field = (array) ($fields[$current_index] ?? []);
		$meta_key = trim((string) ($field['meta_key'] ?? ''));
		$prompt = trim((string) ($field['prompt'] ?? ''));
		if ($meta_key !== '' && $prompt !== '') {
			$context_mode = (string) ($field['prompt_context'] ?? 'none');
			$attached = '';
			if ($context_mode === 'topic' || $context_mode === 'article_and_topic') {
				$attached .= "Topic:\n{$topic}\n\n";
			}
			if ($context_mode === 'article' || $context_mode === 'article_and_topic') {
				$attached .= "Article:\n{$article}\n\n";
			}

			$model = (string) ($field['model_id'] ?? '');
			$user_template = $this->prompt_library->load('custom_field.user.txt');
			$user_prompt = $this->prompt_library->render_with_context($user_template, [
				'custom_field' => [
					'prompt' => $prompt,
					'combined_content' => trim($attached),
				],
				'payload' => $payload,
				'now' => $this->prompt_library->now_string(),
			]);

			$response = $this->openrouter->chat([
				['role' => 'user', 'content' => $user_prompt],
			], $model);
			AiUsageAggregator::append($context, 'custom_fields', $this->openrouter->get_last_usage_metrics());
			if (!is_wp_error($response)) {
				$text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
				if ($text !== '') {
					$results[$meta_key] = $text;
				}
			}
		}

		$next_index = $current_index + 1;
		$context->set('custom_fields', $results);
		$context->set('custom_fields_state', [
			'current_index' => $next_index,
			'total' => $total,
		]);

		if ($next_index < $total) {
			throw new StepDeferredException(sprintf('Custom fields paused (%d/%d); continuing on next tick.', $next_index, $total));
		}

		$context->remove('custom_fields_state');
	}
}
