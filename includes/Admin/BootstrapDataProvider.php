<?php

namespace PostStation\Admin;

use PostStation\Models\Campaign;
use PostStation\Models\PostTask;
use PostStation\Models\Webhook;
use PostStation\Services\SettingsService;
use PostStation\Utils\Countries;
use PostStation\Utils\Languages;

class BootstrapDataProvider
{
	private SettingsService $settings_service;

	public function __construct(?SettingsService $settings_service = null)
	{
		$this->settings_service = $settings_service ?? new SettingsService();
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
					'name' => $term_obj->name ?? '',
					'slug' => $term_obj->slug ?? '',
				];
			}

			$taxonomy_data[$taxonomy->name] = [
				'label' => $taxonomy->labels->name ?? $taxonomy->name,
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
		foreach ($campaigns as &$campaign) {
			$tasks = PostTask::get_by_campaign((int) $campaign['id']);
			$counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
			foreach ($tasks as $task) {
				$status = $task['status'] ?? 'pending';
				if (isset($counts[$status])) {
					$counts[$status]++;
				}
			}
			$campaign['task_counts'] = $counts;
			$campaign['tasks_total'] = count($tasks);
		}
		return $campaigns;
	}

	public function get_bootstrap_data(?array $post_type_options = null, ?array $taxonomy_data = null, ?array $user_data = null): array
	{
		$post_type_options = $post_type_options ?? $this->get_post_type_options();
		$taxonomy_data = $taxonomy_data ?? $this->get_taxonomy_data();
		$user_data = $user_data ?? $this->get_user_data();

		return [
			'settings' => $this->settings_service->get_settings_data(),
			'webhooks' => ['webhooks' => Webhook::get_all()],
			'campaigns' => ['campaigns' => $this->get_campaigns_with_counts()],
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data,
			'languages' => Languages::all(),
			'countries' => Countries::all(),
			'users' => $user_data,
			'current_user_id' => get_current_user_id(),
			'openrouter_models' => $this->settings_service->get_openrouter_service()->get_models(false, true),
		];
	}
}
