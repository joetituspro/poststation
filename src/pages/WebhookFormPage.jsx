import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
	Button,
	Input,
	Card,
	CardHeader,
	CardBody,
	PageHeader,
	PageLoader,
} from '../components/common';
import { webhooks } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

export default function WebhookFormPage() {
	const navigate = useNavigate();
	const { id } = useParams();
	const isEdit = !!id;

	const [name, setName] = useState('');
	const [url, setUrl] = useState('');
	const [errors, setErrors] = useState({});

	const fetchWebhook = useCallback(() => {
		if (id) return webhooks.getById(id);
		return Promise.resolve(null);
	}, [id]);

	const { data, loading } = useQuery(fetchWebhook, [id]);
	const { mutate: saveWebhook, loading: saving } = useMutation(webhooks.save);
	const { mutate: deleteWebhook, loading: deleting } = useMutation(webhooks.delete);

	useEffect(() => {
		if (data?.webhook) {
			setName(data.webhook.name || '');
			setUrl(data.webhook.url || '');
		}
	}, [data]);

	const validate = () => {
		const newErrors = {};
		if (!name.trim()) newErrors.name = 'Name is required';
		if (!url.trim()) newErrors.url = 'URL is required';
		else if (!/^https?:\/\/.+/.test(url)) newErrors.url = 'Please enter a valid URL';
		setErrors(newErrors);
		return Object.keys(newErrors).length === 0;
	};

	const handleSubmit = async (e) => {
		e.preventDefault();
		if (!validate()) return;

		try {
			await saveWebhook({ id, name, url });
			navigate('/webhooks');
		} catch (err) {
			console.error('Failed to save webhook:', err);
		}
	};

	const handleDelete = async () => {
		if (!confirm('Are you sure you want to delete this webhook?')) return;
		try {
			await deleteWebhook(id);
			navigate('/webhooks');
		} catch (err) {
			console.error('Failed to delete webhook:', err);
		}
	};

	if (loading && isEdit) return <PageLoader />;

	return (
		<div>
			<PageHeader
				title={isEdit ? 'Edit Webhook' : 'New Webhook'}
				description={isEdit ? 'Update webhook configuration' : 'Create a new webhook endpoint'}
			/>

			<div className="max-w-2xl">
				<Card>
					<CardBody>
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

							<div className="flex items-center justify-between pt-4">
								<div>
									{isEdit && (
										<Button
											type="button"
											variant="danger"
											onClick={handleDelete}
											loading={deleting}
										>
											Delete
										</Button>
									)}
								</div>
								<div className="flex gap-3">
									<Button
										type="button"
										variant="secondary"
										onClick={() => navigate('/webhooks')}
									>
										Cancel
									</Button>
									<Button type="submit" loading={saving}>
										{isEdit ? 'Update' : 'Create'} Webhook
									</Button>
								</div>
							</div>
						</form>
					</CardBody>
				</Card>
			</div>
		</div>
	);
}
