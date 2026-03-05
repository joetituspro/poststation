<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\WorkflowContext;

class ExtrasStep
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
		$markdown = (string) $context->get('draft_markdown', '');
		if ($markdown === '') {
			throw new \Exception('Draft markdown is empty.');
		}

		$system = $this->prompt_library->load('extras.system.txt');
		$user_template = $this->prompt_library->load('extras.user.txt');
		$prompt = $this->prompt_library->render($user_template, [
			"{{ $('Normalize Prompts').item.json.topic }}" => (string) ($payload['topic'] ?? ''),
			"{{ $('Webhook').item.json.body.language.name }}" => (string) ($payload['language']['name'] ?? 'English'),
			"{{ $('Normalize Prompts').item.json.point_of_view }}" => (string) ($payload['point_of_view'] ?? 'none'),
			"{{ $('Normalize Prompts').item.json.tone_of_voice }}" => (string) ($payload['tone_of_voice'] ?? 'none'),
			"{{ $('Normalize Prompts').item.json.reading_level }}" => (string) ($payload['readability'] ?? 'grade_8'),
			'{{ $now }}' => $this->prompt_library->now_string(),
			"{{ $('Webhook').item.json.body.instruction_set.instructions.title }}" => (string) ($payload['instruction_set']['instructions']['title'] ?? ''),
			"{{ $('Webhook').item.json.body.content_fields.slug.prompt }}" => (string) ($payload['content_fields']['slug']['prompt'] ?? ''),
			'{{ $json.output }}' => $markdown,
		]);

		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $prompt],
		], (string) ($payload['content_fields']['body']['model_id'] ?? ''), true, [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string'],
				'slug' => ['type' => 'string'],
				'conclusion' => ['type' => 'string'],
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
			'required' => ['title', 'slug', 'conclusion', 'key_takeaways', 'faq'],
			'additionalProperties' => true,
		]);

		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$decoded = $this->openrouter->extract_json_content($response);
		if (is_wp_error($decoded)) {
			throw new \Exception($decoded->get_error_message());
		}

		$title_override = trim((string) ($payload['title_override'] ?? ''));
		$slug_override = trim((string) ($payload['slug_override'] ?? ''));
		$title = $title_override !== '' ? $title_override : (string) ($decoded['title'] ?? $payload['topic'] ?? 'Generated Post');
		$slug = $slug_override !== '' ? $slug_override : (string) ($decoded['slug'] ?? sanitize_title($title));

		$key_takeaways_enabled = (string) ($payload['content_fields']['body']['key_takeaways'] ?? 'no') === 'yes';
		$conclusion_enabled = (string) ($payload['content_fields']['body']['conclusion'] ?? 'no') === 'yes';
		$faq_enabled = (string) ($payload['content_fields']['body']['faq'] ?? 'no') === 'yes';

		$article = $markdown;
		if ($key_takeaways_enabled && !empty($decoded['key_takeaways']) && is_array($decoded['key_takeaways'])) {
			$takes = implode("\n", array_map(static fn($item) => '- ' . trim((string) $item), $decoded['key_takeaways']));
			$article = "## Key Takeaways\n{$takes}\n\n" . $article;
		}
		if ($conclusion_enabled && !empty($decoded['conclusion'])) {
			$article .= "\n\n## Conclusion\n" . trim((string) $decoded['conclusion']);
		}
		if ($faq_enabled && !empty($decoded['faq']) && is_array($decoded['faq'])) {
			$faq_lines = [];
			foreach ($decoded['faq'] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$q = trim((string) ($row['q'] ?? ''));
				$a = trim((string) ($row['a'] ?? ''));
				if ($q === '' || $a === '') {
					continue;
				}
				$faq_lines[] = '### ' . $q . "\n" . $a;
			}
			if (!empty($faq_lines)) {
				$article .= "\n\n## Frequently Asked Questions\n" . implode("\n\n", $faq_lines);
			}
		}

		$html = $this->to_html($article);
		$context->set('post_title', $title);
		$context->set('post_slug', $slug);
		$context->set('post_content_html', $html);
	}

	private function to_html(string $markdown): string
	{
		if (class_exists('\Parsedown')) {
			$parsedown = new \Parsedown();
			return (string) $parsedown->text($markdown);
		}

		// Fallback for environments where Parsedown is not installed yet.
		return wpautop(esc_html($markdown));
	}
}
