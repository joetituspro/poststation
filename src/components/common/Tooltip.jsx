import { useEffect, useRef, useState } from 'react';

export default function Tooltip({ content, className = '' }) {
	const [isOpen, setIsOpen] = useState(false);
	const [isPinned, setIsPinned] = useState(false);
	const [position, setPosition] = useState({ top: 0, left: 0, placement: 'bottom' });
	const wrapperRef = useRef(null);
	const tooltipRef = useRef(null);

	const closeTooltip = () => {
		setIsPinned(false);
		setIsOpen(false);
	};

	useEffect(() => {
		const handleClickOutside = (event) => {
			if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
				closeTooltip();
			}
		};

		document.addEventListener('mousedown', handleClickOutside);
		return () => document.removeEventListener('mousedown', handleClickOutside);
	}, []);

	const handleMouseEnter = () => setIsOpen(true);
	const handleMouseLeave = () => {
		if (!isPinned) {
			setIsOpen(false);
		}
	};

	const handleTogglePin = () => {
		setIsPinned((prev) => {
			const next = !prev;
			setIsOpen(next);
			return next;
		});
	};

	useEffect(() => {
		if (!isOpen) return;

		const updatePosition = () => {
			const trigger = wrapperRef.current?.querySelector('button');
			const tooltip = tooltipRef.current;
			if (!trigger || !tooltip) return;

			const rect = trigger.getBoundingClientRect();
			const tooltipRect = tooltip.getBoundingClientRect();
			const padding = 8;

			let placement = 'bottom';
			let top = rect.bottom + 8;
			if (top + tooltipRect.height > window.innerHeight - padding) {
				top = rect.top - 8 - tooltipRect.height;
				placement = 'top';
			}

			let left = rect.left + rect.width / 2 - tooltipRect.width / 2;
			const maxLeft = window.innerWidth - padding - tooltipRect.width;
			left = Math.min(Math.max(left, padding), Math.max(padding, maxLeft));

			setPosition({ top, left, placement });
		};

		const rafId = requestAnimationFrame(updatePosition);
		window.addEventListener('resize', updatePosition);
		window.addEventListener('scroll', updatePosition, true);

		return () => {
			cancelAnimationFrame(rafId);
			window.removeEventListener('resize', updatePosition);
			window.removeEventListener('scroll', updatePosition, true);
		};
	}, [isOpen, content]);

	if (!content) {
		return null;
	}

	return (
		<span
			ref={wrapperRef}
			className={`relative inline-flex items-center ${className}`}
			onMouseEnter={handleMouseEnter}
			onMouseLeave={handleMouseLeave}
		>
			<button
				type="button"
				onClick={handleTogglePin}
				onFocus={() => setIsOpen(true)}
				onBlur={() => !isPinned && setIsOpen(false)}
				className="ml-1 inline-flex h-3 w-3 items-center justify-center rounded-full border border-gray-300 text-[8px]! font-semibold text-gray-600 hover:border-gray-400 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
				aria-label="More info"
			>
				?
			</button>
			{isOpen && (
				<div
					ref={tooltipRef}
					className="fixed z-9999 w-64 max-w-[80vw] rounded-lg border border-gray-200 bg-white p-3 text-xs text-gray-700 shadow-lg font-normal"
					style={{ top: position.top, left: position.left }}
					role="tooltip"
					data-placement={position.placement}
				>
					<div
						className="leading-relaxed"
						dangerouslySetInnerHTML={{ __html: content }}
					/>
				</div>
			)}
		</span>
	);
}
