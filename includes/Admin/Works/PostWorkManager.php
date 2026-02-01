<?php

namespace PostStation\Admin\Works;

use PostStation\Models\PostWork;
use PostStation\Models\PostBlock;
use PostStation\Models\Webhook;
use PostStation\Services\Sitemap;
use Exception;
use PostStation\Admin\Works\PostWorksTable;

class PostWorkManager
{
	private const MENU_SLUG = 'poststation-postworks';
	private const NONCE_ACTION = 'poststation_postwork_action';
	private const NONCE_NAME = 'poststation_postwork_nonce';
	private $postBlockManager;

	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('wp_ajax_poststation_create_postwork', [$this, 'handle_create_postwork']);
		add_action('wp_ajax_poststation_update_postwork', [$this, 'handle_update_postwork']);
		add_action('wp_ajax_poststation_delete_postwork', [$this, 'handle_delete_postwork']);
		add_action('wp_ajax_poststation_create_postblock', [$this, 'handle_create_postblock']);
		add_action('wp_ajax_poststation_update_blocks', [$this, 'handle_update_blocks']);
		add_action('wp_ajax_poststation_delete_postblock', [$this, 'handle_delete_postblock']);
		add_action('wp_ajax_poststation_run_postwork', [$this, 'handle_run_postwork']);
		add_action('wp_ajax_poststation_export_postwork', [$this, 'handle_export_postwork']);
		add_action('wp_ajax_poststation_import_postwork', [$this, 'handle_import_postwork']);
		add_action('wp_ajax_poststation_import_blocks', [$this, 'handle_import_blocks']);
		add_action('wp_ajax_poststation_clear_completed_blocks', [$this, 'handle_clear_completed_blocks']);
	}

	public function enqueue_scripts(): void
	{
		$screen = get_current_screen();
		if ($screen->id !== 'poststation_page_' . self::MENU_SLUG) {
			// return;
		}

		wp_enqueue_style('poststation-admin');

		// Add Select2 CSS from CDN
		wp_enqueue_style(
			'select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
			[],
			'4.1.0-rc.0'
		);

		// Add Select2 JS from CDN
		wp_enqueue_script(
			'select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
			['jquery'],
			'4.1.0-rc.0',
			true
		);

		wp_enqueue_script('poststation-postwork', POSTSTATION_URL . 'assets/js/postwork.js', ['jquery', 'select2'], filemtime(POSTSTATION_PATH . 'assets/js/postwork.js'), true);

		// Add media scripts
		wp_enqueue_media();

		// Get available post types
		$post_types = get_post_types(['public' => true], 'objects');
		$post_type_options = [];
		foreach ($post_types as $type) {
			$post_type_options[$type->name] = $type->labels->singular_name;
		}

		// Get available taxonomies
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		$taxonomy_data = [];
		foreach ($taxonomies as $tax) {
			$taxonomy_data[$tax->name] = [
				'name' => $tax->name,
				'label' => $tax->labels->name,
				'singular_label' => $tax->labels->singular_name
			];
		}

		wp_localize_script('poststation-postwork', 'poststation', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'admin_url' => admin_url(),
			'rest_url' => rest_url(),
			'nonce' => wp_create_nonce(self::NONCE_ACTION),
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data
		]);
	}

	public function render_page(): void
	{
		$action = $_GET['action'] ?? 'list';
		$postwork_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

		switch ($action) {
			case 'edit':
				$this->render_edit_page($postwork_id);
				break;
			default:
				$this->render_list_page();
				break;
		}
	}

	private function render_list_page(): void
	{

		if (!class_exists('WP_List_Table')) {
			require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
		}

		$table = new PostWorksTable();
		$table->prepare_items();
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php _e('Post Works', 'poststation'); ?></h1>
	<button class="page-title-action" id="add-new-postwork">
		<?php _e('Add New', 'poststation'); ?>
	</button>
	<button class="page-title-action" id="import-postwork">
		<?php _e('Import', 'poststation'); ?>
	</button>
	<input type="file" id="import-file" accept=".json" style="display: none;">

	<form method="post">
		<?php
				$table->display();
				?>
	</form>
</div>
<?php
	}

	private function render_edit_page(int $postwork_id): void
	{
		// Ensure tables are up to date
		\PostStation\Models\PostWork::update_tables();
		\PostStation\Models\PostBlock::update_tables();

		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_die(__('Post work not found.', 'poststation'));
		}

		$blocks = PostBlock::get_by_postwork($postwork_id);
		$webhooks = Webhook::get_all();

		include POSTSTATION_PATH . 'includes/Admin/Works/Views/Edit.php';
	}

	public function handle_create_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		if (empty($title)) {
			wp_send_json_error(__('Title is required.', 'poststation'));
		}

		$postwork_id = PostWork::create(['title' => $title]);
		if (!$postwork_id) {
			wp_send_json_error(__('Failed to create post work.', 'poststation'));
		}

		wp_send_json_success([
			'id' => $postwork_id,
			'redirect_url' => add_query_arg([
				'page' => self::MENU_SLUG,
				'action' => 'edit',
				'id' => $postwork_id,
			], admin_url('admin.php')),
		]);
	}

	public function handle_update_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['id'];
		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		$webhook_id = (int)$_POST['webhook_id'];
		$post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
		$post_status = sanitize_text_field($_POST['post_status'] ?? 'pending');
		$default_author_id = (int)$_POST['default_author_id'];
		$instructions = sanitize_textarea_field(wp_unslash($_POST['instructions'] ?? ''));
		$enabled_taxonomies = json_decode(wp_unslash($_POST['enabled_taxonomies'] ?? '{}'), true);
		$default_terms = json_decode(wp_unslash($_POST['default_terms'] ?? '{}'), true);
		$post_fields = json_decode(wp_unslash($_POST['post_fields'] ?? '{}'), true);
		$image_config = json_decode(wp_unslash($_POST['image_config'] ?? '{}'), true);
		$content_fields = wp_unslash($_POST['content_fields'] ?? '{}');

		if (empty($title)) {
			wp_send_json_error(__('Title is required.', 'poststation'));
		}

		// Validate post type
		$post_types = get_post_types(['public' => true]);
		if (!in_array($post_type, $post_types)) {
			wp_send_json_error(__('Invalid post type.', 'poststation'));
		}

		// Validate taxonomies and their terms
		$valid_taxonomies = [];
		$valid_terms = [];
		$public_taxonomies = get_taxonomies(['public' => true]);

		foreach ($public_taxonomies as $tax_name) {
			$valid_taxonomies[$tax_name] = isset($enabled_taxonomies[$tax_name]) && $enabled_taxonomies[$tax_name];

			// Only include terms for enabled taxonomies
			if ($valid_taxonomies[$tax_name] && isset($default_terms[$tax_name])) {
				// Verify terms exist in the taxonomy
				$terms = get_terms([
					'taxonomy' => $tax_name,
					'hide_empty' => false,
					'fields' => 'slugs'
				]);

				$valid_terms[$tax_name] = array_intersect($default_terms[$tax_name], $terms);
			}
		}

		// Validate post status
		$valid_statuses = array_keys(get_post_statuses());
		if (!in_array($post_status, $valid_statuses)) {
			wp_send_json_error(__('Invalid post status.', 'poststation'));
		}

		// Validate author
		if ($default_author_id && !get_userdata($default_author_id)) {
			wp_send_json_error(__('Invalid author.', 'poststation'));
		}

		$success = PostWork::update($postwork_id, [
			'title' => $title,
			'webhook_id' => $webhook_id ?: null,
			'post_type' => $post_type,
			'post_status' => $post_status,
			'default_author_id' => $default_author_id ?: get_current_user_id(),
			'instructions' => $instructions,
			'enabled_taxonomies' => wp_json_encode($valid_taxonomies),
			'default_terms' => wp_json_encode($valid_terms),
			'post_fields' => wp_json_encode($post_fields),
			'image_config' => wp_json_encode($image_config),
			'content_fields' => $content_fields
		]);

		if (!$success) {
			wp_send_json_error(__('Failed to update post work.', 'poststation'));
		}

		wp_send_json_success();
	}

	public function handle_delete_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['id'];

		// Delete associated blocks first
		PostBlock::delete_by_postwork($postwork_id);

		$success = PostWork::delete($postwork_id);
		if (!$success) {
			wp_send_json_error(__('Failed to delete post work.', 'poststation'));
		}

		wp_send_json_success();
	}

	public function handle_run_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)($_POST['id'] ?? 0);
		$block_id = (int)($_POST['block_id'] ?? 0);
		$webhook_id = (int)($_POST['webhook_id'] ?? 0);

		// Validate inputs
		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_send_json_error(__('Post work not found.', 'poststation'));
		}

		$block = PostBlock::get_by_id($block_id);
		if (!$block) {
			wp_send_json_error(__('Block not found.', 'poststation'));
		}

		$webhook = Webhook::get_by_id($webhook_id);
		if (!$webhook) {
			wp_send_json_error(__('Webhook not found.', 'poststation'));
		}

		try {
			$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
			$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];
			$post_fields = !empty($block_post_fields) ? $block_post_fields : $postwork_post_fields;

			// Process placeholders in instructions
			$processed_instructions = $this->process_placeholders($postwork['instructions'] ?? '', $block, $postwork);

			// Process placeholders in each post field prompt and value
			$processed_post_fields = [];
			foreach ($post_fields as $key => $field) {
				if (is_array($field)) {
					$processed_post_fields[$key] = [
						'value' => $this->process_placeholders($field['value'] ?? '', $block, $postwork),
						'prompt' => $this->process_placeholders($field['prompt'] ?? '', $block, $postwork),
						'type' => $field['type'] ?? 'string',
						'required' => $field['required'] ?? false
					];
				} else {
					$processed_post_fields[$key] = $this->process_placeholders($field, $block, $postwork);
				}
			}

			// Get image config from postwork
			$image_config = !empty($postwork['image_config']) ? json_decode($postwork['image_config'], true) : [];

			$body = [
				'block_id' => $block['id'],
				'article_url' => $block['article_url'] ?? '',
				'keyword' => $block['keyword'] ?? '',
				'instructions' => $processed_instructions,
				'taxonomies' => json_decode($block['taxonomies'] ?? '{}', true),
				'post_fields' => $processed_post_fields,
				'feature_image_title' => $block['feature_image_title'] ?? '{{title}}',
				'sitemap' => (new Sitemap())->get_sitemap_json($postwork['post_type']),
				'image_config' => $image_config,
				'callback_url' => get_site_url() . '/ps-api/create',
				'api_key' => get_option('poststation_api_key'),
			];
			// Update block status to processing in DB
			PostBlock::update($block_id, ['status' => 'processing']);

			// Send data to webhook
			$response = wp_remote_post($webhook['url'], [
				'headers' => ['Content-Type' => 'application/json'],
				'body' => wp_json_encode($body),
				'timeout' => 30,
				'sslverify' => false,
			]);

			if (is_wp_error($response)) {
				throw new Exception($response->get_error_message());
			}

			$response_code = wp_remote_retrieve_response_code($response);
			if ($response_code !== 200) {
				throw new Exception(sprintf(
					__('Webhook returned error code: %d', 'poststation'),
					$response_code
				));
			}

			wp_send_json_success([
				'message' => __('Block sent to webhook for processing.', 'poststation'),
			]);
		} catch (Exception $e) {
			PostBlock::update($block_id, [
				'status' => 'failed',
				'error_message' => $e->getMessage(),
			]);

			wp_send_json_error($e->getMessage());
		}
	}

	public function handle_create_postblock(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['postwork_id'];
		if (!$postwork_id) {
			wp_send_json_error(__('Invalid post work ID.', 'poststation'));
		}

		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_send_json_error(__('Post work not found.', 'poststation'));
		}

		$block_id = PostBlock::create([
			'postwork_id' => $postwork_id,
			'article_url' => '',
			'keyword' => '',
			'taxonomies' => '{}',
			'post_fields' => '{}',
			'feature_image_id' => null,
			'status' => 'pending'
		]);

		if (!$block_id) {
			wp_send_json_error(__('Failed to create post block.', 'poststation'));
		}

		wp_send_json_success(['id' => $block_id]);
	}

	public function handle_update_blocks(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$blocks = json_decode(stripslashes($_POST['blocks']), true);
		if (!is_array($blocks)) {
			wp_send_json_error(__('Invalid blocks data.', 'poststation'));
		}

		$success = true;
		$errors = [];

		foreach ($blocks as $block) {
			$block_id = (int)$block['id'];
			$article_url = !empty($block['article_url']) ? esc_url_raw($block['article_url']) : null;
			$keyword = !empty($block['keyword']) ? sanitize_text_field($block['keyword']) : null;
			$taxonomies = json_decode($block['taxonomies'] ?? '{}', true);
			$post_fields = json_decode($block['post_fields'] ?? '{}', true);
			$feature_image_id = !empty($block['feature_image_id']) ? (int)$block['feature_image_id'] : null;
			$feature_image_title = !empty($block['feature_image_title']) ? sanitize_text_field($block['feature_image_title']) : '{{title}}';

			// Validate and sanitize taxonomies
			$valid_taxonomies = [];
			if (is_array($taxonomies)) {
				foreach ($taxonomies as $tax_name => $terms) {
					// Verify taxonomy exists
					if (!taxonomy_exists($tax_name)) {
						continue;
					}

					// Clean and validate terms
					$terms = array_map('sanitize_text_field', array_map('trim', $terms));
					$terms = array_filter($terms);

					if (!empty($terms)) {
						$valid_taxonomies[$tax_name] = $terms;
					}
				}
			}

			$result = PostBlock::update($block_id, [
				'article_url' => $article_url,
				'keyword' => $keyword,
				'post_fields' => wp_json_encode($post_fields),
				'taxonomies' => wp_json_encode($valid_taxonomies),
				'feature_image_id' => $feature_image_id,
				'feature_image_title' => $feature_image_title
			]);

			if (!$result) {
				$errors[] = sprintf(__('Failed to update block #%d.', 'poststation'), $block_id);
				$success = false;
			}
		}

		if (!$success) {
			wp_send_json_error([
				'message' => __('Some blocks failed to update.', 'poststation'),
				'errors' => $errors
			]);
		}

		wp_send_json_success();
	}

	private function process_placeholders(string $text, array $block, array $postwork): string
	{
		if (empty($text)) {
			return $text;
		}

		$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
		$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];

		$placeholders = [
			'{{article_url}}' => $block['article_url'] ?? '',
			'{{keyword}}' => $block['keyword'] ?? '',
			'{{image_title}}' => str_replace('{{title}}', $block['keyword'] ?: 'Post', $block['feature_image_title'] ?? '{{title}}'),
			'{{sitemap}}' => wp_json_encode((new Sitemap())->get_sitemap_json($postwork['post_type'])),
		];

		// Handle post fields
		foreach ($postwork_post_fields as $key => $field) {
			$value = $block_post_fields[$key]['value'] ?? $field['value'] ?? '';
			$placeholders["{{{$key}}}"] = $value;
		}

		return str_replace(array_keys($placeholders), array_values($placeholders), $text);
	}

	public function handle_delete_postblock(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$block_id = (int)$_POST['id'];
		$success = PostBlock::delete($block_id);

		if (!$success) {
			wp_send_json_error(__('Failed to delete post block.', 'poststation'));
		}

		wp_send_json_success();
	}

	public function handle_export_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['id'];
		$include_blocks = isset($_POST['include_blocks']) ? (bool)$_POST['include_blocks'] : true;
		$exclude_statuses = isset($_POST['exclude_statuses']) ? (array)$_POST['exclude_statuses'] : ['completed', 'failed'];

		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_send_json_error(__('Post work not found.', 'poststation'));
		}

		// Remove internal fields
		unset($postwork['id']);
		unset($postwork['author_id']);
		unset($postwork['created_at']);
		unset($postwork['updated_at']);
		unset($postwork['webhook_id']);

		$export_data = [
			'postwork' => $postwork,
			'blocks' => [],
		];

		if ($include_blocks) {
			$blocks = PostBlock::get_by_postwork($postwork_id);
			foreach ($blocks as $block) {
				// Skip blocks with excluded statuses
				if (in_array($block['status'], $exclude_statuses)) {
					continue;
				}

				// Remove internal fields
				unset($block['id']);
				unset($block['postwork_id']);
				unset($block['post_id']);
				unset($block['created_at']);
				unset($block['updated_at']);

				$export_data['blocks'][] = $block;
			}
		}

		wp_send_json_success($export_data);
	}

	public function handle_import_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$import_data = json_decode(stripslashes($_POST['import_data']), true);
		if (!$import_data || !isset($import_data['postwork'])) {
			wp_send_json_error(__('Invalid import data.', 'poststation'));
		}

		// Create new postwork
		$postwork_data = $import_data['postwork'];
		$postwork_data['author_id'] = get_current_user_id();

		$postwork_id = PostWork::create($postwork_data);
		if (!$postwork_id) {
			wp_send_json_error(__('Failed to create post work.', 'poststation'));
		}

		// Import blocks if any
		if (!empty($import_data['blocks'])) {
			foreach ($import_data['blocks'] as $block_data) {
				$block_data['postwork_id'] = $postwork_id;
				$block_data['status'] = 'pending'; // Reset status to pending
				PostBlock::create($block_data);
			}
		}

		wp_send_json_success([
			'message' => __('Post work imported successfully.', 'poststation'),
			'redirect_url' => add_query_arg([
				'page' => self::MENU_SLUG,
				'action' => 'edit',
				'id' => $postwork_id,
			], admin_url('admin.php')),
		]);
	}

	public function handle_import_blocks(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)($_POST['postwork_id'] ?? 0);
		if (!$postwork_id) {
			wp_send_json_error(__('Invalid post work ID.', 'poststation'));
		}

		$blocks_json = stripslashes($_POST['blocks_json'] ?? '[]');
		$blocks = json_decode($blocks_json, true);

		if (!is_array($blocks)) {
			wp_send_json_error(__('Invalid blocks JSON format.', 'poststation'));
		}

		if (empty($blocks)) {
			wp_send_json_error(__('No blocks to import.', 'poststation'));
		}

		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_send_json_error(__('Post work not found.', 'poststation'));
		}

		$created_count = 0;
		foreach ($blocks as $block_data) {
			$keyword = sanitize_text_field($block_data['topic'] ?? $block_data['keyword'] ?? '');
			$article_url = esc_url_raw($block_data['article_url'] ?? '');
			$feature_image_title = sanitize_text_field($block_data['feature_image_title'] ?? '{{title}}');
			$slug = sanitize_text_field($block_data['slug'] ?? '');

			$data = [
				'postwork_id' => $postwork_id,
				'article_url' => $article_url,
				'keyword' => $keyword,
				'feature_image_title' => $feature_image_title,
				'status' => 'pending'
			];

			// Handle post fields (especially slug)
			$post_fields = [];
			if (isset($block_data['post_fields']) && is_array($block_data['post_fields'])) {
				$post_fields = $block_data['post_fields'];
			}

			// If slug is provided at the top level, add it to post_fields
			if (!empty($slug)) {
				$post_fields['slug'] = [
					'value' => $slug,
					'prompt' => '',
					'type' => 'string',
					'required' => false
				];
			}

			if (!empty($post_fields)) {
				$data['post_fields'] = wp_json_encode($post_fields);
			}

			// Handle taxonomies
			if (isset($block_data['taxonomies']) && is_array($block_data['taxonomies'])) {
				$data['taxonomies'] = wp_json_encode($block_data['taxonomies']);
			}

			$block_id = PostBlock::create($data);
			if ($block_id) {
				$created_count++;
			}
		}

		wp_send_json_success([
			'message' => sprintf(__('%d blocks imported successfully.', 'poststation'), $created_count),
			'count' => $created_count
		]);
	}

	public function handle_clear_completed_blocks(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)($_POST['postwork_id'] ?? 0);
		if (!$postwork_id) {
			wp_send_json_error(__('Invalid post work ID.', 'poststation'));
		}

		$success = PostBlock::delete_completed_by_postwork($postwork_id);

		if ($success) {
			wp_send_json_success(__('Completed blocks cleared successfully.', 'poststation'));
		} else {
			wp_send_json_error(__('Failed to clear completed blocks.', 'poststation'));
		}
	}
}