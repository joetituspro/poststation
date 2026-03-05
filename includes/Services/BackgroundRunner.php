<?php

namespace PostStation\Services;

use PostStation\Models\Campaign;
use PostStation\Models\PostTask;
use PostStation\Models\TaskExecutionState;
use PostStation\Services\Workflow\LocalWorkflowRunner;

class BackgroundRunner
{
	private const TIMEOUT_SECONDS = 30 * 5;
	private const MAX_DISPATCH_ATTEMPTS = 50;

	public function init(): void
	{
	}

	public function start_run_for_task(int $campaign_id, int $task_id): bool
	{
		$result = TaskRunner::dispatch_task($campaign_id, $task_id);
		return !empty($result['success']);
	}

	public function start_run_if_pending(int $campaign_id): bool
	{
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign || ($campaign['status'] ?? '') !== 'active') {
			return false;
		}
		if ($this->has_processing_task_only($campaign_id)) {
			return false;
		}
		$task = $this->get_next_task($campaign_id);
		if (!$task) {
			return false;
		}

		return $this->start_run_for_task($campaign_id, (int) $task['id']);
	}

	public function cancel_run(int $campaign_id): bool
	{
		$states = TaskExecutionState::get_by_campaign_id($campaign_id);
		foreach ($states as $state) {
			$task_id = (int) ($state['task_id'] ?? 0);
			if ($task_id > 0) {
				TaskExecutionState::mark_terminal($task_id, 'cancelled', 'Cancelled by user.');
			}
		}
		TaskExecutionState::delete_by_campaign_id($campaign_id);

		global $wpdb;
		$table_name = $wpdb->prefix . PostTask::get_table_name();
		$wpdb->update(
			$table_name,
			[
				'status' => 'pending',
				'progress' => null,
			],
			[
				'campaign_id' => $campaign_id,
				'status' => 'processing'
			]
		);

		return true;
	}

	public function cancel_task_run(int $task_id): bool
	{
		$task = PostTask::get_by_id($task_id);
		if (!$task || ($task['status'] ?? '') !== 'processing') {
			return false;
		}

		$state = TaskExecutionState::get_by_task_id($task_id);
		if ($state) {
			TaskExecutionState::mark_terminal($task_id, 'cancelled', 'Cancelled by user.');
		}
		TaskExecutionState::delete_by_task_id($task_id);

		PostTask::update($task_id, [
			'status' => 'cancelled',
			'progress' => null,
			'error_message' => null,
		]);

		return true;
	}

	/**
	 * Local-only update loop.
	 * - Always resumes local processing tasks (active or paused campaigns).
	 * - Auto-starts pending tasks only for active campaigns.
	 */
	public function handle_live_update(): void
	{
		global $wpdb;
		TaskExecutionState::delete_orphans();

		$campaign_table = $wpdb->prefix . Campaign::get_table_name();
		$campaigns = $wpdb->get_results(
			"SELECT id, status FROM {$campaign_table}",
			ARRAY_A
		);
		if (empty($campaigns)) {
			return;
		}

		foreach ($campaigns as $campaign) {
			$campaign_id = (int) ($campaign['id'] ?? 0);
			if ($campaign_id <= 0) {
				continue;
			}

			$tasks = PostTask::get_by_campaign($campaign_id);
			if (empty($tasks)) {
				continue;
			}

			$processing_task = null;
			foreach ($tasks as $task) {
				if (($task['status'] ?? '') === 'processing') {
					$processing_task = $task;
					break;
				}
			}

			if ($processing_task) {
				$this->handle_local_processing_task($processing_task, $campaign_id, $tasks);
				continue;
			}

			if (($campaign['status'] ?? '') === 'active') {
				$this->start_next_task($campaign_id, $tasks);
			}
		}
	}

	private function handle_local_processing_task(array $processing_task, int $campaign_id, array $tasks): void
	{
		$task_id = (int) ($processing_task['id'] ?? 0);
		if ($task_id <= 0) {
			return;
		}

		$state = TaskExecutionState::get_by_task_id($task_id);
		if ($state) {
			$runner = new LocalWorkflowRunner();
			try {
				$runner->resume_from_state($task_id);
			} catch (\Throwable $e) {
				PostTask::update($task_id, [
					'status' => 'failed',
					'error_message' => $e->getMessage(),
					'progress' => null,
				]);
				return;
			}

			$latest = PostTask::get_by_id($task_id);
			if (($latest['status'] ?? '') !== 'processing') {
				$campaign = Campaign::get_by_id($campaign_id);
				if (($campaign['status'] ?? '') === 'active') {
					$this->start_next_task($campaign_id, $tasks);
				}
			}
			return;
		}

		if (!empty($processing_task['error_message']) || $this->is_timed_out($processing_task)) {
			PostTask::update($task_id, [
				'status' => 'failed',
				'error_message' => !empty($processing_task['error_message'])
					? $processing_task['error_message']
					: __('Status check timed out.', 'poststation'),
				'progress' => null,
			]);
		}
	}

	private function start_next_task(int $campaign_id, ?array $preloaded_tasks = null): void
	{
		$tasks = $preloaded_tasks ?? PostTask::get_by_campaign($campaign_id);
		$attempts = 0;
		foreach ($tasks as $task) {
			if ($attempts >= self::MAX_DISPATCH_ATTEMPTS) {
				break;
			}
			if (($task['status'] ?? '') !== 'pending') {
				continue;
			}
			if (!PostTask::has_required_data_for_dispatch($task)) {
				continue;
			}
			$attempts++;
			$result = TaskRunner::dispatch_task($campaign_id, (int) $task['id']);
			if (!empty($result['success'])) {
				return;
			}
		}
	}

	private function get_next_task(int $campaign_id): ?array
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		foreach ($tasks as $task) {
			if (($task['status'] ?? '') !== 'pending') {
				continue;
			}
			if (PostTask::has_required_data_for_dispatch($task)) {
				return $task;
			}
		}
		return null;
	}

	private function has_processing_task_only(int $campaign_id): bool
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		foreach ($tasks as $task) {
			if (($task['status'] ?? '') === 'processing') {
				return true;
			}
		}
		return false;
	}

	private function is_timed_out(array $block): bool
	{
		$started_at = $block['run_started_at'] ?? null;
		if (empty($started_at)) {
			return false;
		}

		$started_ts = strtotime($started_at);
		if (!$started_ts) {
			return false;
		}

		$now = current_time('timestamp');
		return ($now - $started_ts) > self::TIMEOUT_SECONDS;
	}
}
