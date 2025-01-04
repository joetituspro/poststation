<?php

namespace PostStation\Admin;

class Settings
{
	private const OPTION_KEY = 'poststation_api_key';
	private const MENU_SLUG = 'poststation';

	public function __construct()
	{
		add_action('admin_init', [$this, 'register_settings']);
	}

	public function register_settings(): void
	{
		register_setting(self::MENU_SLUG . '_settings', self::OPTION_KEY);
	}

	public function render_settings_page(): void
	{
		$api_key = get_option(self::OPTION_KEY, '');
?>
<div class="wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields(self::MENU_SLUG . '_settings'); ?>
		<table class="form-table">
			<tr>
				<th scope="row"><?php _e('API Key', 'poststation'); ?></th>
				<td>
					<input type="text" id="<?php echo esc_attr(self::OPTION_KEY); ?>"
						name="<?php echo esc_attr(self::OPTION_KEY); ?>" value="<?php echo esc_attr($api_key); ?>"
						class="regular-text" readonly>
					<p class="description">
						<?php _e('Use this API key in your requests with the X-API-Key header.', 'poststation'); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
<?php
	}

	public static function get_menu_slug(): string
	{
		return self::MENU_SLUG;
	}
}