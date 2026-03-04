<?php

namespace PostStation\Services;

class PluginUpdateService
{
	private const UPDATE_CACHE_TRANSIENT = UpdateService::PLUGIN_UPDATE_CACHE_TRANSIENT;

	private SupportService $support_service;
	private UpdateService $update_service;

	public function __construct(
		?SupportService $support_service = null,
		?UpdateService $update_service = null
	) {
		$this->support_service = $support_service ?? new SupportService();
		$this->update_service = $update_service ?? new UpdateService();
	}

	public function init(): void
	{
		add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_plugin_update']);
		add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
		add_filter('auto_update_plugin', [$this, 'maybe_auto_update_plugin'], 10, 2);
		add_action('upgrader_process_complete', [$this, 'handle_upgrade_complete'], 10, 2);
	}

	/**
	 * @param object $transient
	 * @return object
	 */
	public function inject_plugin_update($transient)
	{
		if (!is_object($transient)) {
			return $transient;
		}

		$info = $this->get_update_info(false);
		if (is_wp_error($info) || empty($info['new_version']) || empty($info['package'])) {
			return $transient;
		}

		if (version_compare((string) $info['new_version'], POSTSTATION_VERSION, '<=')) {
			return $transient;
		}

		$plugin_file = plugin_basename(POSTSTATION_FILE);
		$item = (object) [
			'slug' => POSTSTATION_SLUG,
			'plugin' => $plugin_file,
			'new_version' => (string) $info['new_version'],
			'url' => (string) ($info['url'] ?? 'https://rankima.com/poststation'),
			'package' => (string) $info['package'],
			'tested' => (string) ($info['tested'] ?? ''),
			'requires_php' => (string) ($info['requires_php'] ?? ''),
			'requires' => (string) ($info['requires'] ?? ''),
		];

		if (!isset($transient->response) || !is_array($transient->response)) {
			$transient->response = [];
		}
		$transient->response[$plugin_file] = $item;
		return $transient;
	}

	/**
	 * @param mixed $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugins_api($result, $action, $args)
	{
		if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== POSTSTATION_SLUG) {
			return $result;
		}

		$info = $this->get_update_info(false);
		if (is_wp_error($info)) {
			return $result;
		}

		$obj = (object) [
			'name' => POSTSTATION_NAME,
			'slug' => POSTSTATION_SLUG,
			'version' => (string) ($info['new_version'] ?? POSTSTATION_VERSION),
			'author' => '<a href="https://rankima.com">Rankima</a>',
			'homepage' => (string) ($info['url'] ?? 'https://rankima.com/poststation'),
			'requires' => (string) ($info['requires'] ?? '5.8'),
			'requires_php' => (string) ($info['requires_php'] ?? '7.4'),
			'tested' => (string) ($info['tested'] ?? ''),
			'sections' => (object) (is_array($info['sections'] ?? null) ? $info['sections'] : []),
			'banners' => (object) (is_array($info['banners'] ?? null) ? $info['banners'] : []),
			'download_link' => (string) ($info['package'] ?? ''),
		];

		return $obj;
	}

	/**
	 * @param bool $update
	 * @param object $item
	 */
	public function maybe_auto_update_plugin($update, $item): bool
	{
		if (!is_object($item)) {
			return (bool) $update;
		}

		$plugin = (string) ($item->plugin ?? '');
		if ($plugin !== plugin_basename(POSTSTATION_FILE)) {
			return (bool) $update;
		}

		return $this->support_service->is_plugin_auto_update_enabled();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function handle_upgrade_complete($upgrader, $options): void
	{
		if (!is_array($options)) {
			return;
		}
		if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
			return;
		}

		$plugins = $options['plugins'] ?? [];
		if (!is_array($plugins)) {
			return;
		}

		if (!in_array(plugin_basename(POSTSTATION_FILE), $plugins, true)) {
			return;
		}

		delete_transient(self::UPDATE_CACHE_TRANSIENT);
		update_option(SupportService::PLUGIN_UPDATE_LAST_CHECK_OPTION, current_time('timestamp'));
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_update_info(bool $force = false)
	{
		return $this->update_service->get_plugin_update_info($force);
	}
}
