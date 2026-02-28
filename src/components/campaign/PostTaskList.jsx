import { useEffect, useState, useRef } from 'react';
import { Button, Select, StatusBadge } from '../common';
import PostTaskForm from './PostTaskForm';
import { getAdminUrl } from '../../api/client';
import { PUBLICATION_MODE_LABELS } from '../../utils/publication';

const STATUS_FILTERS = [
	{ value: '', label: 'All Statuses' },
	{ value: 'pending', label: 'Pending' },
	{ value: 'processing', label: 'Processing' },
	{ value: 'completed', label: 'Completed' },
	{ value: 'failed', label: 'Failed' },
];

export default function PostTaskList( {
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
	deletingTaskIds = [],
} ) {
	const [ filter, setFilter ] = useState( '' );
	const [ expandedId, setExpandedId ] = useState( null );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const importRef = useRef( null );
	const menuRef = useRef( null );

	const filteredTasks = filter
		? tasks.filter( ( task ) => task.status === filter )
		: tasks;

	const handleImport = ( e ) => {
		const file = e.target.files?.[ 0 ];
		if ( file ) {
			onImportTasks( file );
		}
		e.target.value = '';
	};

	useEffect( () => {
		const onDocumentClick = ( event ) => {
			if ( ! menuRef.current?.contains( event.target ) ) {
				setMenuOpen( false );
			}
		};
		document.addEventListener( 'mousedown', onDocumentClick );
		return () =>
			document.removeEventListener( 'mousedown', onDocumentClick );
	}, [] );

	return (
		<div className="space-y-4">
			<div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
				<div className="flex flex-col sm:flex-row sm:items-start gap-3 flex-1">
					<h3 className="text-lg font-medium text-gray-900 pb-2">
						Post Tasks
					</h3>
					<Select
						options={ STATUS_FILTERS }
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
						placeholder=""
					/>
				</div>
				<div className="flex items-center gap-2 shrink-0">
					<input
						ref={ importRef }
						type="file"
						accept=".json"
						className="hidden"
						onChange={ handleImport }
					/>
					<Button
						size="sm"
						onClick={ onAddTask }
						loading={ loading }
						className="w-full sm:w-auto h-10"
					>
						Add Post Task
					</Button>
					<div className="relative" ref={ menuRef }>
						<button
							type="button"
							onClick={ () => setMenuOpen( ( prev ) => ! prev ) }
							className="h-10 w-10 inline-flex items-center justify-center border border-gray-300 rounded-md text-gray-600 hover:bg-gray-50"
							aria-label="Task actions"
							aria-expanded={ menuOpen }
						>
							<svg
								className="w-5 h-5"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									strokeLinecap="round"
									strokeLinejoin="round"
									strokeWidth={ 2 }
									d="M4 6h16M4 12h16M4 18h16"
								/>
							</svg>
						</button>
						{ menuOpen && (
							<div className="absolute right-0 mt-1 w-52 bg-white border border-gray-200 rounded-md shadow-lg z-20 py-1">
								<button
									type="button"
									onClick={ () => {
										onRetryFailedTasks();
										setMenuOpen( false );
									} }
									disabled={
										retryFailedLoading ||
										! tasks.some(
											( task ) => task.status === 'failed'
										)
									}
									className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
								>
									Retry Failed
								</button>
								<button
									type="button"
									onClick={ () => {
										onClearCompleted();
										setMenuOpen( false );
									} }
									disabled={
										clearCompletedLoading ||
										! tasks.some(
											( task ) =>
												task.status === 'completed'
										)
									}
									className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
								>
									Clear Completed
								</button>
								<button
									type="button"
									onClick={ () => {
										importRef.current?.click();
										setMenuOpen( false );
									} }
									disabled={ importLoading }
									className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
								>
									Import Post Tasks
								</button>
							</div>
						) }
					</div>
				</div>
			</div>

			{ filteredTasks.length === 0 ? (
				<div className="text-center py-8 bg-gray-50 rounded-lg">
					<p className="text-gray-500">
						{ filter
							? 'No post tasks match this filter'
							: 'No post tasks yet. Add your first post task to get started.' }
					</p>
				</div>
			) : (
				<div className="space-y-2">
					{ filteredTasks.map( ( task ) => (
						<TaskItem
							key={ task.id }
							task={ task }
							campaign={ campaign }
							retryingTaskId={ retryingTaskId }
							isExpanded={ expandedId === task.id }
							onToggle={ () =>
								setExpandedId(
									expandedId === task.id ? null : task.id
								)
							}
							onUpdate={ ( data ) =>
								onUpdateTask( task.id, data )
							}
							onDelete={ () => onDeleteTask( task.id ) }
							onDuplicate={ () => onDuplicateTask( task.id ) }
							onRun={ () => onRunTask( task.id ) }
							isDeleting={ deletingTaskIds.some(
								( id ) => String( id ) === String( task.id )
							) }
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

function TaskItem( {
	task,
	campaign,
	retryingTaskId,
	isExpanded,
	onToggle,
	onUpdate,
	onDelete,
	onDuplicate,
	onRun,
	isDeleting = false,
} ) {
	const formatDateTime = ( value ) => {
		if ( ! value ) return '';
		const date = new Date( String( value ).replace( ' ', 'T' ) );
		if ( Number.isNaN( date.getTime() ) ) return String( value );
		return date.toLocaleString( [], {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} );
	};

	const adminUrl = getAdminUrl();
	const topicValue = task.topic ?? '';
	const researchUrl = task.research_url ?? '';
	const campaignType =
		task.campaign_type || campaign?.campaign_type || 'default';
	const isRewrite = campaignType === 'rewrite_blog_post';
	const isPublished = !! task.post_id;
	const primaryLabel = isRewrite
		? ( researchUrl || '' ).replace( /^https?:\/\//i, '' )
		: topicValue || 'No Topic';
	const showSubRow = isPublished
		? task.post_title || task.title_override || topicValue || 'Post'
		: false;
	const campaignTypeLabel =
		{
			default: 'Default',
			rewrite_blog_post: 'Rewrite',
		}[ campaignType ] || 'Default';
	const hasPublicationOverride =
		task.publication_override === true ||
		String( task.publication_override ?? '0' ) === '1';
	const publicationMode = hasPublicationOverride
		? task.publication_mode || 'pending_review'
		: task.publication_mode ||
		  campaign?.publication_mode ||
		  'pending_review';
	const publicationModeLabel =
		PUBLICATION_MODE_LABELS[ publicationMode ] || 'Pending Review';
	const isScheduledPost = task.wp_post_status === 'future';
	const completedDateTimeLabel = isScheduledPost
		? 'Scheduled for'
		: 'Published on';
	const completedDateTimeValue =
		task.scheduled_publication_date || task.post_date || '';

	return (
		<div className="border border-gray-200 rounded-lg overflow-hidden">
			<div
				className="flex flex-col sm:flex-row sm:items-center justify-between px-3 py-2 sm:px-4 sm:py-3 bg-white hover:bg-gray-50 cursor-pointer gap-2"
				onClick={ onToggle }
			>
				<div className="flex items-center gap-3 sm:gap-4 min-w-0 flex-1">
					<button className="text-gray-400 shrink-0">
						<svg
							className={ `w-5 h-5 transition-transform ${
								isExpanded ? 'rotate-90' : ''
							}` }
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								strokeWidth={ 2 }
								d="M9 5l7 7-7 7"
							/>
						</svg>
					</button>

					<div className="flex flex-col min-w-0 flex-1 space-y-0.5">
						<div className="text-[10px] font-medium text-gray-400 shrink-0">
							#{ task.id }
							<span className="mx-2 text-gray-600 font-medium">
								·
							</span>
							<span className="text-gray-600">
								{ campaignTypeLabel }
							</span>
							{ task.status !== 'completed' && (
								<>
									<span className="mx-2 text-gray-600 font-medium">
										·
									</span>
									<span className="text-gray-600">
										{ publicationModeLabel }
									</span>
								</>
							) }
							{ task.status === 'completed' &&
								completedDateTimeValue && (
									<>
										<span className="mx-2 text-gray-600 font-medium">
											·
										</span>
										<span
											className={ `inline-flex items-center gap-1 leading-none m-0 p-0 font-medium ${
												isScheduledPost
													? 'text-amber-700'
													: 'text-gray-900'
											}` }
										>
											{ isScheduledPost && (
												<svg
													className="w-3 h-2 leading-none m-0 p-0 shrink-0"
													fill="none"
													viewBox="0 0 24 24"
													stroke="currentColor"
													title="Scheduled post date/time"
												>
													<path
														strokeLinecap="round"
														strokeLinejoin="round"
														strokeWidth={ 2 }
														d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"
													/>
												</svg>
											) }
											{ ! isScheduledPost && (
												<svg
													className="w-3 h-2 leading-none m-0 p-0 shrink-0 text-green-600"
													fill="none"
													viewBox="0 0 24 24"
													stroke="currentColor"
													title="Published post date/time"
												>
													<path
														strokeLinecap="round"
														strokeLinejoin="round"
														strokeWidth={ 2 }
														d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"
													/>
												</svg>
											) }
											<span className="truncate">
												{ formatDateTime(
													completedDateTimeValue
												) }
											</span>
										</span>
									</>
								) }
							<span className="mx-2 text-gray-600 font-medium">
								·
							</span>
							<StatusBadge status={ task.status } />
						</div>
						<div className="flex flex-wrap items-center gap-2 mt-0.5 min-w-0">
							<span className="text-[14px] font-semibold text-gray-900 truncate">
								{ primaryLabel }
							</span>
							{ task.progress !== null &&
								task.progress !== undefined &&
								task.status !== 'completed' && (
									<span className="text-[10px] text-indigo-600 font-medium bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-100 italic min-w-0 break-words whitespace-normal">
										{ String( task.progress ) }
									</span>
								) }
						</div>
						{ showSubRow && (
							<div className="flex items-center gap-1 text-[11px] text-gray-500 truncate mt-0.5">
								<svg
									className="w-3 h-3 shrink-0"
									fill="none"
									viewBox="0 0 24 24"
									stroke="currentColor"
								>
									<path
										strokeLinecap="round"
										strokeLinejoin="round"
										strokeWidth={ 2 }
										d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.827a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"
									/>
								</svg>
								<span className="truncate">{ showSubRow }</span>
							</div>
						) }
					</div>
				</div>

				<div
					className="flex flex-col items-end gap-1 shrink-0 ml-7 sm:ml-0"
					onClick={ ( e ) => e.stopPropagation() }
				>
					<div className="flex items-center gap-1.5 sm:gap-2">
						{ task.status === 'completed' && task.post_id && (
							<>
								<a
									href={ `${ adminUrl }post.php?post=${ task.post_id }&action=edit` }
									target="_blank"
									rel="noopener noreferrer"
									className="inline-flex items-center px-2 py-1 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-md hover:bg-indigo-100 transition-colors"
								>
									Edit
								</a>
								<a
									href={ `/?p=${ task.post_id }` }
									target="_blank"
									rel="noopener noreferrer"
									className="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 bg-gray-50 rounded-md hover:bg-gray-100 transition-colors"
								>
									View
								</a>
							</>
						) }
						{ task.status === 'failed' && (
							<Button
								variant="secondary"
								size="sm"
								onClick={ onRun }
								className="h-7 text-[11px] px-2"
								loading={
									String( retryingTaskId ) ===
									String( task.id )
								}
								disabled={
									String( retryingTaskId ) ===
									String( task.id )
								}
							>
								Retry
							</Button>
						) }
						<button
							onClick={ onDuplicate }
							className="p-1.5 text-gray-400 hover:text-indigo-600 rounded-md hover:bg-indigo-50 transition-colors"
							title="Duplicate"
						>
							<svg
								className="w-4 h-4"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									strokeLinecap="round"
									strokeLinejoin="round"
									strokeWidth={ 2 }
									d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"
								/>
							</svg>
						</button>
						{ task.status !== 'processing' && (
							<button
								onClick={ onDelete }
								disabled={ isDeleting }
								className="p-1.5 text-gray-400 hover:text-red-600 rounded-md hover:bg-red-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
								title="Delete"
							>
								{ isDeleting ? (
									<svg
										className="w-4 h-4 animate-spin"
										fill="none"
										viewBox="0 0 24 24"
									>
										<circle
											className="opacity-25"
											cx="12"
											cy="12"
											r="10"
											stroke="currentColor"
											strokeWidth="4"
										/>
										<path
											className="opacity-75"
											fill="currentColor"
											d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
										/>
									</svg>
								) : (
									<svg
										className="w-4 h-4"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={ 2 }
											d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
										/>
									</svg>
								) }
							</button>
						) }
					</div>
				</div>
			</div>

			{ isExpanded && (
				<div className="px-4 py-4 bg-gray-50 border-t border-gray-200">
					{ task.error_message && (
						<div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
							<p className="text-sm text-red-700">
								{ task.error_message }
							</p>
						</div>
					) }
					{ task.status === 'completed' ? (
						<div className="text-sm text-gray-700 space-y-1">
							<p>
								<span className="font-medium text-gray-600">
									Campaign Type:
								</span>{ ' ' }
								{ campaignTypeLabel }
							</p>
							<p>
								<span className="font-medium text-gray-600">
									Publication:
								</span>{ ' ' }
								{ publicationModeLabel }
							</p>
							{ completedDateTimeValue && (
								<p className="inline-flex items-center gap-1">
									{ isScheduledPost ? (
										<svg
											className="w-3.5 h-3.5 text-amber-700"
											fill="none"
											viewBox="0 0 24 24"
											stroke="currentColor"
											title="Scheduled post date/time"
										>
											<path
												strokeLinecap="round"
												strokeLinejoin="round"
												strokeWidth={ 2 }
												d="M8 7V3m8 4V3m-9 8h10m-11 9h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v11a2 2 0 002 2z"
											/>
										</svg>
									) : (
										<svg
											className="w-3.5 h-3.5 text-green-600"
											fill="none"
											viewBox="0 0 24 24"
											stroke="currentColor"
											title="Published post date/time"
										>
											<path
												strokeLinecap="round"
												strokeLinejoin="round"
												strokeWidth={ 2 }
												d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"
											/>
										</svg>
									) }
									<span>
										{ completedDateTimeLabel }:{ ' ' }
										{ formatDateTime(
											completedDateTimeValue
										) }
									</span>
								</p>
							) }
							{ isRewrite ? (
								<p>
									<span className="font-medium text-gray-600">
										Research URL:
									</span>{ ' ' }
									{ researchUrl || '-' }
								</p>
							) : (
								<p>
									<span className="font-medium text-gray-600">
										Topic:
									</span>{ ' ' }
									{ topicValue || '-' }
								</p>
							) }
							<p>
								<span className="font-medium text-gray-600">
									Keywords:
								</span>{ ' ' }
								{ ( task.keywords ?? '' ).trim() || '-' }
							</p>
							{ ( task.title_override ?? '' ).trim() && (
								<p>
									<span className="font-medium text-gray-600">
										Title Override:
									</span>{ ' ' }
									{ task.title_override }
								</p>
							) }
							{ ( task.slug_override ?? '' ).trim() && (
								<p>
									<span className="font-medium text-gray-600">
										Slug Override:
									</span>{ ' ' }
									{ task.slug_override }
								</p>
							) }
							{ task.feature_image_id && (
								<p>
									<span className="font-medium text-gray-600">
										Featured Image:
									</span>{ ' ' }
									ID { task.feature_image_id }
								</p>
							) }
							{ ( task.feature_image_title ?? '' ).trim() &&
								task.feature_image_title !== '{{title}}' && (
									<p>
										<span className="font-medium text-gray-600">
											Featured Image Title:
										</span>{ ' ' }
										{ task.feature_image_title }
									</p>
								) }
						</div>
					) : (
						<PostTaskForm
							task={ task }
							campaign={ campaign }
							onChange={ onUpdate }
						/>
					) }
				</div>
			) }
		</div>
	);
}
