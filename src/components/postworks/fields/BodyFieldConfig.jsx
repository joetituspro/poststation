import { Select, Textarea, Input, Tooltip } from '../../common';

const MODE_OPTIONS = [
	{ value: 'single_prompt', label: 'Single Prompt Article' },
	{ value: 'sectioned', label: 'Sectioned Article' },
];

export default function BodyFieldConfig({ config, onChange, articleType }) {
	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const isListicle = articleType === 'listicle';

	const toneOptions = [
		{ value: 'none', label: 'None' },
		{ value: 'friendly', label: 'Friendly' },
		{ value: 'professional', label: 'Professional' },
		{ value: 'informational', label: 'Informational' },
		{ value: 'transactional', label: 'Transactional' },
		{ value: 'inspirational', label: 'Inspirational' },
		{ value: 'neutral', label: 'Neutral' },
		{ value: 'witty', label: 'Witty' },
		{ value: 'casual', label: 'Casual' },
		{ value: 'authoritative', label: 'Authoritative' },
		{ value: 'encouraging', label: 'Encouraging' },
		{ value: 'persuasive', label: 'Persuasive' },
		{ value: 'poetic', label: 'Poetic' },
	];

	const povOptions = [
		{ value: 'none', label: 'None' },
		{ value: 'first_person_singular', label: 'First person singular (I, me, my, mine)' },
		{ value: 'first_person_plural', label: 'First person plural (we, us, our, ours)' },
		{ value: 'second_person', label: 'Second person (you, your, yours)' },
		{ value: 'third_person', label: 'Third person (he, she, it, they)' },
	];

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
			<Select
				label="Mode"
				tooltip="Single prompt generates one body. Sectioned creates structured sections."
				options={MODE_OPTIONS}
				value={config.mode || 'single_prompt'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			<Textarea
				label="Additional Instruction"
				tooltip="Extra guidance used when generating the body content."
				value={config.prompt || ''}
				onChange={(e) => handleChange('prompt', e.target.value)}
				placeholder="Add specific instructions for content generation..."
				rows={4}
			/>

			<div className="space-y-4">
				<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
					<Select
						label="Tone of Voice"
						tooltip="Defines the writing tone for sectioned content."
						options={toneOptions}
						value={config.tone_of_voice || 'none'}
						onChange={(e) => handleChange('tone_of_voice', e.target.value)}
					/>

					<Select
						label="Point of View"
						tooltip="Narrative perspective used in the content."
						options={povOptions}
						value={config.point_of_view || 'none'}
						onChange={(e) => handleChange('point_of_view', e.target.value)}
					/>
				</div>

				<div className="space-y-2">
					<h4 className="text-sm font-semibold text-gray-700">Intro Hook</h4>
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
					<h4 className="text-sm font-semibold text-gray-700">Structure</h4>
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
						<h4 className="text-sm font-semibold text-gray-700">List Config</h4>
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
								className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
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
							rows={3}
						/>
					</div>
				)}
			</div>
		</div>
	);
}
