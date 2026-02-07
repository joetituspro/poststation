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
	return (
		<div className={className}>
			{label && (
				<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
					<span>{label}</span>
					{tooltip && <Tooltip content={tooltip} />}
				</label>
			)}
			<select
				className={`
					block w-full rounded-lg border px-3 py-2 text-sm
					focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
					disabled:bg-gray-50 disabled:text-gray-500
					${error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : 'border-gray-300'}
				`}
				{...props}
			>
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

			<select
				className={`
					block w-full rounded-lg border px-3 py-2 text-sm
					focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
					${error ? 'border-red-300' : 'border-gray-300'}
				`}
				onChange={handleAdd}
				value=""
			>
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
