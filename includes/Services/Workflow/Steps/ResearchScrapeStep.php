<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Models\TaskExecutionState;
use PostStation\Services\SettingsService;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\StepDeferredException;
use PostStation\Services\Workflow\WorkflowProgressService;
use PostStation\Services\Workflow\WorkflowContext;

class ResearchScrapeStep
{
	private const CLEANUP_PRIMARY_MODEL = 'google/gemini-2.5-flash-lite';
	private const CLEANUP_MAX_TRIES = 1;
	private const CLEANUP_RETRY_WAIT_SECONDS = 0;
	private const CLEANUP_TOTAL_TIME_BUDGET_SECONDS = 12;

	private const STEP_TIME_BUDGET_SECONDS = 18;
	private const PER_URL_TIME_BUDGET_SECONDS = 14;
	private const RANKIMA_EXTRACTOR_ENDPOINT = 'https://extractor.rankima.com/v1/extract';

	private SettingsService $settings_service;
	private OpenRouterClient $openrouter;
	private N8nPromptLibrary $prompt_library;
	private WorkflowProgressService $progress_service;

	public function __construct(
		?SettingsService $settings_service = null,
		?OpenRouterClient $openrouter = null,
		?N8nPromptLibrary $prompt_library = null,
		?WorkflowProgressService $progress_service = null
	) {
		$this->settings_service = $settings_service ?? new SettingsService();
		$this->openrouter = $openrouter ?? new OpenRouterClient();
		$this->prompt_library = $prompt_library ?? new N8nPromptLibrary();
		$this->progress_service = $progress_service ?? new WorkflowProgressService();
	}

