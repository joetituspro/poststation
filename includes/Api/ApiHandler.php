<?php

namespace PostStation\Api;

use WP_Error;
use Exception;
use PostStation\Models\Webhook;
use PostStation\Models\PostBlock;
use PostStation\Models\PostWork;
use PostStation\Api\Create;
use PostStation\Services\ImageOptimizer;

class ApiHandler
{
	private const OPTION_KEY = 'poststation_api_key';
	private const REQUIRED_FIELDS = [];
	private const ALLOWED_FIELDS = [
		'block_id',
		'title',
		'content',
		'slug',
		'thumbnail_url',
		'taxonomies',
		'custom_fields',
		'status',
		'progress',
		'error_message'
	];

	/**
	 * Handle incoming API requests
	 *
	 * @return void
	 */
	public function init(): void
	{
		add_action('init', [$this, 'register_endpoints']);
	}

	/**
	 * Register custom endpoints
	 *
	 * @return void
	 */
	public function register_endpoints(): void
	{
		// Add rewrite rules for our endpoints
		add_rewrite_rule(
			'ps-api/create/?$',
			'index.php?pagename=ps-api/create',
			'top'
		);

		add_rewrite_rule(
			'ps-api/progress/?$',
			'index.php?pagename=ps-api/progress',
			'top'
		);

		add_rewrite_rule(
			'ps-api/blocks/?$',
			'index.php?pagename=ps-api/blocks',
			'top'
		);

		add_rewrite_rule(
			'ps-api/upload/?$',
			'index.php?pagename=ps-api/upload',
			'top'
		);

		add_action('parse_request', [$this, 'handle_api_request']);
	}

	/**
	 * Handle API requests
	 *
	 * @param \WP $wp WordPress request object
	 * @return void
	 */
	public function handle_api_request(\WP $wp): void
	{
		// Check if this is our API request
		if (!isset($wp->query_vars['pagename']) || !str_starts_with($wp->query_vars['pagename'], 'ps-api')) {
			return;
		}

		// Set JSON response headers
		header('Content-Type: application/json');

		// Handle CORS
		$this->handle_cors();

		// Handle preflight requests
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			status_header(200);
			exit();
		}

		// Get the endpoint from the URL
		$endpoint = str_replace('ps-api/', '', $wp->query_vars['pagename']);

		try {
			switch ($endpoint) {
				case 'create':
					// Only allow POST method for create endpoint
					if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
						$this->send_error('Method not allowed', 405);
					}

					// Validate API key
					$this->validate_api_key();

					// Parse JSON body
					$body = $this->get_request_body();

					// Process the create request
					$response = $this->process_create_request($body);

					// Send success response
					$this->send_response($response);
					break;

				case 'progress':
					// Only allow POST method for progress endpoint
					if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
						$this->send_error('Method not allowed', 405);
					}

					// Validate API key
					$this->validate_api_key();

					// Parse JSON body
					$body = $this->get_request_body();

					// Process the progress update
					$response = $this->update_block_progress($body);

					// Send success response
					$this->send_response($response);
					break;

