import { createContext, useContext, useState, useCallback } from 'react';

const ToastContext = createContext(null);

const TOAST_DURATION = 4000;

const typeStyles = {
	success: 'bg-emerald-50 border-emerald-200 text-emerald-800',
	error: 'bg-red-50 border-red-200 text-red-800',
	info: 'bg-indigo-50 border-indigo-200 text-indigo-800',
};

export function ToastProvider({ children }) {
	const [toasts, setToasts] = useState([]);

	const hideToast = useCallback((id) => {
		setToasts((prev) => prev.filter((t) => t.id !== id));
	}, []);

	const showToast = useCallback((message, type = 'info') => {
		const id = Date.now() + Math.random();
		setToasts((prev) => [...prev, { id, message, type }]);

		setTimeout(() => {
			hideToast(id);
		}, TOAST_DURATION);
	}, [hideToast]);

	const value = { showToast, hideToast };

	return (
		<ToastContext.Provider value={value}>
			{children}
			<ToastContainer toasts={toasts} onDismiss={hideToast} />
		</ToastContext.Provider>
	);
}

export function useToast() {
	const ctx = useContext(ToastContext);
	if (!ctx) throw new Error('useToast must be used within ToastProvider');
	return ctx;
}

function ToastContainer({ toasts, onDismiss }) {
	if (toasts.length === 0) return null;

	return (
		<div
			className="fixed bottom-4 right-4 z-[99999] flex flex-col gap-2 max-w-sm w-full pointer-events-none"
			aria-live="polite"
		>
			{toasts.map((toast) => (
				<ToastItem
					key={toast.id}
					message={toast.message}
					type={toast.type}
					onDismiss={() => onDismiss(toast.id)}
				/>
			))}
		</div>
	);
}

function ToastItem({ message, type = 'info', onDismiss }) {
	const style = typeStyles[type] ?? typeStyles.info;

	return (
		<div
			className={`pointer-events-auto flex items-center gap-3 rounded-lg border px-4 py-3 shadow-sm ${style}`}
			role="alert"
		>
			{type === 'success' && (
				<svg className="w-5 h-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
				</svg>
			)}
			{type === 'error' && (
				<svg className="w-5 h-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
				</svg>
			)}
			{type === 'info' && (
				<svg className="w-5 h-5 shrink-0 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
				</svg>
			)}
			<p className="text-sm font-medium flex-1">{message}</p>
			<button
				type="button"
				onClick={onDismiss}
				className="shrink-0 p-1 rounded hover:opacity-70 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-gray-400"
				aria-label="Dismiss"
			>
				<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>
	);
}
