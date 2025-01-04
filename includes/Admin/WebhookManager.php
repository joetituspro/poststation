<?php

namespace PostStation\Admin;

use PostStation\Models\Webhook;

class WebhookManager
{
	private const MENU_SLUG = 'poststation-webhooks';
	private const NONCE_ACTION = 'poststation_webhook_action';
	private const NONCE_NAME = 'poststation_webhook_nonce';

	public function __construct()
	{
		add_action('admin_post_poststation_save_webhook', [$this, 'handle_save_webhook']);
		add_action('admin_post_poststation_delete_webhook', [$this, 'handle_delete_webhook']);
	}

	public function render_page(): void
	{
		$action = $_GET['action'] ?? 'list';
		$webhook_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

		switch ($action) {
			case 'new':
				$this->render_form();
				break;
			case 'edit':
				$this->render_form($webhook_id);
				break;
			default:
				$this->render_list();
				break;
		}
	}

	private function render_list(): void
	{
		$webhooks = Webhook::get_all();
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php _e('Webhooks', 'poststation'); ?></h1>
	<a href="<?php echo esc_url(add_query_arg('action', 'new')); ?>" class="page-title-action">
		<?php _e('Add New', 'poststation'); ?>
	</a>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php _e('Name', 'poststation'); ?></th>
				<th><?php _e('URL', 'poststation'); ?></th>
				<th><?php _e('Author', 'poststation'); ?></th>
				<th><?php _e('Created', 'poststation'); ?></th>
				<th><?php _e('Actions', 'poststation'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if (empty($webhooks)) : ?>
			<tr>
				<td colspan="5"><?php _e('No webhooks found.', 'poststation'); ?></td>
			</tr>
			<?php endif; ?>

			<?php foreach ($webhooks as $webhook) : ?>
			<tr>
				<td><?php echo esc_html($webhook['name']); ?></td>
				<td><?php echo esc_url($webhook['url']); ?></td>
				<td><?php echo esc_html(get_user_by('id', $webhook['author_id'])->display_name); ?></td>
				<td><?php echo esc_html(get_date_from_gmt($webhook['created_at'])); ?></td>
				<td>
					<a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $webhook['id']])); ?>"
						class="button-link">
						<?php _e('Edit', 'poststation'); ?>
					</a>
					|
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
						style="display:inline;">
						<input type="hidden" name="action" value="poststation_delete_webhook">
						<input type="hidden" name="webhook_id" value="<?php echo esc_attr($webhook['id']); ?>">
						<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
						<button type="submit" class="button-link"
							onclick="return confirm('<?php esc_attr_e('Are you sure?', 'poststation'); ?>')">
							<?php _e('Delete', 'poststation'); ?>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php
	}

	private function render_form(?int $webhook_id = null): void
	{
		$webhook = null;
		if ($webhook_id) {
			$webhook = Webhook::get_by_id($webhook_id);
			if (!$webhook) {
				wp_die(__('Webhook not found.', 'poststation'));
			}
		}
	?>
<div class="wrap">
	<h1><?php echo $webhook ? __('Edit Webhook', 'poststation') : __('Add New Webhook', 'poststation'); ?></h1>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="poststation_save_webhook">
		<?php if ($webhook) : ?>
		<input type="hidden" name="webhook_id" value="<?php echo esc_attr($webhook['id']); ?>">
		<?php endif; ?>
		<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="webhook_name"><?php _e('Name', 'poststation'); ?></label>
				</th>
				<td>
					<input name="webhook_name" type="text" id="webhook_name"
						value="<?php echo esc_attr($webhook['name'] ?? ''); ?>" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="webhook_url"><?php _e('URL', 'poststation'); ?></label>
				</th>
				<td>
					<input name="webhook_url" type="url" id="webhook_url"
						value="<?php echo esc_attr($webhook['url'] ?? ''); ?>" class="regular-text" required>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
<?php
	}

	public function handle_save_webhook(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'poststation'));
		}

		check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

		$webhook_id = isset($_POST['webhook_id']) ? (int)$_POST['webhook_id'] : 0;
		$data = [
			'name' => sanitize_text_field($_POST['webhook_name']),
			'url' => esc_url_raw($_POST['webhook_url']),
		];

		if ($webhook_id > 0) {
			$success = Webhook::update($webhook_id, $data);
		} else {
			$success = Webhook::create($data);
		}



		wp_redirect(add_query_arg(
			[
				'page' => self::MENU_SLUG,
				'updated' => $success ? '1' : '0'
			],
			admin_url('admin.php')
		));
		exit;
	}

	public function handle_delete_webhook(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'poststation'));
		}

		check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

		$webhook_id = isset($_POST['webhook_id']) ? (int)$_POST['webhook_id'] : 0;
		$success = $webhook_id > 0 ? Webhook::delete($webhook_id) : false;

		wp_redirect(add_query_arg(
			[
				'page' => self::MENU_SLUG,
				'deleted' => $success ? '1' : '0'
			],
			admin_url('admin.php')
		));
		exit;
	}
}