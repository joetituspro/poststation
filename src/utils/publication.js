export const PUBLICATION_MODE_OPTIONS = [
	{ value: 'pending_review', label: 'Pending Review' },
	{ value: 'publish_instantly', label: 'Publish Instantly' },
	{ value: 'publish_intervals', label: 'Publish at Intervals' },
	{ value: 'rolling_schedule', label: 'Rolling Schedule' },
];

export const TASK_PUBLICATION_MODE_OPTIONS = [
	{ value: 'pending_review', label: 'Pending Review' },
	{ value: 'publish_instantly', label: 'Publish Instantly' },
	{ value: 'set_date', label: 'Set a Date' },
];

export const PUBLICATION_MODE_LABELS = {
	pending_review: 'Pending Review',
	publish_instantly: 'Publish Instantly',
	publish_intervals: 'Publish at Intervals',
	rolling_schedule: 'Rolling Schedule',
	set_date: 'Set a Date',
};

const pad = ( n ) => String( n ).padStart( 2, '0' );

export const getTodayDateValue = () => {
	const d = new Date();
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad(
		d.getDate()
	) }`;
};

export const getNowDateTimeLocalValue = () => {
	const d = new Date();
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad(
		d.getDate()
	) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
};

export const getDatePlusDaysValue = ( days = 30, fromDate = null ) => {
	const base = fromDate ? new Date( `${ fromDate }T00:00:00` ) : new Date();
	base.setDate( base.getDate() + days );
	return `${ base.getFullYear() }-${ pad( base.getMonth() + 1 ) }-${ pad(
		base.getDate()
	) }`;
};

export const mapLegacyPostStatusToPublicationMode = ( status ) => {
	if ( status === 'publish' ) return 'publish_instantly';
	if ( status === 'future' ) return 'rolling_schedule';
	return 'pending_review';
};

export const normalizePublicationMode = ( mode ) => {
	if ( mode === 'pending' ) return 'pending_review';
	if ( mode === 'publish' ) return 'publish_instantly';
	if ( mode === 'future' ) return 'rolling_schedule';
	if ( mode === 'schedule_date' || mode === 'publish_randomly' ) {
		return 'rolling_schedule';
	}
	if (
		mode === 'pending_review' ||
		mode === 'publish_instantly' ||
		mode === 'publish_intervals' ||
		mode === 'rolling_schedule'
	) {
		return mode;
	}
	return 'pending_review';
};

export const normalizeTaskPublicationMode = ( mode ) => {
	if ( mode === 'schedule_date' ) return 'set_date';
	if ( mode === 'publish_randomly' ) return 'pending_review';
	if (
		mode === 'pending_review' ||
		mode === 'publish_instantly' ||
		mode === 'set_date'
	) {
		return mode;
	}
	return 'pending_review';
};

export const normalizeCampaignPublication = ( campaign = {} ) => {
	const mode = normalizePublicationMode(
		campaign.publication_mode ||
			mapLegacyPostStatusToPublicationMode( campaign.post_status )
	);
	const intervalValue = Math.max(
		1,
		parseInt( campaign.publication_interval_value ?? 1, 10 ) || 1
	);
	const intervalUnit =
		campaign.publication_interval_unit === 'minute' ? 'minute' : 'hour';
	const rollingDays = [ 7, 14, 30, 60 ].includes(
		parseInt( campaign.rolling_schedule_days ?? 30, 10 )
	)
		? parseInt( campaign.rolling_schedule_days ?? 30, 10 )
		: 30;

	return {
		...campaign,
		publication_mode: mode,
		publication_interval_value: intervalValue,
		publication_interval_unit: intervalUnit,
		rolling_schedule_days: rollingDays,
	};
};

export const normalizeTaskPublication = ( task = {}, campaign = {} ) => {
	const campaignMode = normalizePublicationMode(
		campaign.publication_mode ||
			mapLegacyPostStatusToPublicationMode( campaign.post_status )
	);
	const hasTaskOverride =
		task.publication_override === true ||
		String( task.publication_override ?? '0' ) === '1';
	const mode = hasTaskOverride
		? normalizeTaskPublicationMode( task.publication_mode )
		: normalizePublicationMode(
				task.publication_mode || campaignMode || 'pending_review'
		  );

	return {
		...task,
		publication_override: hasTaskOverride,
		publication_mode: mode,
		publication_date: task.publication_date || getNowDateTimeLocalValue(),
	};
};

export const normalizeDateTimeLocalValue = ( value ) => {
	if ( ! value ) return '';
	return String( value ).replace( ' ', 'T' ).slice( 0, 16 );
};
