import { useState } from 'react';
import { Button, Select } from '../common';
import { getTaxonomies } from '../../api/client';
import TitleFieldConfig from './fields/TitleFieldConfig';
import BodyFieldConfig from './fields/BodyFieldConfig';
import CategoryFieldConfig from './fields/CategoryFieldConfig';
import TagFieldConfig from './fields/TagFieldConfig';
import CustomTaxFieldConfig from './fields/CustomTaxFieldConfig';
import CustomFieldConfig from './fields/CustomFieldConfig';
import ImageFieldConfig from './fields/ImageFieldConfig';

const FIELD_TYPES = [
	{ value: 'title', label: 'Title' },
	{ value: 'body', label: 'Body' },
	{ value: 'categories', label: 'Categories' },
	{ value: 'tags', label: 'Tags' },
	{ value: 'custom_tax', label: 'Custom Taxonomy' },
	{ value: 'custom_field', label: 'Custom Field' },
	{ value: 'image', label: 'Image' },
];

// Default content fields structure
const getDefaultContentFields = () => ({
	title: {
		enabled: true,
		mode: 'generate_from_topic',
		prompt: '',
	},
	body: {
		enabled: true,
		mode: 'single_prompt',
		prompt: '',
	},
	categories: {
		enabled: false,
		mode: 'manual',
		prompt: '',
		selected: [],
	},
	tags: {
		enabled: false,
		mode: 'generate',
		prompt: '',
		selected: [],
	},
	custom_taxonomies: [],
	custom_fields: [],
	image: {
		enabled: false,
		mode: 'generate_from_title',
		prompt: '',
		template_id: '',
		category_text: '',
		main_text: '',
		category_color: '#000000',
		title_color: '#000000',
		background_images: [],
	},
});

