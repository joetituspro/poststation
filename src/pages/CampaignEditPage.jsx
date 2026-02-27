import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams } from 'react-router-dom';
import {
	Button,
	Card,
	CardBody,
	PageLoader,
	RichSelect,
	useToast,
} from '../components/common';
import CampaignForm from '../components/campaign/CampaignForm';
import ContentFieldsEditor from '../components/campaign/ContentFieldsEditor';
import PostTaskList from '../components/campaign/PostTaskList';
import InfoSidebar from '../components/layout/InfoSidebar';
import WritingPresetModal from '../components/writing-presets/WritingPresetModal';
import RssFeedConfigModal from '../components/campaign/RssFeedConfigModal';
import RssResultsModal from '../components/campaign/RssResultsModal';
import {
	campaigns,
	postTasks,
	webhooks,
	generateTaskId,
	getTaxonomies,
	getPendingProcessingPostTasks,
	getBootstrapWebhooks,
	getBootstrapWritingPresets,
} from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';
import { useUnsavedChanges } from '../context/UnsavedChangesContext';
import {
	normalizeCampaignPublication,
	normalizeTaskPublication,
} from '../utils/publication';

const isBlank = ( value ) => String( value ?? '' ).trim() === '';
const DEFAULT_WRITING_PRESET_KEYS = [ 'listicle', 'news', 'guide', 'howto' ];
const isDefaultWritingPreset = ( key ) =>
	key && DEFAULT_WRITING_PRESET_KEYS.includes( key );

// Icons for writing preset options (by key)
const InstructionIcon = ( { type, className = 'w-4 h-4' } ) => {
	const c = className;
	if ( type === 'listicle' ) {
		return (
			<svg
				className={ c }
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				strokeWidth={ 2 }
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					d="M4 6h16M4 10h16M4 14h16M4 18h16"
				/>
			</svg>
		);
	}
	if ( type === 'news' ) {
		return (
			<svg
				className={ c }
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				strokeWidth={ 2 }
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"
				/>
			</svg>
		);
	}
	if ( type === 'guide' ) {
		return (
			<svg
				className={ c }
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				strokeWidth={ 2 }
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
				/>
			</svg>
		);
	}
	if ( type === 'howto' ) {
		return (
			<svg
				className={ c }
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
				strokeWidth={ 2 }
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
				/>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
				/>
			</svg>
		);
	}
	// Default icon for custom writing presets
	return (
		<svg
			className={ c }
			fill="none"
			viewBox="0 0 24 24"
			stroke="currentColor"
			strokeWidth={ 2 }
		>
			<path
				strokeLinecap="round"
				strokeLinejoin="round"
				d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
			/>
		</svg>
	);
};

// Editable title: shows text, pen icon on hover, click to edit inline
function EditableCampaignTitle( { value, onChange } ) {
	const [ isEditing, setIsEditing ] = useState( false );
	const [ editValue, setEditValue ] = useState( value || '' );
	const inputRef = useRef( null );

	useEffect( () => {
		setEditValue( value || '' );
	}, [ value ] );

	useEffect( () => {
		if ( isEditing && inputRef.current ) {
			inputRef.current.focus();
			inputRef.current.select();
		}
	}, [ isEditing ] );

	const handleBlur = () => {
		setIsEditing( false );
		const trimmed = editValue.trim();
		if ( trimmed && trimmed !== value ) {
			onChange( trimmed );
		} else {
			setEditValue( value || '' );
		}
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' ) {
			e.target.blur();
		}
		if ( e.key === 'Escape' ) {
			setEditValue( value || '' );
			setIsEditing( false );
			inputRef.current?.blur();
		}
	};

	const displayName = value?.trim() || 'Untitled Campaign';

	return (
		<div className="flex items-center min-w-0">
			{ isEditing ? (
				<input
					ref={ inputRef }
					type="text"
					value={ editValue }
					onChange={ ( e ) => setEditValue( e.target.value ) }
					onBlur={ handleBlur }
					onKeyDown={ handleKeyDown }
					className="poststation-field flex-1 min-w-0 px-3 py-1.5 text-xl font-semibold border-indigo-300 focus:border-indigo-500 focus:ring-indigo-500"
				/>
			) : (
				<button
					type="button"
					onClick={ () => setIsEditing( true ) }
					className="group flex items-center gap-2 min-w-0 rounded-lg py-1.5 px-2 -ml-2 hover:bg-gray-100 transition-colors text-left"
				>
					<span className="text-xl font-semibold text-gray-900 truncate">
						{ displayName }
					</span>
					<svg
						className="shrink-0 w-4 h-4 text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity"
						fill="none"
						viewBox="0 0 24 24"
						stroke="currentColor"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							strokeWidth={ 2 }
							d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
						/>
					</svg>
				</button>
			) }
		</div>
	);
}

