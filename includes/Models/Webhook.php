<?php

namespace PostStation\Models;

class Webhook
{
	private const TABLE_NAME = 'poststation_webhooks';

	public static function create_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			url varchar(2083) NOT NULL,
			author_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY author_id (author_id)
		) {$charset_collate};";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
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

		$result = $wpdb->insert(
			$table_name,
			[
				'name' => $data['name'],
				'url' => $data['url'],
				'author_id' => get_current_user_id(),
			],
			['%s', '%s', '%d']
		);

		return $result ? $wpdb->insert_id : false;
	}

	public static function update(int $id, array $data): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->update(
			$table_name,
			[
				'name' => $data['name'],
				'url' => $data['url'],
			],
			['id' => $id],
			['%s', '%s'],
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
