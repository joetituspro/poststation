import { Select, Textarea, ModelSelect } from '../../common';

const MODE_OPTIONS = [
	{ value: 'generate_from_title', label: 'Generate from Title' },
];

export default function SlugFieldConfig({ config, onChange }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	return (
		<div className="space-y-4">
			<Select
				label="Mode"
				tooltip="Controls how the slug is generated."
				options={MODE_OPTIONS}
				value={config.mode || 'generate_from_title'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			<Textarea
				label="Additional Instruction"
				tooltip="Optional guidance for slug generation (keywords, style, length)."
				value={config.prompt || ''}
				onChange={(e) => handleChange('prompt', e.target.value)}
				placeholder="Optional: e.g. keep it short, include primary keyword"
				rows={2}
			/>

			<ModelSelect
				label="Model"
				tooltip="OpenRouter model used to generate the slug."
				value={config.model_id || ''}
				onChange={(e) => handleChange('model_id', e.target.value)}
				filter="text"
			/>
		</div>
	);
}
