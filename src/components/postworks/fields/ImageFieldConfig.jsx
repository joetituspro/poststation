import { Select, Textarea, Input, Tooltip } from '../../common';

const MODE_OPTIONS = [
	{ value: 'generate_from_title', label: 'Generate from Article Title' },
	{ value: 'generate_from_dt', label: 'Generate from DT (Design Template)' },
];

export default function ImageFieldConfig({ config, onChange }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

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
				value={config.mode || 'generate_from_title'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			{config.mode === 'generate_from_title' && (
				<Textarea
					label="Additional Instruction"
					tooltip="Extra guidance for the image generation."
					value={config.prompt || ''}
					onChange={(e) => handleChange('prompt', e.target.value)}
					placeholder="Instructions for generating the featured image..."
					rows={3}
				/>
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
								className="w-full h-10 rounded border border-gray-300 cursor-pointer"
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
								className="w-full h-10 rounded border border-gray-300 cursor-pointer"
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
								className="text-sm text-indigo-600 hover:text-indigo-900 disabled:opacity-50"
							>
								Add Images
							</button>
						</div>
						{(config.background_images || []).length > 0 ? (
							<div className="flex flex-wrap gap-2">
								{(config.background_images || []).map((imageId, index) => (
									<div
										key={index}
										className="relative w-16 h-16 bg-gray-100 rounded border border-gray-200 flex items-center justify-center"
									>
										<span className="text-xs text-gray-500">#{imageId}</span>
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
