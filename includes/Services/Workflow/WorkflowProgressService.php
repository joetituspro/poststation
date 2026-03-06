<?php

namespace PostStation\Services\Workflow;

use PostStation\Models\PostTask;

class WorkflowProgressService
{
	public function start_processing(int $task_id, string $execution_id): void
	{
		PostTask::update($task_id, [
			'status' => 'processing',
			'execution_id' => $execution_id,
			'run_started_at' => current_time('mysql'),
			'error_message' => null,
			'progress' => null,
			'ai_total_tokens' => null,
			'ai_total_cost_usd' => null,
			'ai_call_count' => null,
			'ai_cost_unknown' => 0,
			'ai_tokens_estimated' => 0,
		]);
	}

	public function update_progress(int $task_id, string $progress): void
	{
		PostTask::update($task_id, [
			'progress' => $progress,
			'run_started_at' => current_time('mysql'),
		]);
	}

	public function mark_failed(int $task_id, string $message): void
	{
		PostTask::update($task_id, [
			'status' => 'failed',
			'error_message' => $message,
			'progress' => null,
		]);
	}
}
