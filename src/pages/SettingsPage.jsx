import { useState, useCallback, useEffect } from 'react';
import { Button, Input, Modal, Card, CardHeader, CardBody, PageHeader, PageLoader, ModelSelect } from '../components/common';
import { settings, getBootstrapSettings, refreshBootstrap } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

export default function SettingsPage() {
	const [showApiDocs, setShowApiDocs] = useState(false);
	const [apiKey, setApiKey] = useState('');
	const [openRouterApiKey, setOpenRouterApiKey] = useState('');
	const [defaultTextModel, setDefaultTextModel] = useState('');
	const [defaultImageModel, setDefaultImageModel] = useState('');
	const [copied, setCopied] = useState(false);

	const bootstrapSettings = getBootstrapSettings();
	const fetchSettings = useCallback(() => settings.get(), []);
	const { data, loading, error, refetch } = useQuery(fetchSettings, [], { initialData: bootstrapSettings });
	const { mutate: saveApiKey, loading: saving } = useMutation(settings.saveApiKey, {
		onSuccess: refreshBootstrap,
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

	const handleSave = async () => {
		try {
			await saveApiKey(apiKey);
			refetch();
		} catch (err) {
			console.error('Failed to save API key:', err);
			refetch();
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

	if (loading) return <PageLoader />;

	return (
		<div>
			<PageHeader
				title="Settings"
				description="Manage your PostStation configuration"
			/>

			<div className="max-w-2xl space-y-6">
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
							<div className="flex gap-2">
								<Input
									label="API Key"
									tooltip="Used to authenticate requests to the PostStation API."
									type="text"
									value={apiKey || data?.api_key || ''}
									onChange={(e) => setApiKey(e.target.value)}
									placeholder="Enter API key"
									className="flex-1"
								/>
								<Button variant="secondary" onClick={handleCopy}>
									{copied ? 'Copied!' : 'Copy'}
								</Button>
							</div>
							<div className="flex justify-end">
								<Button onClick={handleSave} loading={saving}>
									Save API Key
								</Button>
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

				{/* General Info Card */}
				<Card>
					<CardHeader>
						<h3 className="text-lg font-medium text-gray-900">About PostStation</h3>
					</CardHeader>
					<CardBody>
						<p className="text-sm text-gray-600">
							PostStation is a WordPress plugin that enables automated post creation through webhooks and API endpoints. 
							Configure Campaigns to define post templates, then trigger content generation via webhooks.
						</p>
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
						<h4 className="font-medium text-gray-900 mb-2">Endpoints</h4>
						<div className="space-y-3">
							<div className="p-3 bg-gray-50 rounded-lg">
								<code className="text-sm font-mono text-indigo-600">POST /wp-json/poststation/v1/create</code>
								<p className="text-sm text-gray-600 mt-1">REST API endpoint for creating posts</p>
							</div>
							<div className="p-3 bg-gray-50 rounded-lg">
								<code className="text-sm font-mono text-indigo-600">POST /ps-api/create</code>
								<p className="text-sm text-gray-600 mt-1">Custom API endpoint (alternative)</p>
							</div>
						</div>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">Authentication</h4>
						<p className="text-sm text-gray-600 mb-2">
							Include your API key in the request header:
						</p>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>X-API-Key: your-api-key</code>
						</pre>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">Request Body</h4>
						<pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-sm overflow-x-auto">
							<code>{`{
  "title": "Post Title",
  "content": "Post content...",
  "slug": "post-slug",
  "thumbnail_url": "https://...",
  "taxonomies": {
    "category": ["news"],
    "post_tag": ["featured"]
  },
  "custom_fields": {
    "meta_key": "meta_value"
  },
  "task_id": 123
}`}</code>
						</pre>
					</section>

					<section>
						<h4 className="font-medium text-gray-900 mb-2">Fields</h4>
						<ul className="text-sm text-gray-600 space-y-1">
							<li><strong>title</strong> (required) - Post title</li>
							<li><strong>content</strong> (required) - Post content (HTML)</li>
							<li><strong>slug</strong> - URL slug</li>
							<li><strong>thumbnail_url</strong> - Featured image URL</li>
							<li><strong>taxonomies</strong> - Object with taxonomy terms</li>
							<li><strong>custom_fields</strong> - Custom meta fields</li>
							<li><strong>task_id</strong> - Associated Post Task ID</li>
						</ul>
					</section>
				</div>
			</Modal>
		</div>
	);
}
