<?php

namespace PostStation\Services;

use PostStation\Models\PostTask;
use PostStation\Models\Campaign;
use PostStation\Models\Webhook;
use PostStation\Utils\Languages;
use PostStation\Utils\Countries;
use Exception;

class BlockRunner
{
	public static function dispatch_task(int $campaign_id, int $task_id, int $webhook_id): array
	{
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign) {
			return ['success' => false, 'message' => __('Campaign not found.', 'poststation')];
		}

		$task = PostTask::get_by_id($task_id);
		if (!$task) {
			return ['success' => false, 'message' => __('Post task not found.', 'poststation')];
		}

		$webhook = Webhook::get_by_id($webhook_id);
		if (!$webhook) {
			return ['success' => false, 'message' => __('Webhook not found.', 'poststation')];
		}

		try {
			$content_fields = !empty($campaign['content_fields']) ? json_decode($campaign['content_fields'], true) : [];
			$default_content_fields = Campaign::get_default_content_fields();
			$content_fields = array_replace_recursive($default_content_fields, $content_fields);
			$title_override = trim((string) ($task['title_override'] ?? ''));
			$slug_override = trim((string) ($task['slug_override'] ?? ''));
			$sitemap_entries = (new Sitemap())->get_sitemap_json($campaign['post_type']);
			$sitemap_urls = array_values(array_filter(array_map(
				static fn($entry) => is_array($entry) ? (string) ($entry['url'] ?? '') : '',
				$sitemap_entries
			)));
			$sitemap_csv = implode(', ', $sitemap_urls);
			
			// Inject terms for auto_select mode in taxonomies
			if (!empty($content_fields)) {
				if (!empty($content_fields['title']['mode']) && $content_fields['title']['mode'] === 'generate_from_topic') {
					$content_fields['title']['mode'] = 'generate';
				}

				if (!empty($content_fields['image']['mode']) && $content_fields['image']['mode'] === 'generate_from_title') {
					$content_fields['image']['mode'] = 'generate_from_article';
				}

				if (!empty($task['feature_image_id']) && isset($content_fields['image'])) {
					$content_fields['image']['enabled'] = false;
				}

				if ($title_override !== '' && isset($content_fields['title'])) {
					$content_fields['title']['enabled'] = false;
				}

				if ($slug_override !== '' && isset($content_fields['slug'])) {
					$content_fields['slug']['enabled'] = false;
				}

				if (isset($content_fields['body'])) {
					$content_fields['body']['tone_of_voice'] = (string) ($campaign['tone_of_voice'] ?? 'none');
					$content_fields['body']['point_of_view'] = (string) ($campaign['point_of_view'] ?? 'none');
					$content_fields['body']['readability'] = (string) ($campaign['readability'] ?? 'grade_8');
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

				/*
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
				*/

				$unified_taxonomies = [];
				if (isset($content_fields['categories'])) {
					$info = $get_taxonomy_info('category');
					$unified_taxonomies[] = array_merge(
						['taxonomy' => 'category'],
						$content_fields['categories'],
						$info ?: []
					);
				}
				if (isset($content_fields['tags'])) {
					$info = $get_taxonomy_info('post_tag');
					$unified_taxonomies[] = array_merge(
						['taxonomy' => 'post_tag'],
						$content_fields['tags'],
						$info ?: []
					);
				}
				if (!empty($content_fields['custom_taxonomies'])) {
					foreach ($content_fields['custom_taxonomies'] as $tax_config) {
						$info = !empty($tax_config['taxonomy']) ? $get_taxonomy_info($tax_config['taxonomy']) : null;
						$unified_taxonomies[] = array_merge(
							$tax_config,
							$info ?: []
						);
					}
				}

				$content_fields['taxonomies'] = $unified_taxonomies;

				// Send only unified taxonomies to webhook payload.
				unset(
					$content_fields['categories'],
					$content_fields['tags'],
					$content_fields['custom_taxonomies']
				);
			}

			$topic = $task['topic'] ?? '';
			$keywords_raw = $task['keywords'] ?? '';
			$keywords = array_values(array_filter(array_map('trim', explode(',', $keywords_raw))));
			$keywords = array_slice($keywords, 0, 5);
			$primary_keyword = $keywords[0] ?? $topic;
			$article_type = $task['article_type'] ?? $campaign['article_type'] ?? 'blog_post';
			$language_key = $campaign['language'] ?? 'en';
			$country_key = $campaign['target_country'] ?? 'international';

			$body = [
				'task_id' => $task['id'],
				'research_url' => $task['research_url'] ?? '',
				'topic' => $topic,
				'title_override' => $title_override,
				'slug_override' => $slug_override,
				'feature_image_id' => !empty($task['feature_image_id']) ? (int) $task['feature_image_id'] : null,
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
				'tone_of_voice' => (string) ($campaign['tone_of_voice'] ?? 'none'),
				'point_of_view' => (string) ($campaign['point_of_view'] ?? 'none'),
				'readability' => (string) ($campaign['readability'] ?? 'grade_8'),
				'content_fields' => $content_fields,
				'sitemap' => $sitemap_csv,
				'callback_url' => get_site_url() . '/ps-api',
				'api_key' => get_option('poststation_api_key'),
			];

			PostTask::update($task_id, [
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

			return ['success' => true, 'task' => $task];
		} catch (Exception $e) {
			PostTask::update($task_id, [
				'status' => 'failed',
				'error_message' => $e->getMessage(),
			]);

			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	private static function process_placeholders(string $text, array $task, array $campaign): string
	{
		if (empty($text)) {
			return $text;
		}

		$topic = $task['topic'] ?? '';
		$keywords_raw = $task['keywords'] ?? '';
		$keywords = array_values(array_filter(array_map('trim', explode(',', $keywords_raw))));
		$keywords = array_slice($keywords, 0, 5);
		$primary_keyword = $keywords[0] ?? $topic;
		$article_type = $task['article_type'] ?? $campaign['article_type'] ?? 'blog_post';

		$placeholders = [
			'{{research_url}}' => $task['research_url'] ?? '',
			'{{topic}}' => $topic,
			'{{keywords}}' => implode(', ', $keywords),
			'{{primary_keyword}}' => $primary_keyword,
			'{{article_type}}' => $article_type,
			'{{image_title}}' => str_replace('{{title}}', $topic ?: 'Post', $task['feature_image_title'] ?? '{{title}}'),
			'{{sitemap}}' => implode(', ', array_values(array_filter(array_map(
				static fn($entry) => is_array($entry) ? (string) ($entry['url'] ?? '') : '',
				(new Sitemap())->get_sitemap_json($campaign['post_type'])
			)))),
		];

		return str_replace(array_keys($placeholders), array_values($placeholders), $text);
	}
}
