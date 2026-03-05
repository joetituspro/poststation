<?php

namespace PostStation\Services\Workflow;

use PostStation\Models\WorkflowSpec;

class WorkflowSpecService
{
	private WorkflowSpecImporter $importer;

	public function __construct(?WorkflowSpecImporter $importer = null)
	{
		$this->importer = $importer ?? new WorkflowSpecImporter();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_active_spec()
	{
		$row = WorkflowSpec::get_active();
		if (!$row) {
			$this->importer->ensure_default_spec_seeded();
			$row = WorkflowSpec::get_active();
		}

		if (!$row) {
			return new \WP_Error('poststation_workflow_spec_missing', 'No active local workflow specification found.');
		}

		$spec = json_decode((string) ($row['spec_json'] ?? ''), true);
		if (!is_array($spec)) {
			return new \WP_Error('poststation_workflow_spec_invalid', 'Active local workflow specification is invalid JSON.');
		}

		$spec['db_id'] = (int) ($row['id'] ?? 0);
		$spec['db_version'] = (string) ($row['version'] ?? '');
		return $spec;
	}

	public function reseed_default_spec(): bool
	{
		return $this->importer->ensure_default_spec_seeded();
	}
}

