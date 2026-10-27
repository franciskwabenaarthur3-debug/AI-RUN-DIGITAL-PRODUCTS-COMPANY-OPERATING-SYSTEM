<?php

namespace App\Agents;

/**
 * The fixed list of tools agents may be granted. Agents cannot define new
 * tools; execution through a policy-checked gateway arrives in Phase 4.
 */
final class ToolCatalog
{
    /**
     * name => [description, risk level (1 autonomous, 2 approval required)]
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const TOOLS = [
        'search_prospects' => ['Search permitted sources for prospects', 1],
        'analyze_website' => ['Analyze a public website for improvements', 1],
        'create_lead' => ['Create a lead record', 1],
        'update_lead_status' => ['Change a lead status within the workflow', 1],
        'draft_outreach' => ['Draft an outreach message (not sent)', 1],
        'draft_proposal' => ['Draft a proposal from an approved package', 1],
        'validate_proposal' => ['Validate a proposal draft', 1],
        'request_approval' => ['Ask the founder to approve a Level 2 action', 1],
        'create_project' => ['Create a project from an accepted proposal', 2],
        'create_project_task' => ['Create a task within a project', 1],
        'generate_project_plan' => ['Draft a project plan', 1],
        'run_quality_checks' => ['Run allowlisted quality checks in isolation', 1],
        'prepare_release' => ['Prepare release notes and evidence', 2],
        'draft_customer_response' => ['Draft a customer response (not sent)', 1],
        'schedule_maintenance_task' => ['Schedule a maintenance task', 1],
        'generate_operating_report' => ['Generate an internal operating report', 1],
    ];

    public static function exists(string $tool): bool
    {
        return array_key_exists($tool, self::TOOLS);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TOOLS);
    }
}
