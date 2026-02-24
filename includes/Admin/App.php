<?php

namespace PostStation\Admin;

use PostStation\Admin\Ajax\CampaignAjaxHandler;
use PostStation\Admin\Ajax\InstructionAjaxHandler;
use PostStation\Admin\Ajax\PostTaskAjaxHandler;
use PostStation\Admin\Ajax\RssAjaxHandler;
use PostStation\Admin\Ajax\SettingsAjaxHandler;
use PostStation\Admin\Ajax\WebhookAjaxHandler;

class App
{
	private BootstrapDataProvider $bootstrap_provider;
	private CampaignAjaxHandler $campaign_handler;
	private PostTaskAjaxHandler $posttask_handler;
	private WebhookAjaxHandler $webhook_handler;
	private SettingsAjaxHandler $settings_handler;
	private InstructionAjaxHandler $instruction_handler;
	private RssAjaxHandler $rss_handler;

	public function __construct()
	{
		$this->bootstrap_provider = new BootstrapDataProvider();
		$this->campaign_handler = new CampaignAjaxHandler($this->bootstrap_provider);
		$this->posttask_handler = new PostTaskAjaxHandler();
		$this->webhook_handler = new WebhookAjaxHandler();
		$this->settings_handler = new SettingsAjaxHandler();
		$this->instruction_handler = new InstructionAjaxHandler();
		$this->rss_handler = new RssAjaxHandler();

		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_head', [$this, 'hide_wp_notices']);

		add_action('wp_ajax_poststation_get_campaigns', [$this->campaign_handler, 'get_campaigns']);
		add_action('wp_ajax_poststation_get_campaign', [$this->campaign_handler, 'get_campaign']);
		add_action('wp_ajax_poststation_create_campaign', [$this->campaign_handler, 'create_campaign']);
		add_action('wp_ajax_poststation_update_campaign', [$this->campaign_handler, 'update_campaign']);
		add_action('wp_ajax_poststation_delete_campaign', [$this->campaign_handler, 'delete_campaign']);
		add_action('wp_ajax_poststation_run_campaign', [$this->campaign_handler, 'run_campaign']);
		add_action('wp_ajax_poststation_stop_campaign_run', [$this->campaign_handler, 'stop_campaign_run']);
		add_action('wp_ajax_poststation_export_campaign', [$this->campaign_handler, 'export_campaign']);
		add_action('wp_ajax_poststation_import_campaign', [$this->campaign_handler, 'import_campaign']);
		add_action('wp_ajax_poststation_run_rss_now', [$this->rss_handler, 'run_rss_now']);
		add_action('wp_ajax_poststation_rss_add_to_tasks', [$this->rss_handler, 'rss_add_to_tasks']);

		add_action('wp_ajax_poststation_create_posttask', [$this->posttask_handler, 'create_posttask']);
		add_action('wp_ajax_poststation_update_posttasks', [$this->posttask_handler, 'update_posttasks']);
		add_action('wp_ajax_poststation_delete_posttask', [$this->posttask_handler, 'delete_posttask']);
		add_action('wp_ajax_poststation_clear_completed_posttasks', [$this->posttask_handler, 'clear_completed_posttasks']);
		add_action('wp_ajax_poststation_import_posttasks', [$this->posttask_handler, 'import_posttasks']);

		add_action('wp_ajax_poststation_get_webhooks', [$this->webhook_handler, 'get_webhooks']);
		add_action('wp_ajax_poststation_get_webhook', [$this->webhook_handler, 'get_webhook']);
		add_action('wp_ajax_poststation_save_webhook', [$this->webhook_handler, 'save_webhook']);
		add_action('wp_ajax_poststation_delete_webhook', [$this->webhook_handler, 'delete_webhook']);

		add_action('wp_ajax_poststation_get_settings', [$this->settings_handler, 'get_settings']);
		add_action('wp_ajax_poststation_save_api_key', [$this->settings_handler, 'save_api_key']);
		add_action('wp_ajax_poststation_save_openrouter_api_key', [$this->settings_handler, 'save_openrouter_api_key']);
		add_action('wp_ajax_poststation_save_openrouter_defaults', [$this->settings_handler, 'save_openrouter_defaults']);
		add_action('wp_ajax_poststation_get_openrouter_models', [$this->settings_handler, 'get_openrouter_models']);

		add_action('wp_ajax_poststation_get_bootstrap', [$this, 'ajax_get_bootstrap']);

		add_action('wp_ajax_poststation_create_instruction', [$this->instruction_handler, 'create_instruction']);
		add_action('wp_ajax_poststation_update_instruction', [$this->instruction_handler, 'update_instruction']);
		add_action('wp_ajax_poststation_duplicate_instruction', [$this->instruction_handler, 'duplicate_instruction']);
		add_action('wp_ajax_poststation_reset_instruction', [$this->instruction_handler, 'reset_instruction']);
		add_action('wp_ajax_poststation_delete_instruction', [$this->instruction_handler, 'delete_instruction']);

		// Clear static bootstrap cache when terms or users change
		add_action('created_term', [BootstrapDataProvider::class, 'clear_static_cache']);
		add_action('edited_term', [BootstrapDataProvider::class, 'clear_static_cache']);
		add_action('delete_term', [BootstrapDataProvider::class, 'clear_static_cache']);
		add_action('profile_update', [BootstrapDataProvider::class, 'clear_static_cache']);
		add_action('user_register', [BootstrapDataProvider::class, 'clear_static_cache']);
		add_action('delete_user', [BootstrapDataProvider::class, 'clear_static_cache']);
	}

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

