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

	/**
	 * Render placeholders using a central path-based syntax.
	 *
	 * Supported:
	 * - {payload.topic}
	 * - {payload.content_fields.body.sources_count || 3}
	 * - {research.data}
	 *
	 * @param array<string,mixed> $context
	 */
	public function render_with_context(string $template, array $context): string
	{
		if ($template === '') {
			return '';
		}

		return (string) preg_replace_callback(
			'/\{\s*([a-zA-Z_][a-zA-Z0-9_.]*)(?:\s*\|\|\s*([^{}]+?))?\s*\}/',
			function (array $matches) use ($context): string {
				$path = (string) ($matches[1] ?? '');
				$default_raw = array_key_exists(2, $matches) ? trim((string) $matches[2]) : null;

				$value = $this->get_by_path($context, $path);
				if ($value === null || $value === '') {
					$value = $this->parse_default_value($default_raw);
				}

				return $this->stringify_value($value);
			},
			$template
		);
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

	/**
	 * @param array<string,mixed> $context
	 * @return mixed
	 */
	private function get_by_path(array $context, string $path)
	{
		$parts = array_filter(explode('.', $path), static fn($part) => $part !== '');
		if (empty($parts)) {
			return null;
		}

		$current = $context;
		foreach ($parts as $part) {
			if (!is_array($current) || !array_key_exists($part, $current)) {
				return null;
			}
			$current = $current[$part];
		}

		return $current;
	}

	/**
	 * @return mixed
	 */
	private function parse_default_value(?string $raw)
	{
		if ($raw === null || $raw === '') {
			return '';
		}

		$value = trim($raw);
		// Guard for typo variants like "{x || 3)".
		if (str_ends_with($value, ')') && !str_contains($value, '(')) {
			$value = rtrim($value, ')');
		}
		$value = trim($value);

		if ($value === 'null') {
			return '';
		}
		if ($value === 'true') {
			return true;
		}
		if ($value === 'false') {
			return false;
		}
		if (is_numeric($value)) {
			return str_contains($value, '.') ? (float) $value : (int) $value;
		}

		$quoted = preg_match('/^([\'"])(.*)\1$/s', $value, $m);
		if ($quoted === 1) {
			return (string) ($m[2] ?? '');
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 */
	private function stringify_value($value): string
	{
		if (is_array($value) || is_object($value)) {
			return wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return '';
		}
		return (string) $value;
	}
}
