import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
	Button,
	Table,
	TableHead,
	TableBody,
	TableRow,
	TableHeader,
	TableCell,
	EmptyState,
	PageHeader,
	PageLoader,
	ConfirmModal,
} from '../components/common';
import { webhooks, getBootstrapWebhooks, refreshBootstrap } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

export default function WebhooksPage() {
	const navigate = useNavigate();
	const [deleteId, setDeleteId] = useState(null);

	const bootstrapWebhooks = getBootstrapWebhooks();
	const fetchWebhooks = useCallback(() => webhooks.getAll(), []);
	const { data, loading, error, refetch } = useQuery(fetchWebhooks, [], { initialData: bootstrapWebhooks });
	const { mutate: deleteWebhook, loading: deleting } = useMutation(webhooks.delete, {
		onSuccess: refreshBootstrap,
	});

	const handleDelete = async () => {
		if (deleteId) {
			await deleteWebhook(deleteId);
			refetch();
		}
	};

	if (loading) return <PageLoader />;

	const webhookList = data?.webhooks || [];

	return (
		<div>
			<PageHeader
				title="Webhooks"
				description="Manage webhook endpoints for external integrations"
				actions={
					<Button onClick={() => navigate('/webhooks/new')}>
						Add Webhook
					</Button>
				}
			/>

			{webhookList.length === 0 ? (
				<EmptyState
					icon={
						<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" className="w-full h-full">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
						</svg>
					}
					title="No webhooks yet"
					description="Create your first webhook to enable external integrations"
					action={
						<Button onClick={() => navigate('/webhooks/new')}>
							Add Webhook
						</Button>
					}
				/>
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Name</TableHeader>
							<TableHeader>URL</TableHeader>
							<TableHeader>Created</TableHeader>
							<TableHeader className="w-24">Actions</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{webhookList.map((webhook) => (
							<TableRow key={webhook.id}>
								<TableCell>
									<span className="font-medium">{webhook.name}</span>
								</TableCell>
								<TableCell>
									<code className="text-sm text-gray-600 bg-gray-100 px-2 py-1 rounded">
										{webhook.url}
									</code>
								</TableCell>
								<TableCell>
									{new Date(webhook.created_at).toLocaleDateString()}
								</TableCell>
								<TableCell>
									<div className="flex items-center gap-2">
										<button
											onClick={() => navigate(`/webhooks/${webhook.id}`)}
											className="text-indigo-600 hover:text-indigo-900 text-sm font-medium"
										>
											Edit
										</button>
										<button
											onClick={() => setDeleteId(webhook.id)}
											className="text-red-600 hover:text-red-900 text-sm font-medium"
										>
											Delete
										</button>
									</div>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}

			<ConfirmModal
				isOpen={deleteId !== null}
				onClose={() => setDeleteId(null)}
				onConfirm={handleDelete}
				title="Delete Webhook"
				message="Are you sure you want to delete this webhook? This action cannot be undone."
				confirmText="Delete"
			/>
		</div>
	);
}
