<?php

namespace PostStation\Api;

use WP_Error;
use Exception;
use PostStation\Models\Webhook;
use PostStation\Models\PostTask;
use PostStation\Models\Campaign;
use PostStation\Services\SettingsService;

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
		// Get and validate task
		$task_id = $data['task_id'] ?? null;
		$task = $task_id ? PostTask::get_by_id($task_id) : null;

		// Get campaign
		$campaign = null;
		if ($task) {
			$campaign = Campaign::get_by_id((int) $task['campaign_id']);
		}

		// Update task status to processing and reset timeout
		if ($task_id && $task) {
			PostTask::update($task_id, [
				'status' => 'processing',
				'run_started_at' => current_time('mysql')
			]);
		}

		try {
			// Allow developers to handle content publication
			$result = apply_filters('poststation_handle_content_publication', null, $data, $task ?: [], $campaign ?: []);

			if ($result === null) {
				// Use default publication handler if no custom handler
				$result = $this->handle_content_publication($data, $task ?: [], $campaign ?: []);
			} elseif (!is_array($result) || !isset($result['post_id'])) {
				throw new Exception('Invalid custom publication result. Must return array with post_id');
			}

			// Update task status to completed
			if ($task_id && $task) {
				$task_update = [
					'status' => 'completed',
					'post_id' => $result['post_id'],
					'error_message' => null,
					'progress' => null,
				];
				if (!empty($result['scheduled_publication_date'])) {
					$task_update['scheduled_publication_date'] = $result['scheduled_publication_date'];
				}
				PostTask::update($task_id, $task_update);
			}

			// Return standardized response
			return array_merge([
				'success' => true,
				'post_url' => get_permalink($result['post_id']),
				'edit_url' => get_edit_post_link($result['post_id'], 'url'),
			], $result);
		} catch (Exception $e) {
			// Update task status to failed
			if ($task_id && $task) {
				PostTask::update($task_id, [
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
	 * @param array $task Post task data
	 * @param array $campaign Campaign data
	 * @return array
	 * @throws Exception If publication fails
	 */
	private function handle_content_publication(array $data, array $task, array $campaign): array
	{
		// Prepare post data
		$post_data = $this->prepare_post_data($data, $task, $campaign);

		// Insert post
		$post_id = wp_insert_post($post_data, true);
		if (is_wp_error($post_id)) {
			throw new Exception($post_id->get_error_message());
		}

		// Handle taxonomies
		if (!empty($data['taxonomies'])) {
			$this->handle_taxonomies($post_id, $data['taxonomies'], $campaign);
		}

		// Handle thumbnail
		if (!empty($data['thumbnail_id'])) {
			$this->handle_thumbnail_by_id($post_id, (int) $data['thumbnail_id']);
		} elseif (!empty($data['thumbnail_url'])) {
			$this->handle_thumbnail($post_id, $data['thumbnail_url']);
		} elseif (!empty($task['feature_image_id'])) {
			set_post_thumbnail($post_id, $task['feature_image_id']);
		}

		// Handle post fields
		if (!empty($data['custom_fields'])) {
			$this->handle_custom_fields($post_id, $data['custom_fields']);
		}

		if (!empty($task['id'])) {
			$this->attach_task_images($post_id, (int) $task['id']);
		}

		return [
			'post_id' => $post_id,
			'scheduled_publication_date' => $post_data['post_status'] === 'future'
				? ($post_data['post_date'] ?? null)
				: null,
		];
	}

	private function attach_task_images(int $post_id, int $task_id): void
	{
		$attachments = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => 'poststation_posttask_id',
					'value' => $task_id,
				],
			],
		]);

		if (empty($attachments)) {
			return;
		}

		foreach ($attachments as $attachment_id) {
			wp_update_post([
				'ID' => $attachment_id,
				'post_parent' => $post_id,
			]);
		}
	}

	/**
	 * Handle taxonomies for the post
	 *
	 * @param int $post_id Post ID
	 * @param array $taxonomies Taxonomy data
	 * @param array $campaign Campaign data
	 * @return void
	 */
	private function handle_taxonomies(int $post_id, $taxonomies, array $campaign): void
	{
		if (is_string($taxonomies)) {
			$decoded = json_decode($taxonomies, true);
			$taxonomies = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($taxonomies)) {
			return;
		}

		foreach ($taxonomies as $taxonomy => $terms) {
			// Skip if taxonomy doesn't exist
			if (!taxonomy_exists($taxonomy)) {
				continue;
			}

			$normalized_terms = $this->normalize_taxonomy_terms($terms);
			if (empty($normalized_terms)) {
				continue;
			}

			// Handle hierarchical taxonomies (like categories)
			if (is_taxonomy_hierarchical($taxonomy)) {
				$term_ids = [];
				foreach ($normalized_terms as $term_name) {
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
				wp_set_object_terms($post_id, $normalized_terms, $taxonomy);
			}
		}
	}

	/**
	 * Normalize taxonomy terms to an array of names/slugs.
	 *
	 * Accepts comma-separated strings or arrays.
	 *
	 * @param mixed $terms
	 * @return array
	 */
	private function normalize_taxonomy_terms($terms): array
	{
		if (is_string($terms)) {
			$terms = explode(',', $terms);
		}

		if (!is_array($terms)) {
			return [];
		}

		$normalized = array_map(
			static function ($term) {
				return is_scalar($term) ? trim((string) $term) : '';
			},
			$terms
		);
		$normalized = array_filter($normalized, static fn($term) => $term !== '');

		return array_values(array_unique($normalized));
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
		$local_thumbnail_id = $this->resolve_local_thumbnail_id_from_url($thumbnail_url);
		if ($local_thumbnail_id > 0) {
			set_post_thumbnail($post_id, $local_thumbnail_id);
			return;
		}

		$thumbnail_id = $this->handle_image_upload($thumbnail_url, $post_id);
		if (is_wp_error($thumbnail_id)) {
			throw new Exception($thumbnail_id->get_error_message());
		}
		set_post_thumbnail($post_id, $thumbnail_id);
	}

	/**
	 * Handle thumbnail from an existing attachment id.
	 *
	 * @param int $post_id Post ID
	 * @param int $thumbnail_id Attachment ID
	 * @return void
	 * @throws Exception If thumbnail id is invalid
	 */
	private function handle_thumbnail_by_id(int $post_id, int $thumbnail_id): void
	{
		if ($thumbnail_id <= 0) {
			throw new Exception('Invalid thumbnail_id');
		}

		$attachment = get_post($thumbnail_id);
		if (
			!$attachment ||
			$attachment->post_type !== 'attachment' ||
			!str_starts_with((string) get_post_mime_type($thumbnail_id), 'image/')
		) {
			throw new Exception('Invalid thumbnail_id image');
		}

		set_post_thumbnail($post_id, $thumbnail_id);
	}

	/**
	 * Resolve local uploaded image attachment id from thumbnail URL.
	 *
	 * @param string $thumbnail_url Thumbnail URL
	 * @return int Attachment id or 0
	 */
	private function resolve_local_thumbnail_id_from_url(string $thumbnail_url): int
	{
		if (!$this->is_local_server_url($thumbnail_url)) {
			return 0;
		}

		$identifier = $this->extract_image_identifier_from_url($thumbnail_url);
		if ($identifier === '') {
			return 0;
		}

		$attachments = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => 'poststation_image_identifier',
					'value' => $identifier,
				],
			],
		]);

		return !empty($attachments) ? (int) $attachments[0] : 0;
	}

	/**
	 * Check whether URL is hosted on this server.
	 *
	 * @param string $url URL to check
	 * @return bool
	 */
	private function is_local_server_url(string $url): bool
	{
		$url_host = (string) parse_url($url, PHP_URL_HOST);
		if ($url_host === '') {
			return false;
		}

		$site_host = (string) parse_url(site_url(), PHP_URL_HOST);
		$home_host = (string) parse_url(home_url(), PHP_URL_HOST);

		return in_array($url_host, array_filter([$site_host, $home_host]), true);
	}

	/**
	 * Extract image identifier from upload filename.
	 * Expected format includes: -psid-{identifier}.ext
	 *
	 * @param string $url Image URL
	 * @return string
	 */
	private function extract_image_identifier_from_url(string $url): string
	{
		$path = (string) parse_url($url, PHP_URL_PATH);
		$basename = wp_basename($path);

		if (preg_match('/-psid-([a-z0-9]+)(?:-\d+)?\.[a-z0-9]+$/i', $basename, $matches)) {
			return strtolower($matches[1]);
		}

		return '';
	}

	/**
	 * Handle post fields for the post
	 *
	 * @param int $post_id Post ID
	 * @param array $task Post task data
	 * @param array $campaign Campaign data
	 * @param array $api_post_fields Custom fields data from API
	 * @return void
	 */
	private function handle_custom_fields(int $post_id, $api_post_fields = []): void
	{
		if (is_string($api_post_fields)) {
			$decoded = json_decode($api_post_fields, true);
			$api_post_fields = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($api_post_fields)) {
			return;
		}

		foreach ($api_post_fields as $meta_key => $meta_value) {
			// Skip title, content, and slug as they're handled in prepare_post_data
			if (in_array($meta_key, ['title', 'content', 'slug'], true)) {
				continue;
			}

			if (is_string($meta_key) && $meta_key !== '') {
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

		$task_id = $data['task_id'] ?? 0;
		$task = $task_id ? PostTask::get_by_id((int) $task_id) : null;

		// 1. Get image title (default to {{title}} if not set)
		$image_title = $task['feature_image_title'] ?? ($data['feature_image_title'] ?? '{{title}}');
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
			// Get last used image URL from campaign meta
			$campaign_id = $task['campaign_id'] ?? 0;
			$last_bg_url = '';

			if ($campaign_id) {
				$last_bg_url = get_option('poststation_last_bg_image_' . $campaign_id, '');
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
			if ($campaign_id) {
				update_option('poststation_last_bg_image_' . $campaign_id, $bg_image_url);
			}
		}

		if (!empty($bg_image_url)) {
			$tunnel_url = SettingsService::get_tunnel_url();

			// Parse the BG image URL host
			$image_host = parse_url($bg_image_url, PHP_URL_HOST);

			// If local image URLs are used, replace them with the configured tunnel URL.
			if (
				$tunnel_url !== '' &&
				$image_host &&
				(in_array($image_host, ['localhost', '127.0.0.1']) || strpos($image_host, '.local') !== false)
			) {
				$scheme = parse_url($bg_image_url, PHP_URL_SCHEME);
				$local_base = $scheme . '://' . $image_host;
				$bg_image_url = str_replace($local_base, $tunnel_url, $bg_image_url);
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
	 * @param array $task Post task data
	 * @param array $campaign Campaign data
	 * @return array
	 */
	private function prepare_post_data(array $data, array $task, array $campaign): array
	{
		$publication = $this->resolve_publication_details($task, $campaign);
		$post_data = [
			'post_type' => $campaign['post_type'] ?? 'post',
			'post_status' => $publication['post_status'],
			'post_author' => $campaign['default_author_id'] ?? get_current_user_id(),
		];
		if (!empty($publication['post_date'])) {
			$post_data['post_date'] = $publication['post_date'];
			$post_data['post_date_gmt'] = get_gmt_from_date($publication['post_date']);
		}

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

	private function resolve_publication_details(array $task, array $campaign): array
	{
		$has_override = !empty($task['publication_override']);
		if ($has_override) {
			$mode = $this->sanitize_task_publication_mode($task['publication_mode'] ?? 'pending_review');
			return $this->resolve_task_override_publication_details($mode, $task);
		}

		$mode = $this->sanitize_campaign_publication_mode(
			$task['publication_mode']
				?? ($campaign['publication_mode'] ?? ($campaign['post_status'] ?? 'pending'))
		);
		return $this->resolve_campaign_publication_details($mode, $task, $campaign);
	}

	private function resolve_task_override_publication_details(string $mode, array $task): array
	{
		if ($mode === 'pending_review') {
			return ['post_status' => 'pending', 'post_date' => null];
		}
		if ($mode === 'publish_instantly') {
			return ['post_status' => 'publish', 'post_date' => null];
		}

		$date_value = trim((string) ($task['publication_date'] ?? ''));
		if ($date_value === '') {
			throw new Exception('Publication Date is required when Task Publication Mode is Set a Date.');
		}
		$date_ts = strtotime($date_value);
		if (!$date_ts) {
			throw new Exception('Invalid Publication Date.');
		}
		$now_ts = current_time('timestamp');
		if ($date_ts <= $now_ts) {
			return ['post_status' => 'publish', 'post_date' => null];
		}

		return [
			'post_status' => 'future',
			'post_date' => wp_date('Y-m-d H:i:s', $date_ts),
		];
	}

	private function resolve_campaign_publication_details(string $mode, array $task, array $campaign): array
	{
		if ($mode === 'pending_review') {
			return ['post_status' => 'pending', 'post_date' => null];
		}
		if ($mode === 'publish_instantly') {
			return ['post_status' => 'publish', 'post_date' => null];
		}
		if ($mode === 'publish_intervals') {
			$interval_value = max(1, (int) ($campaign['publication_interval_value'] ?? 1));
			$interval_unit = $this->sanitize_publication_interval_unit($campaign['publication_interval_unit'] ?? 'hour');
			$seconds = $interval_unit === 'minute'
				? ($interval_value * MINUTE_IN_SECONDS)
				: ($interval_value * HOUR_IN_SECONDS);
			$publish_ts = current_time('timestamp') + $seconds;

			return [
				'post_status' => 'future',
				'post_date' => wp_date('Y-m-d H:i:s', $publish_ts),
			];
		}

		$rolling_days = $this->sanitize_rolling_schedule_days($campaign['rolling_schedule_days'] ?? 30);
		$selected_datetime = $this->resolve_rolling_schedule_publication_datetime($task, $rolling_days);
		return [
			'post_status' => 'future',
			'post_date' => $selected_datetime,
		];
	}

	private function resolve_rolling_schedule_publication_datetime(array $task, int $days): string
	{
		$campaign_id = (int) ($task['campaign_id'] ?? 0);
		$current_task_id = (int) ($task['id'] ?? 0);
		if ($campaign_id <= 0) {
			throw new Exception('Invalid campaign for rolling schedule.');
		}

		$completed_count = $this->count_completed_rolling_schedule_tasks($campaign_id, $current_task_id);
		$day_offset = $completed_count % $days;
		$today = wp_date('Y-m-d', current_time('timestamp'));
		$selected_date_ts = strtotime($today . ' 00:00:00');
		$selected_date_ts = strtotime('+' . $day_offset . ' days', $selected_date_ts);
		$selected_date = wp_date('Y-m-d', $selected_date_ts);
		$selected_ts = strtotime($selected_date . ' 09:00:00');
		$now_ts = current_time('timestamp');
		if ($selected_ts <= $now_ts) {
			$selected_ts = $now_ts + 120;
		}

		return wp_date('Y-m-d H:i:s', $selected_ts);
	}

	private function count_completed_rolling_schedule_tasks(int $campaign_id, int $current_task_id): int
	{
		global $wpdb;

		$table_name = $wpdb->prefix . PostTask::get_table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE campaign_id = %d
				AND id <> %d
				AND status = 'completed'
				AND publication_override = 0
				AND publication_mode = 'rolling_schedule'
				AND scheduled_publication_date IS NOT NULL",
				$campaign_id,
				$current_task_id
			)
		);

		return max(0, (int) $count);
	}

	private function sanitize_campaign_publication_mode($mode): string
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

	private function sanitize_task_publication_mode($mode): string
	{
		$raw = sanitize_text_field((string) ($mode ?? 'pending_review'));
		if ($raw === 'pending') {
			$raw = 'pending_review';
		}
		if ($raw === 'publish') {
			$raw = 'publish_instantly';
		}
		if ($raw === 'future' || $raw === 'schedule_date') {
			$raw = 'set_date';
		}
		if ($raw === 'publish_randomly') {
			$raw = 'pending_review';
		}
		$allowed = ['pending_review', 'publish_instantly', 'set_date'];
		return in_array($raw, $allowed, true) ? $raw : 'pending_review';
	}

	private function sanitize_publication_interval_unit($unit): string
	{
		$unit = sanitize_text_field((string) $unit);
		return in_array($unit, ['minute', 'hour'], true) ? $unit : 'hour';
	}

	private function sanitize_rolling_schedule_days($days): int
	{
		$days = (int) $days;
		return in_array($days, [7, 14, 30, 60], true) ? $days : 30;
	}
}
