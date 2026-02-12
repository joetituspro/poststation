import { Select, Textarea, MultiSelect, Input, ModelSelect } from '../../common';
import { getTaxonomies } from '../../../api/client';

const MODE_OPTIONS = [
	{ value: 'manual', label: 'Manual Selection' },
	{ value: 'generate', label: 'Generate Based on Article' },
	{ value: 'auto_select', label: 'Auto Select from Existing' },
];

export default function CustomTaxFieldConfig({ config, onChange, taxonomies: taxonomiesProp }) {
	const hasTaxonomies = taxonomiesProp && Object.keys(taxonomiesProp).length > 0;
	const taxonomies = hasTaxonomies ? taxonomiesProp : (getTaxonomies() ?? {});
	
	// Get custom taxonomies (exclude category and post_tag)
	const customTaxonomies = Object.entries(taxonomies)
		.filter(([key]) => !['category', 'post_tag'].includes(key))
		.map(([key, tax]) => ({
			value: key,
			label: (tax.label || key).replace(/&amp;/g, '&'),
		}));

	const handleChange = (field, value) => {
		onChange({ ...config, [field]: value });
	};

	// Get terms for the selected taxonomy
	const selectedTaxTerms = config.taxonomy && Array.isArray(taxonomies?.[config.taxonomy]?.terms)
		? taxonomies[config.taxonomy].terms
		: [];

	const termOptions = selectedTaxTerms.map(term => ({
		value: term.slug || term.term_id?.toString(),
		label: (term.name || '').replace(/&amp;/g, '&'),
	}));

	return (
		<div className="space-y-4">
			<Select
				label="Taxonomy"
				tooltip="Choose a custom taxonomy to manage for this post work."
				options={customTaxonomies}
				value={config.taxonomy || ''}
				onChange={(e) => handleChange('taxonomy', e.target.value)}
				placeholder="Select a taxonomy..."
			/>

			{config.taxonomy && (
				<>
					<Select
						label="Mode"
						tooltip="Manual: pick terms. Generate: create new. Auto-select: choose from existing."
						options={MODE_OPTIONS}
						value={config.mode || 'manual'}
						onChange={(e) => handleChange('mode', e.target.value)}
					/>

					{config.mode === 'manual' && (
						<MultiSelect
							label={`Select ${taxonomies?.[config.taxonomy]?.label || 'Terms'}`}
							tooltip="Choose specific terms to assign."
							options={termOptions}
							value={config.selected || []}
							onChange={(selected) => handleChange('selected', selected)}
							placeholder="Choose terms..."
						/>
					)}

					{(config.mode === 'generate' || config.mode === 'auto_select') && (
						<Input
							label={config.mode === 'generate' ? "Number of Terms to Generate" : "Number of Terms to Auto-Select"}
							tooltip="How many terms to return for this post."
							type="number"
							min="1"
							value={config.term_count || 3}
							onChange={(e) => handleChange('term_count', Math.max(1, parseInt(e.target.value) || 1))}
						/>
					)}

					{(config.mode === 'generate' || config.mode === 'auto_select') && (
						<Textarea
							label="Additional Instruction"
							tooltip="Optional instructions to guide term selection."
							value={config.prompt || ''}
							onChange={(e) => handleChange('prompt', e.target.value)}
							placeholder={
								config.mode === 'generate'
									? 'Instructions for generating terms...'
									: 'Instructions for selecting from existing terms...'
							}
							rows={2}
						/>
					)}

					{(config.mode === 'generate' || config.mode === 'auto_select') && (
						<ModelSelect
							label="Model"
							tooltip="OpenRouter model used to generate or auto-select terms."
							value={config.model_id || ''}
							onChange={(e) => handleChange('model_id', e.target.value)}
							filter="text"
						/>
					)}
				</>
			)}
		</div>
	);
}
