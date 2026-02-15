<?php

namespace PostStation\Services;

use PostStation\Models\PostTask;

class BackgroundRunner
{
	public const ACTION_CHECK_TASK_STATUS = 'poststation_check_posttask_status';
	private const CHECK_INTERVAL = 30;
	private const TIMEOUT_SECONDS = 65;

	public function init(): void
	{
		add_action(self::ACTION_CHECK_TASK_STATUS, [$this, 'handle_check_task_status'], 10, 4);
	}

	public function schedule_status_check(int $campaign_id, int $task_id, int $webhook_id, int $attempt = 0): void
	{
		if (!function_exists('as_schedule_single_action')) {
			return;
		}

		$args = [
			'campaign_id' => $campaign_id,
			'task_id' => $task_id,
			'webhook_id' => $webhook_id,
			'attempt' => $attempt,
		];

		$already_scheduled = function_exists('as_has_scheduled_action')
			? as_has_scheduled_action(self::ACTION_CHECK_TASK_STATUS, $args, $this->get_group($campaign_id))
			: false;

		if ($already_scheduled) {
			return;
		}

		as_schedule_single_action(
			time() + self::CHECK_INTERVAL,
			self::ACTION_CHECK_TASK_STATUS,
			$args,
			$this->get_group($campaign_id)
		);
	}

	public function handle_check_task_status(int $campaign_id, int $task_id, int $webhook_id, int $attempt = 0): void
	{
		$task = PostTask::get_by_id($task_id);
		if (!$task) {
			return;
		}

		if ($task['status'] === 'completed') {
			$this->start_next_task($campaign_id, $webhook_id);
			return;
		}

		if ($task['status'] === 'failed') {
			$this->start_next_task($campaign_id, $webhook_id);
			return;
		}

		if ($task['status'] === 'processing' && !empty($task['error_message'])) {
			PostTask::update($task_id, [
				'status' => 'failed',
				'error_message' => $task['error_message'],
			]);
			$this->start_next_task($campaign_id, $webhook_id);
			return;
		}

		if ($task && $this->is_timed_out($task)) {
			PostTask::update($task_id, [
				'status' => 'failed',
				'error_message' => __('Status check timed out.', 'poststation'),
			]);

			$this->start_next_task($campaign_id, $webhook_id);
			return;
		}

		if ($task['status'] === 'processing') {
			$this->schedule_status_check($campaign_id, $task_id, $webhook_id, $attempt + 1);
			return;
		}

		if ($task['status'] === 'pending') {
			return;
		}

		$this->start_next_task($campaign_id, $webhook_id);
	}

	public function cancel_run(int $campaign_id): bool
	{
		if (!function_exists('as_unschedule_all_actions')) {
			return false;
		}

		as_unschedule_all_actions(self::ACTION_CHECK_TASK_STATUS, [], $this->get_group($campaign_id));

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

	private function start_next_task(int $campaign_id, int $webhook_id): void
	{
		if ($this->has_processing_task_only($campaign_id)) {
			return;
		}

		$task = $this->get_next_task($campaign_id);
		if (!$task) {
			if (function_exists('as_unschedule_all_actions')) {
				as_unschedule_all_actions(self::ACTION_CHECK_TASK_STATUS, [], $this->get_group($campaign_id));
			}
			return;
		}

		$result = TaskRunner::dispatch_task($campaign_id, (int) $task['id'], $webhook_id);
		if (!$result['success']) {
			return;
		}

		$this->schedule_status_check($campaign_id, (int) $task['id'], $webhook_id, 0);
	}

	private function get_next_task(int $campaign_id): ?array
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		foreach ($tasks as $task) {
			if ($task['status'] === 'pending') {
				return $task;
			}
		}
		return null;
	}

	private function has_processing_task(int $campaign_id): bool
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		foreach ($tasks as $task) {
			if ($task['status'] === 'processing') {
				return true;
			}
		}
		if (function_exists('as_get_scheduled_actions')) {
			$group = $this->get_group($campaign_id);
			$pending = as_get_scheduled_actions([
				'hook' => self::ACTION_CHECK_TASK_STATUS,
				'group' => $group,
				'status' => 'pending',
				'per_page' => 1,
			]);
			if (!empty($pending)) {
				return true;
			}
			$in_progress = as_get_scheduled_actions([
				'hook' => self::ACTION_CHECK_TASK_STATUS,
				'group' => $group,
				'status' => 'in-progress',
				'per_page' => 1,
			]);
			if (!empty($in_progress)) {
				return true;
			}
		}
		return false;
	}

	private function has_processing_task_only(int $campaign_id): bool
	{
		$tasks = PostTask::get_by_campaign($campaign_id);
		foreach ($tasks as $task) {
			if ($task['status'] === 'processing') {
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

	private function get_group(int $campaign_id): string
	{
		return 'poststation_campaign_' . $campaign_id;
	}
}
