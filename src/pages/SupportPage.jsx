import { useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Input,
	Modal,
	PageHeader,
	PageLoader,
	useToast,
} from '../components/common';
import { getBootstrapSupport, refreshBootstrap, support } from '../api/client';
import { useMutation } from '../hooks/useApi';

function StepCard({ title, subtitle, active, locked, complete, children }) {
	return (
		<div className={`rounded-lg border p-4 transition ${active ? 'border-indigo-500 bg-indigo-50/40' : 'border-gray-200 bg-white'} ${locked ? 'blur-[1px] opacity-60 pointer-events-none' : ''}`}>
			<div className="mb-2 flex items-center justify-between">
				<div>
					<h4 className="text-sm font-semibold text-gray-900">{title}</h4>
					<p className="text-xs text-gray-500">{subtitle}</p>
				</div>
				{complete && <span className="text-xs rounded bg-green-100 px-2 py-1 text-green-700">Complete</span>}
			</div>
			{children}
		</div>
	);
}

export default function SupportPage() {
	const location = useLocation();
	const { showToast } = useToast();
	const bootstrapSupport = getBootstrapSupport();
	const [supportState, setSupportState] = useState(bootstrapSupport);
	const [licenseKeyInput, setLicenseKeyInput] = useState('');
	const [licenseKeyTouched, setLicenseKeyTouched] = useState(false);
	const [manualBlueprint, setManualBlueprint] = useState(null);
	const [onboardingOpen, setOnboardingOpen] = useState(false);
	const [onboardingStep, setOnboardingStep] = useState(1);

	// Sync local inputs from bootstrap-backed support state
	useEffect(() => {
		const state = supportState || getBootstrapSupport();
		if (!state) return;

		if (!licenseKeyTouched) {
			const isActive = Boolean(state?.license?.status?.valid);
			if (isActive) {
				setLicenseKeyInput(state?.license?.truncated_key || '');
			} else {
				setLicenseKeyInput('');
			}
		}
	}, [supportState, licenseKeyTouched]);

	const licenseStatus = supportState?.license?.status || {};
	const licenseValid = Boolean(
		licenseStatus?.valid ?? (licenseStatus?.status === 'active')
	);
	const n8nConfigured = Boolean(supportState?.n8n?.base_url && supportState?.n8n?.n8n_api_key_set);
	const hasOnboardingQuery = useMemo(() => new URLSearchParams(location.search).get('onboarding') === '1', [location.search]);

	useEffect(() => {
		const required = Boolean(supportState?.onboarding_required);
		if (required || hasOnboardingQuery) {
			setOnboardingOpen(true);
		}
	}, [supportState?.onboarding_required, hasOnboardingQuery]);

	const { mutate: saveLicense, loading: savingLicense } = useMutation(support.saveLicense, {
		onSuccess: async (result) => {
			if (result?.license) {
				setSupportState((prev) => ({
					...prev,
					license: {
						...(prev?.license || {}),
						...result.license,
						status: result.status || prev?.license?.status || {},
					},
				}));
			}
			showToast(result?.message || 'License activated successfully.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});

	const { mutate: deactivateLicense, loading: deactivatingLicense } = useMutation(support.deactivateLicense, {
		onSuccess: async (result) => {
			if (result?.license) {
				setSupportState((prev) => ({
					...prev,
					license: {
						...(prev?.license || {}),
						...result.license,
						status: result.status || prev?.license?.status || {},
					},
				}));
			}
			showToast(result?.message || 'License deactivated successfully.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
			setLicenseKeyInput('');
		},
	});

	const { mutate: refreshLicense, loading: refreshingLicense } = useMutation(support.refreshLicense, {
		onSuccess: async (result) => {
			if (result?.license) {
				setSupportState((prev) => ({
					...prev,
					license: {
						...(prev?.license || {}),
						...result.license,
						status: result.status || prev?.license?.status || {},
					},
				}));
			}
			showToast('License status refreshed.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});

	const { mutate: deployN8nBlueprint, loading: deploying } = useMutation(support.deployN8nBlueprint, {
		onSuccess: async (result) => {
			showToast('Blueprint deployed successfully.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
			setOnboardingOpen(false);
		},
	});

	const { mutate: fetchManualBlueprint, loading: loadingManualBlueprint } = useMutation(support.getManualBlueprint, {
		onSuccess: (result) => setManualBlueprint(result),
	});

	const { mutate: checkBlueprintUpdate, loading: checkingBlueprint } = useMutation(support.checkBlueprintUpdate, {
		onSuccess: async (result) => {
			showToast('Blueprint update check completed.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});

	const { mutate: setAutoUpdatePlugin, loading: savingAutoUpdate } = useMutation(support.setAutoUpdatePlugin, {
		onSuccess: async (result) => {
			showToast('Plugin auto-update setting saved.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
		},
	});

	const { mutate: completeOnboarding } = useMutation(support.completeOnboarding, {
		onSuccess: async (result) => {
			showToast('Onboarding completed.', 'success');
			await refreshBootstrap();
			setSupportState(getBootstrapSupport());
			setOnboardingOpen(false);
		},
	});

	const saveLicenseAction = async () => {
		try {
			await saveLicense({ licenseKey: licenseKeyInput });
			setOnboardingStep(2);
		} catch (e) {
			showToast(e.message || 'Unable to save license.', 'error');
		}
	};

	const deployAction = async () => {
		try {
			await deployN8nBlueprint();
		} catch (e) {
			showToast(e.message || 'Deployment failed.', 'error');
		}
	};

	const skipN8nOnboarding = async () => {
		try {
			await completeOnboarding();
		} catch (e) {
			showToast(e.message || 'Could not complete onboarding.', 'error');
		}
	};

	const activateFromPage = async () => {
		try {
			await saveLicense({ licenseKey: licenseKeyInput });
		} catch (e) {
			showToast(e.message || 'Unable to activate license.', 'error');
		}
	};

	const deactivateFromPage = async () => {
		try {
			await deactivateLicense();
			setLicenseKeyTouched(false);
		} catch (e) {
			showToast(e.message || 'Unable to deactivate license.', 'error');
		}
	};

	const refreshLicenseFromPage = async () => {
		try {
			await refreshLicense();
		} catch (e) {
			showToast(e.message || 'Unable to refresh license status.', 'error');
		}
	};

	const checkBlueprintUpdateFromPage = async () => {
		try {
			await checkBlueprintUpdate({ force: true });
		} catch (e) {
			showToast(e.message || 'Blueprint update check failed.', 'error');
		}
	};

	const setAutoUpdateFromPage = async (enabled) => {
		try {
			await setAutoUpdatePlugin(enabled);
		} catch (e) {
			showToast(e.message || 'Unable to update plugin auto-update setting.', 'error');
		}
	};

	const loadManualBlueprintFromPage = async () => {
		try {
			await fetchManualBlueprint();
		} catch (e) {
			showToast(e.message || 'Unable to load manual blueprint.', 'error');
		}
	};

	const blueprintUpdate = supportState?.n8n?.blueprint_update || {};
	const pluginAutoUpdateEnabled = Boolean(supportState?.updates?.plugin_auto_update_enabled);
	const pluginLatest = supportState?.updates?.plugin_latest?.new_version || '';
	const pluginCurrent = supportState?.updates?.plugin_current_version || '';

	return (
		<div>
			<PageHeader
				title="Support"
				description="License, n8n deployment, manual setup, and update management."
			/>

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
									if ((licenseKeyInput || '').includes('...')) {
										setLicenseKeyInput('');
									}
								}}
								placeholder={supportState?.license?.key_set ? 'License key is stored (hidden). Enter to replace.' : 'Enter your license key'}
								disabled={licenseValid}
							/>
							<div className="flex items-end gap-2">
								{licenseValid ? (
									<>
										<Button variant="danger" onClick={deactivateFromPage} loading={deactivatingLicense}>
											Deactivate
										</Button>
										<Button variant="secondary" onClick={refreshLicenseFromPage} loading={refreshingLicense}>
											Refresh
										</Button>
									</>
								) : (
									<Button onClick={activateFromPage} loading={savingLicense}>
										Activate
									</Button>
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
								{licenseStatus?.manage_url && (
									<a className="text-indigo-600 text-sm underline" href={licenseStatus.manage_url} target="_blank" rel="noreferrer">
										Manage
									</a>
								)}
							</div>
						</div>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3 className="text-lg font-medium text-gray-900">Update Center</h3>
					</CardHeader>
					<CardBody>
						<div className="flex flex-wrap gap-2 items-center mb-3">
							<Button variant="secondary" onClick={checkBlueprintUpdateFromPage} loading={checkingBlueprint}>
								Check Blueprint Update
							</Button>
							<span className={`text-xs rounded px-2 py-1 ${blueprintUpdate?.update_available ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700'}`}>
								{blueprintUpdate?.update_available ? 'Blueprint Update Available' : 'Blueprint Up to Date'}
							</span>
							{blueprintUpdate?.latest_version && <span className="text-xs text-gray-600">Latest: {blueprintUpdate.latest_version}</span>}
						</div>
						<label className="poststation-switch inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700">
							<input
								type="checkbox"
								className="poststation-field-checkbox"
								checked={pluginAutoUpdateEnabled}
								disabled={savingAutoUpdate}
								onChange={(e) => setAutoUpdateFromPage(e.target.checked)}
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

			<Modal isOpen={onboardingOpen} onClose={() => setOnboardingOpen(false)} title="Welcome to Post Station Setup" size="lg">
				<div className="space-y-4">
					<StepCard
						title="Step 1: Activate License"
						subtitle="Required"
						active={onboardingStep === 1}
						locked={false}
						complete={licenseValid}
					>
						<div className="space-y-3">
							<Input
								label="License Key"
								value={licenseKeyInput}
								onChange={(e) => {
									setLicenseKeyTouched(true);
									setLicenseKeyInput(e.target.value);
								}}
								onFocus={() => {
									if ((licenseKeyInput || '').includes('...')) {
										setLicenseKeyInput('');
									}
								}}
								placeholder="Enter your license key"
								disabled={licenseValid}
							/>
							<div className="flex gap-2">
								<Button onClick={saveLicenseAction} loading={savingLicense}>Save & Continue</Button>
								{licenseValid && (
									<Button variant="secondary" onClick={refreshLicenseFromPage} loading={refreshingLicense}>
										Refresh Status
									</Button>
								)}
							</div>
						</div>
					</StepCard>

					<StepCard
						title="Step 2: n8n Connection Settings"
						subtitle="Optional"
						active={onboardingStep === 2}
						locked={!licenseValid}
						complete={n8nConfigured}
					>
						<div className="space-y-3">
							<p className="text-sm text-gray-600">
								n8n connection has moved to the Settings page.
								Configure Base URL and API Key there, then return to deploy.
							</p>
							<div className="flex flex-wrap gap-2">
								<a href="#/settings">
									<Button variant="secondary" disabled={!licenseValid}>Open Settings</Button>
								</a>
								<Button variant="secondary" onClick={skipN8nOnboarding} disabled={!licenseValid}>Skip (Manual Later)</Button>
								{n8nConfigured && (
									<Button onClick={() => setOnboardingStep(3)} disabled={!licenseValid}>Continue</Button>
								)}
							</div>
						</div>
					</StepCard>

					<StepCard
						title="Step 3: Deploy Blueprint"
						subtitle="Deploy now to finish onboarding"
						active={onboardingStep === 3}
						locked={!licenseValid || !n8nConfigured}
						complete={Boolean(supportState?.n8n?.workflow_id)}
					>
						<div className="space-y-2">
							<p className="text-sm text-gray-600">
								This will create/update n8n credentials and import the latest Rankima blueprint.
							</p>
							<div className="flex gap-2">
								<Button onClick={deployAction} loading={deploying} disabled={!licenseValid || !n8nConfigured}>Deploy Now</Button>
								<Button variant="secondary" onClick={() => setOnboardingStep(2)}>Back</Button>
							</div>
						</div>
					</StepCard>
				</div>
			</Modal>
		</div>
	);
}
