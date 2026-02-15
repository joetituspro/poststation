import { Routes, Route, Navigate } from 'react-router-dom';
import { ToastProvider } from './components/common';
import AppShell from './components/layout/AppShell';
import { UnsavedChangesProvider } from './context/UnsavedChangesContext';
import SettingsPage from './pages/SettingsPage';
import CampaignsPage from './pages/CampaignsPage';
import CampaignEditPage from './pages/CampaignEditPage';
import WebhooksPage from './pages/WebhooksPage';
import WebhookFormPage from './pages/WebhookFormPage';

export default function App() {
	return (
		<ToastProvider>
			<UnsavedChangesProvider>
				<AppShell>
					<Routes>
					<Route path="/" element={<Navigate to="/campaigns" replace />} />
					<Route path="/settings" element={<SettingsPage />} />
					<Route path="/campaigns" element={<CampaignsPage />} />
					<Route path="/campaigns/:id" element={<CampaignEditPage />} />
					<Route path="/webhooks" element={<WebhooksPage />} />
					<Route path="/webhooks/new" element={<WebhookFormPage />} />
					<Route path="/webhooks/:id" element={<WebhookFormPage />} />
					</Routes>
				</AppShell>
			</UnsavedChangesProvider>
		</ToastProvider>
	);
}