export default function ContentFieldsEditor({ postWork, onChange, taxonomies: taxonomiesProp }) {
	const [selectedType, setSelectedType] = useState('');
	const [expandedField, setExpandedField] = useState(null);
	const hasTaxonomies = taxonomiesProp && Object.keys(taxonomiesProp).length > 0;
	const taxonomies = hasTaxonomies ? taxonomiesProp : (getTaxonomies() ?? {});

	// Parse content fields or use defaults
	const contentFields = postWork.content_fields 
		? (typeof postWork.content_fields === 'string' 
			? JSON.parse(postWork.content_fields) 
			: postWork.content_fields)
		: getDefaultContentFields();

	const updateContentFields = (newFields) => {
		onChange({
			...postWork,
			content_fields: JSON.stringify(newFields),
		});
	};

	const handleAddField = () => {
		if (!selectedType) return;

		const newFields = { ...contentFields };

		// Handle different field types
		if (selectedType === 'title' || selectedType === 'body' || selectedType === 'categories' || selectedType === 'tags' || selectedType === 'image') {
			if (newFields[selectedType]?.enabled) {
				// Already enabled, just expand it
				setExpandedField(selectedType);
			} else {
				// Enable the field
				newFields[selectedType] = {
					...newFields[selectedType],
					enabled: true,
				};
				updateContentFields(newFields);
				setExpandedField(selectedType);
			}
		} else if (selectedType === 'custom_tax') {
			// Add a new custom taxonomy
			newFields.custom_taxonomies = [
				...(newFields.custom_taxonomies || []),
				{ id: Date.now(), taxonomy: '', mode: 'manual', prompt: '', selected: [] },
			];
			updateContentFields(newFields);
			setExpandedField(`custom_tax_${newFields.custom_taxonomies.length - 1}`);
		} else if (selectedType === 'custom_field') {
			// Add a new custom field
			newFields.custom_fields = [
				...(newFields.custom_fields || []),
				{ id: Date.now(), meta_key: '', prompt: '' },
			];
			updateContentFields(newFields);
			setExpandedField(`custom_field_${newFields.custom_fields.length - 1}`);
		}

		setSelectedType('');
	};

	const handleFieldChange = (fieldType, fieldConfig) => {
		const newFields = { ...contentFields };
		newFields[fieldType] = fieldConfig;
		updateContentFields(newFields);
	};

	const handleRemoveField = (fieldType, index = null) => {
		const newFields = { ...contentFields };
		
		if (fieldType === 'custom_taxonomies' && index !== null) {
			newFields.custom_taxonomies = newFields.custom_taxonomies.filter((_, i) => i !== index);
		} else if (fieldType === 'custom_fields' && index !== null) {
			newFields.custom_fields = newFields.custom_fields.filter((_, i) => i !== index);
		} else if (fieldType !== 'title' && fieldType !== 'body') {
			// Don't allow removing title/body, just disable them
			newFields[fieldType] = { ...newFields[fieldType], enabled: false };
		}
		
		updateContentFields(newFields);
	};

	const handleCustomTaxChange = (index, config) => {
		const newFields = { ...contentFields };
		newFields.custom_taxonomies[index] = config;
		updateContentFields(newFields);
	};

	const handleCustomFieldChange = (index, config) => {
		const newFields = { ...contentFields };
		newFields.custom_fields[index] = config;
		updateContentFields(newFields);
	};

	const toggleExpand = (fieldId) => {
		setExpandedField(expandedField === fieldId ? null : fieldId);
	};

	// Get available field types (exclude already added single fields)
	const availableTypes = FIELD_TYPES.filter(type => {
		if (['custom_tax', 'custom_field'].includes(type.value)) return true;
		if (['title', 'body'].includes(type.value)) return true; // Always show, will expand if already enabled
		return !contentFields[type.value]?.enabled;
	});

	return (
		<div className="space-y-4">
			{/* Add Field Controls */}
			<div className="flex flex-col sm:flex-row gap-2">
				<Select
					options={availableTypes}
					value={selectedType}
					onChange={(e) => setSelectedType(e.target.value)}
					placeholder="Select field type to add..."
					className="flex-1"
				/>
				<Button onClick={handleAddField} disabled={!selectedType} className="w-full sm:w-auto">
					Add
				</Button>
			</div>

			{/* Field Cards */}
			<div className="space-y-3">
				{/* Title Field */}
				{contentFields.title?.enabled && (
					<FieldCard
						title="Title"
						isExpanded={expandedField === 'title'}
						onToggle={() => toggleExpand('title')}
						canRemove={false}
					>
						<TitleFieldConfig
							config={contentFields.title}
							onChange={(config) => handleFieldChange('title', config)}
						/>
					</FieldCard>
				)}

				{/* Body Field */}
				{contentFields.body?.enabled && (
					<FieldCard
						title="Body"
						isExpanded={expandedField === 'body'}
						onToggle={() => toggleExpand('body')}
						canRemove={false}
					>
						<BodyFieldConfig
							config={contentFields.body}
							onChange={(config) => handleFieldChange('body', config)}
						/>
					</FieldCard>
				)}

				{/* Categories Field */}
				{contentFields.categories?.enabled && (
					<FieldCard
						title="Categories"
						isExpanded={expandedField === 'categories'}
						onToggle={() => toggleExpand('categories')}
						onRemove={() => handleRemoveField('categories')}
					>
						<CategoryFieldConfig
							config={contentFields.categories}
							onChange={(config) => handleFieldChange('categories', config)}
							taxonomies={taxonomies}
						/>
					</FieldCard>
				)}

				{/* Tags Field */}
				{contentFields.tags?.enabled && (
					<FieldCard
						title="Tags"
						isExpanded={expandedField === 'tags'}
						onToggle={() => toggleExpand('tags')}
						onRemove={() => handleRemoveField('tags')}
					>
						<TagFieldConfig
							config={contentFields.tags}
							onChange={(config) => handleFieldChange('tags', config)}
							taxonomies={taxonomies}
						/>
					</FieldCard>
				)}

				{/* Custom Taxonomies */}
				{(contentFields.custom_taxonomies || []).map((taxConfig, index) => (
					<FieldCard
						key={taxConfig.id || index}
						title={`Custom Taxonomy${taxConfig.taxonomy ? `: ${taxConfig.taxonomy}` : ''}`}
						isExpanded={expandedField === `custom_tax_${index}`}
						onToggle={() => toggleExpand(`custom_tax_${index}`)}
						onRemove={() => handleRemoveField('custom_taxonomies', index)}
					>
						<CustomTaxFieldConfig
							config={taxConfig}
							onChange={(config) => handleCustomTaxChange(index, config)}
							taxonomies={taxonomies}
						/>
					</FieldCard>
				))}

				{/* Custom Fields */}
				{(contentFields.custom_fields || []).map((fieldConfig, index) => (
					<FieldCard
						key={fieldConfig.id || index}
						title={`Custom Field${fieldConfig.meta_key ? `: ${fieldConfig.meta_key}` : ''}`}
						isExpanded={expandedField === `custom_field_${index}`}
						onToggle={() => toggleExpand(`custom_field_${index}`)}
						onRemove={() => handleRemoveField('custom_fields', index)}
					>
						<CustomFieldConfig
							config={fieldConfig}
							onChange={(config) => handleCustomFieldChange(index, config)}
						/>
					</FieldCard>
				))}

				{/* Image Field */}
				{contentFields.image?.enabled && (
					<FieldCard
						title="Featured Image"
						isExpanded={expandedField === 'image'}
						onToggle={() => toggleExpand('image')}
						onRemove={() => handleRemoveField('image')}
					>
						<ImageFieldConfig
							config={contentFields.image}
							onChange={(config) => handleFieldChange('image', config)}
						/>
					</FieldCard>
				)}
			</div>

			{/* Empty state */}
			{!contentFields.title?.enabled && !contentFields.body?.enabled && (
				<div className="text-center py-8 text-gray-500">
					<p>No content fields configured. Add Title and Body to get started.</p>
				</div>
			)}
		</div>
	);
}

// Reusable Field Card Component
function FieldCard({ title, isExpanded, onToggle, onRemove, canRemove = true, children }) {
	return (
		<div className="border border-gray-200 rounded-lg overflow-hidden">
			<div
				className="flex items-center justify-between px-4 py-3 bg-gray-50 cursor-pointer hover:bg-gray-100"
				onClick={onToggle}
			>
				<div className="flex items-center gap-3">
					<svg
						className={`w-4 h-4 text-gray-400 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
						fill="none"
						viewBox="0 0 24 24"
						stroke="currentColor"
					>
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
					</svg>
					<span className="font-medium text-gray-900">{title}</span>
				</div>
				{canRemove && onRemove && (
					<button
						onClick={(e) => {
							e.stopPropagation();
							onRemove();
						}}
						className="text-sm text-red-600 hover:text-red-800"
					>
						Remove
					</button>
				)}
			</div>
			{isExpanded && (
				<div className="px-4 py-4 bg-white border-t border-gray-200">
					{children}
				</div>
			)}
		</div>
	);
}
