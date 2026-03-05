<?php

namespace PostStation\Services;

use Exception;
use PostStation\Models\Campaign;
use PostStation\Models\CampaignRss;
use PostStation\Models\RssHistory;

class RssService
{
	public const ALLOWED_INTERVALS = [15, 60, 360, 1440];

	private const GLOBAL_REQUEST_TIMEOUT = 120;
	private const MANUAL_REQUEST_TIMEOUT = 60;

	public static function fetch_items_for_campaign(int $campaign_id, int $timeout_seconds = self::GLOBAL_REQUEST_TIMEOUT): ?array
	{
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign) {
			return null;
		}
		$rss_enabled = isset($campaign['rss_enabled']) ? strtolower(trim((string) $campaign['rss_enabled'])) : 'no';
		if ($rss_enabled !== 'yes') {
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

		return self::fetch_items_locally($sources_payload);
	}

	/**
	 * @param array<int,array{source_id:mixed,feed_url:string}> $sources_payload
	 * @return array<string,mixed>|null
	 */
	private static function fetch_items_locally(array $sources_payload): ?array
	{
		if (!function_exists('fetch_feed')) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		$sources = [];
		foreach ($sources_payload as $source) {
			$feed_url = trim((string) ($source['feed_url'] ?? ''));
			if ($feed_url === '') {
				continue;
			}
			$feed = fetch_feed($feed_url);
			if (is_wp_error($feed)) {
				continue;
			}

			$items = [];
			$max = $feed->get_item_quantity(20);
			$feed_items = $feed->get_items(0, $max);
			foreach ((array) $feed_items as $item) {
				$link = trim((string) $item->get_link());
				if ($link === '') {
					continue;
				}
				$items[] = [
					'title' => (string) $item->get_title(),
					'url' => $link,
					'date' => (string) $item->get_date('c'),
				];
			}

			$sources[] = [
				'source_id' => $source['source_id'],
				'feed_url' => $feed_url,
				'items' => $items,
			];
		}

		if (empty($sources)) {
			return null;
		}

		return ['sources' => $sources];
	}

	public static function handle_rss_update(): void
	{
		global $wpdb;

		$campaign_table = $wpdb->prefix . Campaign::get_table_name();
		$rss_table = $wpdb->prefix . CampaignRss::get_table_name();

		$rows = $wpdb->get_results(
			"SELECT c.id AS campaign_id, r.frequency_interval
			FROM {$campaign_table} c
			INNER JOIN {$rss_table} r ON r.campaign_id = c.id
			WHERE LOWER(TRIM(IFNULL(c.rss_enabled, 'no'))) = 'yes'",
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
	 * @return array{status?: string, sources?: array<int, array{source_id: int, feed_url: string, items?: array}>}
	 * @throws Exception
	 */
	public static function run_rss_check(int $campaign_id): array
	{
		$result = self::fetch_items_for_campaign($campaign_id, self::MANUAL_REQUEST_TIMEOUT);
		if ($result === null) {
			throw new Exception(__('RSS fetch failed: invalid campaign or response.', 'poststation'));
		}
		return $result;
	}
}
