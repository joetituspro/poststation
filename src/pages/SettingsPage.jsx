import { useState, useCallback, useEffect } from 'react';
import { Button, Input, Modal, ConfirmModal, Card, CardHeader, CardBody, PageHeader, PageLoader, ModelSelect } from '../components/common';
import WritingPresetModal from '../components/writing-presets/WritingPresetModal';
import { settings, getBootstrapSettings, getBootstrapWritingPresets, refreshBootstrap, writingPresets, getPluginName } from '../api/client';

const DEFAULT_WRITING_PRESET_KEYS = ['listicle', 'news', 'guide', 'howto'];
const isDefaultPreset = (key) => key && DEFAULT_WRITING_PRESET_KEYS.includes(key);
import { useQuery, useMutation } from '../hooks/useApi';

export default function SettingsPage() {
	const pluginName = getPluginName();
	const [showApiDocs, setShowApiDocs] = useState(false);
	const [apiKey, setApiKey] = useState('');
	const [openRouterApiKey, setOpenRouterApiKey] = useState('');
	const [defaultTextModel, setDefaultTextModel] = useState('');
	const [defaultImageModel, setDefaultImageModel] = useState('');
	const [copied, setCopied] = useState(false);

	const [writingPresetModal, setWritingPresetModal] = useState({ open: false, mode: 'add', writingPreset: null });
	const [deleteWritingPreset, setDeleteWritingPreset] = useState(null);
	const [deleting, setDeleting] = useState(false);
	const [writingPresetsList, setWritingPresetsList] = useState(() => getBootstrapWritingPresets());
	const fetchWritingPresets = useCallback(async () => {
		await refreshBootstrap();
		setWritingPresetsList(getBootstrapWritingPresets());
	}, []);

	const bootstrapSettings = getBootstrapSettings();
	const fetchSettings = useCallback(() => settings.get(), []);
	const { data, loading, error, refetch } = useQuery(fetchSettings, [], { initialData: bootstrapSettings });
	const { mutate: regenerateApiKey, loading: regenerating } = useMutation(settings.regenerateApiKey, {
		onSuccess: (result) => {
			if (result?.api_key) {
				setApiKey(result.api_key);
			}
			refreshBootstrap();
		},
	});
	const { mutate: saveOpenRouterApiKey, loading: savingOpenRouter } = useMutation(settings.saveOpenRouterApiKey, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: saveOpenRouterDefaults, loading: savingOpenRouterDefaults } = useMutation(settings.saveOpenRouterDefaults, {
		onSuccess: refreshBootstrap,
	});

	useEffect(() => {
		if (data?.api_key) {
			setApiKey(data.api_key);
		}
		setDefaultTextModel(data?.openrouter_default_text_model || '');
		setDefaultImageModel(data?.openrouter_default_image_model || '');
	}, [data]);

	const handleCopy = () => {
		navigator.clipboard.writeText(apiKey || data?.api_key || '');
		setCopied(true);
		setTimeout(() => setCopied(false), 2000);
	};

	const REGENERATE_CONFIRM_MESSAGE =
		'Generate a new API key? The current key will stop working immediately. You must update any clients using the old key.';

	const handleRegenerate = async () => {
		if (!window.confirm(REGENERATE_CONFIRM_MESSAGE)) return;
		try {
			const result = await regenerateApiKey();
			if (result?.api_key) setApiKey(result.api_key);
		} catch (err) {
			console.error('Failed to regenerate API key:', err);
		}
	};

	const handleSaveOpenRouterSettings = async () => {
		try {
			if ((openRouterApiKey || '').trim() !== '') {
				await saveOpenRouterApiKey(openRouterApiKey);
				setOpenRouterApiKey('');
			}
			await saveOpenRouterDefaults(defaultTextModel, defaultImageModel);
			refetch();
		} catch (err) {
			console.error('Failed to save OpenRouter settings:', err);
			refetch();
		}
	};

	const openWritingPresetModal = (mode, writingPreset = null) => {
		setWritingPresetModal({ open: true, mode, writingPreset });
	};
	const closeWritingPresetModal = () => {
		setWritingPresetModal((prev) => ({ ...prev, open: false }));
	};
	const handleWritingPresetSaved = () => {
		// Modal already called refreshBootstrap() before onSaved; use current bootstrap so list updates immediately
		setWritingPresetsList(getBootstrapWritingPresets());
	};

	const handleConfirmDeleteWritingPreset = async () => {
		if (!deleteWritingPreset?.id) return;
		setDeleting(true);
		try {
			await writingPresets.delete(deleteWritingPreset.id);
			await refreshBootstrap();
			setWritingPresetsList(getBootstrapWritingPresets());
			setDeleteWritingPreset(null);
		} catch (err) {
			console.error('Failed to delete writing preset:', err);
		} finally {
			setDeleting(false);
		}
	};

	if (loading) return <PageLoader />;

	return (
		<div>
			<PageHeader
				title="Settings"
				description={ `Manage your ${ pluginName } configuration` }
			/>

			<div className="max-w-5xl grid grid-cols-2 gap-6">
				{/* API Key Card */}
				<Card>
					<CardHeader>
						<div className="flex items-center justify-between">
							<div>
								<h3 className="text-lg font-medium text-gray-900">API Key</h3>
								<p className="text-sm text-gray-500">Use this key to authenticate API requests</p>
							</div>
							<Button variant="secondary" onClick={() => setShowApiDocs(true)}>
								View API Docs
							</Button>
						</div>
					</CardHeader>
					<CardBody>
						<div className="space-y-4">
							<div className="flex gap-2 items-end">
								<Input
									label="API Key"
									tooltip={ `Used to authenticate requests to the ${ pluginName } API.` }
									type="text"
									value={apiKey || data?.api_key || ''}
									readOnly
									className="flex-1"
								/>
								<button
									type="button"
									className="poststation-icon-btn mb-1"
									onClick={handleCopy}
									title={copied ? 'Copied!' : 'Copy'}
									aria-label="Copy"
								>
									{copied ? (
										<svg className="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
										</svg>
									) : (
										<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<path strokeLinecap="round" strokeLinejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
										</svg>
									)}
								</button>
								<button
									type="button"
									className="poststation-icon-btn mb-1"
									onClick={handleRegenerate}
									disabled={regenerating}
									title="Regenerate API key"
									aria-label="Regenerate API key"
								>
									{regenerating ? (
										<svg className="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
											<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
											<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
										</svg>
									) : (
										<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
										</svg>
									)}
								</button>
							</div>
						</div>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<div>
							<h3 className="text-lg font-medium text-gray-900">OpenRouter</h3>
							<p className="text-sm text-gray-500">Store your OpenRouter API key for model discovery</p>
						</div>
					</CardHeader>
					<CardBody>
						<div className="space-y-4">
							<Input
								label="OpenRouter API Key"
								tooltip="Saved encrypted server-side. The value cannot be viewed after saving."
								type="password"
								value={openRouterApiKey}
								onChange={(e) => setOpenRouterApiKey(e.target.value)}
								placeholder={data?.openrouter_api_key_set ? 'Saved (hidden). Enter new key to replace or leave empty to clear.' : 'Enter OpenRouter API key'}
							/>
							<p className="text-xs text-gray-500">
								{data?.openrouter_api_key_set
									? 'An OpenRouter key is currently stored and hidden.'
									: 'No OpenRouter key stored yet.'}
							</p>
							<div className="grid grid-cols-1 gap-3 pt-2 border-t border-gray-100">
								<ModelSelect
									label="Default Text Model"
									tooltip="Used as the default text model for new and unconfigured Campaign fields."
									value={defaultTextModel}
									onChange={(e) => setDefaultTextModel(e.target.value)}
									filter="text"
								/>
								<ModelSelect
									label="Default Image Model"
									tooltip="Used as the default image model for new and unconfigured Campaign image fields."
									value={defaultImageModel}
									onChange={(e) => setDefaultImageModel(e.target.value)}
									filter="image"
								/>
							</div>
							<div className="flex justify-end gap-2">
								<Button
									onClick={handleSaveOpenRouterSettings}
									loading={savingOpenRouter || savingOpenRouterDefaults}
								>
									Save OpenRouter Settings
								</Button>
							</div>
						</div>
					</CardBody>
				</Card>

				{/* Writing presets */}
				<Card className="col-span-2">
					<CardHeader>
						<div className="flex items-center justify-between">
							<div>
								<h3 className="text-lg font-medium text-gray-900">Writing presets</h3>
								<p className="text-sm text-gray-500">Manage writing presets used for title, body, and section generation</p>
							</div>
							<Button variant="primary" onClick={() => openWritingPresetModal('add')}>
								Add new
							</Button>
						</div>
					</CardHeader>
					<CardBody>
						<div className="space-y-4">
						{writingPresetsList.length === 0 ? (
							<div className="flex items-center justify-center py-10 px-6 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50">
							<p className="text-sm text-gray-400">No writing presets. Add one to get started.</p>
							</div>
						) : (
							<div className="flex flex-col gap-1.5 max-h-72 overflow-y-auto pr-1">
							{writingPresetsList.map((inst) => (
								<div
								key={inst.id}
								className="group flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 transition-all hover:border-gray-300 hover:bg-gray-50"
								>
								{/* Info */}
								<div className="min-w-0 flex-1 flex flex-col gap-0.5">
									<div className="flex items-center gap-2">
										<span className="font-medium text-sm text-gray-900 truncate">
										{inst.name}
										</span>
										{inst.key && (
										<span className="shrink-0 inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs font-mono text-gray-500">
											{inst.key}
										</span>
										)}
									</div>
									{inst.description && (
									<span className="text-xs text-gray-400 truncate">
										{inst.description}
									</span>
									)}
								</div>

								{/* Actions */}
								<div className="flex items-center gap-1 shrink-0">
									<button
										type="button"
										className="poststation-icon-btn"
										onClick={() => openWritingPresetModal('edit', inst)}
										title="Edit"
										aria-label="Edit"
									>
										<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
										</svg>
									</button>
									<button
										type="button"
										className="poststation-icon-btn"
										onClick={() => openWritingPresetModal('duplicate', inst)}
										title="Duplicate"
										aria-label="Duplicate"
									>
										<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
										</svg>
									</button>
									{!isDefaultPreset(inst.key) && (
										<button
											type="button"
											className="poststation-icon-btn-danger"
											onClick={() => setDeleteWritingPreset(inst)}
											title="Delete"
											aria-label="Delete"
										>
											<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
												<path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
											</svg>
										</button>
									)}
								</div>
								</div>
							))}
							</div>
						)}
						</div>
					</CardBody>
				</Card>
			</div>

			{/* API Documentation Modal */}
			<Modal
				isOpen={showApiDocs}
				onClose={() => setShowApiDocs(false)}
				title="API Documentation"
				size="lg"
			>
				<div className="space-y-6">
					<section>
						<h4 className="font-medium text-gray-900 mb-2">Base URL</h4>
						<p className="text-sm text-gray-600">
							All endpoints live under <code className="font-mono text-indigo-600 bg-gray-100 px-1 rounded">/ps-api/</code> on your site (e.g. <code className="font-mono text-indigo-600 bg-gray-100 px-1 rounded">https://yoursite.com/ps-api/create</code>).
						</p>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">Authentication</h4>
						<p className="text-sm text-gray-600 mb-2">
							Send your API key in the request header for <strong>POST</strong> endpoints:
						</p>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>X-API-Key: your-api-key</code>
						</pre>
						<p className="text-sm text-gray-500 mt-1">GET /ps-api/posttasks does not require the header.</p>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">Endpoints</h4>
						<div className="space-y-3">
							<div className="p-3 bg-gray-50 rounded-lg">
								<code className="text-sm font-mono text-indigo-600">POST /ps-api/create</code>
								<p className="text-sm text-gray-600 mt-1">Create a post. Links to a post task when <code>task_id</code> is provided.</p>
							</div>
							<div className="p-3 bg-gray-50 rounded-lg">
								<code className="text-sm font-mono text-indigo-600">POST /ps-api/progress</code>
								<p className="text-sm text-gray-600 mt-1">Update a post task’s status or progress (e.g. processing, completed, failed).</p>
							</div>
							<div className="p-3 bg-gray-50 rounded-lg">
								<code className="text-sm font-mono text-indigo-600">GET /ps-api/posttasks?campaign_id=123</code>
								<p className="text-sm text-gray-600 mt-1">List post tasks for a campaign. Optional: <code>status</code> (pending, processing, completed, failed, cancelled or &quot;all&quot;), <code>last_task_count</code> for delta updates.</p>
							</div>
							<div className="p-3 bg-gray-50 rounded-lg">
								<code className="text-sm font-mono text-indigo-600">POST /ps-api/upload</code>
								<p className="text-sm text-gray-600 mt-1">Upload an image as base64 for a task (e.g. generated images).</p>
							</div>
						</div>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">POST /ps-api/create — Request body</h4>
						<p className="text-sm text-gray-600 mb-2">JSON body. Content-Type: application/json.</p>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>{`{
  "task_id": 123,
  "title": "Post Title",
  "content": "<p>Post content...</p>",
  "slug": "post-slug",
  "thumbnail_url": "https://...",
  "thumbnail_id": 456,
  "taxonomies": {
    "category": ["news"],
    "post_tag": ["featured"]
  },
  "custom_fields": {
    "meta_key": "meta_value"
  }
}`}</code>
						</pre>
						<ul className="text-sm text-gray-600 space-y-1 mt-2">
							<li><strong>task_id</strong> — Associated post task ID (optional)</li>
							<li><strong>title</strong> — Post title</li>
							<li><strong>content</strong> — Post content (HTML)</li>
							<li><strong>slug</strong> — URL slug (defaults from title)</li>
							<li><strong>thumbnail_url</strong> — Featured image URL</li>
							<li><strong>thumbnail_id</strong> — WordPress attachment ID for featured image</li>
							<li><strong>taxonomies</strong> — Object of taxonomy slug → array of term names/slugs</li>
							<li><strong>custom_fields</strong> — Post meta key/value pairs</li>
						</ul>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">POST /ps-api/create — Response</h4>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>{`{
  "success": true,
  "post_id": 789,
  "post_url": "https://...",
  "edit_url": "https://..."
}`}</code>
						</pre>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">POST /ps-api/progress — Request body</h4>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>{`{
  "task_id": 123,
  "execution_id": "optional-run-id",
  "status": "processing|completed|failed|cancelled",
  "progress": "Optional progress message",
  "error_message": "Required when status is failed"
}`}</code>
						</pre>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">POST /ps-api/upload — Request body</h4>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>{`{
  "task_id": 123,
  "image_base64": "base64-encoded-image-data",
  "index": 0,
  "filename": "image.png",
  "alt_text": "Description",
  "format": "webp"
}`}</code>
						</pre>
						<p className="text-sm text-gray-600 mt-1"><strong>task_id</strong> and <strong>image_base64</strong> are required.</p>
					</section>
				</div>
			</Modal>

			<WritingPresetModal
				isOpen={writingPresetModal.open}
				onClose={closeWritingPresetModal}
				mode={writingPresetModal.mode}
				writingPreset={writingPresetModal.writingPreset}
				onSaved={handleWritingPresetSaved}
			/>

			<ConfirmModal
				isOpen={Boolean(deleteWritingPreset)}
				onClose={() => setDeleteWritingPreset(null)}
				onConfirm={handleConfirmDeleteWritingPreset}
				title="Delete writing preset"
				message={deleteWritingPreset ? `Delete "${deleteWritingPreset.name}"? This cannot be undone.` : ''}
				confirmText="Delete"
				variant="danger"
				loading={deleting}
			/>
		</div>
	);
}
