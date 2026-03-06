import { useState, useCallback, useEffect } from 'react';
import { Button, Input, Card, CardHeader, CardBody, PageHeader, PageLoader, ModelSelect, Select, useToast } from '../components/common';
import { settings, getBootstrapSettings, refreshBootstrap, getPluginName } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

const SCRAPER_PROVIDER_OPTIONS = [
	{ value: 'rankima', label: 'Rankima Article Extractor (Recommended)' },
	{ value: 'firecrawl', label: 'Firecrawl' },
	{ value: 'rapidapi', label: 'RapidAPI' },
];

export default function SettingsPage() {
	const pluginName = getPluginName();
	const { showToast } = useToast();
	const [savingSettings, setSavingSettings] = useState(false);
	const [openRouterApiKey, setOpenRouterApiKey] = useState('');
	const [defaultTextModel, setDefaultTextModel] = useState('');
	const [defaultImageModel, setDefaultImageModel] = useState('');
	const [articleScraperProvider, setArticleScraperProvider] = useState('rankima');
	const [rankimaExtractorApiKey, setRankimaExtractorApiKey] = useState('');
	const [firecrawlApiUrl, setFirecrawlApiUrl] = useState('https://api.firecrawl.dev/v2/scrape');
	const [firecrawlApiKey, setFirecrawlApiKey] = useState('');
	const [rapidapiApiUrl, setRapidapiApiUrl] = useState('https://article-extractor2.p.rapidapi.com/article/parse');
	const [rapidapiApiKey, setRapidapiApiKey] = useState('');
	const [cleanDataWithAi, setCleanDataWithAi] = useState(true);
	const [cleanDataModelId, setCleanDataModelId] = useState('google/gemini-2.5-flash-lite');
	const [enableTunnelUrl, setEnableTunnelUrl] = useState(false);
	const [tunnelUrl, setTunnelUrl] = useState('');

	const bootstrapSettings = getBootstrapSettings();
	const fetchSettings = useCallback(() => settings.get(), []);
	const { data, loading, refetch } = useQuery(fetchSettings, [], { initialData: bootstrapSettings });
	const { mutate: saveSettings } = useMutation(settings.save, {
		onSuccess: refreshBootstrap,
	});

	useEffect(() => {
		if (!data) return;
		setDefaultTextModel(data.openrouter_default_text_model || '');
		setDefaultImageModel(data.openrouter_default_image_model || '');
		setArticleScraperProvider(data.article_scraper_provider || 'rankima');
		setFirecrawlApiUrl(data.firecrawl_api_url || 'https://api.firecrawl.dev/v2/scrape');
		setRapidapiApiUrl(data.rapidapi_api_url || 'https://article-extractor2.p.rapidapi.com/article/parse');
		setCleanDataWithAi(data.clean_data_with_ai !== false);
		setCleanDataModelId(data.clean_data_model_id || 'google/gemini-2.5-flash-lite');
		setEnableTunnelUrl(Boolean(data.enable_tunnel_url));
		setTunnelUrl(data.tunnel_url || '');
	}, [data]);

	const hasUnsavedChanges =
		(openRouterApiKey || '').trim() !== '' ||
		(defaultTextModel || '') !== (data?.openrouter_default_text_model || '') ||
		(defaultImageModel || '') !== (data?.openrouter_default_image_model || '') ||
		(articleScraperProvider || 'rankima') !== (data?.article_scraper_provider || 'rankima') ||
		(rankimaExtractorApiKey || '').trim() !== '' ||
		(firecrawlApiUrl || '').trim() !== (data?.firecrawl_api_url || '').trim() ||
		(firecrawlApiKey || '').trim() !== '' ||
		(rapidapiApiUrl || '').trim() !== (data?.rapidapi_api_url || '').trim() ||
		(rapidapiApiKey || '').trim() !== '' ||
		Boolean(cleanDataWithAi) !== Boolean(data?.clean_data_with_ai !== false) ||
		(cleanDataModelId || '') !== (data?.clean_data_model_id || 'google/gemini-2.5-flash-lite') ||
		(Boolean(enableTunnelUrl) !== Boolean(data?.enable_tunnel_url) ||
			(tunnelUrl || '').trim() !== (data?.tunnel_url || '').trim());

	const handleSaveSettings = async (e) => {
		e?.preventDefault?.();
		setSavingSettings(true);
		try {
			await saveSettings({
				default_text_model: defaultTextModel,
				default_image_model: defaultImageModel,
				openrouter_api_key: (openRouterApiKey || '').trim() !== '' ? openRouterApiKey : undefined,
				article_scraper_provider: articleScraperProvider,
				rankima_extractor_api_key: rankimaExtractorApiKey,
				firecrawl_api_url: firecrawlApiUrl,
				firecrawl_api_key: firecrawlApiKey,
				rapidapi_api_url: rapidapiApiUrl,
				rapidapi_api_key: rapidapiApiKey,
				clean_data_with_ai: cleanDataWithAi ? '1' : '0',
				clean_data_model_id: cleanDataModelId || 'google/gemini-2.5-flash-lite',
				enable_tunnel_url: enableTunnelUrl ? '1' : '0',
				tunnel_url: tunnelUrl,
			});

			setOpenRouterApiKey('');
			setRankimaExtractorApiKey('');
			setFirecrawlApiKey('');
			setRapidapiApiKey('');
			showToast('Settings saved.', 'success');
			await refreshBootstrap();
			await refetch({ background: true });
		} catch (err) {
			console.error('Failed to save settings:', err);
			showToast(err?.message || 'Failed to save settings.', 'error');
		} finally {
			setSavingSettings(false);
		}
	};

	if (loading) return <PageLoader />;

	return (
		<div>
			<form onSubmit={handleSaveSettings}>
				<div className="poststation-sticky-header sticky top-8 bg-gray-50">
					<PageHeader
						title="Settings"
						description={`Manage your ${pluginName} configuration`}
						actions={(
							<Button type="submit" loading={savingSettings} disabled={!hasUnsavedChanges}>
								Save Settings
							</Button>
						)}
					/>
				</div>

				<div className="max-w-5xl grid grid-cols-2 gap-6">
					<Card>
						<CardHeader>
							<div>
								<h3 className="text-lg font-medium text-gray-900">OpenRouter</h3>
								<p className="text-sm text-gray-500">Store API key and defaults.</p>
							</div>
						</CardHeader>
						<CardBody>
							<div className="space-y-4">
								<Input
									label="OpenRouter API Key"
									type="password"
									value={openRouterApiKey}
									onChange={(e) => setOpenRouterApiKey(e.target.value)}
									placeholder={data?.openrouter_api_key_set ? 'Saved (hidden). Enter new key to replace.' : 'Enter OpenRouter API key'}
								/>
								<ModelSelect label="Default Text Model" value={defaultTextModel} onChange={(e) => setDefaultTextModel(e.target.value)} filter="text" />
								<ModelSelect label="Default Image Model" value={defaultImageModel} onChange={(e) => setDefaultImageModel(e.target.value)} filter="image" />
							</div>
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<div>
								<h3 className="text-lg font-medium text-gray-900">Article Scraper</h3>
								<p className="text-sm text-gray-500">Choose provider and enter API keys.</p>
							</div>
						</CardHeader>
						<CardBody>
							<div className="space-y-4">
								<Select
									label="Provider"
									options={SCRAPER_PROVIDER_OPTIONS}
									value={articleScraperProvider}
									onChange={(e) => setArticleScraperProvider(e.target.value)}
								/>

								{articleScraperProvider === 'rankima' && (
									<Input
										label="Rankima Extractor API Key"
										type="password"
										value={rankimaExtractorApiKey}
										onChange={(e) => setRankimaExtractorApiKey(e.target.value)}
										placeholder={data?.rankima_extractor_api_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter Rankima API key'}
									/>
								)}
								{articleScraperProvider === 'firecrawl' && (
									<>
										<Input label="Firecrawl API URL" value={firecrawlApiUrl} onChange={(e) => setFirecrawlApiUrl(e.target.value)} />
										<Input
											label="Firecrawl API Key"
											type="password"
											value={firecrawlApiKey}
											onChange={(e) => setFirecrawlApiKey(e.target.value)}
											placeholder={data?.firecrawl_api_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter Firecrawl API key'}
										/>
									</>
								)}
								{articleScraperProvider === 'rapidapi' && (
									<>
										<Input label="RapidAPI URL" value={rapidapiApiUrl} onChange={(e) => setRapidapiApiUrl(e.target.value)} />
										<Input
											label="RapidAPI Key"
											type="password"
											value={rapidapiApiKey}
											onChange={(e) => setRapidapiApiKey(e.target.value)}
											placeholder={data?.rapidapi_api_key_set ? 'Saved (hidden). Enter to replace.' : 'Enter RapidAPI key'}
										/>
									</>
								)}

								{articleScraperProvider === 'rankima' ? (
									<p className="text-sm text-gray-500">
										Rankima extractor already returns cleaned data. AI cleanup is skipped.
									</p>
								) : (
									<>
										<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700">
											<input
												type="checkbox"
												className="poststation-field-checkbox"
												checked={cleanDataWithAi}
												onChange={(e) => setCleanDataWithAi(e.target.checked)}
											/>
											<span className="poststation-switch-track" aria-hidden />
											<span>Clean Data with AI</span>
										</label>
										{cleanDataWithAi && (
											<ModelSelect
												label="Clean Data Model"
												value={cleanDataModelId}
												onChange={(e) => setCleanDataModelId(e.target.value)}
												filter="text"
											/>
										)}
									</>
								)}
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
										<input type="checkbox" checked={enableTunnelUrl} onChange={(e) => setEnableTunnelUrl(e.target.checked)} />
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

		</div>
	);
}
