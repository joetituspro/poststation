<?php

namespace PostStation\Models;

class PostBlock
{
	protected const TABLE_NAME = 'poststation_postblocks';

	public static function get_table_name(): string
	{
		return self::TABLE_NAME;
	}

	public static function update_tables(): bool
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$tables_created_or_updated = false;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			postwork_id bigint(20) unsigned NOT NULL,
			article_url text NOT NULL,
			taxonomies text DEFAULT NULL,
			custom_fields text DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'pending',
			post_id bigint(20) unsigned DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY postwork_id (postwork_id),
			KEY status (status),
			KEY post_id (post_id)
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

	/**
	 * Drop the table when uninstalling the plugin
	 */
	public static function drop_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
		delete_option('poststation_postblock_db_version');
	}

	public static function get_by_postwork(int $postwork_id): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE postwork_id = %d ORDER BY created_at ASC",
				$postwork_id
			),
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
		$table_name = $wpdb->prefix . self::get_table_name();

		$data = wp_parse_args($data, [
			'postwork_id' => 0,
			'article_url' => '',
			'taxonomies' => '{}',
			'status' => 'pending'
		]);

		return $wpdb->insert($table_name, $data) ? $wpdb->insert_id : false;
	}

	public static function update(int $id, array $data): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::get_table_name();

		// If taxonomies is provided as array, convert to JSON
		if (isset($data['taxonomies']) && is_array($data['taxonomies'])) {
			$data['taxonomies'] = wp_json_encode($data['taxonomies']);
		}

		return (bool)$wpdb->update($table_name, $data, ['id' => $id]);
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

	public static function delete_by_postwork(int $postwork_id): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		return $wpdb->delete(
			$table_name,
			['postwork_id' => $postwork_id],
			['%d']
		) !== false;
	}
}