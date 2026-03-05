<?php

namespace PostStation\Api;

use Exception;
use PostStation\Models\PostTask;

class ApiHandler
{
	public function init(): void
	{
		add_action('init', [$this, 'register_endpoints']);
	}

	public function register_endpoints(): void
	{
		add_rewrite_rule(
			'ps-api/posttasks/?$',
			'index.php?pagename=ps-api/posttasks',
			'top'
		);

		add_action('parse_request', [$this, 'handle_api_request']);
	}

	public function handle_api_request(\WP $wp): void
	{
		if (!isset($wp->query_vars['pagename']) || $wp->query_vars['pagename'] !== 'ps-api/posttasks') {
			return;
		}

		header('Content-Type: application/json');
		$this->handle_cors();

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
			status_header(200);
			exit();
		}

		try {
			if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
				$this->send_error('Method not allowed', 405);
			}

			$campaign_id = (int) ($_GET['campaign_id'] ?? 0);
			if ($campaign_id <= 0) {
				$this->send_error('Missing campaign_id parameter', 400);
			}

			$status_filter = trim((string) ($_GET['status'] ?? ''));
			$last_task_count = (int) ($_GET['last_task_count'] ?? -1);
			$this->send_response($this->get_tasks_by_status($campaign_id, $status_filter, $last_task_count));
		} catch (Exception $e) {
			$this->send_error($e->getMessage(), $e->getCode() ?: 400);
		}
	}

	private function handle_cors(): void
	{
		$allowed_origins = apply_filters('poststation_allowed_origins', ['*']);
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

		if (in_array('*', $allowed_origins, true) || in_array($origin, $allowed_origins, true)) {
			header('Access-Control-Allow-Origin: ' . $origin);
			header('Access-Control-Allow-Methods: GET, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type');
			header('Access-Control-Max-Age: 86400');
		}
	}

	private function send_response($data, int $status = 200): void
	{
		status_header($status);
		echo wp_json_encode($data);
		exit;
	}

	private function send_error(string $message, int $status = 400): void
	{
		$this->send_response([
			'success' => false,
			'error' => $message,
		], $status);
	}

	/**
	 * @return array{tasks: array, new_tasks_available: bool, full_tasks?: array}
	 */
	private function get_tasks_by_status(int $campaign_id, string $status_filter, int $last_task_count = -1): array
	{
		$allowed_statuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
		if ($status_filter === 'all' || $status_filter === '') {
			$statuses = $allowed_statuses;
		} else {
			$statuses = array_values(array_intersect(
				$allowed_statuses,
				array_map('trim', explode(',', $status_filter))
			));
			if (empty($statuses)) {
				$statuses = ['pending', 'processing'];
			}
		}

		$tasks = PostTask::get_by_campaign($campaign_id);
		$current_count = is_array($tasks) ? count($tasks) : 0;
		$new_tasks_available = $last_task_count >= 0 && $current_count > $last_task_count;

		$status_list = [];
		if (!empty($tasks)) {
			foreach ($tasks as $task) {
				if (!in_array($task['status'], $statuses, true)) {
					continue;
				}
				$status_list[] = [
					'id' => (int) $task['id'],
					'status' => $task['status'],
					'progress' => $task['progress'] ?? null,
					'post_id' => $task['post_id'] ?? null,
					'error_message' => $task['error_message'] ?? null,
					'scheduled_publication_date' => $task['scheduled_publication_date'] ?? null,
					'post_date' => $task['post_date'] ?? null,
					'wp_post_status' => $task['wp_post_status'] ?? null,
				];
			}
		}

		$result = [
			'tasks' => $status_list,
			'new_tasks_available' => $new_tasks_available,
		];
		if ($new_tasks_available && !empty($tasks)) {
			$result['full_tasks'] = $tasks;
		}

		return $result;
	}
}
