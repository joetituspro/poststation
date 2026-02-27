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
					'title' => 'Write a clear, specific listicle title that includes an exact number, a primary keyword near the beginning, and a strong benefit. Use a direct pattern such as "X [Items/Tips/Ways] to [Outcome]". Keep it concise, avoid vague wording, and make sure the promised value is realistic and useful.',
					'body' => 'Open with a short introduction that frames the problem and what readers will gain. Use H2 headings for each numbered item and keep each item focused on one idea. For every item, include what it is, why it matters, and how to apply it in practical terms. Keep paragraphs short, use bullets for steps or examples, and add occasional bold emphasis only for key terms. Maintain a helpful, confident tone and prioritize clarity and actionability over filler.',
				],
			],
			[
				'key' => 'news',
				'name' => 'News',
				'description' => 'News-style articles with clear headline and structure.',
				'instructions' => [
					'title' => 'Write a factual, timely headline that prioritizes the main development first and uses plain language. Place the core keyword naturally in the first half of the title. Avoid hype, sensational phrasing, and clickbait. Keep the headline precise so readers can understand the event at a glance.',
					'body' => 'Start with a brief lead paragraph that summarizes the key event, who is affected, and why it matters now. Follow with H2 sections that expand details in a logical sequence: confirmed facts, context/background, implications, and next expected developments. Keep tone neutral and evidence-based. Use short paragraphs, attribute claims clearly, and separate confirmed information from speculation. When relevant, include concise data points or quotes to improve credibility and readability.',
				],
			],
			[
				'key' => 'guide',
				'name' => 'Guide',
				'description' => 'Informational guides with logical sections.',
				'instructions' => [
					'title' => 'Write a practical guide title that states the topic and intended outcome clearly. Use a keyword-forward pattern such as "Complete Guide to [Topic]" or "[Topic] Guide for [Audience]". Keep it straightforward, benefit-driven, and easy to scan.',
					'body' => 'Begin with an introduction that defines the topic, audience, and expected result. Structure the article with clear H2 sections that move from fundamentals to deeper guidance, and use H3 subsections when a section has multiple components. In each section, explain concepts in plain language, provide actionable best practices, and include examples where useful. Keep paragraphs concise, use bullets or tables for comparisons/checklists, and maintain a teaching-focused tone that builds reader confidence step by step.',
				],
			],
			[
				'key' => 'howto',
				'name' => 'How-to',
				'description' => 'Step-by-step how-to articles.',
				'instructions' => [
					'title' => 'Write a clear task-oriented title that starts with "How to" and states the exact goal. Include the main keyword naturally and, when useful, indicate scope or difficulty. Keep wording specific, direct, and outcome-focused.',
					'body' => 'Open with a short introduction that explains who this process is for, what result they will achieve, and any prerequisites. Present the process as a numbered sequence with H2 step headings. For each step, explain the action, the expected result, and common mistakes to avoid. Use concise paragraphs and bullet sub-steps where needed for clarity. Keep tone instructional and practical, and ensure each step can be followed independently without ambiguity.',
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
