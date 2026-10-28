@props(['status'])
@php
    $tone = match ($status->value) {
        'qualified', 'replied', 'converted', 'accepted', 'active', 'complete', 'completed', 'succeeded', 'enabled' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'outreach_drafted', 'contacted', 'sent', 'researched', 'in_progress', 'queued', 'running' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'unsubscribed', 'disqualified', 'lost', 'draft', 'withdrawn', 'declined', 'inactive', 'cancelled', 'disabled', 'created' => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20',
        'executed', 'approved', 'approved_for_release', 'delivered', 'verified', 'passed', 'closed', 'resolved' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'executing', 'planning', 'internal_review', 'client_review', 'deployed', 'in_review', 'scheduled', 'info' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'rejected', 'expired', 'denied', 'skipped', 'rolled_back', 'on_hold', 'wont_fix', 'paused', 'ended', 'low' => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20',
        'failed', 'invalidated', 'needs_reconciliation', 'failed_verification', 'high', 'critical' => 'bg-red-50 text-red-800 ring-red-600/20',
        default => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $tone]) }}>{{ $status->label() }}</span>
