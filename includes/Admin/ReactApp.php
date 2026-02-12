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
	private const OPENROUTER_KEY_OPTION = 'poststation_openrouter_api_key';
	private const OPENROUTER_KEY_OPTION_ENC = 'poststation_openrouter_api_key_enc';
	private const OPENROUTER_DEFAULT_TEXT_MODEL_OPTION = 'poststation_openrouter_default_text_model';
	private const OPENROUTER_DEFAULT_IMAGE_MODEL_OPTION = 'poststation_openrouter_default_image_model';

	public function __construct()
	{
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		
		// Register AJAX handlers
		add_action('wp_ajax_poststation_get_postworks', [$this, 'ajax_get_postworks']);
		add_action('wp_ajax_poststation_get_postwork', [$this, 'ajax_get_postwork']);
		add_action('wp_ajax_poststation_get_webhooks', [$this, 'ajax_get_webhooks']);
		add_action('wp_ajax_poststation_get_webhook', [$this, 'ajax_get_webhook']);
		add_action('wp_ajax_poststation_get_bootstrap', [$this, 'ajax_get_bootstrap']);
		add_action('wp_ajax_poststation_save_webhook', [$this, 'ajax_save_webhook']);
		add_action('wp_ajax_poststation_delete_webhook', [$this, 'ajax_delete_webhook']);
		add_action('wp_ajax_poststation_get_settings', [$this, 'ajax_get_settings']);
		add_action('wp_ajax_poststation_save_api_key', [$this, 'ajax_save_api_key']);
		add_action('wp_ajax_poststation_save_openrouter_api_key', [$this, 'ajax_save_openrouter_api_key']);
		add_action('wp_ajax_poststation_save_openrouter_defaults', [$this, 'ajax_save_openrouter_defaults']);
		add_action('wp_ajax_poststation_get_openrouter_models', [$this, 'ajax_get_openrouter_models']);

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

		$post_type_options = $this->get_post_type_options();
		$taxonomy_data = $this->get_taxonomy_data();
		$user_data = $this->get_user_data();
		$bootstrap_data = $this->get_bootstrap_data($post_type_options, $taxonomy_data, $user_data);

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
			'bootstrap' => $bootstrap_data,
		]);
	}

	private function get_post_type_options(): array
	{
		$post_types = get_post_types(['public' => true], 'objects');
		$post_type_options = [];
		foreach ($post_types as $post_type) {
			$post_type_options[$post_type->name] = $post_type->label;
		}

		return $post_type_options;
	}

	private function get_taxonomy_data(): array
	{
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

		return $taxonomy_data;
	}

	private function get_user_data(): array
	{
		$users = get_users(['role__in' => ['administrator', 'editor', 'author']]);
		return array_map(function($user) {
			return [
				'id' => $user->ID,
				'display_name' => $user->display_name,
			];
		}, $users);
	}

	private function get_postworks_with_counts(): array
	{
		$postworks = PostWork::get_all();

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

		return $postworks;
	}

	private function get_settings_data(): ?array
	{
		if (!current_user_can('manage_options')) {
			return null;
		}

		$openrouter_key = $this->resolve_openrouter_api_key();
		return [
			'api_key' => get_option('poststation_api_key', ''),
			'openrouter_api_key_set' => $openrouter_key !== '',
			'openrouter_default_text_model' => get_option(self::OPENROUTER_DEFAULT_TEXT_MODEL_OPTION, ''),
			'openrouter_default_image_model' => get_option(self::OPENROUTER_DEFAULT_IMAGE_MODEL_OPTION, ''),
		];
	}

	private function get_bootstrap_data(?array $post_type_options = null, ?array $taxonomy_data = null, ?array $user_data = null): array
	{
		$post_type_options = $post_type_options ?? $this->get_post_type_options();
		$taxonomy_data = $taxonomy_data ?? $this->get_taxonomy_data();
		$user_data = $user_data ?? $this->get_user_data();

		return [
			'settings' => $this->get_settings_data(),
			'webhooks' => ['webhooks' => Webhook::get_all()],
			'postworks' => ['postworks' => $this->get_postworks_with_counts()],
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data,
			'languages' => Languages::all(),
			'countries' => Countries::all(),
			'users' => $user_data,
			'current_user_id' => get_current_user_id(),
			'openrouter_models' => $this->get_openrouter_models(false, true),
		];
	}

	private function resolve_openrouter_api_key(): string
	{
		$filtered = apply_filters('poststation_openrouter_api_key', '');
		if (is_string($filtered) && $filtered !== '') {
			return trim($filtered);
		}

		if (defined('POSTSTATION_OPENROUTER_API_KEY') && is_string(POSTSTATION_OPENROUTER_API_KEY) && POSTSTATION_OPENROUTER_API_KEY !== '') {
			return trim(POSTSTATION_OPENROUTER_API_KEY);
		}

		$env_key = getenv('OPENROUTER_API_KEY');
		if (is_string($env_key) && $env_key !== '') {
			return trim($env_key);
		}

		$encrypted_option = get_option(self::OPENROUTER_KEY_OPTION_ENC, '');
		if (is_string($encrypted_option) && $encrypted_option !== '') {
			$decrypted = $this->decrypt_openrouter_api_key($encrypted_option);
			if ($decrypted !== '') {
				return $decrypted;
			}
		}

		$option_key = get_option(self::OPENROUTER_KEY_OPTION, '');
		if (is_string($option_key) && $option_key !== '') {
			return trim($option_key);
		}

		return '';
	}

	private function get_openrouter_encryption_secret(): string
	{
		return hash('sha256', wp_salt('auth') . '|poststation|openrouter', true);
	}

	private function encrypt_openrouter_api_key(string $plain_text): string
	{
		if ($plain_text === '') {
			return '';
		}

		if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
			return '';
		}

		$key = $this->get_openrouter_encryption_secret();
		$iv = openssl_random_pseudo_bytes(12);
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plain_text,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if (!is_string($ciphertext) || $ciphertext === '') {
			return '';
		}

		return base64_encode($iv . $tag . $ciphertext);
	}

	private function decrypt_openrouter_api_key(string $encoded): string
	{
		if ($encoded === '') {
			return '';
		}

		if (!function_exists('openssl_decrypt')) {
			return '';
		}

		$payload = base64_decode($encoded, true);
		if (!is_string($payload) || strlen($payload) <= 28) {
			return '';
		}

		$iv = substr($payload, 0, 12);
		$tag = substr($payload, 12, 16);
		$ciphertext = substr($payload, 28);
		$key = $this->get_openrouter_encryption_secret();
		$decrypted = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return is_string($decrypted) ? trim($decrypted) : '';
	}

	private function is_image_generation_model(array $model): bool
	{
		return in_array('image', $this->get_output_modalities($model), true);
	}

	private function get_output_modalities(array $model): array
	{
		return array_map(
			static fn($value) => strtolower((string) $value),
			(array) ($model['architecture']['output_modalities'] ?? [])
		);
	}

	private function is_audio_generation_model(array $model): bool
	{
		return in_array('audio', $this->get_output_modalities($model), true);
	}

	private function is_text_generation_model(array $model): bool
	{
		$output_modalities = $this->get_output_modalities($model);

		// Text list should be text-capable only, excluding image/audio output models.
		return in_array('text', $output_modalities, true)
			&& !in_array('image', $output_modalities, true)
			&& !in_array('audio', $output_modalities, true);
	}

	private function normalize_openrouter_models(array $models): array
	{
		$normalized = [];

		foreach ($models as $model) {
			if (!is_array($model)) {
				continue;
			}

			$id = trim((string) ($model['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$normalized_id = strtolower($id);
			if ($normalized_id === 'openrouter/auto' || str_starts_with($normalized_id, 'openrouter/auto-')) {
				continue;
			}

			
			$name = trim((string) ($model['name'] ?? $id));
			$supports_image = $this->is_image_generation_model($model);
			$supports_audio = $this->is_audio_generation_model($model);
			$supports_text = $this->is_text_generation_model($model);

			$normalized[] = [
				'id' => $id,
				'name' => $name !== '' ? $name : $id,
				'supportsImage' => $supports_image,
				'supportsAudio' => $supports_audio,
				'supportsText' => $supports_text,
			];
		}

		return $normalized;
	}

	/**
	 * @return array|\WP_Error
	 */
	private function get_openrouter_models(bool $force_refresh = false, bool $suppress_errors = false)
	{
		$cache_key = 'poststation_openrouter_models_v2';
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$api_key = $this->resolve_openrouter_api_key();
		if ($api_key === '') {
			if ($suppress_errors) {
				return [];
			}
			return new \WP_Error('missing_openrouter_key', 'OpenRouter API key is missing.');
		}

		$response = wp_remote_get('https://openrouter.ai/api/v1/models', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			if ($suppress_errors) {
				return [];
			}
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);

		if ($status < 200 || $status >= 300 || !is_array($body)) {
			if ($suppress_errors) {
				return [];
			}
			return new \WP_Error('openrouter_request_failed', 'Failed to fetch OpenRouter models.');
		}

		$models = $this->normalize_openrouter_models((array) ($body['data'] ?? []));
		set_transient($cache_key, $models, 30 * MINUTE_IN_SECONDS);

		return $models;
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

		$postworks = $this->get_postworks_with_counts();

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

		$user_data = $this->get_user_data();
		$taxonomy_data = $this->get_taxonomy_data();

		wp_send_json_success([
			'postwork' => $postwork,
			'blocks' => $blocks,
			'users' => $user_data,
			'taxonomies' => $taxonomy_data,
		]);
	}

	/**
	 * AJAX: Get localized bootstrap data
	 */
	public function ajax_get_bootstrap(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$bootstrap = $this->get_bootstrap_data();

		wp_send_json_success(['bootstrap' => $bootstrap]);
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
		$openrouter_key_set = $this->resolve_openrouter_api_key() !== '';
		$default_text_model = get_option(self::OPENROUTER_DEFAULT_TEXT_MODEL_OPTION, '');
		$default_image_model = get_option(self::OPENROUTER_DEFAULT_IMAGE_MODEL_OPTION, '');

		wp_send_json_success([
			'api_key' => $api_key,
			'openrouter_api_key_set' => $openrouter_key_set,
			'openrouter_default_text_model' => $default_text_model,
			'openrouter_default_image_model' => $default_image_model,
		]);
	}

	public function ajax_get_openrouter_models(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$force_refresh = !empty($_POST['force_refresh']) && $_POST['force_refresh'] !== 'false';
		$models = $this->get_openrouter_models($force_refresh);
		if (is_wp_error($models)) {
			wp_send_json_error(['message' => $models->get_error_message()]);
		}

		wp_send_json_success([
			'models' => $models,
			'updated_at' => current_time('timestamp'),
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

	public function ajax_save_openrouter_api_key(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$api_key = sanitize_text_field($_POST['api_key'] ?? '');
		if ($api_key === '') {
			delete_option(self::OPENROUTER_KEY_OPTION_ENC);
			delete_option(self::OPENROUTER_KEY_OPTION);
			wp_send_json_success(['message' => 'OpenRouter API key cleared']);
		}

		$encrypted = $this->encrypt_openrouter_api_key($api_key);
		if ($encrypted === '') {
			wp_send_json_error(['message' => 'Unable to securely store OpenRouter API key on this server']);
		}

		update_option(self::OPENROUTER_KEY_OPTION_ENC, $encrypted);
		delete_option(self::OPENROUTER_KEY_OPTION);

		wp_send_json_success(['message' => 'OpenRouter API key saved']);
	}

	public function ajax_save_openrouter_defaults(): void
	{
		if (!$this->verify_nonce()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$default_text_model = sanitize_text_field($_POST['default_text_model'] ?? '');
		$default_image_model = sanitize_text_field($_POST['default_image_model'] ?? '');

		update_option(self::OPENROUTER_DEFAULT_TEXT_MODEL_OPTION, $default_text_model);
		update_option(self::OPENROUTER_DEFAULT_IMAGE_MODEL_OPTION, $default_image_model);

		wp_send_json_success(['message' => 'OpenRouter default models saved']);
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
