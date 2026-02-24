<?php

namespace PostStation\Admin\Ajax;

use PostStation\Models\Instruction;

class InstructionAjaxHandler
{
	public function create_instruction(): void
	{
		if (!NonceVerifier::verify()) {
			wp_send_json_error(['message' => 'Invalid nonce']);
		}
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$key = sanitize_key((string) ($_POST['key'] ?? ''));
		$name = sanitize_text_field((string) ($_POST['name'] ?? ''));
		$description = sanitize_textarea_field((string) ($_POST['description'] ?? ''));
		$instructions = json_decode(stripslashes((string) ($_POST['instructions'] ?? '{}')), true);
		if (!is_array($instructions)) {
			$instructions = ['title' => '', 'body' => ''];
		}
		if ($key === '' || $name === '') {
			wp_send_json_error(['message' => 'Key and name are required']);
		}
		if (Instruction::get_by_key($key)) {
			wp_send_json_error(['message' => 'An instruction with this key already exists']);
		}

		$id = Instruction::create([
			'key' => $key,
			'name' => $name,
			'description' => $description,
			'instructions' => $instructions,
		]);
		if ($id) {
			$instruction = Instruction::get_by_id($id);
			wp_send_json_success(['message' => 'Instruction created', 'id' => $id, 'instruction' => $instruction]);
		}
		wp_send_json_error(['message' => 'Failed to create instruction']);
	}

	public function update_instruction(): void
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
		$existing = Instruction::get_by_id($id);
		if (!$existing) {
			wp_send_json_error(['message' => 'Instruction not found']);
		}

		$description = sanitize_textarea_field((string) ($_POST['description'] ?? ''));
		$instructions = json_decode(stripslashes((string) ($_POST['instructions'] ?? '{}')), true);
		if (!is_array($instructions)) {
			$instructions = ['title' => '', 'body' => ''];
		}

		$success = Instruction::update($id, [
			'description' => $description,
			'instructions' => $instructions,
		]);
		if ($success) {
			$instruction = Instruction::get_by_id($id);
			wp_send_json_success(['message' => 'Instruction updated', 'instruction' => $instruction]);
		}
		wp_send_json_error(['message' => 'Failed to update instruction']);
	}

	public function duplicate_instruction(): void
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
		$source = Instruction::get_by_id($id);
		if (!$source) {
			wp_send_json_error(['message' => 'Instruction not found']);
		}
		if (Instruction::get_by_key($new_key)) {
			wp_send_json_error(['message' => 'An instruction with this key already exists']);
		}

		$new_id = Instruction::create([
			'key' => $new_key,
			'name' => $new_name,
			'description' => $source['description'] ?? '',
			'instructions' => $source['instructions'] ?? ['title' => '', 'body' => ''],
		]);
		if ($new_id) {
			$instruction = Instruction::get_by_id($new_id);
			wp_send_json_success(['message' => 'Instruction duplicated', 'id' => $new_id, 'instruction' => $instruction]);
		}
		wp_send_json_error(['message' => 'Failed to duplicate instruction']);
	}

	public function reset_instruction(): void
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
		$success = Instruction::reset_to_default($id);
		if ($success) {
			$instruction = Instruction::get_by_id($id);
			wp_send_json_success(['message' => 'Instruction reset to default', 'instruction' => $instruction]);
		}
		wp_send_json_error(['message' => 'Reset only applies to default presets (Listicle, News, Guide, How-to)']);
	}

	public function delete_instruction(): void
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
		$success = Instruction::delete($id);
		if ($success) {
			wp_send_json_success(['message' => 'Instruction deleted']);
		}
		wp_send_json_error(['message' => 'Cannot delete default presets (Listicle, News, Guide, How-to)']);
	}
}
