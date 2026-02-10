import { useState, useRef } from 'react';
import { Button, Select, StatusBadge, ConfirmModal } from '../common';
import BlockForm from './BlockForm';
import { getAdminUrl } from '../../api/client';

const STATUS_FILTERS = [
	{ value: '', label: 'All Statuses' },
	{ value: 'pending', label: 'Pending' },
	{ value: 'processing', label: 'Processing' },
	{ value: 'completed', label: 'Completed' },
	{ value: 'failed', label: 'Failed' },
];

export default function BlocksList({
	blocks,
	postWork,
	onAddBlock,
	onUpdateBlock,
	onDeleteBlock,
	onDuplicateBlock,
	onRunBlock,
	retryingBlockId,
	onRetryFailedBlocks,
	retryFailedLoading,
	onImportBlocks,
	onClearCompleted,
	loading = false,
	importLoading = false,
	clearCompletedLoading = false,
}) {
	const [filter, setFilter] = useState('');
	const [expandedId, setExpandedId] = useState(null);
	const [deleteId, setDeleteId] = useState(null);
	const [isDeleting, setIsDeleting] = useState(false);
	const importRef = useRef(null);

	const filteredBlocks = filter
		? blocks.filter((b) => b.status === filter)
		: blocks;

	const handleImport = (e) => {
		const file = e.target.files?.[0];
		if (file) {
			onImportBlocks(file);
		}
		e.target.value = '';
	};

	const handleDelete = async () => {
		if (deleteId) {
			setIsDeleting(true);
			try {
				await onDeleteBlock(deleteId);
				if (expandedId === deleteId) {
					setExpandedId(null);
				}
				setDeleteId(null);
			} finally {
				setIsDeleting(false);
			}
		}
	};

	return (
		<div className="space-y-4">
			{/* Header */}
			<div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
				<h3 className="text-lg font-medium text-gray-900">Post Blocks</h3>
				<div className="flex flex-wrap items-center gap-2">
					<Select
						label="Status Filter"
						tooltip="Filter the list by block status."
						options={STATUS_FILTERS}
						value={filter}
						onChange={(e) => setFilter(e.target.value)}
						placeholder=""
						className="w-full sm:w-40"
					/>
					<input
						ref={importRef}
						type="file"
						accept=".json"
						className="hidden"
						onChange={handleImport}
					/>
					<Button
						variant="ghost"
						size="sm"
						onClick={onClearCompleted}
						loading={clearCompletedLoading}
						disabled={clearCompletedLoading || !blocks.some((b) => b.status === 'completed')}
						className="w-full sm:w-auto"
					>
						Clear Completed
					</Button>
					<Button
						variant="ghost"
						size="sm"
						onClick={onRetryFailedBlocks}
						loading={retryFailedLoading}
						disabled={retryFailedLoading || !blocks.some((b) => b.status === 'failed')}
						className="w-full sm:w-auto"
					>
						Retry Failed
					</Button>
					<Button
						variant="secondary"
						size="sm"
						onClick={() => importRef.current?.click()}
						loading={importLoading}
						disabled={importLoading}
						className="w-full sm:w-auto"
					>
						Import Blocks
					</Button>
					<Button size="sm" onClick={onAddBlock} loading={loading} className="w-full sm:w-auto">
						Add Block
					</Button>
				</div>
			</div>

			{/* Blocks list */}
			{filteredBlocks.length === 0 ? (
				<div className="text-center py-8 bg-gray-50 rounded-lg">
					<p className="text-gray-500">
						{filter ? 'No blocks match this filter' : 'No blocks yet. Add your first block to get started.'}
					</p>
				</div>
			) : (
				<div className="space-y-2">
					{filteredBlocks.map((block) => (
						<BlockItem
							key={block.id}
							block={block}
							postWork={postWork}
							retryingBlockId={retryingBlockId}
							isExpanded={expandedId === block.id}
							onToggle={() => setExpandedId(expandedId === block.id ? null : block.id)}
							onUpdate={(data) => onUpdateBlock(block.id, data)}
							onDelete={() => setDeleteId(block.id)}
							onDuplicate={() => onDuplicateBlock(block.id)}
							onRun={() => onRunBlock(block.id)}
						/>
					))}
				</div>
			)}

			<ConfirmModal
				isOpen={deleteId !== null}
				onClose={() => setDeleteId(null)}
				onConfirm={handleDelete}
				loading={isDeleting}
				title="Delete Block"
				message="Are you sure you want to delete this block?"
				confirmText="Delete"
			/>
		</div>
	);
}

