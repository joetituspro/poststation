export const PUBLICATION_MODE_OPTIONS = [
	{ value: 'pending_review', label: 'Pending Review' },
	{ value: 'publish_instantly', label: 'Publish Instantly' },
	{ value: 'schedule_date', label: 'Schedule Date' },
	{ value: 'publish_randomly', label: 'Publish Randomly' },
];

export const PUBLICATION_MODE_LABELS = {
	pending_review: 'Pending Review',
	publish_instantly: 'Publish Instantly',
	schedule_date: 'Schedule Date',
	publish_randomly: 'Publish Randomly',
};

const pad = (n) => String(n).padStart(2, '0');

export const getTodayDateValue = () => {
	const d = new Date();
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

export const getNowDateTimeLocalValue = () => {
	const d = new Date();
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export const getDatePlusDaysValue = (days = 30, fromDate = null) => {
	const base = fromDate ? new Date(`${fromDate}T00:00:00`) : new Date();
	base.setDate(base.getDate() + days);
	return `${base.getFullYear()}-${pad(base.getMonth() + 1)}-${pad(base.getDate())}`;
};

export const mapLegacyPostStatusToPublicationMode = (status) => {
	if (status === 'publish') return 'publish_instantly';
	if (status === 'future') return 'schedule_date';
	return 'pending_review';
};

export const normalizeCampaignPublication = (campaign = {}) => {
	const mode = campaign.publication_mode
		|| mapLegacyPostStatusToPublicationMode(campaign.post_status);
	return {
		...campaign,
		publication_mode: mode,
	};
};

export const normalizeTaskPublication = (task = {}, campaign = {}) => {
	const campaignMode = campaign.publication_mode
		|| mapLegacyPostStatusToPublicationMode(campaign.post_status);
	const mode = task.publication_mode || campaignMode || 'pending_review';
	const today = getTodayDateValue();
	const publicationRandomFrom = task.publication_random_from || campaign.publication_random_from || today;
	const publicationRandomTo = task.publication_random_to
		|| campaign.publication_random_to
		|| getDatePlusDaysValue(30, publicationRandomFrom);

	return {
		...task,
		publication_mode: mode,
		publication_date: task.publication_date || getNowDateTimeLocalValue(),
		publication_random_from: publicationRandomFrom,
		publication_random_to: publicationRandomTo,
	};
};

export const normalizeDateTimeLocalValue = (value) => {
	if (!value) return '';
	return String(value).replace(' ', 'T').slice(0, 16);
};
