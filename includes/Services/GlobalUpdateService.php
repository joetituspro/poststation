<?php

namespace PostStation\Services;

class GlobalUpdateService
{
	public const ACTION_GLOBAL_UPDATE = 'poststation_update';
	public const ACTION_INTERNAL_TICK = 'poststation_update_internal_tick';
	private const GROUP = 'poststation';
	private const INTERVAL_SECONDS = 10;
	private const WP_CRON_SCHEDULE_KEY = 'poststation_every_10_seconds';
	private const LOCK_TRANSIENT_KEY = 'poststation_global_update_lock';
	private const LOCK_TTL_SECONDS = 8;

	public function init(): void
	{
		// Main global update hook (called by Action Scheduler).
		add_action(self::ACTION_GLOBAL_UPDATE, [$this, 'handle_global_update']);
		add_action(self::ACTION_INTERNAL_TICK, [$this, 'handle_global_update']);
		add_filter('cron_schedules', [$this, 'register_internal_schedule']);

		// Ensure recurring schedule exists and clean up legacy per-task/per-campaign jobs.
		add_action('init', [$this, 'ensure_schedule_and_cleanup'], 20);
	}

	/**
	 * @param array<string,mixed> $schedules
	 * @return array<string,mixed>
	 */
	public function register_internal_schedule(array $schedules): array
	{
		if (!isset($schedules[self::WP_CRON_SCHEDULE_KEY])) {
			$schedules[self::WP_CRON_SCHEDULE_KEY] = [
				'interval' => self::INTERVAL_SECONDS,
				'display' => __('Every 10 Seconds (PostStation)', 'poststation'),
			];
		}
		return $schedules;
	}

	public function handle_global_update(): void
	{
		if (!$this->acquire_lock()) {
			return;
		}

		// Orchestrate per-tick work; wrapped in try/catch so one failure doesn't stop the rest.
		try {
			try {
				$this->handle_live_update();
			} catch (\Throwable $e) {
				error_log('PostStation GlobalUpdateService live update error: ' . $e->getMessage());
			}

			try {
				$this->handle_rss_update();
			} catch (\Throwable $e) {
				error_log('PostStation GlobalUpdateService RSS update error: ' . $e->getMessage());
			}

			try {
				$this->handle_blueprint_update_check();
			} catch (\Throwable $e) {
				error_log('PostStation GlobalUpdateService blueprint update error: ' . $e->getMessage());
			}
		} finally {
			$this->release_lock();
		}
	}

	public function ensure_schedule_and_cleanup(): void
	{
		// Internal WP-Cron 10-second loop.
		if (!wp_next_scheduled(self::ACTION_INTERNAL_TICK)) {
			wp_schedule_event(time() + self::INTERVAL_SECONDS, self::WP_CRON_SCHEDULE_KEY, self::ACTION_INTERNAL_TICK);
		}

		// Keep Action Scheduler recurring fallback for sites that already rely on it.
		if (function_exists('as_schedule_recurring_action') && function_exists('as_has_scheduled_action')) {
			$already_scheduled = as_has_scheduled_action(self::ACTION_GLOBAL_UPDATE, [], self::GROUP);
			if (!$already_scheduled) {
				as_schedule_recurring_action(
					time(),
					self::INTERVAL_SECONDS,
					self::ACTION_GLOBAL_UPDATE,
					[],
					self::GROUP
				);
			}
		}

		// Clean up legacy schedules that are no longer used.
		if (function_exists('as_unschedule_all_actions')) {
			// Per-task post status checks (old BackgroundRunner behaviour).
			as_unschedule_all_actions('poststation_check_posttask_status', [], null);

			// Per-campaign RSS scheduled runs.
			as_unschedule_all_actions('poststation_run_rss_scheduled', [], null);
		}
	}

	private function acquire_lock(): bool
	{
		if (get_transient(self::LOCK_TRANSIENT_KEY)) {
			return false;
		}
		set_transient(self::LOCK_TRANSIENT_KEY, 1, self::LOCK_TTL_SECONDS);
		return true;
	}

	private function release_lock(): void
	{
		delete_transient(self::LOCK_TRANSIENT_KEY);
	}

	private function handle_live_update(): void
	{
		$background_runner = new BackgroundRunner();
		$background_runner->handle_live_update();
	}

	private function handle_rss_update(): void
	{
		RssService::handle_rss_update();
	}

	private function handle_blueprint_update_check(): void
	{
		$update_service = new UpdateService();
		$update_service->check_for_updates(false);
	}
}
