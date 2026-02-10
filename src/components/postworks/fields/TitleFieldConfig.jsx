import { Select, Textarea } from '../../common';

const MODE_OPTIONS = [
	{ value: 'generate', label: 'Generate New Title' },
	{ value: 'use_topic_as_title', label: 'Use Topic as Title' },
];

export default function TitleFieldConfig({ config, onChange }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const promptContextOptions = [
		{ value: 'article', label: 'Articles' },
		{ value: 'topic', label: 'Topic' },
		{ value: 'article_and_topic', label: 'Article and topic' },
	];

	return (
		<div className="space-y-4">
			<Select
				label="Mode"
				tooltip="Controls how the title is produced for the post."
				options={MODE_OPTIONS}
				value={config.mode || 'generate'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			{config.mode !== 'use_topic_as_title' && (
				<>
					<Textarea
						label="Additional Instruction"
						tooltip="Extra guidance for the title generation prompt."
						value={config.prompt || ''}
						onChange={(e) => handleChange('prompt', e.target.value)}
						placeholder="Optional: Add specific instructions for title generation..."
						rows={3}
					/>

					<Select
						label="Prompt Context"
						tooltip="Context included when generating this field."
						options={promptContextOptions}
						value={config.prompt_context || 'article_and_topic'}
						onChange={(e) => handleChange('prompt_context', e.target.value)}
					/>
				</>
			)}
		</div>
	);
}
