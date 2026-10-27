<x-layouts.app title="New task">
    <h1 class="mb-6 text-2xl font-semibold tracking-tight">New task</h1>

    <form method="POST" action="{{ route('tasks.store') }}" class="card max-w-3xl space-y-5">
        @csrf
        <div>
            <label for="title" class="form-label">Title</label>
            <input id="title" name="title" required maxlength="255" value="{{ old('title') }}" class="form-input">
            <x-input-error for="title" />
        </div>
        <div>
            <label for="description" class="form-label">Description</label>
            <textarea id="description" name="description" rows="3" maxlength="10000" class="form-input">{{ old('description') }}</textarea>
            <x-input-error for="description" />
        </div>
        <div>
            <label for="instructions" class="form-label">Instructions for the agent <span class="font-normal text-zinc-500">(passed as task input)</span></label>
            <textarea id="instructions" name="instructions" rows="3" maxlength="10000" class="form-input">{{ old('instructions') }}</textarea>
            <x-input-error for="instructions" />
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label for="agent_id" class="form-label">Agent</label>
                <select id="agent_id" name="agent_id" class="form-input">
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}" @selected((int) old('agent_id', $selectedAgent) === $agent->id)>{{ $agent->name }}{{ $agent->isEnabled() ? '' : ' (disabled)' }}</option>
                    @endforeach
                </select>
                <x-input-error for="agent_id" />
            </div>
            <div>
                <label for="priority" class="form-label">Priority</label>
                <select id="priority" name="priority" class="form-input">
                    @foreach ($priorities as $p)
                        <option value="{{ $p->value }}" @selected(old('priority', 'normal') === $p->value)>{{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="due_at" class="form-label">Due</label>
                <input id="due_at" name="due_at" type="datetime-local" value="{{ old('due_at') }}" class="form-input">
                <x-input-error for="due_at" />
            </div>
        </div>
        @if ($openTasks->isNotEmpty())
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="parent_task_id" class="form-label">Parent task <span class="font-normal text-zinc-500">(optional)</span></label>
                    <select id="parent_task_id" name="parent_task_id" class="form-input">
                        <option value="">None</option>
                        @foreach ($openTasks as $t)
                            <option value="{{ $t->id }}" @selected((int) old('parent_task_id') === $t->id)>#{{ $t->id }} {{ $t->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="depends_on" class="form-label">Waits for <span class="font-normal text-zinc-500">(hold Ctrl/Cmd to pick several)</span></label>
                    <select id="depends_on" name="depends_on[]" multiple size="4" class="form-input">
                        @foreach ($openTasks as $t)
                            <option value="{{ $t->id }}" @selected(in_array($t->id, array_map('intval', old('depends_on', [])), true))>#{{ $t->id }} {{ $t->title }} ({{ $t->status->label() }})</option>
                        @endforeach
                    </select>
                    <x-input-error for="depends_on" />
                </div>
            </div>
        @endif
        <input type="hidden" name="queue_now" value="0">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="queue_now" value="1" @checked(old('queue_now', true)) class="rounded border-zinc-300 text-emerald-700">
            Queue immediately
        </label>
        <div class="flex justify-end gap-3">
            <a href="{{ route('tasks.index') }}" class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100">Cancel</a>
            <button type="submit" class="btn-primary">Create task</button>
        </div>
    </form>
</x-layouts.app>
