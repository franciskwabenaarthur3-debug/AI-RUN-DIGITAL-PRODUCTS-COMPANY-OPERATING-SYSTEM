@props(['status'])
@php
    $tone = match ($status->value) {
        'qualified', 'replied', 'converted', 'active', 'complete', 'completed', 'succeeded', 'enabled' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'outreach_drafted', 'contacted', 'researched', 'in_progress', 'queued', 'running' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'unsubscribed', 'disqualified', 'lost', 'inactive', 'cancelled', 'disabled', 'created' => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20',
        'executed', 'approved' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'executing' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'rejected', 'expired', 'denied' => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20',
        'failed', 'invalidated', 'needs_reconciliation' => 'bg-red-50 text-red-800 ring-red-600/20',
        default => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $tone]) }}>{{ $status->label() }}</span>
