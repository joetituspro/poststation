<?php

namespace PostStation\Models;

class PostWork
{
	private const TABLE_NAME = 'poststation_postworks';

	public static function update_tables(): bool
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$tables_created_or_updated = false;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            author_id bigint(20) unsigned NOT NULL,
            webhook_id bigint(20) unsigned DEFAULT NULL,
            post_type varchar(20) NOT NULL DEFAULT 'post',
            post_status varchar(20) NOT NULL DEFAULT 'pending',
            default_author_id bigint(20) unsigned DEFAULT NULL,
            enabled_taxonomies text DEFAULT NULL,
            default_terms text DEFAULT NULL,
			post_fields text DEFAULT NULL,
			image_config text DEFAULT NULL,
			content_fields text DEFAULT NULL,
			tone_of_voice varchar(100) DEFAULT NULL,
			point_of_view varchar(100) DEFAULT NULL,
			instructions text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY author_id (author_id),
            KEY webhook_id (webhook_id),
            KEY default_author_id (default_author_id)
            ) $charset_collate;";
		$tables_created_or_updated |= self::check_and_create_table($table_name, $sql);

		return $tables_created_or_updated;
	}

	private static function check_and_create_table($table_name, $sql)
	{
		global $wpdb;
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		$result = dbDelta($sql);
		// Check if dbDelta executed without errors
		if (is_wp_error($result) || empty($result)) {
			return false;
		}
		return true;
	}

	public static function get_all(): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_results(
			"SELECT * FROM {$table_name} ORDER BY created_at DESC",
			ARRAY_A
		);
	}

	public static function get_by_id(int $id): ?array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
			ARRAY_A
		);
	}

	public static function create(array $data): int|false
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$default_post_fields = [
			'slug' => [
				'value' => '',
				'prompt' => '',
				'type' => 'string',
				'required' => false
			],
			'title' => [
				'value' => '',
				'prompt' => 'Generate a clear and engaging title for this article',
				'type' => 'string',
				'required' => true
			],
			'content' => [
				'value' => '',
				'prompt' => 'Generate comprehensive content for this article',
				'type' => 'string',
				'required' => true
			]
		];

		// Default content fields structure
		$default_content_fields = [
			'title' => [
				'enabled' => true,
				'mode' => 'generate_from_topic',
				'prompt' => '',
			],
			'body' => [
				'enabled' => true,
				'mode' => 'single_prompt',
				'prompt' => '',
			],
			'categories' => [
				'enabled' => false,
				'mode' => 'manual',
				'prompt' => '',
				'selected' => [],
			],
			'tags' => [
				'enabled' => false,
				'mode' => 'generate',
				'prompt' => '',
				'selected' => [],
			],
			'custom_taxonomies' => [],
			'custom_fields' => [],
			'image' => [
				'enabled' => false,
				'mode' => 'generate_from_title',
				'prompt' => '',
				'template_id' => '',
				'category_text' => '',
				'main_text' => '',
				'category_color' => '#000000',
				'title_color' => '#000000',
				'background_images' => [],
			],
		];

		$data = wp_parse_args($data, [
			'title' => '',
			'author_id' => get_current_user_id(),
			'webhook_id' => null,
			'post_type' => 'post',
			'post_status' => 'pending',
			'default_author_id' => get_current_user_id(),
			'enabled_taxonomies' => json_encode([]),
			'default_terms' => json_encode([]),
			'post_fields' => json_encode($default_post_fields),
			'image_config' => json_encode([]),
			'content_fields' => json_encode($default_content_fields),
			'tone_of_voice' => 'seo_optimized',
			'point_of_view' => 'third_person',
			'instructions' => ''
		]);

		return $wpdb->insert($table_name, $data) ? $wpdb->insert_id : false;
	}

	public static function update(int $id, array $data): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$update_data = [];
		$format = [];

		if (isset($data['title'])) {
			$update_data['title'] = $data['title'];
			$format[] = '%s';
		}

		if (isset($data['webhook_id'])) {
			$update_data['webhook_id'] = $data['webhook_id'];
			$format[] = '%d';
		}

		if (isset($data['post_type'])) {
			$update_data['post_type'] = $data['post_type'];
			$format[] = '%s';
		}

		if (isset($data['post_status'])) {
			$update_data['post_status'] = $data['post_status'];
			$format[] = '%s';
		}

		if (isset($data['default_author_id'])) {
			$update_data['default_author_id'] = $data['default_author_id'];
			$format[] = '%d';
		}

		if (isset($data['enabled_taxonomies'])) {
			$update_data['enabled_taxonomies'] = $data['enabled_taxonomies'];
			$format[] = '%s';
		}

		if (isset($data['default_terms'])) {
			$update_data['default_terms'] = $data['default_terms'];
			$format[] = '%s';
		}

		if (isset($data['post_fields'])) {
			$update_data['post_fields'] = $data['post_fields'];
			$format[] = '%s';
		}

		if (isset($data['image_config'])) {
			$update_data['image_config'] = $data['image_config'];
			$format[] = '%s';
		}

		if (isset($data['content_fields'])) {
			$update_data['content_fields'] = $data['content_fields'];
			$format[] = '%s';
		}

		if (isset($data['tone_of_voice'])) {
			$update_data['tone_of_voice'] = $data['tone_of_voice'];
			$format[] = '%s';
		}

		if (isset($data['point_of_view'])) {
			$update_data['point_of_view'] = $data['point_of_view'];
			$format[] = '%s';
		}

		if (isset($data['instructions'])) {
			$update_data['instructions'] = $data['instructions'];
			$format[] = '%s';
		}

		if (empty($update_data)) {
			return false;
		}

		$result = $wpdb->update(
			$table_name,
			$update_data,
			['id' => $id],
			$format,
			['%d']
		);

		return $result !== false;
	}

	public static function delete(int $id): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->delete(
			$table_name,
			['id' => $id],
			['%d']
		) !== false;
	}
}