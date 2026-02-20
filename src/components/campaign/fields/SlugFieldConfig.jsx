import { Select, Textarea } from '../../common';

const MODE_OPTIONS = [
	{ value: 'generate', label: 'Auto Generate Slug' },
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
				value={config.mode || 'generate'}
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

		</div>
	);
}
