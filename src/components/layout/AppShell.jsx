import { useState, useEffect, useRef } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { useUnsavedChanges } from '../../context/UnsavedChangesContext';

const navItems = [
	{ to: '/campaigns', label: 'Campaigns', icon: CampaignsIcon },
	{ to: '/webhooks', label: 'Webhooks', icon: WebhooksIcon },
	{ to: '/settings', label: 'Settings', icon: SettingsIcon },
];

function CampaignsIcon({ className }) {
	return (
		<svg className={className} fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
			<path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
		</svg>
	);
}

function WebhooksIcon({ className }) {
	return (
		<svg className={className} fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
			<path strokeLinecap="round" strokeLinejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
		</svg>
	);
}

function SettingsIcon({ className }) {
	return (
		<svg className={className} fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
			<path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
			<path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
		</svg>
	);
}

export default function AppShell({ children }) {
	const location = useLocation();
	const [isSidebarOpen, setIsSidebarOpen] = useState(false);
	const { isDirty } = useUnsavedChanges();
	const lastHashRef = useRef(typeof window !== 'undefined' ? window.location.hash : '');
	const ignoreNextHashChange = useRef(false);

	useEffect(() => {
		setIsSidebarOpen(false);
	}, [location.pathname]);

	useEffect(() => {
		lastHashRef.current = window.location.hash;
	}, [location.pathname]);

	useEffect(() => {
		if (!isDirty) return;

		const handleBeforeUnload = (event) => {
			event.preventDefault();
			event.returnValue = '';
			return '';
		};

		window.addEventListener('beforeunload', handleBeforeUnload);
		return () => {
			window.removeEventListener('beforeunload', handleBeforeUnload);
		};
	}, [isDirty]);

	useEffect(() => {
		const handleHashChange = () => {
			const nextHash = window.location.hash;

			if (ignoreNextHashChange.current) {
				ignoreNextHashChange.current = false;
				lastHashRef.current = nextHash;
				return;
			}

			if (!isDirty) {
				lastHashRef.current = nextHash;
				return;
			}

			const shouldProceed = window.confirm(
				'You have unsaved changes. Leave without saving?'
			);

			if (shouldProceed) {
				lastHashRef.current = nextHash;
				return;
			}

			ignoreNextHashChange.current = true;
			window.location.hash = lastHashRef.current;
		};

		window.addEventListener('hashchange', handleHashChange);
		return () => {
			window.removeEventListener('hashchange', handleHashChange);
		};
	}, [isDirty]);

	useEffect(() => {
		const appRoot = document.getElementById('poststation-app');
		if (!appRoot) return undefined;

		const updateTopOffset = () => {
			const adminBar = document.getElementById('wpadminbar');
			const adminBarBottom = adminBar ? Math.max(0, adminBar.getBoundingClientRect().bottom) : 0;

			const noticeSelectors = [
				'#wpbody-content .notice',
				'#wpbody-content .update-nag',
				'#wpbody-content .error',
				'#wpbody-content .updated',
			];

			let noticeBottom = adminBarBottom;
			document.querySelectorAll(noticeSelectors.join(',')).forEach((node) => {
				if (!(node instanceof HTMLElement)) return;
				if (node.closest('#poststation-app')) return;
				if (node.offsetParent === null) return;

				const rect = node.getBoundingClientRect();
				if (rect.height <= 0 || rect.bottom <= adminBarBottom) return;
				noticeBottom = Math.max(noticeBottom, rect.bottom);
			});

			const topOffset = Math.max(adminBarBottom, noticeBottom);
			appRoot.style.setProperty('--poststation-top-offset', `${Math.round(topOffset)}px`);
		};

		const rafUpdate = () => window.requestAnimationFrame(updateTopOffset);
		rafUpdate();

		window.addEventListener('resize', rafUpdate, { passive: true });
		window.addEventListener('scroll', rafUpdate, { passive: true });

		const observer = new MutationObserver(rafUpdate);
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: ['class', 'style'],
		});

		return () => {
			window.removeEventListener('resize', rafUpdate);
			window.removeEventListener('scroll', rafUpdate);
			observer.disconnect();
		};
	}, []);

	return (
		<div className="min-h-screen bg-gray-50 flex">
			{isSidebarOpen && (
				<button
					type="button"
					onClick={() => setIsSidebarOpen(false)}
					className="fixed inset-0 bg-black/30 z-99970 lg:hidden poststation-mobile-overlay"
					aria-label="Close navigation"
				/>
			)}
			{/* Sidebar */}
			<aside
				className={`poststation-desktop-sidebar fixed inset-y-0 left-0 z-99980 w-64 bg-white border-r border-gray-200 flex flex-col transform transition-transform duration-200 ease-out poststation-mobile-sidebar ${
					isSidebarOpen ? 'translate-x-0' : '-translate-x-full'
				} lg:translate-x-0`}
			>
				{/* Logo */}
				<div className="h-16 flex items-center px-6 border-b border-gray-200">
					<h1 className="text-xl font-semibold text-gray-900">Post Station</h1>
				</div>

				{/* Navigation */}
				<nav className="flex-1 p-4 space-y-1">
					{navItems.map((item) => (
						<NavLink
							key={item.to}
							to={item.to}
							onClick={(event) => {
								if (isDirty) {
									const shouldProceed = window.confirm(
										'You have unsaved changes. Leave without saving?'
									);
									if (!shouldProceed) {
										event.preventDefault();
										return;
									}
								}
								setIsSidebarOpen(false);
							}}
							className={({ isActive }) =>
								`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
									isActive
										? 'bg-indigo-50 text-indigo-700'
										: 'text-gray-700 hover:bg-gray-100'
								}`
							}
						>
							<item.icon className="w-5 h-5" />
							{item.label}
						</NavLink>
					))}
				</nav>

				{/* Footer */}
				<div className="p-4 border-t border-gray-200">
					<p className="text-xs text-gray-500">PostStation v1.0</p>
				</div>
			</aside>

			{/* Main content */}
			<main className="flex-1 min-w-0">
				<div className="p-4 sm:p-6">
					<div className="flex items-center gap-3 mb-4 lg:hidden">
						<button
							type="button"
							onClick={() => setIsSidebarOpen(true)}
							className="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50"
							aria-label="Open navigation"
						>
							<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
							</svg>
						</button>
						<span className="text-lg font-semibold text-gray-900">Post Station</span>
					</div>
					{children}
				</div>
			</main>
		</div>
	);
}
