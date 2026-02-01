import { Select } from '../common';
import { getPostTypes } from '../../api/client';

const STATUS_OPTIONS = [
	{ value: 'draft', label: 'Draft' },
	{ value: 'pending', label: 'Pending Review' },
	{ value: 'publish', label: 'Published' },
	{ value: 'private', label: 'Private' },
];

export default function PostWorkForm({ postWork, onChange, webhooks = [], users = [] }) {
	const postTypes = getPostTypes();
	const postTypeOptions = Object.entries(postTypes).map(([value, label]) => ({ value, label }));
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
					label="Post Type"
					options={postTypeOptions}
					value={postWork.post_type || 'post'}
					onChange={(e) => handleChange('post_type', e.target.value)}
				/>

				<Select
					label="Default Post Status"
					options={STATUS_OPTIONS}
					value={postWork.post_status || 'pending'}
					onChange={(e) => handleChange('post_status', e.target.value)}
				/>

				<Select
					label="Default Author"
					options={userOptions}
					value={postWork.default_author_id?.toString() || ''}
					onChange={(e) => handleChange('default_author_id', e.target.value)}
					placeholder="Select author..."
				/>

				<Select
					label="Webhook"
					options={webhookOptions}
					value={postWork.webhook_id?.toString() || ''}
					onChange={(e) => handleChange('webhook_id', e.target.value)}
					placeholder="Select webhook..."
				/>
			</div>
		</div>
	);
}
