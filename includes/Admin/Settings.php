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
			
			<div class="poststation-settings-card">
				<form method="post" action="options.php">
					<?php settings_fields(self::MENU_SLUG . '_settings'); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><?php _e('API Key', 'poststation'); ?></th>
							<td>
								<div class="api-key-container">
									<input type="text" id="<?php echo esc_attr(self::OPTION_KEY); ?>"
										name="<?php echo esc_attr(self::OPTION_KEY); ?>" value="<?php echo esc_attr($api_key); ?>"
										class="regular-text" readonly>
									<button type="button" class="button" onclick="copyApiKey()"><?php _e('Copy', 'poststation'); ?></button>
								</div>
								<p class="description">
									<?php _e('Use this API key in your requests with the X-API-Key header.', 'poststation'); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>

				<div class="settings-footer">
					<button type="button" class="button button-secondary" id="open-api-docs">
						<span class="dashicons dashicons-book-alt" style="vertical-align: middle; margin-right: 5px;"></span>
						<?php _e('View API Documentation', 'poststation'); ?>
					</button>
				</div>
			</div>

			<!-- API Documentation Modal -->
			<div id="poststation-modal-overlay" class="poststation-modal-overlay" style="display: none;">
				<div class="poststation-modal">
					<div class="poststation-modal-header">
						<h2><?php _e('API Documentation', 'poststation'); ?></h2>
						<button type="button" class="poststation-modal-close">&times;</button>
					</div>
					<div class="poststation-modal-content">
						<div class="poststation-api-docs">
							<p><?php _e('You can use the following endpoints to create posts programmatically.', 'poststation'); ?></p>

							<table class="widefat striped" style="margin-top: 20px;">
								<thead>
									<tr>
										<th><?php _e('Method', 'poststation'); ?></th>
										<th><?php _e('Endpoint', 'poststation'); ?></th>
										<th><?php _e('Description', 'poststation'); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><code>POST</code></td>
										<td><code><?php echo esc_url(rest_url('poststation/v1/create')); ?></code></td>
										<td><?php _e('WordPress REST API endpoint', 'poststation'); ?></td>
									</tr>
									<tr>
										<td><code>POST</code></td>
										<td><code><?php echo esc_url(site_url('ps-api/create')); ?></code></td>
										<td><?php _e('Custom API endpoint', 'poststation'); ?></td>
									</tr>
								</tbody>
							</table>

							<h3><?php _e('Authentication', 'poststation'); ?></h3>
							<p><?php _e('All requests must include the following HTTP header:', 'poststation'); ?></p>
							<div class="code-block">
								<code>X-API-Key: <?php echo esc_html($api_key); ?></code>
							</div>

							<h3><?php _e('Request Body (JSON)', 'poststation'); ?></h3>
							<p><?php _e('Send a JSON object with the following fields:', 'poststation'); ?></p>
							<div class="json-payload">
								<pre>{
  "title": "Post Title",
  "content": "Post content with &lt;h2&gt;headers&lt;/h2&gt; and other HTML tags.",
  "slug": "custom-post-slug",
  "thumbnail_url": "https://example.com/image.jpg",
  "taxonomies": {
    "category": ["News", "Updates"],
    "post_tag": ["WordPress", "API"]
  },
  "custom_fields": {
    "your_meta_key": "your_meta_value"
  },
  "block_id": 123
}</pre>
							</div>

							<p style="margin-top: 15px;">
								<strong><?php _e('Field Descriptions:', 'poststation'); ?></strong>
							</p>
							<ul class="ul-disc">
								<li><strong>title</strong> (<?php _e('string', 'poststation'); ?>): <?php _e('The title of the post.', 'poststation'); ?></li>
								<li><strong>content</strong> (<?php _e('string', 'poststation'); ?>): <?php _e('The content of the post. HTML is supported.', 'poststation'); ?></li>
								<li><strong>slug</strong> (<?php _e('string, optional', 'poststation'); ?>): <?php _e('The URL slug for the post.', 'poststation'); ?></li>
								<li><strong>thumbnail_url</strong> (<?php _e('string, optional', 'poststation'); ?>): <?php _e('URL of an image to be used as the featured image.', 'poststation'); ?></li>
								<li><strong>taxonomies</strong> (<?php _e('object, optional', 'poststation'); ?>): <?php _e('An object where keys are taxonomy names and values are arrays of term names.', 'poststation'); ?></li>
								<li><strong>custom_fields</strong> (<?php _e('object, optional', 'poststation'); ?>): <?php _e('An object of meta keys and values to be stored with the post.', 'poststation'); ?></li>
								<li><strong>block_id</strong> (<?php _e('integer, optional', 'poststation'); ?>): <?php _e('The ID of a PostBlock. If provided, the block status will be updated to "completed" upon success.', 'poststation'); ?></li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script>
			function copyApiKey() {
				const apiKeyInput = document.getElementById('<?php echo esc_attr(self::OPTION_KEY); ?>');
				apiKeyInput.select();
				document.execCommand('copy');
				
				const btn = event.currentTarget;
				const originalText = btn.textContent;
				btn.textContent = '<?php _e('Copied!', 'poststation'); ?>';
				setTimeout(() => {
					btn.textContent = originalText;
				}, 2000);
			}

			document.addEventListener('DOMContentLoaded', function() {
				const modal = document.getElementById('poststation-modal-overlay');
				const openBtn = document.getElementById('open-api-docs');
				const closeBtn = document.querySelector('.poststation-modal-close');

				openBtn.addEventListener('click', () => {
					modal.style.display = 'flex';
					document.body.style.overflow = 'hidden';
				});

				const closeModal = () => {
					modal.style.display = 'none';
					document.body.style.overflow = '';
				};

				closeBtn.addEventListener('click', closeModal);
				modal.addEventListener('click', (e) => {
					if (e.target === modal) closeModal();
				});
			});
		</script>

		<style>
			.poststation-settings-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin-top: 20px;
				max-width: 800px;
			}
			.api-key-container {
				display: flex;
				gap: 10px;
				align-items: center;
			}
			.settings-footer {
				margin-top: 20px;
				padding-top: 20px;
				border-top: 1px solid #f0f0f1;
			}
			.poststation-modal-overlay {
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: rgba(0, 0, 0, 0.7);
				display: flex;
				justify-content: center;
				align-items: center;
				z-index: 999999;
			}
			.poststation-modal {
				background: #fff;
				width: 90%;
				max-width: 900px;
				max-height: 85vh;
				border-radius: 4px;
				display: flex;
				flex-direction: column;
				box-shadow: 0 10px 25px rgba(0,0,0,0.2);
			}
			.poststation-modal-header {
				padding: 15px 25px;
				border-bottom: 1px solid #f0f0f1;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.poststation-modal-header h2 {
				margin: 0;
				font-size: 1.3em;
			}
			.poststation-modal-close {
				background: none;
				border: none;
				font-size: 28px;
				cursor: pointer;
				color: #646970;
				line-height: 1;
				padding: 0;
			}
			.poststation-modal-close:hover {
				color: #d63638;
			}
			.poststation-modal-content {
				padding: 25px;
				overflow-y: auto;
			}
			.poststation-api-docs h3 {
				margin-top: 25px;
				padding-bottom: 8px;
				border-bottom: 1px solid #f0f0f1;
			}
			.code-block {
				background: #f0f0f1;
				padding: 10px 15px;
				border-radius: 3px;
				margin: 10px 0;
			}
			.json-payload {
				background: #272822;
				color: #f8f8f2;
				padding: 15px;
				border-radius: 4px;
				overflow-x: auto;
				margin: 10px 0;
			}
			.json-payload pre {
				margin: 0;
				font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace;
				font-size: 13px;
				line-height: 1.5;
			}
			.poststation-api-docs ul {
				margin-left: 20px;
			}
			.poststation-api-docs ul li {
				margin-bottom: 8px;
			}
		</style>
		<?php
	}

	public static function get_menu_slug(): string
	{
		return self::MENU_SLUG;
	}
}