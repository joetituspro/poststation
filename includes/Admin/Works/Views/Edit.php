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
						<div class="postwork-header-label"><?php _e('Tone of Voice', 'poststation'); ?></div>
						<div class="postwork-header-value">
							<span class="tone-text">
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
								echo esc_html($tones[$postwork['tone_of_voice'] ?? 'seo_optimized'] ?? $tones['seo_optimized']);
								?>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<select id="tone-of-voice" style="display: none;">
								<?php foreach ($tones as $value => $label) : ?>
								<option value="<?php echo esc_attr($value); ?>"
									<?php selected($value, $postwork['tone_of_voice'] ?? 'seo_optimized'); ?>>
									<?php echo esc_html($label); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Point of View', 'poststation'); ?></div>
						<div class="postwork-header-value">
							<span class="pov-text">
								<?php
								$povs = [
									'first_person_singular' => __('First Person Singular (I, me, my, mine)', 'poststation'),
									'first_person_plural' => __('First Person Plural (we, us, our, ours)', 'poststation'),
									'second_person' => __('Second Person (you, your, yours)', 'poststation'),
									'third_person' => __('Third Person (he, she, it, they)', 'poststation'),
								];
								echo esc_html($povs[$postwork['point_of_view'] ?? 'third_person'] ?? $povs['third_person']);
								?>
							</span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
							<select id="point-of-view" style="display: none;">
								<?php foreach ($povs as $value => $label) : ?>
								<option value="<?php echo esc_attr($value); ?>"
									<?php selected($value, $postwork['point_of_view'] ?? 'third_person'); ?>>
									<?php echo esc_html($label); ?>
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

				<div class="postwork-header-section">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Featured Image', 'poststation'); ?></div>
						<div class="postwork-header-value" id="image-config-trigger">
							<span class="image-config-text"><?php _e('Configure', 'poststation'); ?></span>
							<span class="dashicons dashicons-edit"></span>
						</div>
					</div>
				</div>

				<div class="postwork-header-section" style="grid-column: 1 / -1;">
					<div class="postwork-header-field">
						<div class="postwork-header-label"><?php _e('Global Instructions (optional)', 'poststation'); ?>
						</div>
						<div class="postwork-header-value">
							<textarea id="postwork-instructions"
								style="width: 100%; min-height: 100px; border: none; background: transparent; padding: 0; resize: vertical;"
								placeholder="<?php esc_attr_e('Enter global instructions for all blocks (optional). Use {{keyword}}, {{article_url}}, {{sitemap}}, and custom field keys like {{title}} as placeholders.', 'poststation'); ?>"><?php echo esc_textarea($postwork['instructions'] ?? ''); ?></textarea>
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
							value="<?php echo esc_attr($tax->name); ?>" <?php checked($is_enabled); ?>>
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
							<?php if ($key === 'slug') : ?>
							<input type="text" class="regular-text post-field-value"
								value="<?php echo esc_attr($value); ?>"
								placeholder="<?php esc_attr_e('Enter the default slug', 'poststation'); ?>">
							<?php else : ?>
							<textarea class="post-field-value"
								placeholder="<?php esc_attr_e('Enter the default value for this field', 'poststation'); ?>"><?php echo esc_textarea($value); ?></textarea>
							<?php endif; ?>
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
										<input type="checkbox" class="post-field-required" <?php checked($required); ?>>
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

		<!-- Image Config Panel -->
		<div class="side-panel image-config-panel">
			<div class="side-panel-header">
				<div class="side-panel-title"><?php _e('Featured Image Generator Config', 'poststation'); ?></div>
				<div class="side-panel-close">
					<span class="dashicons dashicons-no-alt"></span>
				</div>
			</div>
			<div class="side-panel-content">
				<?php
				$image_config = !empty($postwork['image_config']) ? json_decode($postwork['image_config'], true) : [];
				$enabled = $image_config['enabled'] ?? false;
				$template_id = $image_config['templateId'] ?? 'classic';
				$category_text = $image_config['categoryText'] ?? '';
				$main_text = $image_config['mainText'] ?? '{{image_title}}';
				$bg_image_urls = $image_config['bgImageUrls'] ?? [];
				// Fallback for old single image URL
				if (empty($bg_image_urls) && !empty($image_config['bgImageUrl'])) {
					$bg_image_urls = [$image_config['bgImageUrl']];
				}
				$category_color = $image_config['categoryColor'] ?? '#c67c4e';
				$title_color = $image_config['titleColor'] ?? '#1a1a1a';
				?>
				<div class="image-config-form">
					<div class="form-field">
						<label class="toggle-label">
							<input type="checkbox" id="image-config-enabled" <?php checked($enabled); ?>>
							<?php _e('Enable Auto-Generated Featured Images', 'poststation'); ?>
						</label>
						<p class="description">
							<?php _e('When enabled, a featured image will be automatically generated using the image-gen service after the post is published.', 'poststation'); ?>
						</p>
					</div>

					<div class="image-config-fields" <?php echo !$enabled ? 'style="display:none;"' : ''; ?>>
						<div class="form-field">
							<label><?php _e('Template ID', 'poststation'); ?></label>
							<input type="text" id="image-template-id" class="regular-text"
								value="<?php echo esc_attr($template_id); ?>" placeholder="classic">
							<p class="description">
								<?php _e('Available templates: classic, modernDark, vibrant, editorial, etc.', 'poststation'); ?>
							</p>
						</div>

						<div class="form-field">
							<label><?php _e('Category Text', 'poststation'); ?></label>
							<input type="text" id="image-category-text" class="regular-text"
								value="<?php echo esc_attr($category_text); ?>" placeholder="HEALTH AND WELLNESS">
							<p class="description">
								<?php _e('The category/subtitle text shown on the image.', 'poststation'); ?></p>
						</div>

						<div class="form-field">
							<label><?php _e('Main Text', 'poststation'); ?></label>
							<input type="text" id="image-main-text" class="regular-text"
								value="<?php echo esc_attr($main_text); ?>" placeholder="{{image_title}}">
							<p class="description">
								<?php _e('Use {{title}} to include the post title, or {{image_title}} for the custom image title.', 'poststation'); ?>
							</p>
						</div>

						<div class="form-field">
							<label><?php _e('Background Images', 'poststation'); ?> (Max 15)</label>
							<div id="bg-images-container" class="bg-images-list">
								<?php foreach ($bg_image_urls as $url) : ?>
								<div class="bg-image-item">
									<div class="bg-image-preview">
										<img src="<?php echo esc_url($url); ?>" alt="">
									</div>
									<div class="bg-image-actions">
										<input type="hidden" class="bg-image-url" value="<?php echo esc_attr($url); ?>">
										<button type="button" class="button remove-bg-image-item"
											title="<?php _e('Remove', 'poststation'); ?>">
											<span class="dashicons dashicons-no"></span>
										</button>
									</div>
								</div>
								<?php endforeach; ?>

								<button type="button" class="button add-bg-image" id="add-bg-image"
									style="<?php echo count($bg_image_urls) >= 15 ? 'display:none;' : ''; ?>">
									<span class="dashicons dashicons-plus"></span>
									<?php _e('Add Image', 'poststation'); ?>
								</button>
							</div>
							<p class="description">
								<?php _e('Upload or select background images. One will be randomly selected for each post.', 'poststation'); ?>
							</p>
						</div>

						<div class="form-field color-fields">
							<div class="color-field">
								<label><?php _e('Category Color', 'poststation'); ?></label>
								<div class="color-input-wrapper">
									<input type="color" id="image-category-color"
										value="<?php echo esc_attr($category_color); ?>">
									<input type="text" class="color-hex-input"
										value="<?php echo esc_attr($category_color); ?>" pattern="^#[0-9A-Fa-f]{6}$">
								</div>
							</div>
							<div class="color-field">
								<label><?php _e('Title Color', 'poststation'); ?></label>
								<div class="color-input-wrapper">
									<input type="color" id="image-title-color"
										value="<?php echo esc_attr($title_color); ?>">
									<input type="text" class="color-hex-input"
										value="<?php echo esc_attr($title_color); ?>" pattern="^#[0-9A-Fa-f]{6}$">
								</div>
							</div>
						</div>
					</div>
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
					<button type="button" class="button" id="import-blocks-trigger">
						<?php _e('Import Blocks', 'poststation'); ?>
					</button>
					<button type="button" class="button" id="clear-completed-blocks">
						<?php _e('Clear Completed', 'poststation'); ?>
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

