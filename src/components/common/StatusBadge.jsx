const statusStyles = {
	pending: 'bg-yellow-100 text-yellow-800',
	processing: 'bg-blue-100 text-blue-800',
	completed: 'bg-green-100 text-green-800',
	failed: 'bg-red-100 text-red-800',
	draft: 'bg-gray-100 text-gray-800',
	publish: 'bg-green-100 text-green-800',
};

export default function StatusBadge({ status, className = '' }) {
	const style = statusStyles[status] || 'bg-gray-100 text-gray-800';
	
	return (
		<span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${style} ${className}`}>
			{status}
		</span>
	);
}

// Counts badge for showing block counts
export function CountsBadge({ counts }) {
	if (!counts) return null;
	
	const { pending = 0, processing = 0, completed = 0, failed = 0 } = counts;
	
	return (
		<div className="flex items-center gap-1">
			{pending > 0 && (
				<span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
					{pending} pending
				</span>
			)}
			{processing > 0 && (
				<span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
					{processing} processing
				</span>
			)}
			{completed > 0 && (
				<span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
					{completed} done
				</span>
			)}
			{failed > 0 && (
				<span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
					{failed} failed
				</span>
			)}
		</div>
	);
}
