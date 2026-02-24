<?php

namespace PostStation\Services;

class GlobalUpdateService
{
	public const ACTION_GLOBAL_UPDATE = 'poststation_update';
	private const GROUP = 'poststation';
	private const INTERVAL_SECONDS = 60;

	public function init(): void
	{
		// Main global update hook (called by Action Scheduler).
		add_action(self::ACTION_GLOBAL_UPDATE, [$this, 'handle_global_update']);

		// Ensure recurring schedule exists and clean up legacy per-task/per-campaign jobs.
		add_action('init', [$this, 'ensure_schedule_and_cleanup'], 20);
	}

	public function handle_global_update(): void
	{
		// Orchestrate per-tick work; wrapped in try/catch so one failure doesn't stop the rest.
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
	}

	public function ensure_schedule_and_cleanup(): void
	{
		if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
			return;
		}

		// Ensure a single recurring global update job exists.
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

		// Clean up legacy schedules that are no longer used.
		if (function_exists('as_unschedule_all_actions')) {
			// Per-task post status checks (old BackgroundRunner behaviour).
			as_unschedule_all_actions('poststation_check_posttask_status', [], null);

			// Per-campaign RSS scheduled runs.
			as_unschedule_all_actions('poststation_run_rss_scheduled', [], null);
		}
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
}