	/**
	 * @param array<string,mixed> $spec
	 */
	public function run(WorkflowContext $context, array $spec): void
	{
		$targets = (array) $context->get('research_targets', []);
		if (empty($targets)) {
			throw new \Exception('No research targets available for scraping.');
		}

		$payload = (array) $context->get('payload', []);
		$task_id = (int) ($payload['task_id'] ?? 0);
		$state = $this->init_scrape_state_if_missing($context, $targets);
		$started = microtime(true);
		$cleanup_calls = 0;

		while (
			!empty($state['queue']) &&
			(int) ($state['current_index'] ?? 0) < count((array) $state['queue']) &&
			(microtime(true) - $started) < self::STEP_TIME_BUDGET_SECONDS
		) {
			$index = (int) $state['current_index'];
			$total = count((array) $state['queue']);
			$item = (array) (($state['queue'][$index] ?? []));
			$url = trim((string) ($item['url'] ?? ''));
			$title = (string) ($item['title'] ?? $url);
			$domain = trim((string) ($item['domain'] ?? (string) parse_url($url, PHP_URL_HOST)));
			if ($domain === '') {
				$domain = 'source';
			}
			$display_index = $index + 1;

			$state['last_domain'] = $domain;
			$context->set('research_domain', $domain);
			$state['inflight'] = ['url' => $url, 'stage' => 'scrape'];
			$this->update_progress($task_id, "Scraping {$domain} ({$display_index}/{$total})");

			$url_started = microtime(true);
			$scrape = $this->scrape_article($url);
			if (($scrape['content'] ?? '') === '') {
				$this->append_error(
					$state,
					$url,
					'scrape',
					(string) ($scrape['message'] ?? 'Scrape failed'),
					(int) ($scrape['attempts'] ?? 1),
					(string) ($scrape['reason'] ?? 'provider_error')
				);
				$state['failed_count'] = (int) ($state['failed_count'] ?? 0) + 1;
				$state['processed_count'] = (int) ($state['processed_count'] ?? 0) + 1;
				$state['current_index'] = $index + 1;
				$state['inflight'] = null;
				$this->persist_state_snapshot($context, $state, $task_id);
				$this->log('scrape_item_failed', [
					'task_id' => $task_id,
					'url' => $url,
					'domain' => $domain,
					'index' => $display_index,
					'total' => $total,
					'stage' => 'scrape',
					'reason' => (string) ($scrape['reason'] ?? 'provider_error'),
				]);
				continue;
			}

			if ((microtime(true) - $url_started) > self::PER_URL_TIME_BUDGET_SECONDS) {
				$this->append_error($state, $url, 'scrape', 'Per-URL time budget exceeded during scraping.', 1, 'timeout');
				$state['failed_count'] = (int) ($state['failed_count'] ?? 0) + 1;
				$state['processed_count'] = (int) ($state['processed_count'] ?? 0) + 1;
				$state['current_index'] = $index + 1;
				$state['inflight'] = null;
				$this->persist_state_snapshot($context, $state, $task_id);
				$this->log('scrape_item_failed', [
					'task_id' => $task_id,
					'url' => $url,
					'domain' => $domain,
					'index' => $display_index,
					'total' => $total,
					'stage' => 'scrape',
					'reason' => 'timeout',
				]);
				continue;
			}

			$state['inflight'] = ['url' => $url, 'stage' => 'cleanup'];
			$this->update_progress($task_id, "Cleaning {$domain} ({$display_index}/{$total})");
			$cleanup = $this->cleanup_research_content($title, (string) ($scrape['content'] ?? ''));
			$cleanup_calls++;
			$cleaned = (string) ($cleanup['content'] ?? '');

			if ($cleaned === '') {
				$this->append_error(
					$state,
					$url,
					'cleanup',
					(string) ($cleanup['message'] ?? 'Cleanup failed'),
					(int) ($cleanup['attempts'] ?? 1),
					(string) ($cleanup['reason'] ?? 'model_error')
				);
				$state['failed_count'] = (int) ($state['failed_count'] ?? 0) + 1;
				$state['processed_count'] = (int) ($state['processed_count'] ?? 0) + 1;
				$state['current_index'] = $index + 1;
				$state['inflight'] = null;
				$this->persist_state_snapshot($context, $state, $task_id);
				$this->log('scrape_item_failed', [
					'task_id' => $task_id,
					'url' => $url,
					'domain' => $domain,
					'index' => $display_index,
					'total' => $total,
					'stage' => 'cleanup',
					'reason' => (string) ($cleanup['reason'] ?? 'model_error'),
				]);
				continue;
			}

			$research_items = (array) $context->get('research_items', []);
			$research_items[] = [
				'title' => $title,
				'url' => $url,
				'full_article' => $cleaned,
			];
			$context->set('research_items', $research_items);

			$state['success_count'] = (int) ($state['success_count'] ?? 0) + 1;
			$state['processed_count'] = (int) ($state['processed_count'] ?? 0) + 1;
			$state['current_index'] = $index + 1;
			$state['inflight'] = null;
			$this->persist_state_snapshot($context, $state, $task_id);
			$this->log('scrape_item_done', [
				'task_id' => $task_id,
				'url' => $url,
				'domain' => $domain,
				'index' => $display_index,
				'total' => $total,
			]);

			if ($cleanup_calls >= 1) {
				break;
			}
		}

		$queue = (array) ($state['queue'] ?? []);
		$is_completed = !empty($queue) && (int) ($state['current_index'] ?? 0) >= count($queue);
		$state['completed'] = $is_completed;
		if ($is_completed) {
			$this->append_standard_research_to_items($context);
		}
		$this->persist_state_snapshot($context, $state, $task_id);

		$has_standard = trim((string) $context->get('research_standard', '')) !== '';
		if ($is_completed && (int) ($state['success_count'] ?? 0) <= 0 && !$has_standard) {
			$summary = $this->build_error_summary($state);
			throw new \Exception('Scraping completed with no usable research content. ' . $summary);
		}

		if (!$is_completed) {
			if ($cleanup_calls >= 1) {
				throw new StepDeferredException('Scraping paused after one AI cleanup request; continuing on next tick.');
			}
			throw new StepDeferredException('Scraping progress saved; continuing on next tick.');
		}
	}

