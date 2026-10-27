<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\ResearchStatus;
use App\Http\Requests\Leads\LeadRequest;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Lead::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'source' => ['nullable', Rule::enum(LeadSource::class)],
            'follow_up' => ['nullable', 'in:due'],
        ]);

        $leads = Lead::query()
            ->search($filters['q'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when(($filters['follow_up'] ?? null) === 'due', fn ($q) => $q->followUpDue())
            ->latest('updated_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('leads.index', [
            'leads' => $leads,
            'filters' => $filters,
            'statuses' => LeadStatus::cases(),
            'sources' => LeadSource::cases(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Lead::class);

        return view('leads.form', $this->formData(new Lead));
    }

    public function store(LeadRequest $request, LeadService $leads): RedirectResponse
    {
        $lead = $leads->create($request->validated(), $request->user());

        return redirect()->route('leads.show', $lead)->with('status', 'Lead created.');
    }

    public function show(Lead $lead): View
    {
        $this->authorize('view', $lead);

        return view('leads.show', [
            'lead' => $lead->load(['customer', 'creator']),
            'activities' => $lead->activities()->with('actor:id,name')->paginate(15),
        ]);
    }

    public function edit(Lead $lead): View
    {
        $this->authorize('update', $lead);

        return view('leads.form', $this->formData($lead));
    }

    public function update(LeadRequest $request, Lead $lead, LeadService $leads): RedirectResponse
    {
        $leads->update($lead, $request->validated(), $request->user());

        return redirect()->route('leads.show', $lead)->with('status', 'Lead updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Lead $lead): array
    {
        return [
            'lead' => $lead,
            'sources' => LeadSource::cases(),
            'researchStatuses' => ResearchStatus::cases(),
        ];
    }
}
