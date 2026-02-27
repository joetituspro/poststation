import { Input, Select, Tooltip } from '../common';
import { getPostTypes, getLanguages, getCountries } from '../../api/client';
import { PUBLICATION_MODE_OPTIONS } from '../../utils/publication';

const CAMPAIGN_TYPE_OPTIONS = [
	{ value: 'default', label: 'Default' },
	{ value: 'rewrite_blog_post', label: 'Rewrite Blog Post' },
];

const TONE_OPTIONS = [
	{ value: 'none', label: 'None' },
	{ value: 'friendly', label: 'Friendly' },
	{ value: 'professional', label: 'Professional' },
	{ value: 'informational', label: 'Informational' },
	{ value: 'transactional', label: 'Transactional' },
	{ value: 'inspirational', label: 'Inspirational' },
	{ value: 'neutral', label: 'Neutral' },
	{ value: 'witty', label: 'Witty' },
	{ value: 'casual', label: 'Casual' },
	{ value: 'authoritative', label: 'Authoritative' },
	{ value: 'encouraging', label: 'Encouraging' },
	{ value: 'persuasive', label: 'Persuasive' },
	{ value: 'poetic', label: 'Poetic' },
];

const POV_OPTIONS = [
	{ value: 'none', label: 'None' },
	{ value: 'first_person_singular', label: 'First Person Singular (I/me)' },
	{ value: 'first_person_plural', label: 'First Person Plural (we/us)' },
	{ value: 'second_person', label: 'Second Person (you)' },
	{ value: 'third_person', label: 'Third Person (he/she/they)' },
];

const READABILITY_OPTIONS = [
	{ value: 'grade_4', label: '4th Grade (Very Easy)' },
	{ value: 'grade_6', label: '6th Grade (Easy)' },
	{ value: 'grade_8', label: '8th Grade (Plain English/Average)' },
	{ value: 'grade_10_12', label: '10th–12th Grade (High School)' },
	{
		value: 'college_graduate',
		label: 'College Graduate/Professional (Difficult)',
	},
];

const INTERVAL_UNIT_OPTIONS = [
	{ value: 'minute', label: 'Minute(s)' },
	{ value: 'hour', label: 'Hour(s)' },
];

const ROLLING_SCHEDULE_DAY_OPTIONS = [
	{ value: '7', label: '7 Days' },
	{ value: '14', label: '14 Days' },
	{ value: '30', label: '30 Days' },
	{ value: '60', label: '60 Days' },
];

