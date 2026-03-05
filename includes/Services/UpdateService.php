<?php

namespace PostStation\Services;

class UpdateService
{
	public const PLUGIN_UPDATE_CACHE_TRANSIENT = 'poststation_rankima_plugin_update_cache';
	private const CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;

	private AuthService $auth_service;
	private RankimaClient $rankima_client;

	public function __construct(?AuthService $auth_service = null, ?RankimaClient $rankima_client = null)
	{
		$this->auth_service = $auth_service ?? AuthService::instance();
		$this->rankima_client = $rankima_client ?? new RankimaClient();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_plugin_update_info(bool $force = false)
	{
		if (!$force) {
			$cached = get_transient(self::PLUGIN_UPDATE_CACHE_TRANSIENT);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$license_key = $this->auth_service->get_license_key();
		if ($license_key === '') {
			$empty = $this->build_empty_result();
			set_transient(self::PLUGIN_UPDATE_CACHE_TRANSIENT, $empty, self::CHECK_INTERVAL);
			return $empty;
		}

		$response = $this->rankima_client->post('/api/license/check-update', [
			'products' => ['poststation-plugin'],
		]);
		if (is_wp_error($response)) {
			return $response;
		}
		if (!is_array($response)) {
			return new \WP_Error('poststation_update_check_invalid_response', 'Invalid update response from Rankima.');
		}

		$data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
		$downloads = isset($data['downloads']) && is_array($data['downloads']) ? $data['downloads'] : [];
		$plugin_download = isset($downloads['poststation-plugin']) && is_array($downloads['poststation-plugin'])
			? $downloads['poststation-plugin']
			: [];

		$plugin_state = [
			'new_version' => sanitize_text_field((string) ($plugin_download['version'] ?? '')),
			'package' => esc_url_raw((string) ($plugin_download['download_url'] ?? '')),
			'url' => (string) apply_filters('poststation_rankima_plugin_homepage', 'https://rankima.com/poststation'),
			'tested' => '',
			'requires_php' => '7.4',
			'requires' => '5.8',
			'sections' => [
				'description' => sanitize_text_field((string) ($plugin_download['name'] ?? 'Post Station by Rankima')),
				'changelog' => wp_kses_post((string) ($plugin_download['changelog'] ?? '')),
			],
			'banners' => [],
			'release_date' => '',
			'file_name' => '',
		];

		set_transient(self::PLUGIN_UPDATE_CACHE_TRANSIENT, $plugin_state, self::CHECK_INTERVAL);
		update_option(SupportService::PLUGIN_UPDATE_LAST_CHECK_OPTION, current_time('timestamp'));
		return $plugin_state;
	}

	/**
	 * Backward-compatible alias.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_for_updates(bool $force = false)
	{
		return $this->get_plugin_update_info($force);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_empty_result(): array
	{
		return [
			'new_version' => '',
			'package' => '',
			'url' => (string) apply_filters('poststation_rankima_plugin_homepage', 'https://rankima.com/poststation'),
			'tested' => '',
			'requires_php' => '7.4',
			'requires' => '5.8',
			'sections' => [
				'description' => 'Post Station by Rankima',
				'changelog' => '',
			],
			'banners' => [],
			'release_date' => '',
			'file_name' => '',
		];
	}
}
