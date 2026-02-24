<?php

namespace PostStation\Services;

use PostStation\Models\Campaign;
use PostStation\Models\PostTask;
use PostStation\Models\RssHistory;

/**
 * Single place to turn RSS feed items into PostTasks and RssHistory.
 * Skips URLs already in history or already existing as a task research_url for the campaign.
 */
class RssTaskProcessor
{
	/**
	 * Process a flat list of RSS items: create tasks for URLs not in history and not already a task; record in history.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $items       Array of arrays with keys: url (required), source_id, title, date.
	 * @return array{count: int, task_ids: int[]}
	 */
	public static function process_items_into_tasks(int $campaign_id, array $items): array
	{
		$campaign = Campaign::get_by_id($campaign_id);
		if (!$campaign) {
			return ['count' => 0, 'task_ids' => []];
		}

		$skip_urls = array_fill_keys(RssHistory::get_processed_urls_for_campaign($campaign_id), true);
		foreach (PostTask::get_by_campaign($campaign_id) as $task) {
			$url = isset($task['research_url']) ? trim((string) $task['research_url']) : '';
			if ($url !== '') {
				$skip_urls[$url] = true;
			}
		}

		$run_id = time();
		$to_insert = [];
		$created_ids = [];
		$max_attempts = 20;

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$url = isset($item['url']) ? trim((string) $item['url']) : '';
			if ($url === '' || isset($skip_urls[$url])) {
				continue;
			}

			$source_id = isset($item['source_id']) ? $item['source_id'] : 0;
			$source_id = is_numeric($source_id) ? (string) (int) $source_id : (string) $source_id;
			$title = isset($item['title']) ? sanitize_text_field($item['title']) : '';
			$date_raw = isset($item['date']) ? trim((string) $item['date']) : '';
			$publication_date = null;
			if ($date_raw !== '') {
				$ts = strtotime($date_raw);
				$publication_date = $ts ? gmdate('Y-m-d', $ts) : null;
			}

			$task_id = null;
			for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
				$generated_id = PostTask::generate_id();
				$task_id = PostTask::create([
					'id' => $generated_id,
					'campaign_id' => $campaign_id,
					'research_url' => $url,
					'campaign_type' => 'rewrite_blog_post',
					'status' => 'pending',
				]);
				if ($task_id) {
					break;
				}
			}
			if (!$task_id) {
				$task_id = PostTask::create([
					'campaign_id' => $campaign_id,
					'research_url' => $url,
					'campaign_type' => 'rewrite_blog_post',
					'status' => 'pending',
				]);
			}
			if ($task_id) {
				$created_ids[] = $task_id;
				$skip_urls[$url] = true;
				$to_insert[] = [
					'campaign_id' => $campaign_id,
					'source_id' => $source_id,
					'article_url' => $url,
					'title' => $title,
					'publication_date' => $publication_date,
					'run_id' => $run_id,
				];
			}
		}

		if (!empty($to_insert)) {
			RssHistory::insert_batch($to_insert);
			RssHistory::prune_keep_last_runs($campaign_id, 3);
		}

		if (($campaign['status'] ?? '') === 'active' && !empty($campaign['webhook_id'])) {
			$runner = new BackgroundRunner();
			$runner->start_run_if_pending($campaign_id);
		}

		return ['count' => count($created_ids), 'task_ids' => $created_ids];
	}

	/**
	 * Flatten webhook response (sources with items) to a flat items list for process_items_into_tasks.
	 *
	 * @param array $response Decoded webhook response with 'sources' key (or top-level array of sources).
	 * @return array<int, array{source_id: mixed, title: string, url: string, date: string}>
	 */
	public static function flatten_response_to_items(array $response): array
	{
		$sources = [];
		if (isset($response['sources']) && is_array($response['sources'])) {
			$sources = $response['sources'];
		} elseif (!empty($response) && isset($response[0]) && is_array($response[0])) {
			$sources = $response;
		}

		$items = [];
		foreach ($sources as $source) {
			if (!is_array($source)) {
				continue;
			}
			$source_id = isset($source['source_id']) ? $source['source_id'] : 0;
			$feed_url = isset($source['feed_url']) ? trim((string) $source['feed_url']) : '';
			$source_items = isset($source['items']) && is_array($source['items']) ? $source['items'] : [];
			foreach ($source_items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$url = isset($item['url']) ? trim((string) $item['url']) : '';
				if ($url === '') {
					continue;
				}
				$items[] = [
					'source_id' => $source_id,
					'title' => isset($item['title']) ? (string) $item['title'] : '',
					'url' => $url,
					'date' => isset($item['date']) ? (string) $item['date'] : '',
				];
			}
		}
		return $items;
	}
}
