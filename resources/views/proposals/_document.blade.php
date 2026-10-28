{{-- The customer-facing proposal. Shared by the preview page and the email; inline styles so mail clients keep them. --}}
@php($money = fn (int $minor) => App\Support\Money::format($minor, $proposal->currency))
<div style="font-family: ui-sans-serif, system-ui, sans-serif; color: #18181b; line-height: 1.5; max-width: 680px;">
    <p style="margin: 0; font-size: 13px; color: #52525b;">{{ $company->company_name }} · Proposal {{ $proposal->reference }} (version {{ $proposal->version }})</p>
    <h1 style="margin: 4px 0 16px; font-size: 22px;">{{ $proposal->title }}</h1>
    <p style="margin: 0 0 16px; font-size: 14px;">For {{ $proposal->recipient_name ?: $proposal->recipientLabel() }}{{ $proposal->recipient_name ? ', '.$proposal->recipientLabel() : '' }}</p>

    @if ($proposal->introduction)
        <p style="margin: 0 0 16px; font-size: 14px; white-space: pre-line;">{{ $proposal->introduction }}</p>
    @endif

    <h2 style="margin: 20px 0 6px; font-size: 16px;">Scope: {{ $proposal->scope_snapshot['package'] ?? '' }}</h2>
    <ul style="margin: 0; padding-left: 20px; font-size: 14px; list-style: disc;">
        @foreach ($proposal->scope_snapshot['scope'] ?? [] as $line)<li>{{ $line }}</li>@endforeach
    </ul>
    @if (! empty($proposal->scope_snapshot['exclusions']))
        <h3 style="margin: 14px 0 6px; font-size: 14px;">Not included</h3>
        <ul style="margin: 0; padding-left: 20px; font-size: 14px; list-style: disc;">
            @foreach ($proposal->scope_snapshot['exclusions'] as $line)<li>{{ $line }}</li>@endforeach
        </ul>
    @endif
    <p style="margin: 12px 0 0; font-size: 14px;">Estimated delivery: {{ $proposal->scope_snapshot['estimated_days'] ?? '?' }} working days. Included revision rounds: {{ $proposal->scope_snapshot['revision_limit'] ?? 0 }}.</p>

    <h2 style="margin: 20px 0 6px; font-size: 16px;">Price</h2>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <thead>
            <tr style="border-bottom: 1px solid #d4d4d8; text-align: left;">
                <th style="padding: 6px 4px;">Item</th>
                <th style="padding: 6px 4px; text-align: right;">Qty</th>
                <th style="padding: 6px 4px; text-align: right;">Unit price</th>
                <th style="padding: 6px 4px; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr style="border-bottom: 1px solid #f4f4f5;">
                    <td style="padding: 6px 4px;">{{ $item->description }}</td>
                    <td style="padding: 6px 4px; text-align: right;">{{ $item->quantity }}</td>
                    <td style="padding: 6px 4px; text-align: right; white-space: nowrap;">{{ $money($item->unit_price_minor) }}</td>
                    <td style="padding: 6px 4px; text-align: right; white-space: nowrap;">{{ $money($item->line_total_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" style="padding: 6px 4px; text-align: right;">Subtotal</td><td style="padding: 6px 4px; text-align: right; white-space: nowrap;">{{ $money($proposal->subtotal_minor) }}</td></tr>
            @if ($proposal->discount_minor > 0)
                <tr><td colspan="3" style="padding: 6px 4px; text-align: right;">Discount ({{ $proposal->discount_reason }})</td><td style="padding: 6px 4px; text-align: right; white-space: nowrap;">-{{ $money($proposal->discount_minor) }}</td></tr>
            @endif
            <tr style="font-weight: 600;"><td colspan="3" style="padding: 6px 4px; text-align: right;">Total</td><td style="padding: 6px 4px; text-align: right; white-space: nowrap;">{{ $money($proposal->amount_minor) }}</td></tr>
        </tfoot>
    </table>

    <h2 style="margin: 20px 0 6px; font-size: 16px;">Payment terms</h2>
    <p style="margin: 0; font-size: 14px; white-space: pre-line;">{{ $proposal->payment_terms }}</p>
    <p style="margin: 16px 0 0; font-size: 14px;">This proposal is valid until {{ $proposal->expires_at->format('j F Y') }}. To accept or ask for changes, reply to this email.</p>
</div>
