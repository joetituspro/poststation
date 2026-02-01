import { Select, Textarea, MultiSelect } from '../../common';
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
			label: tax.label || key,
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
		label: term.name,
	}));

	return (
		<div className="space-y-4">
			<Select
				label="Taxonomy"
				options={customTaxonomies}
				value={config.taxonomy || ''}
				onChange={(e) => handleChange('taxonomy', e.target.value)}
				placeholder="Select a taxonomy..."
			/>

			{config.taxonomy && (
				<>
					<Select
						label="Mode"
						options={MODE_OPTIONS}
						value={config.mode || 'manual'}
						onChange={(e) => handleChange('mode', e.target.value)}
					/>

					{config.mode === 'manual' && (
						<MultiSelect
							label={`Select ${taxonomies?.[config.taxonomy]?.label || 'Terms'}`}
							options={termOptions}
							value={config.selected || []}
							onChange={(selected) => handleChange('selected', selected)}
							placeholder="Choose terms..."
						/>
					)}

					{(config.mode === 'generate' || config.mode === 'auto_select') && (
						<Textarea
							label="Additional Prompt"
							value={config.prompt || ''}
							onChange={(e) => handleChange('prompt', e.target.value)}
							placeholder={
								config.mode === 'generate'
									? 'Instructions for generating terms...'
									: 'Instructions for selecting from existing terms...'
							}
							rows={3}
						/>
					)}
				</>
			)}
		</div>
	);
}
