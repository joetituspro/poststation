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

	public function clear_sitemap_cache($post_id, $post = null, $update = null): void
	{
		if ($post === null) {
			$post = get_post($post_id);
		}

		if (!$post) {
			return;
		}

		delete_transient(self::CACHE_PREFIX . $post->post_type);
	}
}
