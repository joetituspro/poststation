import { Select, Textarea } from '../../common';

const MODE_OPTIONS = [
	{ value: 'single_prompt', label: 'Single Prompt Article' },
	{ value: 'sectioned', label: 'Sectioned Article' },
];

export default function BodyFieldConfig({ config, onChange }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	return (
		<div className="space-y-4">
			<Select
				label="Mode"
				options={MODE_OPTIONS}
				value={config.mode || 'single_prompt'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			<Textarea
				label="Additional Prompt"
				value={config.prompt || ''}
				onChange={(e) => handleChange('prompt', e.target.value)}
				placeholder="Add specific instructions for content generation..."
				rows={4}
			/>

			{config.mode === 'sectioned' && (
				<p className="text-sm text-gray-500">
					Sectioned mode will generate content with distinct sections/headings for better structure.
				</p>
			)}
		</div>
	);
}
