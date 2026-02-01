import { Input } from '../common';

export default function BlockForm({ block, postWork, onChange }) {
	const handleChange = (field, value) => {
		onChange({ [field]: value });
	};

	return (
		<div className="space-y-4">
			{/* Topic Information */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Input
					label="Topic / Keyword"
					value={block.keyword || ''}
					onChange={(e) => handleChange('keyword', e.target.value)}
					placeholder="Main topic or keyword for this post"
				/>
				<Input
					label="Reference URL (Optional)"
					value={block.article_url || ''}
					onChange={(e) => handleChange('article_url', e.target.value)}
					placeholder="https://example.com/reference-article"
				/>
			</div>

			{/* Featured Image Override */}
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Input
					label="Featured Image Title (Override)"
					value={block.feature_image_title || ''}
					onChange={(e) => handleChange('feature_image_title', e.target.value)}
					placeholder="Leave empty to use generated title"
				/>
				<div>
					<label className="block text-sm font-medium text-gray-700 mb-1">
						Featured Image (Override)
					</label>
					{block.feature_image_id ? (
						<div className="flex items-center gap-2">
							<span className="text-sm text-gray-600">Image ID: {block.feature_image_id}</span>
							<button
								type="button"
								onClick={() => handleChange('feature_image_id', null)}
								className="text-sm text-red-600 hover:text-red-900"
							>
								Remove
							</button>
						</div>
					) : (
						<button
							type="button"
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
							className="px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50"
						>
							Select Image
						</button>
					)}
				</div>
			</div>
		</div>
	);
}
