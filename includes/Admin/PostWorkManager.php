<?php

namespace PostStation\Admin;

use PostStation\Models\PostWork;
use PostStation\Models\PostBlock;
use PostStation\Models\Webhook;
use Exception;

class PostWorkManager
{
	private const MENU_SLUG = 'poststation-postworks';
	private const NONCE_ACTION = 'poststation_postwork_action';
	private const NONCE_NAME = 'poststation_postwork_nonce';

	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('wp_ajax_poststation_create_postwork', [$this, 'handle_create_postwork']);
		add_action('wp_ajax_poststation_update_postwork', [$this, 'handle_update_postwork']);
		add_action('wp_ajax_poststation_delete_postwork', [$this, 'handle_delete_postwork']);
		add_action('wp_ajax_poststation_create_postblock', [$this, 'handle_create_postblock']);
		add_action('wp_ajax_poststation_update_blocks', [$this, 'handle_update_blocks']);
		add_action('wp_ajax_poststation_delete_postblock', [$this, 'handle_delete_postblock']);
		add_action('wp_ajax_poststation_run_postwork', [$this, 'handle_run_postwork']);
	}

	public function enqueue_scripts(): void
	{
		$screen = get_current_screen();
		if ($screen->id !== 'poststation_page_' . self::MENU_SLUG) {
			// return;
		}

		wp_enqueue_style('poststation-admin');
		wp_enqueue_script('poststation-postwork', POSTSTATION_URL . 'assets/js/postwork.js', ['jquery'], POSTSTATION_VERSION, true);

		// Add media scripts
		wp_enqueue_media();

		// Get available post types
		$post_types = get_post_types(['public' => true], 'objects');
		$post_type_options = [];
		foreach ($post_types as $type) {
			$post_type_options[$type->name] = $type->labels->singular_name;
		}

		// Get available taxonomies
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		$taxonomy_data = [];
		foreach ($taxonomies as $tax) {
			$taxonomy_data[$tax->name] = [
				'name' => $tax->name,
				'label' => $tax->labels->name,
				'singular_label' => $tax->labels->singular_name
			];
		}

		wp_localize_script('poststation-postwork', 'poststation', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'admin_url' => admin_url(),
			'rest_url' => rest_url(),
			'nonce' => wp_create_nonce(self::NONCE_ACTION),
			'post_types' => $post_type_options,
			'taxonomies' => $taxonomy_data
		]);
	}

	public function render_page(): void
	{
		$action = $_GET['action'] ?? 'list';
		$postwork_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

		switch ($action) {
			case 'edit':
				$this->render_edit_page($postwork_id);
				break;
			default:
				$this->render_list_page();
				break;
		}
	}

	private function render_list_page(): void
	{
		$postworks = PostWork::get_all();
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php _e('Post Works', 'poststation'); ?></h1>
	<button class="page-title-action" id="add-new-postwork">
		<?php _e('Add New', 'poststation'); ?>
	</button>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php _e('Title', 'poststation'); ?></th>
				<th><?php _e('Blocks', 'poststation'); ?></th>
				<th><?php _e('Author', 'poststation'); ?></th>
				<th><?php _e('Created', 'poststation'); ?></th>
				<th><?php _e('Actions', 'poststation'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if (empty($postworks)) : ?>
			<tr>
				<td colspan="5"><?php _e('No post works found.', 'poststation'); ?></td>
			</tr>
			<?php endif; ?>

			<?php foreach ($postworks as $postwork) :
						$blocks = PostBlock::get_by_postwork($postwork['id']);
					?>
			<tr>
				<td>
					<a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $postwork['id']])); ?>">
						<?php echo esc_html($postwork['title']); ?>
					</a>
				</td>
				<td><?php echo count($blocks); ?></td>
				<td><?php echo esc_html(get_user_by('id', $postwork['author_id'])->display_name); ?></td>
				<td><?php echo esc_html(get_date_from_gmt($postwork['created_at'])); ?></td>
				<td>
					<a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $postwork['id']])); ?>"
						class="button-link">
						<?php _e('Edit', 'poststation'); ?>
					</a>
					|
					<button type="button" class="button-link delete-postwork"
						data-id="<?php echo esc_attr($postwork['id']); ?>">
						<?php _e('Delete', 'poststation'); ?>
					</button>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php
	}

	private function render_edit_page(int $postwork_id): void
	{
		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_die(__('Post work not found.', 'poststation'));
		}

		$blocks = PostBlock::get_by_postwork($postwork_id);
		$webhooks = Webhook::get_all();
	?>
