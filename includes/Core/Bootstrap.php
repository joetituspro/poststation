<?php

namespace PostStation\Core;

use Exception;

use PostStation\Models\Webhook;
use PostStation\Models\Campaign;
use PostStation\Models\CampaignRss;
use PostStation\Models\PostTask;
use PostStation\Models\WritingPreset;
use PostStation\Models\RssHistory;
use PostStation\Admin\App;
use PostStation\Services\Sitemap;
use PostStation\Services\BackgroundRunner;
use PostStation\Services\AuthService;
use PostStation\Services\GlobalUpdateService;
use PostStation\Services\PluginUpdateService;
use PostStation\Services\SettingsService;
use PostStation\Services\SupportService;

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

		// Initialize Background Runner (live campaign orchestration)
		$background_runner = new BackgroundRunner();
		$background_runner->init();

		// Initialize global update service (single background loop for live + RSS)
		$global_update = new GlobalUpdateService();
		$global_update->init();

		AuthService::instance();

		$plugin_update_service = new PluginUpdateService();
		$plugin_update_service->init();

		// Initialize Admin
		if (is_admin()) {
			$this->check_db_version();
			add_action('admin_init', [$this, 'maybe_redirect_to_support'], 20);
			
			// Initialize React App (new SPA interface)
			new App();
		}

		// Register assets
		add_action('admin_enqueue_scripts', [$this, 'register_assets']);
	}

	private function check_db_version(): void
	{
		$installed_version = get_option('poststation_posttask_db_version', '0.0.0');
		if (version_compare($installed_version, PostTask::DB_VERSION, '<')) {
			// Defer to init so $wp_rewrite is available when registering endpoints
			add_action('init', [$this, 'activate'], 1);
		}
	}

	public function activate(): void
	{
		try {
			$settings_service = new SettingsService();
			if ($settings_service->get_api_key() === '') {
				$settings_service->save_api_key(wp_generate_password(32, false));
			}

			$support_service = new SupportService();
			$has_seen_onboarding = (int) get_option(SupportService::ONBOARDING_SEEN_AT_OPTION, 0) > 0;
			$is_onboarding_configured = get_option(SupportService::ONBOARDING_REQUIRED_OPTION, null) !== null;
			if (!$has_seen_onboarding && !$is_onboarding_configured) {
				$support_service->mark_onboarding_required();
			}

			// Create or upgrade tables with error checking
			if (!Webhook::create_table()) {
				// throw new Exception('Failed to create Webhook table');
			}

			if (!Campaign::update_tables()) {
				// throw new Exception('Failed to create/update Campaign tables');
			}

			if (!PostTask::update_tables()) {
				// throw new Exception('Failed to create/update PostTask tables');
			}

			if (!WritingPreset::update_tables()) {
				// throw new Exception('Failed to create/update WritingPreset table');
			}
			WritingPreset::seed_defaults();

			if (!CampaignRss::update_tables()) {
				// continue
			}
			if (!RssHistory::update_tables()) {
				// continue
			}

			// Ensure global update schedule exists and clean up legacy schedules.
			if (class_exists(GlobalUpdateService::class)) {
				$global_update = new GlobalUpdateService();
				$global_update->ensure_schedule_and_cleanup();
			}

			// Update version
			update_option('poststation_posttask_db_version', PostTask::DB_VERSION);

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
		Campaign::drop_table();
		CampaignRss::drop_table();
		RssHistory::drop_table();
		PostTask::drop_table();
		WritingPreset::drop_table();

		// Remove options
		delete_option('poststation_api_key');
		delete_option(\PostStation\Services\SettingsService::OPTIONS_KEY);
		delete_option('poststation_posttask_db_version');
		delete_option(\PostStation\Services\AuthService::LICENSE_KEY_OPTION);
		delete_option(\PostStation\Services\AuthService::SITE_KEY_OPTION);
		delete_option(\PostStation\Services\AuthService::LICENSE_STATUS_OPTION);
		delete_option(\PostStation\Services\SupportService::ONBOARDING_REQUIRED_OPTION);
		delete_option(\PostStation\Services\SupportService::ONBOARDING_SEEN_AT_OPTION);
		delete_option(\PostStation\Services\SupportService::ONBOARDING_REDIRECT_OPTION);
		delete_option(\PostStation\Services\SupportService::N8N_BASE_URL_OPTION);
		delete_option(\PostStation\Services\SupportService::N8N_WORKFLOW_ID_OPTION);
		delete_option(\PostStation\Services\SupportService::N8N_API_KEY_OPTION_ENC);
		delete_option(\PostStation\Services\SupportService::RAPIDAPI_KEY_OPTION_ENC);
		delete_option(\PostStation\Services\SupportService::FIRECRAWL_KEY_OPTION_ENC);
		delete_option(\PostStation\Services\SupportService::N8N_AUTODEPLOY_ENABLED_OPTION);
		delete_option(\PostStation\Services\SupportService::BLUEPRINT_UPDATE_STATE_OPTION);
		delete_option(\PostStation\Services\SupportService::PLUGIN_AUTO_UPDATE_ENABLED_OPTION);
		delete_option(\PostStation\Services\SupportService::PLUGIN_UPDATE_LAST_CHECK_OPTION);
		delete_option(\PostStation\Services\SupportService::BLUEPRINT_LAST_CHECK_OPTION);
		delete_transient(\PostStation\Services\AuthService::TRANSIENT_AUTH_CHECK);
		delete_transient(\PostStation\Services\AuthService::TRANSIENT_LAST_LICENSE_KEY);
		delete_transient(\PostStation\Services\AuthService::TRANSIENT_UPGRADE_CACHE);
	}

	public function register_assets(): void
	{
	}

	public function maybe_redirect_to_support(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		if (wp_doing_ajax()) {
			return;
		}
		if (!is_admin()) {
			return;
		}

		if (defined('IFRAME_REQUEST') && IFRAME_REQUEST) {
			return;
		}

		$support_service = new SupportService();
		if (!$support_service->should_redirect_to_support()) {
			return;
		}

		$support_service->clear_support_redirect_flag();
		wp_safe_redirect(admin_url('admin.php?page=' . POSTSTATION_APP_ID . '#/support?onboarding=1'));
		exit;
	}
}
