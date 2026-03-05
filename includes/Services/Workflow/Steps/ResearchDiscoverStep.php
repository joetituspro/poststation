<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\WorkflowContext;

class ResearchDiscoverStep
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
		$research_url = trim((string) ($payload['research_url'] ?? ''));

		// Rewrite mode: direct target URL.
		if ($research_url !== '') {
			if ($this->is_youtube_url($research_url)) {
				throw new \Exception('Research URL cannot be a YouTube link. Please use a normal article URL.');
			}
			$context->set('research_domain', (string) parse_url($research_url, PHP_URL_HOST));
			$context->set('research_items', []);
			$context->remove('research_scrape_state');
			$context->set('research_targets', [[
				'title' => '',
				'url' => $research_url,
			]]);
			return;
		}

		$sources_count = max(1, (int) ($payload['content_fields']['body']['sources_count'] ?? 3));
		$system = $this->prompt_library->load('research.system.txt');
		$user_template = $this->prompt_library->load('research.user.txt');
		$prompt = $this->prompt_library->render_with_context($user_template, [
			'payload' => $payload,
			'topic' => $topic,
			'sources_count' => $sources_count,
			'now' => $this->prompt_library->now_string(),
		]);

		$response = $this->openrouter->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $prompt],
		], 'perplexity/sonar-pro', true, [
			'type' => 'object',
			'properties' => [
				'topic' => ['type' => 'string'],
				'standard_research' => ['type' => 'string'],
				'articles' => [
					'type' => 'array',
					'minItems' => $sources_count,
					'maxItems' => $sources_count,
					'items' => [
						'type' => 'object',
						'properties' => [
							'title' => ['type' => 'string'],
							'url' => ['type' => 'string'],
						],
						'required' => ['title', 'url'],
						'additionalProperties' => true,
					],
				],
			],
			'required' => ['topic', 'standard_research', 'articles'],
			'additionalProperties' => true,
		], 'json_schema');
		if (is_wp_error($response)) {
			throw new \Exception($response->get_error_message());
		}

		$parsed = $this->openrouter->extract_json_content($response);
		if (is_wp_error($parsed)) {
			throw new \Exception($parsed->get_error_message());
		}

		$articles = is_array($parsed['articles'] ?? null) ? $parsed['articles'] : [];
		$targets = $this->normalize_research_targets($articles);
		$standard_research = trim((string) ($parsed['standard_research'] ?? ''));

		if (empty($targets)) {
			throw new \Exception('Research discovery returned no valid non-YouTube article URLs.');
		}

		$first_url = (string) ($targets[0]['url'] ?? '');
		if ($first_url !== '') {
			$context->set('research_domain', (string) parse_url($first_url, PHP_URL_HOST));
		}
		$context->set('research_items', []);
		$context->remove('research_scrape_state');
		$context->set('research_standard', $standard_research);
		$context->set('research_targets', $targets);
	}

	/**
	 * @param array<int,mixed> $articles
	 * @return array<int,array{title:string,url:string}>
	 */
	private function normalize_research_targets(array $articles): array
	{
		$seen = [];
		$targets = [];
		foreach ($articles as $article) {
			if (!is_array($article)) {
				continue;
			}
			$url = trim((string) ($article['url'] ?? ''));
			if ($url === '' || $this->is_youtube_url($url)) {
				continue;
			}

			$canonical = $this->canonicalize_url($url);
			if ($canonical === '' || isset($seen[$canonical])) {
				continue;
			}
			$seen[$canonical] = true;

			$targets[] = [
				'title' => trim((string) ($article['title'] ?? '')) ?: $url,
				'url' => $url,
			];
		}

		return $targets;
	}

	private function is_youtube_url(string $url): bool
	{
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));
		if ($host === '') {
			return false;
		}
		return str_contains($host, 'youtube.com') || $host === 'youtu.be';
	}

	private function canonicalize_url(string $url): string
	{
		$parts = wp_parse_url($url);
		if (!is_array($parts)) {
			return '';
		}

		$host = strtolower((string) ($parts['host'] ?? ''));
		$path = (string) ($parts['path'] ?? '');
		if ($host === '') {
			return '';
		}

		$path = rtrim($path, '/');
		return $host . $path;
	}
}
