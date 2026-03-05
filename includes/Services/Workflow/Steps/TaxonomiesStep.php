<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\StepDeferredException;
use PostStation\Services\Workflow\WorkflowContext;

class TaxonomiesStep
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
		$taxonomies = array_values(array_filter((array) ($payload['content_fields']['taxonomies'] ?? []), 'is_array'));
		$article = (string) $context->get('post_content_html', '');
		$language = (string) (($payload['language']['name'] ?? 'English'));

		$results = (array) $context->get('taxonomies', []);
		$state = (array) $context->get('taxonomies_state', []);
		$current_index = isset($state['current_index']) ? (int) $state['current_index'] : 0;
		$total = count($taxonomies);
		if ($total === 0) {
			$context->set('taxonomies', $results);
			$context->remove('taxonomies_state');
			return;
		}
		if ($current_index >= $total) {
			$context->set('taxonomies', $results);
			$context->remove('taxonomies_state');
			return;
		}

		$tax = (array) ($taxonomies[$current_index] ?? []);
		$taxonomy = sanitize_key((string) ($tax['taxonomy'] ?? ''));
		$mode = (string) ($tax['mode'] ?? 'manual');
		if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
			if ($mode === 'manual') {
				$selected = (array) ($tax['selected'] ?? []);
				if (!empty($selected)) {
					$results[$taxonomy] = implode(', ', array_map('strval', $selected));
				}
			} else {
				$term_count = max(1, (int) ($tax['term_count'] ?? 3));
				$model = (string) ($tax['model_id'] ?? '');
				$prompt = trim((string) ($tax['prompt'] ?? ''));
				$available_terms = (string) ($tax['available_terms'] ?? '');
				$plural_label = (string) ($tax['plural_label'] ?? $taxonomy);
				$template = $mode === 'auto_select'
					? $this->prompt_library->load('tax_choose.user.txt')
					: $this->prompt_library->load('tax_generate.user.txt');
				$user_prompt = $this->prompt_library->render($template, [
					'{{ $json.term_count }}' => (string) $term_count,
					'{{ $json.plural_label }}' => $plural_label,
					'{{ $json.article }}' => $article,
					'{{ $json.language }}' => $language,
					'{{ $json.available_terms }}' => $available_terms,
					'{{ $json.prompt }}' => $prompt,
					'{{ $now }}' => $this->prompt_library->now_string(),
				]);
				$response = $this->openrouter->chat([
					['role' => 'user', 'content' => $user_prompt],
				], $model);
				if (!is_wp_error($response)) {
					$text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
					if ($text !== '') {
						$results[$taxonomy] = $this->normalize_terms_csv($text, $term_count);
					}
				}
			}
		}

		$next_index = $current_index + 1;
		$context->set('taxonomies', $results);
		$context->set('taxonomies_state', [
			'current_index' => $next_index,
			'total' => $total,
		]);
		if ($next_index < $total) {
			throw new StepDeferredException(sprintf('Taxonomies paused (%d/%d); continuing on next tick.', $next_index, $total));
		}
		$context->remove('taxonomies_state');
	}

	private function normalize_terms_csv(string $text, int $limit): string
	{
		$parts = array_values(array_filter(array_map('trim', explode(',', $text))));
		$parts = array_slice(array_unique($parts), 0, $limit);
		return implode(', ', $parts);
	}
}
