import { useState, useEffect } from 'react';
import { Button, Input, Modal, Select } from '../common';
import { campaigns } from '../../api/client';

const FREQUENCY_OPTIONS = [
	{ value: 15, label: 'Every 15 minutes' },
	{ value: 60, label: 'Hourly' },
	{ value: 360, label: 'Every 6 hours' },
	{ value: 1440, label: 'Daily' },
];

function generateSourceId() {
	return typeof crypto !== 'undefined' && crypto.randomUUID
		? crypto.randomUUID()
		: `src-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}

/** Build full campaign payload so backend does not overwrite other fields with defaults. */
function buildCampaignPayload(campaign, frequency_interval, validSources) {
	return {
		title: campaign.title || 'Campaign',
		post_type: campaign.post_type ?? 'post',
		publication_mode: campaign.publication_mode ?? 'pending_review',
		default_author_id: campaign.default_author_id,
		webhook_id: campaign.webhook_id ?? null,
		campaign_type: campaign.campaign_type ?? 'default',
		tone_of_voice: campaign.tone_of_voice ?? 'none',
		point_of_view: campaign.point_of_view ?? 'none',
		readability: campaign.readability ?? 'grade_8',
		language: campaign.language ?? 'en',
		target_country: campaign.target_country ?? 'international',
		writing_preset_id: campaign.writing_preset_id ?? null,
		content_fields: campaign.content_fields,
		rss_enabled: 'yes',
		rss_config: {
			frequency_interval,
			sources: validSources,
		},
	};
}

export default function RssFeedConfigModal({
	isOpen,
	onClose,
	campaign,
	onSave,
	onRunNowComplete,
	onRssConfigChange,
	onTriggerSave,
}) {
	const [frequency_interval, setFrequencyInterval] = useState(60);
	const [sources, setSources] = useState([]);
	const [running, setRunning] = useState(false);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState('');

	useEffect(() => {
		if (!isOpen) return;
		setError('');
		const config = campaign?.rss_config;
		if (config) {
			setFrequencyInterval(
				FREQUENCY_OPTIONS.some((o) => o.value === config.frequency_interval)
					? config.frequency_interval
					: 60
			);
			const src = Array.isArray(config.sources) ? config.sources : [];
			setSources(
				src.length
					? src.map((s) => ({
							source_id: s.source_id ?? generateSourceId(),
							feed_url: s.feed_url ?? '',
					  }))
					: [{ source_id: generateSourceId(), feed_url: '' }]
			);
		} else {
			setFrequencyInterval(60);
			setSources([{ source_id: generateSourceId(), feed_url: '' }]);
		}
	}, [isOpen, campaign?.rss_config]);

	const syncToCampaign = (freq, src) => {
		onRssConfigChange?.({ frequency_interval: freq, sources: src });
	};

	const addSource = () => {
		setSources((prev) => {
			const next = [...prev, { source_id: generateSourceId(), feed_url: '' }];
			syncToCampaign(frequency_interval, next);
			return next;
		});
	};

	const removeSource = (index) => {
		setSources((prev) => {
			const next = prev.filter((_, i) => i !== index);
			syncToCampaign(frequency_interval, next);
			return next;
		});
	};

	const updateSourceUrl = (index, feed_url) => {
		setSources((prev) => {
			const next = prev.map((s, i) => (i === index ? { ...s, feed_url } : s));
			syncToCampaign(frequency_interval, next);
			return next;
		});
	};

	const handleFrequencyChange = (e) => {
		const next = Number(e.target.value);
		setFrequencyInterval(next);
		syncToCampaign(next, sources);
	};

	const handleSave = async () => {
		const validSources = sources.filter((s) => String(s.feed_url ?? '').trim());
		if (validSources.length === 0) {
			setError('Add at least one feed URL before saving.');
			return;
		}
		setError('');
		setSaving(true);
		try {
			const rssConfig = { frequency_interval, sources: validSources };
			syncToCampaign(frequency_interval, validSources);
			const saved = await onTriggerSave?.({ rss_config: rssConfig });
			if (saved) {
				onClose();
			}
		} catch (err) {
			setError(err?.message || 'Save failed.');
		} finally {
			setSaving(false);
		}
	};

	const handleFetch = async () => {
		const validSources = sources.filter((s) => String(s.feed_url ?? '').trim());
		if (validSources.length === 0) {
			setError('Add at least one feed URL before fetching.');
			return;
		}
		setError('');
		setRunning(true);
		try {
			await campaigns.update(campaign.id, buildCampaignPayload(campaign, frequency_interval, validSources));
			const response = await campaigns.runRssNow(campaign.id);
			onRunNowComplete?.(response);
		} catch (err) {
			setError(err?.message || 'Fetch failed.');
		} finally {
			setRunning(false);
		}
	};

	if (!campaign?.id) return null;

	return (
		<Modal isOpen={isOpen} onClose={onClose} title="RSS Feed configuration" size="lg">
			<div className="px-6 pb-6 space-y-4">
				{error && (
					<div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-800">
						{error}
					</div>
				)}
				<div>
					<label className="block text-sm font-medium text-gray-700 mb-1">
						Check frequency
					</label>
					<Select
						options={FREQUENCY_OPTIONS.map((o) => ({ value: String(o.value), label: o.label }))}
						value={String(frequency_interval)}
						onChange={handleFrequencyChange}
					/>
				</div>
				<div>
					<div className="flex items-center justify-between mb-1">
						<label className="block text-sm font-medium text-gray-700">Feed URLs</label>
						<Button type="button" variant="secondary" size="sm" onClick={addSource}>
							Add source
						</Button>
					</div>
					<div className="space-y-2">
						{sources.map((source, index) => (
							<div key={source.source_id} className="flex gap-2 items-center">
								<Input
									type="url"
									value={source.feed_url}
									onChange={(e) => updateSourceUrl(index, e.target.value)}
									placeholder="https://example.com/feed.xml"
									className="flex-1"
								/>
								<Button
									type="button"
									variant="secondary"
									size="sm"
									onClick={() => removeSource(index)}
									disabled={sources.length <= 1}
									aria-label="Remove source"
								>
									Remove
								</Button>
							</div>
						))}
					</div>
				</div>
				<div className="flex flex-wrap gap-3 pt-2 border-t border-gray-200">
					<Button
						type="button"
						variant="primary"
						onClick={handleSave}
						disabled={saving || running}
					>
						{saving ? 'Saving…' : 'Save'}
					</Button>
					<Button
						type="button"
						variant="secondary"
						onClick={handleFetch}
						disabled={running || saving}
					>
						{running ? 'Fetching…' : 'Fetch'}
					</Button>
					<Button type="button" variant="secondary" onClick={onClose} disabled={saving}>
						Back
					</Button>
				</div>
			</div>
		</Modal>
	);
}
