import { Input, Textarea } from '../../common';

export default function CustomFieldConfig({ config, onChange }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	return (
		<div className="space-y-4">
			<Input
				label="Meta Key"
				tooltip="WordPress meta key to store the generated value."
				value={config.meta_key || ''}
				onChange={(e) => handleChange('meta_key', e.target.value)}
				placeholder="e.g., _seo_description, custom_meta_key"
			/>

			<Textarea
				label="Generation Prompt"
				tooltip="Instructions for generating the custom field value."
				value={config.prompt || ''}
				onChange={(e) => handleChange('prompt', e.target.value)}
				placeholder="Instructions for generating this field's value..."
				rows={3}
			/>
		</div>
	);
}
