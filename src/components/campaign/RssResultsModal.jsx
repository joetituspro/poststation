import { useState, useMemo } from 'react';
import { Button, Modal, useToast } from '../common';
import { campaigns } from '../../api/client';

function getDomain(url) {
	if (!url || typeof url !== 'string') return '—';
	try {
		const u = new URL(url.trim());
		return u.hostname.replace(/^www\./, '') || '—';
	} catch {
		return '—';
	}
}

function flattenItems(data) {
	const items = [];
	// Webhook may return { sources: [...] } or a top-level array of sources
	const sourcesList = Array.isArray(data) ? data : data?.sources;
	if (!sourcesList || !Array.isArray(sourcesList)) {
		return items;
	}
	sourcesList.forEach((source) => {
		const sourceId = source.source_id;
		const feedUrl = source.feed_url ?? '';
		const list = source.items ?? [];
		list.forEach((item) => {
			const url = item.url ?? '';
			items.push({
				source_id: sourceId,
				feed_url: feedUrl,
				title: item.title ?? '',
				url,
				date: item.date ?? '',
				domain: getDomain(url),
			});
		});
	});
	return items;
}

export default function RssResultsModal({
	isOpen,
	onClose,
	data,
	campaignId,
	onAddedToTasks,
}) {
	const { showToast } = useToast();
	const [adding, setAdding] = useState(false);
	const [selected, setSelected] = useState({});

	const items = useMemo(() => flattenItems(data), [data]);
	const selectedCount = Object.values(selected).filter(Boolean).length;

	const toggleAll = (checked) => {
		if (checked) {
			const next = {};
			items.forEach((item, i) => {
				if (item.url) next[i] = true;
			});
			setSelected(next);
		} else {
			setSelected({});
		}
	};

	const toggleOne = (index) => {
		setSelected((prev) => ({
			...prev,
			[index]: !prev[index],
		}));
	};

	const handleAddToTasks = async () => {
		const toAdd = items.filter((_, i) => selected[i] && items[i].url);
		if (toAdd.length === 0) {
			showToast('Select at least one item.', 'error');
			return;
		}
		setAdding(true);
		try {
			const result = await campaigns.rssAddToTasks(campaignId, toAdd);
			const count = result?.count ?? 0;
			const newTasks = result?.tasks ?? [];
			showToast(`Added ${count} task(s) to the campaign.`, 'success');
			onAddedToTasks?.(newTasks, count);
			onClose();
		} catch (err) {
			showToast(err?.message || 'Failed to add tasks.', 'error');
		} finally {
			setAdding(false);
		}
	};

	if (!isOpen) return null;

	const status = data?.status ?? '';
	const hasItems = items.length > 0;

	return (
		<Modal isOpen={isOpen} onClose={onClose} title="RSS feed results" size="xl">
			<div className="px-6 pb-6 space-y-4">
				{status && (
					<p className="text-sm text-gray-600">
						<strong>Status:</strong> {status}
					</p>
				)}
				{!hasItems ? (
					<p className="text-sm text-gray-500">No items returned from the feed(s).</p>
				) : (
					<>
						<div className="flex items-center justify-between gap-2 flex-wrap">
							<label className="flex items-center gap-2 text-xs text-gray-600">
								<input
									type="checkbox"
									checked={selectedCount === items.filter((i) => i.url).length && selectedCount > 0}
									onChange={(e) => toggleAll(e.target.checked)}
									className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 focus:ring-1"
								/>
								Select all
							</label>
							<span className="text-xs text-gray-500 tabular-nums">
								{selectedCount} selected · {items.length} items
							</span>
						</div>
						<div className="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden max-h-112 overflow-y-auto">
							<table className="min-w-full text-xs">
								<thead className="sticky top-0 z-10 bg-gray-50/95 backdrop-blur border-b border-gray-200">
									<tr>
										<th className="w-8 pl-2.5 pr-1.5 py-1.5 text-left">
											<span className="sr-only">Select</span>
										</th>
										<th className="w-9 pl-1.5 pr-2 py-1.5 text-right font-medium text-gray-500 uppercase tracking-wider">
											#
										</th>
										<th className="w-32 pl-1.5 pr-2 py-1.5 text-left font-medium text-gray-500 uppercase tracking-wider">
											Domain
										</th>
										<th className="pl-2 pr-2 py-1.5 text-left font-medium text-gray-500 uppercase tracking-wider min-w-48">
											Title
										</th>
										<th className="pl-2 pr-2 py-1.5 text-left font-medium text-gray-500 uppercase tracking-wider min-w-32 max-w-56">
											URL
										</th>
										<th className="w-28 pl-2 pr-2.5 py-1.5 text-left font-medium text-gray-500 uppercase tracking-wider">
											Date
										</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100">
									{items.map((item, index) => (
										<tr
											key={index}
											className="hover:bg-gray-50/80 transition-colors"
										>
											<td className="pl-2.5 pr-1.5 py-1">
												{item.url ? (
													<input
														type="checkbox"
														checked={!!selected[index]}
														onChange={() => toggleOne(index)}
														className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 focus:ring-1"
													/>
												) : null}
											</td>
											<td className="pl-1.5 pr-2 py-1 text-right text-gray-400 tabular-nums">
												{index + 1}
											</td>
											<td className="pl-1.5 pr-2 py-1 text-gray-600 truncate max-w-32" title={item.domain}>
												{item.domain}
											</td>
											<td className="pl-2 pr-2 py-1 text-gray-900 truncate max-w-72" title={item.title || ''}>
												{item.title || '—'}
											</td>
											<td className="pl-2 pr-2 py-1 max-w-56">
												<a
													href={item.url}
													target="_blank"
													rel="noopener noreferrer"
													className="text-indigo-600 hover:underline truncate block"
												>
													{item.url || '—'}
												</a>
											</td>
											<td className="pl-2 pr-2.5 py-1 text-gray-500 whitespace-nowrap truncate max-w-28" title={item.date || ''}>
												{item.date || '—'}
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>
						<div className="flex gap-3 pt-2">
							<Button
								variant="primary"
								onClick={handleAddToTasks}
								disabled={adding || selectedCount === 0}
							>
								{adding ? 'Adding…' : `Add to tasks (${selectedCount})`}
							</Button>
							<Button variant="secondary" onClick={onClose}>
								Back
							</Button>
						</div>
					</>
				)}
			</div>
		</Modal>
	);
}
