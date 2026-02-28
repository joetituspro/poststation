<?php

namespace PostStation\Utils;

class Environment
{
	/**
	 * Check if the current environment is a local installation.
	 * @since 1.0.0
	 * @return boolean
	 */
	public static function is_local()
	{
		$local_indicators = array(
			'localhost',
			'127.0.0.1',
			'::1',
			'.test',
			'.local',
			'.localhost',
		);

		$server_name = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
		$remote_addr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

		foreach ($local_indicators as $indicator) {
			if (
				($server_name !== '' && strpos($server_name, $indicator) !== false) ||
				($remote_addr !== '' && strpos($remote_addr, $indicator) !== false)
			) {
				return true;
			}
		}

		// Additional checks for common local development environments
		if (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
			return true;
		}

		if (self::is_development()) {
			return true;
		}

		return false;
	}

	public static function is_development(): bool
	{
		if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'development') {
			return true;
		}

		if (defined('WP_ENV') && strtolower((string) WP_ENV) === 'development') {
			return true;
		}

		return false;
	}
}
