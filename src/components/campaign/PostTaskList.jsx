import { useEffect, useState, useRef } from 'react';
import { Button, Select, StatusBadge, ConfirmModal } from '../common';
import PostTaskForm from './PostTaskForm';
import { getAdminUrl } from '../../api/client';

const STATUS_FILTERS = [
	{ value: '', label: 'All Statuses' },
	{ value: 'pending', label: 'Pending' },
	{ value: 'processing', label: 'Processing' },
	{ value: 'completed', label: 'Completed' },
	{ value: 'failed', label: 'Failed' },
];

export default function PostTaskList({
	tasks,
	campaign,
	onAddTask,
	onUpdateTask,
	onDeleteTask,
	onDuplicateTask,
	onRunTask,
	retryingTaskId,
	onRetryFailedTasks,
	retryFailedLoading,
	onImportTasks,
	onClearCompleted,
	loading = false,
	importLoading = false,
	clearCompletedLoading = false,
}) {
	const [filter, setFilter] = useState('');
	const [expandedId, setExpandedId] = useState(null);
	const [deleteId, setDeleteId] = useState(null);
	const [isDeleting, setIsDeleting] = useState(false);
	const [menuOpen, setMenuOpen] = useState(false);
	const importRef = useRef(null);
	const menuRef = useRef(null);

	const filteredTasks = filter
		? tasks.filter((task) => task.status === filter)
		: tasks;

	const handleImport = (e) => {
		const file = e.target.files?.[0];
		if (file) {
			onImportTasks(file);
		}
		e.target.value = '';
	};

	const handleDelete = async () => {
		if (deleteId) {
			setIsDeleting(true);
			try {
				await onDeleteTask(deleteId);
				if (expandedId === deleteId) {
					setExpandedId(null);
				}
				setDeleteId(null);
			} finally {
				setIsDeleting(false);
			}
		}
	};

	useEffect(() => {
		const onDocumentClick = (event) => {
			if (!menuRef.current?.contains(event.target)) {
				setMenuOpen(false);
			}
		};
		document.addEventListener('mousedown', onDocumentClick);
		return () => document.removeEventListener('mousedown', onDocumentClick);
	}, []);

	return (
		<div className="space-y-4">
			<div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
				<div className="flex flex-col sm:flex-row sm:items-start gap-3 flex-1">
					<h3 className="text-lg font-medium text-gray-900 pb-2">Post Tasks</h3>
					<Select
						options={STATUS_FILTERS}
						value={filter}
						onChange={(e) => setFilter(e.target.value)}
						placeholder=""
					/>
				</div>
				<div className="flex items-center gap-2 shrink-0">
					<input
						ref={importRef}
						type="file"
						accept=".json"
						className="hidden"
						onChange={handleImport}
					/>
					<Button size="sm" onClick={onAddTask} loading={loading} className="w-full sm:w-auto h-10">
						Add Post Task
					</Button>
					<div className="relative" ref={menuRef}>
						<button
							type="button"
							onClick={() => setMenuOpen((prev) => !prev)}
							className="h-10 w-10 inline-flex items-center justify-center border border-gray-300 rounded-md text-gray-600 hover:bg-gray-50"
							aria-label="Task actions"
							aria-expanded={menuOpen}
						>
							<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
							</svg>
						</button>
						{menuOpen && (
							<div className="absolute right-0 mt-1 w-52 bg-white border border-gray-200 rounded-md shadow-lg z-20 py-1">
								<button
									type="button"
									onClick={() => {
										onRetryFailedTasks();
										setMenuOpen(false);
									}}
									disabled={retryFailedLoading || !tasks.some((task) => task.status === 'failed')}
									className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
								>
									Retry Failed
								</button>
								<button
									type="button"
									onClick={() => {
										onClearCompleted();
										setMenuOpen(false);
									}}
									disabled={clearCompletedLoading || !tasks.some((task) => task.status === 'completed')}
									className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
								>
									Clear Completed
								</button>
								<button
									type="button"
									onClick={() => {
										importRef.current?.click();
										setMenuOpen(false);
									}}
									disabled={importLoading}
									className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
								>
									Import Post Tasks
								</button>
							</div>
						)}
					</div>
				</div>
			</div>

			{filteredTasks.length === 0 ? (
				<div className="text-center py-8 bg-gray-50 rounded-lg">
					<p className="text-gray-500">
						{filter ? 'No post tasks match this filter' : 'No post tasks yet. Add your first post task to get started.'}
					</p>
				</div>
			) : (
				<div className="space-y-2">
					{filteredTasks.map((task) => (
						<TaskItem
							key={task.id}
							task={task}
							campaign={campaign}
							retryingTaskId={retryingTaskId}
							isExpanded={expandedId === task.id}
							onToggle={() => setExpandedId(expandedId === task.id ? null : task.id)}
							onUpdate={(data) => onUpdateTask(task.id, data)}
							onDelete={() => setDeleteId(task.id)}
							onDuplicate={() => onDuplicateTask(task.id)}
							onRun={() => onRunTask(task.id)}
						/>
					))}
				</div>
			)}

			<ConfirmModal
				isOpen={deleteId !== null}
				onClose={() => setDeleteId(null)}
				onConfirm={handleDelete}
				loading={isDeleting}
				title="Delete Post Task"
				message="Are you sure you want to delete this post task?"
				confirmText="Delete"
			/>
		</div>
	);
}

