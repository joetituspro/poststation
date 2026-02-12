<?php

namespace PostStation\Admin\Ajax;

class NonceVerifier
{
	public static function verify(): bool
	{
		$nonce = $_POST['nonce'] ?? '';
		return wp_verify_nonce($nonce, 'poststation_campaign_action')
			|| wp_verify_nonce($nonce, 'poststation_react_action');
	}
}
