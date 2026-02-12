import { useEffect, useMemo, useState } from 'react';
import Tooltip from './Tooltip';
import { getBootstrapOpenRouterModels, openrouter } from '../../api/client';

// Add or remove IDs here when you want to update the popular model group.
const POPULAR_MODELS = [
	'anthropic/claude-sonnet-4.5',
	'openai/gpt-5.2',
	'x-ai/grok-4.1-fast',
	'moonshotai/kimi-k2.5',
	'google/gemini-3-flash-preview',
	'minimax/minimax-m2.1',
	'deepseek/deepseek-v3.2',
	'google/gemini-3-pro-preview',
];

const TOP_MODEL_PRIORITY = [
	...POPULAR_MODELS,
	'anthropic/claude-opus-4.5',
	'openai/gpt-5',
	'google/gemini-2.5-pro',
	'meta-llama/llama-4-maverick',
	'deepseek/deepseek-chat-v3.1',
	'openai/gpt-image-1',
	'google/imagen-4',
	'black-forest-labs/flux-1.1-pro',
	'black-forest-labs/flux-1-schnell',
];

const sortModels = (models = []) => {
	const priorityIndex = new Map(TOP_MODEL_PRIORITY.map((id, index) => [id, index]));

	return [...models].sort((a, b) => {
		const aRank = priorityIndex.has(a.id) ? priorityIndex.get(a.id) : Number.POSITIVE_INFINITY;
		const bRank = priorityIndex.has(b.id) ? priorityIndex.get(b.id) : Number.POSITIVE_INFINITY;

		if (aRank !== bRank) return aRank - bRank;
		return (a.name || a.id || '').localeCompare(b.name || b.id || '');
	});
};

export default function ModelSelect({
	label = 'Model',
	tooltip = 'Choose OpenRouter model used for generation.',
	value = '',
	onChange,
	filter = 'all', // all | text | image
	placeholder = 'Select OpenRouter model...',
	className = '',
	disabled = false,
}) {
	const [models, setModels] = useState(() => getBootstrapOpenRouterModels());
	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState('');

	const loadModels = async (forceRefresh = false) => {
		setIsLoading(true);
		setError('');
		try {
			const response = await openrouter.getModels({ forceRefresh });
			setModels(Array.isArray(response.models) ? response.models : []);
		} catch (err) {
			setError(err?.message || 'Failed to fetch OpenRouter models.');
		} finally {
			setIsLoading(false);
		}
	};

	useEffect(() => {
		if (Array.isArray(models) && models.length > 0) {
			return;
		}
		loadModels(false);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const filteredModels = useMemo(() => {
		const sorted = sortModels(models);
		if (filter === 'text') {
			return sorted.filter(
				(model) =>
					Boolean(model.supportsText)
					&& !Boolean(model.supportsImage)
					&& !Boolean(model.supportsAudio)
			);
		}
		if (filter === 'image') return sorted.filter((model) => Boolean(model.supportsImage));
		return sorted;
	}, [models, filter]);

	const { popularOptions, otherOptions } = useMemo(() => {
		const byId = new Map(filteredModels.map((model) => [model.id, model]));
		const popular = POPULAR_MODELS
			.map((id) => byId.get(id))
			.filter(Boolean)
			.map((model) => ({
				value: model.id,
				label: `${model.name} (${model.id})`,
			}));

		const popularIds = new Set(popular.map((option) => option.value));
		const others = filteredModels
			.filter((model) => !popularIds.has(model.id))
			.map((model) => ({
				value: model.id,
				label: `${model.name} (${model.id})`,
			}));

		return { popularOptions: popular, otherOptions: others };
	}, [filteredModels]);

	return (
		<div className={className}>
			<div className="flex items-center justify-between mb-1">
				<label className="flex items-center text-sm font-medium text-gray-700">
					<span>{label}</span>
					{tooltip && <Tooltip content={tooltip} />}
				</label>
				<button
					type="button"
					onClick={() => loadModels(true)}
					className="poststation-icon-btn"
					disabled={disabled || isLoading}
					title="Refresh model list"
					aria-label="Refresh model list"
				>
					<svg className={`w-4 h-4 ${isLoading ? 'animate-spin' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
			</div>

			<select
				className={`poststation-field ${error ? 'poststation-field-error' : ''}`}
				value={value || ''}
				onChange={onChange}
				disabled={disabled || isLoading}
			>
				<option value="">{isLoading ? 'Loading models...' : placeholder}</option>
				{popularOptions.length > 0 && (
					<optgroup label="Popular Models">
						{popularOptions.map((option) => (
							<option key={option.value} value={option.value}>
								{option.label}
							</option>
						))}
					</optgroup>
				)}
				{otherOptions.length > 0 && (
					<optgroup label="All Models">
						{otherOptions.map((option) => (
							<option key={option.value} value={option.value}>
								{option.label}
							</option>
						))}
					</optgroup>
				)}
				{!isLoading && popularOptions.length === 0 && otherOptions.length === 0 && (
					<option value="" disabled>
						No models available for this filter
					</option>
				)}
			</select>

			{error && <p className="mt-1 text-sm text-red-600">{error}</p>}
		</div>
	);
}
