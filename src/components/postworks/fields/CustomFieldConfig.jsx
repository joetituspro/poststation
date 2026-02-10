import { Input, Textarea, Select } from '../../common';

export default function CustomFieldConfig({ config, onChange }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const promptContextOptions = [
		{ value: 'article', label: 'Articles' },
		{ value: 'topic', label: 'Topic' },
		{ value: 'article_and_topic', label: 'Article and topic' },
		{ value: 'research_content', label: 'Research Content' },
		{ value: 'none', label: 'None' },
	];

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

			<Select
				label="Prompt Context"
				tooltip="Context included when generating this field."
				options={promptContextOptions}
				value={config.prompt_context || 'article_and_topic'}
				onChange={(e) => handleChange('prompt_context', e.target.value)}
			/>
		</div>
	);
}
