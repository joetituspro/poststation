<?php

namespace PostStation\Services;

use PostStation\Models\PostBlock;

class BackgroundRunner
{
	public const ACTION_CHECK_BLOCK_STATUS = 'poststation_check_block_status';
	private const CHECK_INTERVAL = 30;
	private const TIMEOUT_SECONDS = 65;

	public function init(): void
	{
		add_action(self::ACTION_CHECK_BLOCK_STATUS, [$this, 'handle_check_block_status'], 10, 4);
	}

	public function schedule_status_check(int $postwork_id, int $block_id, int $webhook_id, int $attempt = 0): void
	{
		if (!function_exists('as_schedule_single_action')) {
			return;
		}

		$args = [
			'postwork_id' => $postwork_id,
			'block_id' => $block_id,
			'webhook_id' => $webhook_id,
			'attempt' => $attempt,
		];

		$already_scheduled = function_exists('as_has_scheduled_action')
			? as_has_scheduled_action(self::ACTION_CHECK_BLOCK_STATUS, $args, $this->get_group($postwork_id))
			: false;

		if ($already_scheduled) {
			return;
		}

		as_schedule_single_action(
			time() + self::CHECK_INTERVAL,
			self::ACTION_CHECK_BLOCK_STATUS,
			$args,
			$this->get_group($postwork_id)
		);
	}

	public function handle_check_block_status(int $postwork_id, int $block_id, int $webhook_id, int $attempt = 0): void
	{
		$block = PostBlock::get_by_id($block_id);
		if (!$block) {
			return;
		}

		if ($block['status'] === 'completed') {
			$this->start_next_block($postwork_id, $webhook_id);
			return;
		}

		if ($block['status'] === 'failed') {
			$this->start_next_block($postwork_id, $webhook_id);
			return;
		}

		if ($block['status'] === 'processing' && !empty($block['error_message'])) {
			PostBlock::update($block_id, [
				'status' => 'failed',
				'error_message' => $block['error_message'],
			]);
			$this->start_next_block($postwork_id, $webhook_id);
			return;
		}

		if ($block && $this->is_timed_out($block)) {
			// Update block status to failed
			PostBlock::update($block_id, [
				'status' => 'failed',
				'error_message' => __('Status check timed out.', 'poststation'),
			]);

			$this->start_next_block($postwork_id, $webhook_id);
			return;
		}

		if ($block['status'] === 'processing') {
			$this->schedule_status_check($postwork_id, $block_id, $webhook_id, $attempt + 1);
			return;
		}

		if ($block['status'] === 'pending') {
			return;
		}

		$this->start_next_block($postwork_id, $webhook_id);
	}

	public function cancel_run(int $postwork_id): bool
	{
		if (!function_exists('as_unschedule_all_actions')) {
			return false;
		}

		as_unschedule_all_actions(self::ACTION_CHECK_BLOCK_STATUS, [], $this->get_group($postwork_id));

		// Update any processing blocks to pending	 					
		global $wpdb;
		$table_name = $wpdb->prefix . PostBlock::get_table_name();
		$wpdb->update(
			$table_name,
			['status' => 'pending'],
			[
				'postwork_id' => $postwork_id,
				'status' => 'processing'
			]
		);

		return true;
	}

	private function start_next_block(int $postwork_id, int $webhook_id): void
	{
		if ($this->has_processing_block_only($postwork_id)) {
			return;
		}

		$block = $this->get_next_block($postwork_id);
		if (!$block) {
			if (function_exists('as_unschedule_all_actions')) {
				as_unschedule_all_actions(self::ACTION_CHECK_BLOCK_STATUS, [], $this->get_group($postwork_id));
			}
			return;
		}

		$result = BlockRunner::dispatch_block($postwork_id, (int)$block['id'], $webhook_id);
		if (!$result['success']) {
			return;
		}

		$this->schedule_status_check($postwork_id, (int)$block['id'], $webhook_id, 0);
	}

	private function get_next_block(int $postwork_id): ?array
	{
		$blocks = PostBlock::get_by_postwork($postwork_id);
		foreach ($blocks as $block) {
			if ($block['status'] === 'pending') {
				return $block;
			}
		}
		return null;
	}

	private function has_processing_block(int $postwork_id): bool
	{
		$blocks = PostBlock::get_by_postwork($postwork_id);
		foreach ($blocks as $block) {
			if ($block['status'] === 'processing') {
				return true;
			}
		}
		if (function_exists('as_get_scheduled_actions')) {
			$group = $this->get_group($postwork_id);
			$pending = as_get_scheduled_actions([
				'hook' => self::ACTION_CHECK_BLOCK_STATUS,
				'group' => $group,
				'status' => 'pending',
				'per_page' => 1,
			]);
			if (!empty($pending)) {
				return true;
			}
			$in_progress = as_get_scheduled_actions([
				'hook' => self::ACTION_CHECK_BLOCK_STATUS,
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

	private function has_processing_block_only(int $postwork_id): bool
	{
		$blocks = PostBlock::get_by_postwork($postwork_id);
		foreach ($blocks as $block) {
			if ($block['status'] === 'processing') {
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

	private function get_group(int $postwork_id): string
	{
		return 'poststation_' . $postwork_id;
	}
}
