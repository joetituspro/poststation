<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\WorkflowContext;

class WritingStep
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
		$outline = (array) $context->get('outline', []);
		$analysis = (array) $context->get('analysis', []);
		$preliminary_plan = (array) $context->get('preliminary_plan', []);
		$research_items = (array) $context->get('research_items', []);
		$research_text = implode("\n\n", array_map(static function ($item): string {
			return (string) ($item['full_article'] ?? '');
		}, $research_items));

		$internal_links_raw = $context->get('internal_links', null);
		$internal_links = is_array($internal_links_raw) ? $internal_links_raw : [];
		if (!is_array($internal_links_raw)) {
			$internal_links = $this->normalize_payload_sitemap((array) ($payload['sitemap'] ?? []));
		}

		$system = $this->prompt_library->load('writer.system.txt');
		$research_mode = strtolower(trim((string) ($payload['content_fields']['body']['research_mode'] ?? 'perplexity')));
		$use_plan_prompt = $research_mode === 'none';
		$user_template = $this->prompt_library->load($use_plan_prompt ? 'writer.user.plan.txt' : 'writer.user.research.txt');
		$prompt = $this->prompt_library->render_with_context($user_template, [
			'payload' => $payload,
			'analysis' => [
				'structure_type' => (string) ($analysis['structure_type'] ?? ''),
				'json' => wp_json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
			],
			'preliminary_plan' => [
				'json' => wp_json_encode($preliminary_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
				'structure_type' => (string) ($preliminary_plan['structure_type'] ?? ''),
			],
			'internal_links' => [
				'json' => wp_json_encode($internal_links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
			],
			'outline' => [
				'json' => wp_json_encode((array) ($outline['outline'] ?? $outline), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
			],
			'research' => [
				'data' => $research_text,
			],
			'now' => $this->prompt_library->now_string(),
		]);

		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $prompt],
		], (string) ($payload['content_fields']['body']['model_id'] ?? ''));

		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$draft = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
		if ($draft === '') {
			throw new \Exception('Writer returned empty draft.');
		}

		$context->set('draft_markdown', $draft);
	}

	/**
	 * @param array<int,mixed> $entries
	 * @return array<int,array{title:string,url:string}>
	 */
	private function normalize_payload_sitemap(array $entries): array
	{
		$out = [];
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			if (isset($entry['title']) || isset($entry['url'])) {
				$title = trim((string) ($entry['title'] ?? ''));
				$url = trim((string) ($entry['url'] ?? ''));
			} else {
				$title = trim((string) ($entry[0] ?? ''));
				$url = trim((string) ($entry[1] ?? ''));
			}
			if ($title === '' || $url === '') {
				continue;
			}
			$out[] = ['title' => $title, 'url' => $url];
		}
		return $out;
	}
}
