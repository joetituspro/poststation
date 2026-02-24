<?php

namespace PostStation\Services;

use Exception;
use PostStation\Models\Campaign;
use PostStation\Models\CampaignRss;
use PostStation\Models\RssHistory;
use PostStation\Models\Webhook;

/**
 * Central RSS service: shared webhook logic, global background runs, and manual "Run RSS now".
 */
class RssService
{
	/** Allowed frequency intervals in minutes (must match UI validation). */
	public const ALLOWED_INTERVALS = [15, 60, 360, 1440];

	private const GLOBAL_REQUEST_TIMEOUT = 120;
	private const MANUAL_REQUEST_TIMEOUT = 60;

	/**
	 * Fetch RSS items from webhook for a campaign. Validates campaign, webhook, and sources; does blocking request.
	 *
	 * @param int $campaign_id   Campaign ID.
	 * @param int $timeout_seconds Request timeout in seconds (e.g. 120 for scheduled, 60 for manual).
	 * @return array|null Normalized response with 'sources' key, or null on validation/HTTP/parse failure.
	 */
	public static function fetch_items_for_campaign(int $campaign_id, int $timeout_seconds = self::GLOBAL_REQUEST_TIMEOUT): ?array
	{
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign) {
			return null;
		}
		$webhook_id = isset($campaign['webhook_id']) ? (int) $campaign['webhook_id'] : 0;
		if ($webhook_id <= 0) {
			return null;
		}
		$rss_enabled = isset($campaign['rss_enabled']) ? strtolower(trim((string) $campaign['rss_enabled'])) : 'no';
		if ($rss_enabled !== 'yes') {
			return null;
		}

		$webhook = Webhook::get_by_id($webhook_id);
		if (!$webhook || empty($webhook['url'])) {
			return null;
		}

		$rss_config = CampaignRss::get_by_campaign($campaign_id);
		if (!$rss_config || empty($rss_config['sources'])) {
			return null;
		}

		$sources_payload = [];
		foreach ($rss_config['sources'] as $src) {
			if (!is_array($src)) {
				continue;
			}
			$feed_url = isset($src['feed_url']) ? trim((string) $src['feed_url']) : '';
			if ($feed_url === '') {
				continue;
			}
			$source_id = isset($src['source_id']) ? $src['source_id'] : 0;
			$sources_payload[] = [
				'source_id' => is_numeric($source_id) ? (int) $source_id : $source_id,
				'feed_url' => $feed_url,
			];
		}
		if (empty($sources_payload)) {
			return null;
		}

		$body = [
			'campaign_id' => $campaign_id,
			'rss_mode' => true,
			'sources' => $sources_payload,
		];

		$response = wp_remote_post(
			$webhook['url'],
			[
				'headers' => ['Content-Type' => 'application/json'],
				'body' => wp_json_encode($body),
				'timeout' => $timeout_seconds,
				'sslverify' => false,
				'blocking' => true,
			]
		);

		if (is_wp_error($response)) {
			return null;
		}
		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return null;
		}
		$response_body = wp_remote_retrieve_body($response);
		$decoded = json_decode($response_body, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return null;
		}
		// Normalize: object with "sources" or top-level array of sources
		if (isset($decoded['sources']) && is_array($decoded['sources'])) {
			return $decoded;
		}
		if (!empty($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
			return ['sources' => $decoded];
		}
		return null;
	}

	/**
	 * Run RSS update for all eligible campaigns on the global poststation_update tick.
	 * For each rss-enabled campaign with webhook + sources, respect frequency_interval and process items into tasks.
	 */
	public static function handle_rss_update(): void
	{
		global $wpdb;

		$campaign_table = $wpdb->prefix . Campaign::get_table_name();
		$rss_table = $wpdb->prefix . CampaignRss::get_table_name();

		$rows = $wpdb->get_results(
			"SELECT c.id AS campaign_id, r.frequency_interval
			FROM {$campaign_table} c
			INNER JOIN {$rss_table} r ON r.campaign_id = c.id
			WHERE LOWER(TRIM(IFNULL(c.rss_enabled, 'no'))) = 'yes'
			AND c.webhook_id IS NOT NULL
			AND c.webhook_id > 0",
			ARRAY_A
		);

		if (empty($rows)) {
			return;
		}

		$now = time();
		foreach ($rows as $row) {
			$campaign_id = (int) $row['campaign_id'];
			$interval_minutes = (int) ($row['frequency_interval'] ?? 60);
			if (!in_array($interval_minutes, self::ALLOWED_INTERVALS, true)) {
				$interval_minutes = 60;
			}

			$rss_config = CampaignRss::get_by_campaign($campaign_id);
			if (!$rss_config || empty($rss_config['sources'])) {
				continue;
			}

			$last_run = RssHistory::get_last_run_time_for_campaign($campaign_id);
			$interval_seconds = $interval_minutes * 60;
			if ($last_run !== null && ($now - $last_run) < $interval_seconds) {
				continue;
			}

			$normalized = self::fetch_items_for_campaign($campaign_id, self::GLOBAL_REQUEST_TIMEOUT);
			if ($normalized === null) {
				continue;
			}

			$items = RssTaskProcessor::flatten_response_to_items($normalized);
			if (!empty($items)) {
				RssTaskProcessor::process_items_into_tasks($campaign_id, $items);
			} else {
				RssHistory::record_run($campaign_id);
			}
		}
	}

	/**
	 * Manual "Run RSS now" entry point used by AJAX. Throws on failure for better error reporting.
	 *
	 * @return array{status?: string, sources?: array<int, array{source_id: int, feed_url: string, items?: array}>}
	 * @throws Exception
	 */
	public static function run_rss_check(int $campaign_id): array
	{
		$result = self::fetch_items_for_campaign($campaign_id, self::MANUAL_REQUEST_TIMEOUT);
		if ($result === null) {
			throw new Exception(__('RSS fetch failed: invalid campaign, webhook, or response.', 'poststation'));
		}
		return $result;
	}
}
