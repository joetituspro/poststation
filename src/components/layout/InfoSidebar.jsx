export default function InfoSidebar() {
	return (
		<div className="w-full xl:w-80 shrink-0">
			{/* Quick Tips Card */}
			<div className="bg-white rounded-lg border border-gray-200 shadow-sm mb-4">
				<div className="px-4 py-3 border-b border-gray-200">
					<h3 className="text-sm font-medium text-gray-900">Quick Tips</h3>
				</div>
				<div className="px-4 py-3 space-y-3">
					<div className="flex gap-3">
						<div className="shrink-0 w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-medium">
							1
						</div>
						<p className="text-sm text-gray-600">
							Configure your content fields to define what gets generated for each post.
						</p>
					</div>
					<div className="flex gap-3">
						<div className="shrink-0 w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-medium">
							2
						</div>
						<p className="text-sm text-gray-600">
							Add blocks with topics/keywords to create multiple posts at once.
						</p>
					</div>
					<div className="flex gap-3">
						<div className="shrink-0 w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs font-medium">
							3
						</div>
						<p className="text-sm text-gray-600">
							Click Run to process all pending blocks through your configured webhook.
						</p>
					</div>
				</div>
			</div>

			{/* Keyboard Shortcuts */}
			<div className="bg-white rounded-lg border border-gray-200 shadow-sm mb-4">
				<div className="px-4 py-3 border-b border-gray-200">
					<h3 className="text-sm font-medium text-gray-900">Shortcuts</h3>
				</div>
				<div className="px-4 py-3 space-y-2">
					<div className="flex justify-between text-sm">
						<span className="text-gray-600">Save changes</span>
						<kbd className="px-2 py-0.5 bg-gray-100 rounded text-xs font-mono text-gray-700">Ctrl+S</kbd>
					</div>
					<div className="flex justify-between text-sm">
						<span className="text-gray-600">Add new block</span>
						<kbd className="px-2 py-0.5 bg-gray-100 rounded text-xs font-mono text-gray-700">Ctrl+N</kbd>
					</div>
				</div>
			</div>

			{/* Ad/Promo Area */}
			<div className="bg-linear-to-br from-indigo-500 to-purple-600 rounded-lg shadow-sm p-4 text-white">
				<h3 className="font-medium mb-2">Need More Power?</h3>
				<p className="text-sm text-indigo-100 mb-3">
					Upgrade to Pro for unlimited posts, advanced AI models, and priority support.
				</p>
				<a
					href="#"
					className="inline-block px-4 py-2 bg-white text-indigo-600 rounded-lg text-sm font-medium hover:bg-indigo-50 transition-colors"
				>
					Learn More
				</a>
			</div>

			{/* Help Link */}
			<div className="mt-4 text-center">
				<a href="#" className="text-sm text-gray-500 hover:text-gray-700">
					Need help? View documentation
				</a>
			</div>
		</div>
	);
}
