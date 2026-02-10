import { useCallback, useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import {
	Button,
	Table,
	TableHead,
	TableBody,
	TableRow,
	TableHeader,
	TableCell,
	EmptyState,
	PageHeader,
	PageLoader,
	ConfirmModal,
	CountsBadge,
} from '../components/common';
import { postworks, getPostTypes, getBootstrapPostworks, refreshBootstrap } from '../api/client';
import { useQuery, useMutation } from '../hooks/useApi';

export default function PostWorksPage() {
	const navigate = useNavigate();
	const [deleteId, setDeleteId] = useState(null);
	const importRef = useRef(null);

	const bootstrapPostworks = getBootstrapPostworks();
	const fetchPostWorks = useCallback(() => postworks.getAll(), []);
	const { data, loading, error, refetch } = useQuery(fetchPostWorks, [], { initialData: bootstrapPostworks });
	const { mutate: createPostWork, loading: creating } = useMutation(postworks.create, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: deletePostWork, loading: deleting } = useMutation(postworks.delete, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: importPostWork, loading: importing } = useMutation(postworks.import, {
		onSuccess: refreshBootstrap,
	});
	const { mutate: exportPostWork } = useMutation(postworks.export);

	const postTypes = getPostTypes();

	const handleCreate = async () => {
		try {
			const result = await createPostWork();
			if (result?.id) {
				navigate(`/postworks/${result.id}`);
			}
		} catch (err) {
			console.error('Failed to create PostWork:', err);
		}
	};

	const handleDelete = async () => {
		if (deleteId) {
			await deletePostWork(deleteId);
			refetch();
		}
	};

	const handleImport = async (e) => {
		const file = e.target.files?.[0];
		if (!file) return;

		try {
			const result = await importPostWork(file);
			if (result?.id) {
				navigate(`/postworks/${result.id}`);
			}
			refetch();
		} catch (err) {
			console.error('Failed to import:', err);
		}
		e.target.value = '';
	};

	const handleExport = async (id) => {
		try {
			const result = await exportPostWork(id);
			// Create download
			const blob = new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = `postwork-${id}.json`;
			a.click();
			URL.revokeObjectURL(url);
		} catch (err) {
			console.error('Failed to export:', err);
		}
	};

	if (loading) return <PageLoader />;

	const postWorksList = data?.postworks || [];

	return (
		<div>
			<PageHeader
				title="Post Works"
				description="Manage batch post creation workflows"
				actions={
					<>
						<input
							ref={importRef}
							type="file"
							accept=".json"
							className="hidden"
							onChange={handleImport}
						/>
						<Button variant="secondary" onClick={() => importRef.current?.click()} loading={importing}>
							Import
						</Button>
						<Button onClick={handleCreate} loading={creating}>
							Add New
						</Button>
					</>
				}
			/>

			{postWorksList.length === 0 ? (
				<EmptyState
					icon={
						<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" className="w-full h-full">
							<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
						</svg>
					}
					title="No Post Works yet"
					description="Create your first Post Work to start batch post creation"
					action={
						<Button onClick={handleCreate} loading={creating}>
							Create Post Work
						</Button>
					}
				/>
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Title</TableHeader>
							<TableHeader>Post Type</TableHeader>
							<TableHeader>Blocks</TableHeader>
							<TableHeader>Created</TableHeader>
							<TableHeader className="w-32">Actions</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{postWorksList.map((postWork) => (
							<TableRow key={postWork.id}>
								<TableCell>
									<button
										onClick={() => navigate(`/postworks/${postWork.id}`)}
										className="font-medium text-indigo-600 hover:text-indigo-900"
									>
										{postWork.title || `Post Work #${postWork.id}`}
									</button>
								</TableCell>
								<TableCell>
									<span className="inline-flex items-center px-2 py-1 rounded-md bg-gray-100 text-gray-700 text-sm">
										{postTypes[postWork.post_type] || postWork.post_type}
									</span>
								</TableCell>
								<TableCell>
									<CountsBadge counts={postWork.block_counts} />
									{!postWork.block_counts && (
										<span className="text-gray-400 text-sm">No blocks</span>
									)}
								</TableCell>
								<TableCell>
									{new Date(postWork.created_at).toLocaleDateString()}
								</TableCell>
								<TableCell>
									<div className="flex items-center gap-2">
										<button
											onClick={() => navigate(`/postworks/${postWork.id}`)}
											className="text-indigo-600 hover:text-indigo-900 text-sm font-medium"
										>
											Edit
										</button>
										<button
											onClick={() => handleExport(postWork.id)}
											className="text-gray-600 hover:text-gray-900 text-sm font-medium"
										>
											Export
										</button>
										<button
											onClick={() => setDeleteId(postWork.id)}
											className="text-red-600 hover:text-red-900 text-sm font-medium"
										>
											Delete
										</button>
									</div>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}

			<ConfirmModal
				isOpen={deleteId !== null}
				onClose={() => setDeleteId(null)}
				onConfirm={handleDelete}
				title="Delete Post Work"
				message="Are you sure you want to delete this Post Work and all its blocks? This action cannot be undone."
				confirmText="Delete"
			/>
		</div>
	);
}
