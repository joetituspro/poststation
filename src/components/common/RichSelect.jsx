import { useState, useRef, useEffect } from 'react';
import { createPortal } from 'react-dom';
import Tooltip from './Tooltip';

/**
 * Rich select: full-width dropdown that shows icon + label + description per option.
 * Uses a portal to escape overflow:hidden/auto parent containers.
 */
export default function RichSelect({
	label,
	tooltip,
	labelAction,
	options = [],
	value,
	onChange,
	placeholder = 'Select...',
	className = '',
	error,
}) {
	const [open, setOpen] = useState(false);
	const [dropdownStyle, setDropdownStyle] = useState({});
	const containerRef = useRef(null);
	const buttonRef = useRef(null);

	const DROPDOWN_MAX_HEIGHT = 240; // max-h-60 = 240px
	const GAP = 4;

	const calcPosition = () => {
		if (!buttonRef.current) return {};
		const rect = buttonRef.current.getBoundingClientRect();
		const spaceBelow = window.innerHeight - rect.bottom;
		const spaceAbove = rect.top;
		const flipUp = spaceBelow < DROPDOWN_MAX_HEIGHT && spaceAbove > spaceBelow;

		return {
			position: 'fixed',
			left: rect.left,
			width: rect.width,
			zIndex: 9999,
			...(flipUp
				? { bottom: window.innerHeight - rect.top + GAP, top: 'auto' }
				: { top: rect.bottom + GAP, bottom: 'auto' }),
		};
	};

	// Recalculate dropdown position whenever it opens
	useEffect(() => {
		if (open) setDropdownStyle(calcPosition());
	}, [open]);

	// Close on outside click
	useEffect(() => {
		const handleClickOutside = (e) => {
			if (
				containerRef.current &&
				!containerRef.current.contains(e.target) &&
				// also allow clicks inside the portal dropdown
				!document.getElementById('rich-select-portal')?.contains(e.target)
			) {
				setOpen(false);
			}
		};
		if (open) {
			document.addEventListener('mousedown', handleClickOutside);
		}
		return () => document.removeEventListener('mousedown', handleClickOutside);
	}, [open]);

	// Reposition on scroll/resize so the dropdown tracks the button and re-evaluates flip
	useEffect(() => {
		if (!open) return;
		const reposition = () => setDropdownStyle(calcPosition());
		window.addEventListener('scroll', reposition, true);
		window.addEventListener('resize', reposition);
		return () => {
			window.removeEventListener('scroll', reposition, true);
			window.removeEventListener('resize', reposition);
		};
	}, [open]);

	const selected = value != null && value !== '' ? options.find((o) => String(o.value) === String(value)) : null;
	const displayValue = selected ? selected.label : placeholder;

	const dropdown = open
		? createPortal(
				<ul
					id="rich-select-portal"
					style={dropdownStyle}
					className="max-h-60 overflow-auto rounded-lg border border-gray-200 bg-white py-2 shadow-lg"
					role="listbox"
				>
					<li
						role="option"
						aria-selected={value == null || value === ''}
						onClick={() => {
							onChange(null);
							setOpen(false);
						}}
						className="cursor-pointer pl-4 pr-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 border-b border-gray-100"
					>
						{placeholder}
					</li>
					{options.map((option) => {
						const isSelected = String(option.value) === String(value);
						return (
							<li
								key={option.value}
								role="option"
								aria-selected={isSelected}
								onClick={() => {
									onChange(option.value);
									setOpen(false);
								}}
								className={`cursor-pointer pl-4 pr-4 py-2.5 hover:bg-gray-50 flex items-start gap-3 ${
									isSelected ? 'bg-indigo-50 hover:bg-indigo-100' : ''
								}`}
							>
								{option.icon && (
									<span className="flex items-center justify-center w-6 h-6 shrink-0 rounded bg-gray-100 text-gray-600 mt-0.5">
										{option.icon}
									</span>
								)}
								<div className="min-w-0 flex-1 pt-0.5">
									<div className="font-medium text-gray-900">{option.label}</div>
									{option.description && (
										<div className="text-xs text-gray-500 mt-1">{option.description}</div>
									)}
								</div>
							</li>
						);
					})}
				</ul>,
				document.body,
		  )
		: null;

	return (
		<div className={`w-full ${className}`.trim()} ref={containerRef}>
			{(label || labelAction) && (
				<div className="flex items-end justify-between gap-2 mb-1">
					{label && (
						<label className="flex items-center text-sm font-medium text-gray-700 mb-1">
							<span>{label}</span>
							{tooltip && <Tooltip content={tooltip} />}
						</label>
					)}
					{labelAction && <span className="shrink-0">{labelAction}</span>}
				</div>
			)}
			<div className="relative w-full">
				<button
					ref={buttonRef}
					type="button"
					onClick={() => setOpen((prev) => !prev)}
					className={`
						w-full min-h-[38px] flex items-center gap-3 rounded-lg border bg-white
						pl-4 pr-4 py-2.5 text-left text-sm
						${error ? 'poststation-field-error border-red-300' : 'border-gray-300'}
						hover:border-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 focus:outline-none
					`}
					aria-haspopup="listbox"
					aria-expanded={open}
				>
					<span className="flex items-center gap-3 min-w-0 flex-1">
						{selected?.icon && (
							<span className="flex items-center justify-center w-5 h-5 shrink-0 text-gray-500">
								{selected.icon}
							</span>
						)}
						<span className={selected ? 'text-gray-900 truncate' : 'text-gray-500'}>{displayValue}</span>
					</span>
					<span className="shrink-0 flex items-center justify-center w-5 h-5 ml-2" aria-hidden>
						<svg
							className={`w-4 h-4 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`}
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
						</svg>
					</span>
				</button>
			</div>
			{error && <p className="mt-1 text-sm text-red-600">{error}</p>}
			{dropdown}
		</div>
	);
}