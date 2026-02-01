import { Routes, Route, Navigate } from 'react-router-dom';
import AppShell from './components/layout/AppShell';
import SettingsPage from './pages/SettingsPage';
import PostWorksPage from './pages/PostWorksPage';
import PostWorkEditPage from './pages/PostWorkEditPage';
import WebhooksPage from './pages/WebhooksPage';
import WebhookFormPage from './pages/WebhookFormPage';

export default function App() {
	return (
		<AppShell>
			<Routes>
				<Route path="/" element={<Navigate to="/postworks" replace />} />
				<Route path="/settings" element={<SettingsPage />} />
				<Route path="/postworks" element={<PostWorksPage />} />
				<Route path="/postworks/:id" element={<PostWorkEditPage />} />
				<Route path="/webhooks" element={<WebhooksPage />} />
				<Route path="/webhooks/new" element={<WebhookFormPage />} />
				<Route path="/webhooks/:id" element={<WebhookFormPage />} />
			</Routes>
		</AppShell>
	);
}
