<?php

namespace PostStation\Admin\Ajax;

use PostStation\Models\Campaign;
use PostStation\Models\PostTask;
use PostStation\Services\RssService;
use PostStation\Services\RssTaskProcessor;

class RssAjaxHandler
{
	public function run_rss_now(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		if (!$campaign_id) {
			wp_send_json_error(['message' => 'Invalid campaign ID']);
		}

		try {
			$response = RssService::run_rss_check($campaign_id);
			wp_send_json_success($response);
		} catch (\Throwable $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	public function rss_add_to_tasks(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		if (!$campaign_id) {
			wp_send_json_error(['message' => 'Invalid campaign ID']);
		}

		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign) {
			wp_send_json_error(['message' => 'Campaign not found']);
		}

		$items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
		if (is_string($items_raw)) {
			$items = json_decode($items_raw, true);
		} else {
			$items = $items_raw;
		}
		if (!is_array($items)) {
			wp_send_json_error(['message' => 'Invalid items payload']);
		}

		$result = RssTaskProcessor::process_items_into_tasks($campaign_id, $items);

		$tasks = [];
		foreach ($result['task_ids'] as $tid) {
			$task = PostTask::get_by_id($tid);
			if ($task) {
				$tasks[] = $task;
			}
		}

		wp_send_json_success(['count' => $result['count'], 'tasks' => $tasks]);
	}
}
