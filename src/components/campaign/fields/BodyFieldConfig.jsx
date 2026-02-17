import { Select, Textarea, Input, Tooltip, ModelSelect } from '../../common';

const RESEARCH_MODE_OPTIONS = [
	{ value: 'none', label: 'None' },
	{ value: 'perplexity', label: 'Perplexity (Default)' },
	{ value: 'google_dataforseo', label: 'Google via DataForSEO (Coming Soon)', disabled: true },
];

export default function BodyFieldConfig({ config, onChange, articleType }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const isListicle = articleType === 'listicle';
	const isRewrite = articleType === 'rewrite_blog_post';

	const yesNoOptions = [
		{ value: 'yes', label: 'Yes' },
		{ value: 'no', label: 'No' },
	];

	const listNumberingOptions = [
		{ value: 'none', label: 'None' },
		{ value: 'dot', label: '1. , 2. , 3.' },
		{ value: 'paren', label: '1) 2) 3)' },
		{ value: 'colon', label: '1:, 2:, 3:' },
	];

	const numImagesOptions = [
		{ value: 'random', label: 'Random Number' },
		{ value: 'according_to_sections', label: 'According to Sections' },
		{ value: 'custom', label: 'Custom Number' },
	];

	const imageSizeOptions = [
		{ value: '960x768', label: '960×768 (5:4)' },
		{ value: '1024x640', label: '1024×640 (8:5)' },
		{ value: '1024x768', label: '1024×768 (4:3)' },
		{ value: '1152x768', label: '1152×768 (3:2)' },
		{ value: '1280x704', label: '1280×704 (20:11)' },
		{ value: '1344x768', label: '1344×768 (16:9)' },
		{ value: '768x1344', label: '768×1344 (9:16)' },
		{ value: '1024x1024', label: '1024×1024 (1:1)' },
	];

	const imageStyleOptions = [
		{ value: 'none', label: 'None' },
		{ value: 'photo', label: 'Photo' },
		{ value: 'cartoon', label: 'Cartoon' },
		{ value: 'cubism', label: 'Cubism' },
		{ value: 'expressionism', label: 'Expressionism' },
		{ value: 'cyberpunk', label: 'Cyberpunk' },
		{ value: 'fantasy', label: 'Fantasy' },
		{ value: 'cinematic', label: 'Cinematic' },
		{ value: 'abstract', label: 'Abstract' },
		{ value: 'impressionism', label: 'Impressionism' },
		{ value: 'surrealism', label: 'Surrealism' },
		{ value: 'anime', label: 'Anime' },
		{ value: 'comic_book', label: 'Comic Book' },
	];

	const hookPresets = [
		{
			label: 'Question',
			value: "Craft an intriguing question that immediately draws the reader's attention. The question should be relevant to the article's topic and evoke curiosity or challenge common beliefs. Aim to make the reader reflect or feel compelled to find the answer within the article.",
		},
		{
			label: 'Statistical or Fact',
			value: "Begin with a surprising statistic or an unexpected fact that relates directly to the article's main topic. This hook should provide a sense of scale or impact that makes the reader eager to learn more about the subject.",
		},
		{
			label: 'Quotation',
			value: "Use a powerful or thought-provoking quote from a well-known figure that ties into the theme of the article. The quote should set the tone for the article and provoke interest in the topic.",
		},
		{
			label: 'Anecdotal or Story',
			value: "Create a brief, engaging story or anecdote that is relevant to the article's main subject. This story should be relatable and set the stage for the main content.",
		},
		{
			label: 'Personal or Emotional',
			value: "Write an emotionally resonant opening that connects personally with the reader. This could be a reflection, a personal experience, or an emotional appeal that aligns with the article's theme.",
		},
	];

	const handleHookPreset = (value) => {
		handleChange('introductory_hook_brief', value);
	};

	return (
		<div className="space-y-4">
			{!isRewrite && (
				<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
					<Select
						label="Research Mode"
						tooltip="Select the research engine to use for gathering information."
						options={RESEARCH_MODE_OPTIONS}
						value={config.research_mode || 'perplexity'}
						onChange={(e) => handleChange('research_mode', e.target.value)}
					/>
					{config.research_mode !== 'none' && (
						<Input
							label="Number of Sources to Use"
							tooltip="How many sources to research. Max is 10."
							type="number"
							min="1"
							max="10"
							value={config.sources_count ?? 3}
							onChange={(e) => {
								const val = parseInt(e.target.value, 10);
								handleChange('sources_count', isNaN(val) ? '' : Math.min(10, Math.max(1, val)));
							}}
						/>
					)}
				</div>
			)}

			<Textarea
				label="Additional Instruction"
				tooltip="Extra guidance used when generating the body content."
				value={config.prompt || ''}
				onChange={(e) => handleChange('prompt', e.target.value)}
				placeholder="Add specific instructions for content generation..."
				rows={2}
			/>

			<div className="space-y-4">
				<div className="space-y-2">
					<div className="border-b border-gray-200 pb-1">
						<h4 className="text-sm font-semibold text-gray-700">Intro Hook</h4>
					</div>
					<Textarea
						label="Introductory Hook Brief"
						tooltip="Short brief for how the intro hook should start."
						value={config.introductory_hook_brief || ''}
						onChange={(e) => handleChange('introductory_hook_brief', e.target.value)}
						placeholder="Leave empty for default behavior"
						rows={2}
					/>
					<div className="flex flex-wrap gap-2">
						{hookPresets.map((preset) => (
							<button
								key={preset.label}
								type="button"
								onClick={() => handleHookPreset(preset.value)}
								className="px-2 py-1 text-xs rounded border border-gray-200 text-gray-700 hover:bg-gray-50"
							>
								{preset.label}
							</button>
						))}
					</div>
				</div>

				<div className="space-y-2">
					<div className="border-b border-gray-200 pb-1">
						<h4 className="text-sm font-semibold text-gray-700">Structure</h4>
					</div>
					<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
						<Select
							label="Key Takeaways"
							tooltip="Include a key takeaways section."
							options={yesNoOptions}
							value={config.key_takeaways || 'yes'}
							onChange={(e) => handleChange('key_takeaways', e.target.value)}
						/>
						<Select
							label="Conclusion"
							tooltip="Include a conclusion section."
							options={yesNoOptions}
							value={config.conclusion || 'yes'}
							onChange={(e) => handleChange('conclusion', e.target.value)}
						/>
						<Select
							label="FAQ"
							tooltip="Include a FAQ section."
							options={yesNoOptions}
							value={config.faq || 'yes'}
							onChange={(e) => handleChange('faq', e.target.value)}
						/>
						<Select
							label="Internal Linking"
							tooltip="Include internal links to related content."
							options={yesNoOptions}
							value={config.internal_linking || 'yes'}
							onChange={(e) => handleChange('internal_linking', e.target.value)}
						/>
						<Select
							label="External Linking"
							tooltip="Include external links to authoritative sources."
							options={yesNoOptions}
							value={config.external_linking || 'yes'}
							onChange={(e) => handleChange('external_linking', e.target.value)}
						/>
					</div>
				</div>

				{isListicle && (
					<div className="space-y-3">
						<div className="border-b border-gray-200 pb-1">
							<h4 className="text-sm font-semibold text-gray-700">List Config</h4>
						</div>
						<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
							<Select
								label="List Numbering Format"
								tooltip="Format used for list item numbering."
								options={listNumberingOptions}
								value={config.list_numbering_format || 'none'}
								onChange={(e) => handleChange('list_numbering_format', e.target.value)}
							/>

							<Input
								label="Number of List"
								tooltip="How many list items to generate. Leave empty for automatic."
								type="number"
								min="1"
								value={config.number_of_list ?? ''}
								onChange={(e) => {
									const value = e.target.value;
									if (value === '') {
										handleChange('number_of_list', '');
									} else {
										handleChange('number_of_list', Math.max(1, parseInt(value, 10) || 1));
									}
								}}
								placeholder="Automatic"
							/>
						</div>

						<label className="flex items-center gap-2 text-sm font-medium text-gray-700">
							<input
								type="checkbox"
								checked={Boolean(config.use_descending_order)}
								onChange={(e) => handleChange('use_descending_order', e.target.checked)}
								className="poststation-field-checkbox"
							/>
							<span>Use Descending Order</span>
							<Tooltip content="If enabled, list items will be ordered from highest to lowest." />
						</label>

						<Textarea
							label="List Section Instruction"
							tooltip="Instruction applied to each list item/section."
							value={config.list_section_prompt || ''}
							onChange={(e) => handleChange('list_section_prompt', e.target.value)}
							placeholder="Add guidance for each list item section"
							rows={2}
						/>
					</div>
				)}

				<ModelSelect
					label="Model"
					tooltip="OpenRouter model used to generate body content."
					value={config.model_id || ''}
					onChange={(e) => handleChange('model_id', e.target.value)}
					filter="text"
				/>

				<div className="space-y-2">
					<div className="border-b border-gray-200 pb-1">
						<h4 className="text-sm font-semibold text-gray-700">Media</h4>
					</div>
					<div className="space-y-4">
						<Select
							label="Enable Media"
							tooltip="Include AI-generated images within the article body."
							options={yesNoOptions}
							value={config.enable_media || 'no'}
							onChange={(e) => handleChange('enable_media', e.target.value)}
						/>

						{config.enable_media === 'yes' && (
							<div className="space-y-3 p-4 bg-gray-50 rounded-lg border border-gray-100">
								<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
									<Select
										label="Number of Images"
										tooltip="How many images to include in the body."
										options={numImagesOptions}
										value={config.number_of_images || 'random'}
										onChange={(e) => handleChange('number_of_images', e.target.value)}
									/>

									{config.number_of_images === 'custom' && (
										<Input
											label="Custom Number"
											tooltip="Specific number of images to generate."
											type="number"
											min="1"
											value={config.custom_number_of_images ?? 3}
											onChange={(e) => handleChange('custom_number_of_images', parseInt(e.target.value, 10) || 1)}
										/>
									)}

									<Select
										label="Image Size"
										tooltip="The aspect ratio and resolution for generated images."
										options={imageSizeOptions}
										value={config.image_size || '1344x768'}
										onChange={(e) => handleChange('image_size', e.target.value)}
									/>

									<Select
										label="Image Style"
										tooltip="The visual style for generated images."
										options={imageStyleOptions}
										value={config.image_style || 'none'}
										onChange={(e) => handleChange('image_style', e.target.value)}
									/>
								</div>

								<Textarea
									label="Additional Instruction"
									tooltip="Extra guidance for media placement and generation inside the body."
									value={config.media_prompt || ''}
									onChange={(e) => handleChange('media_prompt', e.target.value)}
									placeholder="Optional: where to place images, what scenes to emphasize, etc."
									rows={2}
								/>

								<ModelSelect
									label="Image Model"
									tooltip="OpenRouter image model used for body media generation."
									value={config.image_model_id || ''}
									onChange={(e) => handleChange('image_model_id', e.target.value)}
									filter="image"
								/>
							</div>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}
