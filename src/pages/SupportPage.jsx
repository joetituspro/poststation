import { useEffect, useState } from 'react';
import { Button, Card, CardBody, CardHeader, Input, PageHeader, useToast } from '../components/common';
import { getBootstrapSupport, refreshBootstrap, support } from '../api/client';
import { useMutation } from '../hooks/useApi';

export default function SupportPage() {
	const { showToast } = useToast();
	const bootstrapSupport = getBootstrapSupport();
	const [supportState, setSupportState] = useState(bootstrapSupport);
	const [licenseKeyInput, setLicenseKeyInput] = useState('');
	const [licenseKeyTouched, setLicenseKeyTouched] = useState(false);

	useEffect(() => {
		const state = supportState || getBootstrapSupport();
		if (!state || licenseKeyTouched) return;

		const isActive = Boolean(state?.license?.status?.valid);
		setLicenseKeyInput(isActive ? state?.license?.truncated_key || '' : '');
	}, [supportState, licenseKeyTouched]);

	const licenseStatus = supportState?.license?.status || {};
	const licenseValid = Boolean(licenseStatus?.valid ?? (licenseStatus?.status === 'active'));
	const pluginAutoUpdateEnabled = Boolean(supportState?.updates?.plugin_auto_update_enabled);
	const pluginLatest = supportState?.updates?.plugin_latest?.new_version || '';
	const pluginCurrent = supportState?.updates?.plugin_current_version || '';

	const { mutate: saveLicense, loading: savingLicense } = useMutation(support.saveLicense, {
		onSuccess: async (result) => {
			showToast(result?.message || 'License activated successfully.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});
	const { mutate: deactivateLicense, loading: deactivatingLicense } = useMutation(support.deactivateLicense, {
		onSuccess: async (result) => {
			showToast(result?.message || 'License deactivated successfully.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
			setLicenseKeyInput('');
		},
	});
	const { mutate: refreshLicense, loading: refreshingLicense } = useMutation(support.refreshLicense, {
		onSuccess: async () => {
			showToast('License status refreshed.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});
	const { mutate: checkPluginUpdate, loading: checkingUpdates } = useMutation(support.checkPluginUpdate, {
		onSuccess: async () => {
			showToast('Plugin update check completed.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});
	const { mutate: setAutoUpdatePlugin, loading: savingAutoUpdate } = useMutation(support.setAutoUpdatePlugin, {
		onSuccess: async () => {
			showToast('Plugin auto-update setting saved.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});

	return (
		<div>
			<PageHeader title="Support" description="License and plugin update management." />
			<div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-5xl w-full mx-auto">
				<Card>
					<CardHeader>
						<h3 className="text-lg font-medium text-gray-900">License Management</h3>
					</CardHeader>
					<CardBody>
						<div className="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3">
							<Input
								label="License Key"
								value={licenseKeyInput}
								onChange={(e) => {
									setLicenseKeyTouched(true);
									setLicenseKeyInput(e.target.value);
								}}
								onFocus={() => {
									if ((licenseKeyInput || '').includes('...')) setLicenseKeyInput('');
								}}
								placeholder={supportState?.license?.key_set ? 'License key is stored (hidden). Enter to replace.' : 'Enter your license key'}
								disabled={licenseValid}
							/>
							<div className="flex items-end gap-2">
								{licenseValid ? (
									<>
										<Button variant="danger" onClick={() => deactivateLicense()} loading={deactivatingLicense}>Deactivate</Button>
										<Button variant="secondary" onClick={() => refreshLicense()} loading={refreshingLicense}>Refresh</Button>
									</>
								) : (
									<Button onClick={() => saveLicense({ licenseKey: licenseKeyInput })} loading={savingLicense}>Activate</Button>
								)}
							</div>
						</div>
						<div className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
							<div>
								<p className="text-gray-500">Plan</p>
								<p className="font-medium text-gray-900">{licenseStatus?.plan_name || 'N/A'}</p>
							</div>
							<div>
								<p className="text-gray-500">Expiration</p>
								<p className="font-medium text-gray-900">{licenseStatus?.expires_at || 'N/A'}</p>
							</div>
							<div className="flex items-end gap-2">
								<span className={`inline-flex rounded px-2 py-1 text-xs ${licenseValid ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
									{licenseValid ? 'Valid' : 'Not Valid'}
								</span>
							</div>
						</div>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3 className="text-lg font-medium text-gray-900">Plugin Updates</h3>
					</CardHeader>
					<CardBody>
						<div className="flex flex-wrap gap-2 items-center mb-3">
							<Button variant="secondary" onClick={() => checkPluginUpdate()} loading={checkingUpdates}>
								Check Updates
							</Button>
						</div>
						<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700">
							<input
								type="checkbox"
								className="poststation-field-checkbox"
								checked={pluginAutoUpdateEnabled}
								disabled={savingAutoUpdate}
								onChange={(e) => setAutoUpdatePlugin(e.target.checked)}
							/>
							<span className="poststation-switch-track" aria-hidden />
							<span>Enable automatic plugin updates from Rankima</span>
						</label>
						<div className="mt-2 text-xs text-gray-600">
							Current plugin version: {pluginCurrent || 'N/A'}
							{pluginLatest && ` | Latest available: ${pluginLatest}`}
						</div>
					</CardBody>
				</Card>
			</div>
		</div>
	);
}
