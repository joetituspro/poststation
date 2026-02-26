<?php

namespace PostStation\Services;

class Sitemap
{
	private const CACHE_PREFIX = 'poststation_sitemap_';
	private const CACHE_EXPIRATION = DAY_IN_SECONDS;

	public function init(): void
	{
		add_action('save_post', [$this, 'clear_sitemap_cache'], 10, 3);
		add_action('deleted_post', [$this, 'clear_sitemap_cache']);
	}

	public function get_sitemap_json(string $post_type): array
	{
		$cache_key = self::CACHE_PREFIX . $post_type;
		$sitemap = get_transient($cache_key);

		if (false === $sitemap) {
			$args = [
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			];

			$posts = get_posts($args);
			$sitemap = [];

			foreach ($posts as $post) {
				$sitemap[] = [
					'url'          => get_permalink($post->ID),
					'title'        => $post->post_title,
					'publish_date' => $post->post_date,
				];
			}

			set_transient($cache_key, $sitemap, self::CACHE_EXPIRATION);
		}

		return $sitemap;
	}

	/**
	 * Get sitemap entries for all public post types (merged).
	 */
	public function get_sitemap_json_all_public(): array
	{
		$cache_key = self::CACHE_PREFIX . 'all_public';
		$sitemap = get_transient($cache_key);

		if (false === $sitemap) {
			$post_types = $this->get_public_post_types_for_links();
			$sitemap = [];
			foreach ($post_types as $post_type) {
				$sitemap = array_merge($sitemap, $this->get_sitemap_json($post_type));
			}
			set_transient($cache_key, $sitemap, self::CACHE_EXPIRATION);
		}

		return $sitemap;
	}

	/**
	 * Get sitemap entries filtered by taxonomy + term IDs.
	 * This is not cached because there can be many term combinations.
	 */
	public function get_sitemap_json_by_taxonomy_terms(string $taxonomy, array $term_ids): array
	{
		$taxonomy = sanitize_key($taxonomy);
		$term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids), static fn($id) => $id > 0)));

		if ($taxonomy === '' || empty($term_ids) || !taxonomy_exists($taxonomy)) {
			return [];
		}

		$args = [
			'post_type'      => $this->get_public_post_types_for_links(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'tax_query'      => [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_ids,
				],
			],
		];

		$posts = get_posts($args);
		$sitemap = [];

		foreach ($posts as $post) {
			$sitemap[] = [
				'url'          => get_permalink($post->ID),
				'title'        => $post->post_title,
				'publish_date' => $post->post_date,
			];
		}

		return $sitemap;
	}

	/**
	 * Public post types used for internal-link source pools.
	 */
	private function get_public_post_types_for_links(): array
	{
		$post_types = get_post_types(['public' => true], 'names');
		if (isset($post_types['page'])) {
			unset($post_types['page']);
		}
		return array_values($post_types);
	}

	public function clear_sitemap_cache($post_id, $post = null, $update = null): void
	{
		if ($post === null) {
			$post = get_post($post_id);
		}

		if (!$post) {
			return;
		}

		delete_transient(self::CACHE_PREFIX . $post->post_type);
		delete_transient(self::CACHE_PREFIX . 'all_public');
	}
}
