<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\AiUsageAggregator;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\WorkflowContext;

class InternalLinksStep
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
		$count = max(1, (int) ($payload['content_fields']['body']['internal_links_count'] ?? 4));
		$outline = (array) $context->get('outline', []);
		$sitemap = $this->normalize_sitemap((array) ($payload['sitemap'] ?? []));

		if (empty($sitemap)) {
			$context->set('internal_links', []);
			return;
		}

		$system = $this->prompt_library->load('internal_links.system.txt');
		$user_template = $this->prompt_library->load('internal_links.user.txt');
		$user_prompt = $this->prompt_library->render_with_context($user_template, [
			'topic' => $topic,
			'outline' => [
				'json' => wp_json_encode((array) ($outline['outline'] ?? $outline), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
			],
			'internal_links' => [
				'count' => $count,
				'sitemap_json' => wp_json_encode($sitemap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
			],
		]);

		$model = (string) ($payload['content_fields']['body']['model_id'] ?? '');
		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $user_prompt],
		], $model, true, [
			'type' => 'array',
			'items' => [
				'type' => 'object',
				'properties' => [
					'url' => ['type' => 'string'],
					'title' => ['type' => 'string'],
				],
				'required' => ['url', 'title'],
				'additionalProperties' => true,
			],
		], 'json_schema');
		AiUsageAggregator::append($context, 'internal_links', $this->openrouter->get_last_usage_metrics());

		$selected = [];
		if (!is_wp_error($response)) {
			$decoded = $this->openrouter->extract_json_content($response);
			if (!is_wp_error($decoded)) {
				$selected = is_array($decoded) ? $decoded : (array) ($decoded['links'] ?? []);
			}
		}

		$selected = $this->normalize_selected_links($selected, $sitemap, $count);
		if (count($selected) < $count) {
			$selected = $this->fill_with_fallback($selected, $sitemap, $topic, (array) ($outline['outline'] ?? $outline), $count);
		}

		$context->set('internal_links', array_values($selected));
	}

	/**
	 * @param array<int,mixed> $entries
	 * @return array<int,array{title:string,url:string}>
	 */
	private function normalize_sitemap(array $entries): array
	{
		$normalized = [];
		$seen = [];
		foreach ($entries as $entry) {
			$title = '';
			$url = '';
			if (is_array($entry)) {
				if (isset($entry['title']) || isset($entry['url'])) {
					$title = trim((string) ($entry['title'] ?? ''));
					$url = trim((string) ($entry['url'] ?? ''));
				} else {
					$title = trim((string) ($entry[0] ?? ''));
					$url = trim((string) ($entry[1] ?? ''));
				}
			}
			if ($title === '' || $url === '') {
				continue;
			}
			$key = $this->canonicalize_url($url);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$normalized[] = ['title' => $title, 'url' => $url];
		}
		return $normalized;
	}

	/**
	 * @param array<int,mixed> $selected
	 * @param array<int,array{title:string,url:string}> $sitemap
	 * @return array<int,array{title:string,url:string}>
	 */
	private function normalize_selected_links(array $selected, array $sitemap, int $count): array
	{
		$allowed = [];
		foreach ($sitemap as $item) {
			$allowed[$this->canonicalize_url((string) $item['url'])] = $item;
		}

		$out = [];
		$seen = [];
		foreach ($selected as $item) {
			if (!is_array($item)) {
				continue;
			}
			$url = trim((string) ($item['url'] ?? ''));
			$key = $this->canonicalize_url($url);
			if ($key === '' || !isset($allowed[$key]) || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $allowed[$key];
			if (count($out) >= $count) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param array<int,array{title:string,url:string}> $selected
	 * @param array<int,array{title:string,url:string}> $sitemap
	 * @param array<string,mixed> $outline
	 * @return array<int,array{title:string,url:string}>
	 */
	private function fill_with_fallback(array $selected, array $sitemap, string $topic, array $outline, int $count): array
	{
		$target_tokens = $this->tokenize($topic . ' ' . $this->outline_to_text($outline));
		$selected_keys = [];
		foreach ($selected as $item) {
			$selected_keys[$this->canonicalize_url((string) $item['url'])] = true;
		}

		$scored = [];
		foreach ($sitemap as $item) {
			$key = $this->canonicalize_url((string) $item['url']);
			if ($key === '' || isset($selected_keys[$key])) {
				continue;
			}
			$title_tokens = $this->tokenize((string) $item['title']);
			$score = count(array_intersect($title_tokens, $target_tokens));
			$scored[] = ['score' => $score, 'item' => $item];
		}

		usort($scored, static function (array $a, array $b): int {
			return (int) $b['score'] <=> (int) $a['score'];
		});

		foreach ($scored as $row) {
			$selected[] = $row['item'];
			if (count($selected) >= $count) {
				break;
			}
		}

		return array_slice($selected, 0, $count);
	}

	/**
	 * @param array<string,mixed> $outline
	 */
	private function outline_to_text(array $outline): string
	{
		$parts = [];
		$intro = trim((string) ($outline['introduction'] ?? ''));
		if ($intro !== '') {
			$parts[] = $intro;
		}
		$body = (array) ($outline['body'] ?? []);
		foreach ($body as $section) {
			if (!is_array($section)) {
				continue;
			}
			$parts[] = trim((string) ($section['section_heading'] ?? ''));
			$parts[] = trim((string) ($section['section_content'] ?? ''));
		}
		return trim(implode(' ', array_filter($parts)));
	}

	/**
	 * @return array<int,string>
	 */
	private function tokenize(string $text): array
	{
		$text = strtolower(trim($text));
		if ($text === '') {
			return [];
		}
		$text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text) ?: $text;
		$tokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', $text) ?: [])));
		$tokens = array_values(array_unique($tokens));
		return array_values(array_filter($tokens, static fn(string $t): bool => strlen($t) > 2));
	}

	private function canonicalize_url(string $url): string
	{
		$parts = wp_parse_url($url);
		if (!is_array($parts)) {
			return '';
		}
		$host = strtolower((string) ($parts['host'] ?? ''));
		$path = rtrim((string) ($parts['path'] ?? ''), '/');
		if ($host === '') {
			return '';
		}
		return $host . $path;
	}
}
