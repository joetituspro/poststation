<?php

namespace PostStation\Services;

class CryptoService
{
	private const CIPHER = 'aes-256-gcm';
	private const IV_LENGTH = 12;
	private const TAG_LENGTH = 16;

	public function encrypt(string $plain_text, string $context): string
	{
		$plain_text = trim($plain_text);
		if ($plain_text === '' || !function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
			return '';
		}

		$key = $this->get_key($context);
		$iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
		$tag = '';
		$ciphertext = openssl_encrypt($plain_text, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

		if (!is_string($ciphertext) || $ciphertext === '') {
			return '';
		}

		return base64_encode($iv . $tag . $ciphertext);
	}

	public function decrypt(string $encoded, string $context): string
	{
		if ($encoded === '' || !function_exists('openssl_decrypt')) {
			return '';
		}

		$payload = base64_decode($encoded, true);
		if (!is_string($payload) || strlen($payload) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
			return '';
		}

		$iv = substr($payload, 0, self::IV_LENGTH);
		$tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
		$ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
		$key = $this->get_key($context);

		$decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '');
		return is_string($decrypted) ? trim($decrypted) : '';
	}

	public function mask_secret(string $value): string
	{
		$value = trim($value);
		$length = strlen($value);
		if ($length <= 0) {
			return '';
		}

		if ($length <= 4) {
			return str_repeat('*', $length);
		}

		return str_repeat('*', $length - 4) . substr($value, -4);
	}

	private function get_key(string $context): string
	{
		$normalized_context = sanitize_key($context);
		if ($normalized_context === '') {
			$normalized_context = 'default';
		}

		return hash('sha256', wp_salt('auth') . '|poststation|' . $normalized_context, true);
	}
}
