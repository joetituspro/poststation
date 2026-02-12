<?php

namespace PostStation\Admin\Ajax;

use PostStation\Admin\BootstrapDataProvider;
use PostStation\Models\Campaign;
use PostStation\Models\PostTask;
use PostStation\Services\BackgroundRunner;
use PostStation\Services\BlockRunner;

class CampaignAjaxHandler
{
	private BootstrapDataProvider $bootstrap_provider;

	public function __construct(?BootstrapDataProvider $bootstrap_provider = null)
	{
		$this->bootstrap_provider = $bootstrap_provider ?? new BootstrapDataProvider();
	}

	public function get_campaigns(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		wp_send_json_success(['campaigns' => $this->bootstrap_provider->get_campaigns_with_counts()]);
	}

	public function get_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}

		$campaign = Campaign::get_by_id($id);
		if (!$campaign) {
			wp_send_json_error(['message' => 'Campaign not found']);
		}

		wp_send_json_success([
			'campaign' => $campaign,
			'tasks' => PostTask::get_by_campaign($id),
			'users' => $this->bootstrap_provider->get_user_data(),
			'taxonomies' => $this->bootstrap_provider->get_taxonomy_data(),
		]);
	}

	public function create_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		if ($title === '') {
			wp_send_json_error(['message' => 'Title is required']);
		}

		$campaign_id = Campaign::create(['title' => $title]);
		if (!$campaign_id) {
			wp_send_json_error(['message' => 'Failed to create campaign']);
		}

		wp_send_json_success(['id' => $campaign_id]);
	}

	public function update_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		if ($campaign_id <= 0 || $title === '') {
			wp_send_json_error(['message' => 'Invalid payload']);
		}

		$success = Campaign::update($campaign_id, [
			'title' => $title,
			'webhook_id' => (int) ($_POST['webhook_id'] ?? 0) ?: null,
			'article_type' => sanitize_text_field($_POST['article_type'] ?? 'blog_post'),
			'language' => sanitize_text_field($_POST['language'] ?? 'en'),
			'target_country' => sanitize_text_field($_POST['target_country'] ?? 'international'),
			'post_type' => sanitize_text_field($_POST['post_type'] ?? 'post'),
			'post_status' => sanitize_text_field($_POST['post_status'] ?? 'pending'),
			'default_author_id' => (int) ($_POST['default_author_id'] ?? 0) ?: get_current_user_id(),
			'content_fields' => wp_unslash($_POST['content_fields'] ?? '{}'),
		]);

		if (!$success) {
			wp_send_json_error(['message' => 'Failed to update campaign']);
		}
		wp_send_json_success();
	}

	public function delete_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		PostTask::delete_by_campaign($campaign_id);
		if (!Campaign::delete($campaign_id)) {
			wp_send_json_error(['message' => 'Failed to delete campaign']);
		}
		wp_send_json_success();
	}

	public function run_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		$task_id = (int) ($_POST['task_id'] ?? 0);
		$webhook_id = (int) ($_POST['webhook_id'] ?? 0);

		$result = BlockRunner::dispatch_task($campaign_id, $task_id, $webhook_id);
		if (!$result['success']) {
			wp_send_json_error(['message' => $result['message'] ?? 'Failed to run task']);
		}

		$runner = new BackgroundRunner();
		$runner->schedule_status_check($campaign_id, $task_id, $webhook_id, 0);

		wp_send_json_success([
			'message' => 'Task sent to webhook for processing',
			'task_id' => $task_id,
		]);
	}

	public function stop_campaign_run(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		$runner = new BackgroundRunner();
		$runner->cancel_run($campaign_id);
		wp_send_json_success();
	}

	public function export_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign) {
			wp_send_json_error(['message' => 'Campaign not found']);
		}

		$tasks = PostTask::get_by_campaign($campaign_id);
		unset($campaign['id'], $campaign['created_at'], $campaign['updated_at']);
		foreach ($tasks as &$task) {
			unset($task['id'], $task['campaign_id'], $task['post_id'], $task['created_at'], $task['updated_at']);
		}

		wp_send_json_success([
			'campaign' => $campaign,
			'tasks' => $tasks,
		]);
	}

	public function import_campaign(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$file = $_FILES['file'] ?? null;
		if (!$file || !file_exists($file['tmp_name'])) {
			wp_send_json_error(['message' => 'No file uploaded']);
		}

		$content = file_get_contents($file['tmp_name']);
		$data = json_decode($content, true);
		if (!$data || !isset($data['campaign'])) {
			wp_send_json_error(['message' => 'Invalid file format']);
		}

		$campaign_id = Campaign::create($data['campaign']);
		if (!$campaign_id) {
			wp_send_json_error(['message' => 'Failed to create campaign']);
		}

		if (!empty($data['tasks'])) {
			foreach ($data['tasks'] as $task_data) {
				$task_data['campaign_id'] = $campaign_id;
				$task_data['status'] = 'pending';
				PostTask::create($task_data);
			}
		}

		wp_send_json_success(['id' => $campaign_id]);
	}
}
