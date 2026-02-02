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

	const handleDelete = () => {
		if (deleteId) {
			onDeleteBlock(deleteId);
			if (expandedId === deleteId) {
				setExpandedId(null);
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
	const preview = block.keyword || block.article_url || `Block #${block.id}`;

	return (
		<div className="border border-gray-200 rounded-lg overflow-hidden">
			{/* Block header */}
			<div
				className="flex items-center justify-between px-4 py-3 bg-white hover:bg-gray-50 cursor-pointer"
				onClick={onToggle}
			>
				<div className="flex items-center gap-3">
					<button className="text-gray-400">
						<svg
							className={`w-5 h-5 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
						</svg>
					</button>
					<span className="text-sm text-gray-500">#{block.id}</span>
					<span className="font-medium text-gray-900 truncate max-w-md">{preview}</span>
					<div className="flex items-center gap-2">
						<StatusBadge status={block.status} />
						{block.progress !== null && block.progress !== undefined && (
							<span className="text-[10px] text-gray-500 italic truncate max-w-[150px]">
								{String(block.progress)}
							</span>
						)}
					</div>
				</div>

				<div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
					{block.status === 'completed' && block.post_id && (
						<>
							<a
								href={`${adminUrl}post.php?post=${block.post_id}&action=edit`}
								target="_blank"
								rel="noopener noreferrer"
								className="text-sm text-indigo-600 hover:text-indigo-900"
							>
								Edit Post
							</a>
							<a
								href={`/?p=${block.post_id}`}
								target="_blank"
								rel="noopener noreferrer"
								className="text-sm text-gray-600 hover:text-gray-900"
							>
								View
							</a>
						</>
					)}
					{block.status === 'failed' && (
						<Button
							variant="secondary"
							size="sm"
							onClick={onRun}
							loading={String(retryingBlockId) === String(block.id)}
							disabled={String(retryingBlockId) === String(block.id)}
						>
							Retry
						</Button>
					)}
					<button
						onClick={onDuplicate}
						className="p-1 text-gray-400 hover:text-gray-600 rounded-md hover:bg-gray-100"
						title="Duplicate"
					>
						<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
						</svg>
					</button>
					<button
						onClick={onDelete}
						className="p-1 text-gray-400 hover:text-red-600 rounded-md hover:bg-red-50"
						title="Delete"
					>
						<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
						</svg>
					</button>
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
