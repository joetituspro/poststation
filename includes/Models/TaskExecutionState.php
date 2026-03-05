<?php

namespace PostStation\Models;

class TaskExecutionState
{
	private const TABLE_NAME = 'poststation_task_execution_state';

	public static function get_table_name(): string
	{
		return self::TABLE_NAME;
	}

	public static function update_tables(): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			task_id bigint(20) unsigned NOT NULL,
			campaign_id bigint(20) unsigned NOT NULL,
			mode varchar(20) NOT NULL DEFAULT 'local',
			status varchar(20) NOT NULL DEFAULT 'running',
			current_step varchar(50) NOT NULL DEFAULT '',
			next_step varchar(50) NOT NULL DEFAULT '',
			attempt_count int(11) NOT NULL DEFAULT 0,
			max_attempts int(11) NOT NULL DEFAULT 3,
			step_started_at datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			payload_fingerprint varchar(64) NOT NULL DEFAULT '',
			payload_json longtext NOT NULL,
			context_json longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY task_id (task_id),
			KEY campaign_id (campaign_id),
			KEY status (status),
			KEY campaign_status (campaign_id, status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		return !is_wp_error($result) && !empty($result);
	}

	public static function get_by_task_id(int $task_id): ?array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE task_id = %d LIMIT 1", $task_id),
			ARRAY_A
		);
		return is_array($row) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $context
	 */
	public static function upsert_running_state(
		int $task_id,
		int $campaign_id,
		string $current_step,
		string $next_step,
		array $payload,
		array $context,
		string $payload_fingerprint,
		int $max_attempts = 3
	): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$now = current_time('mysql');
		$payload_json = wp_json_encode($payload) ?: '{}';
		$context_json = wp_json_encode($context) ?: '{}';
		$max_attempts = max(1, $max_attempts);
		$current_step = sanitize_key($current_step);
		$next_step = sanitize_key($next_step);
		$payload_fingerprint = sanitize_text_field($payload_fingerprint);

		// Atomic upsert to avoid duplicate-key races when multiple ticks dispatch at once.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
			(task_id, campaign_id, mode, status, current_step, next_step, attempt_count, max_attempts, step_started_at, last_error, payload_fingerprint, payload_json, context_json)
			VALUES (%d, %d, %s, %s, %s, %s, %d, %d, %s, NULL, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				campaign_id = VALUES(campaign_id),
				mode = VALUES(mode),
				status = VALUES(status),
				current_step = VALUES(current_step),
				next_step = VALUES(next_step),
				attempt_count = VALUES(attempt_count),
				max_attempts = VALUES(max_attempts),
				step_started_at = VALUES(step_started_at),
				last_error = VALUES(last_error),
				payload_fingerprint = VALUES(payload_fingerprint),
				payload_json = VALUES(payload_json),
				context_json = VALUES(context_json)",
			$task_id,
			$campaign_id,
			'local',
			'running',
			$current_step,
			$next_step,
			0,
			$max_attempts,
			$now,
			$payload_fingerprint,
			$payload_json,
			$context_json
		);

		return $wpdb->query($sql) !== false;
	}

	public static function mark_step_started(int $task_id, string $step): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		return (bool) $wpdb->update(
			$table,
			[
				'status' => 'running',
				'current_step' => sanitize_key($step),
				'step_started_at' => current_time('mysql'),
				'last_error' => null,
			],
			['task_id' => $task_id],
			['%s', '%s', '%s', '%s'],
			['%d']
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function mark_step_succeeded_and_advance(int $task_id, string $current_step, string $next_step, array $context): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$context_json = wp_json_encode($context) ?: '{}';
		return (bool) $wpdb->update(
			$table,
			[
				'status' => 'running',
				'current_step' => sanitize_key($current_step),
				'next_step' => sanitize_key($next_step),
				'attempt_count' => 0,
				'last_error' => null,
				'context_json' => $context_json,
				'step_started_at' => current_time('mysql'),
			],
			['task_id' => $task_id],
			['%s', '%s', '%s', '%d', '%s', '%s', '%s'],
			['%d']
		);
	}

	/**
	 * Persist in-step context progress without advancing step pointers.
	 *
	 * @param array<string,mixed> $context
	 */
	public static function update_context_snapshot(int $task_id, array $context): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$context_json = wp_json_encode($context) ?: '{}';

		return (bool) $wpdb->update(
			$table,
			[
				'context_json' => $context_json,
				'updated_at' => current_time('mysql'),
			],
			['task_id' => $task_id],
			['%s', '%s'],
			['%d']
		);
	}

	public static function mark_step_failed_attempt(int $task_id, string $step, string $error): ?array
	{
		$state = self::get_by_task_id($task_id);
		if (!$state) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$attempt = ((int) ($state['attempt_count'] ?? 0)) + 1;
		$max = max(1, (int) ($state['max_attempts'] ?? 3));
		$status = $attempt >= $max ? 'failed' : 'running';

		$wpdb->update(
			$table,
			[
				'status' => $status,
				'current_step' => sanitize_key($step),
				'attempt_count' => $attempt,
				'last_error' => $error,
				'step_started_at' => current_time('mysql'),
			],
			['task_id' => $task_id],
			['%s', '%s', '%d', '%s', '%s'],
			['%d']
		);

		return self::get_by_task_id($task_id);
	}

	public static function mark_terminal(int $task_id, string $status, ?string $error = null): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		return (bool) $wpdb->update(
			$table,
			[
				'status' => sanitize_key($status),
				'last_error' => $error,
			],
			['task_id' => $task_id],
			['%s', '%s'],
			['%d']
		);
	}

	public static function reset_for_retry(int $task_id): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		return (bool) $wpdb->update(
			$table,
			[
				'status' => 'running',
				'attempt_count' => 0,
				'last_error' => null,
				'step_started_at' => current_time('mysql'),
			],
			['task_id' => $task_id],
			['%s', '%d', '%s', '%s'],
			['%d']
		);
	}

	public static function delete_by_task_id(int $task_id): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->delete($table, ['task_id' => $task_id], ['%d']) !== false;
	}

	public static function delete_by_campaign_id(int $campaign_id): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		return $wpdb->delete($table, ['campaign_id' => $campaign_id], ['%d']) !== false;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_by_campaign_id(int $campaign_id): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM {$table} WHERE campaign_id = %d", $campaign_id),
			ARRAY_A
		);
		return is_array($rows) ? $rows : [];
	}

	public static function delete_orphans(): void
	{
		global $wpdb;
		$state_table = $wpdb->prefix . self::TABLE_NAME;
		$task_table = $wpdb->prefix . PostTask::get_table_name();
		$wpdb->query(
			"DELETE s FROM {$state_table} s
			LEFT JOIN {$task_table} t ON t.id = s.task_id
			WHERE t.id IS NULL"
		);
	}
}
