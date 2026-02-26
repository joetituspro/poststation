<?php

namespace PostStation\Admin\Ajax;

use PostStation\Admin\BootstrapDataProvider;
use PostStation\Models\Campaign;
use PostStation\Models\CampaignRss;
use PostStation\Models\PostTask;
use PostStation\Services\BackgroundRunner;
use PostStation\Services\RssService;
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

		if (!isset($campaign['rss_enabled']) || $campaign['rss_enabled'] === '') {
			$campaign['rss_enabled'] = 'no';
		}
		if (!isset($campaign['status']) || $campaign['status'] === '') {
			$campaign['status'] = 'paused';
		}
		if (!isset($campaign['publication_mode']) || $campaign['publication_mode'] === '') {
			$campaign['publication_mode'] = $this->sanitize_publication_mode($campaign['post_status'] ?? 'pending');
		}
		$campaign['publication_interval_value'] = $this->sanitize_publication_interval_value($campaign['publication_interval_value'] ?? 1);
		$campaign['publication_interval_unit'] = $this->sanitize_publication_interval_unit($campaign['publication_interval_unit'] ?? 'hour');
		$campaign['rolling_schedule_days'] = $this->sanitize_rolling_schedule_days($campaign['rolling_schedule_days'] ?? 30);
		$rss_config = CampaignRss::get_by_campaign($id);
		if ($rss_config) {
			$campaign['rss_config'] = [
				'frequency_interval' => (int) ($rss_config['frequency_interval'] ?? 60),
				'sources' => $rss_config['sources'],
			];
		} else {
			$campaign['rss_config'] = null;
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

		// Preserve existing status if not explicitly provided, so Live doesn't auto-turn off on unrelated updates.
		$existing_campaign = Campaign::get_by_id($campaign_id);
		$current_status = $existing_campaign['status'] ?? 'paused';

		$rss_enabled = sanitize_text_field($_POST['rss_enabled'] ?? 'no');
		if (!in_array($rss_enabled, ['yes', 'no'], true)) {
			$rss_enabled = 'no';
		}
		$rss_config_raw = isset($_POST['rss_config']) ? wp_unslash($_POST['rss_config']) : '';
		$rss_config = is_string($rss_config_raw) ? json_decode($rss_config_raw, true) : $rss_config_raw;

		$campaign_payload = [
			'title' => $title,
			'webhook_id' => (int) ($_POST['webhook_id'] ?? 0) ?: null,
			'campaign_type' => sanitize_text_field($_POST['campaign_type'] ?? 'default'),
			'tone_of_voice' => sanitize_text_field($_POST['tone_of_voice'] ?? 'none'),
			'point_of_view' => sanitize_text_field($_POST['point_of_view'] ?? 'none'),
			'readability' => sanitize_text_field($_POST['readability'] ?? 'grade_8'),
			'language' => sanitize_text_field($_POST['language'] ?? 'en'),
			'target_country' => sanitize_text_field($_POST['target_country'] ?? 'international'),
			'post_type' => sanitize_text_field($_POST['post_type'] ?? 'post'),
			'publication_mode' => $this->sanitize_publication_mode($_POST['publication_mode'] ?? ($_POST['post_status'] ?? 'pending')),
			'publication_interval_value' => $this->sanitize_publication_interval_value($_POST['publication_interval_value'] ?? 1),
			'publication_interval_unit' => $this->sanitize_publication_interval_unit($_POST['publication_interval_unit'] ?? 'hour'),
			'rolling_schedule_days' => $this->sanitize_rolling_schedule_days($_POST['rolling_schedule_days'] ?? 30),
			'default_author_id' => (int) ($_POST['default_author_id'] ?? 0) ?: get_current_user_id(),
			'writing_preset_id' => (int) ($_POST['writing_preset_id'] ?? 0) ?: null,
			'content_fields' => wp_unslash($_POST['content_fields'] ?? '{}'),
			'rss_enabled' => $rss_enabled,
			'status' => $this->sanitize_campaign_status($_POST['status'] ?? $current_status),
		];
		$campaign_payload['post_status'] = $this->map_publication_mode_to_legacy_post_status($campaign_payload['publication_mode']);

		$validation_error = $this->validate_campaign_payload($campaign_payload);
		if ($validation_error !== null) {
			wp_send_json_error(['message' => $validation_error]);
		}

		$rss_validation = $this->validate_rss_config($rss_enabled, $rss_config);
		if ($rss_validation !== null) {
			wp_send_json_error(['message' => $rss_validation]);
		}

		$success = Campaign::update($campaign_id, $campaign_payload);

		if (!$success) {
			wp_send_json_error(['message' => 'Failed to update campaign']);
		}

		if ($rss_enabled === 'no') {
			CampaignRss::delete_by_campaign($campaign_id);
		} else {
			$frequency_interval = 60;
			$sources = [];
			if (is_array($rss_config)) {
				$frequency_interval = isset($rss_config['frequency_interval']) ? (int) $rss_config['frequency_interval'] : 60;
				if (!in_array($frequency_interval, RssService::ALLOWED_INTERVALS, true)) {
					$frequency_interval = 60;
				}
				$sources = isset($rss_config['sources']) && is_array($rss_config['sources']) ? $rss_config['sources'] : [];
			}
			$sources = array_values(array_filter($sources, function ($s) {
				return is_array($s) && !empty(trim((string) ($s['feed_url'] ?? '')));
			}));
			CampaignRss::save($campaign_id, $frequency_interval, $sources);
		}

		$new_status = $this->sanitize_campaign_status($_POST['status'] ?? $current_status);
		if ($new_status === 'active' && !empty((int) ($_POST['webhook_id'] ?? 0))) {
			$runner = new BackgroundRunner();
			$runner->start_run_if_pending($campaign_id);
		}

		wp_send_json_success();
	}

	public function update_campaign_status(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$campaign_id = (int) ($_POST['id'] ?? 0);
		$status = $this->sanitize_campaign_status($_POST['status'] ?? 'paused');
		if ($campaign_id <= 0) {
			wp_send_json_error(['message' => 'Invalid campaign ID']);
		}

		$existing = Campaign::get_by_id($campaign_id);
		if (!$existing) {
			wp_send_json_error(['message' => 'Campaign not found']);
		}

		$success = Campaign::update($campaign_id, ['status' => $status]);
		if (!$success) {
			wp_send_json_error(['message' => 'Failed to update status']);
		}

		if ($status === 'active' && !empty((int) ($existing['webhook_id'] ?? 0))) {
			$runner = new BackgroundRunner();
			$runner->start_run_if_pending($campaign_id);
		}

		wp_send_json_success();
	}

	private function sanitize_campaign_status(string $status): string
	{
		return in_array($status, ['active', 'paused'], true) ? $status : 'paused';
	}

	private function validate_campaign_payload(array $payload): ?string
	{
		$required_fields = [
			'title' => 'Campaign title is required.',
			'campaign_type' => 'Campaign Type is required.',
			'tone_of_voice' => 'Campaign Tone of Voice is required.',
			'point_of_view' => 'Campaign Point of View is required.',
			'readability' => 'Campaign Readability is required.',
			'language' => 'Campaign Language is required.',
			'target_country' => 'Campaign Target Country is required.',
			'post_type' => 'Campaign Post Type is required.',
			'publication_mode' => 'Campaign Publication is required.',
			'default_author_id' => 'Campaign Default Author is required.',
			'webhook_id' => 'Campaign Webhook is required.',
		];

		foreach ($required_fields as $field => $message) {
			if ($this->is_blank($payload[$field] ?? null)) {
				return $message;
			}
		}
		$mode = (string) ($payload['publication_mode'] ?? 'pending_review');
		if ($mode === 'publish_intervals') {
			$interval_value = (int) ($payload['publication_interval_value'] ?? 0);
			$interval_unit = (string) ($payload['publication_interval_unit'] ?? '');
			if ($interval_value < 1) {
				return 'Campaign Interval Value must be at least 1.';
			}
			if (!in_array($interval_unit, ['minute', 'hour'], true)) {
				return 'Campaign Interval Period must be minute or hour.';
			}
		}
		if ($mode === 'rolling_schedule') {
			$days = (int) ($payload['rolling_schedule_days'] ?? 0);
			if (!in_array($days, [7, 14, 30, 60], true)) {
				return 'Campaign Rolling Schedule range must be 7, 14, 30, or 60 days.';
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

	private function validate_rss_config(string $rss_enabled, $rss_config): ?string
	{
		if ($rss_enabled !== 'yes') {
			return null;
		}
		if (!is_array($rss_config)) {
			return null;
		}
		$interval = isset($rss_config['frequency_interval']) ? (int) $rss_config['frequency_interval'] : 0;
		if (!in_array($interval, RssService::ALLOWED_INTERVALS, true)) {
			return __('RSS frequency must be 15, 60, 360, or 1440 minutes.', 'poststation');
		}
		return null;
	}

	private function is_blank($value): bool
	{
		return trim((string) ($value ?? '')) === '';
	}

	private function sanitize_publication_mode($mode): string
	{
		$raw = sanitize_text_field((string) ($mode ?? 'pending_review'));

		if ($raw === 'pending') {
			$raw = 'pending_review';
		}
		if ($raw === 'publish') {
			$raw = 'publish_instantly';
		}
		if ($raw === 'future') {
			$raw = 'rolling_schedule';
		}
		if ($raw === 'schedule_date' || $raw === 'publish_randomly') {
			$raw = 'rolling_schedule';
		}
		$allowed = ['pending_review', 'publish_instantly', 'publish_intervals', 'rolling_schedule'];
		return in_array($raw, $allowed, true) ? $raw : 'pending_review';
	}

	private function sanitize_publication_interval_value($value): int
	{
		$value = (int) $value;
		return $value > 0 ? $value : 1;
	}

	private function sanitize_publication_interval_unit($value): string
	{
		$value = sanitize_text_field((string) $value);
		return in_array($value, ['minute', 'hour'], true) ? $value : 'hour';
	}

	private function sanitize_rolling_schedule_days($value): int
	{
		$value = (int) $value;
		return in_array($value, [7, 14, 30, 60], true) ? $value : 30;
	}

	private function map_publication_mode_to_legacy_post_status(string $mode): string
	{
		if ($mode === 'publish_instantly') {
			return 'publish';
		}
		if ($mode === 'publish_intervals' || $mode === 'rolling_schedule') {
			return 'future';
		}
		return 'pending';
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
		CampaignRss::delete_by_campaign($campaign_id);
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

		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign || empty($campaign['webhook_id'])) {
			wp_send_json_error(['message' => 'Campaign not found or webhook missing']);
		}

		$is_live = ($campaign['status'] ?? '') === 'active';
		if ($is_live) {
			$runner = new BackgroundRunner();
			if (!$runner->start_run_for_task($campaign_id, $task_id, $webhook_id)) {
				wp_send_json_error(['message' => 'Failed to run task']);
			}
		} else {
			$result = TaskRunner::dispatch_task($campaign_id, $task_id, $webhook_id);
			if (empty($result['success'])) {
				wp_send_json_error(['message' => $result['message'] ?? 'Failed to run task']);
			}
		}

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
