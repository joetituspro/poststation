<div id="postblocks">
	<?php foreach ($blocks as $block) :
		$taxonomies = !empty($block['taxonomies']) ? json_decode($block['taxonomies'], true) : [];
		$block_post_fields = !empty($block['post_fields']) ? json_decode($block['post_fields'], true) : [];
		$postwork_post_fields = !empty($postwork['post_fields']) ? json_decode($postwork['post_fields'], true) : [];
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
							<label>
								<?php _e('Article URL', 'poststation'); ?>
								<span class="required">*</span>
							</label>
							<div class="field-input">
								<input type="url" class="regular-text article-url"
									value="<?php echo esc_attr($block['article_url']); ?>" required>
								<span class="error-message"></span>
							</div>
						</div>

						<div class="form-field">
							<label><?php _e('Featured Image', 'poststation'); ?></label>
							<div class="field-input feature-image-field">
								<div class="feature-image-preview"
									style="display: <?php echo !empty($block['feature_image_id']) ? 'block' : 'none'; ?>">
									<img src="<?php echo !empty($block['feature_image_id']) ?
														wp_get_attachment_image_url($block['feature_image_id'], 'thumbnail') : ''; ?>" alt="">
									<span class="dashicons dashicons-no remove-feature-image"></span>
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
						<h3 class="section-title"><?php _e('Post Fields', 'poststation'); ?></h3>
						<?php
							foreach ($postwork_post_fields as $meta_key => $field) :
								$field = is_array($field) ? $field : ['value' => $field];
								$value = isset($block_post_fields[$meta_key]) ?
									(is_array($block_post_fields[$meta_key]) ? $block_post_fields[$meta_key]['value'] : $block_post_fields[$meta_key])
									: $field['value'];
								$prompt = isset($block_post_fields[$meta_key]['prompt']) ? $block_post_fields[$meta_key]['prompt'] : ($field['prompt'] ?? '');
								$type = isset($block_post_fields[$meta_key]['type']) ? $block_post_fields[$meta_key]['type'] : ($field['type'] ?? 'string');
								$required = isset($block_post_fields[$meta_key]['required']) ? $block_post_fields[$meta_key]['required'] : ($field['required'] ?? false);
							?>
						<div class="form-field">
							<label><?php echo esc_html($meta_key); ?></label>
							<div class="field-input">
								<input type="text" class="regular-text post-field-value-input"
									data-key="<?php echo esc_attr($meta_key); ?>"
									value="<?php echo esc_attr($value); ?>"
									placeholder="<?php esc_attr_e('Custom field value', 'poststation'); ?>">
								<div class="prompt-label"><?php _e('AI Prompt', 'poststation'); ?></div>
								<textarea class="post-field-prompt-input" data-key="<?php echo esc_attr($meta_key); ?>"
									placeholder="<?php esc_attr_e('Enter the AI prompt for generating this field\'s content', 'poststation'); ?>"><?php echo esc_textarea($prompt); ?></textarea>
								<div class="field-options">
									<div class="field-type">
										<div class="field-label"><?php _e('Data Type', 'poststation'); ?></div>
										<select class="post-field-type-input"
											data-key="<?php echo esc_attr($meta_key); ?>">
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
											<input type="checkbox" class="post-field-required-input"
												data-key="<?php echo esc_attr($meta_key); ?>"
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
		</div>
	</div>
	<?php endforeach; ?>
</div>