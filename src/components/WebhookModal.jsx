import { useState, useEffect } from 'react';
import { Modal, Button, Input } from './common';
import { webhooks, refreshBootstrap } from '../api/client';
import { useMutation } from '../hooks/useApi';

export default function WebhookModal({ isOpen, onClose, mode = 'add', webhook = null, onSaved }) {
	const [name, setName] = useState('');
	const [url, setUrl] = useState('');
	const [errors, setErrors] = useState({});

	const { mutate: saveWebhook, loading: saving } = useMutation(webhooks.save, {
		onSuccess: (_, vars) => {
			refreshBootstrap();
			onSaved?.();
			onClose();
		},
	});

	useEffect(() => {
		if (isOpen) {
			if (mode === 'edit' && webhook) {
				setName(webhook.name || '');
				setUrl(webhook.url || '');
			} else {
				setName('');
				setUrl('');
			}
			setErrors({});
		}
	}, [isOpen, mode, webhook]);

	const validate = () => {
		const newErrors = {};
		if (!name.trim()) newErrors.name = 'Name is required';
		if (!url.trim()) newErrors.url = 'URL is required';
		else if (!/^https?:\/\/.+/.test(url)) newErrors.url = 'Please enter a valid URL';
		setErrors(newErrors);
		return Object.keys(newErrors).length === 0;
	};

	const handleSubmit = (e) => {
		e.preventDefault();
		if (!validate()) return;
		saveWebhook({ id: webhook?.id, name, url });
	};

	return (
		<Modal
			isOpen={isOpen}
			onClose={onClose}
			title={mode === 'edit' ? 'Edit Webhook' : 'New Webhook'}
			size="md"
		>
			<form onSubmit={handleSubmit} className="space-y-4">
				<Input
					label="Name"
					tooltip="Friendly name used to identify this webhook."
					value={name}
					onChange={(e) => setName(e.target.value)}
					placeholder="e.g., Content Generator"
					error={errors.name}
				/>
				<Input
					label="URL"
					tooltip="Endpoint that receives the generation payload."
					type="url"
					value={url}
					onChange={(e) => setUrl(e.target.value)}
					placeholder="https://api.example.com/webhook"
					error={errors.url}
				/>
				<div className="flex justify-end gap-3 pt-2">
					<Button type="button" variant="secondary" onClick={onClose}>
						Cancel
					</Button>
					<Button type="submit" loading={saving}>
						{mode === 'edit' ? 'Update' : 'Create'} Webhook
					</Button>
				</div>
			</form>
		</Modal>
	);
}
