<?php

namespace PostStation\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;
use PostStation\Models\Webhook;
use PostStation\Models\PostBlock;
use PostStation\Models\PostWork;

class RestApi
{
	private const API_NAMESPACE = 'poststation/v1';
	private const OPTION_KEY = 'poststation_api_key';

	public function __construct()
	{
		$this->register_routes();
	}

	public function register_routes(): void
	{
		register_rest_route(self::API_NAMESPACE, '/create', [
			'methods' => 'POST',
			'callback' => [$this, 'handle_create_request'],
			'permission_callback' => [$this, 'check_permission'],
			'args' => [
				'block_id' => [
					'required' => true,
					'type' => 'integer',
				],
				'title' => [
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'content' => [
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'wp_kses_post',
				],
				'status' => [
					'required' => false,
					'type' => 'string',
					'default' => 'draft',
					'enum' => ['draft', 'publish', 'private'],
				],
				'thumbnail_url' => [
					'required' => false,
					'type' => 'string',
					'sanitize_callback' => 'esc_url_raw',
				],
			],
		]);

		// Add new endpoint for checking block status
		register_rest_route(self::API_NAMESPACE, '/check-status', [
			'methods' => 'GET',
			'callback' => [$this, 'handle_check_status'],
			'permission_callback' => '__return_true',
			'args' => [
				'block_ids' => [
					'required' => true,
					'type' => 'string',
					'description' => 'Comma-separated list of block IDs',
				],
			],
		]);
	}

	public function check_permission(WP_REST_Request $request): bool|WP_Error
	{
		$api_key = $request->get_header('X-API-Key');

		if (empty($api_key)) {
			return new WP_Error(
				'rest_forbidden',
				__('Missing API key', 'poststation'),
				['status' => 403]
			);
		}

		$valid_api_key = get_option(self::OPTION_KEY, '');

		if (empty($valid_api_key) || $api_key !== $valid_api_key) {
			return new WP_Error(
				'rest_forbidden',
				__('Invalid API key', 'poststation'),
				['status' => 403]
			);
		}

		return true;
	}

	public function handle_create_request(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		try {
			// Get block and validate
			$block_id = $request->get_param('block_id');
			$block = PostBlock::get_by_id($block_id);

			if (!$block) {
				throw new Exception(__('Block not found', 'poststation'));
			}

			// Update block status to processing
			PostBlock::update($block_id, ['status' => 'processing']);

			// Get post work
			$postwork = PostWork::get_by_id($block['postwork_id']);
			if (!$postwork) {
				throw new Exception(__('Post work not found', 'poststation'));
			}

			// Create post
			$post_data = [
				'post_title' => $request->get_param('title'),
				'post_content' => $request->get_param('content'),
				'post_status' => $request->get_param('status'),
				'post_type' => $block['post_type'],
			];

			// Insert post
			$post_id = wp_insert_post($post_data, true);
			if (is_wp_error($post_id)) {
				throw new Exception($post_id->get_error_message());
			}

			// Store metadata
			update_post_meta($post_id, '_poststation_block_id', $block_id);
			update_post_meta($post_id, '_poststation_postwork_id', $block['postwork_id']);

			// Handle categories
			if (!empty($block['categories'])) {
				$categories = maybe_unserialize($block['categories']);
				if (is_array($categories)) {
					$category_ids = [];
					foreach ($categories as $category_name) {
						$category = get_term_by('name', $category_name, 'category');
						if (!$category) {
							// Create category if it doesn't exist
							$result = wp_create_category($category_name);
							if (!is_wp_error($result)) {
								$category_ids[] = $result;
							}
						} else {
							$category_ids[] = $category->term_id;
						}
					}
					if (!empty($category_ids)) {
						wp_set_post_categories($post_id, $category_ids, false);
					}
				}
			}

			// Handle tags
			if (!empty($block['tags'])) {
				$tags = maybe_unserialize($block['tags']);
				if (is_array($tags)) {
					wp_set_post_tags($post_id, $tags, false);
				}
			}

			// Handle thumbnail
			$thumbnail_url = $request->get_param('thumbnail_url');
			if (!empty($thumbnail_url)) {
				$thumbnail_id = $this->handle_image_upload($thumbnail_url, $post_id);
				if (!is_wp_error($thumbnail_id)) {
					set_post_thumbnail($post_id, $thumbnail_id);
				}
			}

			// Update block status to completed and store post ID
			PostBlock::update($block_id, [
				'status' => 'completed',
				'post_id' => $post_id,
				'error_message' => null,
			]);

			return new WP_REST_Response([
				'success' => true,
				'post_id' => $post_id,
				'message' => __('Post created successfully', 'poststation'),
			], 201);
		} catch (Exception $e) {
			// Update block status to failed
			if (isset($block_id)) {
				PostBlock::update($block_id, [
					'status' => 'failed',
					'error_message' => $e->getMessage(),
				]);
			}

			return new WP_Error(
				'post_creation_failed',
				$e->getMessage(),
				['status' => 500]
			);
		}
	}

	private function handle_image_upload(string $image_url, int $post_id): int|WP_Error
	{
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$tmp = download_url($image_url);
		if (is_wp_error($tmp)) {
			return $tmp;
		}

		$file_array = [
			'name' => basename($image_url),
			'tmp_name' => $tmp
		];

		$file_type = wp_check_filetype($file_array['name'], null);
		if (empty($file_type['type']) || strpos($file_type['type'], 'image/') !== 0) {
			@unlink($tmp);
			return new WP_Error('invalid_image', __('Invalid image type', 'poststation'));
		}

		$attachment_id = media_handle_sideload($file_array, $post_id);
		@unlink($tmp);

		return $attachment_id;
	}

	public function handle_check_status(WP_REST_Request $request): WP_REST_Response
	{
		global $wpdb;
		$block_ids = array_map('intval', explode(',', $request->get_param('block_ids')));

		if (empty($block_ids)) {
			return new WP_REST_Response([]);
		}

		// Get all blocks in a single query
		$table_name = $wpdb->prefix . PostBlock::get_table_name();
		$ids_string = implode(',', $block_ids);

		$blocks = $wpdb->get_results(
			"SELECT id, status, error_message, post_id 
			 FROM {$table_name} 
			 WHERE id IN ({$ids_string})",
			ARRAY_A
		);

		// Format response
		$statuses = array_combine(
			array_column($blocks, 'id'),
			array_map(function ($block) {
				return [
					'status' => $block['status'],
					'error_message' => $block['error_message'],
					'post_id' => $block['post_id'],
				];
			}, $blocks)
		);

		// Add cache headers
		$response = new WP_REST_Response($statuses);
		$response->set_headers([
			'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
			'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT'
		]);

		return $response;
	}
}
