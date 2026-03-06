<?php

namespace PostStation\Services\Workflow;

class AiUsageAggregator
{
	/**
	 * @param array<string,mixed> $metrics
	 */
	public static function append(WorkflowContext $context, string $step, array $metrics): void
	{
		$usage = (array) $context->get('ai_usage', []);
		$calls = (array) ($usage['calls'] ?? []);
		$by_step = (array) ($usage['by_step'] ?? []);
		$totals = (array) ($usage['totals'] ?? self::blank_bucket());

		$normalized = self::normalize_metrics($metrics);
		$calls[] = [
			'step' => $step,
			'provider' => (string) ($normalized['provider'] ?? 'openrouter'),
			'type' => (string) ($normalized['type'] ?? 'chat'),
			'model' => (string) ($normalized['model'] ?? ''),
			'prompt_tokens' => $normalized['prompt_tokens'],
			'completion_tokens' => $normalized['completion_tokens'],
			'total_tokens' => $normalized['total_tokens'],
			'cost_usd' => $normalized['cost_usd'],
			'tokens_estimated' => !empty($normalized['tokens_estimated']),
			'cost_estimated' => false,
			'raw_usage_present' => !empty($normalized['raw_usage_present']),
			'at' => current_time('mysql'),
		];

		$step_bucket = (array) ($by_step[$step] ?? self::blank_bucket());
		$by_step[$step] = self::merge_bucket($step_bucket, $normalized);
		$totals = self::merge_bucket($totals, $normalized);

		$usage['calls'] = $calls;
		$usage['by_step'] = $by_step;
		$usage['totals'] = $totals;
		$context->set('ai_usage', $usage);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function summarize_step(WorkflowContext $context, string $step): array
	{
		$usage = (array) $context->get('ai_usage', []);
		$bucket = (array) (($usage['by_step'] ?? [])[$step] ?? []);
		return self::finalize_bucket($bucket);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function summarize_totals(WorkflowContext $context): array
	{
		$usage = (array) $context->get('ai_usage', []);
		$bucket = (array) ($usage['totals'] ?? []);
		return self::finalize_bucket($bucket);
	}

	/**
	 * @param array<string,mixed> $bucket
	 * @param array<string,mixed> $metrics
	 * @return array<string,mixed>
	 */
	private static function merge_bucket(array $bucket, array $metrics): array
	{
		$bucket = wp_parse_args($bucket, self::blank_bucket());
		$bucket['call_count'] = (int) $bucket['call_count'] + 1;

		foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
			if ($metrics[$key] !== null) {
				$bucket[$key] = (int) $bucket[$key] + (int) $metrics[$key];
				$bucket['has_token_data'] = true;
			}
		}

		if ($metrics['cost_usd'] !== null) {
			$bucket['cost_usd'] = (float) $bucket['cost_usd'] + (float) $metrics['cost_usd'];
			$bucket['has_cost_data'] = true;
		} else {
			$bucket['has_unknown_cost'] = true;
		}

		if (!empty($metrics['tokens_estimated'])) {
			$bucket['tokens_estimated'] = true;
		}

		return $bucket;
	}

	/**
	 * @param array<string,mixed> $bucket
	 * @return array<string,mixed>
	 */
	private static function finalize_bucket(array $bucket): array
	{
		$bucket = wp_parse_args($bucket, self::blank_bucket());

		return [
			'prompt_tokens' => !empty($bucket['has_token_data']) ? (int) $bucket['prompt_tokens'] : null,
			'completion_tokens' => !empty($bucket['has_token_data']) ? (int) $bucket['completion_tokens'] : null,
			'total_tokens' => !empty($bucket['has_token_data']) ? (int) $bucket['total_tokens'] : null,
			'cost_usd' => (!empty($bucket['has_cost_data']) && empty($bucket['has_unknown_cost']))
				? round((float) $bucket['cost_usd'], 8)
				: null,
			'call_count' => (int) $bucket['call_count'],
			'tokens_estimated' => !empty($bucket['tokens_estimated']),
			'cost_unknown' => empty($bucket['has_cost_data']) || !empty($bucket['has_unknown_cost']),
		];
	}

	/**
	 * @param array<string,mixed> $metrics
	 * @return array<string,mixed>
	 */
	private static function normalize_metrics(array $metrics): array
	{
		$prompt_tokens = isset($metrics['prompt_tokens']) ? (int) $metrics['prompt_tokens'] : null;
		$completion_tokens = isset($metrics['completion_tokens']) ? (int) $metrics['completion_tokens'] : null;
		$total_tokens = isset($metrics['total_tokens']) ? (int) $metrics['total_tokens'] : null;
		$cost_usd = isset($metrics['cost_usd']) ? (float) $metrics['cost_usd'] : null;

		return [
			'provider' => sanitize_text_field((string) ($metrics['provider'] ?? 'openrouter')),
			'type' => sanitize_text_field((string) ($metrics['type'] ?? 'chat')),
			'model' => sanitize_text_field((string) ($metrics['model'] ?? '')),
			'prompt_tokens' => $prompt_tokens !== null && $prompt_tokens >= 0 ? $prompt_tokens : null,
			'completion_tokens' => $completion_tokens !== null && $completion_tokens >= 0 ? $completion_tokens : null,
			'total_tokens' => $total_tokens !== null && $total_tokens >= 0 ? $total_tokens : null,
			'cost_usd' => $cost_usd !== null && $cost_usd >= 0 ? $cost_usd : null,
			'tokens_estimated' => !empty($metrics['tokens_estimated']),
			'cost_estimated' => false,
			'raw_usage_present' => !empty($metrics['raw_usage_present']),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function blank_bucket(): array
	{
		return [
			'prompt_tokens' => 0,
			'completion_tokens' => 0,
			'total_tokens' => 0,
			'cost_usd' => 0.0,
			'call_count' => 0,
			'tokens_estimated' => false,
			'has_token_data' => false,
			'has_cost_data' => false,
			'has_unknown_cost' => false,
		];
	}
}
