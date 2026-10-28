<?php

namespace App\Http\Controllers;

use App\Enums\DeliverableStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\InvalidTransition;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\Agent;
use App\Models\MaintenancePlan;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectMilestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use App\Rules\LinkWithoutCredentials;
use App\Services\ProjectService;
use App\Services\ReleaseService;
use App\Services\TaskService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
        ]);

        $projects = Project::query()
            ->with(['customer:id,company_name', 'proposal.lead:id,company_name'])
            ->withCount(['overdueMilestones', 'milestones', 'milestones as completed_milestones_count' => fn ($q) => $q->where('status', MilestoneStatus::Completed)])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('projects.index', ['projects' => $projects, 'filters' => $filters, 'statuses' => ProjectStatus::cases()]);
    }

    public function show(Project $project, ReleaseService $releases): View
    {
        $this->authorize('view', $project);
        $project->load(['customer', 'proposal', 'milestones.tasks.agent:id,name', 'milestones.tasks.assignee:id,name', 'deliverables', 'releases.checks', 'releases.approvalRequest', 'events']);

        return view('projects.show', [
            'project' => $project,
            'unplannedTasks' => $project->tasks()->whereNull('milestone_id')->with(['agent:id,name', 'assignee:id,name'])->get(),
            'changeRequests' => Proposal::query()->where('project_id', $project->id)->latest('id')->get(),
            'maintenancePlans' => MaintenancePlan::query()->where('project_id', $project->id)->latest('id')->get(),
            'productionProblems' => $project->releases->mapWithKeys(fn ($r) => [$r->id => $r->isProduction() ? $releases->productionProblems($r) : []]),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Project $project): View
    {
        $this->authorize('update', $project);

        return view('projects.edit', ['project' => $project]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->projects->updateDetails($project, $request->validated(), $request->user());

        return redirect()->route('projects.show', $project)->with('status', 'Project details saved.');
    }

    /** For proposals accepted before projects existed; acceptance creates one automatically. */
    public function createFromProposal(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorize('create', Project::class);

        try {
            $project = $this->projects->createFromProposal($proposal, $request->user());
        } catch (InvalidTransition $e) {
            return redirect()->route('proposals.show', $proposal)->withErrors(['proposal' => $e->getMessage()]);
        }

        return redirect()->route('projects.show', $project)->with('status', 'Project ready.');
    }

    public function status(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt($project, function () use ($request, $project, $data) {
            $target = ProjectStatus::from($data['status']);
            $this->projects->changeStatus($project, $target, $request->user(), $data['note'] ?? null);

            return "Project moved to {$target->label()}.";
        });
    }

    public function feedback(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(['in_scope', 'out_of_scope'])],
        ]);

        $this->projects->recordFeedback($project, $data['note'], $data['scope'], $request->user());

        if ($data['scope'] === 'out_of_scope') {
            return redirect()->route('proposals.create', ['project_id' => $project->id])
                ->with('status', 'Feedback recorded. Work outside the agreed scope needs a change request with its own price and approval.');
        }

        return redirect()->route('projects.show', $project)->with('status', 'Feedback recorded.');
    }

    public function handover(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'item' => ['required', 'integer', 'min:0'],
            'done' => ['required', 'boolean'],
        ]);

        return $this->attempt($project, function () use ($request, $project, $data) {
            $this->projects->setHandoverItem($project, (int) $data['item'], (bool) $data['done'], $request->user());

            return 'Handover checklist updated.';
        }, 'handover');
    }

    public function addMilestone(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'due_at' => ['nullable', 'date'],
        ]);

        $this->projects->addMilestone($project, $data['name'], $data['due_at'] ?? null, $request->user());

        return redirect()->to(route('projects.show', $project).'#milestones')->with('status', 'Milestone added.');
    }

    public function milestoneStatus(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['status' => ['required', Rule::enum(MilestoneStatus::class)]]);

        return $this->attempt($project, function () use ($request, $milestone, $data) {
            $status = MilestoneStatus::from($data['status']);
            $this->projects->setMilestoneStatus($milestone, $status, $request->user());

            return "\"{$milestone->name}\" is {$status->label()}.";
        }, 'milestones');
    }

    public function addTask(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'milestone_id' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'regex:/^(user|agent):\d+$/'],
            'due_at' => ['nullable', 'date'],
        ]);

        [$kind, $id] = filled($data['assignee'] ?? null) ? explode(':', $data['assignee']) : [null, null];
        if ($kind === 'user' && ! User::query()->whereKey($id)->where('is_active', true)->exists()
            || $kind === 'agent' && ! Agent::query()->whereKey($id)->exists()) {
            return back()->withErrors(['assignee' => 'Choose an active person or an agent.']);
        }

        return $this->attempt($project, function () use ($request, $project, $data, $kind, $id) {
            $this->projects->addTask($project, [
                'title' => $data['title'],
                'milestone_id' => $data['milestone_id'] ?? null,
                'assignee_user_id' => $kind === 'user' ? (int) $id : null,
                'agent_id' => $kind === 'agent' ? (int) $id : null,
                'due_at' => $data['due_at'] ?? null,
            ], $request->user());

            return 'Task added.';
        }, 'milestones');
    }

    public function completeTask(Request $request, Project $project, Task $task, TaskService $tasks): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt($project, function () use ($request, $task, $tasks, $data) {
            $tasks->completeByPerson($task, $request->user(), $data['note'] ?? null);

            return "\"{$task->title}\" marked done.";
        }, 'milestones');
    }

    public function addDeliverable(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(ProjectDeliverable::TYPES)],
            'url' => ['nullable', 'url:http,https', 'max:255', new LinkWithoutCredentials],
            'milestone_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt($project, function () use ($request, $project, $data) {
            $this->projects->addDeliverable($project, $data, $request->user());

            return 'Deliverable added.';
        }, 'deliverables');
    }

    public function updateDeliverable(Request $request, Project $project, ProjectDeliverable $deliverable): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'status' => ['required', Rule::enum(DeliverableStatus::class)],
            'url' => ['nullable', 'url:http,https', 'max:255', new LinkWithoutCredentials],
        ]);

        $this->projects->updateDeliverable($deliverable, DeliverableStatus::from($data['status']), $data['url'] ?? null, $request->user());

        return redirect()->to(route('projects.show', $project).'#deliverables')->with('status', 'Deliverable updated.');
    }

    private function attempt(Project $project, Closure $action, ?string $anchor = null): RedirectResponse
    {
        $to = route('projects.show', $project).($anchor ? "#{$anchor}" : '');

        try {
            $message = $action();
        } catch (InvalidTransition $e) {
            return redirect()->to($to)->withErrors(['project' => $e->getMessage()]);
        }

        return redirect()->to($to)->with('status', $message);
    }
}
