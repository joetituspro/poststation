<?php

namespace PostStation\Services\Workflow;

use PostStation\Models\PostTask;
use PostStation\Models\TaskExecutionState;
use PostStation\Services\Workflow\Steps\AnalysisStep;
use PostStation\Services\Workflow\Steps\CustomFieldsStep;
use PostStation\Services\Workflow\Steps\ExtrasStep;
use PostStation\Services\Workflow\Steps\FeaturedImageStep;
use PostStation\Services\Workflow\Steps\InternalLinksStep;
use PostStation\Services\Workflow\Steps\OutlineStep;
use PostStation\Services\Workflow\Steps\PreliminaryPlanStep;
use PostStation\Services\Workflow\Steps\PublishStep;
use PostStation\Services\Workflow\Steps\ResearchDiscoverStep;
use PostStation\Services\Workflow\Steps\ResearchScrapeStep;
use PostStation\Services\Workflow\Steps\TaxonomiesStep;
use PostStation\Services\Workflow\Steps\WritingStep;

class LocalWorkflowRunner
{
	private const MAX_ATTEMPTS = 3;
	private const SOFT_BUDGET_SECONDS = 28;
	private const STEP_TIMEOUT_SECONDS = 120;
	private const STEP_SEQUENCE = [
		'init',
		'researching',
		'scraping',
		'analysis',
		'preliminary_plan',
		'outline',
		'internal_links',
		'writing',
		'extras',
		'custom_fields',
		'featured_image',
		'taxonomies',
		'finalizing_publish',
	];
	private const AI_STEPS = [
		'researching',
		'scraping',
		'analysis',
		'preliminary_plan',
		'outline',
		'internal_links',
		'writing',
		'extras',
		'custom_fields',
		'featured_image',
		'taxonomies',
	];

	private WorkflowSpecService $spec_service;
	private WorkflowProgressService $progress_service;
	private ResearchDiscoverStep $research_discover_step;
	private ResearchScrapeStep $research_scrape_step;
	private AnalysisStep $analysis_step;
	private PreliminaryPlanStep $preliminary_plan_step;
	private OutlineStep $outline_step;
	private InternalLinksStep $internal_links_step;
	private WritingStep $writing_step;
	private ExtrasStep $extras_step;
	private CustomFieldsStep $custom_fields_step;
	private TaxonomiesStep $taxonomies_step;
	private FeaturedImageStep $featured_image_step;
	private PublishStep $publish_step;

