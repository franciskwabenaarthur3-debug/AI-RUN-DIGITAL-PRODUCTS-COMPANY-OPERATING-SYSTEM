<x-layouts.app :title="$lead->company_name">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('leads.index') }}" class="text-emerald-700 underline">Leads</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">
                {{ $lead->company_name }} <x-status-badge :status="$lead->status" />
            </h1>
            @if ($lead->do_not_contact)
                <p class="mt-2 rounded-md bg-red-50 px-3 py-2 text-sm font-medium text-red-800" role="note">
                    Do not contact. Opted out {{ $lead->opted_out_at?->format('M j, Y') }}: {{ $lead->opt_out_reason }}
                </p>
            @endif
        </div>
        @can('update', $lead)
            <a href="{{ route('leads.edit', $lead) }}" class="inline-flex items-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Edit details</a>
        @endcan
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="details">
                <h2 id="details" class="mb-4 font-semibold">Details</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Contact</dt><dd>{{ $lead->contact_name ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Email</dt><dd class="break-all">{{ $lead->email ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Phone</dt><dd>{{ $lead->phone ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Website</dt><dd class="break-all">{{ $lead->website ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Qualification score</dt><dd>{{ $lead->qualification_score ?? 'Not scored' }}</dd></div>
                    <div><dt class="text-zinc-500">Next follow-up</dt><dd class="{{ $lead->isFollowUpDue() ? 'font-medium text-amber-700' : '' }}">{{ $lead->next_follow_up_at?->format('M j, Y H:i') ?? '—' }}</dd></div>
                    @if ($lead->customer)
                        <div><dt class="text-zinc-500">Customer</dt><dd><a href="{{ route('customers.show', $lead->customer) }}" class="text-emerald-700 underline">{{ $lead->customer->company_name }}</a></dd></div>
                    @endif
                    <div><dt class="text-zinc-500">Created</dt><dd>{{ $lead->created_at->format('M j, Y') }}{{ $lead->creator ? ' by '.$lead->creator->name : '' }}</dd></div>
                </dl>
                @if ($lead->notes)
                    <div class="mt-4 text-sm"><p class="text-zinc-500">Notes</p><p class="whitespace-pre-line">{{ $lead->notes }}</p></div>
                @endif
            </section>

            <section class="card" aria-labelledby="research">
                <h2 id="research" class="mb-4 font-semibold">Research evidence</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Source</dt><dd>{{ $lead->source->label() }}</dd></div>
                    <div><dt class="text-zinc-500">Research status</dt><dd><x-status-badge :status="$lead->research_status" /></dd></div>
                    <div class="sm:col-span-2"><dt class="text-zinc-500">Source reference</dt><dd class="whitespace-pre-line break-words">{{ $lead->source_reference ?? 'No source recorded. Treat details as unverified.' }}</dd></div>
                </dl>
            </section>

            <section class="card" aria-labelledby="activity">
                <h2 id="activity" class="mb-4 font-semibold">Activity</h2>
                @can('update', $lead)
                    <form method="POST" action="{{ route('leads.notes', $lead) }}" class="mb-5 space-y-2">
                        @csrf
                        <label for="note" class="form-label">Add a note</label>
                        <textarea id="note" name="note" rows="2" required maxlength="5000" class="form-input"></textarea>
                        <div class="flex justify-end"><button type="submit" class="btn-primary">Add note</button></div>
                    </form>
                @endcan
                @if ($activities->isEmpty())
                    <p class="text-sm text-zinc-600">No activity yet.</p>
                @else
                    <ol class="space-y-3 text-sm">
                        @foreach ($activities as $activity)
                            <li class="border-l-2 border-emerald-600/40 pl-3">
                                <p class="whitespace-pre-line">{{ $activity->description }}</p>
                                <p class="text-xs text-zinc-500">
                                    {{ str_replace('_', ' ', $activity->activity_type) }} · {{ $activity->actor?->name ?? $activity->actor_type }} ·
                                    <time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('M j, Y H:i') }}</time>
                                </p>
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-4">{{ $activities->links() }}</div>
                @endif
            </section>
        </div>

        @can('changeStatus', $lead)
            <aside class="space-y-6" aria-label="Actions">
                @php($targets = array_filter($lead->status->manualTransitions(), fn ($s) => $lead->canBeContacted() || ! $s->isOutreach()))
                <section class="card" aria-labelledby="status-heading">
                    <h2 id="status-heading" class="mb-3 font-semibold">Change status</h2>
                    @if ($targets === [])
                        <p class="text-sm text-zinc-600">No further status changes are available.</p>
                    @else
                        <form method="POST" action="{{ route('leads.status', $lead) }}" class="space-y-3">
                            @csrf
                            <div>
                                <label for="status" class="form-label">New status</label>
                                <select id="status" name="status" class="form-input">
                                    @foreach ($targets as $target)
                                        <option value="{{ $target->value }}">{{ $target->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="reason" class="form-label">Reason <span class="font-normal text-zinc-500">(optional)</span></label>
                                <input id="reason" name="reason" maxlength="500" class="form-input">
                            </div>
                            <button type="submit" class="btn-primary w-full">Update status</button>
                        </form>
                    @endif
                </section>

                @unless ($lead->customer_id || in_array($lead->status, [App\Enums\LeadStatus::Unsubscribed, App\Enums\LeadStatus::Converted], true))
                    <section class="card" aria-labelledby="follow-up-heading">
                        <h2 id="follow-up-heading" class="mb-3 font-semibold">Follow-up</h2>
                        <form method="POST" action="{{ route('leads.follow-up', $lead) }}" class="space-y-3">
                            @csrf
                            <label for="follow_up_at" class="form-label">Next follow-up</label>
                            <input id="follow_up_at" name="next_follow_up_at" type="datetime-local" value="{{ $lead->next_follow_up_at?->format('Y-m-d\TH:i') }}" class="form-input">
                            <p class="text-xs text-zinc-500">Leave empty and save to clear.</p>
                            <button type="submit" class="btn-primary w-full">Save follow-up</button>
                        </form>
                    </section>
                @endunless

                @if ($lead->status->canTransitionTo(App\Enums\LeadStatus::Converted) && ! $lead->customer_id)
                    <section class="card" aria-labelledby="convert-heading">
                        <h2 id="convert-heading" class="mb-3 font-semibold">Convert to customer</h2>
                        <form method="POST" action="{{ route('leads.convert', $lead) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm text-zinc-600">Creates a customer record from this lead's contact details. This happens once per lead.</p>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I confirm this lead has become a customer.</label>
                            <button type="submit" class="btn-primary w-full">Convert</button>
                        </form>
                    </section>
                @endif

                @unless ($lead->do_not_contact)
                    <section class="card" aria-labelledby="opt-out-heading">
                        <h2 id="opt-out-heading" class="mb-3 font-semibold">Opt-out</h2>
                        <form method="POST" action="{{ route('leads.opt-out', $lead) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm text-zinc-600">Use when the contact asks not to be contacted. They are excluded from all outreach and this cannot be undone from the app.</p>
                            <div>
                                <label for="opt_out_reason" class="form-label">Reason</label>
                                <input id="opt_out_reason" name="opt_out_reason" required maxlength="500" placeholder="e.g. Replied asking to be removed" class="form-input">
                            </div>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I confirm the contact opted out.</label>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Mark as opted out</button>
                        </form>
                    </section>
                @endunless
            </aside>
        @endcan
    </div>
</x-layouts.app>
