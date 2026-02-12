import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams } from 'react-router-dom';
import {
	Button,
	Card,
	CardBody,
	PageLoader,
	useToast,
} from '../components/common';
import CampaignForm from '../components/postworks/PostWorkForm';
import ContentFieldsEditor from '../components/postworks/ContentFieldsEditor';
import BlocksList from '../components/postworks/BlocksList';
import InfoSidebar from '../components/layout/InfoSidebar';
import { campaigns, postTasks, webhooks, getTaxonomies, getPendingProcessingPostTasks, refreshBootstrap, getBootstrapWebhooks } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';
import { useUnsavedChanges } from '../context/UnsavedChangesContext';

// Editable title: shows text, pen icon on hover, click to edit inline
function EditableCampaignTitle({ value, onChange }) {
	const [isEditing, setIsEditing] = useState(false);
	const [editValue, setEditValue] = useState(value || '');
	const inputRef = useRef(null);

	useEffect(() => {
		setEditValue(value || '');
	}, [value]);

	useEffect(() => {
		if (isEditing && inputRef.current) {
			inputRef.current.focus();
			inputRef.current.select();
		}
	}, [isEditing]);

	const handleBlur = () => {
		setIsEditing(false);
		const trimmed = editValue.trim();
		if (trimmed && trimmed !== value) {
			onChange(trimmed);
		} else {
			setEditValue(value || '');
		}
	};

	const handleKeyDown = (e) => {
		if (e.key === 'Enter') {
			e.target.blur();
		}
		if (e.key === 'Escape') {
			setEditValue(value || '');
			setIsEditing(false);
			inputRef.current?.blur();
		}
	};

	const displayName = value?.trim() || 'Untitled Campaign';

	return (
		<div className="flex items-center min-w-0">
			{isEditing ? (
				<input
					ref={inputRef}
					type="text"
					value={editValue}
					onChange={(e) => setEditValue(e.target.value)}
					onBlur={handleBlur}
					onKeyDown={handleKeyDown}
					className="poststation-field flex-1 min-w-0 px-3 py-1.5 text-xl font-semibold border-indigo-300 focus:border-indigo-500 focus:ring-indigo-500"
				/>
			) : (
				<button
					type="button"
					onClick={() => setIsEditing(true)}
					className="group flex items-center gap-2 min-w-0 rounded-lg py-1.5 px-2 -ml-2 hover:bg-gray-100 transition-colors text-left"
				>
					<span className="text-xl font-semibold text-gray-900 truncate">{displayName}</span>
					<svg
						className="shrink-0 w-4 h-4 text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity"
						fill="none"
						viewBox="0 0 24 24"
						stroke="currentColor"
					>
						<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
					</svg>
				</button>
			)}
		</div>
	);
}

