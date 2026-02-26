<?php

namespace PostStation\Admin\Ajax;

use PostStation\Models\WritingPreset;

class WritingPresetAjaxHandler
{
	private const DESCRIPTION_MAX_LENGTH = 80;

	public function create_writing_preset(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$key = sanitize_key((string) ($_POST['key'] ?? ''));
		$name = sanitize_text_field((string) ($_POST['name'] ?? ''));
		$description = $this->normalize_description((string) ($_POST['description'] ?? ''));
		$instructions = json_decode(stripslashes((string) ($_POST['instructions'] ?? '{}')), true);
		if (!is_array($instructions)) {
			$instructions = ['title' => '', 'body' => ''];
		}
		if ($key === '' || $name === '') {
			wp_send_json_error(['message' => 'Key and name are required']);
		}
		if (WritingPreset::get_by_key($key)) {
			wp_send_json_error(['message' => 'A writing preset with this key already exists']);
		}

		$id = WritingPreset::create([
			'key' => $key,
			'name' => $name,
			'description' => $description,
			'instructions' => $instructions,
		]);
		if ($id) {
			$writing_preset = WritingPreset::get_by_id($id);
			wp_send_json_success(['message' => 'Writing preset created', 'id' => $id, 'writing_preset' => $writing_preset]);
		}
		wp_send_json_error(['message' => 'Failed to create writing preset']);
	}

	public function update_writing_preset(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}
		$existing = WritingPreset::get_by_id($id);
		if (!$existing) {
			wp_send_json_error(['message' => 'Writing preset not found']);
		}

		$description = $this->normalize_description((string) ($_POST['description'] ?? ''));
		$instructions = json_decode(stripslashes((string) ($_POST['instructions'] ?? '{}')), true);
		if (!is_array($instructions)) {
			$instructions = ['title' => '', 'body' => ''];
		}

		$success = WritingPreset::update($id, [
			'description' => $description,
			'instructions' => $instructions,
		]);
		if ($success) {
			$writing_preset = WritingPreset::get_by_id($id);
			wp_send_json_success(['message' => 'Writing preset updated', 'writing_preset' => $writing_preset]);
		}
		wp_send_json_error(['message' => 'Failed to update writing preset']);
	}

	public function duplicate_writing_preset(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		$new_key = sanitize_key((string) ($_POST['new_key'] ?? ''));
		$new_name = sanitize_text_field((string) ($_POST['new_name'] ?? ''));
		if (!$id || $new_key === '' || $new_name === '') {
			wp_send_json_error(['message' => 'ID, new key and new name are required']);
		}
		$source = WritingPreset::get_by_id($id);
		if (!$source) {
			wp_send_json_error(['message' => 'Writing preset not found']);
		}
		if (WritingPreset::get_by_key($new_key)) {
			wp_send_json_error(['message' => 'A writing preset with this key already exists']);
		}

		$new_id = WritingPreset::create([
			'key' => $new_key,
			'name' => $new_name,
			'description' => $this->normalize_description((string) ($source['description'] ?? '')),
			'instructions' => $source['instructions'] ?? ['title' => '', 'body' => ''],
		]);
		if ($new_id) {
			$writing_preset = WritingPreset::get_by_id($new_id);
			wp_send_json_success(['message' => 'Writing preset duplicated', 'id' => $new_id, 'writing_preset' => $writing_preset]);
		}
		wp_send_json_error(['message' => 'Failed to duplicate writing preset']);
	}

	public function reset_writing_preset(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}
		$success = WritingPreset::reset_to_default($id);
		if ($success) {
			$writing_preset = WritingPreset::get_by_id($id);
			wp_send_json_success(['message' => 'Writing preset reset to default', 'writing_preset' => $writing_preset]);
		}
		wp_send_json_error(['message' => 'Reset only applies to default presets (Listicle, News, Guide, How-to)']);
	}

	public function delete_writing_preset(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$id = (int) ($_POST['id'] ?? 0);
		if (!$id) {
			wp_send_json_error(['message' => 'Invalid ID']);
		}
		$success = WritingPreset::delete($id);
		if ($success) {
			wp_send_json_success(['message' => 'Writing preset deleted']);
		}
		wp_send_json_error(['message' => 'Cannot delete default presets (Listicle, News, Guide, How-to)']);
	}

	private function normalize_description(string $description): string
	{
		$description = sanitize_textarea_field($description);
		if (function_exists('mb_substr')) {
			return (string) mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
		}
		return substr($description, 0, self::DESCRIPTION_MAX_LENGTH);
	}
}
