import Tooltip from './Tooltip';

export default function Select({
	label,
	tooltip,
	options = [],
	error,
	className = '',
	placeholder = 'Select...',
	variant = 'default',
	...props
}) {
	const isRequired = Boolean(props.required);
	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;


	if (variant === 'floating') {
		const { id, ...rest } = props;
		const fieldClassName = [
			'poststation-field',
			'peer',
			// Remove default placeholder styling for floating label and align padding
			'appearance-none px-2.5 pb-2.5 pt-4 pr-8',
			error ? 'poststation-field-error' : '',
		]
			.filter(Boolean)
			.join(' ');

		return (
			<div className={`relative ${className}`}>
				<select
					id={id}
					className={fieldClassName}
					{...rest}
				>
					{options.map((option) => (
						<option key={option.value} value={option.value} disabled={option.disabled}>
							{option.label}
						</option>
					))}
				</select>
				{label && (
					<label
						htmlFor={id}
						className={[
							'pointer-events-none',
							'absolute text-xs text-gray-500 duration-300 transform -translate-y-4 scale-75',
							'top-2 z-10 origin-left bg-white px-1',
							'peer-focus:px-1 peer-focus:text-indigo-600',
							'peer-placeholder-shown:scale-100 peer-placeholder-shown:-translate-y-1/2',
							'peer-placeholder-shown:top-1/2 peer-focus:top-2 peer-focus:scale-75 peer-focus:-translate-y-4',
							'start-2',
						].join(' ')}
					>
						<span className="inline-flex items-center gap-1">
							<span>
								{label}
								{isRequired && <span className="text-red-600 ml-0.5">*</span>}
							</span>
						</span>
					</label>
				)}
				{error && (
					<p className="mt-1 text-xs text-red-600">{error}</p>
				)}
			</div>
		);
	}


	return (
		<div className={className}>
			{label && (
				<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
					<span>
						{label}
						{isRequired && <span className="text-red-600 ml-0.5">*</span>}
					</span>
					{tooltip && <Tooltip content={tooltip} />}
				</label>
			)}
			<select className={fieldClassName} {...props}>
				{placeholder && (
					<option value="">{placeholder}</option>
				)}
				{options.map((option) => (
					<option key={option.value} value={option.value} disabled={option.disabled}>
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
	required = false,
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
					<span>
						{label}
						{required && <span className="text-red-600 ml-0.5">*</span>}
					</span>
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
					<option key={option.value} value={option.value} disabled={option.disabled}>
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
