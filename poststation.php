<?php

/**
 * Plugin Name: Post Station
 * Plugin URI: https://digitenet.com/poststation
 * Description: A robust WordPress plugin to handle automated post creation via API
 * Version: 0.0.1
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
define('POSTSTATION_VERSION', '0.0.1');
define('POSTSTATION_FILE', __FILE__);
define('POSTSTATION_PATH', plugin_dir_path(__FILE__));
define('POSTSTATION_URL', plugin_dir_url(__FILE__));
// Autoloader
if (file_exists(POSTSTATION_PATH . 'vendor/autoload.php')) {
	require_once POSTSTATION_PATH . 'vendor/autoload.php';
} else {
	// Fallback autoloader if composer autoload is not available
	spl_autoload_register(function ($class) {
		// Project-specific namespace prefix
		$prefix = 'PostStation\\';

		// Base directory for the namespace prefix
		$base_dir = POSTSTATION_PATH . 'includes/';

		// Check if the class uses the namespace prefix
		$len = strlen($prefix);
		if (strncmp($prefix, $class, $len) !== 0) {
			return;
		}

		// Get the relative class name
		$relative_class = substr($class, $len);

		// Replace namespace separators with directory separators
		$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

		// If the file exists, require it
		if (file_exists($file)) {
			require $file;
		}
	});
}

// Load Action Scheduler if not already available
if (!function_exists('as_enqueue_async_action')) {
	$action_scheduler_paths = [
		POSTSTATION_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php',
		POSTSTATION_PATH . 'vendor/action-scheduler/action-scheduler.php',
		POSTSTATION_PATH . 'lib/action-scheduler/action-scheduler.php',
	];
	foreach ($action_scheduler_paths as $path) {
		if (file_exists($path)) {
			require_once $path;
			break;
		}
	}
}

if (is_admin()) {
	add_action('admin_notices', function () {
		// Check at display time; Action Scheduler defines as_enqueue_async_action on plugins_loaded
		if (!function_exists('as_enqueue_async_action')) {
			echo '<div class="notice notice-error"><p>PostStation requires Action Scheduler. Please run composer install or bundle Action Scheduler.</p></div>';
		}
	});
}

// Add error logging
if (!defined('WP_DEBUG_LOG')) {
	define('WP_DEBUG_LOG', true);
}

// Add after plugin header, before namespace
if (version_compare(PHP_VERSION, '7.4', '<')) {
	if (!function_exists('deactivate_plugins')) {
		require_once(ABSPATH . 'wp-admin/includes/plugin.php');
	}
	deactivate_plugins(plugin_basename(__FILE__));
	wp_die(
		sprintf(
			'PostStation requires PHP version 7.4 or higher. Your current PHP version is %s.',
			PHP_VERSION
		)
	);
}

// Add after PHP version check
$required_extensions = ['json', 'mysqli', 'curl'];
$missing_extensions = array_filter($required_extensions, function ($ext) {
	return !extension_loaded($ext);
});

if (!empty($missing_extensions)) {
	if (!function_exists('deactivate_plugins')) {
		require_once(ABSPATH . 'wp-admin/includes/plugin.php');
	}
	deactivate_plugins(plugin_basename(__FILE__));
	wp_die(
		sprintf(
			'PostStation requires the following PHP extensions: %s. Please contact your hosting provider.',
			implode(', ', $missing_extensions)
		)
	);
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

// Activation and Deactivation hooks
register_activation_hook(__FILE__, function () {
	try {
		$bootstrap = new Core\Bootstrap();
		$bootstrap->activate();
	} catch (Exception $e) {
		error_log('PostStation Activation Error: ' . $e->getMessage());
		wp_die('PostStation Activation Error: ' . $e->getMessage());
	}
});

register_deactivation_hook(__FILE__, function () {
	$bootstrap = new Core\Bootstrap();
	$bootstrap->deactivate();
});