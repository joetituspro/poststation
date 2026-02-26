<?php

namespace PostStation\Services\Ai;

interface AiProviderInterface
{
	/**
	 * Unique provider key (for example: openrouter).
	 */
	public function get_key(): string;

	/**
	 * Generate writing preset fields from user input/context.
	 *
	 * @param string $brief   User prompt.
	 * @param array  $context Optional context (for example article URL/content).
	 * @param array  $options Provider options (for example model override).
	 * @return array|\WP_Error
	 */
	public function generate_writing_preset(string $brief, array $context = [], array $options = []);
}
