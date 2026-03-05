<?php

namespace PostStation\Models;

class WorkflowSpec
{
	private const TABLE_NAME = 'poststation_workflow_specs';

	public static function get_table_name(): string
	{
		return self::TABLE_NAME;
	}

	public static function update_tables(): bool
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version varchar(50) NOT NULL,
			source_hash varchar(100) NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			spec_json longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY version (version),
			KEY is_active (is_active)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result) && !empty($result);
	}

	public static function get_active(): ?array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->get_row("SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", ARRAY_A);
	}

	public static function deactivate_all(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("UPDATE {$table} SET is_active = 0");
	}

	public static function activate(int $id): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		self::deactivate_all();
		return (bool) $wpdb->update($table, ['is_active' => 1], ['id' => $id], ['%d'], ['%d']);
	}

	public static function upsert_active(string $version, string $source_hash, array $spec): int|false
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$encoded = wp_json_encode($spec);
		if (!is_string($encoded) || $encoded === '') {
			return false;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE version = %s AND source_hash = %s ORDER BY id DESC LIMIT 1",
				$version,
				$source_hash
			),
			ARRAY_A
		);

		self::deactivate_all();

		if ($existing && !empty($existing['id'])) {
			$id = (int) $existing['id'];
			$wpdb->update(
				$table,
				[
					'spec_json' => $encoded,
					'is_active' => 1,
				],
				['id' => $id],
				['%s', '%d'],
				['%d']
			);
			return $id;
		}

		$ok = $wpdb->insert(
			$table,
			[
				'version' => sanitize_text_field($version),
				'source_hash' => sanitize_text_field($source_hash),
				'is_active' => 1,
				'spec_json' => $encoded,
			],
			['%s', '%s', '%d', '%s']
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}
}

