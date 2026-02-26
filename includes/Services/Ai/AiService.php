<?php

namespace PostStation\Services\Ai;

class AiService
{
	/** @var array<string, AiProviderInterface> */
	private array $providers = [];

	/**
	 * @param AiProviderInterface[]|null $providers
	 */
	public function __construct(?array $providers = null)
	{
		if (is_array($providers) && !empty($providers)) {
			foreach ($providers as $provider) {
				if ($provider instanceof AiProviderInterface) {
					$this->providers[$provider->get_key()] = $provider;
				}
			}
		}

		if (empty($this->providers)) {
			$openrouter = new OpenRouterAiProvider();
			$this->providers[$openrouter->get_key()] = $openrouter;
		}
	}

	/**
	 * @return array|\WP_Error
	 */
	public function generate_writing_preset(string $brief, string $provider_key = 'openrouter', array $options = [])
	{
		$brief = trim($brief);
		if ($brief === '') {
			return new \WP_Error('empty_prompt', 'Prompt is required.');
		}

		$provider = $this->providers[$provider_key] ?? null;
		if (!$provider) {
			return new \WP_Error('unsupported_provider', 'AI provider is not supported.');
		}

		$context = $this->build_context_from_prompt($brief);
		return $provider->generate_writing_preset($brief, $context, $options);
	}

	private function build_context_from_prompt(string $brief): array
	{
		$context = [];
		$url = $this->extract_first_url($brief);
		if ($url === '') {
			return $context;
		}

		$context['article_url'] = $url;
		$excerpt = $this->fetch_article_excerpt($url);
		if ($excerpt !== '') {
			$context['article_excerpt'] = $excerpt;
		}

		return $context;
	}

	private function extract_first_url(string $text): string
	{
		if (!preg_match('/https?:\/\/[^\s<>"\'\]\)]+/i', $text, $matches)) {
			return '';
		}

		$url = esc_url_raw((string) ($matches[0] ?? ''));
		return is_string($url) ? trim($url) : '';
	}

	private function fetch_article_excerpt(string $url): string
	{
		$response = wp_remote_get($url, ['timeout' => 20]);
		if (is_wp_error($response)) {
			return '';
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		if ($status < 200 || $status >= 300) {
			return '';
		}

		$html = (string) wp_remote_retrieve_body($response);
		if ($html === '') {
			return '';
		}

		$text = wp_strip_all_tags($html, true);
		$text = preg_replace('/\s+/', ' ', $text);
		$text = trim((string) $text);
		if ($text === '') {
			return '';
		}

		$limit = 4000;
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, $limit);
		}
		return substr($text, 0, $limit);
	}
}
