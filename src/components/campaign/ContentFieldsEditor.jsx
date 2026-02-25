import { useState } from 'react';
import { Button, Select } from '../common';
import { getTaxonomies, getBootstrapSettings } from '../../api/client';
import TitleFieldConfig from './fields/TitleFieldConfig';
import SlugFieldConfig from './fields/SlugFieldConfig';
import BodyFieldConfig from './fields/BodyFieldConfig';
import CategoryFieldConfig from './fields/CategoryFieldConfig';
import TagFieldConfig from './fields/TagFieldConfig';
import CustomTaxFieldConfig from './fields/CustomTaxFieldConfig';
import CustomFieldConfig from './fields/CustomFieldConfig';
import ImageFieldConfig from './fields/ImageFieldConfig';

const FIELD_TYPES = [
	{ value: 'slug', label: 'Slug' },
	{ value: 'categories', label: 'Categories' },
	{ value: 'tags', label: 'Tags' },
	{ value: 'custom_tax', label: 'Custom Taxonomy' },
	{ value: 'custom_field', label: 'Custom Field' },
	{ value: 'image', label: 'Featured Image' },
];

// Default content fields structure
const getDefaultContentFields = (settings = null) => {
	const defaultTextModel = settings?.openrouter_default_text_model || '';
	const defaultImageModel = settings?.openrouter_default_image_model || '';
	return ({
	title: {
		enabled: true,
		mode: 'generate',
		prompt: '',
	},
	slug: {
		enabled: true,
		mode: 'generate',
		prompt: '',
	},
	body: {
		enabled: true,
		mode: 'single_prompt',
		prompt: '',
		model_id: defaultTextModel,
		media_prompt: '',
		image_model_id: defaultImageModel,
		disable_intelligence_analysis: false,
		disable_outline: false,
	},
	categories: {
		enabled: false,
		mode: 'manual',
		prompt: '',
		model_id: defaultTextModel,
		selected: [],
	},
	tags: {
		enabled: false,
		mode: 'generate',
		prompt: '',
		model_id: defaultTextModel,
		selected: [],
	},
	custom_taxonomies: [],
	custom_fields: [],
	image: {
		enabled: false,
		mode: 'generate_from_article',
		prompt: '',
		model_id: defaultImageModel,
		image_size: '1344x768',
		image_style: 'none',
		template_id: '',
		category_text: '',
		main_text: '',
		category_color: '#000000',
		title_color: '#000000',
		background_images: [],
	},
});
};

const normalizeContentFields = (rawFields, settings = null) => {
	const defaults = getDefaultContentFields(settings);
	const fields = rawFields && typeof rawFields === 'object' ? rawFields : {};
	const defaultTextModel = settings?.openrouter_default_text_model || '';
	const defaultImageModel = settings?.openrouter_default_image_model || '';

	const modelOrDefault = (value, fallback) => (value && String(value).trim() !== '' ? value : fallback);

	return {
		...defaults,
		...fields,
		title: {
			...defaults.title,
			...(fields.title || {}),
			mode: 'generate',
		},
		slug: {
			...defaults.slug,
			...(fields.slug || {}),
			mode: 'generate',
		},
		body: {
			...defaults.body,
			...(fields.body || {}),
			model_id: modelOrDefault(fields?.body?.model_id, defaultTextModel),
			image_model_id: modelOrDefault(fields?.body?.image_model_id, defaultImageModel),
		},
		categories: {
			...defaults.categories,
			...(fields.categories || {}),
			model_id: modelOrDefault(fields?.categories?.model_id, defaultTextModel),
		},
		tags: {
			...defaults.tags,
			...(fields.tags || {}),
			model_id: modelOrDefault(fields?.tags?.model_id, defaultTextModel),
		},
		image: {
			...defaults.image,
			...(fields.image || {}),
			model_id: modelOrDefault(fields?.image?.model_id, defaultImageModel),
		},
		custom_taxonomies: Array.isArray(fields.custom_taxonomies)
			? fields.custom_taxonomies.map((item, index) => ({
				id: `custom_tax_${index}`,
				taxonomy: '',
				mode: 'manual',
				prompt: '',
				model_id: defaultTextModel,
				selected: [],
				...(item || {}),
				model_id: modelOrDefault(item?.model_id, defaultTextModel),
			}))
			: [],
		custom_fields: Array.isArray(fields.custom_fields)
			? fields.custom_fields.map((item, index) => ({
				id: `custom_field_${index}`,
				meta_key: '',
				prompt: '',
				prompt_context: 'article_and_topic',
				model_id: defaultTextModel,
				...(item || {}),
				model_id: modelOrDefault(item?.model_id, defaultTextModel),
			}))
			: [],
	};
};

export default function ContentFieldsEditor({ campaign, onChange, taxonomies: taxonomiesProp }) {
	const [selectedType, setSelectedType] = useState('');
	const [expandedField, setExpandedField] = useState(null);
	const hasTaxonomies = taxonomiesProp && Object.keys(taxonomiesProp).length > 0;
	const taxonomies = hasTaxonomies ? taxonomiesProp : (getTaxonomies() ?? {});
	const bootstrapSettings = getBootstrapSettings();
	const defaultTextModel = bootstrapSettings?.openrouter_default_text_model || '';

	// Parse content fields or use defaults
	const rawContentFields = campaign.content_fields
		? (typeof campaign.content_fields === 'string'
			? JSON.parse(campaign.content_fields)
			: campaign.content_fields)
		: getDefaultContentFields(bootstrapSettings);
	const contentFields = normalizeContentFields(rawContentFields, bootstrapSettings);

	const updateContentFields = (newFields) => {
		onChange({
			...campaign,
			content_fields: JSON.stringify(newFields),
		});
	};

	const notifyImageFieldRemoved = () => {
		onChange({
			...campaign,
			clear_image_overrides: true,
		});
	};

	const handleAddField = () => {
		if (!selectedType) return;

		const newFields = { ...contentFields };

		// Handle different field types
		if (selectedType === 'title' || selectedType === 'slug' || selectedType === 'body' || selectedType === 'categories' || selectedType === 'tags' || selectedType === 'image') {
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
				{ id: Date.now(), taxonomy: '', mode: 'manual', prompt: '', model_id: defaultTextModel, selected: [] },
			];
			updateContentFields(newFields);
			setExpandedField(`custom_tax_${newFields.custom_taxonomies.length - 1}`);
		} else if (selectedType === 'custom_field') {
			// Add a new custom field
			newFields.custom_fields = [
				...(newFields.custom_fields || []),
				{ id: Date.now(), meta_key: '', prompt: '', prompt_context: 'article_and_topic', model_id: defaultTextModel },
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
		
		if (fieldType === 'image') {
			notifyImageFieldRemoved();
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
		return !contentFields[type.value]?.enabled;
	});

	return (
		<div className="space-y-4 mt-4">
			<h4 className="text-lg font-medium text-gray-900 mb-2">Content Fields</h4>
			{/* Add Field Controls */}
			<div className="flex flex-col sm:flex-row gap-2">
				<Select
					tooltip="Choose a content field to configure for this campaign."
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

				{/* Slug Field */}
				{contentFields.slug?.enabled && (
					<FieldCard
						title="Slug"
						isExpanded={expandedField === 'slug'}
						onToggle={() => toggleExpand('slug')}
						onRemove={() => handleRemoveField('slug')}
					>
						<SlugFieldConfig
							config={contentFields.slug}
							onChange={(config) => handleFieldChange('slug', config)}
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
							campaignType={campaign?.campaign_type || 'default'}
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
