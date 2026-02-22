import { useCallback, useState, useRef } from 'react';
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
	CountsBadge,
} from '../components/common';
import { campaigns, getPostTypes, getBootstrapCampaigns, refreshBootstrap } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

const DELETE_CONFIRM_MESSAGE =
	'Are you sure you want to delete this Campaign and all its post tasks? This action cannot be undone.';

export default function CampaignsPage() {
	const navigate = useNavigate();
	const [deletedIds, setDeletedIds] = useState([]);
	const [deletingIds, setDeletingIds] = useState([]);
	const importRef = useRef(null);

	const bootstrapCampaigns = getBootstrapCampaigns();
	const fetchCampaigns = useCallback(() => campaigns.getAll(), []);
	const { data, loading, error, refetch } = useQuery(fetchCampaigns, [], { initialData: bootstrapCampaigns });
	const { mutate: createCampaign, loading: creating } = useMutation(campaigns.create);
	const { mutate: deleteCampaign } = useMutation(campaigns.delete, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: importCampaign, loading: importing } = useMutation(campaigns.import, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: exportCampaign } = useMutation(campaigns.export);

	const postTypes = getPostTypes();

	const handleCreate = async () => {
		try {
			const result = await createCampaign();
			if (result?.id) {
				navigate(`/campaigns/${result.id}`);
				setTimeout(() => refreshBootstrap(), 0);
			}
		} catch (err) {
			console.error('Failed to create campaign:', err);
		}
	};

	const handleDeleteClick = async (id) => {
		if (!window.confirm(DELETE_CONFIRM_MESSAGE)) return;
		setDeletingIds((prev) => [...prev, id]);
		try {
			await deleteCampaign(id);
			setDeletedIds((prev) => [...prev, id]);
		} catch (err) {
			console.error('Failed to delete campaign:', err);
		} finally {
			setDeletingIds((prev) => prev.filter((x) => x !== id));
		}
	};

	const handleImport = async (e) => {
		const file = e.target.files?.[0];
		if (!file) return;

		try {
			const result = await importCampaign(file);
			if (result?.id) {
				navigate(`/campaigns/${result.id}`);
			}
			refetch();
		} catch (err) {
			console.error('Failed to import:', err);
		}
		e.target.value = '';
	};

	const handleExport = async (id) => {
		try {
			const result = await exportCampaign(id);
			// Create download
			const blob = new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = `campaign-${id}.json`;
			a.click();
			URL.revokeObjectURL(url);
		} catch (err) {
			console.error('Failed to export:', err);
		}
	};

	if (loading) return <PageLoader />;

	const campaignsList = (data?.campaigns || []).filter((c) => !deletedIds.includes(c.id));

	return (
		<div>
			<PageHeader
				title="Campaigns"
				description="Manage batch post creation workflows"
				actions={
					<>
						<input
							ref={importRef}
							type="file"
							accept=".json"
							className="hidden"
							onChange={handleImport}
						/>
						<Button variant="secondary" onClick={() => importRef.current?.click()} loading={importing}>
							Import
						</Button>
						<Button onClick={handleCreate} loading={creating}>
							Add New
						</Button>
					</>
				}
			/>

			{campaignsList.length === 0 ? (
				<EmptyState
					icon={
						<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" className="w-full h-full">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
						</svg>
					}
					title="No Campaigns yet"
					description="Create your first Campaign to start batch post creation"
					action={
						<Button onClick={handleCreate} loading={creating}>
							Create Campaign
						</Button>
					}
				/>
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Title</TableHeader>
							<TableHeader>Post Type</TableHeader>
							<TableHeader>Blocks</TableHeader>
							<TableHeader>Created</TableHeader>
							<TableHeader className="w-32">Actions</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{campaignsList.map((campaign) => (
							<TableRow key={campaign.id}>
								<TableCell>
									<button
										onClick={() => navigate(`/campaigns/${campaign.id}`)}
										className="font-medium text-indigo-600 hover:text-indigo-900"
									>
										{campaign.title || `Campaign #${campaign.id}`}
									</button>
								</TableCell>
								<TableCell>
									<span className="inline-flex items-center px-2 py-1 rounded-md bg-gray-100 text-gray-700 text-sm">
										{postTypes[campaign.post_type] || campaign.post_type}
									</span>
								</TableCell>
								<TableCell>
									<CountsBadge counts={campaign.task_counts} />
									{!campaign.task_counts && (
										<span className="text-gray-400 text-sm">No post tasks</span>
									)}
								</TableCell>
								<TableCell>
									{new Date(campaign.created_at).toLocaleDateString()}
								</TableCell>
								<TableCell>
									<div className="flex items-center gap-2">
										<Button
											variant="ghost"
											size="sm"
											onClick={() => navigate(`/campaigns/${campaign.id}`)}
											className="text-indigo-600 hover:text-indigo-900"
										>
											Edit
										</Button>
										<Button
											variant="ghost"
											size="sm"
											onClick={() => handleExport(campaign.id)}
											className="text-gray-600 hover:text-gray-900"
										>
											Export
										</Button>
										<Button
											variant="danger"
											size="sm"
											onClick={() => handleDeleteClick(campaign.id)}
											loading={deletingIds.includes(campaign.id)}
										>
											Delete
										</Button>
									</div>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</div>
	);
}
