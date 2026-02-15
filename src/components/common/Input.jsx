import Tooltip from './Tooltip';

export default function Input({
	label,
	tooltip,
	error,
	className = '',
	...props
}) {
	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;
	const isRequired = Boolean(props.required);

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
	...props
}) {
	const fieldClassName = `poststation-field ${error ? 'poststation-field-error' : ''}`;
	const isRequired = Boolean(props.required);

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
