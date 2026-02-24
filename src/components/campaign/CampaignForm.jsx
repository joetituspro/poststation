import { Select } from '../common';
import { getPostTypes, getLanguages, getCountries } from '../../api/client';

const STATUS_OPTIONS = [
	{ value: 'draft', label: 'Draft' },
	{ value: 'pending', label: 'Pending Review' },
	{ value: 'publish', label: 'Published' },
	{ value: 'private', label: 'Private' },
];

const CAMPAIGN_TYPE_OPTIONS = [
	{ value: 'default', label: 'Default' },
	{ value: 'listicle', label: 'Listicle' },
	{ value: 'rewrite_blog_post', label: 'Rewrite Blog Post' },
];

const TONE_OPTIONS = [
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

const POV_OPTIONS = [
	{ value: 'none', label: 'None' },
	{ value: 'first_person_singular', label: 'First Person Singular (I/me)' },
	{ value: 'first_person_plural', label: 'First Person Plural (we/us)' },
	{ value: 'second_person', label: 'Second Person (you)' },
	{ value: 'third_person', label: 'Third Person (he/she/they)' },
];

const READABILITY_OPTIONS = [
	{ value: 'grade_4', label: '4th Grade (Very Easy)' },
	{ value: 'grade_6', label: '6th Grade (Easy)' },
	{ value: 'grade_8', label: '8th Grade (Plain English/Average)' },
	{ value: 'grade_10_12', label: '10th–12th Grade (High School)' },
	{ value: 'college_graduate', label: 'College Graduate/Professional (Difficult)' },
];

export default function CampaignForm({ campaign, onChange, webhooks = [], users = [] }) {
	const postTypes = getPostTypes();
	const languages = getLanguages();
	const countries = getCountries();
	const postTypeOptions = Object.entries(postTypes).map(([value, label]) => ({ value, label }));
	const languageOptions = Object.entries(languages).map(([value, label]) => ({ value, label }));
	const countryOptions = Object.entries(countries).map(([value, label]) => ({ value, label }));
	const webhookOptions = webhooks.map((w) => ({ value: w.id.toString(), label: w.name }));
	const userOptions = users.map((u) => ({ value: u.id.toString(), label: u.display_name }));

	const handleChange = (field, value) => {
		onChange({ ...campaign, [field]: value });
	};

	return (
		<div className="space-y-4">
			{/* Main settings grid - no title (edited in header) */}
			<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
				<Select
					label="Campaign Type"
					tooltip="<strong>Campaign Type</strong> sets the overall writing style and structure used for this campaign."
					options={CAMPAIGN_TYPE_OPTIONS}
					value={campaign.campaign_type || 'default'}
					onChange={(e) => handleChange('campaign_type', e.target.value)}
					required
				/>

				<Select
					label="Language"
					tooltip="Primary language for generated content and taxonomy suggestions."
					options={languageOptions}
					value={campaign.language || 'en'}
					onChange={(e) => handleChange('language', e.target.value)}
					required
				/>

				<Select
					label="Tone of Voice"
					tooltip="Global tone used for body generation across tasks."
					options={TONE_OPTIONS}
					value={campaign.tone_of_voice || 'none'}
					onChange={(e) => handleChange('tone_of_voice', e.target.value)}
					required
				/>

				<Select
					label="Point of View"
					tooltip="Global narrative perspective used for generated writing."
					options={POV_OPTIONS}
					value={campaign.point_of_view || 'none'}
					onChange={(e) => handleChange('point_of_view', e.target.value)}
					required
				/>

				<Select
					label="Readability"
					tooltip="Reading complexity level target for generated text."
					options={READABILITY_OPTIONS}
					value={campaign.readability || 'grade_8'}
					onChange={(e) => handleChange('readability', e.target.value)}
					required
				/>

				<Select
					label="Target Country"
					tooltip="Preferred country or region for localization. Default is International."
					options={countryOptions}
					value={campaign.target_country || 'international'}
					onChange={(e) => handleChange('target_country', e.target.value)}
					required
				/>

				<Select
					label="Post Type"
					tooltip="WordPress post type that will be created (e.g., Post, Page, or a custom type)."
					options={postTypeOptions}
					value={campaign.post_type || 'post'}
					onChange={(e) => handleChange('post_type', e.target.value)}
					required
				/>

				<Select
					label="Default Post Status"
					tooltip="Status applied when publishing (Draft, Pending, Published, or Private)."
					options={STATUS_OPTIONS}
					value={campaign.post_status || 'pending'}
					onChange={(e) => handleChange('post_status', e.target.value)}
					required
				/>

				<Select
					label="Default Author"
					tooltip="Default author assigned to created posts."
					options={userOptions}
					value={campaign.default_author_id?.toString() || ''}
					onChange={(e) => handleChange('default_author_id', e.target.value)}
					placeholder="Select author..."
					required
				/>

				<Select
					label="RSS Feeds"
					tooltip="Enable RSS feed sources for this campaign. When enabled, you can set feed URLs and frequency, and run RSS checks to add items as tasks."
					options={[
						{ value: 'no', label: 'No' },
						{ value: 'yes', label: 'Yes' },
					]}
					value={campaign.rss_enabled || 'no'}
					onChange={(e) => handleChange('rss_enabled', e.target.value)}
				/>

				<Select
					label="Webhook"
					tooltip="Webhook endpoint that receives the generation payload for this campaign."
					options={webhookOptions}
					value={campaign.webhook_id?.toString() || ''}
					onChange={(e) => handleChange('webhook_id', e.target.value)}
					placeholder="Select webhook..."
					required
				/>
			</div>
		</div>
	);
}
