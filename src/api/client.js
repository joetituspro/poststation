/**
 * API Client for PostStation
 * Wraps WordPress AJAX calls
 */

const getConfig = () => window.poststation || {};

export const getBootstrap = () => getConfig().bootstrap || {};

export const setBootstrap = (bootstrap = {}) => {
	if (!window.poststation) {
		window.poststation = {};
	}

	window.poststation.bootstrap = bootstrap;

	const mirrorKeys = [
		'post_types',
		'taxonomies',
		'languages',
		'countries',
		'users',
		'current_user_id',
		'settings',
		'webhooks',
		'campaigns',
		'writing_presets',
		'openrouter_models',
	];

	mirrorKeys.forEach((key) => {
		if (bootstrap[key] !== undefined) {
			window.poststation[key] = bootstrap[key];
		}
	});
};

/**
 * Make an AJAX request to WordPress
 * @param {string} action - The AJAX action name
 * @param {Object} data - Additional data to send
 * @returns {Promise<any>}
 */
export async function ajax(action, data = {}) {
	const config = getConfig();
	const formData = new FormData();
	
	formData.append('action', action);
	formData.append('nonce', config.nonce);
	
	Object.entries(data).forEach(([key, value]) => {
		if (value !== undefined && value !== null) {
			if (typeof value === 'object' && !(value instanceof File)) {
				formData.append(key, JSON.stringify(value));
			} else {
				formData.append(key, value);
			}
		}
	});
	
	const response = await fetch(config.ajax_url, {
		method: 'POST',
		body: formData,
		credentials: 'same-origin',
	});
	
	const result = await response.json();
	
	if (!result.success) {
		throw new Error(result.data?.message || 'Request failed');
	}
	
	return result.data;
}

const getPsApiBaseUrl = () => {
	const config = getConfig();
	const restUrl = config.rest_url || '';
	const baseUrl = restUrl ? restUrl.replace(/wp-json\/?$/, '') : '/';
	return baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
};

export async function psApi(endpoint, options = {}) {
	const url = `${getPsApiBaseUrl()}ps-api/${endpoint}`;
	const response = await fetch(url, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			...options.headers,
		},
		credentials: 'same-origin',
	});

	if (!response.ok) {
		throw new Error(`HTTP ${response.status}`);
	}

	return response.json();
}

export async function refreshBootstrap() {
	const data = await ajax('poststation_get_bootstrap');
	const bootstrap = data.bootstrap || data;
	setBootstrap(bootstrap);
	return bootstrap;
}

// Campaign API
export const campaigns = {
	getAll: () => ajax('poststation_get_campaigns'),
	getById: (id) => ajax('poststation_get_campaign', { id }),
	create: (title = 'New Campaign') => ajax('poststation_create_campaign', { title }),
	update: (id, data) => ajax('poststation_update_campaign', { id, ...data }),
	updateStatus: (id, status) => ajax('poststation_update_campaign_status', { id, status }),
	delete: (id) => ajax('poststation_delete_campaign', { id }),
	run: (id, taskId, webhookId) => ajax('poststation_run_campaign', { id, task_id: taskId, webhook_id: webhookId }),
	stopRun: (id) => ajax('poststation_stop_campaign_run', { id }),
	runRssNow: (id) => ajax('poststation_run_rss_now', { id }),
	rssAddToTasks: (id, items) => ajax('poststation_rss_add_to_tasks', { id, items }),
	export: (id) => ajax('poststation_export_campaign', { id }),
	import: (file) => {
		const formData = new FormData();
		formData.append('action', 'poststation_import_campaign');
		formData.append('nonce', getConfig().nonce);
		formData.append('file', file);
		return fetch(getConfig().ajax_url, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		}).then(r => r.json()).then(r => {
			if (!r.success) throw new Error(r.data?.message || 'Import failed');
			return r.data;
		});
	},
};

/**
 * Generate a task id under 8 digits (ms % 1e7 * 10 + random 0-9). Safe for JS and MySQL bigint unsigned.
 */
export function generateTaskId() {
	const n = (Date.now() % 1e7) * 10 + Math.floor(Math.random() * 10);
	return n > 0 ? n : 1;
}

