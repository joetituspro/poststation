<?php

namespace PostStation\Services\Workflow;

use PostStation\Models\WorkflowSpec;

class WorkflowSpecImporter
{
	public const DEFAULT_VERSION = '1.9.0-local';

	public function ensure_default_spec_seeded(): bool
	{
		$spec = $this->get_default_spec();
		$hash = sha1(wp_json_encode($spec) ?: '');
		return (bool) WorkflowSpec::upsert_active(self::DEFAULT_VERSION, $hash, $spec);
	}

	/**
	 * Lightweight normalized workflow spec seeded from the n8n blueprint logic.
	 *
	 * @return array<string,mixed>
	 */
	public function get_default_spec(): array
	{
		return [
			'version' => self::DEFAULT_VERSION,
			'progress_labels' => [
				'starting' => 'Starting',
				'researching' => 'Researching',
				'scraping' => 'Scraping %s',
				'analysis' => 'Performing Analysis',
				'preliminary_plan' => 'Building Preliminary Plan',
				'outline' => 'Crafting Article Outline',
				'internal_links' => 'Sorting Internal Links',
				'writing' => 'Writing Article Draft',
				'custom_fields' => 'Generating for Custom Fields',
				'featured_image' => 'Generating Featured Image',
				'taxonomies' => 'Handling Taxonomies',
				'finalizing' => 'Finalizing',
			],
			'prompts' => [
				'analysis' => 'Analyze the research and return a concise JSON summary with: topic, search_intent, structural_patterns, semantic_keywords, gaps.',
				'outline' => 'Create a practical SEO article outline in JSON with introduction, body sections, and writing notes.',
				'writer' => 'Write a complete markdown article draft using the outline and research. Avoid fluff and keep factual.',
				'extras' => 'From the draft produce JSON {title,slug,key_takeaways[],faq[{q,a}],conclusion}.',
				'taxonomy_choose' => 'From the available terms choose best matching terms (comma separated).',
				'taxonomy_generate' => 'Generate taxonomy terms (comma separated) aligned with the article.',
				'custom_field' => 'Generate value for the custom field based on prompt and context.',
				'featured_image_prompt' => 'Return JSON {prompt,alt_text} for a featured image based on title and topic.',
			],
			'fallbacks' => [
				'allow_fallback_to_wp_scrape' => true,
				'allow_continue_without_images' => true,
			],
		];
	}
}
