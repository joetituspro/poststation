import { useEffect, useState } from 'react';
import { Input, Select, Tooltip } from '../common';

const ARTICLE_TYPE_OPTIONS = [
	{ value: 'blog_post', label: 'Blog Post' },
	{ value: 'listicle', label: 'Listicle' },
	{ value: 'rewrite_blog_post', label: 'Rewrite Blog Post' },
];

export default function PostTaskForm({ task, campaign, onChange }) {
	const isProcessing = task.status === 'processing';
	const [featuredImageUrl, setFeaturedImageUrl] = useState('');

	const handleChange = (field, value) => {
		if (isProcessing) return;
		onChange({ [field]: value });
	};

	const resolvedArticleType = task.article_type || campaign?.article_type || 'blog_post';
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

		const resolveAttachment = async () => {
			const attachmentId = Number(task.feature_image_id);
			if (!attachmentId || !window.wp?.media?.attachment) {
				if (mounted) setFeaturedImageUrl('');
				return;
			}

			try {
				const attachment = window.wp.media.attachment(attachmentId);
				await attachment.fetch();
				const attrs = attachment.attributes || {};
				const resolvedUrl =
					attrs?.sizes?.thumbnail?.url ||
					attrs?.sizes?.medium?.url ||
					attrs?.url ||
					'';
				if (mounted) {
					setFeaturedImageUrl(resolvedUrl);
				}
			} catch {
				if (mounted) {
					setFeaturedImageUrl('');
				}
			}
		};

		resolveAttachment();
		return () => {
			mounted = false;
		};
	}, [task.feature_image_id]);

	const handleTopicChange = (value) => {
		handleChange('topic', value);
	};

	const handleKeywordsChange = (value) => {
		// Allow free typing, but limit to 5 keywords
		const parts = value.split(',');
		if (parts.length <= 5) {
			handleChange('keywords', value);
		}
	};

	return (
		<div className={`space-y-4 ${isProcessing ? 'opacity-75' : ''}`}>
			{/* Article Type Selector */}
			<div className="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-3">
				<Select
					label="Article Type"
					tooltip="Overrides the campaign article type for this post task only."
					options={ARTICLE_TYPE_OPTIONS}
					value={resolvedArticleType}
					onChange={(e) => handleChange('article_type', e.target.value)}
					className="min-w-0"
					disabled={isProcessing}
				/>
				{resolvedArticleType !== 'rewrite_blog_post' ? (
					<Input
						label="Topic"
						tooltip="Main topic used for generation and placeholders."
						value={task.topic ?? ''}
						onChange={(e) => handleTopicChange(e.target.value)}
						placeholder="Main topic for this post task"
						required
						disabled={isProcessing}
					/>
				) : (
					<Input
						label="Research URL"
						tooltip="Source URL for rewrite mode. Content is based on this article."
						value={task.research_url || ''}
						onChange={(e) => handleChange('research_url', e.target.value)}
						placeholder="https://example.com/article"
						required
						disabled={isProcessing}
					/>
				)}
			</div>

			{/* Keywords */}
			<div className="grid grid-cols-1">
				<Input
					label="Keywords (Optional, max 5)"
					tooltip="Comma-separated list. The first keyword is treated as primary."
					value={task.keywords || ''}
					onChange={(e) => handleKeywordsChange(e.target.value)}
					placeholder="keyword one, keyword two"
					disabled={isProcessing}
				/>
			</div>

			{/* Title + Slug Overrides */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Input
					label="Title Override (Optional)"
					tooltip="If set, campaign title generation is disabled for this task and this value is sent to the webhook."
					value={task.title_override ?? ''}
					onChange={(e) => handleChange('title_override', e.target.value)}
					placeholder="Manual title for this post task"
					disabled={isProcessing}
				/>
				<Input
					label="Slug Override (Optional)"
					tooltip="If set, campaign slug generation is disabled for this task and this value is sent to the webhook."
					value={task.slug_override ?? ''}
					onChange={(e) => handleChange('slug_override', e.target.value)}
					placeholder="manual-post-slug"
					disabled={isProcessing}
				/>
			</div>

			{/* Featured Image Override */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				{showImageTitleOverride && (
					<Input
						label="Featured Image Title (Override)"
						tooltip="Overrides the title used when generating the featured image."
						value={task.feature_image_title || ''}
						onChange={(e) => handleChange('feature_image_title', e.target.value)}
						placeholder="Leave empty to use generated title"
						disabled={isProcessing}
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
