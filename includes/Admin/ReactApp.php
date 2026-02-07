<?php

namespace PostStation\Admin;

use PostStation\Models\PostWork;
use PostStation\Models\PostBlock;
use PostStation\Models\Webhook;
use PostStation\Utils\Languages;
use PostStation\Utils\Countries;

/**
 * React SPA Admin Interface
 */
class ReactApp
{
	public function __construct()
	{
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		
		// Register AJAX handlers
		add_action('wp_ajax_poststation_get_postworks', [$this, 'ajax_get_postworks']);
		add_action('wp_ajax_poststation_get_postwork', [$this, 'ajax_get_postwork']);
		add_action('wp_ajax_poststation_get_webhooks', [$this, 'ajax_get_webhooks']);
		add_action('wp_ajax_poststation_get_webhook', [$this, 'ajax_get_webhook']);
		add_action('wp_ajax_poststation_save_webhook', [$this, 'ajax_save_webhook']);
		add_action('wp_ajax_poststation_delete_webhook', [$this, 'ajax_delete_webhook']);
		add_action('wp_ajax_poststation_get_settings', [$this, 'ajax_get_settings']);
		add_action('wp_ajax_poststation_save_api_key', [$this, 'ajax_save_api_key']);

		// PostWork AJAX handlers
		add_action('wp_ajax_poststation_create_postwork', [$this, 'ajax_create_postwork']);
		add_action('wp_ajax_poststation_update_postwork', [$this, 'ajax_update_postwork']);
		add_action('wp_ajax_poststation_delete_postwork', [$this, 'ajax_delete_postwork']);
		add_action('wp_ajax_poststation_run_postwork', [$this, 'ajax_run_postwork']);
		add_action('wp_ajax_poststation_stop_postwork_run', [$this, 'ajax_stop_postwork_run']);
		add_action('wp_ajax_poststation_export_postwork', [$this, 'ajax_export_postwork']);
		add_action('wp_ajax_poststation_import_postwork', [$this, 'ajax_import_postwork']);

		// PostBlock AJAX handlers
		add_action('wp_ajax_poststation_create_postblock', [$this, 'ajax_create_postblock']);
		add_action('wp_ajax_poststation_update_blocks', [$this, 'ajax_update_blocks']);
		add_action('wp_ajax_poststation_delete_postblock', [$this, 'ajax_delete_postblock']);
		add_action('wp_ajax_poststation_clear_completed_blocks', [$this, 'ajax_clear_completed_blocks']);
		add_action('wp_ajax_poststation_import_blocks', [$this, 'ajax_import_blocks']);
	}

	/**
	 * Register the React app menu page
	 */
	public function register_menu(): void
	{
		add_menu_page(
			__('Post Station', 'poststation'),
			__('Post Station', 'poststation'),
			'edit_posts',
			'poststation-app',
			[$this, 'render_app'],
			'dashicons-rest-api',
			30
		);
	}

	/**
	 * Render the React app container
	 */
	public function render_app(): void
	{
		echo '<div id="poststation-app"></div>';
	}

