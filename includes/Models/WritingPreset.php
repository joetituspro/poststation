<?php

namespace PostStation\Models;

class WritingPreset
{
	private const TABLE_NAME = 'poststation_writing_presets';

	/** Keys that are seeded by default; key and name cannot be modified for these. */
	public const DEFAULT_KEYS = ['listicle', 'news', 'guide', 'howto'];

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
            `key` varchar(50) NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            instructions text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY writing_preset_key (`key`)
            ) $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta($sql);
		$tables_created_or_updated = !is_wp_error($result) && !empty($result);
		self::migrate_instructions_table($table_name);
		return (bool) $tables_created_or_updated;
	}

	private static function migrate_instructions_table(string $table_name): void
	{
		global $wpdb;
		$old_table = $wpdb->prefix . 'poststation_instructions';
		if ($old_table === $table_name) {
			return;
		}
		$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $old_table));
		if (!$exists) {
			return;
		}
		$rows = $wpdb->get_results("SELECT id, `key`, name, description, instructions, created_at, updated_at FROM {$old_table}", ARRAY_A);
		if (!empty($rows)) {
			foreach ($rows as $row) {
				$wpdb->replace($table_name, $row);
			}
		}
	}

	public static function get_all(): array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$rows = $wpdb->get_results("SELECT id, `key`, name, description, instructions FROM {$table_name} ORDER BY id ASC", ARRAY_A);
		if (!is_array($rows)) {
			return [];
		}
		foreach ($rows as &$row) {
			if (isset($row['instructions']) && is_string($row['instructions'])) {
				$decoded = json_decode($row['instructions'], true);
				if (!is_array($decoded)) {
					$decoded = [];
				}
				$row['instructions'] = [
					'title' => (string) ($decoded['title'] ?? ''),
					'body' => (string) ($decoded['body'] ?? ''),
				];
			}
		}
		return $rows;
	}

	public static function get_by_id(int $id): ?array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id), ARRAY_A);
		if (!$row) {
			return null;
		}
		if (isset($row['instructions']) && is_string($row['instructions'])) {
			$decoded = json_decode($row['instructions'], true);
			if (!is_array($decoded)) {
				$decoded = [];
			}
			$row['instructions'] = [
				'title' => (string) ($decoded['title'] ?? ''),
				'body' => (string) ($decoded['body'] ?? ''),
			];
		}
		return $row;
	}

	public static function get_by_key(string $key): ?array
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE `key` = %s", $key), ARRAY_A);
		if (!$row) {
			return null;
		}
		if (isset($row['instructions']) && is_string($row['instructions'])) {
			$decoded = json_decode($row['instructions'], true);
			if (!is_array($decoded)) {
				$decoded = [];
			}
			$row['instructions'] = [
				'title' => (string) ($decoded['title'] ?? ''),
				'body' => (string) ($decoded['body'] ?? ''),
			];
		}
		return $row;
	}

	public static function get_default_rows(): array
	{
		return [
			[
				'key' => 'listicle',
				'name' => 'Listicle',
				'description' => 'List-based articles with clear numbered items.',
				'instructions' => [
					'title' => 'Instruction on how to write a listicle-style title (curiosity, number, clarity).',
					'body' => 'Instruction on how to write listicle body with distinct sections per list item.',
				],
			],
			[
				'key' => 'news',
				'name' => 'News',
				'description' => 'News-style articles with clear headline and structure.',
				'instructions' => [
					'title' => 'Instruction on how to write a news-style headline.',
					'body' => 'Instruction on how to write a news article body.',
				],
			],
			[
				'key' => 'guide',
				'name' => 'Guide',
				'description' => 'Informational guides with logical sections.',
				'instructions' => [
					'title' => 'Instruction on how to write a guide-style title.',
					'body' => 'Instruction on how to write a guide body.',
				],
			],
			[
				'key' => 'howto',
				'name' => 'How-to',
				'description' => 'Step-by-step how-to articles.',
				'instructions' => [
					'title' => 'Instruction on how to write a how-to title.',
					'body' => 'Instruction on how to write a how-to body.',
				],
			],
		];
	}

	public static function seed_defaults(): void
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
		if ($count > 0) {
			return;
		}
		$defaults = self::get_default_rows();
		foreach ($defaults as $row) {
			$instructions_json = json_encode($row['instructions']);
			$wpdb->insert(
				$table_name,
				[
					'key' => $row['key'],
					'name' => $row['name'],
					'description' => $row['description'],
					'instructions' => $instructions_json,
				],
				['%s', '%s', '%s', '%s']
			);
		}
	}

	public static function is_default_key(string $key): bool
	{
		return in_array($key, self::DEFAULT_KEYS, true);
	}

	public static function create(array $data): int|false
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$key = sanitize_key((string) ($data['key'] ?? ''));
		$name = sanitize_text_field((string) ($data['name'] ?? ''));
		$description = sanitize_textarea_field((string) ($data['description'] ?? ''));
		$instructions = $data['instructions'] ?? ['title' => '', 'body' => ''];
		if (!is_array($instructions)) {
			$instructions = ['title' => '', 'body' => ''];
		}
		$instructions_json = json_encode([
			'title' => (string) ($instructions['title'] ?? ''),
			'body' => (string) ($instructions['body'] ?? ''),
		]);
		if ($key === '' || $name === '') {
			return false;
		}
		$result = $wpdb->insert(
			$table_name,
			[
				'key' => $key,
				'name' => $name,
				'description' => $description,
				'instructions' => $instructions_json,
			],
			['%s', '%s', '%s', '%s']
		);
		return $result ? (int) $wpdb->insert_id : false;
	}

	public static function update(int $id, array $data): bool
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$existing = self::get_by_id($id);
		if (!$existing) {
			return false;
		}
		$update_data = [];
		$format = [];
		if (array_key_exists('description', $data)) {
			$update_data['description'] = sanitize_textarea_field((string) $data['description']);
			$format[] = '%s';
		}
		if (array_key_exists('instructions', $data)) {
			$instructions = $data['instructions'];
			if (!is_array($instructions)) {
				$instructions = ['title' => '', 'body' => ''];
			}
			$update_data['instructions'] = json_encode([
				'title' => (string) ($instructions['title'] ?? ''),
				'body' => (string) ($instructions['body'] ?? ''),
			]);
			$format[] = '%s';
		}
		if (empty($update_data)) {
			return false;
		}
		return $wpdb->update($table_name, $update_data, ['id' => $id], $format, ['%d']) !== false;
	}

	public static function reset_to_default(int $id): bool
	{
		$existing = self::get_by_id($id);
		if (!$existing) {
			return false;
		}
		$key = (string) ($existing['key'] ?? '');
		if (!self::is_default_key($key)) {
			return false;
		}
		$defaults = self::get_default_rows();
		foreach ($defaults as $row) {
			if (($row['key'] ?? '') === $key) {
				return self::update($id, [
					'description' => $row['description'] ?? '',
					'instructions' => $row['instructions'] ?? ['title' => '', 'body' => ''],
				]);
			}
		}
		return false;
	}

	public static function delete(int $id): bool
	{
		$existing = self::get_by_id($id);
		if (!$existing || self::is_default_key((string) ($existing['key'] ?? ''))) {
			return false;
		}
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

