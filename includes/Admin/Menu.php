<?php

namespace PostStation\Admin;

class Menu
{
	private Settings $settings;
	private WebhookManager $webhook_manager;
	private Works\PostWorkManager $postwork_manager;

	public function __construct(Settings $settings, WebhookManager $webhook_manager, Works\PostWorkManager $postwork_manager)
	{
		$this->settings = $settings;
		$this->webhook_manager = $webhook_manager;
		$this->postwork_manager = $postwork_manager;
		add_action('admin_menu', [$this, 'register_menus']);
	}

	public function register_menus(): void
	{
		// Add main menu
		add_menu_page(
			__('Post Station', 'poststation'),
			__('Post Station', 'poststation'),
			'edit_posts',
			Settings::get_menu_slug(),
			[$this->settings, 'render_settings_page'],
			'dashicons-rest-api',
			30
		);

		// Add Settings submenu
		add_submenu_page(
			Settings::get_menu_slug(),
			__('Settings', 'poststation'),
			__('Settings', 'poststation'),
			'manage_options',
			Settings::get_menu_slug(),
			[$this->settings, 'render_settings_page']
		);

		// Add Post Works submenu
		add_submenu_page(
			Settings::get_menu_slug(),
			__('Post Works', 'poststation'),
			__('Post Works', 'poststation'),
			'edit_posts',
			'poststation-postworks',
			[$this->postwork_manager, 'render_page']
		);

		// Add Webhooks submenu
		add_submenu_page(
			Settings::get_menu_slug(),
			__('Webhooks', 'poststation'),
			__('Webhooks', 'poststation'),
			'manage_options',
			Settings::get_menu_slug() . '-webhooks',
			[$this->webhook_manager, 'render_page']
		);
	}
}
