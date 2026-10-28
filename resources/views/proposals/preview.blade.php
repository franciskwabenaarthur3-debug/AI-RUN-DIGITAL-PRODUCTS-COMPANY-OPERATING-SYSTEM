<x-layouts.app :title="'Preview '.$proposal->label()">
    <div class="mb-6">
        <p class="text-sm"><a href="{{ route('proposals.show', $proposal) }}" class="text-emerald-700 underline">Back to {{ $proposal->label() }}</a></p>
        <h1 class="text-2xl font-semibold tracking-tight">Preview</h1>
        <p class="text-sm text-zinc-600">This is exactly what the recipient will get{{ $proposal->recipient_email ? ' at '.$proposal->recipient_email : '' }}.</p>
    </div>
    <div class="card overflow-x-auto">
        @include('proposals._document')
    </div>
</x-layouts.app>