<!-- Add Import Blocks Modal -->
<div class="import-blocks-modal" id="import-blocks-modal">
	<div class="import-blocks-modal-content">
		<div class="import-blocks-modal-header">
			<h2><?php _e('Import Post Blocks', 'poststation'); ?></h2>
			<button type="button" class="modal-close">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="import-blocks-modal-body">
			<div class="import-guide">
				<p><?php _e('Paste your valid JSON array of block objects below. Use the following simplified format:', 'poststation'); ?>
				</p>
				<ul class="guide-fields">
					<li><code>article_url</code>: <?php _e('Source article URL (optional)', 'poststation'); ?></li>
					<li><code>topic</code>: <?php _e('Maps to the block Keyword (optional)', 'poststation'); ?></li>
					<li><code>slug</code>: <?php _e('Maps to the block Slug field (optional)', 'poststation'); ?></li>
					<li><code>feature_image_title</code>:
						<?php _e('Custom title for the image (optional)', 'poststation'); ?></li>
				</ul>
			</div>

			<div class="sample-json-section">
				<div class="section-header">
					<h3><?php _e('Sample JSON Format', 'poststation'); ?></h3>
					<button type="button" class="button button-small copy-sample-json">
						<span class="dashicons dashicons-clipboard"></span>
						<?php _e('Copy Sample', 'poststation'); ?>
					</button>
				</div>
				<pre class="sample-json-code"><code id="import-sample-code"></code></pre>
			</div>

			<div class="import-textarea-wrapper">
				<textarea id="import-blocks-json" placeholder='[
  {
    "article_url": "https://example.com/article",
    "topic": "my topic",
    "slug": "custom-slug",
    "feature_image_title": "Custom Image Title"
  }
]'></textarea>
			</div>
		</div>
		<div class="import-blocks-modal-footer">
			<button type="button"
				class="button button-secondary modal-close"><?php _e('Cancel', 'poststation'); ?></button>
			<button type="button" class="button button-primary" id="process-import-blocks">
				<span class="dashicons dashicons-download"></span>
				<?php _e('Import and Save', 'poststation'); ?>
			</button>
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