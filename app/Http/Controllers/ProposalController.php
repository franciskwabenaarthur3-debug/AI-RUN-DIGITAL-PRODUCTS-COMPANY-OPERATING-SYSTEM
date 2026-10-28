<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Enums\ProposalStatus;
use App\Http\Requests\Proposals\ProposalRequest;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ServicePackage;
use App\Proposals\ProposalChecks;
use App\Proposals\ProposalRefused;
use App\Services\ProposalService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProposalController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Proposal::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProposalStatus::class)],
        ]);

        $proposals = Proposal::query()
            ->with(['lead:id,company_name', 'customer:id,company_name'])
            // Only the latest version of each proposal.
            ->whereNotIn('id', Proposal::query()->whereNotNull('previous_version_id')->select('previous_version_id'))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('proposals.index', ['proposals' => $proposals, 'filters' => $filters, 'statuses' => ProposalStatus::cases()]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Proposal::class);

        return view('proposals.form', [
            'proposal' => new Proposal([
                'lead_id' => $request->integer('lead_id') ?: null,
                'customer_id' => $request->integer('customer_id') ?: null,
                'payment_terms' => config('company_os.proposals.default_payment_terms'),
                'expires_at' => now()->addDays((int) config('company_os.proposals.default_valid_days')),
            ]),
            'items' => [],
            'leads' => Lead::query()->where('do_not_contact', false)->whereNotIn('status', [LeadStatus::Unsubscribed, LeadStatus::Converted])->orderBy('company_name')->get(['id', 'company_name']),
            'customers' => Customer::query()->where('status', 'active')->orderBy('company_name')->get(['id', 'company_name']),
            'packages' => ServicePackage::query()->where('is_active', true)->orderBy('name')->get(),
            'discount' => ['type' => 'none', 'value' => ''],
        ]);
    }

    public function store(ProposalRequest $request): RedirectResponse
    {
        $proposal = $this->proposals->create($request->proposalData(), $request->user());

        return redirect()->route('proposals.show', $proposal)->with('status', "Draft {$proposal->label()} created.");
    }

    public function show(Proposal $proposal): View
    {
        $this->authorize('view', $proposal);
        $proposal->load(['items', 'events', 'lead', 'customer', 'servicePackage', 'approvalRequest', 'creatorUser:id,name', 'creatorAgent:id,name']);

        return view('proposals.show', [
            'proposal' => $proposal,
            'versions' => $proposal->versions()->get(['id', 'version', 'status', 'amount_minor', 'currency', 'content_hash', 'created_at']),
            'latest' => $proposal->isLatest(),
            'problems' => in_array($proposal->status, [ProposalStatus::Draft, ProposalStatus::Approved, ProposalStatus::PendingApproval], true) ? ProposalChecks::problems($proposal) : [],
            'emailEnabled' => (bool) config('company_os.proposals.email_enabled'),
        ]);
    }

    public function preview(Proposal $proposal): View
    {
        $this->authorize('view', $proposal);

        return view('proposals.preview', [
            'proposal' => $proposal->load(['lead', 'customer']),
            'items' => $proposal->items()->get(),
            'company' => CompanySetting::current(),
        ]);
    }

    public function edit(Proposal $proposal): View|RedirectResponse
    {
        $this->authorize('update', $proposal);

        if (! $proposal->isEditable() || ! $proposal->isLatest()) {
            return redirect()->route('proposals.show', $proposal)->withErrors(['proposal' => 'Only the latest draft can be edited. Start a new version to make changes.']);
        }

        return view('proposals.form', [
            'proposal' => $proposal,
            'items' => $proposal->items()->get()->slice(1)->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'unit_price' => Money::fromMinor($i->unit_price_minor)])->values()->all(),
            'leads' => collect(),
            'customers' => collect(),
            'packages' => collect(),
            'discount' => $proposal->discount_minor > 0 ? ['type' => 'amount', 'value' => Money::fromMinor($proposal->discount_minor)] : ['type' => 'none', 'value' => ''],
        ]);
    }

    public function update(ProposalRequest $request, Proposal $proposal): RedirectResponse
    {
        return $this->attempt($proposal, function () use ($request, $proposal) {
            $this->proposals->update($proposal, $request->proposalData(), $request->user());

            return 'Draft saved.';
        });
    }

    public function submit(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorize('update', $proposal);

        return $this->attempt($proposal, function () use ($request, $proposal) {
            $this->proposals->submit($proposal, $request->user());

            return 'Submitted. The founder must approve this exact version before it can be sent.';
        });
    }

    public function send(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorize('send', $proposal);
        $data = $request->validate([
            'method' => ['required', Rule::in(['email', 'manual'])],
            'note' => ['nullable', 'required_if:method,manual', 'string', 'max:500'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($proposal, function () use ($request, $proposal, $data) {
            $sent = $this->proposals->send($proposal, $data['method'], $request->user(), $data['note'] ?? null);

            return $sent->delivery_method === 'email'
                ? "Sent. The mail service returned: {$sent->delivery_status}."
                : 'Recorded as sent by you.';
        });
    }

    public function respond(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorize('update', $proposal);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['accepted', 'declined', 'changes_requested'])],
            'note' => ['required', 'string', 'max:2000'],
            'responded_at' => ['required', 'date', 'before_or_equal:today'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($proposal, function () use ($request, $proposal, $data) {
            $this->proposals->recordResponse($proposal, $data['outcome'], $data['note'], Carbon::parse($data['responded_at']), $request->user());

            return 'Response recorded.';
        });
    }

    public function withdraw(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorize('update', $proposal);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ]);

        return $this->attempt($proposal, function () use ($request, $proposal, $data) {
            $this->proposals->withdraw($proposal, $data['reason'], $request->user());

            return 'Proposal withdrawn.';
        });
    }

    public function newVersion(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorize('update', $proposal);

        try {
            $next = $this->proposals->newVersion($proposal, $request->user());
        } catch (ProposalRefused $e) {
            return redirect()->route('proposals.show', $proposal)->withErrors(['proposal' => $e->getMessage()]);
        }

        return redirect()->route('proposals.edit', $next)->with('status', "Version {$next->version} started as a draft.");
    }

    private function attempt(Proposal $proposal, \Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (ProposalRefused $e) {
            return redirect()->route('proposals.show', $proposal)->withErrors(array_merge(['proposal' => $e->getMessage()], array_combine(
                array_map(fn ($i) => "problem_{$i}", array_keys($e->problems)),
                $e->problems,
            )));
        }

        return redirect()->route('proposals.show', $proposal)->with('status', $message);
    }
}
