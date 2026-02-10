import Tooltip from './Tooltip';

export default function Select({
	label,
	tooltip,
	options = [],
	error,
	className = '',
	placeholder = 'Select...',
	...props
}) {
	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;

	return (
		<div className={className}>
			{label && (
				<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
					<span>{label}</span>
					{tooltip && <Tooltip content={tooltip} />}
				</label>
			)}
			<select className={fieldClassName} {...props}>
				{placeholder && (
					<option value="">{placeholder}</option>
				)}
				{options.map((option) => (
					<option key={option.value} value={option.value}>
						{option.label}
					</option>
				))}
			</select>
			{error && (
				<p className="mt-1 text-sm text-red-600">{error}</p>
			)}
		</div>
	);
}

// Multi-select with tags
export function MultiSelect({
	label,
	tooltip,
	options = [],
	value = [],
	onChange,
	error,
	className = '',
	placeholder = 'Select...',
}) {
	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;

	const handleAdd = (e) => {
		const val = e.target.value;
		if (val && !value.includes(val)) {
			onChange([...value, val]);
		}
		e.target.value = '';
	};

	const handleRemove = (val) => {
		onChange(value.filter((v) => v !== val));
	};

	const availableOptions = options.filter((o) => !value.includes(o.value));
	const selectedOptions = options.filter((o) => value.includes(o.value));

	return (
		<div className={className}>
			{label && (
				<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
					<span>{label}</span>
					{tooltip && <Tooltip content={tooltip} />}
				</label>
			)}
			
			{/* Selected tags */}
			{selectedOptions.length > 0 && (
				<div className="flex flex-wrap gap-1 mb-2">
					{selectedOptions.map((option) => (
						<span
							key={option.value}
							className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-indigo-100 text-indigo-700 text-sm"
						>
							{option.label}
							<button
								type="button"
								onClick={() => handleRemove(option.value)}
								className="hover:text-indigo-900"
							>
								<svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
						</span>
					))}
				</div>
			)}

			<select className={fieldClassName} onChange={handleAdd} value="">
				<option value="">{placeholder}</option>
				{availableOptions.map((option) => (
					<option key={option.value} value={option.value}>
						{option.label}
					</option>
				))}
			</select>
			{error && (
				<p className="mt-1 text-sm text-red-600">{error}</p>
			)}
		</div>
	);
}
