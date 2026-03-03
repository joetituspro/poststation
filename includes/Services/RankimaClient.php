<?php

namespace PostStation\Services;

class RankimaClient
{
	public const DEFAULT_API_BASE_URL = 'http://localhost:3000';
	private const TIMEOUT = 20;
	private const RETRY_TIMEOUT = 25;

	/**
	 * @param array<string,mixed> $body
	 * @return array|\WP_Error
	 */
	public function post(string $endpoint, array $body = [])
	{
		return $this->request($endpoint, $body, 'POST');
	}

	public function get_base_url(): string
	{
		$base = self::DEFAULT_API_BASE_URL;

		$base = trim((string) esc_url_raw($base));
		if ($base === '') {
			$base = self::DEFAULT_API_BASE_URL;
		}

		return rtrim($base, '/');
	}

	/**
	 * @return array|\WP_Error
	 */
	public function request($endpoint, $body = [], $method = 'GET')
    {
        $site_key = $this->get_site_key();
		$site_domain = $this->get_site_domain();
		$license_key = AuthService::get_license_key();
        $license_key = isset($body['license_key']) && !empty($body['license_key']) ? $body['license_key'] : ($license_key ?? '');

		$data = array_merge($body, [
			'license_key' => $license_key,
            'site_key' => $site_key,
            'domain' => $site_domain,
            'timestamp' => time(),
            'signature' => $this->signature($site_key, $site_domain),
        ]);

		$url = $this->build_url($endpoint);
		$args = [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Accept' => 'application/json',
			],
			'sslverify' => false,
		];

		if ($method === 'POST') {
            $args['body'] = json_encode($data);
            $response = wp_remote_post($url, $args);
        } else {
            $args['body'] = $data;
            $response = wp_remote_get($url, $args);
        }

		if (is_wp_error($response)) {
            return ['status' => 'connection_error', 'message' => 'Error: Unable to connect to the host. ' . $response->get_error_message()];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return ['status' => 'connection_error', 'message' => 'Error: Host returned an unexpected response. Status code: ' . $response_code];
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);


        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['status' => 'connection_error', 'message' => 'Error: Invalid response from host.'];
        }

		return $response_body;
	}

	private function build_url(string $path, array $query = []): string
	{
		$path = '/' . ltrim($path, '/');
		$url = $this->get_base_url() . $path;

		if (!empty($query)) {
			$url = add_query_arg($query, $url);
		}

		return $url;
	}

	private function get_site_domain(): string
	{
		$domain = (string) parse_url(site_url(), PHP_URL_HOST);
		return sanitize_text_field($domain);
	}


	private function get_site_key(): string
	{
		$site_key = (string) get_option(AuthService::SITE_KEY_OPTION, '');
		return sanitize_text_field($site_key);
	}

	private function signature(string $site_key, string $site_domain): string
	{
		$site_key = trim($site_key);
		$site_domain = trim($site_domain);

		if ($site_key === '' || $site_domain === '') {
			return '';
		}

		return hash('sha256', $site_key . '|' . $site_domain);
	}

}
