<?php

namespace PostStation\Services;

use PostStation\Models\PostTask;
use PostStation\Models\Campaign;
use PostStation\Models\Webhook;
use PostStation\Utils\Languages;
use PostStation\Utils\Countries;
use Exception;

class TaskRunner
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

		if (!PostTask::has_required_data_for_dispatch($task)) {
			$task_type = $task['campaign_type'] ?? 'default';
			$required = $task_type === 'rewrite_blog_post' ? __('research URL', 'poststation') : __('topic', 'poststation');
			return ['success' => false, 'message' => sprintf(__('Task cannot be sent: %s is required.', 'poststation'), $required)];
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
			$sitemap_service = new Sitemap();
			$body_config = $content_fields['body'] ?? [];
			$sitemap_entries = self::get_internal_link_sitemap_entries($sitemap_service, $campaign, $body_config);
			$sitemap_payload = self::format_sitemap_payload($sitemap_entries);
			
			// Inject terms for auto_select mode in taxonomies
			if (!empty($content_fields)) {
				if (
					!empty($content_fields['title']['mode']) &&
					in_array($content_fields['title']['mode'], ['generate_from_topic', 'use_topic_as_title'], true)
				) {
					$content_fields['title']['mode'] = 'generate';
				}

				if (!empty($content_fields['slug']['mode']) && $content_fields['slug']['mode'] === 'generate_from_title') {
					$content_fields['slug']['mode'] = 'generate';
				}

				// Normalize legacy image mode values to 'generate'
				if (!empty($content_fields['image']['mode']) && in_array($content_fields['image']['mode'], ['generate_from_title', 'generate_from_article'], true)) {
					$content_fields['image']['mode'] = 'generate';
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

					$resolved_internal_link_mode = self::resolve_internal_link_mode((array) $content_fields['body']);
					$content_fields['body']['internal_links_mode'] = $resolved_internal_link_mode;
					$content_fields['body']['internal_links_count'] = max(1, (int) ($content_fields['body']['internal_links_count'] ?? 4));

					if ($resolved_internal_link_mode === 'specific_taxonomy') {
						$content_fields['body']['internal_links_taxonomy'] = sanitize_key((string) ($content_fields['body']['internal_links_taxonomy'] ?? ''));
						$content_fields['body']['internal_links_terms'] = array_values(array_unique(array_filter(
							array_map('intval', (array) ($content_fields['body']['internal_links_terms'] ?? [])),
							static fn($term_id) => $term_id > 0
						)));
					} else {
						$content_fields['body']['internal_links_taxonomy'] = '';
						$content_fields['body']['internal_links_terms'] = [];
					}
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

				$content_fields = self::sanitize_content_fields_for_webhook($content_fields);

				$unified_taxonomies = [];
				if (isset($content_fields['categories']) && self::is_field_enabled($content_fields['categories'])) {
					$info = $get_taxonomy_info('category');
					$unified_taxonomies[] = array_merge(
						['taxonomy' => 'category'],
						$content_fields['categories'],
						$info ?: []
					);
				}
				if (isset($content_fields['tags']) && self::is_field_enabled($content_fields['tags'])) {
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

			$campaign_type = $task['campaign_type'] ?? $campaign['campaign_type'] ?? 'default';
			$topic = trim((string) ($task['topic'] ?? ''));
			$research_url = trim((string) ($task['research_url'] ?? ''));
			if ($campaign_type === 'rewrite_blog_post') {
				$topic = '';
			} else {
				$research_url = '';
			}
			$keywords_raw = $task['keywords'] ?? '';
			$keywords = array_values(array_filter(array_map('trim', explode(',', $keywords_raw))));
			$keywords = array_slice($keywords, 0, 5);
			$language_key = $campaign['language'] ?? 'en';
			$country_key = $campaign['target_country'] ?? 'international';

			$callback_base = get_site_url();
			$host = (string) parse_url($callback_base, PHP_URL_HOST);
			$is_local_host = in_array($host, ['localhost', '127.0.0.1'], true) || preg_match('/\.local$/i', $host);
			$tunnel_url = SettingsService::get_tunnel_url();
			if ($is_local_host && $tunnel_url !== '') {
				$callback_base = $tunnel_url;
			}
			$callback_url = rtrim($callback_base, '/') . '/ps-api';
			$workflow_api_key = (string) get_option(SettingsService::WORKFLOW_API_KEY_OPTION, '');
			$poststation_api_key = (string) get_option('poststation_api_key', '');
			$send_api_to_webhook = SettingsService::should_send_api_to_webhook();

			$body = [
				'task_id' => $task['id'],
				'research_url' => $research_url,
				'topic' => $topic,
				'title_override' => $title_override,
				'slug_override' => $slug_override,
				'feature_image_id' => !empty($task['feature_image_id']) ? (int) $task['feature_image_id'] : null,
				'keywords' => implode(', ', $keywords),
				'campaign_type' => $campaign_type,
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
				'sitemap' => $sitemap_payload,
				'callback_url' => $callback_url,
			];
			if ($send_api_to_webhook) {
				$body['api_key'] = $poststation_api_key;
			}

			PostTask::update($task_id, [
				'status' => 'processing',
				'run_started_at' => current_time('mysql'),
				'error_message' => null,
				'progress' => null,
			]);

			$response = wp_remote_post($webhook['url'], [
				'headers' => [
					'Content-Type' => 'application/json',
					'X-API-Key' => $workflow_api_key,
				],
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

			$response_body = wp_remote_retrieve_body($response);
			if (is_string($response_body)) {
				$execution_id = trim($response_body);
				if ($execution_id !== '') {
					PostTask::update($task_id, ['execution_id' => $execution_id]);
				}
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
		$campaign_type = $task['campaign_type'] ?? $campaign['campaign_type'] ?? 'default';

		$content_fields = !empty($campaign['content_fields']) ? json_decode($campaign['content_fields'], true) : [];
		$body_config = is_array($content_fields) ? ($content_fields['body'] ?? []) : [];
		$sitemap_service = new Sitemap();
		$sitemap_entries = self::get_internal_link_sitemap_entries($sitemap_service, $campaign, $body_config);
		$sitemap_str = self::format_sitemap_urls_string($sitemap_entries);

		$placeholders = [
			'{{research_url}}' => $task['research_url'] ?? '',
			'{{topic}}' => $topic,
			'{{keywords}}' => implode(', ', $keywords),
			'{{campaign_type}}' => $campaign_type,
			'{{image_title}}' => str_replace('{{title}}', $topic ?: 'Post', $task['feature_image_title'] ?? '{{title}}'),
			'{{sitemap}}' => $sitemap_str,
		];

		return str_replace(array_keys($placeholders), array_values($placeholders), $text);
	}

	private static function get_internal_link_sitemap_entries(Sitemap $sitemap_service, array $campaign, array $body_config): array
	{
		$mode = self::resolve_internal_link_mode($body_config);
		if ($mode === 'none') {
			return [];
		}

		if ($mode === 'campaign_post_type_only') {
			$post_type = (string) ($campaign['post_type'] ?? 'post');
			return $sitemap_service->get_sitemap_json($post_type);
		}

		if ($mode === 'specific_taxonomy') {
			$taxonomy = sanitize_key((string) ($body_config['internal_links_taxonomy'] ?? ''));
			if (in_array($taxonomy, ['post_format', 'format'], true)) {
				return [];
			}

			$term_ids = array_values(array_unique(array_filter(
				array_map('intval', (array) ($body_config['internal_links_terms'] ?? [])),
				static fn($term_id) => $term_id > 0
			)));

			if ($taxonomy !== '' && !empty($term_ids)) {
				return $sitemap_service->get_sitemap_json_by_taxonomy_terms($taxonomy, $term_ids);
			}

			return [];
		}

		return $sitemap_service->get_sitemap_json_all_public();
	}

	private static function resolve_internal_link_mode(array $body_config): string
	{
		$mode = strtolower(trim((string) ($body_config['internal_links_mode'] ?? '')));
		if ($mode === 'any_post_type') {
			$mode = 'all_post_types';
		}

		$allowed_modes = ['none', 'all_post_types', 'campaign_post_type_only', 'specific_taxonomy'];
		if (in_array($mode, $allowed_modes, true)) {
			return $mode;
		}

		return 'all_post_types';
	}

	private static function format_sitemap_payload(array $sitemap_entries): array
	{
		return array_values(array_filter(array_map(
			static function ($entry) {
				if (!is_array($entry)) {
					return null;
				}

				$url = isset($entry['url']) ? (string) $entry['url'] : '';
				if ($url === '') {
					return null;
				}

				$title = isset($entry['title']) ? (string) $entry['title'] : '';
				return [$title, $url];
			},
			$sitemap_entries
		)));
	}

	private static function format_sitemap_urls_string(array $sitemap_entries): string
	{
		return implode(', ', array_values(array_filter(array_map(
			static fn($entry) => is_array($entry) ? (string) ($entry['url'] ?? '') : '',
			$sitemap_entries
		))));
	}

	private static function sanitize_content_fields_for_webhook(array $content_fields): array
	{
		$toggle_fields = ['title', 'slug', 'body', 'categories', 'tags', 'image'];
		foreach ($toggle_fields as $field_name) {
			if (!isset($content_fields[$field_name]) || !is_array($content_fields[$field_name])) {
				continue;
			}

			if (!self::is_field_enabled($content_fields[$field_name])) {
				$content_fields[$field_name] = ['enabled' => false];
			}
		}

		foreach (['custom_taxonomies', 'custom_fields'] as $list_field) {
			$items = $content_fields[$list_field] ?? [];
			if (!is_array($items)) {
				$content_fields[$list_field] = [];
				continue;
			}

			$content_fields[$list_field] = array_values(array_filter(
				$items,
				static function ($item): bool {
					if (!is_array($item)) {
						return false;
					}
					return TaskRunner::is_field_enabled($item);
				}
			));
		}

		return $content_fields;
	}

	private static function is_field_enabled($config): bool
	{
		if (!is_array($config)) {
			return false;
		}

		if (!array_key_exists('enabled', $config)) {
			return true;
		}

		$value = $config['enabled'];
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return $value === 1;
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			return in_array($normalized, ['1', 'true', 'yes'], true);
		}

		return false;
	}
}