	/**
	 * Enqueue React app scripts and styles
	 */
	public function enqueue_scripts(string $hook): void
	{
		// Only load on our admin page
		if ($hook !== 'toplevel_page_poststation-app') {
			return;
		}

		$build_path = POSTSTATION_PATH . 'build/';
		$build_url = POSTSTATION_URL . 'build/';

		// Check if build files exist
		if (!file_exists($build_path . 'poststation-admin.js')) {
			add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>PostStation React build not found. Run <code>npm run build</code> to compile.</p></div>';
			});
			return;
		}

		// Load asset file for dependencies and version
		$asset_file = $build_path . 'poststation-admin.asset.php';
		$asset = file_exists($asset_file) 
			? require $asset_file 
			: ['dependencies' => ['react', 'react-dom'], 'version' => filemtime($build_path . 'poststation-admin.js')];

		// Enqueue the React bundle
		wp_enqueue_script(
			'poststation-react-app',
			$build_url . 'poststation-admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Enqueue styles if separate CSS file exists
		if (file_exists($build_path . 'poststation-admin.css')) {
			wp_enqueue_style(
				'poststation-react-app',
				$build_url . 'poststation-admin.css',
				[],
				$asset['version']
			);
		}

		// Enqueue WordPress media library
		wp_enqueue_media();

		// Get post types
		$post_types = get_post_types(['public' => true], 'objects');
		$post_type_options = [];
		foreach ($post_types as $post_type) {
			$post_type_options[$post_type->name] = $post_type->label;
		}

		// Get taxonomies with terms (categories, tags, custom taxonomies)
		$taxonomy_data = [];
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		foreach ($taxonomies as $taxonomy) {
			$terms = get_terms([
				'taxonomy' => $taxonomy->name,
				'hide_empty' => false,
			]);
			if (is_wp_error($terms)) {
				$terms = [];
			}
			$terms_array = [];
			foreach ((array) $terms as $term) {
				$term_obj = is_object($term) ? $term : (object) $term;
				$terms_array[] = [
					'term_id' => $term_obj->term_id ?? 0,
					'name' => $term_obj->name ?? '',
					'slug' => $term_obj->slug ?? '',
				];
			}
			$taxonomy_data[$taxonomy->name] = [
				'label' => $taxonomy->labels->name ?? $taxonomy->name,
				'terms' => $terms_array,
			];
		}

		// Get users for author dropdown
		$users = get_users(['role__in' => ['administrator', 'editor', 'author']]);
		$user_data = array_map(function($user) {
			return [
				'id' => $user->ID,
				'display_name' => $user->display_name,
			];
		}, $users);

		// Pass data to JavaScript
		wp_localize_script('poststation-react-app', 'poststation', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'admin_url' => admin_url(),
			'rest_url' => rest_url(),
			'nonce' => wp_create_nonce('poststation_postwork_action'), // Use existing nonce for PostWork operations
			'react_nonce' => wp_create_nonce('poststation_react_action'), // For React-specific operations
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data,
			'languages' => Languages::all(),
			'countries' => Countries::all(),
			'users' => $user_data,
			'current_user_id' => get_current_user_id(),
		]);
	}

	/**
	 * Verify AJAX nonce
	 */
	private function verify_nonce(): bool
	{
		// Accept both nonces for flexibility
		$nonce = $_POST['nonce'] ?? '';
		return wp_verify_nonce($nonce, 'poststation_postwork_action') || 
		       wp_verify_nonce($nonce, 'poststation_react_action');
	}

	/**
	 * AJAX: Get all postworks
	 */
	public function ajax_get_postworks(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$postworks = PostWork::get_all();
		
		// Add block counts to each postwork
		foreach ($postworks as &$postwork) {
			$blocks = PostBlock::get_by_postwork($postwork['id']);
			$counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
			foreach ($blocks as $block) {
				$status = $block['status'] ?? 'pending';
				if (isset($counts[$status])) {
					$counts[$status]++;
				}
			}
			$postwork['block_counts'] = $counts;
			$postwork['blocks_total'] = count($blocks);
		}

		wp_send_json_success(['postworks' => $postworks]);
	}

	/**
	 * AJAX: Get single postwork with blocks
	 */
	public function ajax_get_postwork(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$id = intval($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}

		$postwork = PostWork::get_by_id($id);
		if (!$postwork) {
			wp_send_json_error(['message' => 'PostWork not found']);
		}

		$blocks = PostBlock::get_by_postwork($id);

		// Get users for author dropdown
		$users = get_users(['role__in' => ['administrator', 'editor', 'author']]);
		$user_data = array_map(function($user) {
			return [
				'id' => $user->ID,
				'display_name' => $user->display_name,
			];
		}, $users);

		// Get taxonomies with terms for content field dropdowns
		$taxonomy_data = [];
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		foreach ($taxonomies as $taxonomy) {
			$terms = get_terms([
				'taxonomy' => $taxonomy->name,
				'hide_empty' => false,
			]);
			if (is_wp_error($terms)) {
				$terms = [];
			}
			$terms_array = [];
			foreach ((array) $terms as $term) {
				$term_obj = is_object($term) ? $term : (object) $term;
				$terms_array[] = [
					'term_id' => $term_obj->term_id ?? 0,
					'name' => $term_obj->name ?? '',
					'slug' => $term_obj->slug ?? '',
				];
			}
			$taxonomy_data[$taxonomy->name] = [
				'label' => $taxonomy->labels->name ?? $taxonomy->name,
				'terms' => $terms_array,
			];
		}

		wp_send_json_success([
			'postwork' => $postwork,
			'blocks' => $blocks,
			'users' => $user_data,
			'taxonomies' => $taxonomy_data,
		]);
	}

	/**
	 * AJAX: Get all webhooks
	 */
	public function ajax_get_webhooks(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$webhooks = Webhook::get_all();
		wp_send_json_success(['webhooks' => $webhooks]);
	}

	/**
	 * AJAX: Get single webhook
	 */
	public function ajax_get_webhook(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$id = intval($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}

		$webhook = Webhook::get_by_id($id);
		if (!$webhook) {
			wp_send_json_error(['message' => 'Webhook not found']);
		}

		wp_send_json_success(['webhook' => $webhook]);
	}

	/**
	 * AJAX: Get settings
	 */
	public function ajax_get_settings(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$api_key = get_option('poststation_api_key', '');

		wp_send_json_success([
			'api_key' => $api_key,
		]);
	}

	/**
	 * AJAX: Save API key
	 */
	public function ajax_save_api_key(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$api_key = sanitize_text_field($_POST['api_key'] ?? '');
		update_option('poststation_api_key', $api_key);

		wp_send_json_success(['message' => 'API key saved']);
	}

	/**
	 * AJAX: Save webhook (create or update)
	 */
	public function ajax_save_webhook(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = intval($_POST['id'] ?? 0);
		$name = sanitize_text_field($_POST['name'] ?? '');
		$url = esc_url_raw($_POST['url'] ?? '');

		if (empty($name) || empty($url)) {
			wp_send_json_error(['message' => 'Name and URL are required']);
		}

		$data = [
			'name' => $name,
			'url' => $url,
		];

		if ($id > 0) {
			$success = Webhook::update($id, $data);
			if ($success) {
				wp_send_json_success(['message' => 'Webhook updated', 'id' => $id]);
			}
		} else {
			$new_id = Webhook::create($data);
			if ($new_id) {
				wp_send_json_success(['message' => 'Webhook created', 'id' => $new_id]);
			}
		}

		wp_send_json_error(['message' => 'Failed to save webhook']);
	}

	/**
	 * AJAX: Delete webhook
	 */
	public function ajax_delete_webhook(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = intval($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}

		$success = Webhook::delete($id);
		if ($success) {
			wp_send_json_success(['message' => 'Webhook deleted']);
		}

		wp_send_json_error(['message' => 'Failed to delete webhook']);
	}

	/**
	 * AJAX: Create postwork
	 */
	public function ajax_create_postwork(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		if (empty($title)) {
			wp_send_json_error(['message' => 'Title is required']);
		}

		$postwork_id = PostWork::create(['title' => $title]);
		if (!$postwork_id) {
			wp_send_json_error(['message' => 'Failed to create post work']);
		}

		wp_send_json_success(['id' => $postwork_id]);
	}

	/**
	 * AJAX: Update postwork
	 */
	public function ajax_update_postwork(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)$_POST['id'];
		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		$webhook_id = (int)$_POST['webhook_id'];
		$article_type = sanitize_text_field($_POST['article_type'] ?? 'blog_post');
		$language = sanitize_text_field($_POST['language'] ?? 'en');
		$target_country = sanitize_text_field($_POST['target_country'] ?? 'international');
		$post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
		$post_status = sanitize_text_field($_POST['post_status'] ?? 'pending');
		$default_author_id = (int)$_POST['default_author_id'];
		$content_fields = wp_unslash($_POST['content_fields'] ?? '{}');

		if (empty($title)) {
			wp_send_json_error(['message' => 'Title is required']);
		}

		$success = PostWork::update($postwork_id, [
			'title' => $title,
			'webhook_id' => $webhook_id ?: null,
			'article_type' => $article_type ?: 'blog_post',
			'language' => $language ?: 'en',
			'target_country' => $target_country ?: 'international',
			'post_type' => $post_type,
			'post_status' => $post_status,
			'default_author_id' => $default_author_id ?: get_current_user_id(),
			'content_fields' => $content_fields
		]);

		if (!$success) {
			wp_send_json_error(['message' => 'Failed to update post work']);
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Delete postwork
	 */
	public function ajax_delete_postwork(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)$_POST['id'];

		// Delete associated blocks first
		PostBlock::delete_by_postwork($postwork_id);

		$success = PostWork::delete($postwork_id);
		if (!$success) {
			wp_send_json_error(['message' => 'Failed to delete post work']);
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Run single block
	 */
	public function ajax_run_postwork(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)($_POST['id'] ?? 0);
		$block_id = (int)($_POST['block_id'] ?? 0);
		$webhook_id = (int)($_POST['webhook_id'] ?? 0);

		$result = \PostStation\Services\BlockRunner::dispatch_block($postwork_id, $block_id, $webhook_id);
		if (!$result['success']) {
			wp_send_json_error(['message' => $result['message'] ?? 'Failed to run block']);
		}

		$runner = new \PostStation\Services\BackgroundRunner();
		$runner->schedule_status_check($postwork_id, $block_id, $webhook_id, 0);

		wp_send_json_success([
			'message' => 'Block sent to webhook for processing',
			'block_id' => $block_id,
		]);
	}

	/**
	 * AJAX: Stop postwork run
	 */
	public function ajax_stop_postwork_run(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)($_POST['id'] ?? 0);
		$runner = new \PostStation\Services\BackgroundRunner();
		$runner->cancel_run($postwork_id);

		wp_send_json_success();
	}

	/**
	 * AJAX: Export postwork
	 */
	public function ajax_export_postwork(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)$_POST['id'];
		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_send_json_error(['message' => 'PostWork not found']);
		}

		$blocks = PostBlock::get_by_postwork($postwork_id);
		
		// Clean up data for export
		unset($postwork['id'], $postwork['created_at'], $postwork['updated_at']);
		foreach ($blocks as &$block) {
			unset($block['id'], $block['postwork_id'], $block['post_id'], $block['created_at'], $block['updated_at']);
		}

		wp_send_json_success([
			'postwork' => $postwork,
			'blocks' => $blocks
		]);
	}

	/**
	 * AJAX: Import postwork
	 */
	public function ajax_import_postwork(): void
	{
		if (!$this->verify_nonce()) {
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

		if (!$data || !isset($data['postwork'])) {
			wp_send_json_error(['message' => 'Invalid file format']);
		}

		$postwork_id = PostWork::create($data['postwork']);
		if (!$postwork_id) {
			wp_send_json_error(['message' => 'Failed to create postwork']);
		}

		if (!empty($data['blocks'])) {
			foreach ($data['blocks'] as $block_data) {
				$block_data['postwork_id'] = $postwork_id;
				$block_data['status'] = 'pending';
				PostBlock::create($block_data);
			}
		}

		wp_send_json_success(['id' => $postwork_id]);
	}

	/**
	 * AJAX: Create postblock
	 */
	public function ajax_create_postblock(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)$_POST['postwork_id'];
		$postwork = PostWork::get_by_id($postwork_id);
		$article_type = $postwork['article_type'] ?? 'blog_post';
		$block_id = PostBlock::create([
			'postwork_id' => $postwork_id,
			'article_type' => $article_type,
			'status' => 'pending'
		]);

		if (!$block_id) {
			wp_send_json_error(['message' => 'Failed to create block']);
		}

		wp_send_json_success(['id' => $block_id, 'block' => PostBlock::get_by_id($block_id)]);
	}

	/**
	 * AJAX: Update multiple blocks
	 */
	public function ajax_update_blocks(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$blocks = json_decode(stripslashes($_POST['blocks']), true);
		if (!is_array($blocks)) {
			wp_send_json_error(['message' => 'Invalid blocks data']);
		}

		foreach ($blocks as $block) {
			$id = (int)$block['id'];
			unset($block['id']);
			
			PostBlock::update($id, $block);
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Delete postblock
	 */
	public function ajax_delete_postblock(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int)$_POST['id'];
		if (PostBlock::delete($id)) {
			wp_send_json_success();
		}

		wp_send_json_error(['message' => 'Failed to delete block']);
	}

	/**
	 * AJAX: Clear completed blocks
	 */
	public function ajax_clear_completed_blocks(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)$_POST['postwork_id'];
		global $wpdb;
		$table_name = $wpdb->prefix . PostBlock::get_table_name();
		$wpdb->delete($table_name, ['postwork_id' => $postwork_id, 'status' => 'completed']);

		wp_send_json_success();
	}

	/**
	 * AJAX: Import blocks
	 */
	public function ajax_import_blocks(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$postwork_id = (int)$_POST['postwork_id'];
		$file = $_FILES['file'] ?? null;
		if (!$file || !file_exists($file['tmp_name'])) {
			wp_send_json_error(['message' => 'No file uploaded']);
		}

		$content = file_get_contents($file['tmp_name']);
		$blocks = json_decode($content, true);

		if (!is_array($blocks)) {
			wp_send_json_error(['message' => 'Invalid file format']);
		}

		foreach ($blocks as $block_data) {
			$block_data['postwork_id'] = $postwork_id;
			$block_data['status'] = 'pending';
			PostBlock::create($block_data);
		}

		wp_send_json_success();
	}
}