	public function render_app(): void
	{
		echo '<div id="poststation-app"></div>';
	}

	public function enqueue_scripts(string $hook): void
	{
		if ($hook !== 'toplevel_page_poststation-app') {
			return;
		}

		$build_path = POSTSTATION_PATH . 'build/';
		$build_url = POSTSTATION_URL . 'build/';
		if (!file_exists($build_path . 'poststation-admin.js')) {
			add_action('admin_notices', static function () {
				echo '<div class="notice notice-error"><p>PostStation React build not found. Run <code>npm run build</code> to compile.</p></div>';
			});
			return;
		}

		$asset_file = $build_path . 'poststation-admin.asset.php';
		$asset = file_exists($asset_file)
			? require $asset_file
			: [];
		$asset = is_array($asset) ? $asset : [];
		$dependencies = $asset['dependencies'] ?? ['react', 'react-dom'];
		$version = $asset['version'] ?? filemtime($build_path . 'poststation-admin.js');

		wp_enqueue_script('poststation-react-app', $build_url . 'poststation-admin.js', $dependencies, $version, true);
		if (file_exists($build_path . 'poststation-admin.css')) {
			wp_enqueue_style('poststation-react-app', $build_url . 'poststation-admin.css', [], $version);
		}
		wp_enqueue_media();

		$post_type_options = $this->bootstrap_provider->get_post_type_options();
		$taxonomy_data = $this->bootstrap_provider->get_taxonomy_data();
		$user_data = $this->bootstrap_provider->get_user_data();
		$bootstrap_data = $this->bootstrap_provider->get_bootstrap_data($post_type_options, $taxonomy_data, $user_data);

		wp_localize_script('poststation-react-app', 'poststation', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'admin_url' => admin_url(),
			'rest_url' => rest_url(),
			'nonce' => wp_create_nonce('poststation_campaign_action'),
			'react_nonce' => wp_create_nonce('poststation_react_action'),
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data,
			'languages' => $bootstrap_data['languages'],
			'countries' => $bootstrap_data['countries'],
			'users' => $user_data,
			'current_user_id' => get_current_user_id(),
			'bootstrap' => $bootstrap_data,
		]);
	}

	public function hide_wp_notices(): void
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->id !== 'toplevel_page_poststation-app') {
			return;
		}
		echo '<style>
			#wpbody-content > .notice,
			#wpbody-content > .update-nag,
			#wpbody-content > .error,
			#wpbody-content > .updated {
				display: none !important;
			}
		</style>';
	}

	public function ajax_get_bootstrap(): void
	{
		if (!\PostStation\Admin\Ajax\NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		wp_send_json_success(['bootstrap' => $this->bootstrap_provider->get_bootstrap_data()]);
	}
}
