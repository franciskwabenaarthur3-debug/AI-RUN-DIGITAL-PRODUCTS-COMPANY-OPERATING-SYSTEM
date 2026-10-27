<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Exceptions\InvalidTransition;
use App\Http\Requests\Leads\AddLeadNoteRequest;
use App\Http\Requests\Leads\ChangeLeadStatusRequest;
use App\Http\Requests\Leads\OptOutLeadRequest;
use App\Http\Requests\Leads\ScheduleFollowUpRequest;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Workflow actions on a single lead.
 */
class LeadActionController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function status(ChangeLeadStatusRequest $request, Lead $lead): RedirectResponse
    {
        try {
            $this->leads->changeStatus($lead, LeadStatus::from($request->validated('status')), $request->user(), $request->validated('reason'));
        } catch (InvalidTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Status updated.');
    }

    public function followUp(ScheduleFollowUpRequest $request, Lead $lead): RedirectResponse
    {
        $at = $request->validated('next_follow_up_at');
        $this->leads->scheduleFollowUp($lead, $at ? Carbon::parse($at) : null, $request->user());

        return back()->with('status', $at ? 'Follow-up scheduled.' : 'Follow-up cleared.');
    }

    public function note(AddLeadNoteRequest $request, Lead $lead): RedirectResponse
    {
        $this->leads->addNote($lead, $request->validated('note'), $request->user());

        return back()->with('status', 'Note added.');
    }

    public function optOut(OptOutLeadRequest $request, Lead $lead): RedirectResponse
    {
        $this->leads->optOut($lead, $request->validated('opt_out_reason'), $request->user());

        return back()->with('status', 'Contact marked as opted out. They will be excluded from outreach.');
    }

    public function convert(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('convert', $lead);
        $request->validate(['confirm' => ['accepted']]);

        try {
            $customer = $this->leads->convert($lead, $request->user());
        } catch (InvalidTransition $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Lead converted to customer.');
    }
}
