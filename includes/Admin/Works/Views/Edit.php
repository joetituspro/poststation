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
						<div class="postwork-header-label"><?php _e('Post Fields', 'poststation'); ?></div>
						<div class="postwork-header-value" id="post-fields-trigger">
							<span class="post-fields-text"><?php _e('Configure', 'poststation'); ?></span>
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

		<!-- Post Fields Panel -->
		<div class="side-panel post-fields-panel">
			<div class="side-panel-header">
				<div class="side-panel-title"><?php _e('Post Fields', 'poststation'); ?></div>
				<div class="side-panel-close">
					<span class="dashicons dashicons-no-alt"></span>
				</div>
			</div>
			<div class="side-panel-actions">
				<button type="button" class="button add-post-field-button">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php _e('Add Post Field', 'poststation'); ?>
				</button>
			</div>
			<div class="side-panel-content">
				<div class="post-fields-container">
					<?php
					$post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];
					foreach ($post_fields as $key => $field) :
						$value = is_array($field) ? ($field['value'] ?? '') : $field;
						$prompt = is_array($field) ? ($field['prompt'] ?? '') : '';
						$type = is_array($field) ? ($field['type'] ?? 'string') : 'string';
						$required = is_array($field) ? ($field['required'] ?? false) : false;
					?>
						<div class="post-field-item" data-key="<?php echo esc_attr($key); ?>">
							<div class="post-field-header">
								<div class="post-field-key">
									<input type="text" class="regular-text post-field-key-input"
										value="<?php echo esc_attr($key); ?>"
										placeholder="<?php esc_attr_e('Meta Key', 'poststation'); ?>">
									<div class="error-message"></div>
								</div>
								<div class="post-field-actions">
									<span class="post-field-delete dashicons dashicons-trash"
										title="<?php esc_attr_e('Delete Field', 'poststation'); ?>"></span>
								</div>
							</div>
							<div class="post-field-content">
								<div class="field-label"><?php _e('Default Value', 'poststation'); ?></div>
								<textarea class="post-field-value"
									placeholder="<?php esc_attr_e('Enter the default value for this field', 'poststation'); ?>"><?php echo esc_textarea($value); ?></textarea>
								<div class="field-label"><?php _e('AI Prompt', 'poststation'); ?></div>
								<textarea class="post-field-prompt"
									placeholder="<?php esc_attr_e('Enter the AI prompt for generating this field\'s content', 'poststation'); ?>"><?php echo esc_textarea($prompt); ?></textarea>
								<div class="field-options">
									<div class="field-type">
										<div class="field-label"><?php _e('Data Type', 'poststation'); ?></div>
										<select class="post-field-type">
											<option value="string" <?php selected($type, 'string'); ?>>
												<?php _e('String', 'poststation'); ?></option>
											<option value="number" <?php selected($type, 'number'); ?>>
												<?php _e('Number', 'poststation'); ?></option>
											<option value="boolean" <?php selected($type, 'boolean'); ?>>
												<?php _e('Boolean', 'poststation'); ?></option>
											<option value="array" <?php selected($type, 'array'); ?>>
												<?php _e('Array', 'poststation'); ?></option>
											<option value="object" <?php selected($type, 'object'); ?>>
												<?php _e('Object', 'poststation'); ?></option>
										</select>
									</div>
									<div class="field-required">
										<label>
											<input type="checkbox" class="post-field-required"
												<?php checked($required); ?>>
											<?php _e('Required Field', 'poststation'); ?>
										</label>
									</div>
								</div>
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

		<?php include POSTSTATION_PATH . 'includes/Admin/Works/Views/Blocks.php'; ?>

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