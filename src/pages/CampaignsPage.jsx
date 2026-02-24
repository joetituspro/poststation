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

const RSS_FREQUENCY_LABELS = {
	15: 'Every 15 min',
	60: 'Hourly',
	360: 'Every 6 hours',
	1440: 'Daily',
};

function rssSummary(campaign) {
	if (!campaign.rss_enabled || campaign.rss_enabled !== 'yes') return null;
	const count = campaign.rss_sources_count ?? 0;
	const interval = campaign.rss_frequency_interval ?? 60;
	const label = RSS_FREQUENCY_LABELS[interval] ?? `Every ${interval} min`;
	return { count, label };
}

function RssIcon({ className = 'w-4 h-4' }) {
	return (
		<svg className={className} fill="currentColor" viewBox="0 0 24 24" aria-hidden>
			<path d="M6.18 15.64a2.18 2.18 0 0 1 2.18 2.18C8.36 19 7.38 20 6.18 20C5 20 4 19 4 17.82a2.18 2.18 0 0 1 2.18-2.18M4 4.44A15.56 15.56 0 0 1 19.56 20h-2.83A12.73 12.73 0 0 0 4 7.27V4.44m0 5.66a9.9 9.9 0 0 1 9.9 9.9h-2.83A7.07 7.07 0 0 0 4 12.93V10.1Z" />
		</svg>
	);
}

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
	const { mutate: updateStatus } = useMutation(campaigns.updateStatus, {
		onSuccess: () => {
			refetch();
			refreshBootstrap();
		},
	});
	const { mutate: importCampaign, loading: importing } = useMutation(campaigns.import, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: exportCampaign } = useMutation(campaigns.export);
	const [togglingStatusIds, setTogglingStatusIds] = useState([]);

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

	const handleLiveToggle = async (campaign) => {
		const currentStatus = campaign.status ?? 'paused';
		const newStatus = currentStatus === 'active' ? 'paused' : 'active';
		setTogglingStatusIds((prev) => [...prev, campaign.id]);
		try {
			await updateStatus(campaign.id, newStatus);
		} catch (err) {
			console.error('Failed to update campaign status:', err);
		} finally {
			setTogglingStatusIds((prev) => prev.filter((id) => id !== campaign.id));
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
							<TableHeader className="w-20 text-center">Live</TableHeader>
							<TableHeader>RSS</TableHeader>
							<TableHeader className="w-24">Actions</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{campaignsList.map((campaign) => {
							const rss = rssSummary(campaign);
							return (
							<TableRow key={campaign.id}>
								<TableCell>
									<button
										onClick={() => navigate(`/campaigns/${campaign.id}`)}
										className="font-semibold text-indigo-600 hover:text-indigo-900"
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
								<TableCell className="text-center">
									<label
										className="poststation-switch inline-flex items-center justify-center gap-2 cursor-pointer"
										title={(campaign.status ?? 'paused') === 'active' ? 'Live: tasks run automatically' : 'Paused: turn on to run tasks automatically'}
									>
										<input
											type="checkbox"
											className="poststation-field-checkbox"
											checked={(campaign.status ?? 'paused') === 'active'}
											disabled={togglingStatusIds.includes(campaign.id)}
											onChange={() => handleLiveToggle(campaign)}
										/>
										<span className="poststation-switch-track" />
									</label>
								</TableCell>
								<TableCell>
									{rss ? (
										<span className="inline-flex items-center gap-0.5 text-sm text-gray-700" title="RSS feed sources and check frequency">
											<RssIcon className="w-4 h-4 text-orange-500 shrink-0" />
											<span>{rss.count}, {rss.label}</span>
										</span>
									) : (
										<span className="text-gray-400 text-sm">—</span>
									)}
								</TableCell>
								<TableCell>
									<div className="flex items-center gap-1">
										<button
											type="button"
											className="poststation-icon-btn"
											onClick={() => navigate(`/campaigns/${campaign.id}`)}
											title="Open"
											aria-label="Open"
										>
											<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
												<path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
											</svg>
										</button>
										<button
											type="button"
											className="poststation-icon-btn"
											onClick={() => handleExport(campaign.id)}
											title="Export"
											aria-label="Export"
										>
											<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
												<path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
											</svg>
										</button>
										<button
											type="button"
											className="poststation-icon-btn-danger"
											onClick={() => handleDeleteClick(campaign.id)}
											disabled={deletingIds.includes(campaign.id)}
											title="Delete"
											aria-label="Delete"
										>
											{deletingIds.includes(campaign.id) ? (
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
							);
						})}
					</TableBody>
				</Table>
			)}
		</div>
	);
}
