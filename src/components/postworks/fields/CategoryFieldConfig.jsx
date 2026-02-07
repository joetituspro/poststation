import { Select, Textarea, MultiSelect, Input } from '../../common';
import { getTaxonomies } from '../../../api/client';

const MODE_OPTIONS = [
	{ value: 'manual', label: 'Manual Selection' },
	{ value: 'generate', label: 'Generate Based on Article' },
	{ value: 'auto_select', label: 'Auto Select from Existing' },
];

export default function CategoryFieldConfig({ config, onChange, taxonomies: taxonomiesProp }) {
	const hasTaxonomies = taxonomiesProp && Object.keys(taxonomiesProp).length > 0;
	const taxonomies = hasTaxonomies ? taxonomiesProp : (getTaxonomies() ?? {});
	const categoryTerms = Array.isArray(taxonomies?.category?.terms) ? taxonomies.category.terms : [];

	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	const categoryOptions = categoryTerms.map(term => ({
		value: term.slug || term.term_id?.toString(),
		label: (term.name || '').replace(/&amp;/g, '&'),
	}));

	return (
		<div className="space-y-4">
			<Select
				label="Mode"
				tooltip="Manual: pick categories. Generate: create new. Auto-select: choose from existing."
				options={MODE_OPTIONS}
				value={config.mode || 'manual'}
				onChange={(e) => handleChange('mode', e.target.value)}
			/>

			{config.mode === 'manual' && (
				<MultiSelect
					label="Select Categories"
					tooltip="Choose specific categories to assign."
					options={categoryOptions}
					value={config.selected || []}
					onChange={(selected) => handleChange('selected', selected)}
					placeholder="Choose categories..."
				/>
			)}

			{(config.mode === 'generate' || config.mode === 'auto_select') && (
				<Input
					label={config.mode === 'generate' ? "Number of Categories to Generate" : "Number of Categories to Auto-Select"}
					tooltip="How many categories to return for this post."
					type="number"
					min="1"
					value={config.term_count || 3}
					onChange={(e) => handleChange('term_count', Math.max(1, parseInt(e.target.value) || 1))}
				/>
			)}

			{(config.mode === 'generate' || config.mode === 'auto_select') && (
				<Textarea
					label="Additional Instruction"
					tooltip="Optional instructions to guide category selection."
					value={config.prompt || ''}
					onChange={(e) => handleChange('prompt', e.target.value)}
					placeholder={
						config.mode === 'generate'
							? 'Instructions for generating categories...'
							: 'Instructions for selecting from existing categories...'
					}
					rows={3}
				/>
			)}
		</div>
	);
}
