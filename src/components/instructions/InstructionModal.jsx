import { useState, useEffect } from 'react';
import { Button, Input, Textarea, Modal } from '../common';
import { instructions, refreshBootstrap } from '../../api/client';

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
		}
	}, [isOpen, mode, instruction]);

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

	return (
		<Modal isOpen={isOpen} onClose={onClose} title={title} size="lg">
			<form onSubmit={handleSubmit} className="space-y-4">
				{error && (
					<div className="p-3 rounded-lg bg-red-50 text-red-700 text-sm">
						{error}
					</div>
				)}
				<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
					<Input
						label="Key"
						value={key}
						onChange={(e) => setKey(e.target.value)}
						placeholder="e.g. my_preset"
						disabled={lockKeyAndName}
						required
					/>
					<Input
						label="Name"
						value={name}
						onChange={(e) => setName(e.target.value)}
						placeholder="Display name"
						disabled={lockKeyAndName}
						required
					/>
				</div>
				<Textarea
					label="Description"
					value={description}
					onChange={(e) => setDescription(e.target.value)}
					placeholder="Short description for the preset"
					rows={2}
				/>
				<div className="border-t border-gray-200 pt-4 space-y-4">
					<h4 className="text-sm font-medium text-gray-900">Instructions</h4>
					<Textarea
						label="Title instruction"
						value={titleInstruction}
						onChange={(e) => setTitleInstruction(e.target.value)}
						placeholder="How to write the title for this type"
						rows={2}
					/>
					<Textarea
						label="Body instruction"
						value={bodyInstruction}
						onChange={(e) => setBodyInstruction(e.target.value)}
						placeholder="How to write the body"
						rows={3}
					/>
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
					<Button type="button" variant="secondary" onClick={onClose} disabled={saving}>
						Cancel
					</Button>
					<Button type="submit" loading={saving}>
						{mode === 'add' || mode === 'duplicate' ? 'Create' : 'Save'}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
