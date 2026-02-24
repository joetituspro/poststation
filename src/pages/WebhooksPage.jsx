import { useCallback, useState } from 'react';
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
} from '../components/common';
import WebhookModal from '../components/WebhookModal';
import { webhooks, getBootstrapWebhooks, refreshBootstrap } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

const DELETE_CONFIRM_MESSAGE =
	'Are you sure you want to delete this webhook? This action cannot be undone.';

export default function WebhooksPage() {
	const [deletingIds, setDeletingIds] = useState([]);
	const [deletedIds, setDeletedIds] = useState([]);
	const [webhookModal, setWebhookModal] = useState({ open: false, mode: 'add', webhook: null });

	const bootstrapWebhooks = getBootstrapWebhooks();
	const fetchWebhooks = useCallback(() => webhooks.getAll(), []);
	const { data, loading, error, refetch } = useQuery(fetchWebhooks, [], { initialData: bootstrapWebhooks });

	const openWebhookModal = (mode, webhook = null) => {
		setWebhookModal({ open: true, mode, webhook });
	};
	const closeWebhookModal = () => {
		setWebhookModal((prev) => ({ ...prev, open: false }));
	};
	const handleWebhookSaved = () => {
		refetch();
	};
	const { mutate: deleteWebhook } = useMutation(webhooks.delete, {
		onSuccess: refreshBootstrap,
	});

	const handleDeleteClick = async (id) => {
		if (!window.confirm(DELETE_CONFIRM_MESSAGE)) return;
		setDeletingIds((prev) => [...prev, id]);
		try {
			await deleteWebhook(id);
			setDeletedIds((prev) => [...prev, id]);
		} catch (err) {
			console.error('Failed to delete webhook:', err);
		} finally {
			setDeletingIds((prev) => prev.filter((x) => x !== id));
		}
	};

	if (loading) return <PageLoader />;

	const webhookList = (data?.webhooks || []).filter((w) => !deletedIds.includes(w.id));

	return (
		<div>
			<PageHeader
				title="Webhooks"
				description="Manage webhook endpoints for external integrations"
				actions={
					<Button onClick={() => openWebhookModal('add')}>
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
						<Button onClick={() => openWebhookModal('add')}>
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
									<div className="flex items-center gap-1">
										<button
											type="button"
											className="poststation-icon-btn"
											onClick={() => openWebhookModal('edit', webhook)}
											title="Edit"
											aria-label="Edit"
										>
											<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
												<path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
											</svg>
										</button>
										<button
											type="button"
											className="poststation-icon-btn-danger"
											onClick={() => handleDeleteClick(webhook.id)}
											disabled={deletingIds.includes(webhook.id)}
											title="Delete"
											aria-label="Delete"
										>
											{deletingIds.includes(webhook.id) ? (
												<svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
													<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
													<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
												</svg>
											) : (
												<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
													<path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
												</svg>
											)}
										</button>
									</div>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}

			<WebhookModal
				isOpen={webhookModal.open}
				onClose={closeWebhookModal}
				mode={webhookModal.mode}
				webhook={webhookModal.webhook}
				onSaved={handleWebhookSaved}
			/>
		</div>
	);
}
