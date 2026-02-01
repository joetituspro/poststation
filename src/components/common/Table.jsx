export default function Table({ children, className = '' }) {
	return (
		<div className={`overflow-x-auto rounded-lg border border-gray-200 ${className}`}>
			<table className="min-w-full divide-y divide-gray-200">
				{children}
			</table>
		</div>
	);
}

export function TableHead({ children }) {
	return (
		<thead className="bg-gray-50">
			{children}
		</thead>
	);
}

export function TableBody({ children }) {
	return (
		<tbody className="bg-white divide-y divide-gray-200">
			{children}
		</tbody>
	);
}

export function TableRow({ children, onClick, className = '' }) {
	return (
		<tr
			className={`${onClick ? 'cursor-pointer hover:bg-gray-50' : ''} ${className}`}
			onClick={onClick}
		>
			{children}
		</tr>
	);
}

export function TableHeader({ children, className = '' }) {
	return (
		<th className={`px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ${className}`}>
			{children}
		</th>
	);
}

export function TableCell({ children, className = '' }) {
	return (
		<td className={`px-4 py-3 text-sm text-gray-900 ${className}`}>
			{children}
		</td>
	);
}

// Empty state component
export function EmptyState({ icon, title, description, action }) {
	return (
		<div className="text-center py-12">
			{icon && (
				<div className="mx-auto w-12 h-12 text-gray-400 mb-4">
					{icon}
				</div>
			)}
			<h3 className="text-sm font-medium text-gray-900">{title}</h3>
			{description && (
				<p className="mt-1 text-sm text-gray-500">{description}</p>
			)}
			{action && (
				<div className="mt-4">{action}</div>
			)}
		</div>
	);
}
