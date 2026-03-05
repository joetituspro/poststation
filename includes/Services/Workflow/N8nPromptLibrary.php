<?php

namespace PostStation\Services\Workflow;

class N8nPromptLibrary
{
	/**
	 * Load prompt template from bundled n8n-derived prompt files.
	 */
	public function load(string $name): string
	{
		$path = trailingslashit(POSTSTATION_PATH) . 'resources/workflow-prompts/n8n/' . $name;
		if (!is_readable($path)) {
			return '';
		}
		$raw = file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return '';
		}

		// n8n expression bodies often begin with "=". Keep source exact but remove the prefix for LLM input.
		return $this->normalize_template($raw);
	}

	/**
	 * @param array<string,string> $replacements
	 */
	public function render(string $template, array $replacements): string
	{
		if ($template === '') {
			return '';
		}
		return strtr($template, $replacements);
	}

	public function now_string(): string
	{
		return wp_date('Y-m-d H:i:s');
	}

	private function normalize_template(string $template): string
	{
		$clean = preg_replace('/^\xEF\xBB\xBF/', '', $template) ?: $template;
		$clean = trim($clean);
		if (str_starts_with($clean, '=')) {
			$clean = ltrim(substr($clean, 1));
		}
		return $clean;
	}
}