				case 'blocks':
					// Only allow GET method for blocks endpoint
					if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
						$this->send_error('Method not allowed', 405);
					}

					$postwork_id = (int)($_GET['postwork_id'] ?? 0);
					if (!$postwork_id) {
						$this->send_error('Missing postwork_id parameter', 400);
					}

					$status_filter = trim((string)($_GET['status'] ?? ''));
					$response = $this->get_blocks_by_status($postwork_id, $status_filter);
					$this->send_response($response);
					break;

				case 'upload':
					// Only allow POST method for upload endpoint
					if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
						$this->send_error('Method not allowed', 405);
					}

					// Validate API key
					$this->validate_api_key();

					$body = $this->get_upload_body();
					$response = $this->handle_image_upload($body);
					$this->send_response($response);
					break;

				default:
					$this->send_error('Endpoint not found', 404);
			}
		} catch (Exception $e) {
			$this->send_error($e->getMessage(), $e->getCode() ?: 400);
		}
	}

	/**
	 * Handle CORS headers
	 *
	 * @return void
	 */
	private function handle_cors(): void
	{
		$allowed_origins = apply_filters('poststation_allowed_origins', ['*']);
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

		if (in_array('*', $allowed_origins) || in_array($origin, $allowed_origins)) {
			header('Access-Control-Allow-Origin: ' . $origin);
			header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
			header('Access-Control-Max-Age: 86400'); // 24 hours cache
		}
	}

	/**
	 * Validate API key from headers
	 *
	 * @throws Exception If API key is invalid or missing
	 * @return void
	 */
	private function validate_api_key(): void
	{
		$api_key = $_SERVER['HTTP_X_API_KEY'] ?? null;

		if (!$api_key) {
			throw new Exception('Missing API key', 401);
		}

		$valid_api_key = get_option(self::OPTION_KEY, '');

		if (empty($valid_api_key) || !hash_equals($valid_api_key, $api_key)) {
			throw new Exception('Invalid API key', 403);
		}
	}

	/**
	 * Get and validate request body
	 *
	 * @throws Exception If request body is invalid
	 * @return array
	 */
	private function get_request_body(): array
	{
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON payload', 400);
		}

		// Validate required fields
		foreach (self::REQUIRED_FIELDS as $field) {
			if (!isset($data[$field])) {
				throw new Exception("Missing required field: {$field}", 400);
			}
		}

		// Remove any disallowed fields
		return array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
	}

	private function get_upload_body(): array
	{
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON payload', 400);
		}

		$required = ['block_id', 'image_base64'];
		foreach ($required as $field) {
			if (!isset($data[$field]) || $data[$field] === '') {
				throw new Exception("Missing required field: {$field}", 400);
			}
		}

		return $data;
	}

	/**
	 * Process create request
	 *
	 * @param array $data Request data
	 * @throws Exception If processing fails
	 * @return array
	 */
	private function process_create_request(array $data): array
	{
		$create = new Create();
		return $create->process_request($data);
	}

	private function handle_image_upload(array $data): array
	{
		$block_id = (int) $data['block_id'];
		$block = $block_id ? PostBlock::get_by_id($block_id) : null;
		if (!$block) {
			throw new Exception('Block not found', 404);
		}

		$optimizer = new ImageOptimizer();
		$result = $optimizer->upload_base64_image([
			'block_id' => $block_id,
			'image_base64' => (string) $data['image_base64'],
			'index' => $data['index'] ?? null,
			'filename' => $data['filename'] ?? '',
			'alt_text' => $data['alt_text'] ?? '',
			'format' => $data['format'] ?? 'webp',
		]);

		$attachment_id = $result['attachment_id'];

		update_post_meta($attachment_id, 'poststation_block_id', $block_id);
		if (isset($data['index'])) {
			update_post_meta($attachment_id, 'poststation_block_index', $data['index']);
		}
		if (!empty($data['filename'])) {
			update_post_meta($attachment_id, 'poststation_original_filename', (string) $data['filename']);
		}
		if (!empty($data['alt_text'])) {
			update_post_meta($attachment_id, 'poststation_alt_text', (string) $data['alt_text']);
			update_post_meta($attachment_id, '_wp_attachment_image_alt', (string) $data['alt_text']);
		}

		return [
			'success' => true,
			'attachment_id' => $attachment_id,
			'url' => $result['url'],
			'format' => $result['format'],
		];
	}

	/**
	 * Send JSON response
	 *
	 * @param mixed $data Response data
	 * @param int $status HTTP status code
	 * @return void
	 */
	private function send_response($data, int $status = 200): void
	{
		status_header($status);
		echo wp_json_encode($data);
		exit;
	}

	/**
	 * Send error response
	 *
	 * @param string $message Error message
	 * @param int $status HTTP status code
	 * @return void
	 */
	private function send_error(string $message, int $status = 400): void
	{
		$this->send_response([
			'success' => false,
			'error' => $message
		], $status);
	}

	/**
	 * Get pending and processing blocks for a post work.
	 *
	 * @param int $postwork_id
	 * @return array
	 */
	private function get_blocks_by_status(int $postwork_id, string $status_filter): array
	{
		$allowed_statuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
		if ($status_filter === 'all' || $status_filter === '') {
			$statuses = $allowed_statuses;
		} elseif ($status_filter !== '') {
			$statuses = array_values(array_intersect(
				$allowed_statuses,
				array_map('trim', explode(',', $status_filter))
			));
			if (empty($statuses)) {
				$statuses = ['pending', 'processing'];
			}
		} else {
			$statuses = ['pending', 'processing'];
		}

		$blocks = PostBlock::get_by_postwork($postwork_id);
		if (empty($blocks)) {
			return [];
		}

		$response = [];
		foreach ($blocks as $block) {
			if (!in_array($block['status'], $statuses, true)) {
				continue;
			}

			$response[] = [
				'id' => (int)$block['id'],
				'status' => $block['status'],
				'progress' => $block['progress'] ?? null,
				'post_id' => $block['post_id'] ?? null,
				'error_message' => $block['error_message'] ?? null,
			];
		}

		return $response;
	}

	/**
	 * Update progress of a block
	 *
	 * @param array $data Request data
	 * @throws Exception If update fails
	 * @return array
	 */
	private function update_block_progress(array $data): array
	{
		$block_id = $data['block_id'] ?? null;
		$status = $data['status'] ?? null;
		$progress = $data['progress'] ?? null;
		$error_message = $data['error_message'] ?? null;

		if (!$block_id) {
			throw new Exception('Missing block_id', 400);
		}

		$block = PostBlock::get_by_id($block_id);
		if (!$block) {
			throw new Exception('Block not found', 404);
		}

		$update_data = [];
		if ($progress !== null) {
			$update_data['progress'] = $progress;
		}
		if ($status !== null) {
			$allowed_statuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
			if (in_array($status, $allowed_statuses, true)) {
				$update_data['status'] = $status;
				if ($status === 'completed') {
					$update_data['error_message'] = null;
				}
				if ($status === 'failed' && $error_message) {
					$update_data['error_message'] = $error_message;
				}
			}
		}

		if (!empty($update_data)) {
			// Reset timeout by updating run_started_at if the block is still processing
			$current_status = $update_data['status'] ?? $block['status'];
			if ($current_status === 'processing') {
				$update_data['run_started_at'] = current_time('mysql');
			}
			PostBlock::update($block_id, $update_data);
		}

		return [
			'success' => true,
			'message' => 'Progress updated successfully'
		];
	}
}