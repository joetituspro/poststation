/**
 * API Client for PostStation
 * Wraps WordPress AJAX calls
 */

const getConfig = () => window.poststation || {};

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

/**
 * Make a REST API request
 * @param {string} endpoint - The REST endpoint (relative to rest_url)
 * @param {Object} options - Fetch options
 * @returns {Promise<any>}
 */
export async function rest(endpoint, options = {}) {
	const config = getConfig();
	const url = `${config.rest_url}${endpoint}`;
	
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

// PostWork API
export const postworks = {
	getAll: () => ajax('poststation_get_postworks'),
	getById: (id) => ajax('poststation_get_postwork', { id }),
	create: (title = 'New Post Work') => ajax('poststation_create_postwork', { title }),
	update: (id, data) => ajax('poststation_update_postwork', { id, ...data }),
	delete: (id) => ajax('poststation_delete_postwork', { id }),
	run: (id, blockId, webhookId) => ajax('poststation_run_postwork', { id, block_id: blockId, webhook_id: webhookId }),
	stopRun: (id) => ajax('poststation_stop_postwork_run', { id }),
	export: (id) => ajax('poststation_export_postwork', { id }),
	import: (file) => {
		const formData = new FormData();
		formData.append('action', 'poststation_import_postwork');
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

// PostBlock API
export const blocks = {
	create: (postworkId) => ajax('poststation_create_postblock', { postwork_id: postworkId }),
	update: (postworkId, blocksData) => ajax('poststation_update_blocks', { postwork_id: postworkId, blocks: blocksData }),
	delete: (id) => ajax('poststation_delete_postblock', { id }),
	clearCompleted: (postworkId) => ajax('poststation_clear_completed_blocks', { postwork_id: postworkId }),
	import: (postworkId, file) => {
		const formData = new FormData();
		formData.append('action', 'poststation_import_blocks');
		formData.append('nonce', getConfig().nonce);
		formData.append('postwork_id', postworkId);
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
};

export const getPendingProcessingBlocks = (postworkId) =>
	psApi(`blocks?postwork_id=${postworkId}&status=all`);

// Get config values
export const getPostTypes = () => getConfig().post_types || {};
export const getTaxonomies = () => getConfig().taxonomies || {};
export const getAdminUrl = () => getConfig().admin_url || '';
export const getLanguages = () => getConfig().languages || {};
export const getCountries = () => getConfig().countries || {};