// PostTask API
export const postTasks = {
	create: (campaignId, options = {}) =>
		ajax('poststation_create_posttask', { campaign_id: campaignId, ...options }),
	update: (campaignId, tasksData) => ajax('poststation_update_posttasks', { campaign_id: campaignId, tasks: tasksData }),
	delete: (id) => ajax('poststation_delete_posttask', { id }),
	clearCompleted: (campaignId) => ajax('poststation_clear_completed_posttasks', { campaign_id: campaignId }),
	import: (campaignId, file) => {
		const formData = new FormData();
		formData.append('action', 'poststation_import_posttasks');
		formData.append('nonce', getConfig().nonce);
		formData.append('campaign_id', campaignId);
		formData.append('file', file);
		return fetch(getConfig().ajax_url, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		}).then(r => r.json()).then(r => {
			if (!r.success) throw new Error(r.data?.message || 'Import failed');
			return r.data;
		});
	},
};

// Webhook API
export const webhooks = {
	getAll: () => ajax('poststation_get_webhooks'),
	getById: (id) => ajax('poststation_get_webhook', { id }),
	save: (data) => ajax('poststation_save_webhook', data),
	delete: (id) => ajax('poststation_delete_webhook', { id }),
};

// Writing presets API
export const writingPresets = {
	create: (data) =>
		ajax('poststation_create_writing_preset', {
			key: data.key,
			name: data.name,
			description: data.description ?? '',
			instructions: JSON.stringify(data.instructions ?? { title: '', body: '' }),
		}),
	update: (id, data) =>
		ajax('poststation_update_writing_preset', {
			id,
			description: data.description ?? '',
			instructions: JSON.stringify(data.instructions ?? { title: '', body: '' }),
		}),
	duplicate: (id, newKey, newName) =>
		ajax('poststation_duplicate_writing_preset', { id, new_key: newKey, new_name: newName }),
	reset: (id) => ajax('poststation_reset_writing_preset', { id }),
	delete: (id) => ajax('poststation_delete_writing_preset', { id }),
};

// Settings API
export const settings = {
	get: () => ajax('poststation_get_settings'),
	regenerateApiKey: () => ajax('poststation_regenerate_api_key'),
	saveWorkflowApiKey: (workflowApiKey) => ajax('poststation_save_workflow_api_key', { workflow_api_key: workflowApiKey }),
	saveSendApiToWebhook: (sendApiToWebhook) => ajax('poststation_save_send_api_to_webhook', { send_api_to_webhook: sendApiToWebhook ? '1' : '0' }),
	saveOpenRouterApiKey: (apiKey) => ajax('poststation_save_openrouter_api_key', { api_key: apiKey }),
	saveOpenRouterDefaults: (defaultTextModel, defaultImageModel) =>
		ajax('poststation_save_openrouter_defaults', {
			default_text_model: defaultTextModel,
			default_image_model: defaultImageModel,
		}),
	saveDevSettings: (enableTunnelUrl, tunnelUrl) =>
		ajax('poststation_save_dev_settings', {
			enable_tunnel_url: enableTunnelUrl ? '1' : '0',
			tunnel_url: tunnelUrl,
		}),
};

export const getPendingProcessingPostTasks = (campaignId, lastTaskCount = null) => {
	const params = new URLSearchParams({ campaign_id: campaignId, status: 'all' });
	if (lastTaskCount != null && lastTaskCount >= 0) {
		params.set('last_task_count', String(lastTaskCount));
	}
	return psApi(`posttasks?${params.toString()}`);
};

// Get config values
const getBootstrapValue = (key) => getBootstrap()[key] ?? getConfig()[key];

export const getPostTypes = () => getBootstrapValue('post_types') || {};
export const getTaxonomies = () => getBootstrapValue('taxonomies') || {};
export const getAdminUrl = () => getConfig().admin_url || '';
export const getLanguages = () => getBootstrapValue('languages') || {};
export const getCountries = () => getBootstrapValue('countries') || {};
export const getPluginName = () => getConfig().plugin_name || 'Post Station by Rankima';
export const getPluginSlug = () => getConfig().plugin_slug || 'poststation';
export const getPluginVersion = () => getConfig().plugin_version || '';
export const getPluginAppId = () => getConfig().plugin_app_id || `${getPluginSlug()}-app`;

export const getBootstrapSettings = () => getBootstrap().settings || null;
export const getBootstrapWebhooks = () => getBootstrap().webhooks || null;
export const getBootstrapCampaigns = () => getBootstrap().campaigns || null;
export const getBootstrapWritingPresets = () => getBootstrap().writing_presets || [];
export const getBootstrapOpenRouterModels = () => getBootstrap().openrouter_models || [];

export const openrouter = {
	getModels: ({ forceRefresh = false } = {}) =>
		ajax('poststation_get_openrouter_models', { force_refresh: forceRefresh ? '1' : '0' }),
};

export const ai = {
	generateWritingPreset: ({ prompt, provider = 'openrouter', model = '' }) =>
		ajax('poststation_generate_writing_preset', { prompt, provider, model }),
};
