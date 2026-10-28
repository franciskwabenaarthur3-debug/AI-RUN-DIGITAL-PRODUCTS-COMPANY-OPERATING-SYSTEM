@php
    use App\Enums\ApprovalStatus;
    use App\Enums\ProposalStatus;
    $money = fn (int $minor) => App\Support\Money::format($minor, $proposal->currency);
    $approval = $proposal->approvalRequest;
    $approvalOpen = $approval && in_array($approval->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Executing], true) && ! $approval->isExpired();
    $canReRequest = $latest && in_array($proposal->status, [ProposalStatus::PendingApproval, ProposalStatus::Approved], true) && ! $approvalOpen;
@endphp
<x-layouts.app :title="$proposal->label()">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('proposals.index') }}" class="text-emerald-700 underline">Proposals</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $proposal->title }} <x-status-badge :status="$proposal->status" /></h1>
            <p class="text-sm text-zinc-600">{{ $proposal->label() }} · for {{ $proposal->recipientLabel() }} · created {{ $proposal->created_at->format('M j, Y') }} by {{ $proposal->creatorName() }}</p>
            @unless ($latest)
                <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" role="note">A newer version exists. This version is kept for the record.</p>
            @endunless
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('proposals.preview', $proposal) }}" class="inline-flex items-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Preview</a>
            @if ($latest && $proposal->isEditable())
                @can('update', $proposal)
                    <a href="{{ route('proposals.edit', $proposal) }}" class="inline-flex items-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Edit draft</a>
                @endcan
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif

    @if ($problems && $latest)
        <div role="note" class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">This version cannot be {{ $proposal->status === ProposalStatus::Draft ? 'submitted' : 'sent' }} yet:</p>
            <ul class="mt-1 list-disc pl-5">@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="details">
                <h2 id="details" class="mb-4 font-semibold">Details</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Recipient</dt><dd class="break-all">{{ $proposal->recipient_name ?? '—' }}<br>{{ $proposal->recipient_email ?? 'No email' }}</dd></div>
                    <div><dt class="text-zinc-500">Record</dt><dd>
                        @if ($proposal->lead)<a href="{{ route('leads.show', $proposal->lead) }}" class="text-emerald-700 underline">Lead: {{ $proposal->lead->company_name }}</a>@endif
                        @if ($proposal->customer)<a href="{{ route('customers.show', $proposal->customer) }}" class="text-emerald-700 underline">Customer: {{ $proposal->customer->company_name }}</a>@endif
                    </dd></div>
                    <div><dt class="text-zinc-500">Package</dt><dd>{{ $proposal->scope_snapshot['package'] ?? '' }} ({{ $proposal->scope_snapshot['estimated_days'] ?? '?' }} days, {{ $proposal->scope_snapshot['revision_limit'] ?? 0 }} revisions)</dd></div>
                    <div><dt class="text-zinc-500">Valid until</dt><dd>{{ $proposal->expires_at->format('M j, Y') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-zinc-500">Payment terms</dt><dd class="whitespace-pre-line">{{ $proposal->payment_terms }}</dd></div>
                    @if ($proposal->content_hash)
                        <div class="sm:col-span-2"><dt class="text-zinc-500">Content hash</dt><dd class="break-all font-mono text-xs">{{ $proposal->content_hash }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="card" aria-labelledby="price">
                <h2 id="price" class="mb-4 font-semibold">Price breakdown</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            <tr><th scope="col" class="py-2 pr-3">Item</th><th scope="col" class="py-2 pr-3 text-right">Qty</th><th scope="col" class="py-2 pr-3 text-right">Unit</th><th scope="col" class="py-2 text-right">Total</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($proposal->items as $item)
                                <tr><td class="py-2 pr-3">{{ $item->description }}</td><td class="py-2 pr-3 text-right">{{ $item->quantity }}</td><td class="whitespace-nowrap py-2 pr-3 text-right">{{ $money($item->unit_price_minor) }}</td><td class="whitespace-nowrap py-2 text-right">{{ $money($item->line_total_minor) }}</td></tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-zinc-200">
                            <tr><td colspan="3" class="py-2 pr-3 text-right text-zinc-600">Subtotal</td><td class="whitespace-nowrap py-2 text-right">{{ $money($proposal->subtotal_minor) }}</td></tr>
                            @if ($proposal->discount_minor > 0)
                                <tr><td colspan="3" class="py-2 pr-3 text-right text-zinc-600">Discount: {{ $proposal->discount_reason }}</td><td class="whitespace-nowrap py-2 text-right">-{{ $money($proposal->discount_minor) }}</td></tr>
                            @endif
                            <tr class="font-semibold"><td colspan="3" class="py-2 pr-3 text-right">Total</td><td class="whitespace-nowrap py-2 text-right">{{ $money($proposal->amount_minor) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="card" aria-labelledby="scope">
                <h2 id="scope" class="mb-3 font-semibold">Scope and exclusions</h2>
                <div class="grid gap-4 text-sm sm:grid-cols-2">
                    <ul class="list-disc pl-5">@foreach ($proposal->scope_snapshot['scope'] ?? [] as $line)<li>{{ $line }}</li>@endforeach</ul>
                    <div>
                        <p class="text-zinc-500">Not included</p>
                        <ul class="list-disc pl-5">@forelse ($proposal->scope_snapshot['exclusions'] ?? [] as $line)<li>{{ $line }}</li>@empty<li class="list-none text-zinc-500">None listed</li>@endforelse</ul>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="status">
                <h2 id="status" class="mb-4 font-semibold">Approval, sending and response</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Approval</dt><dd>
                        @if ($approval)
                            <a href="{{ route('approvals.show', $approval) }}" class="text-emerald-700 underline">Request #{{ $approval->id }}</a> <x-status-badge :status="$approval->status" />
                            @if ($approval->isExpired() && in_array($approval->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true))<span class="block text-xs text-amber-800">Past its deadline</span>@endif
                        @else
                            Not requested
                        @endif
                    </dd></div>
                    <div><dt class="text-zinc-500">Approved</dt><dd>{{ $proposal->approved_at?->format('M j, Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Sent</dt><dd>{{ $proposal->sent_at?->format('M j, Y H:i') ?? 'Not sent' }}</dd></div>
                    <div><dt class="text-zinc-500">Delivery</dt><dd>
                        @if ($proposal->delivery_status)
                            {{ ucfirst(str_replace('_', ' ', $proposal->delivery_status)) }} ({{ $proposal->delivery_method }})
                            @if ($proposal->delivery_reference)<span class="block break-all font-mono text-xs">{{ $proposal->delivery_reference }}</span>@endif
                        @else
                            —
                        @endif
                    </dd></div>
                    @if ($proposal->responded_at)
                        <div class="sm:col-span-2"><dt class="text-zinc-500">Response on {{ $proposal->responded_at->format('M j, Y') }}</dt><dd class="whitespace-pre-line">{{ $proposal->response_note }}</dd></div>
                    @endif
                </dl>
                @if ($approval?->status === ApprovalStatus::NeedsReconciliation)
                    <p class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">Sending started but its outcome was not recorded. Check with the recipient or the mail service before doing anything else.</p>
                @endif
            </section>

            <section class="card" aria-labelledby="versions">
                <h2 id="versions" class="mb-3 font-semibold">Versions</h2>
                <ul class="divide-y divide-zinc-100 text-sm">
                    @foreach ($versions as $v)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>@if ($v->id === $proposal->id)<strong>Version {{ $v->version }}</strong> (this one)@else<a href="{{ route('proposals.show', $v->id) }}" class="text-emerald-700 underline">Version {{ $v->version }}</a>@endif · {{ $v->created_at->format('M j, Y') }}</span>
                            <span class="flex items-center gap-2"><x-status-badge :status="$v->status" /> {{ App\Support\Money::format($v->amount_minor, $v->currency) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="card" aria-labelledby="history">
                <h2 id="history" class="mb-3 font-semibold">History</h2>
                <ol class="space-y-3 text-sm">
                    @foreach ($proposal->events as $event)
                        <li>
                            <p><span class="font-medium">{{ $event->label() }}</span> · {{ $event->actorName() }} · {{ $event->created_at->format('M j, Y H:i') }}</p>
                            @if ($event->metadata)
                                <p class="break-all text-xs text-zinc-500">{{ collect($event->metadata)->reject(fn ($v) => $v === null || $v === [])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : json_encode($x), $v)) : $v))->implode(' · ') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <aside class="space-y-6" aria-label="Actions">
            @if ($proposal->project)
                <p class="card text-sm">Change request for project <a href="{{ route('projects.show', $proposal->project) }}" class="text-emerald-700 underline">{{ $proposal->project->name }}</a>.{{ $proposal->status === ProposalStatus::Accepted ? ' Its work and price were added to the project.' : '' }}</p>
            @endif
            @if ($proposal->status === ProposalStatus::Accepted && ! $proposal->project_id)
                @if ($proposal->createdProject)
                    <p class="card text-sm">Project: <a href="{{ route('projects.show', $proposal->createdProject) }}" class="text-emerald-700 underline">{{ $proposal->createdProject->name }}</a></p>
                @else
                    @can('create', App\Models\Project::class)
                        <section class="card">
                            <h2 class="mb-2 font-semibold">Start the project</h2>
                            <p class="mb-3 text-sm text-zinc-600">This proposal was accepted before projects existed. Create its project from the package's delivery template.</p>
                            <form method="POST" action="{{ route('proposals.project', $proposal) }}">
                                @csrf
                                <button type="submit" class="btn-primary w-full">Create project</button>
                            </form>
                        </section>
                    @endcan
                @endif
            @endif
            @if ($latest)
                @can('update', $proposal)
                    @if ($proposal->status === ProposalStatus::Draft || $canReRequest)
                        <section class="card">
                            <h2 class="mb-2 font-semibold">{{ $proposal->status === ProposalStatus::Draft ? 'Submit for approval' : 'Request approval again' }}</h2>
                            <p class="mb-3 text-sm text-zinc-600">{{ $proposal->status === ProposalStatus::Draft ? 'Freezes this version. The founder approves this exact content and recipient before it can be sent.' : 'The earlier approval request is no longer usable.' }}</p>
                            <form method="POST" action="{{ route('proposals.submit', $proposal) }}">
                                @csrf
                                <button type="submit" class="btn-primary w-full" @disabled($problems)>{{ $proposal->status === ProposalStatus::Draft ? 'Submit' : 'Request approval' }}</button>
                            </form>
                        </section>
                    @endif
                @endcan

                @if ($proposal->status === ProposalStatus::Approved && $approvalOpen)
                    @can('send', $proposal)
                        <section class="card">
                            <h2 class="mb-2 font-semibold">Send</h2>
                            <form method="POST" action="{{ route('proposals.send', $proposal) }}" class="space-y-3">
                                @csrf
                                <fieldset class="space-y-2 text-sm">
                                    <legend class="form-label">How</legend>
                                    <label class="flex items-start gap-2"><input type="radio" name="method" value="email" @checked($emailEnabled) @disabled(! $emailEnabled) class="mt-0.5 text-emerald-700"> <span>Email it to <span class="break-all">{{ $proposal->recipient_email }}</span>@unless ($emailEnabled)<span class="block text-xs text-zinc-500">Email sending is not configured.</span>@endunless</span></label>
                                    <label class="flex items-start gap-2"><input type="radio" name="method" value="manual" @checked(! $emailEnabled) class="mt-0.5 text-emerald-700"> <span>I sent it myself; record it</span></label>
                                </fieldset>
                                <div>
                                    <label for="send_note" class="form-label">How and when you sent it <span class="font-normal text-zinc-500">(for a manual send)</span></label>
                                    <input id="send_note" name="note" maxlength="500" class="form-input">
                                </div>
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> Send version {{ $proposal->version }} for {{ $money($proposal->amount_minor) }} to this recipient</label>
                                <button type="submit" class="btn-primary w-full" @disabled($problems)>Send</button>
                            </form>
                        </section>
                    @else
                        <p class="card text-sm text-zinc-600">Approved. Waiting for the founder to send it.</p>
                    @endcan
                @endif

                @if ($proposal->status === ProposalStatus::PendingApproval && $approvalOpen)
                    <p class="card text-sm text-zinc-600">Waiting for the founder's decision on <a href="{{ route('approvals.show', $approval) }}" class="text-emerald-700 underline">approval #{{ $approval->id }}</a>.</p>
                @endif

                @can('update', $proposal)
                    @if ($proposal->status === ProposalStatus::Sent)
                        <section class="card">
                            <h2 class="mb-2 font-semibold">Record the response</h2>
                            <p class="mb-3 text-sm text-zinc-600">Record only what the recipient actually told you.</p>
                            <form method="POST" action="{{ route('proposals.respond', $proposal) }}" class="space-y-3">
                                @csrf
                                <div>
                                    <label for="outcome" class="form-label">Outcome</label>
                                    <select id="outcome" name="outcome" class="form-input">
                                        <option value="accepted">Accepted</option>
                                        <option value="changes_requested">Asked for changes</option>
                                        <option value="declined">Declined</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="responded_at" class="form-label">Date</label>
                                    <input id="responded_at" name="responded_at" type="date" required max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" class="form-input">
                                </div>
                                <div>
                                    <label for="response_note" class="form-label">Evidence</label>
                                    <textarea id="response_note" name="note" rows="3" required maxlength="2000" placeholder="e.g. Email reply from the client on 28 Oct accepting the quote" class="form-input"></textarea>
                                </div>
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> This is the recipient's answer</label>
                                <button type="submit" class="btn-primary w-full">Record</button>
                            </form>
                        </section>
                    @endif

                    @if ($proposal->status->allowsNewVersion())
                        <section class="card">
                            <h2 class="mb-2 font-semibold">New version</h2>
                            <p class="mb-3 text-sm text-zinc-600">Copies this version into a new draft.{{ in_array($proposal->status, [ProposalStatus::PendingApproval, ProposalStatus::Approved], true) ? ' This version is withdrawn and its approval stops counting.' : '' }}</p>
                            <form method="POST" action="{{ route('proposals.new-version', $proposal) }}">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Start version {{ $proposal->version + 1 }}</button>
                            </form>
                        </section>
                    @endif

                    @if ($proposal->status->canTransitionTo(ProposalStatus::Withdrawn))
                        <section class="card">
                            <h2 class="mb-2 font-semibold">Withdraw</h2>
                            <form method="POST" action="{{ route('proposals.withdraw', $proposal) }}" class="space-y-2">
                                @csrf
                                <label for="withdraw_reason" class="form-label">Reason</label>
                                <input id="withdraw_reason" name="reason" required maxlength="2000" class="form-input">
                                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> Withdraw this proposal{{ $proposal->sent_at ? ' (tell the recipient yourself)' : '' }}</label>
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Withdraw</button>
                            </form>
                        </section>
                    @endif
                @endcan
            @endif
        </aside>
    </div>
</x-layouts.app>
