import { getPluginName } from '../../api/client';

export default function InfoSidebar() {
	const pluginName = getPluginName();

	return (
		<div className="w-full xl:w-64 shrink-0">
			<div className="bg-white rounded-lg border border-gray-200 shadow-sm mb-4">
				<div className="px-4 py-3 border-b border-gray-200">
					<h3 className="text-sm font-medium text-gray-900">
						About {pluginName}
					</h3>
				</div>
				<div className="px-4 py-3">
					<p className="text-sm text-gray-600">
						{pluginName} runs a fully local workflow inside your WordPress
						site for AI content generation, scheduling, and publishing from
						one dashboard.
					</p>
				</div>
			</div>
		</div>
	);
}
