<?php

namespace PostStation\Services;

class SupportService
{
	public const ONBOARDING_REQUIRED_OPTION = 'poststation_support_onboarding_required';
	public const ONBOARDING_SEEN_AT_OPTION = 'poststation_support_onboarding_seen_at';
	public const ONBOARDING_REDIRECT_OPTION = 'poststation_support_pending_redirect';
	public const PLUGIN_AUTO_UPDATE_ENABLED_OPTION = 'poststation_plugin_auto_update_enabled';
	public const PLUGIN_UPDATE_LAST_CHECK_OPTION = 'poststation_rankima_last_plugin_update_check';

	private AuthService $auth_service;

	public function __construct(?AuthService $auth_service = null)
	{
		$this->auth_service = $auth_service ?? AuthService::instance();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_support_state(): array
	{
		$license_status = $this->auth_service->get_license_status();

		return [
			'onboarding_required' => $this->is_onboarding_required(),
			'onboarding_seen_at' => (int) get_option(self::ONBOARDING_SEEN_AT_OPTION, 0),
			'license' => [
				'key_set' => $this->auth_service->get_license_key() !== '',
				'truncated_key' => $this->auth_service->get_truncated_license_key(),
				'site_key_set' => $this->auth_service->get_site_key() !== '',
				'cached_status' => $this->auth_service->get_status(),
				'status' => $license_status,
			],
			'updates' => [
				'plugin_current_version' => POSTSTATION_VERSION,
				'plugin_latest' => $this->get_plugin_update_snapshot(),
				'plugin_auto_update_enabled' => $this->is_plugin_auto_update_enabled(),
				'last_plugin_update_check_at' => (int) get_option(self::PLUGIN_UPDATE_LAST_CHECK_OPTION, 0),
			],
		];
	}

	public function is_onboarding_required(): bool
	{
		return get_option(self::ONBOARDING_REQUIRED_OPTION, '0') === '1';
	}

	public function mark_onboarding_required(): void
	{
		update_option(self::ONBOARDING_REQUIRED_OPTION, '1');
		update_option(self::ONBOARDING_REDIRECT_OPTION, '1');
	}

	public function complete_onboarding(): void
	{
		update_option(self::ONBOARDING_REQUIRED_OPTION, '0');
		update_option(self::ONBOARDING_SEEN_AT_OPTION, current_time('timestamp'));
		delete_option(self::ONBOARDING_REDIRECT_OPTION);
	}

	public function should_redirect_to_support(): bool
	{
		return get_option(self::ONBOARDING_REDIRECT_OPTION, '0') === '1';
	}

	public function clear_support_redirect_flag(): void
	{
		delete_option(self::ONBOARDING_REDIRECT_OPTION);
	}

	public function is_plugin_auto_update_enabled(): bool
	{
		return get_option(self::PLUGIN_AUTO_UPDATE_ENABLED_OPTION, '0') === '1';
	}

	public function set_plugin_auto_update_enabled(bool $enabled): void
	{
		update_option(self::PLUGIN_AUTO_UPDATE_ENABLED_OPTION, $enabled ? '1' : '0');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_plugin_update_snapshot(): array
	{
		$cached = get_transient(UpdateService::PLUGIN_UPDATE_CACHE_TRANSIENT);
		if (!is_array($cached)) {
			return [];
		}

		return [
			'new_version' => sanitize_text_field((string) ($cached['new_version'] ?? '')),
			'url' => esc_url_raw((string) ($cached['url'] ?? '')),
		];
	}
}