	/**
	 * @param array<int,mixed> $targets
	 * @return array<string,mixed>
	 */
	private function init_scrape_state_if_missing(WorkflowContext $context, array $targets): array
	{
		$existing = $context->get('research_scrape_state', null);
		if (is_array($existing) && !empty($existing['queue']) && array_key_exists('current_index', $existing)) {
			return $existing;
		}

		$queue = [];
		$seen = [];
		foreach ($targets as $target) {
			if (!is_array($target)) {
				continue;
			}
			$url = trim((string) ($target['url'] ?? ''));
			if ($url === '' || $this->is_youtube_url($url)) {
				continue;
			}
			$canonical = $this->canonicalize_url($url);
			if ($canonical === '' || isset($seen[$canonical])) {
				continue;
			}
			$seen[$canonical] = true;
			$queue[] = [
				'title' => (string) ($target['title'] ?? $url),
				'url' => $url,
				'domain' => (string) parse_url($url, PHP_URL_HOST),
			];
		}

		$state = [
			'queue' => $queue,
			'current_index' => 0,
			'processed_count' => 0,
			'success_count' => 0,
			'failed_count' => 0,
			'errors' => [],
			'inflight' => null,
			'last_domain' => '',
			'completed' => false,
		];
		$context->set('research_items', []);
		$context->set('research_scrape_state', $state);
		return $state;
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
		return $host . rtrim($path, '/');
	}

	/**
	 * @return array{content:string,reason:string,message:string,attempts:int}
	 */
	private function scrape_article(string $url): array
	{
		$provider = $this->settings_service->get_article_scraper_provider();
		$content = '';
		$message = '';

		if ($provider === 'rankima') {
			$content = $this->scrape_via_rankima($url, $message);
		} elseif ($provider === 'firecrawl') {
			$content = $this->scrape_via_firecrawl($url, $message);
		} elseif ($provider === 'rapidapi') {
			$content = $this->scrape_via_rapidapi($url, $message);
		}

		if ($content !== '') {
			return ['content' => $content, 'reason' => '', 'message' => '', 'attempts' => 1];
		}

		return [
			'content' => '',
			'reason' => 'provider_error',
			'message' => $message !== '' ? $message : sprintf('Scraper provider "%s" returned no content.', $provider),
			'attempts' => 1,
		];
	}

	private function scrape_via_rankima(string $url, string &$error = ''): string
	{
		$key = trim($this->settings_service->get_rankima_extractor_api_key());
		if ($key === '') {
			$error = 'Rankima Article Extractor API key is required.';
			return '';
		}

		$response = wp_remote_post(self::RANKIMA_EXTRACTOR_ENDPOINT, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode([
				'url' => $url,
			]),
		]);
		if (is_wp_error($response)) {
			$error = $response->get_error_message();
			return '';
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$error = sprintf('Rankima extractor request failed with HTTP %d.', $status);
			return '';
		}

