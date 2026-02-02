<?php

namespace PostStation\Core;

use Exception;

use PostStation\Admin\ReactApp;
use PostStation\Services\Sitemap;
use PostStation\Services\BackgroundRunner;
use PostStation\Models\Webhook;
use PostStation\Models\PostWork;
use PostStation\Models\PostBlock;

class Bootstrap
{
	public function __construct()
	{
		$this->init_hooks();
	}

	private function init_hooks(): void
	{
		// Initialize Custom API
		$api_handler = new \PostStation\Api\ApiHandler();
		$api_handler->init();

		// Initialize Sitemap Service
		$sitemap_service = new Sitemap();
		$sitemap_service->init();

		// Initialize Background Runner
		$background_runner = new BackgroundRunner();
		$background_runner->init();

		// Initialize Admin
		if (is_admin()) {
			$this->check_db_version();
			
			// Initialize React App (new SPA interface)
			new ReactApp();
		}

		// Register assets
		add_action('admin_enqueue_scripts', [$this, 'register_assets']);
	}

	private function check_db_version(): void
	{
		$installed_version = get_option('poststation_postblock_db_version', '0.0.0');
		if (version_compare($installed_version, PostBlock::DB_VERSION, '<')) {
			// Defer to init so $wp_rewrite is available when registering endpoints
			add_action('init', [$this, 'activate'], 1);
		}
	}

	public function activate(): void
	{
		try {
			// Generate API key if not exists
			if (!get_option('poststation_api_key')) {
				update_option('poststation_api_key', wp_generate_password(32, false));
			}

			// Create or upgrade tables with error checking
			if (!Webhook::create_table()) {
				// throw new Exception('Failed to create Webhook table');
			}

			if (!PostWork::update_tables()) {
				// throw new Exception('Failed to create/update PostWork tables');
			}

			if (!PostBlock::update_tables()) {
				// throw new Exception('Failed to create/update PostBlock tables');
			}

			// Update version
			update_option('poststation_postblock_db_version', PostBlock::DB_VERSION);

			// Register rewrite rules before flushing
			$api_handler = new \PostStation\Api\ApiHandler();
			$api_handler->register_endpoints();

			// Flush rewrite rules
			flush_rewrite_rules();
		} catch (Exception $e) {
			error_log('PostStation Bootstrap Error: ' . $e->getMessage());
			throw $e; // Re-throw to be caught by activation hook
		}
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
			filemtime(POSTSTATION_PATH . 'assets/css/admin.css')
		);
	}
}