<div class="wrap">
	<div class="poststation-main-header">
		<div class="poststation-header-left">
			<div class="poststation-logo">
				<span class="dashicons dashicons-rest-api"></span>
				Post Station
			</div>
			<div class="postwork-title-wrapper">
				<span class="separator">|</span>
				<div class="postwork-title" id="title-display">
					<span class="title-text"><?php echo esc_html($postwork['title']); ?></span>
					<span class="dashicons dashicons-edit"></span>
					<input type="text" id="postwork-title" value="<?php echo esc_attr($postwork['title']); ?>"
						style="display: none;">
				</div>
			</div>
		</div>
		<div class="poststation-header-actions">
			<button type="button" class="button toggle-options">
				<span class="dashicons dashicons-admin-generic"></span>
				<span class="button-text">Show Options</span>
			</button>
			<button type="button" class="button show-api-format">
				<span class="dashicons dashicons-code-standards"></span>
				<span class="button-text">API Response Format</span>
			</button>
		</div>
	</div>

	<div class="loading-overlay">
		<span class="spinner is-active"></span>
		<div class="message"><?php _e('Saving...', 'poststation'); ?></div>
	</div>

	<div id="postwork-form">
		<input type="hidden" id="postwork-id" value="<?php echo esc_attr($postwork['id']); ?>">

		<div class="postwork-header" style="display: none;">
			<div class="postwork-header-content">
				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Post Type', 'poststation'); ?></div>
						<div class="postwork-header-value">
							<span class="post-type-text">
								<?php
										$post_types = get_post_types(['public' => true], 'objects');
										echo esc_html($post_types[$postwork['post_type']]->labels->singular_name);
										?>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<select id="post-type" style="display: none;">
								<?php foreach ($post_types as $type) : ?>
								<option value="<?php echo esc_attr($type->name); ?>"
									<?php selected($type->name, $postwork['post_type']); ?>>
									<?php echo esc_html($type->labels->singular_name); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Default Status', 'poststation'); ?></div>
						<div class="postwork-header-value">
							<span class="status-text"
								data-status="<?php echo esc_attr($postwork['post_status'] ?? 'pending'); ?>">
								<?php
										$statuses = get_post_statuses();
										echo esc_html($statuses[$postwork['post_status']] ?? 'Pending');
										?>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<select id="post-status" style="display: none;">
								<?php foreach (get_post_statuses() as $status => $label) : ?>
								<option value="<?php echo esc_attr($status); ?>"
									<?php selected($status, $postwork['post_status'] ?? 'pending'); ?>>
									<?php echo esc_html($label); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Default Author', 'poststation'); ?></div>
						<div class="postwork-header-value">
							<span class="author-text">
								<?php
										$default_author = !empty($postwork['default_author_id'])
											? get_userdata($postwork['default_author_id'])
											: get_userdata(get_current_user_id());
										echo esc_html($default_author->display_name);
										?>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<select id="default-author-id" style="display: none;">
								<?php
										$users = get_users(['role__in' => ['administrator', 'editor', 'author']]);
										foreach ($users as $user) :
										?>
								<option value="<?php echo esc_attr($user->ID); ?>"
									<?php selected($user->ID, $postwork['default_author_id'] ?? get_current_user_id()); ?>>
									<?php echo esc_html($user->display_name); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Webhook', 'poststation'); ?></div>
						<div class="postwork-header-value">
							<span class="webhook-text">
								<?php
										if ($postwork['webhook_id']) {
											foreach ($webhooks as $webhook) {
												if ($webhook['id'] === $postwork['webhook_id']) {
													echo esc_html($webhook['name']);
													break;
												}
											}
										} else {
											_e('Select Webhook', 'poststation');
										}
										?>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<select id="webhook-id" style="display: none;">
								<option value=""><?php _e('Select a webhook', 'poststation'); ?></option>
								<?php foreach ($webhooks as $webhook) : ?>
								<option value="<?php echo esc_attr($webhook['id']); ?>"
									<?php selected($webhook['id'], $postwork['webhook_id']); ?>>
									<?php echo esc_html($webhook['name']); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Taxonomies', 'poststation'); ?></div>
						<div class="postwork-header-value" id="taxonomy-trigger">
							<span class="taxonomy-text"><?php _e('Configure', 'poststation'); ?></span>
							<span class="dashicons dashicons-edit"></span>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('AI Prompts', 'poststation'); ?></div>
						<div class="postwork-header-value" id="prompts-trigger">
							<span class="prompts-text"><?php _e('Configure', 'poststation'); ?></span>
							<span class="dashicons dashicons-edit"></span>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Custom Fields', 'poststation'); ?></div>
						<div class="postwork-header-value" id="custom-fields-trigger">
							<span class="custom-fields-text"><?php _e('Configure', 'poststation'); ?></span>
							<span class="dashicons dashicons-edit"></span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Side Panel Overlay (shared) -->
		<div class="side-panel-overlay"></div>

		<!-- Taxonomy Panel -->
		<div class="side-panel taxonomy-panel">
			<div class="side-panel-header">
				<div class="side-panel-title"><?php _e('Configure Taxonomies', 'poststation'); ?></div>
				<div class="side-panel-close">
					<span class="dashicons dashicons-no-alt"></span>
				</div>
			</div>
			<div class="side-panel-content">
				<?php
						$enabled_taxonomies = !empty($postwork['enabled_taxonomies']) ? json_decode($postwork['enabled_taxonomies'], true) : ['category' => true, 'post_tag' => true];
						$default_terms = !empty($postwork['default_terms']) ? json_decode($postwork['default_terms'], true) : [];

						$taxonomies = get_taxonomies(['public' => true], 'objects');
						foreach ($taxonomies as $tax) :
							$is_enabled = isset($enabled_taxonomies[$tax->name]) ? $enabled_taxonomies[$tax->name] : false;
							$tax_default_terms = isset($default_terms[$tax->name]) ? $default_terms[$tax->name] : [];
						?>
				<div class="taxonomy-setting-row" data-taxonomy="<?php echo esc_attr($tax->name); ?>">
					<label class="taxonomy-checkbox-wrapper">
						<input type="checkbox" class="taxonomy-checkbox" name="enabled_taxonomies[]"
							value="<?php echo esc_attr($tax->name); ?>" <?php checked($is_enabled); ?>
							<?php if (in_array($tax->name, ['category', 'post_tag'])) echo 'checked disabled'; ?>>
						<?php echo esc_html($tax->labels->name); ?>
					</label>
					<div class="taxonomy-defaults <?php echo $is_enabled ? 'active' : ''; ?>">
						<div class="taxonomy-defaults-label">
							<?php printf(__('Default %s', 'poststation'), $tax->labels->name); ?></div>
						<select class="default-terms-select" multiple="multiple"
							data-taxonomy="<?php echo esc_attr($tax->name); ?>"
							data-placeholder="<?php printf(__('Select default %s', 'poststation'), strtolower($tax->labels->name)); ?>">
							<?php
										$terms = get_terms([
											'taxonomy' => $tax->name,
											'hide_empty' => false,
										]);
										foreach ($terms as $term) :
											$selected = in_array($term->slug, $tax_default_terms);
										?>
							<option value="<?php echo esc_attr($term->slug); ?>" <?php selected($selected); ?>>
								<?php echo esc_html($term->name); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Prompts Panel -->
		<div class="side-panel prompts-panel">
			<div class="side-panel-header">
				<div class="side-panel-title"><?php _e('AI Prompts', 'poststation'); ?></div>
				<div class="side-panel-close">
					<span class="dashicons dashicons-no-alt"></span>
				</div>
			</div>
			<div class="side-panel-actions">
				<button type="button" class="button add-prompt-button">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php _e('Add New Prompt', 'poststation'); ?>
				</button>
			</div>
			<div class="side-panel-content">
				<?php
						$default_prompts = [
							'post_title' => [
								'title' => 'Post Title',
								'content' => 'Generate a clear and engaging title for this article.'
							],
							'post_content' => [
								'title' => 'Post Content',
								'content' => 'Generate comprehensive and well-structured content for this article.'
							]
						];

						$prompts = !empty($postwork['prompts']) ? json_decode($postwork['prompts'], true) : $default_prompts;
						foreach ($prompts as $key => $prompt) :
						?>
				<div class="prompt-item" data-key="<?php echo esc_attr($key); ?>">
					<div class="prompt-header">
						<input type="text" class="regular-text prompt-title-input"
							value="<?php echo esc_attr($prompt['title']); ?>"
							placeholder="<?php esc_attr_e('Prompt Title', 'poststation'); ?>"
							<?php echo in_array($key, ['post_title', 'post_content', 'thumbnail']) ? 'readonly' : ''; ?>>
						<div class="prompt-actions">
							<?php if (!in_array($key, ['post_title', 'post_content', 'thumbnail'])) : ?>
							<span class="prompt-delete dashicons dashicons-trash"
								title="<?php esc_attr_e('Delete Prompt', 'poststation'); ?>"></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="prompt-content">
						<textarea class="prompt-textarea"
							placeholder="<?php esc_attr_e('Enter your prompt content here...', 'poststation'); ?>"><?php echo esc_textarea($prompt['content']); ?></textarea>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Custom Fields Panel -->
		<div class="side-panel custom-fields-panel">
			<div class="side-panel-header">
				<div class="side-panel-title"><?php _e('Custom Fields', 'poststation'); ?></div>
				<div class="side-panel-close">
					<span class="dashicons dashicons-no-alt"></span>
				</div>
			</div>
			<div class="side-panel-actions">
				<button type="button" class="button add-custom-field-button">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php _e('Add Custom Field', 'poststation'); ?>
				</button>
			</div>
			<div class="side-panel-content">
				<div class="custom-fields-container">
					<?php
							$custom_fields = !empty($postwork['custom_fields']) ? json_decode($postwork['custom_fields'], true) : [];
							foreach ($custom_fields as $key => $value) :
							?>
					<div class="custom-field-item" data-key="<?php echo esc_attr($key); ?>">
						<div class="custom-field-header">
							<div class="custom-field-key">
								<input type="text" class="regular-text custom-field-key-input"
									value="<?php echo esc_attr($key); ?>"
									placeholder="<?php esc_attr_e('Meta Key', 'poststation'); ?>">
								<div class="error-message"></div>
							</div>
							<div class="custom-field-actions">
								<span class="custom-field-delete dashicons dashicons-trash"
									title="<?php esc_attr_e('Delete Field', 'poststation'); ?>"></span>
							</div>
						</div>
						<div class="custom-field-content">
							<textarea class="custom-field-value"
								placeholder="<?php esc_attr_e('Default Value', 'poststation'); ?>"><?php echo esc_textarea($value); ?></textarea>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="postwork-actions">
			<div class="postwork-actions-content">
				<div class="postwork-actions-left">
					<select id="block-status-filter" class="regular-text">
						<option value="all" selected><?php _e('All Blocks', 'poststation'); ?> (<span
								class="status-count">0</span>)</option>
						<option value="pending"><?php _e('Pending', 'poststation'); ?> (<span
								class="status-count">0</span>)</option>
						<option value="processing"><?php _e('Processing', 'poststation'); ?> (<span
								class="status-count">0</span>)</option>
						<option value="completed"><?php _e('Completed', 'poststation'); ?> (<span
								class="status-count">0</span>)</option>
						<option value="failed"><?php _e('Failed', 'poststation'); ?> (<span
								class="status-count">0</span>)</option>
					</select>
					<button type="button" class="button" id="add-postblock">
						<?php _e('Add Post Block', 'poststation'); ?>
					</button>
					<button type="button" class="button button-primary" id="save-postwork">
						<?php _e('Save', 'poststation'); ?>
					</button>
				</div>
				<div class="postwork-actions-right">
					<div class="postwork-state saving-required">
						<span class="dashicons dashicons-warning"></span>
						<span class="state-message"><?php _e('Saving Required', 'poststation'); ?></span>
					</div>
					<button type="button" class="button button-secondary" id="run-postwork">
						<?php _e('Run', 'poststation'); ?>
					</button>
				</div>
			</div>
		</div>

		<div id="postblocks">
			<?php foreach ($blocks as $block) :
						$taxonomies = !empty($block['taxonomies']) ? json_decode($block['taxonomies'], true) : [];
						$block_custom_fields = !empty($block['custom_fields']) ? json_decode($block['custom_fields'], true) : [];
						$postwork_custom_fields = !empty($postwork['custom_fields']) ? json_decode($postwork['custom_fields'], true) : [];
						$postwork_prompts = !empty($postwork['prompts']) ? json_decode($postwork['prompts'], true) : [];
						$prompts = !empty($block['prompts']) ? json_decode($block['prompts'], true) : $postwork_prompts;

					?>
			<div class="postblock" data-id="<?php echo esc_attr($block['id']); ?>"
				data-status="<?php echo esc_attr($block['status']); ?>">
				<div class="postblock-header">
					<div class="postblock-header-info">
						<span class="block-id">#<?php echo esc_html($block['id']); ?></span>
						<span class="block-url" title="<?php echo esc_attr($block['article_url']); ?>">
							<?php echo esc_html(wp_parse_url($block['article_url'], PHP_URL_HOST) . wp_parse_url($block['article_url'], PHP_URL_PATH)); ?>
						</span>
						<span class="block-error-count">
							<span class="dashicons dashicons-warning"></span>
							<span class="error-count-text"></span>
						</span>
						<?php if (!empty($block['post_id']) && get_post($block['post_id'])) : ?>
						<a href="<?php echo esc_url(get_edit_post_link($block['post_id'])); ?>" class="block-edit-link"
							target="_blank" title="<?php esc_attr_e('Edit Post', 'poststation'); ?>">
							<span class="dashicons dashicons-edit"></span>
						</a>
						<?php endif; ?>
					</div>
					<div class="postblock-header-actions">
						<span class="block-status-badge <?php echo esc_attr($block['status']); ?>"
							title="<?php echo esc_attr($block['error_message'] ?? ''); ?>">
							<?php echo esc_html($block['status']); ?>
						</span>
						<?php if ($block['status'] === 'failed') : ?>
						<button type="button" class="button-link run-block"
							title="<?php esc_attr_e('Run this block', 'poststation'); ?>">
							<span class="dashicons dashicons-controls-play"></span>
						</button>
						<?php endif; ?>
						<button type="button" class="button-link duplicate-postblock"
							title="<?php esc_attr_e('Duplicate', 'poststation'); ?>">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
						<button type="button" class="button-link delete-postblock"
							title="<?php esc_attr_e('Delete', 'poststation'); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</div>
				</div>
				<div class="postblock-content" style="display: none;">
					<div class="postblock-form">
						<!-- Left Column -->
						<div class="postblock-column">
							<div class="form-section">
								<h3 class="section-title"><?php _e('Basic Information', 'poststation'); ?></h3>
								<div class="form-field">
									<label><?php _e('Article URL', 'poststation'); ?></label>
									<div class="field-input">
										<input type="url" class="regular-text article-url"
											value="<?php echo esc_attr($block['article_url']); ?>" required>
										<div class="error-message"></div>
									</div>
								</div>

								<div class="form-field">
									<label><?php _e('Post Title', 'poststation'); ?></label>
									<div class="field-input">
										<input type="text" class="regular-text post-title"
											value="<?php echo esc_attr($block['post_title'] ?? ''); ?>"
											placeholder="<?php esc_attr_e('Enter post title', 'poststation'); ?>">

									</div>
								</div>

								<div class="form-field">
									<label><?php _e('Featured Image', 'poststation'); ?></label>
									<div class="field-input feature-image-field">
										<div class="feature-image-preview"
											style="display: <?php echo !empty($block['feature_image_id']) ? 'block' : 'none'; ?>">
											<img src="<?php echo !empty($block['feature_image_id']) ?
																		wp_get_attachment_image_url($block['feature_image_id'], 'thumbnail') : ''; ?>" alt="">
											<button type="button" class="button remove-feature-image">
												<span class="dashicons dashicons-no"></span>
												<?php _e('Remove Image', 'poststation'); ?>
											</button>
										</div>
										<div class="feature-image-upload"
											style="display: <?php echo empty($block['feature_image_id']) ? 'block' : 'none'; ?>">
											<input type="hidden" class="feature-image-id" name="feature_image_id"
												value="<?php echo esc_attr($block['feature_image_id'] ?? ''); ?>">
											<button type="button" class="button upload-feature-image">
												<span class="dashicons dashicons-upload"></span>
												<?php _e('Upload Image', 'poststation'); ?>
											</button>
											<p class="description">
												<?php _e('Upload or select an image to use as the featured image.', 'poststation'); ?>
											</p>
										</div>
									</div>
								</div>
							</div>

							<div class="form-section">
								<h3 class="section-title"><?php _e('Taxonomies', 'poststation'); ?></h3>
								<?php
											foreach ($enabled_taxonomies as $tax_name => $enabled) :
												if (!$enabled) continue;
												$taxonomy = get_taxonomy($tax_name);
												if (!$taxonomy) continue;
												$tax_values = isset($taxonomies[$tax_name]) ? implode(', ', $taxonomies[$tax_name]) : '';
											?>
								<div class="form-field">
									<label><?php echo esc_html($taxonomy->labels->name); ?></label>
									<div class="field-input">
										<input type="text" class="regular-text taxonomy-field"
											data-taxonomy="<?php echo esc_attr($tax_name); ?>"
											value="<?php echo esc_attr($tax_values); ?>" placeholder="<?php esc_attr_e(sprintf(
																															'Comma-separated %s',
																															strtolower($taxonomy->labels->name)
																														), 'poststation'); ?>">
									</div>
								</div>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- Right Column -->
						<div class="postblock-column">
							<div class="form-section">
								<h3 class="section-title"><?php _e('Custom Fields', 'poststation'); ?></h3>
								<?php
											foreach ($postwork_custom_fields as $meta_key => $default_value) :
												$value = isset($block_custom_fields[$meta_key]) ? $block_custom_fields[$meta_key] : $default_value;
											?>
								<div class="form-field">
									<label><?php echo esc_html($meta_key); ?></label>
									<div class="field-input">
										<input type="text" class="regular-text custom-field-value-input"
											data-meta-key="<?php echo esc_attr($meta_key); ?>"
											value="<?php echo esc_attr($value); ?>"
											placeholder="<?php esc_attr_e('Custom field value', 'poststation'); ?>">
									</div>
								</div>
								<?php endforeach; ?>
							</div>

							<div class="form-section">
								<h3 class="section-title"><?php _e('AI Prompts', 'poststation'); ?></h3>
								<div class="block-prompts-section collapsed">
									<div class="block-prompts-header">
										<div class="block-prompts-title">
											<span class="dashicons dashicons-arrow-down block-prompts-toggle"></span>
											<?php _e('AI Prompts', 'poststation'); ?>
											<span class="block-prompts-count">(<?php echo count($prompts); ?>)</span>
										</div>
									</div>
									<div class="block-prompts-content" style="display: none;">
										<div class="block-prompts-container">
											<?php foreach ($prompts as $key => $prompt) : ?>
											<div class="block-prompt-item" data-key="<?php echo esc_attr($key); ?>">
												<div class="prompt-header">
													<span
														class="prompt-title"><?php echo esc_html($prompt['title']); ?></span>
												</div>
												<div class="prompt-content">
													<textarea class="prompt-textarea"
														placeholder="<?php esc_attr_e('Enter your prompt content here...', 'poststation'); ?>"><?php echo esc_textarea($prompt['content']); ?></textarea>
												</div>
											</div>
											<?php endforeach; ?>
										</div>
										<p class="description">
											<?php _e('Block-specific prompts will override global prompts.', 'poststation'); ?>
										</p>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<!-- Add the modal markup at the end of the page -->
