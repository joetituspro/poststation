import { useState, useEffect } from 'react';
import { Button, Input, ModelSelect, Textarea, Modal } from '../common';
import { ai, getBootstrapSettings, writingPresets, refreshBootstrap } from '../../api/client';

const DEFAULT_KEYS = ['listicle', 'news', 'guide', 'howto'];
const MAX_BODY_ELEMENTS = 10;
const isDefaultPreset = (key) => key && DEFAULT_KEYS.includes(key);
const KEY_FORMAT = /^[a-z0-9_-]+$/;

const normalizeKey = (value = '') =>
	String(value)
		.toLowerCase()
		.replace(/[^a-z0-9_\s-]/g, '')
		.replace(/\s+/g, '_')
		.replace(/-+/g, '-')
		.replace(/_+/g, '_');

const createBodyElement = (name = '', content = '') => ({
	id: `${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
	name: String(name ?? ''),
	content: String(content ?? ''),
});

const normalizeBodyElements = (elements) => {
	if (!Array.isArray(elements)) return [];
	const normalized = [];
	for (const row of elements) {
		const name = String(row?.name ?? '').trim();
		const content = String(row?.content ?? '').trim();
		if (!name && !content) continue;
		normalized.push(createBodyElement(name, content));
		if (normalized.length >= MAX_BODY_ELEMENTS) break;
	}
	return normalized;
};

const parseBodyInstruction = (value) => {
	if (Array.isArray(value)) {
		return normalizeBodyElements(value);
	}

	if (value && typeof value === 'object' && Array.isArray(value.elements)) {
		return normalizeBodyElements(value.elements);
	}

	const text = String(value ?? '').trim();
	if (!text) return [];

	try {
		const parsed = JSON.parse(text);
		if (Array.isArray(parsed)) {
			return normalizeBodyElements(parsed);
		}
		if (parsed && typeof parsed === 'object' && Array.isArray(parsed.elements)) {
			return normalizeBodyElements(parsed.elements);
		}
	} catch (_err) {
		// Legacy plain-text body instructions are converted into one row.
	}

	return [createBodyElement('Body instruction', text)];
};

const serializeBodyInstruction = (elements) => {
	const payload = [];
	const seen = new Set();

	for (const row of elements || []) {
		const name = String(row?.name ?? '').trim();
		const content = String(row?.content ?? '').trim();
		if (!name && !content) continue;

		const signature = `${name.toLowerCase()}::${content.toLowerCase()}`;
		if (seen.has(signature)) continue;
		seen.add(signature);

		payload.push({ name, content });
		if (payload.length >= MAX_BODY_ELEMENTS) break;
	}

	return JSON.stringify(payload);
};

export default function WritingPresetModal({
	isOpen,
	onClose,
	mode = 'add',
	writingPreset = null,
	onSaved,
	onSaveAndApply,
	showSaveAndApply = false,
}) {
	const bootstrapSettings = getBootstrapSettings() || {};
	const defaultAiModel = bootstrapSettings?.openrouter_default_text_model || '';
	const [key, setKey] = useState('');
	const [name, setName] = useState('');
	const [titleInstruction, setTitleInstruction] = useState('');
	const [bodyElements, setBodyElements] = useState([]);
	const [saving, setSaving] = useState(false);
	const [resetting, setResetting] = useState(false);
	const [aiOpen, setAiOpen] = useState(false);
	const [aiPrompt, setAiPrompt] = useState('');
	const [aiModel, setAiModel] = useState(defaultAiModel);
	const [aiGenerating, setAiGenerating] = useState(false);
	const [error, setError] = useState('');

	const lockKeyAndName = mode === 'edit' && writingPreset && isDefaultPreset(writingPreset.key);
	const showResetButton = mode === 'edit' && writingPreset && isDefaultPreset(writingPreset.key);
	const bodyElementsLimitReached = bodyElements.length >= MAX_BODY_ELEMENTS;

	useEffect(() => {
		if (!isOpen) return;
		setError('');
		if (mode === 'add' && !writingPreset) {
			setKey('');
			setName('');
			setTitleInstruction('');
			setBodyElements([]);
			setAiPrompt('');
			setAiModel(defaultAiModel);
			setAiOpen(false);
			return;
		}
		if (mode === 'duplicate' || mode === 'edit') {
			const inst = writingPreset || {};
			const instr = inst.instructions || {};
			setKey(mode === 'duplicate' ? '' : (inst.key ?? ''));
			setName(mode === 'duplicate' ? '' : (inst.name ?? ''));
			setTitleInstruction(instr.title ?? '');
			setBodyElements(parseBodyInstruction(instr.body));
			setAiPrompt('');
			setAiModel(defaultAiModel);
			setAiOpen(false);
		}
	}, [isOpen, mode, writingPreset, defaultAiModel]);

	const handleAiGenerate = async () => {
		setError('');
		if (!String(aiPrompt ?? '').trim()) {
			setError('Describe the preset or provide an article URL first.');
			return;
		}

		setAiGenerating(true);
		try {
			const result = await ai.generateWritingPreset({
				prompt: String(aiPrompt ?? '').trim(),
				provider: 'openrouter',
				model: String(aiModel ?? '').trim(),
			});
			const preset = result?.preset || {};
			const generatedInstructions = preset?.instructions || {};
			setKey(normalizeKey(preset?.key ?? ''));
			setName(preset?.name ?? '');
			setTitleInstruction(generatedInstructions?.title ?? '');
			setBodyElements(parseBodyInstruction(generatedInstructions?.body));
		} catch (err) {
			setError(err?.message || 'Failed to generate preset with AI.');
		} finally {
			setAiGenerating(false);
		}
	};

	const addBodyElement = () => {
		if (bodyElementsLimitReached) return;
		setBodyElements((prev) => [...prev, createBodyElement()]);
	};

	const updateBodyElement = (id, field, value) => {
		setBodyElements((prev) =>
			prev.map((row) =>
				row.id === id ? { ...row, [field]: value } : row
			)
		);
	};

	const removeBodyElement = (id) => {
		setBodyElements((prev) => prev.filter((row) => row.id !== id));
	};

	const savePreset = async ({ applyAfterSave = false } = {}) => {
		setError('');
		const keyTrim = normalizeKey(String(key ?? '').trim());
		const nameTrim = String(name ?? '').trim();
		if (!keyTrim || !nameTrim) {
			setError('Key and name are required.');
			return false;
		}
		if (!KEY_FORMAT.test(keyTrim)) {
			setError('Key must use lowercase letters, numbers, underscores, and hyphens only.');
			return false;
		}
		const payload = {
			instructions: {
				title: String(titleInstruction ?? '').trim(),
				body: serializeBodyInstruction(bodyElements),
			},
		};
		setSaving(true);
		try {
			let result = null;
			let savedId = null;
			let savedPreset = null;
			if (mode === 'add' || mode === 'duplicate') {
				result = await writingPresets.create({
					key: keyTrim,
					name: nameTrim,
					...payload,
				});
				savedId = result?.id ?? result?.writing_preset?.id ?? null;
				savedPreset = result?.writing_preset ?? null;
			} else {
				result = await writingPresets.update(writingPreset.id, payload);
				savedId = writingPreset?.id ?? result?.writing_preset?.id ?? null;
				savedPreset = result?.writing_preset ?? {
					...(writingPreset || {}),
					instructions: payload.instructions,
				};
			}
			await refreshBootstrap();
			onSaved?.({
				id: savedId,
				writingPreset: savedPreset,
				mode,
				applyAfterSave,
			});
			if (applyAfterSave) {
				await onSaveAndApply?.({
					id: savedId,
					writingPreset: savedPreset,
				});
			}
			onClose();
			return true;
		} catch (err) {
			setError(err?.message || 'Failed to save.');
			return false;
		} finally {
			setSaving(false);
		}
	};

	const handleSubmit = async (e) => {
		e.preventDefault();
		await savePreset({ applyAfterSave: false });
	};

	const handleSaveAndApply = async () => {
		await savePreset({ applyAfterSave: true });
	};

	const handleReset = async () => {
		if (!writingPreset?.id || !showResetButton) return;
		setError('');
		setResetting(true);
		try {
			const result = await writingPresets.reset(writingPreset.id);
			await refreshBootstrap();
			const inst = result?.writing_preset;
			if (inst) {
				setTitleInstruction(inst.instructions?.title ?? '');
				setBodyElements(parseBodyInstruction(inst.instructions?.body));
			}
			onSaved?.();
		} catch (err) {
			setError(err?.message || 'Failed to reset.');
		} finally {
			setResetting(false);
		}
	};

	const title =
		mode === 'add'
			? 'Add writing preset'
			: mode === 'duplicate'
				? 'Duplicate writing preset'
				: 'Edit writing preset';

	const showAiTools = mode === 'add';
	const keyHasInvalidFormat = !!key && !KEY_FORMAT.test(key);
	const modalTitle = (
		<span className="inline-flex items-center gap-2">
			<span>{title}</span>
			{showAiTools && (
				<button
					type="button"
					onClick={() => setAiOpen((prev) => !prev)}
					title="Generate with AI"
					className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors"
				>
					<svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
						<path strokeLinecap="round" strokeLinejoin="round" d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3zM5 16l.9 2.1L8 19l-2.1.9L5 22l-.9-2.1L2 19l2.1-.9L5 16zM19 14l.9 2.1L22 17l-2.1.9L19 20l-.9-2.1L16 17l2.1-.9L19 14z" />
					</svg>
				</button>
			)}
		</span>
	);

	return (
		<Modal isOpen={isOpen} onClose={onClose} title={modalTitle} size="lg">
			<form onSubmit={handleSubmit} className="space-y-4">
				{error && (
					<div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm">
						{error}
					</div>
				)}
				{showAiTools && aiOpen && (
					<div className="rounded-xl border border-indigo-200 bg-indigo-50/60 p-3 space-y-3">
						<ModelSelect
							label="AI model"
							tooltip="OpenRouter text model used to generate the writing preset."
							value={aiModel}
							onChange={(e) => setAiModel(e.target.value)}
							filter="text"
							placeholder="Use default model"
							disabled={aiGenerating || saving || resetting}
						/>
						<Textarea
							label="Describe the preset you want"
							value={aiPrompt}
							onChange={(e) => setAiPrompt(e.target.value)}
							placeholder="Example: Create a preset for product comparison reviews, or paste an article sample URL..."
							rows={3}
						/>
						<div className="flex justify-end">
							<Button
								type="button"
								onClick={handleAiGenerate}
								loading={aiGenerating}
								disabled={saving || resetting}
							>
								Generate with AI
							</Button>
						</div>
					</div>
				)}
				<div className="relative">
					{aiGenerating && (
						<div className="absolute inset-0 z-20 rounded-xl bg-white/80 backdrop-blur-sm border border-indigo-100 flex flex-col items-center justify-center gap-4">
							<div className="h-10 w-10 rounded-full border-2 border-indigo-200 border-t-indigo-600 animate-spin" />
							<div className="flex gap-1">
								<span className="h-2 w-2 rounded-full bg-indigo-500 animate-bounce [animation-delay:-0.3s]" />
								<span className="h-2 w-2 rounded-full bg-indigo-500 animate-bounce [animation-delay:-0.15s]" />
								<span className="h-2 w-2 rounded-full bg-indigo-500 animate-bounce" />
							</div>
							<p className="text-sm text-indigo-900 font-medium">Generating preset...</p>
						</div>
					)}
					<div className="space-y-4">
						<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
							<Input
								label="Key"
								value={key}
								onChange={(e) => setKey(normalizeKey(e.target.value))}
								placeholder="e.g. my_preset"
								disabled={lockKeyAndName || aiGenerating}
								error={keyHasInvalidFormat ? 'Use lowercase letters, numbers, underscores, and hyphens only.' : ''}
								required
							/>
							<Input
								label="Name"
								value={name}
								onChange={(e) => setName(e.target.value)}
								placeholder="Display name"
								disabled={lockKeyAndName || aiGenerating}
								required
							/>
						</div>
						<div className="border-t border-gray-200 pt-4 space-y-4">
							<Textarea
								label="Title instruction"
								value={titleInstruction}
								onChange={(e) => setTitleInstruction(e.target.value)}
								placeholder="How to write the title for this type"
								rows={2}
								disabled={aiGenerating}
							/>

							<div>
								<div className="flex items-center justify-between mb-2">
									<label className="text-sm font-medium text-gray-700">
										Writing Preset
									</label>
									<span className="text-xs text-gray-500">
										{bodyElements.length}/{MAX_BODY_ELEMENTS}
									</span>
								</div>
								<div className="rounded-lg border border-gray-200 overflow-hidden">
									<div className="grid grid-cols-12 bg-gray-50 border-b border-gray-200">
										<div className="col-span-4 px-3 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wide">Name</div>
										<div className="col-span-7 px-3 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wide">Content</div>
										<div className="col-span-1 px-3 py-2" />
									</div>
									{bodyElements.length === 0 ? (
										<div className="px-3 py-4 text-sm text-gray-500 bg-white">No elements yet. Add one below.</div>
									) : (
										<div className="max-h-72 overflow-y-auto bg-white">
											{bodyElements.map((row) => (
												<div key={row.id} className="grid grid-cols-12 border-b border-gray-100 last:border-b-0">
														<div className="col-span-3 px-2 py-2">
															<textarea
																value={row.name}
																onChange={(e) => updateBodyElement(row.id, 'name', e.target.value)}
															placeholder="e.g. Voice Tone"
															rows={2}
																className="poststation-inline-editor w-full min-h-[56px] resize-none! bg-transparent border-0 rounded-none px-2 py-2 text-sm font-bold! text-gray-900 placeholder:text-gray-400 outline-none shadow-none focus:outline-none focus:ring-0 focus:border-0"
															disabled={aiGenerating}
														/>
														</div>
													<div className="col-span-8 px-2 py-2">
														<textarea
															value={row.content}
															onChange={(e) => updateBodyElement(row.id, 'content', e.target.value)}
															placeholder="Describe this element"
															rows={2}
																className="poststation-inline-editor w-full min-h-[72px] resize-none bg-transparent border-0 rounded-none px-2 py-2 text-sm text-gray-900 placeholder:text-gray-400 outline-none shadow-none focus:outline-none focus:ring-0 focus:border-0"
															disabled={aiGenerating}
														/>
													</div>
													<div className="col-span-1 px-2 py-2 flex items-center justify-center">
														<button
															type="button"
															className="poststation-icon-btn-danger"
															onClick={() => removeBodyElement(row.id)}
															title="Remove element"
															aria-label="Remove element"
															disabled={aiGenerating}
														>
															<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
																<path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
															</svg>
														</button>
													</div>
												</div>
											))}
										</div>
									)}
								</div>
								<div className="pt-3">
									<Button
										type="button"
										variant="secondary"
										onClick={addBodyElement}
										disabled={aiGenerating || bodyElementsLimitReached}
									>
										Add an Element
									</Button>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div className="flex justify-end gap-2 pt-2">
					{showResetButton && (
						<Button
							type="button"
							variant="secondary"
							onClick={handleReset}
							loading={resetting}
							disabled={saving}
							className="mr-auto"
						>
							Reset to default
						</Button>
					)}
					<Button type="button" variant="secondary" onClick={onClose} disabled={saving || aiGenerating}>
						Cancel
					</Button>
					{mode === 'edit' && showSaveAndApply && (
						<Button
							type="button"
							variant="secondary"
							onClick={handleSaveAndApply}
							loading={saving}
							disabled={aiGenerating || keyHasInvalidFormat}
						>
							Save and Apply
						</Button>
					)}
					<Button type="submit" loading={saving} disabled={aiGenerating || keyHasInvalidFormat}>
						{mode === 'add' || mode === 'duplicate' ? 'Create' : 'Save'}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
