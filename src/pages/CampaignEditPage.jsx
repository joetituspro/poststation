import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams } from 'react-router-dom';
import {
	Button,
	Card,
	CardBody,
	PageLoader,
	useToast,
} from '../components/common';
import CampaignForm from '../components/campaign/CampaignForm';
import ContentFieldsEditor from '../components/campaign/ContentFieldsEditor';
import PostTaskList from '../components/campaign/PostTaskList';
import InfoSidebar from '../components/layout/InfoSidebar';
import { campaigns, postTasks, webhooks, getTaxonomies, getPendingProcessingPostTasks, refreshBootstrap, getBootstrapWebhooks } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';
import { useUnsavedChanges } from '../context/UnsavedChangesContext';

const isBlank = (value) => String(value ?? '').trim() === '';

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
	const [taskItems, setTaskItems] = useState([]);
	const [showSettings, setShowSettings] = useState(false);
	const [isRunning, setIsRunning] = useState(false);
	const [isDirty, setIsDirty] = useState(false);
	const [retryingTaskId, setRetryingTaskId] = useState(null);
	const [retryFailedLoading, setRetryFailedLoading] = useState(false);
	const [savingAll, setSavingAll] = useState(false);
	const [runLoading, setRunLoading] = useState(false);
	const [stopLoading, setStopLoading] = useState(false);
	const [clearCompletedLoading, setClearCompletedLoading] = useState(false);
	const [importLoading, setImportLoading] = useState(false);
	const stopRunRef = useRef(false);
	const { showToast } = useToast();
	const { setIsDirty: setGlobalDirty } = useUnsavedChanges();

	const getTaskIdKey = useCallback((value) => String(value ?? ''), []);
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
	const { mutate: createTask, loading: creatingTask } = useMutation(
		() => postTasks.create(id)
	);
	const { mutate: updateTasks } = useMutation(
		(tasksData) => postTasks.update(id, tasksData)
	);
	const { mutate: deleteTask } = useMutation(postTasks.delete);
	const { mutate: clearCompletedTasks } = useMutation(
		() => postTasks.clearCompleted(id)
	);
	const { mutate: importTasks } = useMutation(
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
			const toneOfVoice = data.campaign?.tone_of_voice || 'none';
			const pointOfView = data.campaign?.point_of_view || 'none';
			const readability = data.campaign?.readability || 'grade_8';
			setCampaign({
				...data.campaign,
				article_type: articleType,
				language,
				target_country: targetCountry,
				tone_of_voice: toneOfVoice,
				point_of_view: pointOfView,
				readability,
			});
			setTaskItems(
				(data.tasks || []).map((task) => ({
					...task,
					article_type: task.article_type || articleType,
					topic: task.topic ?? '',
					keywords: task.keywords ?? '',
					title_override: task.title_override ?? '',
					slug_override: task.slug_override ?? '',
				}))
			);
		}
	}, [data]);

	useEffect(() => {
		const hasProcessing = taskItems.some((task) => task.status === 'processing');
		if (hasProcessing !== isRunning) {
			setIsRunning(hasProcessing);
		}
	}, [taskItems, isRunning]);

	useEffect(() => {
		setGlobalDirty(isDirty);
		return () => setGlobalDirty(false);
	}, [isDirty, setGlobalDirty]);

	// Keyboard shortcuts
	useEffect(() => {
		const handleKeyDown = (e) => {
			const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
			const modifier = isMac ? e.metaKey : e.ctrlKey;

			// Ctrl+S or Cmd+S: Save changes
			if (modifier && e.key === 's') {
				e.preventDefault();
				if (isDirty && !savingAll) {
					handleSave();
				}
			}

			// Ctrl+N or Cmd+N: Add new post task
			if (modifier && e.key === 'n') {
				e.preventDefault();
				if (!creatingTask) {
					handleAddTask();
				}
			}
		};

		window.addEventListener('keydown', handleKeyDown);
		return () => window.removeEventListener('keydown', handleKeyDown);
	}, [isDirty, savingAll, creatingTask, handleSave, handleAddTask]);

	const applyPendingProcessingUpdates = useCallback((pendingProcessing) => {
		if (!Array.isArray(pendingProcessing) || pendingProcessing.length === 0) return;
		const updatesById = new Map(pendingProcessing.map((item) => [getTaskIdKey(item.id), item]));
		setTaskItems((prev) =>
			prev.map((task) => {
				const match = updatesById.get(getTaskIdKey(task.id));
				if (!match) return task;

				// Don't overwrite local 'processing' status with 'pending' or 'failed' 
				// if the server is just slow to catch up
				if (task.status === 'processing' && match.status !== 'processing') {
					// Only allow transition to completed or failed if it's actually finished
					if (match.status !== 'completed' && match.status !== 'failed') {
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
					task.error_message === match.error_message
				) {
					return task;
				}
				return {
					...task,
					status: newStatus,
					progress: match.progress,
					post_id: match.post_id,
					error_message: match.error_message,
				};
			})
		);
	}, [getTaskIdKey]);

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
			const updatedTasks = taskItems.map((task) => ({
				...task,
				feature_image_id: null,
				feature_image_title: '',
			}));
			setTaskItems(updatedTasks);
			setIsDirty(true);
			updateTasks(updatedTasks).catch(() => {
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

	// Handle post task changes
	const handleTaskUpdate = (taskId, updates) => {
		setTaskItems((prev) =>
			prev.map((task) => (task.id === taskId ? { ...task, ...updates } : task))
		);
		setIsDirty(true);
	};

	// Add new post task
	const handleAddTask = async () => {
		try {
			const result = await createTask();
			const applyCurrentDefaults = (task) => ({
				...task,
				article_type: campaign?.article_type || 'blog_post',
				topic: task.topic ?? '',
				keywords: task.keywords ?? '',
				title_override: task.title_override ?? '',
				slug_override: task.slug_override ?? '',
			});
			if (result?.task) {
				const enrichedTask = applyCurrentDefaults(result.task);
				setTaskItems((prev) => [enrichedTask, ...prev]);
				await updateTasks([enrichedTask]);
				return;
			}
			if (result?.id) {
				const newTask = {
					id: result.id,
					status: 'pending',
					topic: '',
					keywords: '',
					article_type: campaign?.article_type || 'blog_post',
					article_url: '',
					research_url: '',
					feature_image_id: null,
					feature_image_title: '',
					title_override: '',
					slug_override: '',
				};
				const enrichedTask = applyCurrentDefaults(newTask);
				setTaskItems((prev) => [enrichedTask, ...prev]);
				await updateTasks([enrichedTask]);
			}
		} catch (err) {
			console.error('Failed to create post task:', err);
		}
	};

	const handleDeleteTask = async (taskId) => {
		try {
			await deleteTask(taskId);
			setTaskItems((prev) => prev.filter((task) => task.id !== taskId));
		} catch (err) {
			console.error('Failed to delete post task:', err);
		}
	};

	const handleDuplicateTask = async (taskId) => {
		const original = taskItems.find((task) => task.id === taskId);
		if (!original) return;

		try {
			const result = await createTask();
			const { id: _, status: __, post_id: ___, error_message: ____, ...copyData } = original;
			if (result?.task) {
				const newTask = {
					...result.task,
					...copyData,
					article_type: copyData.article_type || campaign?.article_type || 'blog_post',
					topic: copyData.topic ?? '',
					keywords: copyData.keywords ?? '',
					title_override: copyData.title_override ?? '',
					slug_override: copyData.slug_override ?? '',
				};
				setTaskItems((prev) => [newTask, ...prev]);
				setIsDirty(true);
				return;
			}
			if (result?.id) {
				const newTask = {
					...copyData,
					id: result.id,
					status: 'pending',
					article_type: copyData.article_type || campaign?.article_type || 'blog_post',
					topic: copyData.topic ?? '',
					keywords: copyData.keywords ?? '',
					title_override: copyData.title_override ?? '',
					slug_override: copyData.slug_override ?? '',
				};
				setTaskItems((prev) => [newTask, ...prev]);
				setIsDirty(true);
			}
		} catch (err) {
			console.error('Failed to duplicate post task:', err);
		}
	};

	const handleRetryTask = async (taskId) => {
		if (!campaign?.webhook_id) {
			showToast('Select a webhook before retrying.', 'error');
			return;
		}

		if (retryingTaskId) return;
		setRetryingTaskId(getTaskIdKey(taskId));

		const nextTasks = taskItems.map((task) =>
			getTaskIdKey(task.id) === getTaskIdKey(taskId)
				? { ...task, status: 'pending', error_message: null, progress: null }
				: task
		);

		try {
			await updateTasks(nextTasks);
			setTaskItems(nextTasks);
			showToast('Post task set to pending.', 'info');
		} catch (err) {
			refetch();
			showToast(err?.message || 'Failed to reset post task.', 'error');
		} finally {
			setRetryingTaskId(null);
		}
	};

	const handleRetryFailedTasks = async () => {
		if (!campaign?.webhook_id) {
			showToast('Select a webhook before retrying.', 'error');
			return;
		}

		const hasFailed = taskItems.some((task) => task.status === 'failed');
		if (!hasFailed) {
			showToast('No failed post tasks to retry.', 'info');
			return;
		}

		if (retryFailedLoading) return;
		setRetryFailedLoading(true);

		const nextTasks = taskItems.map((task) =>
			task.status === 'failed'
				? { ...task, status: 'pending', error_message: null, progress: null }
				: task
		);

		try {
			await updateTasks(nextTasks);
			setTaskItems(nextTasks);
			showToast('Failed post tasks set to pending.', 'info');
		} catch (err) {
			refetch();
			showToast(err?.message || 'Failed to reset post tasks.', 'error');
		} finally {
			setRetryFailedLoading(false);
		}
	};

	const handleImportTasks = async (file) => {
		if (importLoading) return;
		setImportLoading(true);
		try {
			await importTasks(file);
			refetch();
			showToast('Post tasks imported.', 'success');
		} catch (err) {
			console.error('Failed to import post tasks:', err);
			showToast(err?.message || 'Failed to import post tasks.', 'error');
		} finally {
			setImportLoading(false);
		}
	};

	// Clear completed post tasks
	const handleClearCompleted = async () => {
		if (clearCompletedLoading) return;
		setClearCompletedLoading(true);
		try {
			await clearCompletedTasks();
			setTaskItems((prev) => prev.filter((task) => task.status !== 'completed'));
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
		const validationErrors = [];

		if (isBlank(campaign?.title)) {
			validationErrors.push('Campaign title is required.');
		}
		if (isBlank(campaign?.article_type)) {
			validationErrors.push('Campaign Article Type is required.');
		}
		if (isBlank(campaign?.language)) {
			validationErrors.push('Campaign Language is required.');
		}
		if (isBlank(campaign?.tone_of_voice)) {
			validationErrors.push('Campaign Tone of Voice is required.');
		}
		if (isBlank(campaign?.point_of_view)) {
			validationErrors.push('Campaign Point of View is required.');
		}
		if (isBlank(campaign?.readability)) {
			validationErrors.push('Campaign Readability is required.');
		}
		if (isBlank(campaign?.target_country)) {
			validationErrors.push('Campaign Target Country is required.');
		}
		if (isBlank(campaign?.post_type)) {
			validationErrors.push('Campaign Post Type is required.');
		}
		if (isBlank(campaign?.post_status)) {
			validationErrors.push('Campaign Default Post Status is required.');
		}
		if (isBlank(campaign?.default_author_id)) {
			validationErrors.push('Campaign Default Author is required.');
		}
		if (isBlank(campaign?.webhook_id)) {
			validationErrors.push('Campaign Webhook is required.');
		}

		let contentFields = {};
		try {
			contentFields = campaign?.content_fields
				? (typeof campaign.content_fields === 'string'
					? JSON.parse(campaign.content_fields)
					: campaign.content_fields)
				: {};
		} catch {
			contentFields = {};
		}

		const customFields = Array.isArray(contentFields?.custom_fields) ? contentFields.custom_fields : [];
		customFields.forEach((field, index) => {
			if (isBlank(field?.meta_key)) {
				validationErrors.push(`Custom Field ${index + 1}: Meta Key is required.`);
			}
			if (isBlank(field?.prompt)) {
				validationErrors.push(`Custom Field ${index + 1}: Generation Prompt is required.`);
			}
			if (isBlank(field?.prompt_context)) {
				validationErrors.push(`Custom Field ${index + 1}: Prompt Context is required.`);
			}
		});

		taskItems.forEach((task) => {
			const taskType = task.article_type || campaign?.article_type || 'blog_post';
			if (taskType === 'rewrite_blog_post') {
				if (isBlank(task.research_url)) {
					validationErrors.push(`Task #${task.id}: Research URL is required for rewrite type.`);
				}
			} else if (isBlank(task.topic)) {
				validationErrors.push(`Task #${task.id}: Topic is required.`);
			}
		});

		if (validationErrors.length > 0) {
			showToast(validationErrors[0], 'error');
			return false;
		}

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
				tone_of_voice: campaign.tone_of_voice,
				point_of_view: campaign.point_of_view,
				readability: campaign.readability,
				content_fields: campaign.content_fields,
			});

			await updateTasks(taskItems);
			await refreshBootstrap();

			setIsDirty(false);
			showToast('Changes saved.', 'success');
			return true;
		} catch (err) {
			console.error('Failed to save:', err);
			showToast(err?.message || 'Failed to save.', 'error');
			return false;
		} finally {
			setSavingAll(false);
		}
	};

	const handleRun = async () => {
		if (isDirty) {
			const saved = await handleSave();
			if (!saved) {
				return;
			}
		}

		if (!campaign?.webhook_id) {
			showToast('Select a webhook before running.', 'error');
			return;
		}

		const nextTask = taskItems.find((task) => task.status === 'pending');

		if (!nextTask) {
			showToast('No pending post tasks to run.', 'info');
			return;
		}

		if (runLoading) return;
		setRunLoading(true);
		setIsRunning(true);
		stopRunRef.current = false;

		try {
			showToast('Run starting...', 'info');
			await runCampaign(nextTask.id, campaign.webhook_id ?? 0);
			setTaskItems((prev) =>
				prev.map((task) =>
					getTaskIdKey(task.id) === getTaskIdKey(nextTask.id)
						? { ...task, status: 'processing', error_message: null, progress: null }
						: task
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
			setTaskItems((prev) =>
				prev.map((task) => (task.status === 'processing' ? { ...task, status: 'cancelled' } : task))
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
				<div className="poststation-sticky-header sticky top-8 px-4 py-3 sm:py-4 mb-4 sm:mb-6 bg-gray-50 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
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
								variant="success"
								onClick={handleRun}
								loading={runLoading}
								disabled={
									runLoading ||
									taskItems.filter((task) => task.status === 'pending').length === 0
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
								campaign={campaign}
								onChange={handleCampaignChange}
								webhooks={webhooksList}
								users={users}
							/>
							{/* Content Fields inside same section */}
							<div className="border-t border-gray-200">
								<h4 className="text-lg font-medium text-gray-900">Content Fields</h4>
								<ContentFieldsEditor
									campaign={campaign}
									onChange={handleCampaignChange}
									taxonomies={taxonomies}
								/>
							</div>
						</CardBody>
					)}
				</Card>

				{/* Post Task List */}
				<Card>
					<CardBody>
						<PostTaskList
							tasks={taskItems}
							campaign={campaign}
							onAddTask={handleAddTask}
							onUpdateTask={handleTaskUpdate}
							onDeleteTask={handleDeleteTask}
							onDuplicateTask={handleDuplicateTask}
							onRunTask={handleRetryTask}
							retryingTaskId={retryingTaskId}
							onRetryFailedTasks={handleRetryFailedTasks}
							retryFailedLoading={retryFailedLoading}
							onImportTasks={handleImportTasks}
							onClearCompleted={handleClearCompleted}
							loading={creatingTask}
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