export default function CampaignEditPage() {
	const { id } = useParams();
	const [ campaign, setCampaign ] = useState( null );
	const [ taskItems, setTaskItems ] = useState( [] );
	const [ showSettings, setShowSettings ] = useState( false );
	const [ isRunning, setIsRunning ] = useState( false );
	const [ isDirty, setIsDirty ] = useState( false );
	const [ retryingTaskId, setRetryingTaskId ] = useState( null );
	const [ retryFailedLoading, setRetryFailedLoading ] = useState( false );
	const [ writingPresetModal, setWritingPresetModal ] = useState( {
		open: false,
		mode: 'add',
		writingPreset: null,
	} );
	const [ rssConfigModalOpen, setRssConfigModalOpen ] = useState( false );
	const [ rssResultsModalOpen, setRssResultsModalOpen ] = useState( false );
	const [ rssResultsData, setRssResultsData ] = useState( null );
	const [ savingAll, setSavingAll ] = useState( false );
	const [ runLoading, setRunLoading ] = useState( false );
	const [ stopLoading, setStopLoading ] = useState( false );
	const [ clearCompletedLoading, setClearCompletedLoading ] =
		useState( false );
	const [ importLoading, setImportLoading ] = useState( false );
	const [ deletingTaskIds, setDeletingTaskIds ] = useState( [] );
	const stopRunRef = useRef( false );
	const manualRunRef = useRef( false );
	const currentManualTaskIdRef = useRef( null );
	const taskCountRef = useRef( 0 );
	const campaignRef = useRef( null );

	const hasRequiredData = useCallback( ( task ) => {
		const taskType = task?.campaign_type ?? 'default';
		if ( taskType === 'rewrite_blog_post' ) {
			return String( task?.research_url ?? '' ).trim() !== '';
		}
		return String( task?.topic ?? '' ).trim() !== '';
	}, [] );

	const getNextPendingTask = useCallback( () => {
		return (
			taskItems.find(
				( t ) => t.status === 'pending' && hasRequiredData( t )
			) ?? null
		);
	}, [ taskItems, hasRequiredData ] );
	const { showToast } = useToast();
	const { setIsDirty: setGlobalDirty } = useUnsavedChanges();

	const getTaskIdKey = useCallback( ( value ) => String( value ?? '' ), [] );
	const fetchCampaign = useCallback( () => campaigns.getById( id ), [ id ] );
	const { data, loading, error, refetch } = useQuery( fetchCampaign, [ id ] );

	// Fetch webhooks for dropdown
	const bootstrapWebhooks = getBootstrapWebhooks();
	const fetchWebhooks = useCallback( () => webhooks.getAll(), [] );
	const { data: webhooksData } = useQuery( fetchWebhooks, [], {
		initialData: bootstrapWebhooks,
	} );

	// Mutations
	const { mutate: updateCampaign, loading: saving } = useMutation( ( data ) =>
		campaigns.update( id, data )
	);
	const { mutate: createTask, loading: creatingTask } = useMutation(
		( clientId ) =>
			postTasks.create( id, clientId != null ? { id: clientId } : {} )
	);
	const { mutate: updateTasks } = useMutation( ( tasksData ) =>
		postTasks.update( id, tasksData )
	);
	const { mutate: deleteTask } = useMutation( postTasks.delete );
	const { mutate: clearCompletedTasks } = useMutation( () =>
		postTasks.clearCompleted( id )
	);
	const { mutate: importTasks } = useMutation( ( file ) =>
		postTasks.import( id, file )
	);
	const { mutate: runCampaign } = useMutation( ( taskId, webhookId ) =>
		campaigns.run( id, taskId, webhookId )
	);
	const { mutate: stopCampaignRun } = useMutation( () =>
		campaigns.stopRun( id )
	);
	const { mutate: exportCampaign } = useMutation( () =>
		campaigns.export( id )
	);

	// Initialize state from fetched data
	useEffect( () => {
		if ( data ) {
			const campaignType = data.campaign?.campaign_type || 'default';
			const language = data.campaign?.language || 'en';
			const targetCountry =
				data.campaign?.target_country || 'international';
			const toneOfVoice = data.campaign?.tone_of_voice || 'none';
			const pointOfView = data.campaign?.point_of_view || 'none';
			const readability = data.campaign?.readability || 'grade_8';
			const normalizedCampaign = normalizeCampaignPublication( {
				...data.campaign,
				campaign_type: campaignType,
				language,
				target_country: targetCountry,
				tone_of_voice: toneOfVoice,
				point_of_view: pointOfView,
				readability,
			} );
			setCampaign( normalizedCampaign );
			setTaskItems(
				( data.tasks || [] ).map( ( task ) => ( {
					...normalizeTaskPublication( task, normalizedCampaign ),
					campaign_type: task.campaign_type || campaignType,
					topic: task.topic ?? '',
					keywords: task.keywords ?? '',
					title_override: task.title_override ?? '',
					slug_override: task.slug_override ?? '',
				} ) )
			);
		}
	}, [ data ] );

	useEffect( () => {
		const hasProcessing = taskItems.some(
			( task ) => task.status === 'processing'
		);
		if ( hasProcessing !== isRunning ) {
			setIsRunning( hasProcessing );
		}
	}, [ taskItems, isRunning ] );

	useEffect( () => {
		setGlobalDirty( isDirty );
		return () => setGlobalDirty( false );
	}, [ isDirty, setGlobalDirty ] );

	// Keyboard shortcuts
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			const isMac =
				navigator.platform.toUpperCase().indexOf( 'MAC' ) >= 0;
			const modifier = isMac ? e.metaKey : e.ctrlKey;

			// Ctrl+S or Cmd+S: Save changes
			if ( modifier && e.key === 's' ) {
				e.preventDefault();
				if ( isDirty && ! savingAll ) {
					handleSave();
				}
			}

			// Ctrl+N or Cmd+N: Add new post task
			if ( modifier && e.key === 'n' ) {
				e.preventDefault();
				if ( ! creatingTask ) {
					handleAddTask();
				}
			}
		};

		window.addEventListener( 'keydown', handleKeyDown );
		return () => window.removeEventListener( 'keydown', handleKeyDown );
	}, [ isDirty, savingAll, creatingTask, handleSave, handleAddTask ] );

	const applyPendingProcessingUpdates = useCallback(
		( pendingProcessing ) => {
			if (
				! Array.isArray( pendingProcessing ) ||
				pendingProcessing.length === 0
			)
				return;
			const updatesById = new Map(
				pendingProcessing.map( ( item ) => [
					getTaskIdKey( item.id ),
					item,
				] )
			);
			setTaskItems( ( prev ) =>
				prev.map( ( task ) => {
					const match = updatesById.get( getTaskIdKey( task.id ) );
					if ( ! match ) return task;

					// Don't overwrite local 'processing' status with 'pending' or 'failed'
					// if the server is just slow to catch up
					if (
						task.status === 'processing' &&
						match.status !== 'processing'
					) {
						// Only allow transition to completed or failed if it's actually finished
						if (
							match.status !== 'completed' &&
							match.status !== 'failed'
						) {
							return task;
						}
					}

					const newStatus =
						match.status === 'processing' && match.error_message
							? 'failed'
							: match.status;

					if (
						task.status === newStatus &&
						task.progress === match.progress &&
						task.post_id === match.post_id &&
						task.error_message === match.error_message &&
						task.scheduled_publication_date ===
							match.scheduled_publication_date &&
						task.post_date === match.post_date &&
						task.wp_post_status === match.wp_post_status
					) {
						return task;
					}
					return {
						...task,
						status: newStatus,
						progress: match.progress,
						post_id: match.post_id,
						error_message: match.error_message,
						scheduled_publication_date:
							match.scheduled_publication_date,
						post_date: match.post_date,
						wp_post_status: match.wp_post_status,
					};
				} )
			);
		},
		[ getTaskIdKey ]
	);

	useEffect( () => {
		taskCountRef.current = taskItems.length;
	}, [ taskItems.length ] );
	useEffect( () => {
		campaignRef.current = campaign;
	}, [ campaign ] );

	// In active (manual) mode, when current task completes/fails, start next pending or end run
	useEffect( () => {
		if (
			! manualRunRef.current ||
			! currentManualTaskIdRef.current ||
			campaign?.status === 'active'
		)
			return;
		if ( stopRunRef.current ) {
			manualRunRef.current = false;
			currentManualTaskIdRef.current = null;
			return;
		}
		const currentId = currentManualTaskIdRef.current;
		const currentTask = taskItems.find(
			( t ) => getTaskIdKey( t.id ) === currentId
		);
		if (
			! currentTask ||
			( currentTask.status !== 'completed' &&
				currentTask.status !== 'failed' )
		)
			return;

		const nextTask = taskItems.find(
			( t ) => t.status === 'pending' && hasRequiredData( t )
		);
		if ( ! nextTask ) {
			manualRunRef.current = false;
			currentManualTaskIdRef.current = null;
			setIsRunning( false );
			return;
		}

		currentManualTaskIdRef.current = getTaskIdKey( nextTask.id );
		setTaskItems( ( prev ) =>
			prev.map( ( task ) =>
				getTaskIdKey( task.id ) === getTaskIdKey( nextTask.id )
					? {
							...task,
							status: 'processing',
							error_message: null,
							progress: null,
					  }
					: task
			)
		);
		runCampaign( nextTask.id, campaign?.webhook_id ?? 0 );
	}, [
		taskItems,
		campaign?.status,
		campaign?.webhook_id,
		runCampaign,
		getTaskIdKey,
		hasRequiredData,
	] );

	useEffect( () => {
		let cancelled = false;

		const refreshPendingProcessing = async () => {
			try {
				const lastCount = taskCountRef.current;
				const data = await getPendingProcessingPostTasks(
					id,
					lastCount
				);
				if ( cancelled ) return;
				const taskList = Array.isArray( data )
					? data
					: data?.tasks ?? [];
				applyPendingProcessingUpdates( taskList );
				if (
					data?.new_tasks_available &&
					Array.isArray( data?.full_tasks ) &&
					data.full_tasks.length > 0
				) {
					setTaskItems( ( prev ) => {
						const prevIds = new Set(
							prev.map( ( t ) => getTaskIdKey( t.id ) )
						);
						const campaignData = campaignRef.current;
						const campaignType =
							campaignData?.campaign_type ?? 'default';
						const toAdd = data.full_tasks
							.filter(
								( t ) => ! prevIds.has( getTaskIdKey( t.id ) )
							)
							.map( ( task ) => ( {
								...normalizeTaskPublication(
									task,
									campaignData || {}
								),
								campaign_type:
									task.campaign_type || campaignType,
								topic: task.topic ?? '',
								keywords: task.keywords ?? '',
								title_override: task.title_override ?? '',
								slug_override: task.slug_override ?? '',
							} ) );
						return toAdd.length ? [ ...toAdd, ...prev ] : prev;
					} );
				}
			} catch {
				// ignore polling errors
			}
		};

		refreshPendingProcessing();
		const interval = setInterval( refreshPendingProcessing, 5000 );

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [ id, applyPendingProcessingUpdates, getTaskIdKey ] );

	// Handle campaign changes
	const handleCampaignChange = ( newCampaign ) => {
		if ( newCampaign?.clear_image_overrides ) {
			const updatedTasks = taskItems.map( ( task ) => ( {
				...task,
				feature_image_id: null,
				feature_image_title: '',
			} ) );
			setTaskItems( updatedTasks );
			setIsDirty( true );
			updateTasks( updatedTasks ).catch( () => {
				refetch();
			} );
			const { clear_image_overrides: _, ...rest } = newCampaign;
			setCampaign( rest );
			return;
		}

		setCampaign( newCampaign );
		setIsDirty( true );
	};

	const handleWritingPresetSelect = ( id ) => {
		const nextPresetId = id ?? null;
		const presets = getBootstrapWritingPresets() || [];
		const selectedPreset =
			nextPresetId != null
				? presets.find(
						( p ) => Number( p.id ) === Number( nextPresetId )
				  )
				: null;

		let contentFieldsRaw = {};
		try {
			if ( campaign?.content_fields ) {
				contentFieldsRaw =
					typeof campaign.content_fields === 'string'
						? JSON.parse( campaign.content_fields )
						: campaign.content_fields;
			}
		} catch {
			contentFieldsRaw = {};
		}

		let contentFields = {
			...contentFieldsRaw,
		};

		if ( selectedPreset ) {
			const instructions = selectedPreset.instructions || {};
			const titleInstruction = String( instructions.title ?? '' );
			const bodyInstruction = String( instructions.body ?? '' );

			const existingTitlePrompt = String(
				contentFields?.title?.prompt ?? ''
			).trim();
			const existingBodyPrompt = String(
				contentFields?.body?.prompt ?? ''
			).trim();

			const willOverrideTitle =
				titleInstruction.trim() !== '' &&
				existingTitlePrompt !== '' &&
				existingTitlePrompt !== titleInstruction;
			const willOverrideBody =
				bodyInstruction.trim() !== '' &&
				existingBodyPrompt !== '' &&
				existingBodyPrompt !== bodyInstruction;

			if ( willOverrideTitle || willOverrideBody ) {
				const ok = window.confirm(
					'Applying this preset will replace the existing campaign title and body instructions. Do you wish to proceed?'
				);
				if ( ! ok ) {
					return;
				}
			}

			if ( titleInstruction.trim() !== '' ) {
				contentFields.title = {
					...( contentFields.title || {} ),
					prompt: titleInstruction,
				};
			}
			if ( bodyInstruction.trim() !== '' ) {
				contentFields.body = {
					...( contentFields.body || {} ),
					prompt: bodyInstruction,
				};
			}
		} else {
			const existingTitlePrompt = String(
				contentFields?.title?.prompt ?? ''
			).trim();
			const existingBodyPrompt = String(
				contentFields?.body?.prompt ?? ''
			).trim();

			const hasAnyPrompt =
				existingTitlePrompt !== '' || existingBodyPrompt !== '';

			if ( hasAnyPrompt ) {
				const ok = window.confirm(
					'Removing the writing preset will clear the existing campaign title and body instructions. Do you wish to proceed?'
				);
				if ( ! ok ) {
					return;
				}

				if ( contentFields.title ) {
					contentFields.title = {
						...( contentFields.title || {} ),
						prompt: '',
					};
				}
				if ( contentFields.body ) {
					contentFields.body = {
						...( contentFields.body || {} ),
						prompt: '',
					};
				}
			}
		}

		handleCampaignChange( {
			...campaign,
			writing_preset_id: nextPresetId,
			content_fields: JSON.stringify( contentFields ),
		} );
	};

	// Handle title change from header
	const handleTitleChange = ( newTitle ) => {
		handleCampaignChange( { ...campaign, title: newTitle } );
	};

	// Handle post task changes
	const handleTaskUpdate = ( taskId, updates ) => {
		setTaskItems( ( prev ) =>
			prev.map( ( task ) =>
				task.id === taskId ? { ...task, ...updates } : task
			)
		);
		setIsDirty( true );
	};

	// Add new post task (optimistic: show row with client-generated id, server stores it)
	const applyCurrentDefaults = useCallback(
		( task ) => ( {
			...normalizeTaskPublication( task, campaign || {} ),
			publication_override: false,
			publication_mode: campaign?.publication_mode || 'pending_review',
			campaign_type: campaign?.campaign_type || 'default',
			topic: task.topic ?? '',
			keywords: task.keywords ?? '',
			title_override: task.title_override ?? '',
			slug_override: task.slug_override ?? '',
		} ),
		[ campaign ]
	);

	const handleAddTask = () => {
		const newId = generateTaskId();
		const optimisticTask = {
			id: newId,
			status: 'pending',
			topic: '',
			keywords: '',
			campaign_type: campaign?.campaign_type || 'default',
			publication_override: false,
			publication_mode: campaign?.publication_mode || 'pending_review',
			article_url: '',
			research_url: '',
			feature_image_id: null,
			feature_image_title: '',
			title_override: '',
			slug_override: '',
		};
		setTaskItems( ( prev ) => [ optimisticTask, ...prev ] );

		createTask( newId )
			.then( ( result ) => {
				const apply = applyCurrentDefaults;
				const enrichedTask = result?.task
					? apply( { ...result.task, id: newId } )
					: apply( { ...optimisticTask, id: newId } );
				setTaskItems( ( prev ) =>
					prev.map( ( t ) => ( t.id === newId ? enrichedTask : t ) )
				);
			} )
			.catch( ( err ) => {
				setTaskItems( ( prev ) =>
					prev.filter( ( t ) => t.id !== newId )
				);
				showToast( err?.message ?? 'Failed to create task.', 'error' );
			} );
	};

	const handleDeleteTask = async ( taskId ) => {
		if (
			! window.confirm(
				'Are you sure you want to delete this post task?'
			)
		)
			return;
		setDeletingTaskIds( ( prev ) => [ ...prev, taskId ] );
		try {
			await deleteTask( taskId );
			setTaskItems( ( prev ) =>
				prev.filter( ( task ) => task.id !== taskId )
			);
		} catch ( err ) {
			showToast( err?.message ?? 'Failed to delete post task.', 'error' );
		} finally {
			setDeletingTaskIds( ( prev ) =>
				prev.filter( ( id ) => id !== taskId )
			);
		}
	};

	const handleDuplicateTask = ( taskId ) => {
		const original = taskItems.find( ( task ) => task.id === taskId );
		if ( ! original ) return;

		const newId = generateTaskId();
		const {
			id: _id,
			post_id: _pid,
			error_message: _err,
			status: _status,
			...copyData
		} = original;
		const optimisticCopy = {
			...copyData,
			id: newId,
			status: 'pending',
			...normalizeTaskPublication( copyData, campaign || {} ),
			campaign_type:
				copyData.campaign_type || campaign?.campaign_type || 'default',
			topic: copyData.topic ?? '',
			keywords: copyData.keywords ?? '',
			title_override: copyData.title_override ?? '',
			slug_override: copyData.slug_override ?? '',
		};
		setTaskItems( ( prev ) => [ optimisticCopy, ...prev ] );
		setIsDirty( true );

		createTask( newId )
			.then( ( result ) => {
				const newTask = result?.task
					? {
							...result.task,
							...copyData,
							id: newId,
							status: result.task.status ?? 'pending',
							...normalizeTaskPublication(
								copyData,
								campaign || {}
							),
							campaign_type:
								copyData.campaign_type ||
								campaign?.campaign_type ||
								'default',
							topic: copyData.topic ?? '',
							keywords: copyData.keywords ?? '',
							title_override: copyData.title_override ?? '',
							slug_override: copyData.slug_override ?? '',
					  }
					: {
							...copyData,
							id: newId,
							status: 'pending',
							...normalizeTaskPublication(
								copyData,
								campaign || {}
							),
							campaign_type:
								copyData.campaign_type ||
								campaign?.campaign_type ||
								'default',
							topic: copyData.topic ?? '',
							keywords: copyData.keywords ?? '',
							title_override: copyData.title_override ?? '',
							slug_override: copyData.slug_override ?? '',
					  };
				setTaskItems( ( prev ) =>
					prev.map( ( t ) => ( t.id === newId ? newTask : t ) )
				);
				setIsDirty( true );
			} )
			.catch( ( err ) => {
				setTaskItems( ( prev ) =>
					prev.filter( ( t ) => t.id !== newId )
				);
				showToast(
					err?.message ?? 'Failed to duplicate task.',
					'error'
				);
			} );
	};

	const handleRetryTask = async ( taskId ) => {
		if ( ! campaign?.webhook_id ) {
			showToast( 'Select a webhook before retrying.', 'error' );
			return;
		}

		if ( retryingTaskId ) return;
		setRetryingTaskId( getTaskIdKey( taskId ) );

		const nextTasks = taskItems.map( ( task ) =>
			getTaskIdKey( task.id ) === getTaskIdKey( taskId )
				? {
						...task,
						status: 'pending',
						error_message: null,
						progress: null,
				  }
				: task
		);

		try {
			await updateTasks( nextTasks );
			setTaskItems( nextTasks );
			showToast( 'Post task set to pending.', 'info' );
		} catch ( err ) {
			showToast( err?.message || 'Failed to reset post task.', 'error' );
		} finally {
			setRetryingTaskId( null );
		}
	};

	const handleRetryFailedTasks = async () => {
		if ( ! campaign?.webhook_id ) {
			showToast( 'Select a webhook before retrying.', 'error' );
			return;
		}

		const hasFailed = taskItems.some(
			( task ) => task.status === 'failed'
		);
		if ( ! hasFailed ) {
			showToast( 'No failed post tasks to retry.', 'info' );
			return;
		}

		if ( retryFailedLoading ) return;
		setRetryFailedLoading( true );

		const nextTasks = taskItems.map( ( task ) =>
			task.status === 'failed'
				? {
						...task,
						status: 'pending',
						error_message: null,
						progress: null,
				  }
				: task
		);

		try {
			await updateTasks( nextTasks );
			setTaskItems( nextTasks );
			showToast( 'Failed post tasks set to pending.', 'info' );
		} catch ( err ) {
			showToast( err?.message || 'Failed to reset post tasks.', 'error' );
		} finally {
			setRetryFailedLoading( false );
		}
	};

	const handleImportTasks = async ( file ) => {
		if ( importLoading ) return;
		setImportLoading( true );
		try {
			await importTasks( file );
			refetch();
			showToast( 'Post tasks imported.', 'success' );
		} catch ( err ) {
			console.error( 'Failed to import post tasks:', err );
			showToast(
				err?.message || 'Failed to import post tasks.',
				'error'
			);
		} finally {
			setImportLoading( false );
		}
	};

	// Clear completed post tasks
	const handleClearCompleted = async () => {
		if ( clearCompletedLoading ) return;
		setClearCompletedLoading( true );
		try {
			await clearCompletedTasks();
			setTaskItems( ( prev ) =>
				prev.filter( ( task ) => task.status !== 'completed' )
			);
			showToast( 'Completed post tasks cleared.', 'success' );
		} catch ( err ) {
			console.error( 'Failed to clear completed:', err );
			showToast(
				err?.message || 'Failed to clear completed post tasks.',
				'error'
			);
		} finally {
			setClearCompletedLoading( false );
		}
	};

	// Save everything
	const handleSave = async ( overrides = {} ) => {
		const validationErrors = [];

		if ( isBlank( campaign?.title ) ) {
			validationErrors.push( 'Campaign title is required.' );
		}
		if ( isBlank( campaign?.campaign_type ) ) {
			validationErrors.push( 'Campaign Type is required.' );
		}
		if ( isBlank( campaign?.language ) ) {
			validationErrors.push( 'Campaign Language is required.' );
		}
		if ( isBlank( campaign?.tone_of_voice ) ) {
			validationErrors.push( 'Campaign Tone of Voice is required.' );
		}
		if ( isBlank( campaign?.point_of_view ) ) {
			validationErrors.push( 'Campaign Point of View is required.' );
		}
		if ( isBlank( campaign?.readability ) ) {
			validationErrors.push( 'Campaign Readability is required.' );
		}
		if ( isBlank( campaign?.target_country ) ) {
			validationErrors.push( 'Campaign Target Country is required.' );
		}
		if ( isBlank( campaign?.post_type ) ) {
			validationErrors.push( 'Campaign Post Type is required.' );
		}
		if ( isBlank( campaign?.publication_mode ) ) {
			validationErrors.push( 'Campaign Publication is required.' );
		}
		if (
			( campaign?.publication_mode || 'pending_review' ) ===
			'publish_intervals'
		) {
			const intervalValue = parseInt(
				campaign?.publication_interval_value ?? 0,
				10
			);
			if ( ! intervalValue || intervalValue < 1 ) {
				validationErrors.push(
					'Campaign Interval Value must be at least 1.'
				);
			}
			if (
				! [ 'minute', 'hour' ].includes(
					campaign?.publication_interval_unit || ''
				)
			) {
				validationErrors.push(
					'Campaign Interval Period must be minute or hour.'
				);
			}
		}
		if (
			( campaign?.publication_mode || 'pending_review' ) ===
			'rolling_schedule'
		) {
			const days = parseInt( campaign?.rolling_schedule_days ?? 0, 10 );
			if ( ! [ 7, 14, 30, 60 ].includes( days ) ) {
				validationErrors.push(
					'Campaign Rolling Schedule range must be 7, 14, 30, or 60 days.'
				);
			}
		}
		if ( isBlank( campaign?.default_author_id ) ) {
			validationErrors.push( 'Campaign Default Author is required.' );
		}
		if ( isBlank( campaign?.webhook_id ) ) {
			validationErrors.push( 'Campaign Webhook is required.' );
		}

		let contentFields = {};
		try {
			contentFields = campaign?.content_fields
				? typeof campaign.content_fields === 'string'
					? JSON.parse( campaign.content_fields )
					: campaign.content_fields
				: {};
		} catch {
			contentFields = {};
		}

		const customFields = Array.isArray( contentFields?.custom_fields )
			? contentFields.custom_fields
			: [];
		customFields.forEach( ( field, index ) => {
			if ( isBlank( field?.meta_key ) ) {
				validationErrors.push(
					`Custom Field ${ index + 1 }: Meta Key is required.`
				);
			}
			if ( isBlank( field?.prompt ) ) {
				validationErrors.push(
					`Custom Field ${
						index + 1
					}: Generation Prompt is required.`
				);
			}
			if ( isBlank( field?.prompt_context ) ) {
				validationErrors.push(
					`Custom Field ${ index + 1 }: Prompt Context is required.`
				);
			}
		} );

		taskItems.forEach( ( task ) => {
			const taskType =
				task.campaign_type || campaign?.campaign_type || 'default';
			if ( taskType === 'rewrite_blog_post' ) {
				if ( isBlank( task.research_url ) ) {
					validationErrors.push(
						`Task #${ task.id }: Research URL is required for rewrite type.`
					);
				}
			} else if ( isBlank( task.topic ) ) {
				validationErrors.push(
					`Task #${ task.id }: Topic is required.`
				);
			}

			// If manually title is provided, slug cannot be empty
			if (
				! isBlank( task.title_override ) &&
				isBlank( task.slug_override )
			) {
				validationErrors.push(
					`Task #${ task.id }: Slug Override is required when a Title Override is provided.`
				);
			}

			const hasPublicationOverride =
				task.publication_override === true ||
				String( task.publication_override ?? '0' ) === '1';
			const taskPublicationMode = hasPublicationOverride
				? task.publication_mode || 'pending_review'
				: task.publication_mode ||
				  campaign?.publication_mode ||
				  'pending_review';
			if (
				hasPublicationOverride &&
				taskPublicationMode === 'set_date'
			) {
				if ( isBlank( task.publication_date ) ) {
					validationErrors.push(
						`Task #${ task.id }: Publication Date is required when Task Publication Mode is Set a Date.`
					);
				}
			}
		} );

		if ( validationErrors.length > 0 ) {
			showToast( validationErrors[ 0 ], 'error' );
			return false;
		}

		if ( savingAll ) return;
		setSavingAll( true );
		const rssOverride = overrides?.rss_config != null;
		const statusValue = overrides?.status ?? campaign.status ?? 'paused';

		// Ensure title/body prompts are filled from preset when selected but empty
		let contentFieldsForSave = campaign.content_fields;
		try {
			const presetId = campaign.writing_preset_id ?? null;
			if ( presetId ) {
				const presets = getBootstrapWritingPresets() || [];
				const preset = presets.find(
					( p ) => Number( p.id ) === Number( presetId )
				);
				if ( preset ) {
					const instructions = preset.instructions || {};
					const titleInstruction = String(
						instructions.title ?? ''
					).trim();
					const bodyInstruction = String(
						instructions.body ?? ''
					).trim();

					let parsedFields = {};
					if ( campaign.content_fields ) {
						parsedFields =
							typeof campaign.content_fields === 'string'
								? JSON.parse( campaign.content_fields )
								: campaign.content_fields;
					}

					const titlePrompt = String(
						parsedFields?.title?.prompt ?? ''
					).trim();
					const bodyPrompt = String(
						parsedFields?.body?.prompt ?? ''
					).trim();

					let changed = false;
					if ( titlePrompt === '' && titleInstruction !== '' ) {
						parsedFields.title = {
							...( parsedFields.title || {} ),
							prompt: titleInstruction,
						};
						changed = true;
					}
					if ( bodyPrompt === '' && bodyInstruction !== '' ) {
						parsedFields.body = {
							...( parsedFields.body || {} ),
							prompt: bodyInstruction,
						};
						changed = true;
					}

					if ( changed ) {
						contentFieldsForSave = JSON.stringify( parsedFields );
					}
				}
			}
		} catch {
			// If anything goes wrong parsing/merging, fall back to existing content_fields
			contentFieldsForSave = campaign.content_fields;
		}

		const payload = {
			title: campaign.title,
			post_type: campaign.post_type,
			publication_mode: campaign.publication_mode,
			publication_interval_value: campaign.publication_interval_value,
			publication_interval_unit: campaign.publication_interval_unit,
			rolling_schedule_days: campaign.rolling_schedule_days,
			default_author_id: campaign.default_author_id,
			webhook_id: campaign.webhook_id,
			campaign_type: campaign.campaign_type,
			tone_of_voice: campaign.tone_of_voice,
			point_of_view: campaign.point_of_view,
			readability: campaign.readability,
			language: campaign.language,
			target_country: campaign.target_country,
			writing_preset_id: campaign.writing_preset_id ?? null,
			content_fields: contentFieldsForSave,
			rss_enabled: rssOverride ? 'yes' : campaign.rss_enabled ?? 'no',
			rss_config: overrides?.rss_config ?? campaign.rss_config ?? null,
			status: statusValue,
		};
		try {
			await Promise.all( [
				updateCampaign( payload ),
				updateTasks( taskItems ),
			] );

			if ( rssOverride ) {
				setCampaign( ( prev ) => ( {
					...prev,
					rss_enabled: 'yes',
					rss_config: overrides.rss_config,
				} ) );
			}
			if ( overrides?.status != null ) {
				setCampaign( ( prev ) => ( {
					...prev,
					status: overrides.status,
				} ) );
			}
			setIsDirty( false );
			showToast( 'Changes saved.', 'success' );
			return true;
		} catch ( err ) {
			console.error( 'Failed to save:', err );
			showToast( err?.message || 'Failed to save.', 'error' );
			return false;
		} finally {
			setSavingAll( false );
		}
	};

	const handleRun = async () => {
		if ( isDirty ) {
			const saved = await handleSave();
			if ( ! saved ) {
				return;
			}
		}

		if ( ! campaign?.webhook_id ) {
			showToast( 'Select a webhook before running.', 'error' );
			return;
		}

		const nextTask = getNextPendingTask();
		if ( ! nextTask ) {
			showToast( 'No pending post tasks to run.', 'info' );
			return;
		}

		if ( runLoading ) return;
		setRunLoading( true );
		setIsRunning( true );
		stopRunRef.current = false;

		const isLive = campaign?.status === 'active';
		if ( ! isLive ) {
			manualRunRef.current = true;
			currentManualTaskIdRef.current = getTaskIdKey( nextTask.id );
		}

		try {
			showToast( 'Run starting...', 'info' );
			await runCampaign( nextTask.id, campaign.webhook_id ?? 0 );
			setTaskItems( ( prev ) =>
				prev.map( ( task ) =>
					getTaskIdKey( task.id ) === getTaskIdKey( nextTask.id )
						? {
								...task,
								status: 'processing',
								error_message: null,
								progress: null,
						  }
						: task
				)
			);

			const pollData = await getPendingProcessingPostTasks( id );
			const pendingProcessing = Array.isArray( pollData )
				? pollData
				: pollData?.tasks ?? [];
			applyPendingProcessingUpdates( pendingProcessing );
		} catch ( err ) {
			setIsRunning( false );
			if ( ! isLive ) {
				manualRunRef.current = false;
				currentManualTaskIdRef.current = null;
			}
			showToast( err?.message || 'Failed to start run.', 'error' );
		} finally {
			setRunLoading( false );
		}
	};

	const handleStop = async () => {
		stopRunRef.current = true;
		manualRunRef.current = false;
		currentManualTaskIdRef.current = null;
		if ( stopLoading ) return;
		setStopLoading( true );
		try {
			await stopCampaignRun();
			setTaskItems( ( prev ) =>
				prev.map( ( task ) =>
					task.status === 'processing'
						? { ...task, status: 'cancelled' }
						: task
				)
			);
			showToast( 'Run stopped.', 'info' );
		} catch ( err ) {
			showToast( err?.message || 'Failed to stop run.', 'error' );
		} finally {
			setStopLoading( false );
		}
	};

	const handleExport = async () => {
		try {
			const result = await exportCampaign();
			const blob = new Blob( [ JSON.stringify( result.data, null, 2 ) ], {
				type: 'application/json',
			} );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = `campaign-${ id }.json`;
			a.click();
			URL.revokeObjectURL( url );
			showToast( 'Export downloaded.', 'success' );
		} catch ( err ) {
			console.error( 'Failed to export:', err );
			showToast( err?.message || 'Failed to export.', 'error' );
		}
	};

	if ( loading || ! campaign ) return <PageLoader />;

	const webhooksList = webhooksData?.webhooks || [];
	const writingPresets = getBootstrapWritingPresets() || [];
	const users = data?.users || [];
	const hasTaxonomies =
		data?.taxonomies && Object.keys( data.taxonomies ).length > 0;
	const taxonomies = hasTaxonomies ? data.taxonomies : getTaxonomies() ?? {};

	return (
		<div className="flex flex-col xl:flex-row gap-4 lg:gap-6">
			<div className="flex-1 min-w-0">
				{ /* Sticky header - below WP admin bar */ }
				<div className="poststation-sticky-header sticky top-8 px-4 py-3 sm:py-4 mb-4 sm:mb-6 bg-gray-50 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
					<div className="flex items-center gap-3 min-w-0">
						<EditableCampaignTitle
							value={ campaign.title }
							onChange={ handleTitleChange }
						/>
						<label
							className="poststation-switch flex items-center gap-2 shrink-0 cursor-pointer"
							title="When Live is on, pending tasks run automatically without clicking Run. New or saved tasks start immediately."
						>
							<input
								type="checkbox"
								className="poststation-field-checkbox"
								checked={ campaign?.status === 'active' }
								disabled={ savingAll }
								onChange={ async ( e ) => {
									const newStatus = e.target.checked
										? 'active'
										: 'paused';
									const previousStatus =
										campaign?.status ?? 'paused';
									setCampaign( ( prev ) =>
										prev
											? { ...prev, status: newStatus }
											: prev
									);
									const saved = await handleSave( {
										status: newStatus,
									} );
									if ( ! saved ) {
										setCampaign( ( prev ) =>
											prev
												? {
														...prev,
														status: previousStatus,
												  }
												: prev
										);
									}
								} }
							/>
							<span className="poststation-switch-track" />
							<span
								className="text-sm font-medium text-gray-700 whitespace-nowrap"
								title="When Live is on, pending tasks run automatically without clicking Run. New or saved tasks start immediately."
							>
								Live
							</span>
						</label>
					</div>
					<div className="flex flex-wrap items-center gap-2 sm:gap-3 shrink-0">
						<Button variant="secondary" onClick={ handleExport }>
							Export
						</Button>
						<Button
							variant="secondary"
							onClick={ handleSave }
							loading={ savingAll }
							disabled={ ! isDirty || savingAll }
						>
							{ isDirty ? 'Save Changes' : 'Saved' }
						</Button>
						{ campaign?.status === 'active' ? (
							<span
								className={ `text-sm font-medium ${
									! isDirty
										? 'text-green-600'
										: 'text-gray-500'
								}` }
								title={
									! isDirty
										? 'Campaign is live; tasks run automatically.'
										: 'Save to activate live mode.'
								}
							>
								Running
							</span>
						) : isRunning ? (
							<Button
								variant="danger"
								onClick={ handleStop }
								loading={ stopLoading }
							>
								Stop
							</Button>
						) : (
							<Button
								variant="success"
								onClick={ handleRun }
								loading={ runLoading }
								disabled={
									runLoading ||
									taskItems.filter(
										( task ) => task.status === 'pending'
									).length === 0
								}
							>
								Run
							</Button>
						) }
					</div>
				</div>

				{ /* Campaign Settings (includes Content Fields) - Collapsible */ }
				<Card className="mb-5">
					<div
						className="px-5 py-3 border-b border-gray-200 flex items-center justify-between cursor-pointer hover:bg-gray-50 transition-colors"
						onClick={ () => setShowSettings( ! showSettings ) }
					>
						<h3 className="text-lg font-medium text-gray-900">
							Campaign Settings
						</h3>
						<div className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700">
							{ showSettings ? 'Hide' : 'Show' }
							<svg
								className={ `w-4 h-4 transition-transform ${
									showSettings ? 'rotate-180' : ''
								}` }
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path
									strokeLinecap="round"
									strokeLinejoin="round"
									strokeWidth={ 2 }
									d="M19 9l-7 7-7-7"
								/>
							</svg>
						</div>
					</div>
					{ showSettings && (
						<CardBody className="px-5 py-4 space-y-6">
							<CampaignForm
								campaign={ campaign }
								onChange={ handleCampaignChange }
								webhooks={ webhooksList }
								users={ users }
							/>
							{ /* RSS Feeds section - above Writing preset */ }
							{ campaign?.rss_enabled === 'yes' && (
								<div className="border-t border-gray-200 pt-6">
									<div className="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50/50 px-4 py-3">
										<div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
											<svg
												className="h-4 w-4"
												fill="currentColor"
												viewBox="0 0 24 24"
												aria-hidden
											>
												<path d="M6.18 15.64a2.18 2.18 0 0 1 2.18 2.18C8.36 19 7.38 20 6.18 20C5 20 4 19 4 17.82a2.18 2.18 0 0 1 2.18-2.18M4 4.44A15.56 15.56 0 0 1 19.56 20h-2.83A12.73 12.73 0 0 0 4 7.27V4.44m0 5.66a9.9 9.9 0 0 1 9.9 9.9h-2.83A7.07 7.07 0 0 0 4 12.93V10.1Z" />
											</svg>
										</div>
										<div className="min-w-0 flex-1">
											<div className="flex flex-wrap items-center gap-2">
												<span className="text-sm font-medium text-gray-900">
													RSS Feeds
												</span>
												{ campaign?.rss_config?.sources
													?.length > 0 && (
													<span className="inline-flex items-center rounded-md bg-gray-200/80 px-2 py-0.5 text-xs font-medium text-gray-700">
														{ ( () => {
															const m =
																campaign
																	.rss_config
																	.frequency_interval;
															const labels = {
																15: 'Every 15 min',
																60: 'Hourly',
																360: 'Every 6 h',
																1440: 'Daily',
															};
															return (
																labels[ m ] ||
																`Every ${ m } min`
															);
														} )() }
													</span>
												) }
											</div>
											{ campaign?.rss_config?.sources
												?.length > 0 ? (
												<ul className="mt-1.5 space-y-0.5">
													{ campaign.rss_config.sources
														.slice( 0, 5 )
														.map( ( s, i ) => (
															<li
																key={ i }
																className="text-xs text-gray-600 truncate"
																title={
																	s.feed_url ||
																	''
																}
															>
																{ s.feed_url ||
																	'—' }
															</li>
														) ) }
													{ campaign.rss_config
														.sources.length > 5 && (
														<li className="text-xs text-gray-500">
															+
															{ campaign
																.rss_config
																.sources
																.length -
																5 }{ ' ' }
															more
														</li>
													) }
												</ul>
											) : (
												<p className="mt-1 text-xs text-gray-500">
													No feeds configured. Add
													feed URLs to run RSS checks.
												</p>
											) }
										</div>
										<Button
											type="button"
											variant="secondary"
											size="sm"
											onClick={ () =>
												setRssConfigModalOpen( true )
											}
											className="shrink-0"
										>
											Set Feeds
										</Button>
									</div>
								</div>
							) }
							{ /* Content Fields inside same section */ }
							<div className="border-t border-gray-200 pt-6">
								<div className="mb-4">
									<RichSelect
										label="Writing preset"
										tooltip="Optional writing preset for title, body, and section generation. Selecting a preset can copy its instructions into the Title and Body prompts."
										labelAction={
											<Button
												type="button"
												variant="secondary"
												size="sm"
												onClick={ () =>
													setWritingPresetModal( {
														open: true,
														mode: 'add',
														writingPreset: null,
													} )
												}
											>
												Add new preset
											</Button>
										}
										options={ writingPresets.map(
											( i ) => ( {
												value: Number( i.id ),
												label: i.name,
												description:
													i.description || '',
												icon: (
													<InstructionIcon
														type={ i.key }
														className="w-4 h-4"
													/>
												),
												instructions: i.instructions,
												sourcePreset: i,
											} )
										) }
										value={
											campaign?.writing_preset_id ?? null
										}
										onChange={ handleWritingPresetSelect }
										getOptionAction={ ( option ) => {
											const preset =
												option.sourcePreset || null;
											if (
												! preset ||
												isDefaultWritingPreset(
													preset.key
												)
											) {
												return null;
											}
											return {
												title: 'Edit preset',
												ariaLabel: `Edit ${ option.label }`,
												icon: (
													<svg
														className="w-4 h-4"
														fill="none"
														viewBox="0 0 24 24"
														stroke="currentColor"
														strokeWidth={ 2 }
													>
														<path
															strokeLinecap="round"
															strokeLinejoin="round"
															d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
														/>
													</svg>
												),
												onClick: () =>
													setWritingPresetModal( {
														open: true,
														mode: 'edit',
														writingPreset: preset,
													} ),
											};
										} }
										placeholder="None"
									/>
								</div>
								<ContentFieldsEditor
									campaign={ campaign }
									onChange={ handleCampaignChange }
									taxonomies={ taxonomies }
								/>
							</div>
						</CardBody>
					) }
				</Card>

				{ /* Post Task List */ }
				<Card>
					<CardBody>
						<PostTaskList
							tasks={ taskItems }
							campaign={ campaign }
							onAddTask={ handleAddTask }
							onUpdateTask={ handleTaskUpdate }
							onDeleteTask={ handleDeleteTask }
							onDuplicateTask={ handleDuplicateTask }
							onRunTask={ handleRetryTask }
							retryingTaskId={ retryingTaskId }
							onRetryFailedTasks={ handleRetryFailedTasks }
							retryFailedLoading={ retryFailedLoading }
							onImportTasks={ handleImportTasks }
							onClearCompleted={ handleClearCompleted }
							loading={ creatingTask }
							importLoading={ importLoading }
							clearCompletedLoading={ clearCompletedLoading }
							deletingTaskIds={ deletingTaskIds }
						/>
					</CardBody>
				</Card>
			</div>

			<InfoSidebar />

			<WritingPresetModal
				isOpen={ writingPresetModal.open }
				onClose={ () =>
					setWritingPresetModal( ( prev ) => ( {
						...prev,
						open: false,
					} ) )
				}
				mode={ writingPresetModal.mode }
				writingPreset={ writingPresetModal.writingPreset }
				showSaveAndApply={ writingPresetModal.mode === 'edit' }
				onSaved={ () => {} }
				onSaveAndApply={ ( result ) => {
					const savedId = result?.id ?? null;
					if ( savedId != null ) {
						handleWritingPresetSelect( savedId );
					}
				} }
			/>
			<RssFeedConfigModal
				isOpen={ rssConfigModalOpen }
				onClose={ () => setRssConfigModalOpen( false ) }
				campaign={ campaign }
				onSave={ () => refetch() }
				onRunNowComplete={ ( responseData ) => {
					setRssResultsData( responseData ?? null );
					setRssResultsModalOpen( true );
				} }
				onRssConfigChange={ ( rssConfig ) => {
					handleCampaignChange( {
						...campaign,
						rss_enabled: 'yes',
						rss_config: rssConfig,
					} );
				} }
				onTriggerSave={ handleSave }
			/>
			<RssResultsModal
				isOpen={ rssResultsModalOpen }
				onClose={ () => {
					setRssResultsModalOpen( false );
					setRssResultsData( null );
				} }
				data={ rssResultsData }
				campaignId={ id }
				onAddedToTasks={ ( newTasks ) => {
					if ( ! Array.isArray( newTasks ) || newTasks.length === 0 )
						return;
					const campaignType = campaign?.campaign_type ?? 'default';
					const normalized = newTasks.map( ( task ) => ( {
						...normalizeTaskPublication( task, campaign || {} ),
						campaign_type: task.campaign_type || campaignType,
						topic: task.topic ?? '',
						keywords: task.keywords ?? '',
						title_override: task.title_override ?? '',
						slug_override: task.slug_override ?? '',
					} ) );
					setTaskItems( ( prev ) => [ ...normalized, ...prev ] );
				} }
			/>
		</div>
	);
}
