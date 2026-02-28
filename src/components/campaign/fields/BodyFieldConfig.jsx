import { Select, Textarea, Input, Tooltip, ModelSelect, MultiSelect } from '../../common';

const RESEARCH_MODE_OPTIONS = [
	{ value: 'none', label: 'None' },
	{ value: 'perplexity', label: 'Perplexity (Default)' },
	{
		value: 'dataforseo',
		label: 'DataForSEO (Coming Soon)',
		disabled: true,
	},
];

const INTERNAL_LINK_MODE_OPTIONS = [
	{ value: 'none', label: 'None' },
	{ value: 'all_post_types', label: 'Yes - All Post Types' },
	{ value: 'campaign_post_type_only', label: 'Yes - Campaign Post Type Only' },
	{ value: 'specific_taxonomy', label: 'Yes - Specific Taxonomy' },
];

export default function BodyFieldConfig({ config, onChange, campaignType, taxonomies = {} }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const yesNoOptions = [
		{ value: 'yes', label: 'Yes' },
		{ value: 'no', label: 'No' },
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

	const selectedInternalLinksMode = (() => {
		if (typeof config.internal_links_mode === 'string' && config.internal_links_mode !== '') {
			if (config.internal_links_mode === 'any_post_type') {
				return 'all_post_types';
			}
			return config.internal_links_mode;
		}
		return 'all_post_types';
	})();

	const selectedTaxonomy = config.internal_links_taxonomy || '';
	const selectedTaxonomyData = selectedTaxonomy ? taxonomies?.[selectedTaxonomy] : null;
	const selectedTaxonomyTerms = Array.isArray(selectedTaxonomyData?.terms) ? selectedTaxonomyData.terms : [];
	const selectedTerms = Array.isArray(config.internal_links_terms) ? config.internal_links_terms : [];
	const normalizedSelectedTerms = selectedTerms.map((value) => String(value));
	const internalLinkTermOptions = selectedTaxonomyTerms.map((term) => ({
		value: String(term.term_id),
		label: `${term.name} (${term.count ?? 0})`,
	}));
	const taxonomyOptions = Object.entries(taxonomies || {})
		.filter(([slug]) => slug !== 'post_format' && slug !== 'format')
		.map(([slug, data]) => ({
			value: slug,
			label: data?.label || slug,
		}));

	const handleInternalLinkModeChange = (mode) => {
		const updates = {
			internal_links_mode: mode,
		};
		if (mode !== 'specific_taxonomy') {
			updates.internal_links_taxonomy = '';
			updates.internal_links_terms = [];
		}
		onChange({ ...config, ...updates });
	};

	return (
		<div className="space-y-4">
			<ModelSelect
				label="Model"
				tooltip="OpenRouter model used to generate body content."
				value={config.model_id || ''}
				onChange={(e) => handleChange('model_id', e.target.value)}
				filter="text"
			/>

			<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
				<Select
					label="Real-Time Data"
					tooltip="Select the source for real-time data when generating content."
					options={ RESEARCH_MODE_OPTIONS }
					value={ config.research_mode || 'perplexity' }
					onChange={ ( e ) =>
						handleChange(
							'research_mode',
							e.target.value
						)
					}
				/>
				{ config.research_mode !== 'none' && (
					<Input
						label="Number of Sources to Use"
						tooltip="How many sources to research. Max is 10."
						type="number"
						min="1"
						max="10"
						value={ config.sources_count ?? 5 }
						onChange={ ( e ) => {
							const val = parseInt(
								e.target.value,
								10
							);
							handleChange(
								'sources_count',
								Number.isNaN( val )
									? ''
									: Math.min(
											10,
											Math.max(
												1,
												val
											)
									  )
							);
						} }
					/>
				) }
			</div>

			<div className="space-y-1">
				<Select
					label="Generation Mode"
					tooltip="Control whether the AI writes the full article in one pass or section by section."
					options={ [
						{ value: 'single', label: 'Single' },
						{
							value: 'segmented',
							label: 'Segmented (Coming Soon)',
							disabled: true,
						},
					] }
					value={ config.generation_mode || 'single' }
					onChange={ ( e ) =>
						handleChange(
							'generation_mode',
							e.target.value
						)
					}
				/>
				<p className="text-xs text-gray-500">
					{ ( config.generation_mode || 'single' ) ===
					'segmented'
						? 'Segmented — AI writes each section individually for more focused and detailed output.'
						: 'Single — AI writes the entire article in one pass.' }
				</p>
			</div>

			<Textarea
				label="Additional Instruction"
				tooltip="Extra guidance used when generating the body content."
				value={ config.prompt || '' }
				onChange={ ( e ) =>
					handleChange( 'prompt', e.target.value )
				}
				placeholder="Add specific instructions for content generation..."
				rows={2}
			/>

			<div className="space-y-4">
				<div className="space-y-2">
					<div className="border-b border-gray-200 pb-1">
						<h4 className="text-sm font-semibold text-gray-700">Structure</h4>
					</div>
					<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
						<Select
							label="Key Takeaways"
							tooltip="Include a key takeaways section."
							options={ yesNoOptions }
							value={ config.key_takeaways || 'yes' }
							onChange={ ( e ) =>
								handleChange(
									'key_takeaways',
									e.target.value
								)
							}
						/>
						<Select
							label="Conclusion"
							tooltip="Include a conclusion section."
							options={ yesNoOptions }
							value={ config.conclusion || 'yes' }
							onChange={ ( e ) =>
								handleChange(
									'conclusion',
									e.target.value
								)
							}
						/>
						<Select
							label="FAQ"
							tooltip="Include a FAQ section."
							options={ yesNoOptions }
							value={ config.faq || 'yes' }
							onChange={ ( e ) =>
								handleChange(
									'faq',
									e.target.value
								)
							}
						/>
					</div>
				</div>

				<div className="space-y-2">
					<div className="border-b border-gray-200 pb-1">
						<h4 className="text-sm font-semibold text-gray-700">Internal Links</h4>
					</div>
					<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
						<Select
							label="Internal Linking"
							tooltip="Control whether internal links are added and where they can be sourced from."
							options={INTERNAL_LINK_MODE_OPTIONS}
							value={selectedInternalLinksMode}
							onChange={(e) => handleInternalLinkModeChange(e.target.value)}
							className="sm:col-span-2"
						/>
						{selectedInternalLinksMode !== 'none' && (
							<Input
								label="Number of Internal Links"
								tooltip="How many internal links to include. Default is 4."
								type="number"
								min="1"
								value={config.internal_links_count ?? 4}
								onChange={(e) => {
									const val = parseInt(e.target.value, 10);
									handleChange('internal_links_count', isNaN(val) ? 4 : Math.max(1, val));
								}}
							/>
						)}
						{selectedInternalLinksMode === 'specific_taxonomy' && (
							<Select
								label="Taxonomy"
								tooltip="Only posts assigned to selected terms in this taxonomy are used for internal links."
								options={taxonomyOptions}
								value={selectedTaxonomy}
								onChange={(e) =>
									onChange({
										...config,
										internal_links_taxonomy: e.target.value,
										internal_links_terms: [],
									})
								}
								placeholder="Select taxonomy..."
							/>
						)}
					</div>
					{selectedInternalLinksMode === 'specific_taxonomy' && selectedTaxonomy && (
						<div className="space-y-1">
							<MultiSelect
								label="Terms"
								tooltip="Choose terms for internal-link sourcing. Term post counts are shown in each option."
								options={internalLinkTermOptions}
								value={normalizedSelectedTerms}
								onChange={(values) => handleChange('internal_links_terms', values)}
								placeholder="Select terms..."
							/>
						</div>
					)}
				</div>

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
