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
            article_type varchar(50) NOT NULL DEFAULT 'blog_post',
			language varchar(20) NOT NULL DEFAULT 'en',
			target_country varchar(20) NOT NULL DEFAULT 'international',
            post_type varchar(20) NOT NULL DEFAULT 'post',
            post_status varchar(20) NOT NULL DEFAULT 'pending',
            default_author_id bigint(20) unsigned DEFAULT NULL,
			content_fields text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY author_id (author_id),
            KEY webhook_id (webhook_id),
            KEY default_author_id (default_author_id)
            ) $charset_collate;";
		$tables_created_or_updated |= self::check_and_create_table($table_name, $sql);
		self::drop_legacy_columns($table_name);

		return $tables_created_or_updated;
	}

	private static function drop_legacy_columns(string $table_name): void
	{
		global $wpdb;
		$columns = ['tone_of_voice', 'point_of_view', 'enabled_taxonomies', 'default_terms', 'post_fields', 'image_config', 'instructions'];
		foreach ($columns as $column) {
			$exists = $wpdb->get_var(
				$wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column)
			);
			if ($exists) {
				$wpdb->query("ALTER TABLE {$table_name} DROP COLUMN {$column}");
			}
		}
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

		$default_content_fields = self::get_default_content_fields();

		$data = wp_parse_args($data, [
			'title' => '',
			'author_id' => get_current_user_id(),
			'webhook_id' => null,
			'article_type' => 'blog_post',
			'language' => 'en',
			'target_country' => 'international',
			'post_type' => 'post',
			'post_status' => 'pending',
			'default_author_id' => get_current_user_id(),
			'content_fields' => json_encode($default_content_fields),
		]);

		return $wpdb->insert($table_name, $data) ? $wpdb->insert_id : false;
	}

	public static function get_default_content_fields(): array
	{
		$default_text_model = (string) get_option('poststation_openrouter_default_text_model', '');
		$default_image_model = (string) get_option('poststation_openrouter_default_image_model', '');

		return [
			'title' => [
				'enabled' => true,
				'mode' => 'generate',
				'prompt' => '',
				'prompt_context' => 'article_and_topic',
				'model_id' => $default_text_model,
			],
			'slug' => [
				'enabled' => false,
				'mode' => 'generate_from_title',
				'prompt' => '',
				'model_id' => $default_text_model,
			],
			'body' => [
				'enabled' => true,
				'mode' => 'single_prompt',
				'prompt' => '',
				'model_id' => $default_text_model,
				'media_prompt' => '',
				'image_model_id' => $default_image_model,
				'tone_of_voice' => 'none',
				'point_of_view' => 'none',
				'introductory_hook_brief' => '',
				'key_takeaways' => 'yes',
				'conclusion' => 'yes',
				'faq' => 'yes',
				'internal_linking' => 'yes',
				'external_linking' => 'yes',
				'list_numbering_format' => 'none',
				'use_descending_order' => false,
				'list_section_prompt' => '',
				'number_of_list' => '',
				'enable_media' => 'no',
				'number_of_images' => 'random',
				'custom_number_of_images' => 3,
				'image_size' => '1344x768',
				'image_style' => 'none',
			],
			'categories' => [
				'enabled' => false,
				'mode' => 'manual',
				'prompt' => '',
				'model_id' => $default_text_model,
				'selected' => [],
			],
			'tags' => [
				'enabled' => false,
				'mode' => 'generate',
				'prompt' => '',
				'model_id' => $default_text_model,
				'selected' => [],
			],
			'custom_taxonomies' => [],
			'custom_fields' => [],
			'image' => [
				'enabled' => false,
				'mode' => 'generate_from_article',
				'prompt' => '',
				'model_id' => $default_image_model,
				'image_size' => '1344x768',
				'image_style' => 'none',
				'template_id' => '',
				'category_text' => '',
				'main_text' => '',
				'category_color' => '#000000',
				'title_color' => '#000000',
				'background_images' => [],
			],
		];
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

		if (isset($data['article_type'])) {
			$update_data['article_type'] = $data['article_type'];
			$format[] = '%s';
		}

		if (isset($data['language'])) {
			$update_data['language'] = $data['language'];
			$format[] = '%s';
		}

		if (isset($data['target_country'])) {
			$update_data['target_country'] = $data['target_country'];
			$format[] = '%s';
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

		if (isset($data['content_fields'])) {
			$update_data['content_fields'] = $data['content_fields'];
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