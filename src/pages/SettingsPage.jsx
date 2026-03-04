import { useState, useCallback, useEffect } from 'react';
import { Button, Input, Modal, Card, CardHeader, CardBody, PageHeader, PageLoader, ModelSelect, useToast } from '../components/common';
import { settings, getBootstrapSettings, refreshBootstrap, getPluginName } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

export default function SettingsPage() {
	const pluginName = getPluginName();
	const { showToast } = useToast();
	const [savingSettings, setSavingSettings] = useState(false);
	const [regenerating, setRegenerating] = useState(false);
	const [showApiDocs, setShowApiDocs] = useState(false);
	const [apiKey, setApiKey] = useState('');
	const [showPoststationApiKey, setShowPoststationApiKey] = useState(false);
	const [sendApiToWebhook, setSendApiToWebhook] = useState(true);
	const [openRouterApiKey, setOpenRouterApiKey] = useState('');
	const [defaultTextModel, setDefaultTextModel] = useState('');
	const [defaultImageModel, setDefaultImageModel] = useState('');
	const [enableTunnelUrl, setEnableTunnelUrl] = useState(false);
	const [tunnelUrl, setTunnelUrl] = useState('');
	const [n8nBaseUrl, setN8nBaseUrl] = useState('');
	const [n8nApiKey, setN8nApiKey] = useState('');
	const [n8nWorkflowId, setN8nWorkflowId] = useState('');
	const [rapidApiKey, setRapidApiKey] = useState('');
	const [firecrawlKey, setFirecrawlKey] = useState('');
	const [openRouterConnKey, setOpenRouterConnKey] = useState('');
	const [showN8nCredentials, setShowN8nCredentials] = useState(false);
	const [showDeployConfirm, setShowDeployConfirm] = useState(false);
	const [deployCreateOrUpdateWebhook, setDeployCreateOrUpdateWebhook] = useState(true);
	const [deployCreateOrUpdateCredentials, setDeployCreateOrUpdateCredentials] = useState(true);
	const [deployError, setDeployError] = useState('');
	const [copied, setCopied] = useState(false);

	const bootstrapSettings = getBootstrapSettings();
	const fetchSettings = useCallback(() => settings.get(), []);
	const { data, loading, refetch } = useQuery(fetchSettings, [], { initialData: bootstrapSettings });
	const { mutate: saveSettings } = useMutation(settings.save, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: deployN8nBlueprint, loading: deployingN8nBlueprint } = useMutation(settings.deployN8nBlueprint, {
		onSuccess: refreshBootstrap,
	});

	useEffect(() => {
		if (data?.api_key) {
			setApiKey(data.api_key);
		}
		setSendApiToWebhook(data?.send_api_to_webhook !== false);
		setDefaultTextModel(data?.openrouter_default_text_model || '');
		setDefaultImageModel(data?.openrouter_default_image_model || '');
		setEnableTunnelUrl(Boolean(data?.enable_tunnel_url));
		setTunnelUrl(data?.tunnel_url || '');
	}, [data]);

	useEffect(() => {
		setN8nBaseUrl(data?.n8n_base_url || '');
		setN8nWorkflowId(data?.n8n_workflow_id || '');
	}, [data?.n8n_base_url, data?.n8n_workflow_id]);

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
			setRegenerating(true);
			const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
			const randomBytes = new Uint32Array(32);
			window.crypto.getRandomValues(randomBytes);
			const generatedKey = Array.from(randomBytes, (value) => charset[value % charset.length]).join('');
			setApiKey(generatedKey);
		} catch (err) {
			console.error('Failed to regenerate API key locally:', err);
		} finally {
			setRegenerating(false);
		}
	};

	const hasUnsavedApiKeyChange = (apiKey || '') !== (data?.api_key || '');

	const hasUnsavedSendApiChange = sendApiToWebhook !== (data?.send_api_to_webhook !== false);
	const hasUnsavedOpenRouterChanges =
		(openRouterApiKey || '').trim() !== '' ||
		(defaultTextModel || '') !== (data?.openrouter_default_text_model || '') ||
		(defaultImageModel || '') !== (data?.openrouter_default_image_model || '');
	const hasUnsavedDevChanges =
		Boolean(data?.is_local) &&
		(enableTunnelUrl !== Boolean(data?.enable_tunnel_url) ||
			(tunnelUrl || '').trim() !== (data?.tunnel_url || '').trim());

	const hasUnsavedN8nChanges =
		(n8nBaseUrl || '').trim() !== (data?.n8n_base_url || '').trim() ||
		(n8nWorkflowId || '').trim() !== (data?.n8n_workflow_id || '').trim() ||
		(n8nApiKey || '').trim() !== '' ||
		(rapidApiKey || '').trim() !== '' ||
		(firecrawlKey || '').trim() !== '' ||
		(openRouterConnKey || '').trim() !== '';

	const hasUnsavedChanges =
		hasUnsavedApiKeyChange ||
		hasUnsavedSendApiChange ||
		hasUnsavedOpenRouterChanges ||
		hasUnsavedDevChanges ||
		hasUnsavedN8nChanges;

	const handleSaveSettings = async (e) => {
		e?.preventDefault?.();

		const base = (n8nBaseUrl || '').trim();
		const key = (n8nApiKey || '').trim();
		const hasStoredKey = Boolean(data?.n8n_api_key_set);
		if (hasUnsavedN8nChanges && (base === '' || (key === '' && !hasStoredKey))) {
			showToast('n8n Base URL and n8n API Key are required.', 'error');
			return;
		}

		setSavingSettings(true);
		try {
			await saveSettings({
				api_key: apiKey,
				send_api_to_webhook: sendApiToWebhook ? '1' : '0',
				default_text_model: defaultTextModel,
				default_image_model: defaultImageModel,
				openrouter_api_key: (openRouterApiKey || '').trim() !== '' ? openRouterApiKey : undefined,
				enable_tunnel_url: enableTunnelUrl ? '1' : '0',
				tunnel_url: tunnelUrl,
				base_url: base,
				workflow_id: n8nWorkflowId,
				n8n_api_key: n8nApiKey,
				rapidapi_key: rapidApiKey,
				firecrawl_key: firecrawlKey,
				openrouter_key: openRouterConnKey,
			});

			setOpenRouterApiKey('');
			setN8nApiKey('');
			setRapidApiKey('');
			setFirecrawlKey('');
			setOpenRouterConnKey('');
			showToast('Settings saved.', 'success');
			await refreshBootstrap();
			await refetch({ background: true });
		} catch (err) {
			console.error('Failed to save settings:', err);
			showToast(err?.message || 'Failed to save settings.', 'error');
			await refreshBootstrap();
			await refetch({ background: true });
		} finally {
			setSavingSettings(false);
		}
	};

	const canDeployN8n =
		!hasUnsavedN8nChanges &&
		(data?.n8n_base_url || '').trim() !== '' &&
		Boolean(data?.n8n_api_key_set) &&
		!savingSettings &&
		!deployingN8nBlueprint;

	const handleDeployN8nBlueprint = async (options = {}) => {
		if (!canDeployN8n) {
			showToast('Save n8n settings first. Deploy is only available when there are no unsaved changes.', 'error');
			return;
		}

		if (options?.create_or_update_credentials) {
			const missing = [];
			if (!data?.rapidapi_key_set) missing.push('RapidAPI Key');
			if (!data?.firecrawl_key_set) missing.push('Firecrawl Key');
			if (!data?.n8n_openrouter_key_set) missing.push('OpenRouter Key');
			if (missing.length > 0) {
				setDeployError(`Create/Update credentials is enabled, but required credentials are missing: ${missing.join(', ')}. Save these in n8n Connection credentials first.`);
				return;
			}
		}

		try {
			setDeployError('');
			await deployN8nBlueprint(options);
			await refreshBootstrap();
			await refetch();
			setShowDeployConfirm(false);
			showToast('n8n workflow deployed successfully.', 'success');
		} catch (err) {
			console.error('Failed to deploy n8n workflow:', err);
			setDeployError(err?.message || 'Failed to deploy n8n workflow.');
		}
	};

	const handleRequestDeployN8nBlueprint = () => {
		if (!canDeployN8n) {
			showToast('Save n8n settings first. Deploy is only available when there are no unsaved changes.', 'error');
			return;
		}
		setDeployCreateOrUpdateWebhook(true);
		setDeployCreateOrUpdateCredentials(true);
		setDeployError('');
		setShowDeployConfirm(true);
	};

	const savedWorkflowId = (data?.n8n_workflow_id || '').trim();

	if (loading) return <PageLoader />;

	return (
		<div>
			<form onSubmit={handleSaveSettings}>
				<div className="poststation-sticky-header sticky top-8  bg-gray-50">
					<PageHeader
						title="Settings"
						description={ `Manage your ${ pluginName } configuration` }
						actions={(
							<Button
								type="submit"
								loading={savingSettings}
								disabled={!hasUnsavedChanges || deployingN8nBlueprint}
							>
								Save Settings
							</Button>
						)}
					/>
				</div>

				<div className="max-w-5xl grid grid-cols-2 gap-6">
				{/* API Key Card */}
				<Card>
					<CardHeader>
						<div className="flex items-center justify-between">
							<div>
								<h3 className="text-lg font-medium text-gray-900">Poststation API Key</h3>
								<p className="text-sm text-gray-500">Use this key to authenticate Poststation API requests.</p>
							</div>
							<Button variant="secondary" onClick={() => setShowApiDocs(true)}>
								View API Docs
							</Button>
						</div>
					</CardHeader>
					<CardBody>
						<div className="space-y-4">
							<div className="flex gap-2 items-end">
								<div className="flex-1">
									<div className="flex items-center justify-between mb-1">
										<label className="flex items-center text-sm font-medium text-gray-700">
											Poststation API Key
										</label>
										<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-xs text-gray-600">
											<input
												type="checkbox"
												className="poststation-field-checkbox"
												checked={sendApiToWebhook}
												disabled={savingSettings}
												onChange={(e) => setSendApiToWebhook(e.target.checked)}
											/>
											<span className="poststation-switch-track" aria-hidden />
											<span>Send to Webhook</span>
										</label>
									</div>
									<p className="text-xs text-gray-500 mb-2">
										Used to authenticate requests to the {pluginName} API. When "Send to Webhook" is enabled, this key is included in webhook payload data.
									</p>
									<div className="relative">
										<input
											className="poststation-field pr-10"
											type={showPoststationApiKey ? 'text' : 'password'}
											value={apiKey || data?.api_key || ''}
											readOnly
										/>
										<button
											type="button"
											className="absolute right-2 top-1/2 -translate-y-1/2 poststation-icon-btn"
											onClick={() => setShowPoststationApiKey((prev) => !prev)}
											title={showPoststationApiKey ? 'Hide key' : 'Show key'}
											aria-label={showPoststationApiKey ? 'Hide key' : 'Show key'}
										>
											{showPoststationApiKey ? (
												<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
													<path strokeLinecap="round" strokeLinejoin="round" d="M3 3l18 18M10.477 10.48a3 3 0 004.243 4.242M9.88 5.09A10.958 10.958 0 0112 4.909c5.523 0 10 4.477 10 10 0 1.232-.223 2.41-.632 3.498M6.228 6.228A9.965 9.965 0 002 14.91c0 .84.103 1.656.297 2.435" />
												</svg>
											) : (
												<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
													<path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
													<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5s8.268 2.943 9.542 7c-1.274 4.057-5.065 7-9.542 7s-8.268-2.943-9.542-7z" />
												</svg>
											)}
										</button>
									</div>
								</div>
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
						<div className="flex items-center justify-between gap-3">
							<div>
								<h3 className="text-lg font-medium text-gray-900">n8n Connection</h3>
								<p className="text-sm text-gray-500">Configure n8n connection and optional credential keys</p>
							</div>
							<Button
								variant="secondary"
								onClick={handleRequestDeployN8nBlueprint}
								disabled={!canDeployN8n}
								loading={deployingN8nBlueprint}
								title={!canDeployN8n ? 'Save all n8n changes before deploying.' : 'Deploy workflow'}
							>
								Deploy
							</Button>
						</div>
					</CardHeader>
					<CardBody>
						<div className="space-y-3">
							<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
								<Input
									label="n8n Base URL"
									value={n8nBaseUrl}
									onChange={(e) => setN8nBaseUrl(e.target.value)}
									placeholder="https://your-n8n.example.com"
									required
								/>
								<Input
									label="n8n API Key"
									type="password"
									value={n8nApiKey}
									onChange={(e) => setN8nApiKey(e.target.value)}
									placeholder={data?.n8n_api_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter n8n API key'}
									required={!data?.n8n_api_key_set}
								/>
								<Input
									label="Workflow ID"
									value={n8nWorkflowId}
									onChange={(e) => setN8nWorkflowId(e.target.value)}
									placeholder="Optional: existing workflow id"
								/>
							</div>
							<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700">
								<input
									type="checkbox"
									className="poststation-field-checkbox"
									checked={showN8nCredentials}
									onChange={(e) => setShowN8nCredentials(e.target.checked)}
								/>
								<span className="poststation-switch-track" aria-hidden />
								<span>Credentials?</span>
							</label>
							{showN8nCredentials && (
								<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
									<Input label="RapidAPI Key" type="password" value={rapidApiKey} onChange={(e) => setRapidApiKey(e.target.value)} placeholder={data?.rapidapi_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter RapidAPI key'} />
									<Input label="Firecrawl Key" type="password" value={firecrawlKey} onChange={(e) => setFirecrawlKey(e.target.value)} placeholder={data?.firecrawl_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter Firecrawl key'} />
									<Input label="OpenRouter Key" type="password" value={openRouterConnKey} onChange={(e) => setOpenRouterConnKey(e.target.value)} placeholder={data?.n8n_openrouter_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter OpenRouter key'} />
								</div>
							)}
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
								placeholder={data?.openrouter_api_key_set ? 'Saved (hidden). Enter new key to replace.' : 'Enter OpenRouter API key'}
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
						</div>
					</CardBody>
				</Card>
				{Boolean(data?.is_local) && (
					<Card>
						<CardHeader>
							<div>
								<h3 className="text-lg font-medium text-gray-900">Dev</h3>
								<p className="text-sm text-gray-500">Local development overrides</p>
							</div>
						</CardHeader>
						<CardBody>
							<div className="space-y-4">
								<label className="flex items-center gap-2 text-sm text-gray-800">
									<input
										type="checkbox"
										checked={enableTunnelUrl}
										onChange={(e) => setEnableTunnelUrl(e.target.checked)}
									/>
									Enable tunnel URL
								</label>

								{enableTunnelUrl && (
									<Input
										label="Tunnel URL"
										type="url"
										value={tunnelUrl}
										onChange={(e) => setTunnelUrl(e.target.value)}
										placeholder="https://your-subdomain.ngrok-free.app"
									/>
								)}

							</div>
						</CardBody>
					</Card>
				)}

				</div>
			</form>

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

			<Modal
				isOpen={showDeployConfirm}
				onClose={() => {
					if (deployingN8nBlueprint) return;
					setShowDeployConfirm(false);
					setDeployError('');
				}}
				title="Deploy n8n Workflow"
				size="md"
			>
				<div className="space-y-4">
					<div className="text-sm text-gray-600">
						Deployment will push the latest RANKIMA workflow blueprint to your connected n8n instance and then attempt activation.
					</div>
					<div className="space-y-2">
					<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700 mt-1">
						<input
							type="checkbox"
							className="poststation-field-checkbox"
							checked={deployCreateOrUpdateWebhook}
							onChange={(e) => setDeployCreateOrUpdateWebhook(e.target.checked)}
							disabled={deployingN8nBlueprint}
						/>
						<span className="poststation-switch-track" aria-hidden />
						<span>Create/Update Webhook After Deployment</span>
					</label>
					<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700">
						<input
							type="checkbox"
							className="poststation-field-checkbox"
							checked={deployCreateOrUpdateCredentials}
							onChange={(e) => setDeployCreateOrUpdateCredentials(e.target.checked)}
							disabled={deployingN8nBlueprint}
						/>
						<span className="poststation-switch-track" aria-hidden />
						<span>Create/Update n8n workflow credentials</span>
					</label>
					</div>

					{savedWorkflowId !== '' ? (
						<p className="text-sm text-gray-600 leading-6 pt-1">
							Clicking <strong>Proceed to Deploy</strong> will attempt to update your n8n workflow with ID <code>{savedWorkflowId}</code> to the latest version.
						</p>
					) : (
						<p className="text-sm text-gray-600 leading-6 pt-1">
							This will create a new latest RANKIMA workflow in your n8n instance with webhook path <code>rankima</code>. Ensure no existing workflow is already using that webhook path, or the new workflow may not activate.
						</p>
					)}
					{deployError && (
						<div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 mt-1">
							{deployError}
						</div>
					)}

					<div className="flex justify-end gap-3 pt-3">
						<Button
							variant="secondary"
							onClick={() => {
								setShowDeployConfirm(false);
								setDeployError('');
							}}
							disabled={deployingN8nBlueprint}
						>
							Cancel
						</Button>
						<Button
							onClick={() => handleDeployN8nBlueprint({
								create_or_update_webhook: deployCreateOrUpdateWebhook,
								create_or_update_credentials: deployCreateOrUpdateCredentials,
							})}
							loading={deployingN8nBlueprint}
						>
							Proceed to Deploy
						</Button>
					</div>
				</div>
			</Modal>

		</div>
	);
}
