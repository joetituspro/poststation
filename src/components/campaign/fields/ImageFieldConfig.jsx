import { useEffect, useState } from 'react';
import { Select, Textarea, Input, Tooltip, ModelSelect } from '../../common';

const MODE_OPTIONS = [
	{ value: 'generate', label: 'Generate New' },
	{ value: 'generate_from_dt', label: 'Generate from DT (Design Template)' },
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

export default function ImageFieldConfig({ config, onChange }) {
	const [backgroundImagePreviews, setBackgroundImagePreviews] = useState({});

	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	useEffect(() => {
		let mounted = true;
		const ids = (config.background_images || []).filter(Boolean);
		if (!ids.length || !window.wp?.media?.attachment) {
			setBackgroundImagePreviews({});
			return;
		}

		const resolvePreviews = async () => {
			const nextPreviews = {};
			await Promise.all(
				ids.map(async (rawId) => {
					const imageId = Number(rawId);
					if (!imageId) return;
					try {
						const attachment = window.wp.media.attachment(imageId);
						await attachment.fetch();
						const attrs = attachment.attributes || {};
						const resolvedUrl =
							attrs?.sizes?.thumbnail?.url ||
							attrs?.sizes?.medium?.url ||
							attrs?.url ||
							'';
						if (resolvedUrl) {
							nextPreviews[imageId] = resolvedUrl;
						}
					} catch {
						// Keep fallback card with ID.
					}
				})
			);
			if (mounted) {
				setBackgroundImagePreviews(nextPreviews);
			}
		};

		resolvePreviews();
		return () => {
			mounted = false;
		};
	}, [config.background_images]);

	const handleAddBackgroundImage = () => {
		if (window.wp?.media) {
			const frame = window.wp.media({
				title: 'Select Background Image',
				button: { text: 'Select' },
				multiple: true,
			});
			frame.on('select', () => {
				const attachments = frame.state().get('selection').toJSON();
				const newImages = attachments.map((a) => a.id);
				const existing = config.background_images || [];
				handleChange('background_images', [...existing, ...newImages].slice(0, 15));
			});
			frame.open();
		}
	};

	const handleRemoveBackgroundImage = (index) => {
		const images = [...(config.background_images || [])];
		images.splice(index, 1);
		handleChange('background_images', images);
	};

	return (
		<div className="space-y-4">
			<Select
				label="Mode"
				tooltip="Choose how the featured image is produced."
				options={MODE_OPTIONS}
				value={config.mode || 'generate'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			{config.mode === 'generate' && (
				<>
					<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
						<Select
							label="Image Size"
							tooltip="The aspect ratio and resolution for the generated image."
							options={imageSizeOptions}
							value={config.image_size || '1344x768'}
							onChange={(e) => handleChange('image_size', e.target.value)}
						/>

						<Select
							label="Image Style"
							tooltip="The visual style for the generated image."
							options={imageStyleOptions}
							value={config.image_style || 'none'}
							onChange={(e) => handleChange('image_style', e.target.value)}
						/>
					</div>

					<Textarea
						label="Additional Instruction"
						tooltip="Extra guidance for the image generation."
						value={config.prompt || ''}
						onChange={(e) => handleChange('prompt', e.target.value)}
						placeholder="Instructions for generating the featured image..."
						rows={2}
					/>

					<ModelSelect
						label="Image Model"
						tooltip="OpenRouter image model used for featured image generation."
						value={config.model_id || ''}
						onChange={(e) => handleChange('model_id', e.target.value)}
						filter="image"
					/>
				</>
			)}

			{config.mode === 'generate_from_dt' && (
				<>
					<Input
						label="Template ID"
						tooltip="ID of the design template used by the image generator."
						value={config.template_id || ''}
						onChange={(e) => handleChange('template_id', e.target.value)}
						placeholder="Design template ID"
					/>

					<Input
						label="Category Text"
						tooltip="Text displayed as the category label on the image."
						value={config.category_text || ''}
						onChange={(e) => handleChange('category_text', e.target.value)}
						placeholder="Category label for the image"
					/>

					<Input
						label="Main Text"
						tooltip="Main headline text for the image. Supports placeholders like {{title}}."
						value={config.main_text || ''}
						onChange={(e) => handleChange('main_text', e.target.value)}
						placeholder="{{title}} or custom text"
					/>

					<div className="grid grid-cols-2 gap-4">
						<div>
							<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
								<span>Category Color</span>
								<Tooltip content="Color used for the category label on the image." />
							</label>
							<input
								type="color"
								value={config.category_color || '#000000'}
								onChange={(e) => handleChange('category_color', e.target.value)}
								className="poststation-field-color"
							/>
						</div>
						<div>
							<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
								<span>Title Color</span>
								<Tooltip content="Color used for the main text on the image." />
							</label>
							<input
								type="color"
								value={config.title_color || '#000000'}
								onChange={(e) => handleChange('title_color', e.target.value)}
								className="poststation-field-color"
							/>
						</div>
					</div>

					{/* Background Images */}
					<div>
						<div className="flex items-center justify-between mb-2">
							<label className="flex items-center text-sm font-medium text-gray-700">
								<span>Background Images ({(config.background_images || []).length}/15)</span>
								<Tooltip content="Optional list of background images used by the generator." />
							</label>
							<button
								type="button"
								onClick={handleAddBackgroundImage}
								disabled={(config.background_images || []).length >= 15}
								className="px-2 py-1 text-xs border border-indigo-200 text-indigo-700 rounded hover:bg-indigo-50 disabled:opacity-50"
							>
								Add Images
							</button>
						</div>
						{(config.background_images || []).length > 0 ? (
							<div className="flex flex-wrap gap-2">
								{(config.background_images || []).map((imageId, index) => (
									<div
										key={index}
										className="relative w-16 h-16 bg-gray-100 rounded border border-gray-200 overflow-hidden flex items-center justify-center"
									>
										{backgroundImagePreviews[Number(imageId)] ? (
											<img
												src={backgroundImagePreviews[Number(imageId)]}
												alt={`Background ${imageId}`}
												className="w-full h-full object-cover"
											/>
										) : (
											<span className="text-xs text-gray-500">#{imageId}</span>
										)}
										<button
											type="button"
											onClick={() => handleRemoveBackgroundImage(index)}
											className="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white rounded-full text-xs flex items-center justify-center"
										>
											×
										</button>
									</div>
								))}
							</div>
						) : (
							<p className="text-sm text-gray-500">No background images selected</p>
						)}
					</div>
				</>
			)}
		</div>
	);
}
