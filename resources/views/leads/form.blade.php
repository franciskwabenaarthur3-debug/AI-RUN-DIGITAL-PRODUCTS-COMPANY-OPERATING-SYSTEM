<x-layouts.app :title="$lead->exists ? 'Edit lead' : 'New lead'">
    <h1 class="mb-6 text-2xl font-semibold tracking-tight">{{ $lead->exists ? 'Edit '.$lead->company_name : 'New lead' }}</h1>

    <form method="POST" action="{{ $lead->exists ? route('leads.update', $lead) : route('leads.store') }}" class="card max-w-3xl space-y-5">
        @csrf
        @if ($lead->exists) @method('PUT') @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="company_name" class="form-label">Company name</label>
                <input id="company_name" name="company_name" required maxlength="255" value="{{ old('company_name', $lead->company_name) }}" class="form-input">
                <x-input-error for="company_name" />
            </div>
            <div>
                <label for="contact_name" class="form-label">Contact name <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="contact_name" name="contact_name" maxlength="255" value="{{ old('contact_name', $lead->contact_name) }}" class="form-input">
                <x-input-error for="contact_name" />
            </div>
            <div>
                <label for="email" class="form-label">Email <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="email" name="email" type="email" maxlength="255" value="{{ old('email', $lead->email) }}" class="form-input">
                <x-input-error for="email" />
            </div>
            <div>
                <label for="phone" class="form-label">Phone <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="phone" name="phone" type="tel" maxlength="50" value="{{ old('phone', $lead->phone) }}" class="form-input">
                <x-input-error for="phone" />
            </div>
            <div>
                <label for="website" class="form-label">Website <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="website" name="website" maxlength="2048" placeholder="example.com" value="{{ old('website', $lead->website) }}" class="form-input">
                <x-input-error for="website" />
            </div>
            <div>
                <label for="source" class="form-label">Source</label>
                <select id="source" name="source" class="form-input">
                    @foreach ($sources as $s)
                        <option value="{{ $s->value }}" @selected(old('source', $lead->source?->value ?? 'manual') === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="source" />
            </div>
            <div>
                <label for="research_status" class="form-label">Research status</label>
                <select id="research_status" name="research_status" class="form-input">
                    @foreach ($researchStatuses as $s)
                        <option value="{{ $s->value }}" @selected(old('research_status', $lead->research_status?->value ?? 'pending') === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="research_status" />
            </div>
            <div class="sm:col-span-2">
                <label for="source_reference" class="form-label">Source reference <span class="font-normal text-zinc-500">(where this information came from, with date)</span></label>
                <textarea id="source_reference" name="source_reference" rows="2" maxlength="2000" class="form-input">{{ old('source_reference', $lead->source_reference) }}</textarea>
                <x-input-error for="source_reference" />
            </div>
            <div>
                <label for="qualification_score" class="form-label">Qualification score (0–100)</label>
                <input id="qualification_score" name="qualification_score" type="number" min="0" max="100" value="{{ old('qualification_score', $lead->qualification_score) }}" class="form-input">
                <x-input-error for="qualification_score" />
            </div>
            <div>
                <label for="next_follow_up_at" class="form-label">Next follow-up</label>
                <input id="next_follow_up_at" name="next_follow_up_at" type="datetime-local" value="{{ old('next_follow_up_at', $lead->next_follow_up_at?->format('Y-m-d\TH:i')) }}" class="form-input">
                <x-input-error for="next_follow_up_at" />
            </div>
            <div class="sm:col-span-2">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="4" maxlength="10000" class="form-input">{{ old('notes', $lead->notes) }}</textarea>
                <x-input-error for="notes" />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ $lead->exists ? route('leads.show', $lead) : route('leads.index') }}" class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100">Cancel</a>
            <button type="submit" class="btn-primary">{{ $lead->exists ? 'Save changes' : 'Create lead' }}</button>
        </div>
    </form>
</x-layouts.app>
