<?php

namespace PostStation\Models;

class PostTask
{
	public const DB_VERSION = '3.9';
	protected const TABLE_NAME = 'poststation_posttasks';

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
			campaign_id bigint(20) unsigned NOT NULL,
			article_url text,
			research_url text,
			topic text,
			keywords text,
			campaign_type varchar(50) NOT NULL DEFAULT 'default',
			title_override text DEFAULT NULL,
			slug_override text DEFAULT NULL,
			feature_image_id bigint(20) unsigned DEFAULT NULL,
			feature_image_title text DEFAULT NULL,
			run_started_at datetime DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'pending',
			progress text DEFAULT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			error_message text DEFAULT NULL,
			execution_id varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id),
			KEY status (status),
			KEY post_id (post_id),
			KEY feature_image_id (feature_image_id),
			KEY execution_id (execution_id)
		) $charset_collate;";
		$tables_created_or_updated |= self::check_and_create_table($table_name, $sql);
		self::migrate_article_type_to_campaign_type($table_name);
		return (bool) $tables_created_or_updated;
	}

	private static function check_and_create_table($table_name, $sql): bool
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result) && !empty($result);
	}

	private static function migrate_article_type_to_campaign_type(string $table_name): void
	{
		global $wpdb;
		$col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'article_type'", ARRAY_A);
		if (empty($col)) {
			return;
		}
		$wpdb->query("ALTER TABLE `{$table_name}` CHANGE COLUMN `article_type` `campaign_type` varchar(50) NOT NULL DEFAULT 'default'");
	}

	public static function drop_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
		delete_option('poststation_posttask_db_version');
	}

	/**
	 * Get task counts per campaign in a single query (avoids N+1).
	 *
	 * @return array<int, array{pending: int, processing: int, completed: int, failed: int, total: int}>
	 */
	public static function get_task_counts_by_campaigns(): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$rows = $wpdb->get_results(
			"SELECT campaign_id, status, COUNT(*) AS cnt FROM {$table_name} GROUP BY campaign_id, status",
			ARRAY_A
		);

		$statuses = ['pending', 'processing', 'completed', 'failed'];
		$by_campaign = [];
		foreach ($rows as $row) {
			$cid = (int) $row['campaign_id'];
			if (!isset($by_campaign[$cid])) {
				$by_campaign[$cid] = array_fill_keys($statuses, 0);
				$by_campaign[$cid]['total'] = 0;
			}
			$cnt = (int) $row['cnt'];
			$status = $row['status'] ?? 'pending';
			if (in_array($status, $statuses, true)) {
				$by_campaign[$cid][$status] += $cnt;
			} else {
				$by_campaign[$cid]['pending'] += $cnt;
			}
			$by_campaign[$cid]['total'] += $cnt;
		}
		return $by_campaign;
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

	/**
	 * Get the latest task by execution_id (highest id). Used when progress is sent with execution_id only.
	 */
	public static function get_latest_by_execution_id(string $execution_id): ?array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$execution_id = trim($execution_id);
		if ($execution_id === '') {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE execution_id = %s ORDER BY id DESC LIMIT 1",
				$execution_id
			),
			ARRAY_A
		);
	}

	/**
	 * Generate a task id under 8 digits (ms % 1e7 * 10 + random 0-9). Suitable for bigint unsigned.
	 */
	public static function generate_id(): int
	{
		$ms = (int) round(microtime(true) * 1000);
		$n = ($ms % 10000000) * 10 + random_int(0, 9);
		return $n > 0 ? $n : 1;
	}

	public static function create(array $data): int|false
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$provided_id = isset($data['id']) ? (int) $data['id'] : null;
		unset($data['id']);
		$data = wp_parse_args($data, [
			'campaign_id' => 0,
			'article_url' => '',
			'research_url' => '',
			'topic' => '',
			'keywords' => '',
			'campaign_type' => 'default',
			'title_override' => '',
			'slug_override' => '',
			'feature_image_id' => null,
			'feature_image_title' => '{{title}}',
			'status' => 'pending',
		]);
		if ($provided_id > 0) {
			$data['id'] = $provided_id;
		}
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
