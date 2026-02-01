import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams } from 'react-router-dom';
import {
	Button,
	Card,
	CardBody,
	PageLoader,
	useToast,
} from '../components/common';
import PostWorkForm from '../components/postworks/PostWorkForm';
import ContentFieldsEditor from '../components/postworks/ContentFieldsEditor';
import BlocksList from '../components/postworks/BlocksList';
import InfoSidebar from '../components/layout/InfoSidebar';
import { postworks, blocks, webhooks, checkStatus, getTaxonomies } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

// Editable title: shows text, pen icon on hover, click to edit inline
function EditablePostWorkTitle({ value, onChange }) {
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

	const displayName = value?.trim() || 'Untitled Post Work';

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
					className="flex-1 min-w-0 text-xl font-semibold text-gray-900 bg-white border border-indigo-300 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
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

export default function PostWorkEditPage() {
	const { id } = useParams();
	const [postWork, setPostWork] = useState(null);
	const [blocksList, setBlocksList] = useState([]);
	const [showSettings, setShowSettings] = useState(false);
	const [isRunning, setIsRunning] = useState(false);
	const [isDirty, setIsDirty] = useState(false);
	const stopRunRef = useRef(false);
	const { showToast } = useToast();

	// Fetch postwork data
	const fetchPostWork = useCallback(() => postworks.getById(id), [id]);
	const { data, loading, error, refetch } = useQuery(fetchPostWork, [id]);

	// Fetch webhooks for dropdown
	const fetchWebhooks = useCallback(() => webhooks.getAll(), []);
	const { data: webhooksData } = useQuery(fetchWebhooks);

	// Mutations
	const { mutate: updatePostWork, loading: saving } = useMutation(
		(data) => postworks.update(id, data)
	);
	const { mutate: createBlock, loading: creatingBlock } = useMutation(
		() => blocks.create(id)
	);
	const { mutate: updateBlocks } = useMutation(
		(blocksData) => blocks.update(id, blocksData)
	);
	const { mutate: deleteBlock } = useMutation(blocks.delete);
	const { mutate: clearCompletedBlocks } = useMutation(
		() => blocks.clearCompleted(id)
	);
	const { mutate: importBlocks } = useMutation(
		(file) => blocks.import(id, file)
	);
	const { mutate: runPostWork } = useMutation(
		(blockId, webhookId) => postworks.run(id, blockId, webhookId)
	);
	const { mutate: exportPostWork } = useMutation(
		() => postworks.export(id)
	);

	// Initialize state from fetched data
	useEffect(() => {
		if (data) {
			setPostWork(data.postwork);
			setBlocksList(data.blocks || []);
		}
	}, [data]);

	// Handle postwork changes
	const handlePostWorkChange = (newPostWork) => {
		setPostWork(newPostWork);
		setIsDirty(true);
	};

	// Handle title change from header
	const handleTitleChange = (newTitle) => {
		handlePostWorkChange({ ...postWork, title: newTitle });
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
			if (result?.block) {
				setBlocksList((prev) => [result.block, ...prev]);
				return;
			}
			if (result?.id) {
				const newBlock = {
					id: result.id,
					status: 'pending',
					keyword: '',
					article_url: '',
					feature_image_id: null,
					feature_image_title: '',
				};
				setBlocksList((prev) => [newBlock, ...prev]);
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
				const newBlock = { ...result.block, ...copyData };
				setBlocksList((prev) => [newBlock, ...prev]);
				setIsDirty(true);
				return;
			}
			if (result?.id) {
				const newBlock = { ...copyData, id: result.id, status: 'pending' };
				setBlocksList((prev) => [newBlock, ...prev]);
				setIsDirty(true);
			}
		} catch (err) {
			console.error('Failed to duplicate block:', err);
		}
	};

	// Run single block (for retry)
	const handleRunBlock = async (blockId) => {
		handleBlockUpdate(blockId, { status: 'pending' });
		setIsDirty(true);
	};

	// Import blocks
	const handleImportBlocks = async (file) => {
		try {
			await importBlocks(file);
			refetch();
			showToast('Blocks imported.', 'success');
		} catch (err) {
			console.error('Failed to import blocks:', err);
			showToast(err?.message || 'Failed to import blocks.', 'error');
		}
	};

	// Clear completed blocks
	const handleClearCompleted = async () => {
		try {
			await clearCompletedBlocks();
			setBlocksList((prev) => prev.filter((b) => b.status !== 'completed'));
			showToast('Completed blocks cleared.', 'success');
		} catch (err) {
			console.error('Failed to clear completed:', err);
			showToast(err?.message || 'Failed to clear completed blocks.', 'error');
		}
	};

	// Save everything
	const handleSave = async () => {
		try {
			await updatePostWork({
				title: postWork.title,
				post_type: postWork.post_type,
				post_status: postWork.post_status,
				default_author_id: postWork.default_author_id,
				webhook_id: postWork.webhook_id,
				content_fields: postWork.content_fields,
			});

			await updateBlocks(blocksList);

			setIsDirty(false);
			showToast('Changes saved.', 'success');
		} catch (err) {
			console.error('Failed to save:', err);
			showToast(err?.message || 'Failed to save.', 'error');
		}
	};

	// Run postwork
	const handleRun = async () => {
		if (isDirty) {
			await handleSave();
		}

		setIsRunning(true);
		stopRunRef.current = false;

		try {
			const toRun = blocksList.filter((b) => b.status === 'pending' || b.status === 'failed');

			for (const block of toRun) {
				if (stopRunRef.current) break;

				setBlocksList((prev) =>
					prev.map((b) => (b.id === block.id ? { ...b, status: 'processing' } : b))
				);

				try {
					await runPostWork(block.id, postWork.webhook_id ?? 0);

					let attempts = 0;
					while (attempts < 60 && !stopRunRef.current) {
						await new Promise((resolve) => setTimeout(resolve, 2000));
						try {
							const status = await checkStatus(block.id);
							if (status.status === 'completed' || status.status === 'failed') {
								setBlocksList((prev) =>
									prev.map((b) =>
										b.id === block.id
											? { ...b, status: status.status, post_id: status.post_id, error_message: status.error_message }
											: b
									)
								);
								break;
							}
						} catch {
							// continue
						}
						attempts++;
					}
				} catch (err) {
					setBlocksList((prev) =>
						prev.map((b) =>
							b.id === block.id
								? { ...b, status: 'failed', error_message: err.message }
								: b
						)
					);
					showToast(err?.message || 'Block failed.', 'error');
				}
			}
		} finally {
			setIsRunning(false);
			if (stopRunRef.current) {
				showToast('Run stopped.', 'info');
			} else {
				showToast('Run complete.', 'success');
			}
		}
	};

	const handleStop = () => {
		stopRunRef.current = true;
	};

	const handleExport = async () => {
		try {
			const result = await exportPostWork();
			const blob = new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = `postwork-${id}.json`;
			a.click();
			URL.revokeObjectURL(url);
			showToast('Export downloaded.', 'success');
		} catch (err) {
			console.error('Failed to export:', err);
			showToast(err?.message || 'Failed to export.', 'error');
		}
	};

	if (loading || !postWork) return <PageLoader />;

	const webhooksList = webhooksData?.webhooks || [];
	const users = data?.users || [];
	const hasTaxonomies = data?.taxonomies && Object.keys(data.taxonomies).length > 0;
	const taxonomies = hasTaxonomies ? data.taxonomies : (getTaxonomies() ?? {});

	return (
		<div className="flex flex-col xl:flex-row gap-4 lg:gap-6">
			<div className="flex-1 min-w-0">
				{/* Sticky header - below WP admin bar */}
				<div className="poststation-sticky-header sticky top-8 px-4 sm:px-8 py-3 sm:py-4 mb-4 sm:mb-6 bg-gray-50 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
					<EditablePostWorkTitle
						value={postWork.title}
						onChange={handleTitleChange}
					/>
					<div className="flex flex-wrap items-center gap-2 sm:gap-3 shrink-0">
						<Button variant="secondary" onClick={handleExport}>
							Export
						</Button>
						<Button variant="secondary" onClick={handleSave} loading={saving} disabled={!isDirty}>
							{isDirty ? 'Save Changes' : 'Saved'}
						</Button>
						{isRunning ? (
							<Button variant="danger" onClick={handleStop}>
								Stop
							</Button>
						) : (
							<Button
								onClick={handleRun}
								disabled={blocksList.filter((b) => b.status === 'pending' || b.status === 'failed').length === 0}
							>
								Run
							</Button>
						)}
					</div>
				</div>

				{/* Post Work Settings (includes Content Fields) - Collapsible */}
				<Card className="mb-5">
					<div className="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
						<h3 className="text-lg font-medium text-gray-900">Post Work Settings</h3>
						<button
							onClick={() => setShowSettings(!showSettings)}
							className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700"
						>
							{showSettings ? 'Hide' : 'Show'}
							<svg
								className={`w-4 h-4 transition-transform ${showSettings ? 'rotate-180' : ''}`}
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
							</svg>
						</button>
					</div>
					{showSettings && (
						<CardBody className="px-5 py-4 space-y-6">
							<PostWorkForm
								postWork={postWork}
								onChange={handlePostWorkChange}
								webhooks={webhooksList}
								users={users}
							/>
							{/* Content Fields inside same section */}
							<div className="border-t border-gray-200">
								<h4 className="text-lg font-medium text-gray-900 mb-1">Content Fields</h4>
								<p className="text-sm text-gray-500 mb-4">Configure what content to generate for each post</p>
								<ContentFieldsEditor
									postWork={postWork}
									onChange={handlePostWorkChange}
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
							postWork={postWork}
							onAddBlock={handleAddBlock}
							onUpdateBlock={handleBlockUpdate}
							onDeleteBlock={handleDeleteBlock}
							onDuplicateBlock={handleDuplicateBlock}
							onRunBlock={handleRunBlock}
							onImportBlocks={handleImportBlocks}
							onClearCompleted={handleClearCompleted}
							loading={creatingBlock}
						/>
					</CardBody>
				</Card>
			</div>

			<InfoSidebar />
		</div>
	);
}
