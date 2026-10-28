<li class="flex flex-wrap items-center justify-between gap-2 py-2">
    <span>
        <a href="{{ route('tasks.show', $task) }}" class="{{ $task->status === App\Enums\TaskStatus::Completed ? 'text-zinc-500 line-through' : 'text-zinc-900' }} underline-offset-2 hover:underline">{{ $task->title }}</a>
        <span class="block text-xs text-zinc-500">{{ $taskLine($task) }}</span>
    </span>
    <span class="flex items-center gap-2">
        <x-status-badge :status="$task->status" />
        @if (! $task->agent_id && $task->status->canBeCompletedByPerson())
            @can('update', $project)
                <form method="POST" action="{{ route('projects.tasks.complete', [$project, $task]) }}">
                    @csrf
                    <button type="submit" class="btn-secondary px-2 py-1 text-xs" aria-label="Mark done: {{ $task->title }}">Mark done</button>
                </form>
            @endcan
        @endif
    </span>
</li>
