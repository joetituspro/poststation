<?php

namespace PostStation\Services\Workflow\Steps;

use PostStation\Services\ImageOptimizer;
use PostStation\Services\Workflow\OpenRouterClient;
use PostStation\Services\Workflow\N8nPromptLibrary;
use PostStation\Services\Workflow\StepDeferredException;
use PostStation\Services\Workflow\WorkflowContext;

class FeaturedImageStep
{
	private OpenRouterClient $openrouter;
	private ImageOptimizer $image_optimizer;
	private N8nPromptLibrary $prompt_library;

	public function __construct(?OpenRouterClient $openrouter = null, ?ImageOptimizer $image_optimizer = null, ?N8nPromptLibrary $prompt_library = null)
	{
		$this->openrouter = $openrouter ?? new OpenRouterClient();
		$this->image_optimizer = $image_optimizer ?? new ImageOptimizer();
		$this->prompt_library = $prompt_library ?? new N8nPromptLibrary();
	}

	/**
	 * @param array<string,mixed> $spec
	 */
	public function run(WorkflowContext $context, array $spec): void
	{
		$payload = (array) $context->get('payload', []);
		$task_id = (int) ($payload['task_id'] ?? 0);
		$feature_image_id = (int) ($payload['feature_image_id'] ?? 0);
		if ($feature_image_id > 0) {
			$context->set('featured_image_id', $feature_image_id);
			return;
		}

		$image_field = (array) ($payload['content_fields']['image'] ?? []);
		$enabled = !empty($image_field['enabled']);
		$mode = (string) ($image_field['mode'] ?? 'generate');
		if (!$enabled || $mode !== 'generate') {
			return;
		}

		$title = (string) $context->get('post_title', (string) ($payload['topic'] ?? ''));
		$topic = (string) ($payload['topic'] ?? '');
		$image_prompt_instruction = (string) ($image_field['prompt'] ?? '');
		$state = (array) $context->get('featured_image_state', []);

		if (($state['stage'] ?? '') !== 'generate_image') {
			$user_template = $this->prompt_library->load('featured_image.user.txt');
			$user_prompt = $this->prompt_library->render($user_template, [
				'{{ $json.title }}' => $title,
				'{{ $json.keyword }}' => $topic,
				'{{ $json.image_style }}' => (string) ($image_field['style'] ?? ''),
				'{{ $json.aspect_ratio }}' => (string) ($image_field['aspect_ratio'] ?? ''),
				"{{ $('Webhook').item.json.body.content_fields.image.prompt }}" => $image_prompt_instruction,
			]);

			$prompt_builder = $this->openrouter->chat([
				['role' => 'user', 'content' => $user_prompt],
			], (string) ($payload['content_fields']['body']['model_id'] ?? ''), true, [
				'type' => 'object',
				'properties' => [
					'prompt' => ['type' => 'string'],
					'alt_text' => ['type' => 'string'],
				],
				'required' => ['prompt'],
				'additionalProperties' => true,
			]);
			if (is_wp_error($prompt_builder)) {
				return;
			}

			$prompt_data = $this->openrouter->extract_json_content($prompt_builder);
			if (is_wp_error($prompt_data)) {
				return;
			}
			$prompt = trim((string) ($prompt_data['prompt'] ?? ''));
			$alt_text = trim((string) ($prompt_data['alt_text'] ?? ''));
			if ($prompt === '') {
				return;
			}

			$context->set('featured_image_state', [
				'stage' => 'generate_image',
				'prompt' => $prompt,
				'alt_text' => $alt_text,
			]);
			throw new StepDeferredException('Featured image prompt prepared; generating image on next tick.');
		}

		$prompt = trim((string) ($state['prompt'] ?? ''));
		$alt_text = trim((string) ($state['alt_text'] ?? ''));
		if ($prompt === '') {
			$context->remove('featured_image_state');
			return;
		}

		$image_response = $this->openrouter->generate_image($prompt, (string) ($image_field['model_id'] ?? ''));
		if (is_wp_error($image_response)) {
			return;
		}

		$raw_image = (string) ($image_response['choices'][0]['message']['images'][0]['image_url']['url'] ?? '');
		$base64 = $this->resolve_to_base64_data_uri($raw_image);
		if ($base64 === '') {
			return;
		}

		$upload = $this->image_optimizer->upload_base64_image([
			'task_id' => $task_id,
			'image_base64' => $base64,
			'alt_text' => $alt_text,
			'format' => 'webp',
		]);

		$attachment_id = (int) ($upload['attachment_id'] ?? 0);
		if ($attachment_id > 0) {
			$context->set('featured_image_id', $attachment_id);
		}
		$context->remove('featured_image_state');
	}

	private function resolve_to_base64_data_uri(string $source): string
	{
		if ($source === '') {
			return '';
		}
		if (str_starts_with($source, 'data:image/')) {
			return $source;
		}
		if (!preg_match('#^https?://#i', $source)) {
			return '';
		}

		$response = wp_remote_get($source, ['timeout' => 60]);
		if (is_wp_error($response)) {
			return '';
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') {
			return '';
		}
		$content_type = (string) wp_remote_retrieve_header($response, 'content-type');
		if (!str_starts_with($content_type, 'image/')) {
			$content_type = 'image/png';
		}

		return 'data:' . $content_type . ';base64,' . base64_encode($body);
	}
}
