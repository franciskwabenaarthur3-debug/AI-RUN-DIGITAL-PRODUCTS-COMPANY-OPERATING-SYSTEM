<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

/**
 * Registers the seven logical agents, disabled, with their default tool
 * grants. Safe to re-run: existing agents (and any changes the founder made
 * to their status or permissions) are left untouched.
 */
class AgentSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, description: string, tools: list<string>}>
     */
    public const AGENTS = [
        'orchestrator' => [
            'name' => 'AI CEO',
            'description' => 'Breaks founder objectives into tasks, assigns agents, monitors progress, escalates blockers and writes daily operating reports. Cannot grant itself permissions or approve its own restricted actions.',
            'tools' => ['create_project_task', 'request_approval', 'generate_operating_report'],
        ],
        'research' => [
            'name' => 'Research Agent',
            'description' => 'Researches markets and prospects from permitted sources, reviews public websites and scores opportunities. Every claim needs a source or is marked as an assumption.',
            'tools' => ['search_prospects', 'analyze_website', 'create_lead'],
        ],
        'sales' => [
            'name' => 'Sales Agent',
            'description' => 'Qualifies leads, drafts outreach and proposals, tracks follow-ups. Cannot send messages or proposals, change prices or accept obligations.',
            'tools' => ['update_lead_status', 'draft_outreach', 'draft_proposal', 'validate_proposal', 'request_approval'],
        ],
        'engineering' => [
            'name' => 'Product & Engineering Agent',
            'description' => 'Turns approved scope into requirements, plans and code changes in isolated branches, runs allowlisted checks and prepares release notes. No production access.',
            'tools' => ['generate_project_plan', 'create_project_task', 'run_quality_checks', 'request_approval'],
        ],
        'marketing' => [
            'name' => 'Marketing Agent',
            'description' => 'Drafts website copy, landing pages, SEO briefs and content calendars. Publishing and paid campaigns require approval.',
            'tools' => ['request_approval'],
        ],
        'customer_success' => [
            'name' => 'Customer Success Agent',
            'description' => 'Classifies customer requests and drafts responses from approved documentation. Escalates complaints, refunds, legal and security issues. Draft-only.',
            'tools' => ['draft_customer_response', 'schedule_maintenance_task', 'request_approval'],
        ],
        'finance' => [
            'name' => 'Finance & Operations Agent',
            'description' => 'Summarizes recorded expenses, verified sales and AI spend against budgets. Never initiates payments or invents transactions.',
            'tools' => ['generate_operating_report'],
        ],
    ];

    public function run(): void
    {
        foreach (self::AGENTS as $role => $definition) {
            $agent = Agent::query()->firstOrCreate(['role' => $role], [
                'name' => $definition['name'],
                'slug' => str_replace('_', '-', $role),
                'description' => $definition['description'],
                // New installs start on the newest instructions for the role.
                'instructions_version' => collect(glob(resource_path("agents/{$role}/*.md")) ?: [])->map(fn ($f) => basename($f, '.md'))->sort(SORT_NATURAL)->last() ?? 'v1',
                'runtime' => 'mock',
                'model_key' => null,
            ]);

            if ($agent->wasRecentlyCreated) {
                foreach ($definition['tools'] as $tool) {
                    $agent->permissions()->create(['tool_name' => $tool, 'resource_scope' => '*', 'allowed' => true]);
                }
            }
        }
    }
}
