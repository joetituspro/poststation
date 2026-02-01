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
				<span class="block-url"
					title="<?php echo esc_attr(($block['article_url'] ?? '') ?: ($block['keyword'] ?? '') ?: ''); ?>">
					<?php
						if (!empty($block['article_url'])) {
							echo esc_html(wp_parse_url($block['article_url'], PHP_URL_HOST) . wp_parse_url($block['article_url'], PHP_URL_PATH));
						} elseif (!empty($block['keyword'])) {
							echo esc_html($block['keyword']);
						} else {
							_e('Empty block', 'poststation');
						}
						?>
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
				<a href="<?php echo esc_url(get_permalink($block['post_id'])); ?>" class="block-preview-link"
					target="_blank" title="<?php esc_attr_e('Preview Post', 'poststation'); ?>">
					<span class="dashicons dashicons-visibility"></span>
				</a>
				<?php endif; ?>
			</div>
			<div class="postblock-header-actions">
				<span class="block-status-badge <?php echo esc_attr($block['status']); ?>"
					title="<?php echo esc_attr($block['error_message'] ?? ''); ?>">
					<?php if ($block['status'] === 'processing') : ?>
					<span class="dashicons dashicons-update rotating"></span>
					<?php endif; ?>
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
							</label>
							<div class="field-input">
								<input type="url" class="regular-text article-url"
									value="<?php echo esc_attr($block['article_url'] ?? ''); ?>"
									placeholder="<?php esc_attr_e('Enter article URL (optional)', 'poststation'); ?>">
								<span class="error-message"></span>
							</div>
						</div>

						<div class="form-field">
							<label>
								<?php _e('Keyword', 'poststation'); ?>
							</label>
							<div class="field-input">
								<input type="text" class="regular-text keyword"
									value="<?php echo esc_attr($block['keyword'] ?? ''); ?>"
									placeholder="<?php esc_attr_e('Enter main keyword (optional)', 'poststation'); ?>">
							</div>
						</div>

						<div class="form-field">
							<label><?php _e('Featured Image Title', 'poststation'); ?></label>
							<div class="field-input">
								<input type="text" class="regular-text feature-image-title" name="feature_image_title"
									value="<?php echo esc_attr($block['feature_image_title'] ?? '{{title}}'); ?>"
									placeholder="{{title}}">
								<p class="description">
									<?php _e('Title used for the generated featured image. Default is {{title}}.', 'poststation'); ?>
								</p>
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

						<div class="form-field">
							<label><?php _e('Tone of Voice', 'poststation'); ?></label>
							<div class="field-input">
								<select class="regular-text tone-of-voice">
									<?php
										$tones = [
											'seo_optimized' => __('SEO Optimized (Confident, Knowledgeable, Neutral, and Clear)', 'poststation'),
											'excited' => __('Excited', 'poststation'),
											'professional' => __('Professional', 'poststation'),
											'friendly' => __('Friendly', 'poststation'),
											'formal' => __('Formal', 'poststation'),
											'casual' => __('Casual', 'poststation'),
											'humorous' => __('Humorous', 'poststation'),
											'conversational' => __('Conversational', 'poststation'),
										];
										foreach ($tones as $value => $label) : ?>
									<option value="<?php echo esc_attr($value); ?>"
										<?php selected($block['tone_of_voice'] ?? 'seo_optimized', $value); ?>>
										<?php echo esc_html($label); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="form-field">
							<label><?php _e('Point of View', 'poststation'); ?></label>
							<div class="field-input">
								<select class="regular-text point-of-view">
									<?php
										$povs = [
											'first_person_singular' => __('First Person Singular (I, me, my, mine)', 'poststation'),
											'first_person_plural' => __('First Person Plural (we, us, our, ours)', 'poststation'),
											'second_person' => __('Second Person (you, your, yours)', 'poststation'),
											'third_person' => __('Third Person (he, she, it, they)', 'poststation'),
										];
										foreach ($povs as $value => $label) : ?>
									<option value="<?php echo esc_attr($value); ?>"
										<?php selected($block['point_of_view'] ?? 'third_person', $value); ?>>
										<?php echo esc_html($label); ?>
									</option>
									<?php endforeach; ?>
								</select>
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
					<div class="form-section post-fields-section">
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
								<div class="field-label"><?php _e('Default Value', 'poststation'); ?></div>
								<?php if ($meta_key === 'slug') : ?>
								<input type="text" class="regular-text post-field-value-input"
									data-key="<?php echo esc_attr($meta_key); ?>"
									value="<?php echo esc_attr($value); ?>"
									placeholder="<?php esc_attr_e('Default value', 'poststation'); ?>">
								<?php else : ?>
								<textarea class="post-field-value-input" data-key="<?php echo esc_attr($meta_key); ?>"
									placeholder="<?php esc_attr_e('Default value', 'poststation'); ?>"><?php echo esc_textarea($value); ?></textarea>
								<?php endif; ?>
								<div class="field-label"><?php _e('AI Prompt', 'poststation'); ?></div>
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