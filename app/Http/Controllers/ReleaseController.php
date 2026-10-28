<?php

namespace App\Http\Controllers;

use App\Enums\CheckStatus;
use App\Http\Requests\Projects\ReleaseRequest;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\ReleaseCheck;
use App\Releases\ReleaseRefused;
use App\Services\ReleaseService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReleaseController extends Controller
{
    public function __construct(private readonly ReleaseService $releases) {}

    public function store(ReleaseRequest $request, Project $project): RedirectResponse
    {
        try {
            $release = $this->releases->create($project, $request->validated(), $request->user());
        } catch (ReleaseRefused $e) {
            return back()->withInput()->withErrors(['release' => $e->getMessage()]);
        }

        return redirect()->route('releases.show', $release)->with('status', "{$release->label()} created. Record its quality checks next.");
    }

    public function show(ProjectRelease $release): View
    {
        $this->authorize('view', $release->project);
        $release->load(['project', 'checks.recorder:id,name', 'approvalRequest']);

        return view('releases.show', [
            'release' => $release,
            'project' => $release->project,
            'problems' => $release->isProduction() ? $this->releases->productionProblems($release) : [],
            'history' => $release->project->events()->get()->filter(fn ($e) => ($e->metadata['release_id'] ?? null) === $release->id)->values(),
        ]);
    }

    public function check(Request $request, ProjectRelease $release, ReleaseCheck $check): RedirectResponse
    {
        $this->authorize('update', $release->project);
        $data = $request->validate([
            'status' => ['required', Rule::enum(CheckStatus::class)],
            'evidence' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt($release, function () use ($request, $check, $data) {
            $this->releases->recordCheck($check, CheckStatus::from($data['status']), $data['evidence'] ?? null, $request->user());

            return "\"{$check->label}\" recorded.";
        });
    }

    public function deployStaging(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('update', $release->project);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->attempt($release, function () use ($request, $release, $data) {
            $this->releases->markStagingDeployed($release, $data['note'], $request->user());

            return 'Recorded as deployed to staging. Run the health check next.';
        });
    }

    public function submit(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('update', $release->project);

        return $this->attempt($release, function () use ($request, $release) {
            $this->releases->submit($release, $request->user());

            return 'Submitted. The founder must approve this exact release before it goes to production.';
        });
    }

    public function deploy(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('deploy', $release->project);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($release, function () use ($request, $release, $data) {
            $this->releases->recordDeployment($release, $data['note'], $request->user());

            return 'Production deployment recorded. Run the health check to verify it.';
        });
    }

    public function healthCheck(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('update', $release->project);

        return $this->attempt($release, function () use ($request, $release) {
            $checked = $this->releases->runHealthCheck($release, $request->user());

            return $checked->health_status === 'healthy'
                ? "Healthy: HTTP {$checked->health_detail['http_status']}."
                : 'Not healthy: '.($checked->health_detail['error'] ?? 'no answer').'.';
        });
    }

    public function verify(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('update', $release->project);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($release, function () use ($request, $release, $data) {
            $this->releases->recordManualVerification($release, $data['note'], $request->user());

            return 'Verified by hand.';
        });
    }

    public function rollback(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('update', $release->project);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($release, function () use ($request, $release, $data) {
            $this->releases->recordRollback($release, $data['note'], $request->user());

            return 'Rollback recorded.';
        });
    }

    public function cancel(Request $request, ProjectRelease $release): RedirectResponse
    {
        $this->authorize('update', $release->project);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt($release, function () use ($request, $release, $data) {
            $this->releases->cancel($release, $data['reason'], $request->user());

            return 'Release cancelled.';
        });
    }

    private function attempt(ProjectRelease $release, Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (ReleaseRefused $e) {
            return redirect()->route('releases.show', $release)->withErrors(array_merge(['release' => $e->getMessage()], array_combine(
                array_map(fn ($i) => "problem_{$i}", array_keys($e->problems)),
                $e->problems,
            )));
        }

        return redirect()->route('releases.show', $release)->with('status', $message);
    }
}
