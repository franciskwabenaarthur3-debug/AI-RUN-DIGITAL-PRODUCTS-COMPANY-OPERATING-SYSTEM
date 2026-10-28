<x-layouts.app :title="'Edit '.$project->name">
    <p class="text-sm"><a href="{{ route('projects.show', $project) }}" class="text-emerald-700 underline">{{ $project->name }}</a></p>
    <h1 class="mb-6 text-2xl font-semibold tracking-tight">Edit project details</h1>

    <form method="POST" action="{{ route('projects.update', $project) }}" class="card max-w-3xl space-y-5">
        @csrf
        @method('PUT')
        <div>
            <label for="name" class="form-label">Name</label>
            <input id="name" name="name" required maxlength="200" value="{{ old('name', $project->name) }}" class="form-input">
            <x-input-error for="name" />
        </div>
        <div>
            <label for="description" class="form-label">Description</label>
            <textarea id="description" name="description" rows="4" maxlength="5000" class="form-input">{{ old('description', $project->description) }}</textarea>
            <x-input-error for="description" />
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="start_date" class="form-label">Start date</label>
                <input id="start_date" name="start_date" type="date" value="{{ old('start_date', $project->start_date?->toDateString()) }}" class="form-input">
                <x-input-error for="start_date" />
            </div>
            <div>
                <label for="due_date" class="form-label">Due date</label>
                <input id="due_date" name="due_date" type="date" value="{{ old('due_date', $project->due_date?->toDateString()) }}" class="form-input">
                <x-input-error for="due_date" />
            </div>
        </div>
        @foreach (['repository_url' => 'Git repository URL', 'staging_url' => 'Staging URL', 'production_url' => 'Production URL'] as $field => $label)
            <div>
                <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="url" maxlength="255" placeholder="https://" value="{{ old($field, $project->{$field}) }}" class="form-input">
                <x-input-error :for="$field" />
            </div>
        @endforeach
        <p class="text-xs text-zinc-500">Company OS links to the repository; it does not clone it or deploy from it. Do not put passwords or tokens in these links.</p>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Save</button>
            <a href="{{ route('projects.show', $project) }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
