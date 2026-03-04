import { useState, useCallback } from 'react';
import { Button, ConfirmModal, Card, CardHeader, CardBody, PageHeader } from '../components/common';
import WritingPresetModal from '../components/writing-presets/WritingPresetModal';
import { getBootstrapWritingPresets, refreshBootstrap, writingPresets } from '../api/client';

const DEFAULT_WRITING_PRESET_KEYS = ['listicle', 'news', 'guide', 'howto'];
const isDefaultPreset = (key) => key && DEFAULT_WRITING_PRESET_KEYS.includes(key);

export default function WritingPresetsPage() {
	const [writingPresetModal, setWritingPresetModal] = useState({
		open: false,
		mode: 'add',
		writingPreset: null,
	});
	const [deleteWritingPreset, setDeleteWritingPreset] = useState(null);
	const [deleting, setDeleting] = useState(false);
	const [writingPresetsList, setWritingPresetsList] = useState(() =>
		getBootstrapWritingPresets()
	);

	const fetchWritingPresets = useCallback(async () => {
		await refreshBootstrap();
		setWritingPresetsList(getBootstrapWritingPresets());
	}, []);

	const openWritingPresetModal = (mode, writingPreset = null) => {
		setWritingPresetModal({ open: true, mode, writingPreset });
	};

	const closeWritingPresetModal = () => {
		setWritingPresetModal((prev) => ({ ...prev, open: false }));
	};

	const handleWritingPresetSaved = () => {
		setWritingPresetsList(getBootstrapWritingPresets());
	};

	const handleConfirmDeleteWritingPreset = async () => {
		if (!deleteWritingPreset?.id) return;
		setDeleting(true);
		try {
			await writingPresets.delete(deleteWritingPreset.id);
			await fetchWritingPresets();
			setDeleteWritingPreset(null);
		} catch (err) {
			console.error('Failed to delete writing preset:', err);
		} finally {
			setDeleting(false);
		}
	};

	return (
		<div>
			<PageHeader
				title="Writing Presets"
				description="Manage writing presets used for title and body generation"
				actions={
					<Button variant="primary" onClick={() => openWritingPresetModal('add')}>
						Add new
					</Button>
				}
			/>

			<Card className="max-w-5xl">
				<CardHeader>
					<div>
						<h3 className="text-lg font-medium text-gray-900">Writing presets</h3>
						<p className="text-sm text-gray-500">Create and manage reusable writing profiles.</p>
					</div>
				</CardHeader>
				<CardBody>
					<div className="space-y-4">
						{writingPresetsList.length === 0 ? (
							<div className="flex items-center justify-center py-10 px-6 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50">
								<p className="text-sm text-gray-400">No writing presets. Add one to get started.</p>
							</div>
						) : (
							<div className="flex flex-col gap-1.5 max-h-[60vh] overflow-y-auto pr-1">
								{writingPresetsList.map((inst) => (
									<div
										key={inst.id}
										className="group flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 transition-all hover:border-gray-300 hover:bg-gray-50"
									>
										<div className="min-w-0 flex-1 flex flex-col gap-0.5">
											<div className="flex items-center gap-2">
												<span className="font-medium text-sm text-gray-900 truncate">
													{inst.name}
												</span>
												{inst.key && (
													<span className="shrink-0 inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs font-mono text-gray-500">
														{inst.key}
													</span>
												)}
											</div>
										</div>

										<div className="flex items-center gap-1 shrink-0">
											<button
												type="button"
												className="poststation-icon-btn"
												onClick={() => openWritingPresetModal('edit', inst)}
												title="Edit"
												aria-label="Edit"
											>
												<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
													<path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
												</svg>
											</button>
											<button
												type="button"
												className="poststation-icon-btn"
												onClick={() => openWritingPresetModal('duplicate', inst)}
												title="Duplicate"
												aria-label="Duplicate"
											>
												<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
													<path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
												</svg>
											</button>
											{!isDefaultPreset(inst.key) && (
												<button
													type="button"
													className="poststation-icon-btn-danger"
													onClick={() => setDeleteWritingPreset(inst)}
													title="Delete"
													aria-label="Delete"
												>
													<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
														<path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
													</svg>
												</button>
											)}
										</div>
									</div>
								))}
							</div>
						)}
					</div>
				</CardBody>
			</Card>

			<WritingPresetModal
				isOpen={writingPresetModal.open}
				onClose={closeWritingPresetModal}
				mode={writingPresetModal.mode}
				writingPreset={writingPresetModal.writingPreset}
				onSaved={handleWritingPresetSaved}
			/>

			<ConfirmModal
				isOpen={Boolean(deleteWritingPreset)}
				onClose={() => setDeleteWritingPreset(null)}
				onConfirm={handleConfirmDeleteWritingPreset}
				title="Delete writing preset"
				message={deleteWritingPreset ? `Delete "${deleteWritingPreset.name}"? This cannot be undone.` : ''}
				confirmText="Delete"
				variant="danger"
				loading={deleting}
			/>
		</div>
	);
}
