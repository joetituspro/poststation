<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\AiUsageAggregator;
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
		$prompt = $this->prompt_library->render_with_context($user_template, [
			'payload' => $payload,
			'draft' => [
				'markdown' => $markdown,
			],
			'now' => $this->prompt_library->now_string(),
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
		AiUsageAggregator::append($context, 'extras', $this->openrouter->get_last_usage_metrics());

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
			$key_takeaways_block = "## Key Takeaways\n{$takes}";
			$article = $this->insert_key_takeaways_block($article, $key_takeaways_block);
		}
		if ($conclusion_enabled && !empty($decoded['conclusion'])) {
			$article .= "\n\n" . trim((string) $decoded['conclusion']);
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

	private function insert_key_takeaways_block(string $article, string $block): string
	{
		$article = str_replace("\r\n", "\n", $article);
		$block = trim($block);
		if ($block === '') {
			return $article;
		}

		// Preferred placement: before the first H2/H3 heading.
		if (preg_match('/^###?\s+/m', $article, $matches, PREG_OFFSET_CAPTURE) === 1) {
			$pos = (int) ($matches[0][1] ?? 0);
			$before = rtrim(substr($article, 0, $pos));
			$after = ltrim(substr($article, $pos));
			return $before . "\n\n" . $block . "\n\n" . $after;
		}

		// Fallback: after the second paragraph block when no H2/H3 exists.
		$parts = preg_split('/\n\s*\n/', trim($article));
		if (!is_array($parts) || empty($parts)) {
			return $block . "\n\n" . trim($article);
		}

		if (count($parts) >= 2) {
			array_splice($parts, 2, 0, [$block]);
			return implode("\n\n", $parts);
		}

		return trim($article) . "\n\n" . $block;
	}
}