function BlockItem({
	block,
	postWork,
	retryingBlockId,
	isExpanded,
	onToggle,
	onUpdate,
	onDelete,
	onDuplicate,
	onRun,
}) {
	const adminUrl = getAdminUrl();
	const topicValue = block.topic ?? '';
	const articleType = block.article_type || postWork?.article_type || 'blog_post';
	const showUrl = articleType === 'rewrite_blog_post' && !!block.research_url;
	const articleTypeLabel = {
		blog_post: 'Blog Post',
		listicle: 'Listicle',
		rewrite_blog_post: 'Rewrite',
	}[articleType] || 'Blog Post';

	return (
		<div className="border border-gray-200 rounded-lg overflow-hidden">
			{/* Block header */}
			<div
				className="flex flex-col sm:flex-row sm:items-center justify-between px-3 py-2 sm:px-4 sm:py-3 bg-white hover:bg-gray-50 cursor-pointer gap-2"
				onClick={onToggle}
			>
				<div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
					<button className="text-gray-400 shrink-0">
						<svg
							className={`w-5 h-5 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
						</svg>
					</button>
					
					<div className="flex flex-col min-w-0 flex-1">
						<div className="flex items-center gap-2 flex-wrap">
							<span className="text-xs font-medium text-gray-400 shrink-0">#{block.id}</span>
							{articleType !== 'rewrite_blog_post' && (
								<span className="font-semibold text-gray-900 truncate max-w-[200px] sm:max-w-md">
									{topicValue || 'No Topic'}
								</span>
							)}
							<span className="text-[10px] text-gray-600 font-medium bg-gray-100 px-1.5 py-0.5 rounded border border-gray-200">
								{articleTypeLabel}
							</span>
							<StatusBadge status={block.status} />
							{block.progress !== null && block.progress !== undefined && (
								<span className="text-[10px] text-indigo-600 font-medium bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-100 italic truncate max-w-[120px]">
									{String(block.progress)}
								</span>
							)}
						</div>
						{showUrl && (
							<div className="flex items-center gap-1 text-[11px] text-gray-500 truncate mt-0.5">
								<svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.827a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
								</svg>
								<span className="truncate">{block.research_url}</span>
							</div>
						)}
					</div>
				</div>

				<div className="flex items-center gap-1.5 sm:gap-2 shrink-0 ml-7 sm:ml-0" onClick={(e) => e.stopPropagation()}>
					{block.status === 'completed' && block.post_id && (
						<div className="flex items-center gap-1.5 mr-1 sm:mr-2">
							<a
								href={`${adminUrl}post.php?post=${block.post_id}&action=edit`}
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center px-2 py-1 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-md hover:bg-indigo-100 transition-colors"
							>
								Edit
							</a>
							<a
								href={`/?p=${block.post_id}`}
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 bg-gray-50 rounded-md hover:bg-gray-100 transition-colors"
							>
								View
							</a>
						</div>
					)}
					{block.status === 'failed' && (
						<Button
							variant="secondary"
							size="sm"
							onClick={onRun}
							className="h-7 text-[11px] px-2"
							loading={String(retryingBlockId) === String(block.id)}
							disabled={String(retryingBlockId) === String(block.id)}
						>
							Retry
						</Button>
					)}
					<button
						onClick={onDuplicate}
						className="p-1.5 text-gray-400 hover:text-indigo-600 rounded-md hover:bg-indigo-50 transition-colors"
						title="Duplicate"
					>
						<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
						</svg>
					</button>
					{block.status !== 'processing' && (
						<button
							onClick={onDelete}
							className="p-1.5 text-gray-400 hover:text-red-600 rounded-md hover:bg-red-50 transition-colors"
							title="Delete"
						>
							<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
							</svg>
						</button>
					)}
				</div>
			</div>

			{/* Expanded form */}
			{isExpanded && (
				<div className="px-4 py-4 bg-gray-50 border-t border-gray-200">
					{block.error_message && (
						<div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
							<p className="text-sm text-red-700">{block.error_message}</p>
						</div>
					)}
					<BlockForm
						block={block}
						postWork={postWork}
						onChange={onUpdate}
					/>
					
				</div>
			)}
		</div>
	);
}
