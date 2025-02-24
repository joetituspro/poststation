<?php

namespace PostStation\Api;

use WP_Error;
use Exception;
use PostStation\Models\Webhook;
use PostStation\Models\PostBlock;
use PostStation\Models\PostWork;

class Create
{
	/**
	 * Process create request
	 *
	 * @param array $data Request data
	 * @throws Exception If processing fails
	 * @return array
	 */
	public function process_request(array $data): array
	{
		// Get and validate block
		$block = PostBlock::get_by_id($data['block_id']);
		if (!$block) {
			throw new Exception('Block not found', 404);
		}

		// Get post work
		$postwork = PostWork::get_by_id($block['postwork_id']);
		if (!$postwork) {
			throw new Exception('Post work not found', 404);
		}

		// Update block status to processing
		PostBlock::update($data['block_id'], ['status' => 'processing']);

		try {
			// Prepare post data
			$post_data = $this->prepare_post_data($data, $block, $postwork);

			// Insert post
			$post_id = wp_insert_post($post_data, true);
			if (is_wp_error($post_id)) {
				throw new Exception($post_id->get_error_message());
			}

			// Store metadata
			$this->store_post_metadata($post_id, $block);

			// Handle taxonomies
			if (!empty($data['taxonomies'])) {
				$this->handle_taxonomies($post_id, $data['taxonomies'], $postwork);
			}

			// Handle thumbnail
			if (!empty($data['thumbnail_url'])) {
				$this->handle_thumbnail($post_id, $data['thumbnail_url']);
			} elseif (!empty($block['feature_image_id'])) {
				set_post_thumbnail($post_id, $block['feature_image_id']);
			}

			// Handle custom fields
			if (!empty($data['post_fields'])) {
				$this->handle_post_fields($post_id, $data['post_fields'], $block, $postwork);
			}

			// Update block status to completed
			PostBlock::update($data['block_id'], [
				'status' => 'completed',
				'post_id' => $post_id,
				'error_message' => null,
			]);

			return [
				'success' => true,
				'post_id' => $post_id,
				'post_url' => get_permalink($post_id),
				'edit_url' => get_edit_post_link($post_id, 'url'),
			];
		} catch (Exception $e) {
			// Update block status to failed
			PostBlock::update($data['block_id'], [
				'status' => 'failed',
				'error_message' => $e->getMessage(),
			]);
			throw $e;
		}
	}

	/**
	 * Prepare post data for insertion
	 *
	 * @param array $data Request data
	 * @param array $block Block data
	 * @param array $postwork Post work data
	 * @return array
	 */
	private function prepare_post_data(array $data, array $block, array $postwork): array
	{
		$post_data = [
			'post_type' => $postwork['post_type'],
			'post_status' => $postwork['post_status'] ?? 'pending',
			'post_author' => $postwork['default_author_id'] ?? get_current_user_id(),
		];

		// Get post fields
		$api_post_fields = $data['post_fields'] ?? [];
		$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
		$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];

		// Merge post fields, preferring API values
		$post_fields = array_merge($postwork_post_fields, $block_post_fields, $api_post_fields);

		// Set title and content from post fields if available
		if (!empty($post_fields['title'])) {
			$post_data['post_title'] = is_array($post_fields['title'])
				? $post_fields['title']['value']
				: $post_fields['title'];
		}

		if (!empty($post_fields['content'])) {
			$post_data['post_content'] = is_array($post_fields['content'])
				? $post_fields['content']['value']
				: $post_fields['content'];
		}

		return $post_data;
	}

	/**
	 * Store post metadata
	 *
	 * @param int $post_id Post ID
	 * @param array $block Block data
	 * @return void
	 */
	private function store_post_metadata(int $post_id, array $block): void
	{
		update_post_meta($post_id, '_poststation_block_id', $block['id']);
		update_post_meta($post_id, '_poststation_postwork_id', $block['postwork_id']);
		update_post_meta($post_id, '_poststation_article_url', $block['article_url']);
	}

	/**
	 * Handle taxonomies for the post
	 *
	 * @param int $post_id Post ID
	 * @param array $taxonomies Taxonomy data
	 * @param array $postwork Post work data
	 * @return void
	 */
	private function handle_taxonomies(int $post_id, array $taxonomies, array $postwork): void
	{
		// Get enabled taxonomies from postwork
		$enabled_taxonomies = !empty($postwork['enabled_taxonomies'])
			? json_decode($postwork['enabled_taxonomies'], true)
			: [];

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
					// First try to find by exact name
					$term = get_term_by('name', $term_name, $taxonomy);

					if (!$term) {
						// Try to find by slug
						$slug = sanitize_title($term_name);
						$term = get_term_by('slug', $slug, $taxonomy);
					}

					if (!$term) {
						// Create new term if it doesn't exist
						$result = wp_insert_term($term_name, $taxonomy);
						if (!is_wp_error($result)) {
							$term_ids[] = $result['term_id'];
						}
					} else {
						$term_ids[] = $term->term_id;
					}
				}
				if (!empty($term_ids)) {
					wp_set_object_terms($post_id, $term_ids, $taxonomy);
				}
			}
			// Handle non-hierarchical taxonomies (like tags)
			else {
				wp_set_object_terms($post_id, $terms, $taxonomy);
			}
		}
	}

	/**
	 * Handle thumbnail upload and attachment
	 *
	 * @param int $post_id Post ID
	 * @param string $thumbnail_url Thumbnail URL
	 * @return void
	 * @throws Exception If thumbnail upload fails
	 */
	private function handle_thumbnail(int $post_id, string $thumbnail_url): void
	{
		$thumbnail_id = $this->handle_image_upload($thumbnail_url, $post_id);
		if (is_wp_error($thumbnail_id)) {
			throw new Exception($thumbnail_id->get_error_message());
		}
		set_post_thumbnail($post_id, $thumbnail_id);
	}

	/**
	 * Handle custom fields for the post
	 *
	 * @param int $post_id Post ID
	 * @param array $post_fields Custom fields data
	 * @return void
	 */
	private function handle_post_fields(int $post_id, array $api_post_fields, array $block, array $postwork): void
	{
		// Get custom fields from API request or block
		$api_post_fields = $api_post_fields ?? [];
		$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
		$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];

		// Merge custom fields, preferring API values over block values
		$post_fields = array_merge($postwork_post_fields, $block_post_fields, $api_post_fields);

		foreach ($post_fields as $meta_key => $meta_value) {
			// Skip title and content as they're handled in prepare_post_data
			if (in_array($meta_key, ['post_title', 'post_content'])) {
				continue;
			}

			if (!empty($meta_key)) {
				// Allow developers to validate and prepare individual field value
				$prepared_value = apply_filters('poststation_prepare_custom_field_value', $meta_value, $meta_key, [
					'post_id' => $post_id,
					'block' => $block,
					'postwork' => $postwork
				]);

				// Skip if filter returns null (allows developers to exclude fields)
				if ($prepared_value === null) {
					continue;
				}

				// If filter returns WP_Error, throw exception
				if (is_wp_error($prepared_value)) {
					throw new Exception($prepared_value->get_error_message());
				}

				update_post_meta($post_id, $meta_key, $prepared_value);
			}
		}
	}

	/**
	 * Handle image upload
	 *
	 * @param string $image_url Image URL
	 * @param int $post_id Post ID
	 * @return int|WP_Error Attachment ID or error
	 */
	private function handle_image_upload(string $image_url, int $post_id)
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
}