<?php

namespace PostStation\Services;

use PostStation\Models\PostBlock;
use PostStation\Models\PostWork;
use PostStation\Models\Webhook;
use Exception;

class BlockRunner
{
	public static function dispatch_block(int $postwork_id, int $block_id, int $webhook_id): array
	{
		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			return ['success' => false, 'message' => __('Post work not found.', 'poststation')];
		}

		$block = PostBlock::get_by_id($block_id);
		if (!$block) {
			return ['success' => false, 'message' => __('Block not found.', 'poststation')];
		}

		$webhook = Webhook::get_by_id($webhook_id);
		if (!$webhook) {
			return ['success' => false, 'message' => __('Webhook not found.', 'poststation')];
		}

		try {
			$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
			$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];
			$post_fields = !empty($block_post_fields) ? $block_post_fields : $postwork_post_fields;

			$processed_instructions = self::process_placeholders($postwork['instructions'] ?? '', $block, $postwork);

			$processed_post_fields = [];
			foreach ($post_fields as $key => $field) {
				if (is_array($field)) {
					$processed_post_fields[$key] = [
						'value' => self::process_placeholders($field['value'] ?? '', $block, $postwork),
						'prompt' => self::process_placeholders($field['prompt'] ?? '', $block, $postwork),
						'type' => $field['type'] ?? 'string',
						'required' => $field['required'] ?? false
					];
				} else {
					$processed_post_fields[$key] = self::process_placeholders($field, $block, $postwork);
				}
			}

			$image_config = !empty($postwork['image_config']) ? json_decode($postwork['image_config'], true) : [];
			$content_fields = !empty($postwork['content_fields']) ? json_decode($postwork['content_fields'], true) : [];

			$body = [
				'block_id' => $block['id'],
				'article_url' => $block['article_url'] ?? '',
				'keyword' => $block['keyword'] ?? '',
				'instructions' => $processed_instructions,
				'taxonomies' => json_decode($block['taxonomies'] ?? '{}', true),
				'post_fields' => $processed_post_fields,
				'content_fields' => $content_fields,
				'feature_image_title' => $block['feature_image_title'] ?? '{{title}}',
				'sitemap' => (new Sitemap())->get_sitemap_json($postwork['post_type']),
				'image_config' => $image_config,
				'callback_url' => get_site_url() . '/ps-api',
				'api_key' => get_option('poststation_api_key'),
			];

			PostBlock::update($block_id, [
				'status' => 'processing',
				'run_started_at' => current_time('mysql'),
				'error_message' => null,
				'progress' => null,
			]);

			$response = wp_remote_post($webhook['url'], [
				'headers' => ['Content-Type' => 'application/json'],
				'body' => wp_json_encode($body),
				'timeout' => 30,
				'sslverify' => false,
			]);

			if (is_wp_error($response)) {
				throw new Exception($response->get_error_message());
			}

			$response_code = wp_remote_retrieve_response_code($response);
			if ($response_code !== 200) {
				throw new Exception(sprintf(
					__('Webhook returned error code: %d', 'poststation'),
					$response_code
				));
			}

			return ['success' => true, 'block' => $block];
		} catch (Exception $e) {
			PostBlock::update($block_id, [
				'status' => 'failed',
				'error_message' => $e->getMessage(),
			]);

			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	private static function process_placeholders(string $text, array $block, array $postwork): string
	{
		if (empty($text)) {
			return $text;
		}

		$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
		$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];

		$placeholders = [
			'{{article_url}}' => $block['article_url'] ?? '',
			'{{keyword}}' => $block['keyword'] ?? '',
			'{{image_title}}' => str_replace('{{title}}', $block['keyword'] ?: 'Post', $block['feature_image_title'] ?? '{{title}}'),
			'{{sitemap}}' => wp_json_encode((new Sitemap())->get_sitemap_json($postwork['post_type'])),
		];

		foreach ($postwork_post_fields as $key => $field) {
			$value = $block_post_fields[$key]['value'] ?? $field['value'] ?? '';
			$placeholders["{{{$key}}}"] = $value;
		}

		return str_replace(array_keys($placeholders), array_values($placeholders), $text);
	}
}
