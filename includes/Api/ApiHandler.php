<?php

namespace PostStation\Api;

use WP_Error;
use Exception;
use PostStation\Models\Webhook;
use PostStation\Models\PostBlock;
use PostStation\Models\PostWork;
use PostStation\Api\Create;

class ApiHandler
{
	private const OPTION_KEY = 'poststation_api_key';
	private const REQUIRED_FIELDS = ['block_id'];
	private const ALLOWED_FIELDS = [
		'block_id',
		'title',
		'content',
		'thumbnail_url',
		'taxonomies',
		'post_fields'
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
		if (!isset($wp->query_vars['pagename']) || $wp->query_vars['pagename'] !== 'poststation-api') {
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

		// Only allow POST method for create endpoint
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->send_error('Method not allowed', 405);
		}

		try {
			// Validate API key
			$this->validate_api_key();

			// Parse JSON body
			$body = $this->get_request_body();

			// Process the create request
			$response = $this->process_create_request($body);

			// Send success response
			$this->send_response($response);
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
			header('Access-Control-Allow-Methods: POST, OPTIONS');
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
}
