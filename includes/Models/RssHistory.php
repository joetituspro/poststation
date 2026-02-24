<?php

namespace PostStation\Models;

class RssHistory
{
	private const TABLE_NAME = 'poststation_rss_history';

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
			source_id varchar(64) NOT NULL,
			article_url text NOT NULL,
			title text DEFAULT NULL,
			publication_date date DEFAULT NULL,
			run_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY campaign_article (campaign_id, article_url(191)),
			KEY campaign_run (campaign_id, run_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result);
	}

	/**
	 * Last run time for the campaign (timestamp of most recent run).
	 * Used to enforce frequency_interval between RSS runs.
	 *
	 * @return int|null Unix timestamp of last run, or null if never run.
	 */
	public static function get_last_run_time_for_campaign(int $campaign_id): ?int
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$max_created = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$table_name} WHERE campaign_id = %d",
				$campaign_id
			)
		);
		if ($max_created === null || $max_created === '') {
			return null;
		}
		$ts = strtotime($max_created);
		return $ts ? $ts : null;
	}

	/**
	 * Record that an RSS run occurred for the campaign (e.g. webhook returned 200 but no items).
	 * Ensures get_last_run_time_for_campaign() advances so the next run respects the interval.
	 */
	public static function record_run(int $campaign_id): void
	{
		$run_id = time();
		self::insert_batch([[
			'campaign_id' => $campaign_id,
			'source_id' => '0',
			'article_url' => '__rss_run__',
			'title' => null,
			'publication_date' => null,
			'run_id' => $run_id,
		]]);
	}

	/**
	 * @return array<string> Set of article URLs already processed for this campaign.
	 */
	public static function get_processed_urls_for_campaign(int $campaign_id): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT article_url FROM {$table_name} WHERE campaign_id = %d AND article_url != %s",
				$campaign_id,
				'__rss_run__'
			)
		);
		return is_array($rows) ? array_map('strval', $rows) : [];
	}

	/**
	 * @param array<int, array{campaign_id: int, source_id: int|string, article_url: string, title?: string, publication_date?: string, run_id: int}> $rows
	 */
	public static function insert_batch(array $rows): void
	{
		if (empty($rows)) {
			return;
		}
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$values = [];
		$placeholders = [];
		foreach ($rows as $row) {
			$values[] = $row['campaign_id'];
			$values[] = $row['source_id'];
			$values[] = $row['article_url'];
			$values[] = $row['title'] ?? '';
			$values[] = $row['publication_date'] ?? null;
			$values[] = $row['run_id'];
			$placeholders[] = '(%d, %s, %s, %s, %s, %d)';
		}
		$sql = "INSERT INTO {$table_name} (campaign_id, source_id, article_url, title, publication_date, run_id) VALUES ";
		$sql .= implode(', ', $placeholders);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from row count
		$wpdb->query($wpdb->prepare($sql, $values));
	}

	/**
	 * Keep only the last N distinct runs per campaign; delete older run_id batches.
	 */
	public static function prune_keep_last_runs(int $campaign_id, int $keep = 3): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$run_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT run_id FROM {$table_name} WHERE campaign_id = %d ORDER BY run_id DESC LIMIT %d",
			$campaign_id,
			$keep
		));
		if (!is_array($run_ids) || count($run_ids) === 0) {
			return;
		}
		$placeholders = implode(',', array_fill(0, count($run_ids), '%d'));
		$args = array_merge([$campaign_id], array_map('intval', $run_ids));
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders from run_ids count
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$table_name} WHERE campaign_id = %d AND run_id NOT IN ($placeholders)",
			$args
		));
	}

	public static function drop_table(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
	}
}