export default function CampaignEditPage() {
	const { id } = useParams();
	const [campaign, setCampaign] = useState(null);
	const [blocksList, setBlocksList] = useState([]);
	const [showSettings, setShowSettings] = useState(false);
	const [isRunning, setIsRunning] = useState(false);
	const [isDirty, setIsDirty] = useState(false);
	const [retryingBlockId, setRetryingBlockId] = useState(null);
	const [retryFailedLoading, setRetryFailedLoading] = useState(false);
	const [savingAll, setSavingAll] = useState(false);
	const [runLoading, setRunLoading] = useState(false);
	const [stopLoading, setStopLoading] = useState(false);
	const [clearCompletedLoading, setClearCompletedLoading] = useState(false);
	const [importLoading, setImportLoading] = useState(false);
	const stopRunRef = useRef(false);
	const { showToast } = useToast();
	const { setIsDirty: setGlobalDirty } = useUnsavedChanges();

	const getBlockIdKey = useCallback((value) => String(value ?? ''), []);
	const fetchCampaign = useCallback(() => campaigns.getById(id), [id]);
	const { data, loading, error, refetch } = useQuery(fetchCampaign, [id]);

	// Fetch webhooks for dropdown
	const bootstrapWebhooks = getBootstrapWebhooks();
	const fetchWebhooks = useCallback(() => webhooks.getAll(), []);
	const { data: webhooksData } = useQuery(fetchWebhooks, [], { initialData: bootstrapWebhooks });

	// Mutations
	const { mutate: updateCampaign, loading: saving } = useMutation(
		(data) => campaigns.update(id, data)
	);
	const { mutate: createBlock, loading: creatingBlock } = useMutation(
		() => postTasks.create(id)
	);
	const { mutate: updateBlocks } = useMutation(
		(tasksData) => postTasks.update(id, tasksData)
	);
	const { mutate: deleteBlock } = useMutation(postTasks.delete);
	const { mutate: clearCompletedBlocks } = useMutation(
		() => postTasks.clearCompleted(id)
	);
	const { mutate: importBlocks } = useMutation(
		(file) => postTasks.import(id, file)
	);
	const { mutate: runCampaign } = useMutation(
		(taskId, webhookId) => campaigns.run(id, taskId, webhookId)
	);
	const { mutate: stopCampaignRun } = useMutation(
		() => campaigns.stopRun(id)
	);
	const { mutate: exportCampaign } = useMutation(
		() => campaigns.export(id)
	);

	// Initialize state from fetched data
	useEffect(() => {
		if (data) {
			const articleType = data.campaign?.article_type || 'blog_post';
			const language = data.campaign?.language || 'en';
			const targetCountry = data.campaign?.target_country || 'international';
			setCampaign({
				...data.campaign,
				article_type: articleType,
				language,
				target_country: targetCountry,
			});
			setBlocksList(
				(data.tasks || []).map((block) => ({
					...block,
					article_type: block.article_type || articleType,
					topic: block.topic ?? '',
					keywords: block.keywords ?? '',
				}))
			);
		}
	}, [data]);

	useEffect(() => {
		const hasProcessing = blocksList.some((block) => block.status === 'processing');
		if (hasProcessing !== isRunning) {
			setIsRunning(hasProcessing);
		}
	}, [blocksList, isRunning]);

	useEffect(() => {
		setGlobalDirty(isDirty);
		return () => setGlobalDirty(false);
	}, [isDirty, setGlobalDirty]);

	const applyPendingProcessingUpdates = useCallback((pendingProcessing) => {
		if (!Array.isArray(pendingProcessing) || pendingProcessing.length === 0) return;
		const updatesById = new Map(pendingProcessing.map((item) => [getBlockIdKey(item.id), item]));
		setBlocksList((prev) =>
			prev.map((block) => {
				const match = updatesById.get(getBlockIdKey(block.id));
				if (!match) return block;

				// Don't overwrite local 'processing' status with 'pending' or 'failed' 
				// if the server is just slow to catch up
				if (block.status === 'processing' && match.status !== 'processing') {
					// Only allow transition to completed or failed if it's actually finished
					if (match.status !== 'completed' && match.status !== 'failed') {
						return block;
					}
				}

				const newStatus =
					match.status === 'processing' && match.error_message
						? 'failed'
						: match.status;

				if (
					block.status === newStatus &&
					block.progress === match.progress &&
					block.post_id === match.post_id &&
					block.error_message === match.error_message
				) {
					return block;
				}
				return {
					...block,
					status: newStatus,
					progress: match.progress,
					post_id: match.post_id,
					error_message: match.error_message,
				};
			})
		);
	}, [getBlockIdKey]);

	useEffect(() => {
		let cancelled = false;

		const refreshPendingProcessing = async () => {
			try {
				const pendingProcessing = await getPendingProcessingPostTasks(id);
				if (cancelled) return;
				applyPendingProcessingUpdates(pendingProcessing);
			} catch {
				// ignore polling errors
			}
		};

		refreshPendingProcessing();
		const interval = setInterval(refreshPendingProcessing, 5000);

		return () => {
			cancelled = true;
			clearInterval(interval);
		};
	}, [id, applyPendingProcessingUpdates]);

	// Handle campaign changes
	const handleCampaignChange = (newCampaign) => {
		if (newCampaign?.clear_image_overrides) {
			const updatedBlocks = blocksList.map((block) => ({
				...block,
				feature_image_id: null,
				feature_image_title: '',
			}));
			setBlocksList(updatedBlocks);
			setIsDirty(true);
			updateBlocks(updatedBlocks).catch(() => {
				refetch();
			});
			const { clear_image_overrides: _, ...rest } = newCampaign;
			setCampaign(rest);
			return;
		}

		setCampaign(newCampaign);
		setIsDirty(true);
	};

	// Handle title change from header
	const handleTitleChange = (newTitle) => {
		handleCampaignChange({ ...campaign, title: newTitle });
	};

	// Handle block changes
	const handleBlockUpdate = (blockId, updates) => {
		setBlocksList((prev) =>
			prev.map((b) => (b.id === blockId ? { ...b, ...updates } : b))
		);
		setIsDirty(true);
	};

	// Add new block
	const handleAddBlock = async () => {
		try {
			const result = await createBlock();
			const applyCurrentDefaults = (block) => ({
				...block,
				article_type: campaign?.article_type || 'blog_post',
				topic: block.topic ?? '',
				keywords: block.keywords ?? '',
			});
			if (result?.block) {
				const enrichedBlock = applyCurrentDefaults(result.block);
				setBlocksList((prev) => [enrichedBlock, ...prev]);
				await updateBlocks([enrichedBlock]);
				return;
			}
			if (result?.id) {
				const newBlock = {
					id: result.id,
					status: 'pending',
					topic: '',
					keywords: '',
					article_type: campaign?.article_type || 'blog_post',
					article_url: '',
					research_url: '',
					feature_image_id: null,
					feature_image_title: '',
				};
				const enrichedBlock = applyCurrentDefaults(newBlock);
				setBlocksList((prev) => [enrichedBlock, ...prev]);
				await updateBlocks([enrichedBlock]);
			}
		} catch (err) {
			console.error('Failed to create block:', err);
		}
	};

	// Delete block
	const handleDeleteBlock = async (blockId) => {
		try {
			await deleteBlock(blockId);
			setBlocksList((prev) => prev.filter((b) => b.id !== blockId));
		} catch (err) {
			console.error('Failed to delete block:', err);
		}
	};

	// Duplicate block
	const handleDuplicateBlock = async (blockId) => {
		const original = blocksList.find((b) => b.id === blockId);
		if (!original) return;

		try {
			const result = await createBlock();
			const { id: _, status: __, post_id: ___, error_message: ____, ...copyData } = original;
			if (result?.block) {
				const newBlock = {
					...result.block,
					...copyData,
					article_type: copyData.article_type || campaign?.article_type || 'blog_post',
					topic: copyData.topic ?? '',
					keywords: copyData.keywords ?? '',
				};
				setBlocksList((prev) => [newBlock, ...prev]);
				setIsDirty(true);
				return;
			}
			if (result?.id) {
				const newBlock = {
					...copyData,
					id: result.id,
					status: 'pending',
					article_type: copyData.article_type || campaign?.article_type || 'blog_post',
					topic: copyData.topic ?? '',
					keywords: copyData.keywords ?? '',
				};
				setBlocksList((prev) => [newBlock, ...prev]);
				setIsDirty(true);
			}
		} catch (err) {
			console.error('Failed to duplicate block:', err);
		}
	};

	// Reset single block to pending (for retry)
	const handleRetryBlock = async (blockId) => {
		if (!campaign?.webhook_id) {
			showToast('Select a webhook before retrying.', 'error');
			return;
		}

		if (retryingBlockId) return;
		setRetryingBlockId(getBlockIdKey(blockId));

		const nextBlocks = blocksList.map((block) =>
			getBlockIdKey(block.id) === getBlockIdKey(blockId)
				? { ...block, status: 'pending', error_message: null, progress: null }
				: block
		);

		try {
			await updateBlocks(nextBlocks);
			setBlocksList(nextBlocks);
			showToast('Post task set to pending.', 'info');
		} catch (err) {
			refetch();
			showToast(err?.message || 'Failed to reset post task.', 'error');
		} finally {
			setRetryingBlockId(null);
		}
	};

	const handleRetryFailedBlocks = async () => {
		if (!campaign?.webhook_id) {
			showToast('Select a webhook before retrying.', 'error');
			return;
		}

		const hasFailed = blocksList.some((block) => block.status === 'failed');
		if (!hasFailed) {
			showToast('No failed post tasks to retry.', 'info');
			return;
		}

		if (retryFailedLoading) return;
		setRetryFailedLoading(true);

		const nextBlocks = blocksList.map((block) =>
			block.status === 'failed'
				? { ...block, status: 'pending', error_message: null, progress: null }
				: block
		);

		try {
			await updateBlocks(nextBlocks);
			setBlocksList(nextBlocks);
			showToast('Failed post tasks set to pending.', 'info');
		} catch (err) {
			refetch();
			showToast(err?.message || 'Failed to reset post tasks.', 'error');
		} finally {
			setRetryFailedLoading(false);
		}
	};

	// Import blocks
	const handleImportBlocks = async (file) => {
		if (importLoading) return;
		setImportLoading(true);
		try {
			await importBlocks(file);
			refetch();
			showToast('Post tasks imported.', 'success');
		} catch (err) {
			console.error('Failed to import post tasks:', err);
			showToast(err?.message || 'Failed to import post tasks.', 'error');
		} finally {
			setImportLoading(false);
		}
	};

	// Clear completed blocks
	const handleClearCompleted = async () => {
		if (clearCompletedLoading) return;
		setClearCompletedLoading(true);
		try {
			await clearCompletedBlocks();
			setBlocksList((prev) => prev.filter((b) => b.status !== 'completed'));
			showToast('Completed post tasks cleared.', 'success');
		} catch (err) {
			console.error('Failed to clear completed:', err);
			showToast(err?.message || 'Failed to clear completed post tasks.', 'error');
		} finally {
			setClearCompletedLoading(false);
		}
	};

	// Save everything
	const handleSave = async () => {
		if (savingAll) return;
		setSavingAll(true);
		try {
			await updateCampaign({
				title: campaign.title,
				post_type: campaign.post_type,
				post_status: campaign.post_status,
				default_author_id: campaign.default_author_id,
				webhook_id: campaign.webhook_id,
				article_type: campaign.article_type,
				content_fields: campaign.content_fields,
			});

			await updateBlocks(blocksList);
			await refreshBootstrap();

			setIsDirty(false);
			showToast('Changes saved.', 'success');
		} catch (err) {
			console.error('Failed to save:', err);
			showToast(err?.message || 'Failed to save.', 'error');
		} finally {
			setSavingAll(false);
		}
	};

	const handleRun = async () => {
		if (isDirty) {
			await handleSave();
		}

		if (!campaign?.webhook_id) {
			showToast('Select a webhook before running.', 'error');
			return;
		}

		const nextBlock = blocksList.find((block) => block.status === 'pending');

		if (!nextBlock) {
			showToast('No pending post tasks to run.', 'info');
			return;
		}

		if (runLoading) return;
		setRunLoading(true);
		setIsRunning(true);
		stopRunRef.current = false;

		try {
			showToast('Run starting...', 'info');
			await runCampaign(nextBlock.id, campaign.webhook_id ?? 0);
			setBlocksList((prev) =>
				prev.map((block) =>
					getBlockIdKey(block.id) === getBlockIdKey(nextBlock.id)
						? { ...block, status: 'processing', error_message: null, progress: null }
						: block
				)
			);

			const pendingProcessing = await getPendingProcessingPostTasks(id);
			applyPendingProcessingUpdates(pendingProcessing);
		} catch (err) {
			setIsRunning(false);
			showToast(err?.message || 'Failed to start run.', 'error');
		} finally {
			setRunLoading(false);
		}
	};

	const handleStop = async () => {
		stopRunRef.current = true;
		if (stopLoading) return;
		setStopLoading(true);
		try {
			await stopCampaignRun();
			setBlocksList((prev) =>
				prev.map((b) => (b.status === 'processing' ? { ...b, status: 'cancelled' } : b))
			);
			showToast('Run stopped.', 'info');
		} catch (err) {
			showToast(err?.message || 'Failed to stop run.', 'error');
		} finally {
			setStopLoading(false);
		}
	};

	const handleExport = async () => {
		try {
			const result = await exportCampaign();
			const blob = new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = `campaign-${id}.json`;
			a.click();
			URL.revokeObjectURL(url);
			showToast('Export downloaded.', 'success');
		} catch (err) {
			console.error('Failed to export:', err);
			showToast(err?.message || 'Failed to export.', 'error');
		}
	};

	if (loading || !campaign) return <PageLoader />;

	const webhooksList = webhooksData?.webhooks || [];
	const users = data?.users || [];
	const hasTaxonomies = data?.taxonomies && Object.keys(data.taxonomies).length > 0;
	const taxonomies = hasTaxonomies ? data.taxonomies : (getTaxonomies() ?? {});

	return (
		<div className="flex flex-col xl:flex-row gap-4 lg:gap-6">
			<div className="flex-1 min-w-0">
				{/* Sticky header - below WP admin bar */}
				<div className="poststation-sticky-header sticky top-8 px-4 sm:px-8 py-3 sm:py-4 mb-4 sm:mb-6 bg-gray-50 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
					<EditableCampaignTitle
						value={campaign.title}
						onChange={handleTitleChange}
					/>
					<div className="flex flex-wrap items-center gap-2 sm:gap-3 shrink-0">
						<Button variant="secondary" onClick={handleExport}>
							Export
						</Button>
						<Button
							variant="secondary"
							onClick={handleSave}
							loading={savingAll}
							disabled={!isDirty || savingAll}
						>
							{isDirty ? 'Save Changes' : 'Saved'}
						</Button>
						{isRunning ? (
							<Button variant="danger" onClick={handleStop} loading={stopLoading}>
								Stop
							</Button>
						) : (
							<Button
								onClick={handleRun}
								loading={runLoading}
								disabled={
									runLoading ||
									blocksList.filter((b) => b.status === 'pending').length === 0
								}
							>
								Run
							</Button>
						)}
					</div>
				</div>

				{/* Campaign Settings (includes Content Fields) - Collapsible */}
				<Card className="mb-5">
					<div 
						className="px-5 py-3 border-b border-gray-200 flex items-center justify-between cursor-pointer hover:bg-gray-50 transition-colors"
						onClick={() => setShowSettings(!showSettings)}
					>
						<h3 className="text-lg font-medium text-gray-900">Campaign Settings</h3>
						<div className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700">
							{showSettings ? 'Hide' : 'Show'}
							<svg
								className={`w-4 h-4 transition-transform ${showSettings ? 'rotate-180' : ''}`}
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
							</svg>
						</div>
					</div>
					{showSettings && (
						<CardBody className="px-5 py-4 space-y-6">
							<CampaignForm
								postWork={campaign}
								onChange={handleCampaignChange}
								webhooks={webhooksList}
								users={users}
							/>
							{/* Content Fields inside same section */}
							<div className="border-t border-gray-200">
								<h4 className="text-lg font-medium text-gray-900 mb-1">Content Fields</h4>
								<p className="text-sm text-gray-500 mb-4">Configure what content to generate for each post</p>
								<ContentFieldsEditor
									postWork={campaign}
									onChange={handleCampaignChange}
									taxonomies={taxonomies}
								/>
							</div>
						</CardBody>
					)}
				</Card>

				{/* Blocks List */}
				<Card>
					<CardBody>
						<BlocksList
							blocks={blocksList}
							postWork={campaign}
							onAddBlock={handleAddBlock}
							onUpdateBlock={handleBlockUpdate}
							onDeleteBlock={handleDeleteBlock}
							onDuplicateBlock={handleDuplicateBlock}
							onRunBlock={handleRetryBlock}
							retryingBlockId={retryingBlockId}
							onRetryFailedBlocks={handleRetryFailedBlocks}
							retryFailedLoading={retryFailedLoading}
							onImportBlocks={handleImportBlocks}
							onClearCompleted={handleClearCompleted}
							loading={creatingBlock}
							importLoading={importLoading}
							clearCompletedLoading={clearCompletedLoading}
						/>
					</CardBody>
				</Card>
			</div>

			<InfoSidebar />
		</div>
	);
}