export default function CampaignForm( {
	campaign,
	onChange,
	webhooks = [],
	users = [],
} ) {
	const postTypes = getPostTypes();
	const languages = getLanguages();
	const countries = getCountries();
	const postTypeOptions = Object.entries( postTypes ).map(
		( [ value, label ] ) => ( { value, label } )
	);
	const languageOptions = Object.entries( languages ).map(
		( [ value, label ] ) => ( { value, label } )
	);
	const countryOptions = Object.entries( countries ).map(
		( [ value, label ] ) => ( { value, label } )
	);
	const webhookOptions = webhooks.map( ( w ) => ( {
		value: w.id.toString(),
		label: w.name,
	} ) );
	const userOptions = users.map( ( u ) => ( {
		value: u.id.toString(),
		label: u.display_name,
	} ) );

	const handleChange = ( field, value ) => {
		onChange( { ...campaign, [ field ]: value } );
	};

	return (
		<div className="space-y-4">
			{ /* Main settings grid - no title (edited in header) */ }
			<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
				<Select
					label="Campaign Type"
					tooltip="<strong>Campaign Type</strong> sets the overall writing style and structure used for this campaign."
					options={ CAMPAIGN_TYPE_OPTIONS }
					value={ campaign.campaign_type || 'default' }
					onChange={ ( e ) =>
						handleChange( 'campaign_type', e.target.value )
					}
					required
				/>

				<Select
					label="Language"
					tooltip="Primary language for generated content and taxonomy suggestions."
					options={ languageOptions }
					value={ campaign.language || 'en' }
					onChange={ ( e ) =>
						handleChange( 'language', e.target.value )
					}
					required
				/>

				<Select
					label="Tone of Voice"
					tooltip="Global tone used for body generation across tasks."
					options={ TONE_OPTIONS }
					value={ campaign.tone_of_voice || 'none' }
					onChange={ ( e ) =>
						handleChange( 'tone_of_voice', e.target.value )
					}
					required
				/>

				<Select
					label="Point of View"
					tooltip="Global narrative perspective used for generated writing."
					options={ POV_OPTIONS }
					value={ campaign.point_of_view || 'none' }
					onChange={ ( e ) =>
						handleChange( 'point_of_view', e.target.value )
					}
					required
				/>

				<Select
					label="Readability"
					tooltip="Reading complexity level target for generated text."
					options={ READABILITY_OPTIONS }
					value={ campaign.readability || 'grade_8' }
					onChange={ ( e ) =>
						handleChange( 'readability', e.target.value )
					}
					required
				/>

				<Select
					label="Target Country"
					tooltip="Preferred country or region for localization. Default is International."
					options={ countryOptions }
					value={ campaign.target_country || 'international' }
					onChange={ ( e ) =>
						handleChange( 'target_country', e.target.value )
					}
					required
				/>

				<Select
					label="Post Type"
					tooltip="WordPress post type that will be created (e.g., Post, Page, or a custom type)."
					options={ postTypeOptions }
					value={ campaign.post_type || 'post' }
					onChange={ ( e ) =>
						handleChange( 'post_type', e.target.value )
					}
					required
				/>

				<Select
					label="Publication"
					tooltip="Default publication behavior applied when a task does not use a task-level publication override."
					options={ PUBLICATION_MODE_OPTIONS }
					value={ campaign.publication_mode || 'pending_review' }
					onChange={ ( e ) =>
						handleChange( 'publication_mode', e.target.value )
					}
					required
				/>

				{ ( campaign.publication_mode || 'pending_review' ) ===
					'publish_intervals' && (
					<>
						<div className="space-y-1">
							<div className="flex items-center text-sm font-medium text-gray-700">
								<span>Publish Interval</span>
								<Tooltip content="When a task is completed, its post is scheduled after this interval." />
							</div>
							<div className="flex items-center gap-2">
								<div className="w-28">
									<Input
										label=""
										type="number"
										min="1"
										value={
											campaign.publication_interval_value ??
											1
										}
										onChange={ ( e ) => {
											const value = Math.max(
												1,
												parseInt(
													e.target.value,
													10
												) || 1
											);
											handleChange(
												'publication_interval_value',
												value
											);
										} }
										required
									/>
								</div>
								<div className="w-36">
									<Select
										label=""
										options={ INTERVAL_UNIT_OPTIONS }
										value={
											campaign.publication_interval_unit ||
											'hour'
										}
										onChange={ ( e ) =>
											handleChange(
												'publication_interval_unit',
												e.target.value
											)
										}
										required
									/>
								</div>
							</div>
						</div>
					</>
				) }

				{ ( campaign.publication_mode || 'pending_review' ) ===
					'rolling_schedule' && (
					<Select
						label="Schedule Range"
						tooltip="Completed tasks are spread evenly across this number of days and cycle back to the start when tasks exceed the range."
						options={ ROLLING_SCHEDULE_DAY_OPTIONS }
						value={ (
							campaign.rolling_schedule_days ?? 30
						).toString() }
						onChange={ ( e ) =>
							handleChange(
								'rolling_schedule_days',
								parseInt( e.target.value, 10 ) || 30
							)
						}
						required
					/>
				) }

				<Select
					label="Default Author"
					tooltip="Default author assigned to created posts."
					options={ userOptions }
					value={ campaign.default_author_id?.toString() || '' }
					onChange={ ( e ) =>
						handleChange( 'default_author_id', e.target.value )
					}
					placeholder="Select author..."
					required
				/>

				<Select
					label="RSS Feeds"
					tooltip="Enable RSS feed sources for this campaign. When enabled, you can set feed URLs and frequency, and run RSS checks to add items as tasks."
					options={ [
						{ value: 'no', label: 'No' },
						{ value: 'yes', label: 'Yes' },
					] }
					value={ campaign.rss_enabled || 'no' }
					onChange={ ( e ) =>
						handleChange( 'rss_enabled', e.target.value )
					}
				/>

				<Select
					label="Webhook"
					tooltip="Webhook endpoint that receives the generation payload for this campaign."
					options={ webhookOptions }
					value={ campaign.webhook_id?.toString() || '' }
					onChange={ ( e ) =>
						handleChange( 'webhook_id', e.target.value )
					}
					placeholder="Select webhook..."
					required
				/>
			</div>
		</div>
	);
}