	public function __construct(
		?WorkflowSpecService $spec_service = null,
		?WorkflowProgressService $progress_service = null,
		?ResearchDiscoverStep $research_discover_step = null,
		?ResearchScrapeStep $research_scrape_step = null,
		?AnalysisStep $analysis_step = null,
		?PreliminaryPlanStep $preliminary_plan_step = null,
		?OutlineStep $outline_step = null,
		?InternalLinksStep $internal_links_step = null,
		?WritingStep $writing_step = null,
		?ExtrasStep $extras_step = null,
		?CustomFieldsStep $custom_fields_step = null,
		?TaxonomiesStep $taxonomies_step = null,
		?FeaturedImageStep $featured_image_step = null,
		?PublishStep $publish_step = null
	) {
		$this->spec_service = $spec_service ?? new WorkflowSpecService();
		$this->progress_service = $progress_service ?? new WorkflowProgressService();
		$this->research_discover_step = $research_discover_step ?? new ResearchDiscoverStep();
		$this->research_scrape_step = $research_scrape_step ?? new ResearchScrapeStep();
		$this->analysis_step = $analysis_step ?? new AnalysisStep();
		$this->preliminary_plan_step = $preliminary_plan_step ?? new PreliminaryPlanStep();
		$this->outline_step = $outline_step ?? new OutlineStep();
		$this->internal_links_step = $internal_links_step ?? new InternalLinksStep();
		$this->writing_step = $writing_step ?? new WritingStep();
		$this->extras_step = $extras_step ?? new ExtrasStep();
		$this->custom_fields_step = $custom_fields_step ?? new CustomFieldsStep();
		$this->taxonomies_step = $taxonomies_step ?? new TaxonomiesStep();
		$this->featured_image_step = $featured_image_step ?? new FeaturedImageStep();
		$this->publish_step = $publish_step ?? new PublishStep();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function run(int $task_id, array $payload): array
	{
		return $this->start_or_resume($task_id, $payload);
	}

	/**
	 * Start new execution state or resume existing one using current payload fingerprint.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function start_or_resume(int $task_id, array $payload): array
	{
		$task = PostTask::get_by_id($task_id);
		if (!$task) {
			return ['success' => false, 'message' => 'Post task not found.'];
		}

		$spec = $this->spec_service->get_active_spec();
		if (is_wp_error($spec)) {
			$this->progress_service->mark_failed($task_id, $spec->get_error_message());
			return ['success' => false, 'message' => $spec->get_error_message()];
		}

		$fingerprint = $this->build_payload_fingerprint($payload);
		$state = TaskExecutionState::get_by_task_id($task_id);
		if (!$state) {
			$state = $this->initialize_state($task, $payload, $fingerprint);
		} elseif ((string) ($state['payload_fingerprint'] ?? '') !== $fingerprint) {
			// Task inputs changed: reset and restart from step 1.
			$state = $this->reset_state_with_payload($task, $payload, $fingerprint);
		} elseif (($state['status'] ?? '') === 'failed') {
			TaskExecutionState::reset_for_retry($task_id);
			$state = TaskExecutionState::get_by_task_id($task_id);
		}

		if (!$state) {
			$this->progress_service->mark_failed($task_id, 'Unable to initialize local execution state.');
			return ['success' => false, 'message' => 'Unable to initialize local execution state.'];
		}

		$execution_id = trim((string) ($task['execution_id'] ?? ''));
		if ($execution_id === '') {
			$execution_id = 'local-' . wp_generate_uuid4();
		}
		$this->progress_service->start_processing($task_id, $execution_id);

		return $this->execute_from_state($task_id, $state, $spec);
	}

	/**
	 * Resume from persisted state, used by global tick.
	 *
	 * @return array<string,mixed>
	 */
	public function resume_from_state(int $task_id): array
	{
		$spec = $this->spec_service->get_active_spec();
		if (is_wp_error($spec)) {
			$this->progress_service->mark_failed($task_id, $spec->get_error_message());
			return ['success' => false, 'message' => $spec->get_error_message()];
		}

		$state = TaskExecutionState::get_by_task_id($task_id);
		if (!$state) {
			return ['success' => false, 'message' => 'Execution state not found.'];
		}

		if (($state['status'] ?? '') === 'failed') {
			// Wait for manual retry.
			return ['success' => false, 'message' => 'Execution is in failed state.'];
		}

		return $this->execute_from_state($task_id, $state, $spec);
	}

	public function cancel_task(int $task_id): void
	{
		TaskExecutionState::mark_terminal($task_id, 'cancelled', 'Cancelled by user.');
		TaskExecutionState::delete_by_task_id($task_id);
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $spec
	 * @return array<string,mixed>
	 */
	private function execute_from_state(int $task_id, array $state, array $spec): array
	{
		$context = $this->build_context_from_state($state);
		$started_at = microtime(true);
		$current_step = (string) ($state['next_step'] ?? self::STEP_SEQUENCE[0]);
		$executed_ai_step = false;
		$completed = false;

		register_shutdown_function(function () use ($task_id, &$completed, &$current_step): void {
			if ($completed) {
				return;
			}
			$error = error_get_last();
			if (!$error) {
				return;
			}
			$fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
			if (!in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
				return;
			}
			$msg = sprintf('Step "%s" attempt fatal: %s', $current_step, (string) ($error['message'] ?? 'Fatal error'));
			$this->handle_step_failure($task_id, $current_step, $msg, $spec);
		});

		if (function_exists('set_time_limit')) {
			@set_time_limit(120);
		}

		while ($current_step !== '') {
			if ($this->should_skip_step($current_step, $context)) {
				$step_start_ts = microtime(true);
				$before_context = $context->to_array();
				$step_input = $this->build_step_input($current_step, $before_context);
				$this->apply_skip_side_effects($current_step, $context);
				$next_step = $this->get_next_runnable_step($current_step, $context);
				$this->log_step_run(
					$task_id,
					$current_step,
					$step_start_ts,
					microtime(true),
					$step_input,
					['skipped' => true, 'reason' => $this->skip_reason_for_step($current_step, $context)],
					$next_step
				);

				if ($next_step === '') {
					TaskExecutionState::mark_terminal($task_id, 'completed', null);
					TaskExecutionState::delete_by_task_id($task_id);
					$completed = true;
					return ['success' => true, 'message' => 'Local workflow completed.'];
				}

				TaskExecutionState::mark_step_succeeded_and_advance(
					$task_id,
					$current_step,
					$next_step,
					$context->to_array()
				);
				$current_step = $next_step;
				$state = TaskExecutionState::get_by_task_id($task_id) ?: $state;
				continue;
			}

			if ($executed_ai_step) {
				return ['success' => true, 'message' => 'Paused after AI step; awaiting next tick.'];
			}

			if ((microtime(true) - $started_at) >= self::SOFT_BUDGET_SECONDS) {
				$this->set_progress_for_step($task_id, $spec, $current_step, 0, self::MAX_ATTEMPTS, true);
				return ['success' => true, 'message' => 'Paused at soft time budget boundary.'];
			}

			if ($this->is_step_timed_out($state)) {
				$this->log_step_run($task_id, $current_step, microtime(true), microtime(true), [], ['error' => 'step_timeout'], $current_step);
				$this->handle_step_failure(
					$task_id,
					$current_step,
					sprintf('Step "%s" timed out and will retry on next tick.', $current_step),
					$spec
				);
				$completed = true;
				return ['success' => false, 'message' => 'Step timeout recorded.'];
			}

			TaskExecutionState::mark_step_started($task_id, $current_step);
			$state = TaskExecutionState::get_by_task_id($task_id) ?: $state;
			$step_start_ts = microtime(true);
			$before_context = $context->to_array();
			$step_input = $this->build_step_input($current_step, $before_context);
			try {
				$this->set_progress_for_step($task_id, $spec, $current_step, 0, self::MAX_ATTEMPTS, false, $context);
				$this->execute_step($current_step, $context, $spec);
				$step_response = $this->build_step_response($current_step, $context, $before_context);
				$next_step = $this->get_next_runnable_step($current_step, $context);
				$this->log_step_run($task_id, $current_step, $step_start_ts, microtime(true), $step_input, $step_response, $next_step);

				if ($next_step === '') {
					TaskExecutionState::mark_terminal($task_id, 'completed', null);
					TaskExecutionState::delete_by_task_id($task_id);
					$completed = true;
					return ['success' => true, 'message' => 'Local workflow completed.'];
				}

				TaskExecutionState::mark_step_succeeded_and_advance(
					$task_id,
					$current_step,
					$next_step,
					$context->to_array()
				);

				if ($this->is_ai_step($current_step)) {
					$executed_ai_step = true;
				}
				$current_step = $next_step;
				$state = TaskExecutionState::get_by_task_id($task_id) ?: $state;
				if ($current_step !== '' && $this->is_ai_step($current_step)) {
					return ['success' => true, 'message' => 'Paused before AI step; awaiting next tick.'];
				}
			} catch (StepDeferredException $e) {
				TaskExecutionState::update_context_snapshot($task_id, $context->to_array());
				PostTask::update($task_id, [
					'status' => 'processing',
					'error_message' => null,
					'run_started_at' => current_time('mysql'),
				]);
				$this->log_step_run(
					$task_id,
					$current_step,
					$step_start_ts,
					microtime(true),
					$step_input,
					['deferred' => $e->getMessage()],
					$current_step
				);
				$completed = true;
				return ['success' => true, 'message' => $e->getMessage()];
			} catch (\Throwable $e) {
				$this->log_step_run(
					$task_id,
					$current_step,
					$step_start_ts,
					microtime(true),
					$step_input,
					['error' => $e->getMessage()],
					$current_step
				);
				$this->handle_step_failure($task_id, $current_step, $e->getMessage(), $spec);
				$completed = true;
				return ['success' => false, 'message' => $e->getMessage()];
			}
		}

		$completed = true;
		return ['success' => true, 'message' => 'No-op resume.'];
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|null
	 */
	private function initialize_state(array $task, array $payload, string $fingerprint): ?array
	{
		$task_id = (int) ($task['id'] ?? 0);
		$campaign_id = (int) ($task['campaign_id'] ?? 0);
		$ok = TaskExecutionState::upsert_running_state(
			$task_id,
			$campaign_id,
			self::STEP_SEQUENCE[0],
			self::STEP_SEQUENCE[0],
			$payload,
			['payload' => $payload],
			$fingerprint,
			self::MAX_ATTEMPTS
		);
		return $ok ? TaskExecutionState::get_by_task_id($task_id) : null;
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|null
	 */
	private function reset_state_with_payload(array $task, array $payload, string $fingerprint): ?array
	{
		$task_id = (int) ($task['id'] ?? 0);
		TaskExecutionState::delete_by_task_id($task_id);
		return $this->initialize_state($task, $payload, $fingerprint);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function is_step_timed_out(array $state): bool
	{
		$started = (string) ($state['step_started_at'] ?? '');
		if ($started === '') {
			return false;
		}
		$started_ts = strtotime($started);
		if (!$started_ts) {
			return false;
		}
		return (current_time('timestamp') - $started_ts) > self::STEP_TIMEOUT_SECONDS;
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function build_context_from_state(array $state): WorkflowContext
	{
		$payload = json_decode((string) ($state['payload_json'] ?? '{}'), true);
		$context_data = json_decode((string) ($state['context_json'] ?? '{}'), true);
		if (!is_array($payload)) {
			$payload = [];
		}
		if (!is_array($context_data)) {
			$context_data = [];
		}
		$context_data['payload'] = $payload;
		return new WorkflowContext($context_data);
	}

	private function get_next_step(string $step): string
	{
		$idx = array_search($step, self::STEP_SEQUENCE, true);
		if ($idx === false) {
			return '';
		}
		$next = $idx + 1;
		return $next < count(self::STEP_SEQUENCE) ? self::STEP_SEQUENCE[$next] : '';
	}

	private function is_ai_step(string $step): bool
	{
		return in_array($step, self::AI_STEPS, true);
	}

	private function get_next_runnable_step(string $step, WorkflowContext $context): string
	{
		$next = $this->get_next_step($step);
		while ($next !== '' && $this->should_skip_step($next, $context)) {
			$next = $this->get_next_step($next);
		}
		return $next;
	}

	private function should_skip_step(string $step, WorkflowContext $context): bool
	{
		$payload = (array) $context->get('payload', []);
		switch ($step) {
			case 'researching':
				return $this->is_realtime_none($context) && trim((string) ($payload['research_url'] ?? '')) === '';
			case 'scraping':
				return empty((array) $context->get('research_targets', []));
			case 'analysis':
				return $this->is_realtime_none($context);
			case 'preliminary_plan':
				return !$this->is_realtime_none($context);
			case 'internal_links':
				$mode = strtolower(trim((string) ($payload['content_fields']['body']['internal_links_mode'] ?? 'all_post_types')));
				if ($mode === 'any_post_type') {
					$mode = 'all_post_types';
				}
				$count = max(0, (int) ($payload['content_fields']['body']['internal_links_count'] ?? 4));
				$sitemap = (array) ($payload['sitemap'] ?? []);
				return $mode === 'none' || $count <= 0 || empty($sitemap);
			case 'custom_fields':
				$fields = (array) ($payload['content_fields']['custom_fields'] ?? []);
				return $this->count_enabled_custom_fields($fields) === 0;
			case 'taxonomies':
				$taxonomies = (array) ($payload['content_fields']['taxonomies'] ?? []);
				return $this->count_enabled_taxonomies($taxonomies) === 0;
			case 'featured_image':
				$manual_featured_image_id = (int) ($payload['feature_image_id'] ?? 0);
				if ($manual_featured_image_id > 0) {
					return false;
				}
				$image = (array) ($payload['content_fields']['image'] ?? []);
				$enabled = !empty($image['enabled']);
				$mode = (string) ($image['mode'] ?? 'generate');
				return !($enabled && $mode === 'generate');
			default:
				return false;
		}
	}

	/**
	 * @param array<int,mixed> $fields
	 */
	private function count_enabled_custom_fields(array $fields): int
	{
		$count = 0;
		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}
			if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
				continue;
			}
			$meta_key = trim((string) ($field['meta_key'] ?? ''));
			$prompt = trim((string) ($field['prompt'] ?? ''));
			if ($meta_key === '' || $prompt === '') {
				continue;
			}
			$count++;
		}
		return $count;
	}

	/**
	 * @param array<int,mixed> $taxonomies
	 */
	private function count_enabled_taxonomies(array $taxonomies): int
	{
		$count = 0;
		foreach ($taxonomies as $tax) {
			if (!is_array($tax)) {
				continue;
			}
			if (array_key_exists('enabled', $tax) && empty($tax['enabled'])) {
				continue;
			}
			$taxonomy = sanitize_key((string) ($tax['taxonomy'] ?? ''));
			if ($taxonomy === '') {
				continue;
			}
			$count++;
		}
		return $count;
	}

	private function skip_reason_for_step(string $step, WorkflowContext $context): string
	{
		$payload = (array) $context->get('payload', []);
		switch ($step) {
			case 'researching':
				return 'Real-time data mode is none and no research URL was provided.';
			case 'scraping':
				return 'No research targets available for scraping.';
			case 'analysis':
				return 'Real-time data mode is none; analysis step is disabled.';
			case 'preliminary_plan':
				return 'Preliminary plan only runs when real-time data mode is none.';
			case 'internal_links':
				$mode = strtolower(trim((string) ($payload['content_fields']['body']['internal_links_mode'] ?? 'all_post_types')));
				if ($mode === 'none') {
					return 'Internal links mode is disabled (none).';
				}
				if ((int) ($payload['content_fields']['body']['internal_links_count'] ?? 4) <= 0) {
					return 'Internal links count is zero.';
				}
				return 'No sitemap entries available for internal links.';
			case 'custom_fields':
				$fields = (array) ($payload['content_fields']['custom_fields'] ?? []);
				return $this->count_enabled_custom_fields($fields) === 0
					? 'No enabled custom fields in payload.'
					: 'Custom fields step skipped.';
			case 'taxonomies':
				$taxonomies = (array) ($payload['content_fields']['taxonomies'] ?? []);
				return $this->count_enabled_taxonomies($taxonomies) === 0
					? 'No enabled taxonomies in payload.'
					: 'Taxonomies step skipped.';
			case 'featured_image':
				return 'Featured image generation disabled and no manual feature_image_id provided.';
			default:
				return 'Step skipped.';
		}
	}

	private function apply_skip_side_effects(string $step, WorkflowContext $context): void
	{
		if ($step === 'internal_links') {
			$context->set('internal_links', []);
		}
	}

	/**
	 * @param array<string,mixed> $spec
	 */
	private function execute_step(string $step, WorkflowContext $context, array $spec): void
	{
		switch ($step) {
			case 'init':
				return;
			case 'researching':
				$this->research_discover_step->run($context, $spec);
				return;
			case 'scraping':
				$this->research_scrape_step->run($context, $spec);
				return;
			case 'analysis':
				$this->analysis_step->run($context, $spec);
				return;
			case 'preliminary_plan':
				$this->preliminary_plan_step->run($context, $spec);
				return;
			case 'outline':
				$this->outline_step->run($context, $spec);
				return;
			case 'internal_links':
				$this->internal_links_step->run($context, $spec);
				return;
			case 'writing':
				$this->writing_step->run($context, $spec);
				return;
			case 'extras':
				$this->extras_step->run($context, $spec);
				return;
			case 'custom_fields':
				$this->custom_fields_step->run($context, $spec);
				return;
			case 'featured_image':
				$this->featured_image_step->run($context, $spec);
				return;
			case 'taxonomies':
				$this->taxonomies_step->run($context, $spec);
				return;
			case 'finalizing_publish':
				$result = $this->publish_step->run($context, $spec);
				if (is_array($result)) {
					$context->set('publish_result', $result);
				}
				return;
			default:
				throw new \Exception('Unknown local workflow step: ' . $step);
		}
	}

	/**
	 * @param array<string,mixed> $spec
	 */
	private function handle_step_failure(int $task_id, string $step, string $error, array $spec): void
	{
		$updated = TaskExecutionState::mark_step_failed_attempt($task_id, $step, $error);
		$attempt = (int) ($updated['attempt_count'] ?? 1);
		$max = (int) ($updated['max_attempts'] ?? self::MAX_ATTEMPTS);
		$message = sprintf('Step "%s" attempt %d/%d failed: %s', $step, $attempt, $max, $error);

		if ($attempt >= $max) {
			$this->progress_service->mark_failed($task_id, $message);
			TaskExecutionState::mark_terminal($task_id, 'failed', $message);
			return;
		}

		$this->set_progress_for_step($task_id, $spec, $step, $attempt, $max, true);
		PostTask::update($task_id, [
			'status' => 'processing',
			'error_message' => null,
			'run_started_at' => current_time('mysql'),
		]);
	}

	/**
	 * @param array<string,mixed> $spec
	 */
	private function set_progress_for_step(
		int $task_id,
		array $spec,
		string $step,
		int $attempt = 0,
		int $max = self::MAX_ATTEMPTS,
		bool $pending_retry = false,
		?WorkflowContext $context = null
	): void {
		$labels = (array) ($spec['progress_labels'] ?? []);
		if ($step === 'finalizing_publish') {
			$key = 'finalizing';
		} elseif ($step === 'init') {
			$key = 'starting';
		} else {
			$key = $step;
		}
		$base = (string) ($labels[$key] ?? ucfirst(str_replace('_', ' ', $key)));
		if ($step === 'scraping' && strpos($base, '%s') !== false) {
			$domain = 'source';
			if ($context) {
				$domain = trim((string) $context->get('research_domain', ''));
				if ($domain === '') {
					$targets = (array) $context->get('research_targets', []);
					$first_url = '';
					if (!empty($targets) && is_array($targets[0] ?? null)) {
						$first_url = (string) ($targets[0]['url'] ?? '');
					}
					if ($first_url !== '') {
						$domain = (string) parse_url($first_url, PHP_URL_HOST);
					}
				}
			}
			if ($domain === '') {
				$domain = 'source';
			}
			$base = sprintf($base, $domain);
		}
		if ($pending_retry) {
			$base .= sprintf(' (retry %d/%d pending)', max(1, $attempt), $max);
		}
		$this->progress_service->update_progress($task_id, $base);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function build_payload_fingerprint(array $payload): string
	{
		$parts = [
			'topic' => (string) ($payload['topic'] ?? ''),
			'research_url' => (string) ($payload['research_url'] ?? ''),
			'keywords' => (string) ($payload['keywords'] ?? ''),
			'campaign_type' => (string) ($payload['campaign_type'] ?? ''),
			'content_fields' => (array) ($payload['content_fields'] ?? []),
		];
		return hash('sha256', wp_json_encode($parts) ?: '');
	}

	/**
	 * @param array<string,mixed> $before_context
	 * @return array<string,mixed>|string|int|null
	 */
	private function build_step_response(string $step, WorkflowContext $context, array $before_context)
	{
		$current = $context->to_array();
		$added_or_changed = [];
		foreach ($current as $key => $value) {
			if ($key === 'payload') {
				continue;
			}
			if (!array_key_exists($key, $before_context) || $before_context[$key] !== $value) {
				$added_or_changed[$key] = $value;
			}
		}

		switch ($step) {
			case 'init':
				return $this->trim_for_log(['status' => 'starting']);
			case 'researching':
				return $this->trim_for_log([
					'research_targets' => (array) ($current['research_targets'] ?? []),
				]);
			case 'analysis':
				return $this->trim_for_log((array) ($current['analysis'] ?? []));
			case 'preliminary_plan':
				return $this->trim_for_log((array) ($current['preliminary_plan'] ?? []));
			case 'scraping':
				$scrape_state = (array) ($current['research_scrape_state'] ?? []);
				return $this->trim_for_log([
					'research_items_count' => count((array) ($current['research_items'] ?? [])),
					'research_scrape_state' => [
						'current_index' => (int) ($scrape_state['current_index'] ?? 0),
						'processed_count' => (int) ($scrape_state['processed_count'] ?? 0),
						'success_count' => (int) ($scrape_state['success_count'] ?? 0),
						'failed_count' => (int) ($scrape_state['failed_count'] ?? 0),
						'completed' => !empty($scrape_state['completed']),
						'last_domain' => (string) ($scrape_state['last_domain'] ?? ''),
						'errors' => array_slice((array) ($scrape_state['errors'] ?? []), 0, 3),
					],
				]);
			case 'outline':
				return $this->trim_for_log((array) ($current['outline'] ?? []));
			case 'internal_links':
				return $this->trim_for_log((array) ($current['internal_links'] ?? []));
			case 'writing':
				return $this->trim_for_log(['draft_markdown' => (string) ($current['draft_markdown'] ?? '')]);
			case 'extras':
				return $this->trim_for_log([
					'post_title' => (string) ($current['post_title'] ?? ''),
					'post_slug' => (string) ($current['post_slug'] ?? ''),
					'post_content_html' => (string) ($current['post_content_html'] ?? ''),
				]);
			case 'custom_fields':
				return $this->trim_for_log((array) ($current['custom_fields'] ?? []));
			case 'featured_image':
				return $this->trim_for_log(['featured_image_id' => (int) ($current['featured_image_id'] ?? 0)]);
			case 'taxonomies':
				return $this->trim_for_log((array) ($current['taxonomies'] ?? []));
			case 'finalizing_publish':
				return $this->trim_for_log((array) ($current['publish_result'] ?? []));
			default:
				return $this->trim_for_log($added_or_changed);
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function trim_for_log($value, int $max_len = 2200)
	{
		if (is_string($value)) {
			if (strlen($value) <= $max_len) {
				return $value;
			}
			return substr($value, 0, $max_len) . '... [truncated]';
		}

		if (is_array($value)) {
			$out = [];
			foreach ($value as $k => $v) {
				$out[$k] = $this->trim_for_log($v, $max_len);
			}
			return $out;
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed>|string|int|null $response
	 */
	private function log_step_run(
		int $task_id,
		string $step,
		float $start_ts,
		float $end_ts,
		array $input,
		$response,
		string $next_step
	): void
	{
		if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
			return;
		}

		$payload = [
			'task_id' => $task_id,
			'step' => $step,
			'start_time' => $this->format_ts($start_ts),
			'end_time' => $this->format_ts($end_ts),
			'execution_time' => $this->format_duration(max(0, $end_ts - $start_ts)),
			'input' => $this->trim_for_log($input),
			'response' => $this->trim_for_log($response),
			'next_step' => $next_step,
		];
		error_log('[PostStation][StepRun] ' . (wp_json_encode($payload) ?: '{}'));
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function build_step_input(string $step, array $context): array
	{
		switch ($step) {
			case 'init':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'research_url' => (string) (($context['payload']['research_url'] ?? '')),
				]);
			case 'researching':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'research_url' => (string) (($context['payload']['research_url'] ?? '')),
					'sources_count' => (int) (($context['payload']['content_fields']['body']['sources_count'] ?? 3)),
				]);
			case 'scraping':
				return $this->trim_for_log([
					'research_targets' => (array) ($context['research_targets'] ?? []),
					'research_scrape_state' => (array) ($context['research_scrape_state'] ?? []),
				]);
			case 'analysis':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'research_items' => (array) ($context['research_items'] ?? []),
				]);
			case 'preliminary_plan':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'keywords' => (string) (($context['payload']['keywords'] ?? '')),
					'research_items' => (array) ($context['research_items'] ?? []),
				]);
			case 'outline':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'keywords' => (string) (($context['payload']['keywords'] ?? '')),
					'analysis' => (array) ($context['analysis'] ?? []),
					'preliminary_plan' => (array) ($context['preliminary_plan'] ?? []),
					'research_items' => (array) ($context['research_items'] ?? []),
				]);
			case 'internal_links':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'internal_links_count' => (int) (($context['payload']['content_fields']['body']['internal_links_count'] ?? 4)),
					'outline' => (array) ($context['outline'] ?? []),
					'sitemap' => (array) (($context['payload']['sitemap'] ?? [])),
				]);
			case 'writing':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'outline' => (array) ($context['outline'] ?? []),
					'analysis' => (array) ($context['analysis'] ?? []),
					'preliminary_plan' => (array) ($context['preliminary_plan'] ?? []),
					'research_items' => (array) ($context['research_items'] ?? []),
					'sitemap' => (array) (($context['payload']['sitemap'] ?? [])),
				]);
			case 'extras':
				return $this->trim_for_log([
					'topic' => (string) (($context['payload']['topic'] ?? '')),
					'draft_markdown' => (string) ($context['draft_markdown'] ?? ''),
					'title_override' => (string) (($context['payload']['title_override'] ?? '')),
					'slug_override' => (string) (($context['payload']['slug_override'] ?? '')),
				]);
			case 'custom_fields':
				return $this->trim_for_log([
					'custom_fields_config' => (array) (($context['payload']['content_fields']['custom_fields'] ?? [])),
					'post_content_html' => (string) ($context['post_content_html'] ?? ''),
					'custom_fields_state' => (array) ($context['custom_fields_state'] ?? []),
				]);
			case 'featured_image':
				return $this->trim_for_log([
					'feature_image_id' => (int) (($context['payload']['feature_image_id'] ?? 0)),
					'image_config' => (array) (($context['payload']['content_fields']['image'] ?? [])),
					'post_title' => (string) ($context['post_title'] ?? ''),
					'featured_image_state' => (array) ($context['featured_image_state'] ?? []),
				]);
			case 'taxonomies':
				return $this->trim_for_log([
					'taxonomies_config' => (array) (($context['payload']['content_fields']['taxonomies'] ?? [])),
					'post_content_html' => (string) ($context['post_content_html'] ?? ''),
					'taxonomies_state' => (array) ($context['taxonomies_state'] ?? []),
				]);
			case 'finalizing_publish':
				return $this->trim_for_log([
					'post_title' => (string) ($context['post_title'] ?? ''),
					'post_slug' => (string) ($context['post_slug'] ?? ''),
					'post_content_html' => (string) ($context['post_content_html'] ?? ''),
					'featured_image_id' => (int) ($context['featured_image_id'] ?? 0),
					'taxonomies' => (array) ($context['taxonomies'] ?? []),
					'custom_fields' => (array) ($context['custom_fields'] ?? []),
				]);
			default:
				return $this->trim_for_log([]);
		}
	}

	private function format_ts(float $ts): string
	{
		$seconds = (int) floor($ts);
		$millis = (int) round(($ts - $seconds) * 1000);
		return wp_date('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $millis) . ' ' . wp_timezone_string();
	}

	private function format_duration(float $seconds): string
	{
		if ($seconds < 60) {
			return round($seconds, 3) . ' sec';
		}
		if ($seconds < 3600) {
			return round($seconds / 60, 3) . ' min';
		}
		return round($seconds / 3600, 3) . ' hr';
	}

	private function is_realtime_none(WorkflowContext $context): bool
	{
		$payload = (array) $context->get('payload', []);
		$mode = strtolower(trim((string) ($payload['content_fields']['body']['research_mode'] ?? 'perplexity')));
		return $mode === 'none';
	}
}
