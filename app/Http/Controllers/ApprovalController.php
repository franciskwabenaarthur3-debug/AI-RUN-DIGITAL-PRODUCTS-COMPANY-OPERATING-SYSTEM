<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Governance\ApprovalRefused;
use App\Models\ApprovalRequest;
use App\Models\ToolExecution;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ApprovalRequest::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ApprovalStatus::class)],
        ]);

        $approvals = ApprovalRequest::query()
            ->with(['requestingAgent:id,name', 'requestingUser:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('approvals.index', [
            'approvals' => $approvals,
            'filters' => $filters,
            'statuses' => ApprovalStatus::cases(),
        ]);
    }

    public function show(ApprovalRequest $approval): View
    {
        $this->authorize('view', $approval);

        return view('approvals.show', [
            'approval' => $approval->load(['decision.approver:id,name', 'requestingAgent:id,name', 'requestingUser:id,name', 'task:id,title']),
            'execution' => ToolExecution::query()->where('approval_request_id', $approval->id)->first(),
            'intact' => $approval->snapshotIntact(),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('decide', $approval);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        return $this->decide($request, $approval, true, $data['reason'] ?? null, 'Approved.');
    }

    public function reject(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('decide', $approval);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->decide($request, $approval, false, $data['reason'], 'Rejected.');
    }

    private function decide(Request $request, ApprovalRequest $approval, bool $approve, ?string $reason, string $message): RedirectResponse
    {
        try {
            $this->approvals->decide($approval, $request->user(), $approve, $reason);
        } catch (ApprovalRefused $e) {
            return redirect()->route('approvals.show', $approval)->withErrors(['approval' => $e->getMessage()]);
        }

        return redirect()->route('approvals.show', $approval)->with('status', $message);
    }
}
