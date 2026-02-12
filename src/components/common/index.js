export { default as Button } from './Button';
export { default as Input, Textarea } from './Input';
export { default as Select, MultiSelect } from './Select';
export { default as ModelSelect } from './ModelSelect';
export { default as Modal, ConfirmModal } from './Modal';
export { ToastProvider, useToast } from './Toast';
export { default as Table, TableHead, TableBody, TableRow, TableHeader, TableCell, EmptyState } from './Table';
export { default as StatusBadge, CountsBadge } from './StatusBadge';
export { default as Tooltip } from './Tooltip';

// Loading spinner
export function Spinner({ size = 'md', className = '' }) {
	const sizes = {
		sm: 'h-4 w-4',
		md: 'h-6 w-6',
		lg: 'h-8 w-8',
	};

	return (
		<svg className={`animate-spin ${sizes[size]} ${className}`} fill="none" viewBox="0 0 24 24">
			<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
			<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
		</svg>
	);
}

// Page loading state
export function PageLoader() {
	return (
		<div className="flex items-center justify-center py-12">
			<Spinner size="lg" className="text-indigo-600" />
		</div>
	);
}

// Card component
export function Card({ children, className = '' }) {
	return (
		<div className={`bg-white rounded-lg border border-gray-200 shadow-sm ${className}`}>
			{children}
		</div>
	);
}

export function CardHeader({ children, className = '' }) {
	return (
		<div className={`px-6 py-4 border-b border-gray-200 ${className}`}>
			{children}
		</div>
	);
}

export function CardBody({ children, className = '' }) {
	return (
		<div className={`px-6 py-4 ${className}`}>
			{children}
		</div>
	);
}

// Page header component
export function PageHeader({ title, description, actions }) {
	return (
		<div className="mb-8">
			<div className="flex items-center justify-between">
				<div>
					<h1 className="text-2xl font-semibold text-gray-900">{title}</h1>
					{description && (
						<p className="mt-1 text-sm text-gray-500">{description}</p>
					)}
				</div>
				{actions && (
					<div className="flex items-center gap-3">
						{actions}
					</div>
				)}
			</div>
		</div>
	);
}
