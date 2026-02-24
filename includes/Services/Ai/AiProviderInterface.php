<?php

namespace PostStation\Services\Ai;

interface AiProviderInterface
{
	/**
	 * Unique provider key (for example: openrouter).
	 */
	public function get_key(): string;

	/**
	 * Generate instruction preset fields from user input/context.
	 *
	 * @param string $brief   User prompt.
	 * @param array  $context Optional context (for example article URL/content).
	 * @return array|\WP_Error
	 */
	public function generate_instruction_preset(string $brief, array $context = []);
}

