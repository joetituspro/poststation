import { useState, useEffect } from 'react';
import { Button, Input, Textarea, Modal } from '../common';
import { ai, instructions, refreshBootstrap } from '../../api/client';

const DEFAULT_KEYS = ['listicle', 'news', 'guide', 'howto'];
const isDefaultPreset = (key) => key && DEFAULT_KEYS.includes(key);

export default function InstructionModal({
	isOpen,
	onClose,
	mode = 'add',
	instruction = null,
	onSaved,
}) {
	const [key, setKey] = useState('');
	const [name, setName] = useState('');
	const [description, setDescription] = useState('');
	const [titleInstruction, setTitleInstruction] = useState('');
	const [bodyInstruction, setBodyInstruction] = useState('');
	const [saving, setSaving] = useState(false);
	const [resetting, setResetting] = useState(false);
	const [aiOpen, setAiOpen] = useState(false);
	const [aiPrompt, setAiPrompt] = useState('');
	const [aiGenerating, setAiGenerating] = useState(false);
	const [error, setError] = useState('');

	const lockKeyAndName = mode === 'edit' && instruction && isDefaultPreset(instruction.key);
	const showResetButton = mode === 'edit' && instruction && isDefaultPreset(instruction.key);

	useEffect(() => {
		if (!isOpen) return;
		setError('');
		if (mode === 'add' && !instruction) {
			setKey('');
			setName('');
			setDescription('');
			setTitleInstruction('');
			setBodyInstruction('');
			setAiPrompt('');
			setAiOpen(false);
			return;
		}
		if (mode === 'duplicate' || mode === 'edit') {
			const inst = instruction || {};
			const instr = inst.instructions || {};
			setKey(mode === 'duplicate' ? '' : (inst.key ?? ''));
			setName(mode === 'duplicate' ? '' : (inst.name ?? ''));
			setDescription(inst.description ?? '');
			setTitleInstruction(instr.title ?? '');
			setBodyInstruction(instr.body ?? '');
			setAiPrompt('');
			setAiOpen(false);
		}
	}, [isOpen, mode, instruction]);

	const handleAiGenerate = async () => {
		setError('');
		if (!String(aiPrompt ?? '').trim()) {
			setError('Describe the preset or provide an article URL first.');
			return;
		}

		setAiGenerating(true);
		try {
			const result = await ai.generateInstructionPreset({
				prompt: String(aiPrompt ?? '').trim(),
				provider: 'openrouter',
			});
			const preset = result?.preset || {};
			const generatedInstructions = preset?.instructions || {};
			setKey(preset?.key ?? '');
			setName(preset?.name ?? '');
			setDescription(preset?.description ?? '');
			setTitleInstruction(generatedInstructions?.title ?? '');
			setBodyInstruction(generatedInstructions?.body ?? '');
		} catch (err) {
			setError(err?.message || 'Failed to generate preset with AI.');
		} finally {
			setAiGenerating(false);
		}
	};

	const handleSubmit = async (e) => {
		e.preventDefault();
		setError('');
		const keyTrim = String(key ?? '').trim().toLowerCase().replace(/\s+/g, '_');
		const nameTrim = String(name ?? '').trim();
		if (!keyTrim || !nameTrim) {
			setError('Key and name are required.');
			return;
		}
		const payload = {
			description: String(description ?? '').trim(),
			instructions: {
				title: String(titleInstruction ?? '').trim(),
				body: String(bodyInstruction ?? '').trim(),
			},
		};
		setSaving(true);
		try {
			if (mode === 'add' || mode === 'duplicate') {
				const result = await instructions.create({
					key: keyTrim,
					name: nameTrim,
					...payload,
				});
				await refreshBootstrap();
				onSaved?.(result?.id ?? result?.instruction?.id);
			} else {
				await instructions.update(instruction.id, payload);
				await refreshBootstrap();
				onSaved?.();
			}
			onClose();
		} catch (err) {
			setError(err?.message || 'Failed to save.');
		} finally {
			setSaving(false);
		}
	};

	const handleReset = async () => {
		if (!instruction?.id || !showResetButton) return;
		setError('');
		setResetting(true);
		try {
			const result = await instructions.reset(instruction.id);
			await refreshBootstrap();
			const inst = result?.instruction;
			if (inst) {
				setDescription(inst.description ?? '');
				setTitleInstruction(inst.instructions?.title ?? '');
				setBodyInstruction(inst.instructions?.body ?? '');
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
			? 'Add instruction preset'
			: mode === 'duplicate'
				? 'Duplicate instruction preset'
				: 'Edit instruction preset';

	const showAiTools = mode === 'add';
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
								onChange={(e) => setKey(e.target.value)}
								placeholder="e.g. my_preset"
								disabled={lockKeyAndName || aiGenerating}
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
						<Textarea
							label="Description"
							value={description}
							onChange={(e) => setDescription(e.target.value)}
							placeholder="Short description for the preset"
							rows={2}
							disabled={aiGenerating}
						/>
						<div className="border-t border-gray-200 pt-4 space-y-4">
							<h4 className="text-sm font-medium text-gray-900">Instructions</h4>
							<Textarea
								label="Title instruction"
								value={titleInstruction}
								onChange={(e) => setTitleInstruction(e.target.value)}
								placeholder="How to write the title for this type"
								rows={2}
								disabled={aiGenerating}
							/>
							<Textarea
								label="Body instruction"
								value={bodyInstruction}
								onChange={(e) => setBodyInstruction(e.target.value)}
								placeholder="How to write the body"
								rows={3}
								disabled={aiGenerating}
							/>
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
					<Button type="submit" loading={saving} disabled={aiGenerating}>
						{mode === 'add' || mode === 'duplicate' ? 'Create' : 'Save'}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
