<?php

namespace PostStation\Models;

class PostTask
{
	public const DB_VERSION = '3.0';
	protected const TABLE_NAME = 'poststation_posttasks';
	private const LEGACY_TABLE_NAME = 'poststation_postblocks';

	public static function get_table_name(): string
	{
		return self::TABLE_NAME;
	}

	public static function update_tables(): bool
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$legacy_table_name = $wpdb->prefix . self::LEGACY_TABLE_NAME;
		$tables_created_or_updated = false;

		if (self::table_exists($legacy_table_name) && !self::table_exists($table_name)) {
			$wpdb->query("RENAME TABLE {$legacy_table_name} TO {$table_name}");
		}

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			article_url text,
			research_url text,
			topic text,
			keywords text,
			article_type varchar(50) NOT NULL DEFAULT 'blog_post',
			feature_image_id bigint(20) unsigned DEFAULT NULL,
			feature_image_title text DEFAULT NULL,
			run_started_at datetime DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'pending',
			progress text DEFAULT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id),
			KEY status (status),
			KEY post_id (post_id),
			KEY feature_image_id (feature_image_id)
		) $charset_collate;";
		$tables_created_or_updated |= self::check_and_create_table($table_name, $sql);

		self::migrate_legacy_columns($table_name);
		self::drop_legacy_columns($table_name);
		return (bool) $tables_created_or_updated;
	}

	private static function table_exists(string $table_name): bool
	{
		global $wpdb;
		$result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
		return is_string($result) && $result === $table_name;
	}

	private static function check_and_create_table($table_name, $sql): bool
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result) && !empty($result);
	}

	private static function migrate_legacy_columns(string $table_name): void
	{
		global $wpdb;
		$campaign_id_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'campaign_id'");
		$postwork_id_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'postwork_id'");
		if (!$campaign_id_exists && $postwork_id_exists) {
			$wpdb->query("ALTER TABLE {$table_name} CHANGE postwork_id campaign_id bigint(20) unsigned NOT NULL");
			$wpdb->query("ALTER TABLE {$table_name} ADD KEY campaign_id (campaign_id)");
		}
	}

	private static function drop_legacy_columns(string $table_name): void
	{
		global $wpdb;
		$columns = ['tone_of_voice', 'point_of_view', 'keyword', 'instructions', 'taxonomies', 'post_fields'];
		foreach ($columns as $column) {
			$exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column));
			if ($exists) {
				$wpdb->query("ALTER TABLE {$table_name} DROP COLUMN {$column}");
			}
		}
	}

	public static function drop_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
		delete_option('poststation_posttask_db_version');
	}

	public static function get_by_campaign(int $campaign_id): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE campaign_id = %d ORDER BY FIELD(status, 'processing', 'pending', 'failed', 'completed') ASC, created_at DESC",
				$campaign_id
			),
			ARRAY_A
		);
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
		$data = wp_parse_args($data, [
			'campaign_id' => 0,
			'article_url' => '',
			'research_url' => '',
			'topic' => '',
			'keywords' => '',
			'article_type' => 'blog_post',
			'feature_image_id' => null,
			'feature_image_title' => '{{title}}',
			'status' => 'pending',
		]);

		$data['article_url'] = $data['article_url'] ?? '';
		$data['research_url'] = $data['research_url'] ?? '';
		$data['topic'] = $data['topic'] ?? '';
		$data['keywords'] = $data['keywords'] ?? '';
		return $wpdb->insert($table_name, $data) ? $wpdb->insert_id : false;
	}

	public static function update(int $id, array $data): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return (bool) $wpdb->update($table_name, $data, ['id' => $id]);
	}

	public static function delete(int $id): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->delete($table_name, ['id' => $id], ['%d']) !== false;
	}

	public static function delete_by_campaign(int $campaign_id): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->delete($table_name, ['campaign_id' => $campaign_id], ['%d']) !== false;
	}
}
