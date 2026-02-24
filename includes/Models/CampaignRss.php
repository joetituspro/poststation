<?php

namespace PostStation\Models;

class CampaignRss
{
	private const TABLE_NAME = 'poststation_campaign_rss';

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
			campaign_id bigint(20) unsigned NOT NULL,
			frequency_interval int unsigned NOT NULL DEFAULT 60,
			sources longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY campaign_id (campaign_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result);
	}

	public static function get_by_campaign(int $campaign_id): ?array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table_name} WHERE campaign_id = %d", $campaign_id),
			ARRAY_A
		);
		if (!$row) {
			return null;
		}
		if (!empty($row['sources']) && is_string($row['sources'])) {
			$decoded = json_decode($row['sources'], true);
			$row['sources'] = is_array($decoded) ? $decoded : [];
		} else {
			$row['sources'] = [];
		}
		return $row;
	}

	public static function save(int $campaign_id, int $frequency_interval, array $sources): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$existing = self::get_by_campaign($campaign_id);
		$sources_json = wp_json_encode($sources);

		if ($existing) {
			return (bool) $wpdb->update(
				$table_name,
				[
					'frequency_interval' => $frequency_interval,
					'sources' => $sources_json,
				],
				['campaign_id' => $campaign_id],
				['%d', '%s'],
				['%d']
			);
		}

		return (bool) $wpdb->insert(
			$table_name,
			[
				'campaign_id' => $campaign_id,
				'frequency_interval' => $frequency_interval,
				'sources' => $sources_json,
			],
			['%d', '%d', '%s']
		);
	}

	public static function delete_by_campaign(int $campaign_id): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->delete($table_name, ['campaign_id' => $campaign_id], ['%d']) !== false;
	}

	public static function drop_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
	}
}
