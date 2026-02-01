import { useEffect, useRef } from 'react';

export default function Modal({
	isOpen,
	onClose,
	title,
	children,
	size = 'md',
}) {
	const overlayRef = useRef(null);

	useEffect(() => {
		const handleEscape = (e) => {
			if (e.key === 'Escape') {
				onClose();
			}
		};

		if (isOpen) {
			document.addEventListener('keydown', handleEscape);
			document.body.style.overflow = 'hidden';
		}

		return () => {
			document.removeEventListener('keydown', handleEscape);
			document.body.style.overflow = '';
		};
	}, [isOpen, onClose]);

	if (!isOpen) return null;

	const sizes = {
		sm: 'max-w-md',
		md: 'max-w-lg',
		lg: 'max-w-2xl',
		xl: 'max-w-4xl',
	};

	return (
		<div
			ref={overlayRef}
			className="fixed inset-0 z-50 overflow-y-auto"
			onClick={(e) => e.target === overlayRef.current && onClose()}
		>
			<div className="min-h-screen px-4 flex items-center justify-center">
				{/* Backdrop */}
				<div className="fixed inset-0 bg-black/50 transition-opacity" />

				{/* Modal */}
				<div className={`relative bg-white rounded-xl shadow-xl w-full ${sizes[size]} transform transition-all`}>
					{/* Header */}
					<div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
						<h3 className="text-lg font-semibold text-gray-900">{title}</h3>
						<button
							onClick={onClose}
							className="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
						>
							<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
							</svg>
						</button>
					</div>

					{/* Content */}
					<div className="px-6 py-4">
						{children}
					</div>
				</div>
			</div>
		</div>
	);
}

// Confirm dialog helper
export function ConfirmModal({
	isOpen,
	onClose,
	onConfirm,
	title = 'Confirm',
	message,
	confirmText = 'Confirm',
	cancelText = 'Cancel',
	variant = 'danger',
}) {
	const buttonVariants = {
		danger: 'bg-red-600 hover:bg-red-700 focus:ring-red-500',
		primary: 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500',
	};

	return (
		<Modal isOpen={isOpen} onClose={onClose} title={title} size="sm">
			<p className="text-gray-600 mb-6">{message}</p>
			<div className="flex justify-end gap-3">
				<button
					onClick={onClose}
					className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
				>
					{cancelText}
				</button>
				<button
					onClick={() => {
						onConfirm();
						onClose();
					}}
					className={`px-4 py-2 text-sm font-medium text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 ${buttonVariants[variant]}`}
				>
					{confirmText}
				</button>
			</div>
		</Modal>
	);
}
