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
		$block_id = $data['block_id'] ?? null;
		$block = $block_id ? PostBlock::get_by_id($block_id) : null;

		// Get post work
		$postwork = null;
		if ($block) {
			$postwork = PostWork::get_by_id($block['postwork_id']);
		}

		// Update block status to processing and reset timeout
		if ($block_id && $block) {
			PostBlock::update($block_id, [
				'status' => 'processing',
				'run_started_at' => current_time('mysql')
			]);
		}

		try {
			// Allow developers to handle content publication
			$result = apply_filters('poststation_handle_content_publication', null, $data, $block ?: [], $postwork ?: []);

			if ($result === null) {
				// Use default publication handler if no custom handler
				$result = $this->handle_content_publication($data, $block ?: [], $postwork ?: []);
			} elseif (!is_array($result) || !isset($result['post_id'])) {
				throw new Exception('Invalid custom publication result. Must return array with post_id');
			}

			// Update block status to completed
			if ($block_id && $block) {
				PostBlock::update($block_id, [
					'status' => 'completed',
					'post_id' => $result['post_id'],
					'error_message' => null,
				]);
			}

			// Return standardized response
			return array_merge([
				'success' => true,
				'post_url' => get_permalink($result['post_id']),
				'edit_url' => get_edit_post_link($result['post_id'], 'url'),
			], $result);
		} catch (Exception $e) {
			// Update block status to failed
			if ($block_id && $block) {
				PostBlock::update($block_id, [
					'status' => 'failed',
					'error_message' => $e->getMessage(),
				]);
			}
			throw $e;
		}
	}

	/**
	 * Handle content publication
	 *
	 * @param array $data Request data
	 * @param array $block Block data
	 * @param array $postwork Post work data
	 * @return array
	 * @throws Exception If publication fails
	 */
	private function handle_content_publication(array $data, array $block, array $postwork): array
	{
		// Prepare post data
		$post_data = $this->prepare_post_data($data, $block, $postwork);

		// Insert post
		$post_id = wp_insert_post($post_data, true);
		if (is_wp_error($post_id)) {
			throw new Exception($post_id->get_error_message());
		}

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

		// Handle post fields
		if (!empty($data['custom_fields'])) {
			$this->handle_custom_fields($post_id, $data['custom_fields']);
		}

		return [
			'post_id' => $post_id
		];
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
		foreach ($taxonomies as $taxonomy => $terms) {
			// Skip if taxonomy doesn't exist
			if (!taxonomy_exists($taxonomy)) {
				continue;
			}

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
				// If no terms were successfully created/found, try the original approach
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
	 * Handle post fields for the post
	 *
	 * @param int $post_id Post ID
	 * @param array $block Block data
	 * @param array $postwork Post work data
	 * @param array $api_post_fields Custom fields data from API
	 * @return void
	 */
	private function handle_custom_fields(int $post_id, array $api_post_fields = []): void
	{
		foreach ($api_post_fields as $meta_key => $meta_value) {
			// Skip title, content, and slug as they're handled in prepare_post_data
			if (in_array($meta_key, ['title', 'content', 'slug'])) {
				continue;
			}

			if (!empty($meta_key)) {
				update_post_meta($post_id, $meta_key, $meta_value);
			}
		}
	}

	/**
	 * Generate featured image using image-gen service
	 *
	 * @param int $post_id Post ID
	 * @param array $image_config Image configuration
	 * @param array $data Request data
	 * @return void
	 */
	private function generate_featured_image(int $post_id, $image_config, array $data): void
	{
		if (!is_array($image_config)) {
			return;
		}
		$post = get_post($post_id);
		if (!$post) {
			return;
		}

		// Get block data to access feature_image_title
		$block_id = $data['block_id'] ?? 0;
		$block = $block_id ? PostBlock::get_by_id($block_id) : null;

		// 1. Get image title (default to {{title}} if not set)
		$image_title = $block['feature_image_title'] ?? ($data['feature_image_title'] ?? '{{title}}');
		// Replace {{title}} placeholder with actual post title
		$image_title = str_replace('{{title}}', $post->post_title, $image_title);

		// Prepare the request body for image-gen API
		$main_text = $image_config['mainText'] ?? '{{title}}';
		// Replace placeholders: {{title}} and {{image_title}}
		$main_text = str_replace('{{title}}', $post->post_title, $main_text);
		$main_text = str_replace('{{image_title}}', $image_title, $main_text);

		$body = [
			'templateId' => $image_config['templateId'] ?? 'classic',
			'categoryText' => $image_config['categoryText'] ?? '',
			'mainText' => $main_text,
		];

		// Add optional fields if provided
		$bg_image_url = '';
		$bg_image_urls = $image_config['bgImageUrls'] ?? [];

		// Fallback for old single image URL
		if (empty($bg_image_urls) && !empty($image_config['bgImageUrl'])) {
			$bg_image_urls = [$image_config['bgImageUrl']];
		}

		if (!empty($bg_image_urls)) {
			// Get last used image URL from postwork meta
			$postwork_id = $block['postwork_id'] ?? 0;
			$last_bg_url = '';

			if ($postwork_id) {
				$last_bg_url = get_option('poststation_last_bg_image_' . $postwork_id, '');
			}

			// Filter out the last used URL if there are more than 1 image available
			$available_urls = array_filter($bg_image_urls, function ($url) use ($last_bg_url, $bg_image_urls) {
				return count($bg_image_urls) <= 1 || $url !== $last_bg_url;
			});

			if (empty($available_urls)) {
				$available_urls = $bg_image_urls;
			}

			// Select a random image from available ones
			$bg_image_url = $available_urls[array_rand($available_urls)];

			// Update last used image URL
			if ($postwork_id) {
				update_option('poststation_last_bg_image_' . $postwork_id, $bg_image_url);
			}
		}

		if (!empty($bg_image_url)) {
			$ngrok_url = 'https://natural-cute-robin.ngrok-free.app';

			// Parse the BG image URL host
			$image_host = parse_url($bg_image_url, PHP_URL_HOST);

			// If the image host is a local host, replace it with the ngrok URL
			if ($image_host && (in_array($image_host, ['localhost', '127.0.0.1']) || strpos($image_host, '.local') !== false)) {
				$scheme = parse_url($bg_image_url, PHP_URL_SCHEME);
				$local_base = $scheme . '://' . $image_host;
				$bg_image_url = str_replace($local_base, $ngrok_url, $bg_image_url);
			}

			$body['bgImageUrl'] = $bg_image_url;
		}

		if (!empty($image_config['categoryColor'])) {
			$body['categoryColor'] = $image_config['categoryColor'];
		}

		if (!empty($image_config['titleColor'])) {
			$body['titleColor'] = $image_config['titleColor'];
		}

		// Call the image-gen API
		$response = wp_remote_post('https://image-gen.digitenet.com/api/generate-image', [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body' => wp_json_encode($body),
			'timeout' => 60, // Image generation may take time
			'sslverify' => false,
		]);

		if (is_wp_error($response)) {
			error_log('PostStation: Failed to generate featured image - ' . $response->get_error_message());
			return;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		if ($response_code !== 200) {
			error_log('PostStation: Image-gen API returned error code: ' . $response_code);
			return;
		}

		$response_body = json_decode(wp_remote_retrieve_body($response), true);
		if (empty($response_body['success']) || empty($response_body['downloadUrl'])) {
			error_log('PostStation: Image-gen API returned invalid response');
			return;
		}

		// Download and set the generated image as featured image
		$image_url = $response_body['downloadUrl'];
		$thumbnail_id = $this->handle_image_upload($image_url, $post_id);

		if (is_wp_error($thumbnail_id)) {
			error_log('PostStation: Failed to upload generated image - ' . $thumbnail_id->get_error_message());
			return;
		}

		set_post_thumbnail($post_id, $thumbnail_id);

		// Also insert the image before the first <h2> tag in the content
		$image_url = wp_get_attachment_url($thumbnail_id);
		if ($image_url) {
			$post = get_post($post_id);
			if ($post && !empty($post->post_content)) {
				$img_tag = sprintf(
					'<img src="%s" alt="%s" class="wp-post-image" />',
					esc_url($image_url),
					esc_attr($post->post_title)
				);

				// Find the first <h2> tag and insert the image before it
				if (preg_match('/(<h2[^>]*>)/i', $post->post_content)) {
					$new_content = preg_replace('/(<h2[^>]*>)/i', $img_tag . "\n$1", $post->post_content, 1);
					wp_update_post([
						'ID' => $post_id,
						'post_content' => $new_content,
					]);
				}
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

		// Download the file
		$tmp = download_url($image_url);
		if (is_wp_error($tmp)) {
			return $tmp;
		}

		// Get mime type using fileinfo
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime_type = finfo_file($finfo, $tmp);
		finfo_close($finfo);

		// Generate proper filename with extension
		$ext = '';
		switch ($mime_type) {
			case 'image/jpeg':
				$ext = 'jpg';
				break;
			case 'image/png':
				$ext = 'png';
				break;
			case 'image/gif':
				$ext = 'gif';
				break;
			case 'image/webp':
				$ext = 'webp';
				break;
		}

		if (empty($ext)) {
			@unlink($tmp);
			return new WP_Error('invalid_image', __('Invalid image type', 'poststation'));
		}

		$file_array = [
			'name' => sanitize_file_name('image-' . time() . '.' . $ext),
			'tmp_name' => $tmp
		];

		// Verify if it's actually an image
		if (!getimagesize($tmp)) {
			@unlink($tmp);
			return new WP_Error('invalid_image', __('Invalid image file', 'poststation'));
		}

		$attachment_id = media_handle_sideload($file_array, $post_id);
		@unlink($tmp);

		return $attachment_id;
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
			'post_type' => $postwork['post_type'] ?? 'post',
			'post_status' => $postwork['post_status'] ?? 'pending',
			'post_author' => $postwork['default_author_id'] ?? get_current_user_id(),
		];

		$title = $data['title'] ?? null;
		$content = $data['content'] ?? null;

		// Slug priority: 1. AI response ($data['slug']), 2. Title
		$slug = ($data['slug'] ?? null) ?: $title;

		// Set title and content from post fields if available
		if (!empty($title)) {
			$post_data['post_title'] = $title;
		}

		if (!empty($slug)) {
			// Ensure it's slugified if it came from title
			$post_data['post_name'] = sanitize_title($slug);
		}

		if (!empty($content)) {
			// Clean content: remove <hr> tags
			$content = preg_replace('/<(hr)\s*\/?>/i', '', $content);
			$post_data['post_content'] = $content;
		}

		return $post_data;
	}
}