<?php

namespace PostStation\Admin\Ajax;

use PostStation\Admin\BootstrapDataProvider;
use PostStation\Models\Campaign;
use PostStation\Models\PostTask;
use PostStation\Services\BackgroundRunner;
use PostStation\Services\TaskRunner;

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

		$campaign_id = Campaign::create(['title' => 'Campaign']);
		if (!$campaign_id) {
			wp_send_json_error(['message' => 'Failed to create campaign']);
		}

		Campaign::update($campaign_id, ['title' => "Campaign #{$campaign_id}"]);

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

		$campaign_payload = [
			'title' => $title,
			'webhook_id' => (int) ($_POST['webhook_id'] ?? 0) ?: null,
			'article_type' => sanitize_text_field($_POST['article_type'] ?? 'default'),
			'tone_of_voice' => sanitize_text_field($_POST['tone_of_voice'] ?? 'none'),
			'point_of_view' => sanitize_text_field($_POST['point_of_view'] ?? 'none'),
			'readability' => sanitize_text_field($_POST['readability'] ?? 'grade_8'),
			'language' => sanitize_text_field($_POST['language'] ?? 'en'),
			'target_country' => sanitize_text_field($_POST['target_country'] ?? 'international'),
			'post_type' => sanitize_text_field($_POST['post_type'] ?? 'post'),
			'post_status' => sanitize_text_field($_POST['post_status'] ?? 'pending'),
			'default_author_id' => (int) ($_POST['default_author_id'] ?? 0) ?: get_current_user_id(),
			'content_fields' => wp_unslash($_POST['content_fields'] ?? '{}'),
		];

		$validation_error = $this->validate_campaign_payload($campaign_payload);
		if ($validation_error !== null) {
			wp_send_json_error(['message' => $validation_error]);
		}

		$success = Campaign::update($campaign_id, $campaign_payload);

		if (!$success) {
			wp_send_json_error(['message' => 'Failed to update campaign']);
		}
		wp_send_json_success();
	}

	private function validate_campaign_payload(array $payload): ?string
	{
		$required_fields = [
			'title' => 'Campaign title is required.',
			'article_type' => 'Campaign Article Type is required.',
			'tone_of_voice' => 'Campaign Tone of Voice is required.',
			'point_of_view' => 'Campaign Point of View is required.',
			'readability' => 'Campaign Readability is required.',
			'language' => 'Campaign Language is required.',
			'target_country' => 'Campaign Target Country is required.',
			'post_type' => 'Campaign Post Type is required.',
			'post_status' => 'Campaign Default Post Status is required.',
			'default_author_id' => 'Campaign Default Author is required.',
			'webhook_id' => 'Campaign Webhook is required.',
		];

		foreach ($required_fields as $field => $message) {
			if ($this->is_blank($payload[$field] ?? null)) {
				return $message;
			}
		}

		$content_fields = json_decode((string) ($payload['content_fields'] ?? '{}'), true);
		if (!is_array($content_fields)) {
			return 'Invalid content fields payload.';
		}

		$custom_fields = is_array($content_fields['custom_fields'] ?? null) ? $content_fields['custom_fields'] : [];
		foreach ($custom_fields as $index => $custom_field) {
			if (!is_array($custom_field)) {
				continue;
			}
			$position = (int) $index + 1;
			if ($this->is_blank($custom_field['meta_key'] ?? null)) {
				return sprintf('Custom Field %d: Meta Key is required.', $position);
			}
			if ($this->is_blank($custom_field['prompt'] ?? null)) {
				return sprintf('Custom Field %d: Generation Prompt is required.', $position);
			}
			if ($this->is_blank($custom_field['prompt_context'] ?? null)) {
				return sprintf('Custom Field %d: Prompt Context is required.', $position);
			}
		}

		return null;
	}

	private function is_blank($value): bool
	{
		return trim((string) ($value ?? '')) === '';
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

		$result = TaskRunner::dispatch_task($campaign_id, $task_id, $webhook_id);
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