		$content = trim((string) ($decoded['data']['content'] ?? $decoded['content'] ?? $decoded['data']['markdown'] ?? ''));
		if ($content === '') {
			$error = 'Rankima extractor returned empty content.';
		}
		return $content;
	}

	private function scrape_via_rapidapi(string $url, string &$error = ''): string
	{
		$key = trim($this->settings_service->get_rapidapi_api_key());
		$base_url = trim($this->settings_service->get_rapidapi_api_url());
		if ($key === '') {
			$error = 'RapidAPI key is required.';
			return '';
		}
		if ($base_url === '') {
			$error = 'RapidAPI URL is required.';
			return '';
		}

		$query_url = $base_url;
		$glue = str_contains($query_url, '?') ? '&' : '?';
		$query_url .= $glue . http_build_query([
			'url' => $url,
			'word_per_minute' => 300,
			'desc_truncate_len' => 210,
			'desc_len_min' => 180,
			'content_len_min' => 200,
		]);

		$host = (string) parse_url($base_url, PHP_URL_HOST);
		$response = wp_remote_get($query_url, [
			'timeout' => 20,
			'headers' => [
				'x-rapidapi-key' => $key,
				'x-rapidapi-host' => $host !== '' ? $host : 'article-extractor2.p.rapidapi.com',
			]
		]);
		if (is_wp_error($response)) {
			$error = $response->get_error_message();
			return '';
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$error = sprintf('RapidAPI request failed with HTTP %d.', $status);
			return '';
		}

		$content = trim((string) ($decoded['data']['content'] ?? $decoded['content'] ?? ''));
		if ($content === '') {
			$error = 'RapidAPI returned empty content.';
		}
		return $content;
	}

	private function scrape_via_firecrawl(string $url, string &$error = ''): string
	{
		$key = trim($this->settings_service->get_firecrawl_api_key());
		$base_url = trim($this->settings_service->get_firecrawl_api_url());
		if ($key === '') {
			$error = 'Firecrawl API key is required.';
			return '';
		}
		if ($base_url === '') {
			$error = 'Firecrawl API URL is required.';
			return '';
		}

		$response = wp_remote_post($base_url, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode([
				'url' => $url,
				'onlyMainContent' => true,
				'formats' => ['markdown'],
			]),
		]);
		if (is_wp_error($response)) {
			$error = $response->get_error_message();
			return '';
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$error = sprintf('Firecrawl request failed with HTTP %d.', $status);
			return '';
		}

		$content = trim((string) ($decoded['data']['markdown'] ?? $decoded['data']['content'] ?? $decoded['content'] ?? ''));
		if ($content === '') {
			$error = 'Firecrawl returned empty content.';
		}
		return $content;
	}

	/**
	 * @return array{content:string,reason:string,message:string,attempts:int}
	 */
	private function cleanup_research_content(string $title, string $article): array
	{
		$title = trim($title);
		$article = trim($article);
		if ($article === '') {
			return ['content' => '', 'reason' => 'empty_content', 'message' => 'No article content to clean.', 'attempts' => 1];
		}

		$template = $this->prompt_library->load('research.cleanup.user.txt');
		$prompt = $this->prompt_library->render_with_context($template, [
			'research' => [
				'title' => $title !== '' ? $title : 'Untitled Article',
				'article' => $article,
			],
		]);
		if ($prompt === '') {
			return ['content' => $article, 'reason' => '', 'message' => '', 'attempts' => 1];
		}

		$provider = $this->settings_service->get_article_scraper_provider();
		if ($provider === 'rankima') {
			return ['content' => $article, 'reason' => '', 'message' => '', 'attempts' => 1];
		}

		if (!$this->settings_service->is_clean_data_with_ai_enabled()) {
			return ['content' => $article, 'reason' => '', 'message' => '', 'attempts' => 1];
		}

		$model = trim($this->settings_service->get_clean_data_model_id());
		if ($model === '') {
			$model = self::CLEANUP_PRIMARY_MODEL;
		}
		$cleaned = $this->run_cleanup_model_with_retries($prompt, $model);
		if ($cleaned['content'] !== '') {
			return $cleaned;
		}
		return [
			'content' => '',
			'reason' => $cleaned['reason'] !== '' ? $cleaned['reason'] : 'model_empty',
			'message' => $cleaned['message'] !== '' ? $cleaned['message'] : 'Cleanup model returned empty output.',
			'attempts' => (int) ($cleaned['attempts'] ?? 0),
		];
	}

	/**
	 * @return array{content:string,reason:string,message:string,attempts:int}
	 */
	private function run_cleanup_model_with_retries(string $prompt, string $model): array
	{
		$last_error = '';
		$started = microtime(true);
		$attempts = 0;

		for ($attempt = 1; $attempt <= self::CLEANUP_MAX_TRIES; $attempt++) {
			$attempts = $attempt;
			if ((microtime(true) - $started) >= self::CLEANUP_TOTAL_TIME_BUDGET_SECONDS) {
				$last_error = 'cleanup time budget exceeded';
				break;
			}

			$response = $this->openrouter->chat([
				['role' => 'user', 'content' => $prompt],
			], $model, false);

			if (!is_wp_error($response)) {
				$text = trim($this->extract_cleanup_text($response));
				if ($text !== '') {
					$this->log_cleanup_attempt($model, $attempt, 'ok', '');
					return ['content' => $text, 'reason' => '', 'message' => '', 'attempts' => $attempt];
				}
				$last_error = 'empty response';
				$this->log_cleanup_attempt($model, $attempt, 'empty', $last_error);
			} else {
				$last_error = $response->get_error_message();
				$this->log_cleanup_attempt($model, $attempt, 'error', $last_error);
			}

			if (
				$attempt < self::CLEANUP_MAX_TRIES &&
				(microtime(true) - $started) < self::CLEANUP_TOTAL_TIME_BUDGET_SECONDS
			) {
				sleep(self::CLEANUP_RETRY_WAIT_SECONDS);
			}
		}

		$this->log('cleanup_model_failed', [
			'model' => $model,
			'max_tries' => self::CLEANUP_MAX_TRIES,
			'error' => $last_error,
		]);

		return [
			'content' => '',
			'reason' => str_contains($last_error, 'time budget') ? 'timeout' : 'model_error',
			'message' => $last_error !== '' ? $last_error : 'cleanup failed',
			'attempts' => $attempts,
		];
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function extract_cleanup_text(array $response): string
	{
		$text = trim($this->openrouter->extract_text_content($response));
		if ($text !== '') {
			return $text;
		}

		$choice_text = trim((string) ($response['choices'][0]['text'] ?? ''));
		if ($choice_text !== '') {
			return $choice_text;
		}

		$output_text = trim((string) ($response['output_text'] ?? ''));
		if ($output_text !== '') {
			return $output_text;
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function append_error(
		array &$state,
		string $url,
		string $stage,
		string $message,
		int $attempts,
		string $reason
	): void {
		$errors = (array) ($state['errors'] ?? []);
		$errors[] = [
			'url' => $url,
			'stage' => $stage,
			'reason' => $reason,
			'message' => $message,
			'attempts' => $attempts,
			'at' => current_time('mysql'),
		];
		$state['errors'] = $errors;
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function persist_state_snapshot(WorkflowContext $context, array $state, int $task_id): void
	{
		$context->set('research_scrape_state', $state);
		if ($task_id <= 0) {
			return;
		}
		TaskExecutionState::update_context_snapshot($task_id, $context->to_array());
	}

	private function append_standard_research_to_items(WorkflowContext $context): void
	{
		$standard = trim((string) $context->get('research_standard', ''));
		if ($standard === '') {
			return;
		}

		$items = (array) $context->get('research_items', []);
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			if ((string) ($item['source'] ?? '') === 'standard_research') {
				return;
			}
		}

		$items[] = [
			'title' => 'Standard Research',
			'url' => '',
			'full_article' => $standard,
			'source' => 'standard_research',
		];
		$context->set('research_items', $items);
	}

	private function build_error_summary(array $state): string
	{
		$errors = array_slice((array) ($state['errors'] ?? []), 0, 3);
		if (empty($errors)) {
			return '';
		}

		$parts = [];
		foreach ($errors as $err) {
			if (!is_array($err)) {
				continue;
			}
			$parts[] = sprintf(
				'%s (%s: %s)',
				(string) ($err['url'] ?? 'unknown'),
				(string) ($err['stage'] ?? 'stage'),
				(string) ($err['reason'] ?? 'error')
			);
		}
		return 'Examples: ' . implode('; ', $parts);
	}

	private function update_progress(int $task_id, string $message): void
	{
		if ($task_id <= 0) {
			return;
		}
		$this->progress_service->update_progress($task_id, $message);
	}

	private function log_cleanup_attempt(string $model, int $attempt, string $status, string $detail): void
	{
		$this->log('cleanup_attempt', [
			'model' => $model,
			'attempt' => $attempt,
			'status' => $status,
			'detail' => $detail,
		]);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function log(string $event, array $context = []): void
	{
		// Disabled by design: LocalWorkflowRunner emits one consolidated log per step run.
		return;
	}
}
