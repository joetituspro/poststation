<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Api\Create;
use PostStation\Services\Workflow\WorkflowContext;

class PublishStep
{
	/**
	 * @param array<string,mixed> $spec
	 * @return array<string,mixed>
	 */
	public function run(WorkflowContext $context, array $spec): array
	{
		$payload = (array) $context->get('payload', []);
		$task_id = (int) ($payload['task_id'] ?? 0);
		$data = [
			'task_id' => $task_id,
			'title' => (string) $context->get('post_title', ''),
			'slug' => (string) $context->get('post_slug', ''),
			'content' => (string) $context->get('post_content_html', ''),
			'taxonomies' => (array) $context->get('taxonomies', []),
			'custom_fields' => (array) $context->get('custom_fields', []),
			'thumbnail_id' => $context->get('featured_image_id', null),
		];

		$create = new Create();
		return $create->process_request($data);
	}
}

