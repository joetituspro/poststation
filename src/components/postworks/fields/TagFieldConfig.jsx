import { Select, Textarea, MultiSelect } from '../../common';
import { getTaxonomies } from '../../../api/client';

const MODE_OPTIONS = [
	{ value: 'manual', label: 'Manual Selection' },
	{ value: 'generate', label: 'Generate Based on Article' },
	{ value: 'auto_select', label: 'Auto Select from Existing' },
];

export default function TagFieldConfig({ config, onChange, taxonomies: taxonomiesProp }) {
	const hasTaxonomies = taxonomiesProp && Object.keys(taxonomiesProp).length > 0;
	const taxonomies = hasTaxonomies ? taxonomiesProp : (getTaxonomies() ?? {});
	const tagTerms = Array.isArray(taxonomies?.post_tag?.terms) ? taxonomies.post_tag.terms : [];

	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const tagOptions = tagTerms.map(term => ({
		value: term.slug || term.term_id?.toString(),
		label: term.name,
	}));

	return (
		<div className="space-y-4">
			<Select
				label="Mode"
				options={MODE_OPTIONS}
				value={config.mode || 'generate'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			{config.mode === 'manual' && (
				<MultiSelect
					label="Select Tags"
					options={tagOptions}
					value={config.selected || []}
					onChange={(selected) => handleChange('selected', selected)}
					placeholder="Choose tags..."
				/>
			)}

			{(config.mode === 'generate' || config.mode === 'auto_select') && (
				<Textarea
					label="Additional Prompt"
					value={config.prompt || ''}
					onChange={(e) => handleChange('prompt', e.target.value)}
					placeholder={
						config.mode === 'generate'
							? 'Instructions for generating tags...'
							: 'Instructions for selecting from existing tags...'
					}
					rows={3}
				/>
			)}
		</div>
	);
}
