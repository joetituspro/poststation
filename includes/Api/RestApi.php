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
			// Get and validate block
			$block_id = $request->get_param('block_id');
			$block = PostBlock::get_by_id($block_id);
			if (!$block) {
				throw new Exception(__('Block not found', 'poststation'));
			}

			// Get post work
			$postwork = PostWork::get_by_id($block['postwork_id']);
			if (!$postwork) {
				throw new Exception(__('Post work not found', 'poststation'));
			}

			// Update block status to processing
			PostBlock::update($block_id, ['status' => 'processing']);

			// Prepare post data
			$post_data = $this->prepare_post_data($request, $block, $postwork);

			// Insert post
			$post_id = wp_insert_post($post_data, true);
			if (is_wp_error($post_id)) {
				throw new Exception($post_id->get_error_message());
			}

			// Store metadata
			$this->store_post_metadata($post_id, $block);

			// Handle taxonomies
			$this->handle_taxonomies($post_id, $request, $block, $postwork);

			// Handle thumbnail
			$this->handle_thumbnail($post_id, $request, $block);

			// Handle custom fields
			$this->handle_post_fields($post_id, $request, $block, $postwork);

			// Update block status to completed
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

	private function prepare_post_data(WP_REST_Request $request, array $block, array $postwork): array
	{
		$post_data = [
			'post_title' => $request->get_param('title'),
			'post_type' => $postwork['post_type'],
			'post_status' => $postwork['post_status'] ?? 'pending',
			'post_author' => $postwork['default_author_id'] ?? get_current_user_id(),
		];

		// Add content only if provided
		$content = $request->get_param('content');
		if (!empty($content)) {
			$post_data['post_content'] = $content;
		}

		return $post_data;
	}

	private function store_post_metadata(int $post_id, array $block): void
	{
		update_post_meta($post_id, '_poststation_block_id', $block['id']);
		update_post_meta($post_id, '_poststation_postwork_id', $block['postwork_id']);
		update_post_meta($post_id, '_poststation_article_url', $block['article_url']);
	}

	private function handle_taxonomies(int $post_id, WP_REST_Request $request, array $block, array $postwork): void
	{
		// Get enabled taxonomies from postwork
		$enabled_taxonomies = !empty($postwork['enabled_taxonomies'])
			? json_decode($postwork['enabled_taxonomies'], true)
			: [];

		// Get taxonomies from API request or block
		$api_taxonomies = $request->get_param('taxonomies') ?? [];
		$block_taxonomies = !empty($block['taxonomies']) ? json_decode($block['taxonomies'], true) : [];

		// Merge taxonomies, preferring API values over block values
		$taxonomies = array_merge($block_taxonomies, $api_taxonomies);

		foreach ($enabled_taxonomies as $taxonomy => $enabled) {
			if (!$enabled || !taxonomy_exists($taxonomy)) {
				continue;
			}

			$terms = $taxonomies[$taxonomy] ?? [];
			if (empty($terms)) {
				continue;
			}

			// Handle hierarchical taxonomies (like categories)
			if (is_taxonomy_hierarchical($taxonomy)) {
				$term_ids = [];
				foreach ($terms as $term_name) {
					$term = get_term_by('name', $term_name, $taxonomy);
					if (!$term) {
						$result = wp_insert_term($term_name, $taxonomy);
						if (!is_wp_error($result)) {
							$term_ids[] = $result['term_id'];
						}
					} else {
						$term_ids[] = $term->term_id;
					}
				}
				wp_set_object_terms($post_id, $term_ids, $taxonomy);
			}
			// Handle non-hierarchical taxonomies (like tags)
			else {
				wp_set_object_terms($post_id, $terms, $taxonomy);
			}
		}
	}

	private function handle_thumbnail(int $post_id, WP_REST_Request $request, array $block): void
	{
		// Try to get thumbnail URL from API request
		$thumbnail_url = $request->get_param('thumbnail_url');

		// If no thumbnail URL provided and block has feature image, use that
		if (empty($thumbnail_url) && !empty($block['feature_image_id'])) {
			set_post_thumbnail($post_id, $block['feature_image_id']);
			return;
		}

		// If thumbnail URL is provided, download and set it
		if (!empty($thumbnail_url)) {
			$thumbnail_id = $this->handle_image_upload($thumbnail_url, $post_id);
			if (!is_wp_error($thumbnail_id)) {
				set_post_thumbnail($post_id, $thumbnail_id);
			}
		}
	}

	private function handle_post_fields(int $post_id, WP_REST_Request $request, array $block, array $postwork): void
	{
		// Get custom fields from API request or block
		$api_post_fields = $request->get_param('post_fields') ?? [];
		$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
		$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];

		// Merge custom fields, preferring API values over block values
		$post_fields = array_merge($postwork_post_fields, $block_post_fields, $api_post_fields);

		foreach ($post_fields as $meta_key => $meta_value) {
			if (!empty($meta_key) && !empty($meta_value)) {
				update_post_meta($post_id, $meta_key, $meta_value);
			}
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
