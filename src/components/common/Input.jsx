import Tooltip from './Tooltip';

export default function Input({
	label,
	tooltip,
	error,
	className = '',
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
			<input
				className={`
					block w-full rounded-lg border px-3 py-2 text-sm
					placeholder:text-gray-400
					focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
					disabled:bg-gray-50 disabled:text-gray-500
					${error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : 'border-gray-300'}
				`}
				{...props}
			/>
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
	rows = 3,
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
			<textarea
				rows={rows}
				className={`
					block w-full rounded-lg border px-3 py-2 text-sm
					placeholder:text-gray-400
					focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
					disabled:bg-gray-50 disabled:text-gray-500
					${error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : 'border-gray-300'}
				`}
				{...props}
			/>
			{error && (
				<p className="mt-1 text-sm text-red-600">{error}</p>
			)}
		</div>
	);
}
