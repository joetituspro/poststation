import Tooltip from './Tooltip';

export default function Input({
	label,
	tooltip,
	error,
	className = '',
	variant = 'default',
	...props
}) {
	const isRequired = Boolean(props.required);

	if (variant === 'floating') {
		const { id, placeholder, ...rest } = props;
		const fieldClassName = [
			'poststation-field', 'peer', 'appearance-none px-2.5 pb-2.5 pt-4 pr-8',
			error ? 'poststation-field-error' : '',
		]
			.filter(Boolean)
			.join(' ');

		return (
			<div className={`relative ${className}`}>
				<input
					id={id}
					placeholder={placeholder || ' '}
					className={fieldClassName}
					{...rest}
				/>
				{label && (
					<label
						htmlFor={id}
						className={[
							'pointer-events-none',
							'absolute text-xs text-gray-500 duration-300 transform -translate-y-4 scale-75',
							'top-2 z-10 origin-left bg-white px-1',
							'peer-focus:px-1 peer-focus:text-indigo-600 peer-focus:opacity-100',
							'peer-focus:top-2 peer-focus:scale-75 peer-focus:-translate-y-4',
							placeholder
								? 'peer-placeholder-shown:opacity-0'
								: 'peer-placeholder-shown:scale-100 peer-placeholder-shown:-translate-y-1/2 peer-placeholder-shown:top-1/2',
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

	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;

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
			<input className={fieldClassName} {...props} />
			{error && (
				<p className="mt-1 text-sm text-red-600">{error}</p>
			)}
		</div>
	);
}

export function Textarea({
	label,
	tooltip,
	error,
	className = '',
	rows = 2,
	variant = 'default',
	...props
}) {
	const isRequired = Boolean(props.required);

	if (variant === 'floating') {
		const { id, placeholder, ...rest } = props;
		const fieldClassName = [
			'poststation-field', 'peer', 'appearance-none px-2.5 pb-2.5 pt-4 pr-8',
			error ? 'poststation-field-error' : '',
		]
			.filter(Boolean)
			.join(' ');

		return (
			<div className={`relative ${className}`}>
				<textarea
					id={id}
					rows={rows}
					placeholder={placeholder || ' '}
					className={fieldClassName}
					{...rest}
				/>
				{label && (
					<label
						htmlFor={id}
						className={[
							'pointer-events-none',
							'absolute text-xs text-gray-500 duration-300 transform -translate-y-4 scale-75',
							'top-2 z-10 origin-left bg-white px-1',
							'peer-focus:px-1 peer-focus:text-indigo-600 peer-focus:opacity-100',
							'peer-focus:top-2 peer-focus:scale-75 peer-focus:-translate-y-4',
							placeholder
								? 'peer-placeholder-shown:opacity-0'
								: 'peer-placeholder-shown:scale-100 peer-placeholder-shown:-translate-y-1/2 peer-placeholder-shown:top-1/2',
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

	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;

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
			<textarea rows={rows} className={fieldClassName} {...props} />
			{error && (
				<p className="mt-1 text-sm text-red-600">{error}</p>
			)}
		</div>
	);
}