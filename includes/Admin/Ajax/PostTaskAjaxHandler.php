<?php

namespace PostStation\Admin\Ajax;

use PostStation\Models\Campaign;
use PostStation\Models\PostTask;

class PostTaskAjaxHandler
{
	public function create_posttask(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['campaign_id'] ?? 0);
		$campaign = Campaign::get_by_id($campaign_id);
		$article_type = $campaign['article_type'] ?? 'default';
		$task_id = PostTask::create([
			'campaign_id' => $campaign_id,
			'article_type' => $article_type,
			'status' => 'pending',
		]);

		if (!$task_id) {
			wp_send_json_error(['message' => 'Failed to create task']);
		}

		wp_send_json_success(['id' => $task_id, 'task' => PostTask::get_by_id($task_id)]);
	}

	public function update_posttasks(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$tasks = json_decode(stripslashes($_POST['tasks'] ?? ''), true);
		if (!is_array($tasks)) {
			wp_send_json_error(['message' => 'Invalid tasks data']);
		}

		foreach ($tasks as $task) {
			$task_id = (int) ($task['id'] ?? 0);
			$task_type = sanitize_text_field((string) ($task['article_type'] ?? 'default'));
			if ($task_type === 'rewrite_blog_post') {
				if ($this->is_blank($task['research_url'] ?? null)) {
					wp_send_json_error(['message' => sprintf('Task #%d: Research URL is required for rewrite type.', $task_id)]);
				}
				continue;
			}

			if ($this->is_blank($task['topic'] ?? null)) {
				wp_send_json_error(['message' => sprintf('Task #%d: Topic is required.', $task_id)]);
			}
		}

		foreach ($tasks as $task) {
			$id = (int) ($task['id'] ?? 0);
			unset($task['id']);
			PostTask::update($id, $task);
		}

		wp_send_json_success();
	}

	public function delete_posttask(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (PostTask::delete($id)) {
			wp_send_json_success();
		}
		wp_send_json_error(['message' => 'Failed to delete task']);
	}

	public function clear_completed_posttasks(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['campaign_id'] ?? 0);
		global $wpdb;
		$table_name = $wpdb->prefix . PostTask::get_table_name();
		$wpdb->delete($table_name, ['campaign_id' => $campaign_id, 'status' => 'completed']);
		wp_send_json_success();
	}

	public function import_posttasks(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['campaign_id'] ?? 0);
		$file = $_FILES['file'] ?? null;
		if (!$file || !file_exists($file['tmp_name'])) {
			wp_send_json_error(['message' => 'No file uploaded']);
		}

		$content = file_get_contents($file['tmp_name']);
		$tasks = json_decode($content, true);
		if (!is_array($tasks)) {
			wp_send_json_error(['message' => 'Invalid file format']);
		}

		foreach ($tasks as $task_data) {
			$task_data['campaign_id'] = $campaign_id;
			$task_data['status'] = 'pending';
			PostTask::create($task_data);
		}

		wp_send_json_success();
	}

	private function is_blank($value): bool
	{
		return trim((string) ($value ?? '')) === '';
	}
}
