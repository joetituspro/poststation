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
	delete: (id) => ajax('poststation_delete_campaign', { id }),
	run: (id, taskId, webhookId) => ajax('poststation_run_campaign', { id, task_id: taskId, webhook_id: webhookId }),
	stopRun: (id) => ajax('poststation_stop_campaign_run', { id }),
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

// PostTask API
export const postTasks = {
	create: (campaignId) => ajax('poststation_create_posttask', { campaign_id: campaignId }),
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

// Settings API
export const settings = {
	get: () => ajax('poststation_get_settings'),
	saveApiKey: (apiKey) => ajax('poststation_save_api_key', { api_key: apiKey }),
	saveOpenRouterApiKey: (apiKey) => ajax('poststation_save_openrouter_api_key', { api_key: apiKey }),
	saveOpenRouterDefaults: (defaultTextModel, defaultImageModel) =>
		ajax('poststation_save_openrouter_defaults', {
			default_text_model: defaultTextModel,
			default_image_model: defaultImageModel,
		}),
};

export const getPendingProcessingPostTasks = (campaignId) =>
	psApi(`posttasks?campaign_id=${campaignId}&status=all`);

// Get config values
const getBootstrapValue = (key) => getBootstrap()[key] ?? getConfig()[key];

export const getPostTypes = () => getBootstrapValue('post_types') || {};
export const getTaxonomies = () => getBootstrapValue('taxonomies') || {};
export const getAdminUrl = () => getConfig().admin_url || '';
export const getLanguages = () => getBootstrapValue('languages') || {};
export const getCountries = () => getBootstrapValue('countries') || {};

export const getBootstrapSettings = () => getBootstrap().settings || null;
export const getBootstrapWebhooks = () => getBootstrap().webhooks || null;
export const getBootstrapCampaigns = () => getBootstrap().campaigns || null;
export const getBootstrapOpenRouterModels = () => getBootstrap().openrouter_models || [];

export const openrouter = {
	getModels: ({ forceRefresh = false } = {}) =>
		ajax('poststation_get_openrouter_models', { force_refresh: forceRefresh ? '1' : '0' }),
};
