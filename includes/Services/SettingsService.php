<?php

namespace PostStation\Services;

class SettingsService
{
	private OpenRouterService $openrouter_service;

	public function __construct(?OpenRouterService $openrouter_service = null)
	{
		$this->openrouter_service = $openrouter_service ?? new OpenRouterService();
	}

	public function get_settings_data(): ?array
	{
		if (!current_user_can('manage_options')) {
			return null;
		}

		return [
			'api_key' => get_option('poststation_api_key', ''),
			'openrouter_api_key_set' => $this->openrouter_service->resolve_api_key() !== '',
			'openrouter_default_text_model' => get_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, ''),
			'openrouter_default_image_model' => get_option(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, ''),
		];
	}

	public function save_api_key(string $api_key): void
	{
		update_option('poststation_api_key', sanitize_text_field($api_key));
	}

	/** Generate a new API key, save it, and return the new key. */
	public function regenerate_api_key(): string
	{
		$new_key = wp_generate_password(32, false);
		$this->save_api_key($new_key);
		return $new_key;
	}

	public function save_openrouter_api_key(string $api_key): bool
	{
		return $this->openrouter_service->save_api_key(sanitize_text_field($api_key));
	}

	public function save_openrouter_defaults(string $default_text_model, string $default_image_model): void
	{
		update_option(OpenRouterService::DEFAULT_TEXT_MODEL_OPTION, sanitize_text_field($default_text_model));
		update_option(OpenRouterService::DEFAULT_IMAGE_MODEL_OPTION, sanitize_text_field($default_image_model));
	}

	public function get_openrouter_service(): OpenRouterService
	{
		return $this->openrouter_service;
	}
}
