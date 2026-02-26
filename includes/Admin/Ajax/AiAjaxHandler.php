<?php

namespace PostStation\Admin\Ajax;

use PostStation\Services\Ai\AiService;

class AiAjaxHandler
{
	private AiService $ai_service;

	public function __construct(?AiService $ai_service = null)
	{
		$this->ai_service = $ai_service ?? new AiService();
	}

	public function generate_writing_preset(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$provider = sanitize_key((string) ($_POST['provider'] ?? 'openrouter'));
		$prompt = sanitize_textarea_field((string) ($_POST['prompt'] ?? ''));
		$model = sanitize_text_field((string) ($_POST['model'] ?? ''));
		if (trim($prompt) === '') {
			wp_send_json_error(['message' => 'Prompt is required']);
		}

		$result = $this->ai_service->generate_writing_preset($prompt, $provider, [
			'model' => $model,
		]);
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success([
			'message' => 'Writing preset generated',
			'preset' => $result,
		]);
	}
}
