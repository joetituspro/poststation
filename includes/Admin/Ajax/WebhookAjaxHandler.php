<?php

namespace PostStation\Admin\Ajax;

use PostStation\Models\Webhook;

class WebhookAjaxHandler
{
	public function get_webhooks(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		wp_send_json_success(['webhooks' => Webhook::get_all()]);
	}

	public function get_webhook(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}

		$webhook = Webhook::get_by_id($id);
		if (!$webhook) {
			wp_send_json_error(['message' => 'Webhook not found']);
		}

		wp_send_json_success(['webhook' => $webhook]);
	}

	public function save_webhook(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		$name = sanitize_text_field($_POST['name'] ?? '');
		$url = esc_url_raw($_POST['url'] ?? '');
		if ($name === '' || $url === '') {
			wp_send_json_error(['message' => 'Name and URL are required']);
		}

		$data = ['name' => $name, 'url' => $url];
		if ($id > 0) {
			$success = Webhook::update($id, $data);
			if ($success) {
				wp_send_json_success(['message' => 'Webhook updated', 'id' => $id]);
			}
		} else {
			$new_id = Webhook::create($data);
			if ($new_id) {
				wp_send_json_success(['message' => 'Webhook created', 'id' => $new_id]);
			}
		}

		wp_send_json_error(['message' => 'Failed to save webhook']);
	}

	public function delete_webhook(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}

		if (Webhook::delete($id)) {
			wp_send_json_success(['message' => 'Webhook deleted']);
		}
		wp_send_json_error(['message' => 'Failed to delete webhook']);
	}
}
