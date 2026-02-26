<?php

namespace PostStation\Admin;

use PostStation\Models\Campaign;
use PostStation\Models\CampaignRss;
use PostStation\Models\WritingPreset;
use PostStation\Models\PostTask;
use PostStation\Models\Webhook;
use PostStation\Services\SettingsService;
use PostStation\Utils\Countries;
use PostStation\Utils\Languages;

class BootstrapDataProvider
{
	private const STATIC_CACHE_KEY = 'poststation_bootstrap_static';
	private const STATIC_CACHE_TTL = 300; // 5 minutes

	private SettingsService $settings_service;

	public function __construct(?SettingsService $settings_service = null)
	{
		$this->settings_service = $settings_service ?? new SettingsService();
	}

	/**
	 * Clear the static bootstrap cache. Call when taxonomies/terms or users change so the next request gets fresh data.
	 */
	public static function clear_static_cache(): void
	{
		delete_transient(self::STATIC_CACHE_KEY);
	}

	/**
	 * Cached heavy data (taxonomies, users, openrouter_models) to avoid repeated work on every bootstrap request.
	 */
	private function get_static_bootstrap(): array
	{
		$cached = get_transient(self::STATIC_CACHE_KEY);
		if (is_array($cached)) {
			return $cached;
		}

		$static = [
			'taxonomies' => $this->get_taxonomy_data(),
			'users' => $this->get_user_data(),
			'openrouter_models' => $this->settings_service->get_openrouter_service()->get_models(false, true),
		];
		set_transient(self::STATIC_CACHE_KEY, $static, self::STATIC_CACHE_TTL);
		return $static;
	}

	public function get_post_type_options(): array
	{
		$post_types = get_post_types(['public' => true], 'objects');
		$post_type_options = [];
		foreach ($post_types as $post_type) {
			$post_type_options[$post_type->name] = $post_type->label;
		}
		return $post_type_options;
	}

	public function get_taxonomy_data(): array
	{
		$taxonomy_data = [];
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		foreach ($taxonomies as $taxonomy) {
			if (in_array($taxonomy->name, ['post_format', 'format'], true)) {
				continue;
			}

			$terms = get_terms([
				'taxonomy' => $taxonomy->name,
				'hide_empty' => false,
			]);
			if (is_wp_error($terms)) {
				$terms = [];
			}

			$terms_array = [];
			foreach ((array) $terms as $term) {
				$term_obj = is_object($term) ? $term : (object) $term;
				$terms_array[] = [
					'term_id' => $term_obj->term_id ?? 0,
					'name' => html_entity_decode((string) ($term_obj->name ?? ''), ENT_QUOTES, 'UTF-8'),
					'slug' => $term_obj->slug ?? '',
					'count' => isset($term_obj->count) ? (int) $term_obj->count : 0,
				];
			}

			$taxonomy_data[$taxonomy->name] = [
				'label' => html_entity_decode((string) ($taxonomy->labels->name ?? $taxonomy->name), ENT_QUOTES, 'UTF-8'),
				'terms' => $terms_array,
			];
		}

		return $taxonomy_data;
	}

	public function get_user_data(): array
	{
		$users = get_users(['role__in' => ['administrator', 'editor', 'author']]);
		return array_map(static function ($user) {
			return [
				'id' => $user->ID,
				'display_name' => $user->display_name,
			];
		}, $users);
	}

	public function get_campaigns_with_counts(): array
	{
		$campaigns = Campaign::get_all();
		$counts_by_campaign = PostTask::get_task_counts_by_campaigns();

		foreach ($campaigns as &$campaign) {
			$cid = (int) $campaign['id'];
			$counts = $counts_by_campaign[$cid] ?? [
				'pending' => 0,
				'processing' => 0,
				'completed' => 0,
				'failed' => 0,
				'total' => 0,
			];
			$campaign['task_counts'] = [
				'pending' => $counts['pending'],
				'processing' => $counts['processing'],
				'completed' => $counts['completed'],
				'failed' => $counts['failed'],
			];
			$campaign['tasks_total'] = $counts['total'];

			if (!empty($campaign['rss_enabled']) && $campaign['rss_enabled'] === 'yes') {
				$rss = CampaignRss::get_by_campaign($cid);
				$sources = $rss['sources'] ?? [];
				$campaign['rss_sources_count'] = is_array($sources) ? count($sources) : 0;
				$campaign['rss_frequency_interval'] = isset($rss['frequency_interval']) ? (int) $rss['frequency_interval'] : 60;
			} else {
				$campaign['rss_sources_count'] = 0;
				$campaign['rss_frequency_interval'] = null;
			}
		}
		return $campaigns;
	}

	public function get_bootstrap_data(?array $post_type_options = null, ?array $taxonomy_data = null, ?array $user_data = null): array
	{
		$static = $this->get_static_bootstrap();

		// Use overrides when provided (e.g. initial page load in enqueue); otherwise use cached static data
		$post_type_options = $post_type_options ?? $this->get_post_type_options();
		$taxonomy_data = $taxonomy_data ?? $static['taxonomies'];
		$user_data = $user_data ?? $static['users'];

		return [
			'settings' => $this->settings_service->get_settings_data(),
			'webhooks' => ['webhooks' => Webhook::get_all()],
			'campaigns' => ['campaigns' => $this->get_campaigns_with_counts()],
			'writing_presets' => WritingPreset::get_all(),
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data,
			'languages' => Languages::all(),
			'countries' => Countries::all(),
			'users' => $user_data,
			'current_user_id' => get_current_user_id(),
			'openrouter_models' => $static['openrouter_models'],
		];
	}
}
