<?php

/**
 * Plugin Name: Post Station
 * Plugin URI: https://digitenet.com/poststation
 * Description: A robust WordPress plugin to handle automated post creation via API
 * Version: 1.0.0
 * Author: Joe Titus
 * Author URI: https://digitenet.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: poststation
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

namespace PostStation;

// Prevent direct access
if (!defined('ABSPATH')) {
	exit('Direct access not allowed.');
}

// Define plugin constants
define('POSTSTATION_VERSION', '1.0.0');
define('POSTSTATION_FILE', __FILE__);
define('POSTSTATION_PATH', plugin_dir_path(__FILE__));
define('POSTSTATION_URL', plugin_dir_url(__FILE__));

// Autoloader
if (file_exists(POSTSTATION_PATH . 'vendor/autoload.php')) {
	require_once POSTSTATION_PATH . 'vendor/autoload.php';
}

/**
 * Main plugin class
 */
final class PostStation
{
	/**
	 * @var PostStation|null
	 */
	private static ?PostStation $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance(): PostStation
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct()
	{
		$this->init_plugin();
	}

	/**
	 * Initialize plugin
	 */
	public function init_plugin(): void
	{
		// Load text domain
		load_plugin_textdomain('poststation', false, dirname(plugin_basename(__FILE__)) . '/languages');

		// Initialize core
		new Core\Bootstrap();
	}
}

/**
 * Initialize the plugin
 */
PostStation::get_instance();

// Register activation hook
register_activation_hook(__FILE__, function () {
	// Generate API key if not exists
	if (!get_option('poststation_api_key')) {
		update_option('poststation_api_key', wp_generate_password(32, false));
	}

	// Create database tables
	$bootstrap = new Core\Bootstrap();
	$bootstrap->activate();
});