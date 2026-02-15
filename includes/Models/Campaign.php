<?php

namespace PostStation\Models;

class Campaign
{
	private const TABLE_NAME = 'poststation_campaigns';

	public static function get_table_name(): string
	{
		return self::TABLE_NAME;
	}

	public static function update_tables(): bool
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$tables_created_or_updated = false;

		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            author_id bigint(20) unsigned NOT NULL,
            webhook_id bigint(20) unsigned DEFAULT NULL,
            article_type varchar(50) NOT NULL DEFAULT 'blog_post',
			tone_of_voice varchar(50) NOT NULL DEFAULT 'none',
			point_of_view varchar(50) NOT NULL DEFAULT 'none',
			readability varchar(50) NOT NULL DEFAULT 'grade_8',
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
		return (bool) $tables_created_or_updated;
	}

	private static function check_and_create_table($table_name, $sql): bool
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result) && !empty($result);
	}

	public static function get_all(): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A);
	}

	public static function get_by_id(int $id): ?array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id), ARRAY_A);
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
			'tone_of_voice' => 'none',
			'point_of_view' => 'none',
			'readability' => 'grade_8',
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
				'enabled' => true,
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

		foreach (['title' => '%s', 'webhook_id' => '%d', 'article_type' => '%s', 'tone_of_voice' => '%s', 'point_of_view' => '%s', 'readability' => '%s', 'language' => '%s', 'target_country' => '%s', 'post_type' => '%s', 'post_status' => '%s', 'default_author_id' => '%d', 'content_fields' => '%s'] as $key => $type) {
			if (isset($data[$key])) {
				$update_data[$key] = $data[$key];
				$format[] = $type;
			}
		}

		if (empty($update_data)) {
			return false;
		}

		$result = $wpdb->update($table_name, $update_data, ['id' => $id], $format, ['%d']);
		return $result !== false;
	}

	public static function delete(int $id): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->delete($table_name, ['id' => $id], ['%d']) !== false;
	}

	public static function drop_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
	}
}