function TaskItem({
	task,
	campaign,
	retryingTaskId,
	isExpanded,
	onToggle,
	onUpdate,
	onDelete,
	onDuplicate,
	onRun,
}) {
	const adminUrl = getAdminUrl();
	const topicValue = task.topic ?? '';
	const articleType = task.article_type || campaign?.article_type || 'blog_post';
	const showUrl = articleType === 'rewrite_blog_post' && !!task.research_url;
	const articleTypeLabel = {
		blog_post: 'Blog Post',
		listicle: 'Listicle',
		rewrite_blog_post: 'Rewrite',
	}[articleType] || 'Blog Post';

	return (
		<div className="border border-gray-200 rounded-lg overflow-hidden">
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
							<span className="text-xs font-medium text-gray-400 shrink-0">#{task.id}</span>
							{articleType !== 'rewrite_blog_post' && (
								<span className="font-semibold text-gray-900 truncate max-w-[200px] sm:max-w-md">
									{topicValue || 'No Topic'}
								</span>
							)}
							<span className="text-[10px] text-gray-600 font-medium bg-gray-100 px-1.5 py-0.5 rounded border border-gray-200">
								{articleTypeLabel}
							</span>
							<StatusBadge status={task.status} />
							{task.progress !== null && task.progress !== undefined && (
								<span className="text-[10px] text-indigo-600 font-medium bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-100 italic truncate max-w-[120px]">
									{String(task.progress)}
								</span>
							)}
						</div>
						{showUrl && (
							<div className="flex items-center gap-1 text-[11px] text-gray-500 truncate mt-0.5">
								<svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.827a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
								</svg>
								<span className="truncate">{task.research_url}</span>
							</div>
						)}
					</div>
				</div>

				<div className="flex items-center gap-1.5 sm:gap-2 shrink-0 ml-7 sm:ml-0" onClick={(e) => e.stopPropagation()}>
					{task.status === 'completed' && task.post_id && (
						<div className="flex items-center gap-1.5 mr-1 sm:mr-2">
							<a
								href={`${adminUrl}post.php?post=${task.post_id}&action=edit`}
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center px-2 py-1 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-md hover:bg-indigo-100 transition-colors"
							>
								Edit
							</a>
							<a
								href={`/?p=${task.post_id}`}
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 bg-gray-50 rounded-md hover:bg-gray-100 transition-colors"
							>
								View
							</a>
						</div>
					)}
					{task.status === 'failed' && (
						<Button
							variant="secondary"
							size="sm"
							onClick={onRun}
							className="h-7 text-[11px] px-2"
							loading={String(retryingTaskId) === String(task.id)}
							disabled={String(retryingTaskId) === String(task.id)}
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
					{task.status !== 'processing' && (
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

			{isExpanded && (
				<div className="px-4 py-4 bg-gray-50 border-t border-gray-200">
					{task.error_message && (
						<div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
							<p className="text-sm text-red-700">{task.error_message}</p>
						</div>
					)}
					<PostTaskForm
						task={task}
						campaign={campaign}
						onChange={onUpdate}
					/>
				</div>
			)}
		</div>
	);
}
