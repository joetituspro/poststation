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
		$rows = $wpdb->get_results("SELECT id, `key`, name, instructions, created_at, updated_at FROM {$old_table}", ARRAY_A);
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
		$rows = $wpdb->get_results("SELECT id, `key`, name, instructions FROM {$table_name} ORDER BY id ASC", ARRAY_A);
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
		$row = $wpdb->get_row($wpdb->prepare("SELECT id, `key`, name, instructions FROM {$table_name} WHERE id = %d", $id), ARRAY_A);
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
		$row = $wpdb->get_row($wpdb->prepare("SELECT id, `key`, name, instructions FROM {$table_name} WHERE `key` = %s", $key), ARRAY_A);
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
				'instructions' => [
					'title' => 'Write a clear, specific listicle title that includes an exact number, a primary keyword near the beginning, and a strong benefit. Use a direct pattern such as "X [Items/Tips/Ways] to [Outcome]". Keep it concise, avoid vague wording, and make sure the promised value is realistic and useful.',
					'body' => wp_json_encode([
						['name' => 'Introduction', 'content' => 'Open with a short hook that frames the problem, sets reader expectations, and states the benefit of reading the list.'],
						['name' => 'Section Structure', 'content' => 'Use a numbered H2 for each item and keep every section focused on one clear idea with no overlap.'],
						['name' => 'Item Depth', 'content' => 'For each item, explain what it is, why it matters, and how to apply it in practical terms.'],
						['name' => 'Readability', 'content' => 'Keep paragraphs short and scannable; use bullets for mini-steps, examples, or quick checklists.'],
						['name' => 'Tone', 'content' => 'Maintain a helpful, confident, practical tone that prioritizes clarity and usefulness over fluff.'],
						['name' => 'Formatting', 'content' => 'Use bold text sparingly to emphasize key terms, criteria, or actions without visual clutter.'],
						['name' => 'SEO Body Guidance', 'content' => 'Use the primary keyword naturally in the intro and early sections, then support with relevant semantic terms.'],
						['name' => 'CTA Placement', 'content' => 'Place a soft CTA near the end that aligns with the reader intent and the list outcome.'],
					]) ?: '[]',
				],
			],
			[
				'key' => 'news',
				'name' => 'News',
				'instructions' => [
					'title' => 'Write a factual, timely headline that prioritizes the main development first and uses plain language. Place the core keyword naturally in the first half of the title. Avoid hype, sensational phrasing, and clickbait. Keep the headline precise so readers can understand the event at a glance.',
					'body' => wp_json_encode([
						['name' => 'Lead Paragraph', 'content' => 'Open with a concise lead covering what happened, who is affected, and why it matters now.'],
						['name' => 'Information Flow', 'content' => 'Organize body sections in this order: confirmed facts, relevant context, implications, and near-term developments.'],
						['name' => 'Evidence Standards', 'content' => 'Attribute claims clearly and distinguish verified information from assumptions or projections.'],
						['name' => 'Tone', 'content' => 'Keep tone neutral, factual, and evidence-based; avoid opinionated framing and dramatic language.'],
						['name' => 'Paragraph Style', 'content' => 'Use short paragraphs and direct sentences to improve readability and reduce ambiguity.'],
						['name' => 'Data and Quotes', 'content' => 'Include concise data points or sourced quotes only when they improve credibility and context.'],
						['name' => 'SEO Body Guidance', 'content' => 'Use core and supporting keywords naturally in context, without over-optimization or repetition.'],
						['name' => 'CTA Placement', 'content' => 'If needed, end with a low-friction informational CTA relevant to readers following updates.'],
					]) ?: '[]',
				],
			],
			[
				'key' => 'guide',
				'name' => 'Guide',
				'instructions' => [
					'title' => 'Write a practical guide title that states the topic and intended outcome clearly. Use a keyword-forward pattern such as "Complete Guide to [Topic]" or "[Topic] Guide for [Audience]". Keep it straightforward, benefit-driven, and easy to scan.',
					'body' => wp_json_encode([
						['name' => 'Introduction', 'content' => 'Define the topic, target audience, and expected outcome before moving into the core sections.'],
						['name' => 'Section Flow', 'content' => 'Move from fundamentals to intermediate guidance, then advanced considerations in a logical progression.'],
						['name' => 'Heading Strategy', 'content' => 'Use clear H2 topic sections and H3 subsections only when a section has multiple distinct components.'],
						['name' => 'Instructional Depth', 'content' => 'Explain concepts in plain language and pair each major point with actionable best practices.'],
						['name' => 'Examples', 'content' => 'Add concrete examples where they clarify application, tradeoffs, or common mistakes.'],
						['name' => 'Formatting', 'content' => 'Use bullets, checklists, or compact tables for comparisons and step summaries.'],
						['name' => 'Tone', 'content' => 'Maintain a teaching-focused, confidence-building tone that is clear and practical.'],
						['name' => 'SEO Body Guidance', 'content' => 'Integrate primary and related terms naturally in section intros and key explanations.'],
						['name' => 'CTA Placement', 'content' => 'Place a contextual CTA at the end, aligned with the guide goal and next logical action.'],
					]) ?: '[]',
				],
			],
			[
				'key' => 'howto',
				'name' => 'How-to',
				'instructions' => [
					'title' => 'Write a clear task-oriented title that starts with "How to" and states the exact goal. Include the main keyword naturally and, when useful, indicate scope or difficulty. Keep wording specific, direct, and outcome-focused.',
					'body' => wp_json_encode([
						['name' => 'Introduction', 'content' => 'State who the process is for, the expected result, and prerequisites before the first step.'],
						['name' => 'Step Structure', 'content' => 'Present steps in a numbered sequence with clear H2 step headings and action-first wording.'],
						['name' => 'Step Content', 'content' => 'For each step, include the action, expected outcome, and mistakes or edge cases to avoid.'],
						['name' => 'Sub-steps', 'content' => 'Use bullet sub-steps when a step has multiple actions, checks, or options.'],
						['name' => 'Clarity Rules', 'content' => 'Use concise, unambiguous language so each step can be executed without interpretation gaps.'],
						['name' => 'Tone', 'content' => 'Keep tone instructional, practical, and supportive, focused on successful completion.'],
						['name' => 'Formatting', 'content' => 'Use short paragraphs, occasional bold labels, and visual separation between steps.'],
						['name' => 'SEO Body Guidance', 'content' => 'Use task-intent keywords naturally in step headings and key explanations.'],
						['name' => 'CTA Placement', 'content' => 'Close with a next-step CTA tied to post-completion actions or related workflow goals.'],
					]) ?: '[]',
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
					'instructions' => $instructions_json,
				],
				['%s', '%s', '%s']
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
				'instructions' => $instructions_json,
			],
			['%s', '%s', '%s']
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
