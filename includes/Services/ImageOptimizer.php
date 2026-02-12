<?php

namespace PostStation\Services;

use Exception;
use WP_Error;

class ImageOptimizer
{
	public function upload_base64_image(array $args): array
	{
		$block_id = (int) ($args['block_id'] ?? 0);
		$base64 = (string) ($args['image_base64'] ?? '');
		$index = $args['index'] ?? null;
		$requested_format = (string) ($args['format'] ?? 'webp');
		$filename = (string) ($args['filename'] ?? '');
		$alt_text = (string) ($args['alt_text'] ?? '');
		$image_identifier = sanitize_file_name((string) ($args['image_identifier'] ?? ''));

		if (!$block_id || $base64 === '') {
			throw new Exception('Missing required image upload fields', 400);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$decoded = $this->decode_base64_image($base64);
		$tmp_file = function_exists('wp_tempnam')
			? wp_tempnam('poststation-image')
			: tempnam(sys_get_temp_dir(), 'poststation-image');
		if (!$tmp_file) {
			throw new Exception('Failed to create temp file', 500);
		}

		file_put_contents($tmp_file, $decoded['data']);

		if (!getimagesize($tmp_file)) {
			@unlink($tmp_file);
			throw new Exception('Invalid image data', 400);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$editor = wp_get_image_editor($tmp_file);
		if (is_wp_error($editor)) {
			@unlink($tmp_file);
			throw new Exception($editor->get_error_message(), 500);
		}

		$format = $this->resolve_format($requested_format);
		$mime = $this->format_to_mime($format);

		$upload_dir = wp_upload_dir();
		if (!empty($upload_dir['error'])) {
			@unlink($tmp_file);
			throw new Exception($upload_dir['error'], 500);
		}

		$base_name = $this->sanitize_filename_base($filename);
		$index_label = $index === null || $index === '' ? '0' : (string) $index;
		$date = gmdate('YmdHis');
		$base_filename = sprintf(
			'%s-%d-%s-%s',
			$base_name,
			$block_id,
			$index_label,
			$date
		);
		if ($image_identifier !== '') {
			$base_filename .= '-psid-' . $image_identifier;
		}

		$target_filename = wp_unique_filename($upload_dir['path'], $base_filename . '.' . $format);
		$target_path = trailingslashit($upload_dir['path']) . $target_filename;

		$editor->set_quality(82);
		$saved = $editor->save($target_path, $mime);
		@unlink($tmp_file);

		if (is_wp_error($saved)) {
			throw new Exception($saved->get_error_message(), 500);
		}

		$attachment = [
			'post_mime_type' => $saved['mime-type'] ?? $mime,
			'post_title' => sanitize_text_field($base_name),
			'post_content' => '',
			'post_status' => 'inherit',
		];

		$attachment_id = wp_insert_attachment($attachment, $target_path);
		if (is_wp_error($attachment_id)) {
			throw new Exception($attachment_id->get_error_message(), 500);
		}

		$attach_data = wp_generate_attachment_metadata($attachment_id, $target_path);
		if (!is_wp_error($attach_data)) {
			wp_update_attachment_metadata($attachment_id, $attach_data);
		}

		if ($alt_text !== '') {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
		}

		return [
			'attachment_id' => (int) $attachment_id,
			'url' => wp_get_attachment_url($attachment_id),
			'format' => $format,
		];
	}

	private function decode_base64_image(string $base64): array
	{
		$mime = '';
		$data = $base64;

		if (preg_match('/^data:(image\\/[a-zA-Z0-9.+-]+);base64,(.*)$/', $base64, $matches)) {
			$mime = strtolower($matches[1]);
			$data = $matches[2];
		}

		$decoded = base64_decode($data, true);
		if ($decoded === false) {
			throw new Exception('Invalid base64 image data', 400);
		}

		if ($mime === '') {
			$info = getimagesizefromstring($decoded);
			if ($info && !empty($info['mime'])) {
				$mime = $info['mime'];
			}
		}

		return [
			'data' => $decoded,
			'mime' => $mime,
		];
	}

	private function resolve_format(string $requested): string
	{
		$requested = strtolower($requested);
		if ($requested === 'jpeg') {
			$requested = 'jpg';
		}

		$candidates = ['webp', 'jpg'];
		if ($requested === 'avif') {
			$candidates = ['avif', 'webp', 'jpg'];
		} elseif ($requested === 'jpg') {
			$candidates = ['jpg', 'webp'];
		} elseif ($requested === 'webp') {
			$candidates = ['webp', 'jpg'];
		}

		foreach ($candidates as $format) {
			$mime = $this->format_to_mime($format);
			if (wp_image_editor_supports(['mime_type' => $mime])) {
				return $format;
			}
		}

		return 'jpg';
	}

	private function format_to_mime(string $format): string
	{
		switch ($format) {
			case 'avif':
				return 'image/avif';
			case 'webp':
				return 'image/webp';
			default:
				return 'image/jpeg';
		}
	}

	private function sanitize_filename_base(string $filename): string
	{
		$base = $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : 'image';
		$base = sanitize_file_name($base);
		$base = trim($base, '-_');

		return $base !== '' ? $base : 'image';
	}
}
