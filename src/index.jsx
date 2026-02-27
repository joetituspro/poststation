import { createRoot } from 'react-dom/client';
import { HashRouter } from 'react-router-dom';
import App from './App';
import './index.css';

const appId = window.poststation?.plugin_app_id || 'poststation-app';
const container = document.getElementById(appId);

if (container) {
	const root = createRoot(container);
	root.render(
		<HashRouter>
			<App />
		</HashRouter>
	);
}
