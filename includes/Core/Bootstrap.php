<?php

namespace PostStation\Core;

use PostStation\Admin\Menu;
use PostStation\Admin\Settings;
use PostStation\Admin\WebhookManager;
use PostStation\Admin\PostWorkManager;
use PostStation\Api\RestApi;
use PostStation\Models\Webhook;
use PostStation\Models\PostWork;
use PostStation\Models\PostBlock;

class Bootstrap
{
	public function __construct()
	{
		$this->init_hooks();
		register_activation_hook(POSTSTATION_FILE, [$this, 'activate']);
	}

	private function init_hooks(): void
	{
		// Initialize REST API
		add_action('rest_api_init', function () {
			new RestApi();
		});

		// Initialize Admin
		if (is_admin()) {
			$settings = new Settings();
			$webhook_manager = new WebhookManager();
			$postwork_manager = new PostWorkManager();
			new Menu($settings, $webhook_manager, $postwork_manager);
		}

		// Register assets
		add_action('admin_enqueue_scripts', [$this, 'register_assets']);
	}

	public function activate(): void
	{
		// Create or upgrade tables
		Webhook::create_table();
		PostWork::update_tables();
		PostBlock::update_tables();

		// Set initial version if not exists
		if (!get_option('poststation_postblock_db_version')) {
			update_option('poststation_postblock_db_version', '1.1');
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	public function deactivate(): void
	{
		flush_rewrite_rules();
	}

	public function uninstall(): void
	{
		// Drop tables
		Webhook::drop_table();
		PostWork::drop_table();
		PostBlock::drop_table();

		// Remove options
		delete_option('poststation_api_key');
		delete_option('poststation_postblock_db_version');
	}

	public function register_assets(): void
	{
		wp_register_style(
			'poststation-admin',
			POSTSTATION_URL . 'assets/css/admin.css',
			[],
			POSTSTATION_VERSION
		);
	}
}