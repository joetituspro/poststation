import { useEffect, useState } from 'react';
import { Input, Select, Tooltip } from '../common';
import {
	PUBLICATION_MODE_OPTIONS,
	getDatePlusDaysValue,
	getNowDateTimeLocalValue,
	getTodayDateValue,
	normalizeDateTimeLocalValue,
} from '../../utils/publication';

// Cache attachment ID -> preview URL so image doesn't refetch when task block is expanded again
const attachmentUrlCache = new Map();

const CAMPAIGN_TYPE_OPTIONS = [
	{ value: 'default', label: 'Default' },
	{ value: 'listicle', label: 'Listicle' },
	{ value: 'rewrite_blog_post', label: 'Rewrite Blog Post' },
];

export default function PostTaskForm({ task, campaign, onChange }) {
	const isProcessing = task.status === 'processing';
	const [featuredImageUrl, setFeaturedImageUrl] = useState(() => {
		const id = Number(task?.feature_image_id);
		return id ? (attachmentUrlCache.get(id) || '') : '';
	});
	const [featureImageLoading, setFeatureImageLoading] = useState(false);

	const [isSlugSynced, setIsSlugSynced] = useState(!task.slug_override || task.slug_override.trim() === '');

	const handleChange = (field, value) => {
		if (isProcessing) return;
		const updates = { [field]: value };

		// If user manually edits the slug, stop syncing it from the title
		if (field === 'slug_override') {
			const isValueEmpty = !value || value.trim() === '';
			setIsSlugSynced(isValueEmpty);
			
			// Auto format slug when typed manually
			updates.slug_override = value
				.toLowerCase()
				.replace(/[^\w\s-]/g, '') // Remove non-word chars
				.replace(/\s+/g, '-') // Replace spaces with -
				.replace(/-+/g, '-') // Replace multiple - with single -
				.trimStart(); // trimStart instead of trim to allow typing spaces that become hyphens
		}

		// If title_override is changed and slug should be synced, update slug
		if (field === 'title_override' && isSlugSynced) {
			updates.slug_override = value
				.toLowerCase()
				.replace(/[^\w\s-]/g, '') // Remove non-word chars
				.replace(/\s+/g, '-') // Replace spaces with -
				.replace(/-+/g, '-') // Replace multiple - with single -
				.trim();
		}

		if (field === 'publication_mode') {
			const today = getTodayDateValue();
			if (value === 'schedule_date' && !(task.publication_date ?? '').trim()) {
				updates.publication_date = getNowDateTimeLocalValue();
			}
			if (value === 'publish_randomly') {
				const from = task.publication_random_from || today;
				updates.publication_random_from = from;
				updates.publication_random_to =
					task.publication_random_to || getDatePlusDaysValue(30, from);
			}
		}

		onChange(updates);
	};

	const resolvedCampaignType = task.campaign_type || campaign?.campaign_type || 'default';
	const contentFields = campaign?.content_fields
		? (typeof campaign.content_fields === 'string'
			? JSON.parse(campaign.content_fields)
			: campaign.content_fields)
		: {};
	const imageConfig = contentFields?.image || null;
	const imageMode = imageConfig?.mode || 'generate_from_article';
	const showImageTitleOverride = Boolean(imageConfig?.enabled && imageMode === 'generate_from_dt');

	useEffect(() => {
		let mounted = true;
		const attachmentId = Number(task.feature_image_id);

		if (!attachmentId || !window.wp?.media?.attachment) {
			setFeaturedImageUrl('');
			setFeatureImageLoading(false);
			return;
		}

		const cached = attachmentUrlCache.get(attachmentId);
		if (cached) {
			setFeaturedImageUrl(cached);
			setFeatureImageLoading(false);
			return;
		}

		setFeatureImageLoading(true);
		const attachment = window.wp.media.attachment(attachmentId);
		attachment.fetch().then(() => {
			if (!mounted) return;
			const attrs = attachment.attributes || {};
			const resolvedUrl =
				attrs?.sizes?.thumbnail?.url ||
				attrs?.sizes?.medium?.url ||
				attrs?.url ||
				'';
			attachmentUrlCache.set(attachmentId, resolvedUrl);
			setFeaturedImageUrl(resolvedUrl);
			setFeatureImageLoading(false);
		}).catch(() => {
			if (mounted) {
				setFeaturedImageUrl('');
				setFeatureImageLoading(false);
			}
		});

		return () => {
			mounted = false;
		};
	}, [task.feature_image_id]);

	const handleTopicChange = (value) => {
		handleChange('topic', value);
	};

	return (
		<div className={`space-y-3 ${isProcessing ? 'opacity-75' : ''}`}>
			{/* Campaign Type Selector */}
			<div className="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-3">
				<Select
					label="Campaign Type"
					tooltip="Overrides the campaign type for this post task only."
					options={CAMPAIGN_TYPE_OPTIONS}
					value={resolvedCampaignType}
					onChange={(e) => handleChange('campaign_type', e.target.value)}
					className="min-w-0"
					disabled={isProcessing}
					variant="floating"
				/>
				{resolvedCampaignType !== 'rewrite_blog_post' ? (
					<Input
						label="Topic / Keyword"
						tooltip="Topic or keyword you want to write about."
						value={task.topic ?? ''}
						onChange={(e) => handleTopicChange(e.target.value)}
						required
						disabled={isProcessing}
						variant="floating"
					/>
				) : (
					<Input
						label="Research URL"
						tooltip="Source URL for rewrite mode. Content is based on this article."
						value={task.research_url || ''}
						onChange={(e) => handleChange('research_url', e.target.value)}
						required
						disabled={isProcessing}
						variant="floating"
					/>
				)}
			</div>

			<Input
				label="Keywords"
				tooltip="Comma-separated keywords for this task (e.g. for generation or SEO)."
				placeholder="e.g. coffee, london, guide"
				value={task.keywords ?? ''}
				onChange={(e) => handleChange('keywords', e.target.value)}
				disabled={isProcessing}
				variant="floating"
			/>

			{/* Title + Slug Overrides */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Input
					label="Title Override (Optional)"
					tooltip="If set, campaign title generation is disabled for this task and this value is sent to the webhook."
					value={task.title_override ?? ''}
					onChange={(e) => handleChange('title_override', e.target.value)}
					disabled={isProcessing}
					variant="floating"
				/>
				<Input
					label="Slug Override (Optional)"
					tooltip="If set, campaign slug generation is disabled for this task and this value is sent to the webhook."
					value={task.slug_override ?? ''}
					onChange={(e) => handleChange('slug_override', e.target.value)}
					disabled={isProcessing}
					variant="floating"
				/>
			</div>

			<div className="space-y-3">
				<Select
					label="Publication"
					tooltip="Overrides campaign publication behavior for this task."
					options={PUBLICATION_MODE_OPTIONS}
					value={task.publication_mode || campaign?.publication_mode || 'pending_review'}
					onChange={(e) => handleChange('publication_mode', e.target.value)}
					disabled={isProcessing}
					variant="floating"
				/>

				{(task.publication_mode || campaign?.publication_mode || 'pending_review') === 'schedule_date' && (
					<Input
						label="Publication Date & Time"
						type="datetime-local"
						value={normalizeDateTimeLocalValue(task.publication_date || getNowDateTimeLocalValue())}
						onChange={(e) => handleChange('publication_date', e.target.value)}
						min={getNowDateTimeLocalValue()}
						required
						disabled={isProcessing}
						variant="floating"
					/>
				)}

				{(task.publication_mode || campaign?.publication_mode || 'pending_review') === 'publish_randomly' && (
					<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
						<Input
							label="Random Publish From"
							type="date"
							value={task.publication_random_from || getTodayDateValue()}
							onChange={(e) => {
								const from = e.target.value;
								const updates = { publication_random_from: from };
								const currentTo = task.publication_random_to || '';
								if (!currentTo || currentTo < from) {
									updates.publication_random_to = getDatePlusDaysValue(30, from);
								}
								onChange(updates);
							}}
							min={getTodayDateValue()}
							required
							disabled={isProcessing}
							variant="floating"
						/>
						<Input
							label="Random Publish To"
							type="date"
							value={task.publication_random_to || getDatePlusDaysValue(30, task.publication_random_from || getTodayDateValue())}
							onChange={(e) => handleChange('publication_random_to', e.target.value)}
							min={task.publication_random_from || getTodayDateValue()}
							required
							disabled={isProcessing}
							variant="floating"
						/>
					<p className="md:col-span-2 text-xs text-gray-500">
						Tasks are scheduled one per day across the selected range, then cycle back to the start date.
					</p>
				</div>
			)}
			</div>

			{/* Featured Image Override */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				{showImageTitleOverride && (
					<Input
						label="Featured Image Title (Override)"
						tooltip="Overrides the title used when generating the featured image."
						value={task.feature_image_title || ''}
						onChange={(e) => handleChange('feature_image_title', e.target.value)}
						disabled={isProcessing}
						variant="floating"
					/>
				)}
				<div>
					<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
						<span>Featured Image (Override)</span>
						<Tooltip content="Select a specific image to use instead of the generated image." />
					</label>
					{task.feature_image_id ? (
						<div className="flex items-center gap-3">
							{featuredImageUrl ? (
								<img
									src={featuredImageUrl}
									alt="Featured override preview"
									className="w-12 h-12 rounded object-cover border border-gray-200"
								/>
							) : featureImageLoading ? (
								<div className="w-12 h-12 rounded bg-gray-100 border border-gray-200 flex items-center justify-center">
									<svg className="w-5 h-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
										<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
										<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
									</svg>
								</div>
							) : (
								<div className="w-12 h-12 rounded bg-gray-100 border border-gray-200 flex items-center justify-center text-xs text-gray-500">
									#{task.feature_image_id}
								</div>
							)}
							<span className="text-sm text-gray-600">Image ID: {task.feature_image_id}</span>
							{!isProcessing && (
								<button
									type="button"
									onClick={() => handleChange('feature_image_id', null)}
									className="text-sm text-red-600 hover:text-red-900"
								>
									Remove
								</button>
							)}
						</div>
					) : (
						<button
							type="button"
							disabled={isProcessing}
							onClick={() => {
								// Open WordPress media library
								if (window.wp?.media) {
									const frame = window.wp.media({
										title: 'Select Featured Image',
										button: { text: 'Select' },
										multiple: false,
									});
									frame.on('select', () => {
										const attachment = frame.state().get('selection').first().toJSON();
										const url =
											attachment.sizes?.thumbnail?.url ||
											attachment.sizes?.medium?.url ||
											attachment.url ||
											'';
										if (url) attachmentUrlCache.set(attachment.id, url);
										setFeaturedImageUrl(url);
										handleChange('feature_image_id', attachment.id);
									});
									frame.open();
								}
							}}
							className="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
						>
							Select Image
						</button>
					)}
				</div>
			</div>
		</div>
	);
}