<div class="api-format-modal">
	<div class="api-format-modal-content">
		<div class="api-format-modal-header">
			<h2>API Response Format</h2>
			<button type="button" class="modal-close">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="api-format-modal-body">
			<div class="format-description">
				<p>Your webhook should return a POST request to the callback URL with the following format:</p>
			</div>
			<div class="format-example">
				<div class="format-actions">
					<button type="button" class="button copy-format">
						<span class="dashicons dashicons-clipboard"></span>
						Copy
					</button>
				</div>
				<pre><code class="api-format-code"></code></pre>
			</div>
		</div>
	</div>
</div>
<?php
	}

	public function handle_create_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$title = sanitize_text_field($_POST['title'] ?? '');
		if (empty($title)) {
			wp_send_json_error(__('Title is required.', 'poststation'));
		}

		$postwork_id = PostWork::create(['title' => $title]);
		if (!$postwork_id) {
			wp_send_json_error(__('Failed to create post work.', 'poststation'));
		}

		wp_send_json_success([
			'id' => $postwork_id,
			'redirect_url' => add_query_arg([
				'page' => self::MENU_SLUG,
				'action' => 'edit',
				'id' => $postwork_id,
			], admin_url('admin.php')),
		]);
	}

	public function handle_update_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['id'];
		$title = sanitize_text_field($_POST['title'] ?? '');
		$webhook_id = (int)$_POST['webhook_id'];
		$post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
		$post_status = sanitize_text_field($_POST['post_status'] ?? 'pending');
		$default_author_id = (int)$_POST['default_author_id'];
		$enabled_taxonomies = json_decode(stripslashes($_POST['enabled_taxonomies'] ?? '{}'), true);
		$default_terms = json_decode(stripslashes($_POST['default_terms'] ?? '{}'), true);
		$prompts = json_decode(stripslashes($_POST['prompts'] ?? '{}'), true);
		$custom_fields = json_decode(stripslashes($_POST['custom_fields'] ?? '{}'), true);

		if (empty($title)) {
			wp_send_json_error(__('Title is required.', 'poststation'));
		}

		// Validate post type
		$post_types = get_post_types(['public' => true]);
		if (!in_array($post_type, $post_types)) {
			wp_send_json_error(__('Invalid post type.', 'poststation'));
		}

		// Ensure category and post_tag are always enabled
		$enabled_taxonomies['category'] = true;
		$enabled_taxonomies['post_tag'] = true;

		// Validate taxonomies and their terms
		$valid_taxonomies = [];
		$valid_terms = [];
		$public_taxonomies = get_taxonomies(['public' => true]);

		foreach ($public_taxonomies as $tax_name) {
			$valid_taxonomies[$tax_name] = isset($enabled_taxonomies[$tax_name]) && $enabled_taxonomies[$tax_name];

			// Only include terms for enabled taxonomies
			if ($valid_taxonomies[$tax_name] && isset($default_terms[$tax_name])) {
				// Verify terms exist in the taxonomy
				$terms = get_terms([
					'taxonomy' => $tax_name,
					'hide_empty' => false,
					'fields' => 'slugs'
				]);

				$valid_terms[$tax_name] = array_intersect($default_terms[$tax_name], $terms);
			}
		}

		// Validate post status
		$valid_statuses = array_keys(get_post_statuses());
		if (!in_array($post_status, $valid_statuses)) {
			wp_send_json_error(__('Invalid post status.', 'poststation'));
		}

		// Validate author
		if ($default_author_id && !get_userdata($default_author_id)) {
			wp_send_json_error(__('Invalid author.', 'poststation'));
		}

		$success = PostWork::update($postwork_id, [
			'title' => $title,
			'webhook_id' => $webhook_id ?: null,
			'post_type' => $post_type,
			'post_status' => $post_status,
			'default_author_id' => $default_author_id ?: get_current_user_id(),
			'enabled_taxonomies' => wp_json_encode($valid_taxonomies),
			'default_terms' => wp_json_encode($valid_terms),
			'prompts' => wp_json_encode($prompts),
			'custom_fields' => wp_json_encode($custom_fields)
		]);

		if (!$success) {
			wp_send_json_error(__('Failed to update post work.', 'poststation'));
		}

		wp_send_json_success();
	}

	public function handle_delete_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['id'];

		// Delete associated blocks first
		PostBlock::delete_by_postwork($postwork_id);

		$success = PostWork::delete($postwork_id);
		if (!$success) {
			wp_send_json_error(__('Failed to delete post work.', 'poststation'));
		}

		wp_send_json_success();
	}

	public function handle_create_postblock(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['postwork_id'];
		if (!$postwork_id) {
			wp_send_json_error(__('Invalid post work ID.', 'poststation'));
		}

		$block_id = PostBlock::create([
			'postwork_id' => $postwork_id,
			'article_url' => '',
			'post_type' => 'post',
		]);

		if (!$block_id) {
			wp_send_json_error(__('Failed to create post block.', 'poststation'));
		}

		wp_send_json_success(['id' => $block_id]);
	}

	public function handle_update_blocks(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$blocks = json_decode(stripslashes($_POST['blocks']), true);
		if (!is_array($blocks)) {
			wp_send_json_error(__('Invalid blocks data.', 'poststation'));
		}

		$success = true;
		$errors = [];

		foreach ($blocks as $block) {
			$block_id = (int)$block['id'];
			$article_url = esc_url_raw($block['article_url'] ?? '');
			$post_title = sanitize_text_field($block['post_title'] ?? '');
			$taxonomies = json_decode($block['taxonomies'] ?? '{}', true);
			$prompts = json_decode($block['prompts'] ?? '{}', true);
			$custom_fields = json_decode($block['custom_fields'] ?? '{}', true);
			$feature_image_id = !empty($block['feature_image_id']) ? (int)$block['feature_image_id'] : null;

			if (empty($article_url)) {
				$errors[] = sprintf(__('Article URL is required for block #%d.', 'poststation'), $block_id);
				$success = false;
				continue;
			}

			// Validate and sanitize taxonomies
			$valid_taxonomies = [];
			if (is_array($taxonomies)) {
				foreach ($taxonomies as $tax_name => $terms) {
					// Verify taxonomy exists
					if (!taxonomy_exists($tax_name)) {
						continue;
					}

					// Clean and validate terms
					$terms = array_map('sanitize_text_field', array_map('trim', $terms));
					$terms = array_filter($terms);

					if (!empty($terms)) {
						$valid_taxonomies[$tax_name] = $terms;
					}
				}
			}

			$result = PostBlock::update($block_id, [
				'article_url' => $article_url,
				'post_title' => $post_title,
				'taxonomies' => wp_json_encode($valid_taxonomies),
				'prompts' => wp_json_encode($prompts),
				'custom_fields' => wp_json_encode($custom_fields),
				'feature_image_id' => $feature_image_id
			]);

			if (!$result) {
				$errors[] = sprintf(__('Failed to update block #%d.', 'poststation'), $block_id);
				$success = false;
			}
		}

		if (!$success) {
			wp_send_json_error([
				'message' => __('Some blocks failed to update.', 'poststation'),
				'errors' => $errors
			]);
		}

		wp_send_json_success();
	}

	public function handle_delete_postblock(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$block_id = (int)$_POST['id'];
		$success = PostBlock::delete($block_id);

		if (!$success) {
			wp_send_json_error(__('Failed to delete post block.', 'poststation'));
		}

		wp_send_json_success();
	}

	public function handle_run_postwork(): void
	{
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'poststation'));
		}

		$postwork_id = (int)$_POST['id'];
		$block_id = (int)$_POST['block_id'];
		$webhook_id = (int)$_POST['webhook_id'];

		// Validate inputs
		$postwork = PostWork::get_by_id($postwork_id);
		if (!$postwork) {
			wp_send_json_error(__('Post work not found.', 'poststation'));
		}

		$block = PostBlock::get_by_id($block_id);
		if (!$block) {
			wp_send_json_error(__('Block not found.', 'poststation'));
		}

		$webhook = Webhook::get_by_id($webhook_id);
		if (!$webhook) {
			wp_send_json_error(__('Webhook not found.', 'poststation'));
		}

		try {
			// Get block prompts or fall back to postwork prompts
			$block_prompts = !empty($block['prompts']) ? json_decode($block['prompts'], true) : [];
			$postwork_prompts = !empty($postwork['prompts']) ? json_decode($postwork['prompts'], true) : [];
			$prompts = !empty($block_prompts) ? $block_prompts : $postwork_prompts;

			$block_custom_fields = !empty($block['custom_fields']) ? json_decode($block['custom_fields'], true) : [];
			$postwork_custom_fields = !empty($postwork['custom_fields']) ? json_decode($postwork['custom_fields'], true) : [];
			$custom_fields = !empty($block_custom_fields) ? $block_custom_fields : $postwork_custom_fields;

			// Send data to webhook
			$response = wp_remote_post($webhook['url'], [
				'headers' => ['Content-Type' => 'application/json'],
				'body' => wp_json_encode([
					'block_id' => $block['id'],
					'article_url' => $block['article_url'],
					'post_title' => $block['post_title'],
					'taxonomies' => json_decode($block['taxonomies'], true),
					'custom_fields' => $custom_fields,
					'prompts' => $prompts,
					'callback_url' => rest_url('poststation/v1/create'),
				]),
				'timeout' => 30,
				'sslverify' => false,
			]);

			if (is_wp_error($response)) {
				throw new Exception($response->get_error_message());
			}

			$response_code = wp_remote_retrieve_response_code($response);
			if ($response_code !== 200) {
				throw new Exception(sprintf(
					__('Webhook returned error code: %d', 'poststation'),
					$response_code
				));
			}

			wp_send_json_success([
				'message' => __('Block sent to webhook for processing.', 'poststation'),
			]);
		} catch (Exception $e) {
			PostBlock::update($block_id, [
				'status' => 'failed',
				'error_message' => $e->getMessage(),
			]);

			wp_send_json_error($e->getMessage());
		}
	}
}