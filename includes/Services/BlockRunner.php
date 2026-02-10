<?php

namespace PostStation\Services;

use PostStation\Models\PostBlock;
use PostStation\Models\PostWork;
use PostStation\Models\Webhook;
use PostStation\Utils\Languages;
use PostStation\Utils\Countries;
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
			$content_fields = !empty($postwork['content_fields']) ? json_decode($postwork['content_fields'], true) : [];
			$default_content_fields = PostWork::get_default_content_fields();
			$content_fields = array_replace_recursive($default_content_fields, $content_fields);
			
			// Inject terms for auto_select mode in taxonomies
			if (!empty($content_fields)) {
				if (!empty($content_fields['title']['mode']) && $content_fields['title']['mode'] === 'generate_from_topic') {
					$content_fields['title']['mode'] = 'generate';
				}

				if (!empty($content_fields['image']['mode']) && $content_fields['image']['mode'] === 'generate_from_title') {
					$content_fields['image']['mode'] = 'generate_from_article';
				}

				if (!empty($block['feature_image_id']) && isset($content_fields['image'])) {
					$content_fields['image']['enabled'] = false;
				}

				// Helper to get taxonomy info and terms
				$get_taxonomy_info = function($taxonomy_name) {
					$tax = get_taxonomy($taxonomy_name);
					if (!$tax) return null;

					$terms = get_terms([
						'taxonomy' => $taxonomy_name,
						'hide_empty' => false,
						'fields' => 'names',
					]);

					if (!is_wp_error($terms)) {
						$terms = array_map(function($term) {
							return html_entity_decode($term, ENT_QUOTES, 'UTF-8');
						}, $terms);
					}

					return [
						'name' => $tax->name,
						'label' => $tax->label,
						'singular_label' => $tax->labels->singular_name,
						'plural_label' => $tax->labels->name,
						'available_terms' => !is_wp_error($terms) ? implode(', ', $terms) : '',
					];
				};

				// Process categories
				if (isset($content_fields['categories'])) {
					$info = $get_taxonomy_info('category');
					if ($info) {
						$content_fields['categories'] = array_merge($content_fields['categories'], $info);
					}
				}

				// Process tags
				if (isset($content_fields['tags'])) {
					$info = $get_taxonomy_info('post_tag');
					if ($info) {
						$content_fields['tags'] = array_merge($content_fields['tags'], $info);
					}
				}

				// Process custom taxonomies
				if (!empty($content_fields['custom_taxonomies'])) {
					foreach ($content_fields['custom_taxonomies'] as &$tax_config) {
						if (!empty($tax_config['taxonomy'])) {
							$info = $get_taxonomy_info($tax_config['taxonomy']);
							if ($info) {
								$tax_config = array_merge($tax_config, $info);
							}
						}
					}
					unset($tax_config);
				}

				$unified_taxonomies = [];
				if (isset($content_fields['categories'])) {
					$unified_taxonomies[] = array_merge(
						['taxonomy' => 'category'],
						$content_fields['categories']
					);
				}
				if (isset($content_fields['tags'])) {
					$unified_taxonomies[] = array_merge(
						['taxonomy' => 'post_tag'],
						$content_fields['tags']
					);
				}
				if (!empty($content_fields['custom_taxonomies'])) {
					foreach ($content_fields['custom_taxonomies'] as $tax_config) {
						$unified_taxonomies[] = $tax_config;
					}
				}

				$content_fields['taxonomies'] = $unified_taxonomies;
			}

			$topic = $block['topic'] ?? '';
			$keywords_raw = $block['keywords'] ?? '';
			$keywords = array_values(array_filter(array_map('trim', explode(',', $keywords_raw))));
			$keywords = array_slice($keywords, 0, 5);
			$primary_keyword = $keywords[0] ?? $topic;
			$article_type = $block['article_type'] ?? $postwork['article_type'] ?? 'blog_post';
			$language_key = $postwork['language'] ?? 'en';
			$country_key = $postwork['target_country'] ?? 'international';

			$body = [
				'block_id' => $block['id'],
				'research_url' => $block['research_url'] ?? '',
				'topic' => $topic,
				'keywords' => [
					'primary_key' => $primary_keyword,
					'additional_keywords' => implode(', ', array_values(array_filter($keywords, fn($keyword) => $keyword !== $primary_keyword))),
				],
				'article_type' => $article_type,
				'language' => [
					'key' => $language_key,
					'name' => Languages::get_name($language_key),
				],
				'target_country' => [
					'key' => $country_key,
					'name' => Countries::get_name($country_key),
				],
				'content_fields' => $content_fields,
				'feature_image_title' => $block['feature_image_title'] ?? '{{title}}',
				'sitemap' => (new Sitemap())->get_sitemap_json($postwork['post_type']),
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

		$topic = $block['topic'] ?? '';
		$keywords_raw = $block['keywords'] ?? '';
		$keywords = array_values(array_filter(array_map('trim', explode(',', $keywords_raw))));
		$keywords = array_slice($keywords, 0, 5);
		$primary_keyword = $keywords[0] ?? $topic;
		$article_type = $block['article_type'] ?? $postwork['article_type'] ?? 'blog_post';

		$placeholders = [
			'{{research_url}}' => $block['research_url'] ?? '',
			'{{topic}}' => $topic,
			'{{keywords}}' => implode(', ', $keywords),
			'{{primary_keyword}}' => $primary_keyword,
			'{{article_type}}' => $article_type,
			'{{image_title}}' => str_replace('{{title}}', $topic ?: 'Post', $block['feature_image_title'] ?? '{{title}}'),
			'{{sitemap}}' => wp_json_encode((new Sitemap())->get_sitemap_json($postwork['post_type'])),
		];

		return str_replace(array_keys($placeholders), array_values($placeholders), $text);
	}
}
