import { Select } from '../common';
import { getPostTypes, getLanguages, getCountries } from '../../api/client';

const STATUS_OPTIONS = [
	{ value: 'draft', label: 'Draft' },
	{ value: 'pending', label: 'Pending Review' },
	{ value: 'publish', label: 'Published' },
	{ value: 'private', label: 'Private' },
];

const ARTICLE_TYPE_OPTIONS = [
	{ value: 'blog_post', label: 'Blog Post' },
	{ value: 'listicle', label: 'Listicle' },
	{ value: 'rewrite_blog_post', label: 'Rewrite Blog Post' },
];

export default function PostWorkForm({ postWork, onChange, webhooks = [], users = [] }) {
	const postTypes = getPostTypes();
	const languages = getLanguages();
	const countries = getCountries();
	const postTypeOptions = Object.entries(postTypes).map(([value, label]) => ({ value, label }));
	const languageOptions = Object.entries(languages).map(([value, label]) => ({ value, label }));
	const countryOptions = Object.entries(countries).map(([value, label]) => ({ value, label }));
	const webhookOptions = webhooks.map((w) => ({ value: w.id.toString(), label: w.name }));
	const userOptions = users.map((u) => ({ value: u.id.toString(), label: u.display_name }));

	const handleChange = (field, value) => {
		onChange({ ...postWork, [field]: value });
	};

	return (
		<div className="space-y-4">
			{/* Main settings grid - no title (edited in header) */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Select
					label="Article Type"
					tooltip="<strong>Article Type</strong> sets the overall writing style and structure used for this post work."
					options={ARTICLE_TYPE_OPTIONS}
					value={postWork.article_type || 'blog_post'}
					onChange={(e) => handleChange('article_type', e.target.value)}
				/>

				<Select
					label="Language"
					tooltip="Primary language for generated content and taxonomy suggestions."
					options={languageOptions}
					value={postWork.language || 'en'}
					onChange={(e) => handleChange('language', e.target.value)}
				/>

				<Select
					label="Target Country"
					tooltip="Preferred country or region for localization. Default is International."
					options={countryOptions}
					value={postWork.target_country || 'international'}
					onChange={(e) => handleChange('target_country', e.target.value)}
				/>

				<Select
					label="Post Type"
					tooltip="WordPress post type that will be created (e.g., Post, Page, or a custom type)."
					options={postTypeOptions}
					value={postWork.post_type || 'post'}
					onChange={(e) => handleChange('post_type', e.target.value)}
				/>

				<Select
					label="Default Post Status"
					tooltip="Status applied when publishing (Draft, Pending, Published, or Private)."
					options={STATUS_OPTIONS}
					value={postWork.post_status || 'pending'}
					onChange={(e) => handleChange('post_status', e.target.value)}
				/>

				<Select
					label="Default Author"
					tooltip="Default author assigned to created posts."
					options={userOptions}
					value={postWork.default_author_id?.toString() || ''}
					onChange={(e) => handleChange('default_author_id', e.target.value)}
					placeholder="Select author..."
				/>

				<Select
					label="Webhook"
					tooltip="Webhook endpoint that receives the generation payload for this post work."
					options={webhookOptions}
					value={postWork.webhook_id?.toString() || ''}
					onChange={(e) => handleChange('webhook_id', e.target.value)}
					placeholder="Select webhook..."
				/>
			</div>
		</div>
	);
}
