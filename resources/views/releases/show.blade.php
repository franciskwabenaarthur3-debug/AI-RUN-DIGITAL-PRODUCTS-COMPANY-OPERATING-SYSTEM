@php
    use App\Enums\ApprovalStatus;
    use App\Enums\CheckStatus;
    use App\Enums\ReleaseStatus;
    $approval = $release->approvalRequest;
    $approvalOpen = $approval && in_array($approval->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Executing], true) && ! $approval->isExpired();
    $checksOpen = $release->status === ReleaseStatus::Draft || (! $release->isProduction() && ! in_array($release->status, [ReleaseStatus::Cancelled, ReleaseStatus::RolledBack], true));
    $canAskAgain = $release->isProduction() && in_array($release->status, [ReleaseStatus::PendingApproval, ReleaseStatus::Approved], true) && ! $approvalOpen;
@endphp
<x-layouts.app :title="$release->label().' · '.$project->name">
    <div class="mb-6">
        <p class="text-sm"><a href="{{ route('projects.show', $project) }}#releases" class="text-emerald-700 underline">{{ $project->name }}</a></p>
        <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $release->label() }} <x-status-badge :status="$release->status" /></h1>
        <p class="text-sm text-zinc-600">Created {{ $release->created_at->format('M j, Y') }} · project is {{ $project->status->label() }}</p>
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif

    @if ($problems && in_array($release->status, [ReleaseStatus::Draft, ReleaseStatus::PendingApproval, ReleaseStatus::Approved], true))
        <div role="note" class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">This release cannot go to production yet:</p>
            <ul class="mt-1 list-disc pl-5">@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="details">
                <h2 id="details" class="mb-4 font-semibold">Release</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">URL</dt><dd class="break-all"><a href="{{ $release->target_url }}" class="text-emerald-700 underline" rel="noopener noreferrer" target="_blank">{{ $release->target_url }}</a></dd></div>
                    <div><dt class="text-zinc-500">Commit or tag</dt><dd class="break-all font-mono text-xs">{{ $release->commit_reference ?? '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-zinc-500">What is in it</dt><dd class="whitespace-pre-line">{{ $release->release_notes }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-zinc-500">Rollback plan</dt><dd class="whitespace-pre-line">{{ $release->rollback_plan }}</dd></div>
                    @if ($release->isProduction())
                        <div><dt class="text-zinc-500">Approval</dt><dd>
                            @if ($approval)
                                <a href="{{ route('approvals.show', $approval) }}" class="text-emerald-700 underline">Request #{{ $approval->id }}</a> <x-status-badge :status="$approval->status" />
                            @else
                                Not requested
                            @endif
                        </dd></div>
                    @endif
                    <div><dt class="text-zinc-500">Deployed</dt><dd>{{ $release->deployed_at?->format('M j, Y H:i') ?? 'Not yet' }}@if ($release->deployment_note)<span class="block whitespace-pre-line text-zinc-700">{{ $release->deployment_note }}</span>@endif</dd></div>
                    <div><dt class="text-zinc-500">Health</dt><dd>
                        @if ($release->health_status)
                            {{ ucfirst($release->health_status) }} · {{ $release->health_checked_at->format('M j, Y H:i') }}
                            <span class="block text-xs text-zinc-600">
                                @if ($release->health_status === 'manual')
                                    Verified by {{ $release->health_detail['verified_by'] ?? '?' }}: {{ $release->health_detail['note'] ?? '' }}
                                @else
                                    HTTP {{ $release->health_detail['http_status'] ?? 'none' }} in {{ $release->health_detail['duration_ms'] ?? '?' }} ms{{ ($release->health_detail['error'] ?? null) ? ' · '.$release->health_detail['error'] : '' }}
                                @endif
                            </span>
                        @else
                            Not checked
                        @endif
                    </dd></div>
                    @if ($release->rolled_back_at)
                        <div class="sm:col-span-2"><dt class="text-zinc-500">Rolled back {{ $release->rolled_back_at->format('M j, Y H:i') }}</dt><dd class="whitespace-pre-line">{{ $release->rollback_note }}</dd></div>
                    @endif
                    @if ($release->content_hash)
                        <div class="sm:col-span-2"><dt class="text-zinc-500">Approved content hash</dt><dd class="break-all font-mono text-xs">{{ $release->content_hash }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="card" aria-labelledby="checks">
                <h2 id="checks" class="mb-1 font-semibold">Quality checks</h2>
                <p class="mb-4 text-sm text-zinc-600">Record each result with its evidence: a CI run, a scan report, or what you checked. Mandatory checks must pass; they cannot be skipped or waived.{{ $release->isProduction() ? ' Results are frozen once the release is submitted for approval.' : '' }}</p>
                <ul class="divide-y divide-zinc-100 text-sm">
                    @foreach ($release->checks as $check)
                        <li class="py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-medium">{{ $check->label }} @if ($check->mandatory)<span class="ml-1 text-xs font-normal text-red-700">mandatory</span>@endif</p>
                                <x-status-badge :status="$check->status" />
                            </div>
                            @if ($check->evidence)<p class="mt-1 whitespace-pre-line break-all text-zinc-700">{{ $check->evidence }}</p>@endif
                            @if ($check->recorded_at)<p class="text-xs text-zinc-500">{{ $check->recorder?->name ?? 'Someone' }}, {{ $check->recorded_at->format('M j, Y H:i') }}</p>@endif
                            @if ($checksOpen)
                                @can('update', $project)
                                    <form method="POST" action="{{ route('releases.check', [$release, $check]) }}" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                        @csrf
                                        <div>
                                            <label for="c-{{ $check->id }}-status" class="sr-only">Result for {{ $check->label }}</label>
                                            <select id="c-{{ $check->id }}-status" name="status" class="form-input py-1">
                                                @foreach (CheckStatus::cases() as $s)
                                                    @continue($s === CheckStatus::Skipped && $check->mandatory)
                                                    <option value="{{ $s->value }}" @selected($check->status === $s)>{{ $s->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="flex-1">
                                            <label for="c-{{ $check->id }}-evidence" class="sr-only">Evidence for {{ $check->label }}</label>
                                            <input id="c-{{ $check->id }}-evidence" name="evidence" maxlength="2000" placeholder="Evidence (link or note)" class="form-input py-1">
                                        </div>
                                        <button type="submit" class="btn-secondary py-1">Record</button>
                                    </form>
                                @endcan
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="card" aria-labelledby="history">
                <h2 id="history" class="mb-3 font-semibold">History</h2>
                <ol class="space-y-3 text-sm">
                    @forelse ($history as $event)
                        <li>
                            <p><span class="font-medium">{{ $event->label() }}</span> · {{ $event->actorName() }} · {{ $event->created_at->format('M j, Y H:i') }}</p>
                            @if ($event->note)<p class="whitespace-pre-line text-zinc-700">{{ $event->note }}</p>@endif
                            @if (! empty($event->metadata['problems']))<ul class="list-disc pl-5 text-xs text-zinc-600">@foreach ($event->metadata['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul>@endif
                        </li>
                    @empty
                        <li class="text-zinc-600">Nothing yet.</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="space-y-6" aria-label="Actions">
            @can('update', $project)
                @if (! $release->isProduction() && $release->status === ReleaseStatus::Draft)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Record the staging deployment</h2>
                        <p class="mb-3 text-sm text-zinc-600">After you deployed this version to staging yourself.</p>
                        <form method="POST" action="{{ route('releases.deploy-staging', $release) }}" class="space-y-3">
                            @csrf
                            <label for="staging_note" class="form-label">How you deployed it</label>
                            <textarea id="staging_note" name="note" rows="2" required maxlength="2000" class="form-input"></textarea>
                            <button type="submit" class="btn-primary w-full">Record deployment</button>
                        </form>
                    </section>
                @endif

                @if ($release->isProduction() && ($release->status === ReleaseStatus::Draft || $canAskAgain))
                    <section class="card">
                        <h2 class="mb-2 font-semibold">{{ $release->status === ReleaseStatus::Draft ? 'Submit for approval' : 'Request approval again' }}</h2>
                        <p class="mb-3 text-sm text-zinc-600">The founder approves this exact version, URL, rollback plan and check results. Any change after that needs a new release.</p>
                        <form method="POST" action="{{ route('releases.submit', $release) }}">
                            @csrf
                            <button type="submit" class="btn-primary w-full" @disabled($problems)>Submit</button>
                        </form>
                    </section>
                @endif

                @if ($release->status === ReleaseStatus::PendingApproval && $approvalOpen)
                    <p class="card text-sm text-zinc-600">Waiting for the founder's decision on <a href="{{ route('approvals.show', $approval) }}" class="text-emerald-700 underline">approval #{{ $approval->id }}</a>.</p>
                @endif
            @endcan

            @if ($release->status === ReleaseStatus::Approved && $approvalOpen)
                @can('deploy', $project)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Record the production deployment</h2>
                        <p class="mb-3 text-sm text-zinc-600">Company OS does not deploy. Deploy this approved version with your own tools, following the release notes, then record it here.</p>
                        <form method="POST" action="{{ route('releases.deploy', $release) }}" class="space-y-3">
                            @csrf
                            <label for="deploy_note" class="form-label">How and when you deployed it</label>
                            <textarea id="deploy_note" name="note" rows="2" required maxlength="2000" class="form-input"></textarea>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I deployed version {{ $release->version }} to {{ $release->target_url }}</label>
                            <button type="submit" class="btn-primary w-full" @disabled($problems)>Record deployment</button>
                        </form>
                    </section>
                @else
                    <p class="card text-sm text-zinc-600">Approved. Waiting for the founder to deploy it and record the deployment.</p>
                @endcan
            @endif

            @can('update', $project)
                @if (in_array($release->status, [ReleaseStatus::Deployed, ReleaseStatus::FailedVerification], true))
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Health check</h2>
                        <p class="mb-3 text-sm text-zinc-600">Requests {{ $release->target_url }} once and records the answer. A 2xx answer verifies the release{{ $release->isProduction() ? ' and marks the project delivered' : '' }}.</p>
                        <form method="POST" action="{{ route('releases.health-check', $release) }}">
                            @csrf
                            <button type="submit" class="btn-primary w-full">Run health check</button>
                        </form>
                    </section>
                @endif

                @if ($release->status === ReleaseStatus::Deployed)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Verify by hand</h2>
                        <p class="mb-3 text-sm text-zinc-600">Only when the automatic check cannot reach the site, for example behind a login.</p>
                        <form method="POST" action="{{ route('releases.verify', $release) }}" class="space-y-3">
                            @csrf
                            <label for="verify_note" class="form-label">What you checked</label>
                            <textarea id="verify_note" name="note" rows="2" required maxlength="2000" class="form-input"></textarea>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I checked the release works</label>
                            <button type="submit" class="btn-secondary w-full">Record verification</button>
                        </form>
                    </section>
                @endif

                @if (in_array($release->status, [ReleaseStatus::Deployed, ReleaseStatus::Verified, ReleaseStatus::FailedVerification], true))
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Record a rollback</h2>
                        <p class="mb-3 text-sm text-zinc-600">After you rolled back with the plan above.</p>
                        <form method="POST" action="{{ route('releases.rollback', $release) }}" class="space-y-3">
                            @csrf
                            <label for="rollback_note" class="form-label">What you did and why</label>
                            <textarea id="rollback_note" name="note" rows="2" required maxlength="2000" class="form-input"></textarea>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> The release was rolled back</label>
                            <button type="submit" class="btn-secondary w-full">Record rollback</button>
                        </form>
                    </section>
                @endif

                @if (in_array($release->status, [ReleaseStatus::Draft, ReleaseStatus::PendingApproval, ReleaseStatus::Approved], true))
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Cancel</h2>
                        <form method="POST" action="{{ route('releases.cancel', $release) }}" class="space-y-2">
                            @csrf
                            <label for="cancel_reason" class="form-label">Reason</label>
                            <input id="cancel_reason" name="reason" required maxlength="2000" class="form-input">
                            <button type="submit" class="btn-secondary w-full">Cancel release</button>
                        </form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
</x-layouts.app>
