<?php

namespace PostStation\Services;

class BlueprintUpdateService
{
	private const CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;
	private const CACHE_TRANSIENT = 'poststation_rankima_blueprint_meta_cache';

	private SupportService $support_service;
	private AuthService $auth_service;
	private RankimaClient $rankima_client;

	public function __construct(
		?SupportService $support_service = null,
		?AuthService $auth_service = null,
		?RankimaClient $rankima_client = null
	) {
		$this->support_service = $support_service ?? new SupportService();
		$this->auth_service = $auth_service ?? AuthService::instance();
		$this->rankima_client = $rankima_client ?? new RankimaClient();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_for_updates(bool $force = false)
	{
		if (!$force) {
			$last_check = (int) get_option(SupportService::BLUEPRINT_LAST_CHECK_OPTION, 0);
			if ($last_check > 0 && (current_time('timestamp') - $last_check) < self::CHECK_INTERVAL) {
				$state = get_option(SupportService::BLUEPRINT_UPDATE_STATE_OPTION, []);
				return is_array($state) ? $state : [];
			}
		}

		$license_key = $this->auth_service->get_license_key();
		$site_key = $this->auth_service->get_site_key();
		$license_status = $this->auth_service->get_license_status();
		if ($license_key === '' || empty($license_status['valid'])) {
			$empty = [
				'update_available' => false,
				'latest_version' => '',
				'latest_hash' => '',
				'checked_at' => current_time('timestamp'),
			];
			$this->support_service->set_blueprint_update_state($empty);
			return $empty;
		}

		$current_version = '';
		$current_hash = '';
		$payload = [
			'licenseKey' => $license_key,
			'siteKey' => $site_key,
		];
		$download_id = (string) apply_filters('poststation_rankima_blueprint_download_id', '');
		$download_slug = (string) apply_filters('poststation_rankima_blueprint_download_slug', 'poststation-n8n-blueprint');
		if ($download_id !== '') {
			$payload['downloadId'] = sanitize_text_field($download_id);
		} else {
			$payload['downloadSlug'] = sanitize_text_field($download_slug);
		}

		$response = $this->rankima_client->post('/api/downloads/latest', $payload);
		if (is_wp_error($response)) {
			return $response;
		}

		$latest = isset($response['latest']) && is_array($response['latest']) ? $response['latest'] : [];
		$latest_version = sanitize_text_field((string) ($latest['version'] ?? ''));
		$latest_hash = sanitize_text_field((string) ($latest['hash'] ?? ''));
		$update_available = false;
		if ($latest_version !== '' && $current_version !== '') {
			$update_available = version_compare($latest_version, $current_version, '>');
		} elseif ($latest_version !== '' && $current_version === '') {
			$update_available = true;
		}
		if (!$update_available && $latest_hash !== '' && $current_hash !== '' && $latest_hash !== $current_hash) {
			$update_available = true;
		}

		$state = [
			'update_available' => $update_available,
			'latest_version' => $latest_version,
			'latest_hash' => $latest_hash,
			'release_date' => sanitize_text_field((string) ($latest['releaseDate'] ?? '')),
			'file_name' => sanitize_text_field((string) ($latest['fileName'] ?? '')),
			'checked_at' => current_time('timestamp'),
		];
		$this->support_service->set_blueprint_update_state($state);
		set_transient(self::CACHE_TRANSIENT, $state, self::CHECK_INTERVAL);

		return $state;
	}
}
