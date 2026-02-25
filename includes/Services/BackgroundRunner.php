<?php

namespace PostStation\Services;

use PostStation\Models\Campaign;
use PostStation\Models\PostTask;

class BackgroundRunner
{
	private const TIMEOUT_SECONDS = 30 * 5; // 5 minutes
	private const MAX_DISPATCH_ATTEMPTS = 50;

	public function init(): void
	{
	}

	/**
	 * Dispatch one task. Used by manual Run and by start_run_if_pending.
	 */
	public function start_run_for_task(int $campaign_id, int $task_id, int $webhook_id): bool
	{
		$result = TaskRunner::dispatch_task($campaign_id, $task_id, $webhook_id);
		if (empty($result['success'])) {
			return false;
		}
		return true;
	}

	/**
	 * If campaign is active and has pending tasks with no run in progress, start the first pending task.
	 */
	public function start_run_if_pending(int $campaign_id): bool
	{
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign || empty($campaign['webhook_id']) || ($campaign['status'] ?? '') !== 'active') {
			return false;
		}
		if ($this->has_processing_task_only($campaign_id)) {
			return false;
		}
		$task = $this->get_next_task($campaign_id);
		if (!$task) {
			return false;
		}
		return $this->start_run_for_task($campaign_id, (int) $task['id'], (int) $campaign['webhook_id']);
	}

	public function cancel_run(int $campaign_id): bool
	{
		// Update any processing tasks to pending
		global $wpdb;
		$table_name = $wpdb->prefix . PostTask::get_table_name();
		$wpdb->update(
			$table_name,
			['status' => 'pending'],
			[
				'campaign_id' => $campaign_id,
				'status' => 'processing'
			]
		);

		return true;
	}

	/**
	 * Global live update handler, called from GlobalUpdateService on each poststation_update tick.
	 * For each active campaign with a webhook:
	 * - If there is a processing task, check for timeout and, if timed out, mark failed and dispatch next.
	 * - If there is no processing task but there is a pending task, dispatch one pending task.
	 */
	public function handle_live_update(): void
	{
		global $wpdb;

		$campaign_table = $wpdb->prefix . Campaign::get_table_name();
		$campaigns = $wpdb->get_results(
			"SELECT id, webhook_id FROM {$campaign_table} WHERE status = 'active' AND webhook_id IS NOT NULL",
			ARRAY_A
		);

		if (empty($campaigns)) {
			return;
		}

		foreach ($campaigns as $campaign) {
			$campaign_id = (int) $campaign['id'];
			$webhook_id = (int) $campaign['webhook_id'];
			if ($campaign_id <= 0 || $webhook_id <= 0) {
				continue;
			}

			$this->handle_live_update_for_campaign($campaign_id, $webhook_id);
		}
	}

	private function handle_live_update_for_campaign(int $campaign_id, int $webhook_id): void
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		if (empty($tasks)) {
			return;
		}

		$processing_task = null;
		foreach ($tasks as $task) {
			if (($task['status'] ?? '') === 'processing') {
				$processing_task = $task;
				break;
			}
		}

		if ($processing_task) {
			// If processing task has an explicit error or is timed out, mark failed and move on to next.
			if (!empty($processing_task['error_message']) || $this->is_timed_out($processing_task)) {
				PostTask::update((int) $processing_task['id'], [
					'status' => 'failed',
					'error_message' => !empty($processing_task['error_message'])
						? $processing_task['error_message']
						: __('Status check timed out.', 'poststation'),
				]);

				$this->start_next_task($campaign_id, $webhook_id, $tasks);
			}

			// If still processing and not timed out, do nothing for this campaign this tick.
			return;
		}

		// No processing task; try to start the next pending one.
		$this->start_next_task($campaign_id, $webhook_id, $tasks);
	}

	private function start_next_task(int $campaign_id, int $webhook_id, ?array $preloaded_tasks = null): void
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
			$result = TaskRunner::dispatch_task($campaign_id, (int) $task['id'], $webhook_id);
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

	private function has_processing_task(int $campaign_id): bool
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		foreach ($tasks as $task) {
			if (($task['status'] ?? '') === 'processing') {
				return true;
			}
		}
		return false;
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
