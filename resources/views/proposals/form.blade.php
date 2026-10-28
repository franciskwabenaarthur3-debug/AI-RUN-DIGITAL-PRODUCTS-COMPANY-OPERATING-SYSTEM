@php($editing = $proposal->exists)
<x-layouts.app :title="$editing ? 'Edit '.$proposal->label() : 'New proposal'">
    <h1 class="mb-1 text-2xl font-semibold tracking-tight">{{ $editing ? 'Edit '.$proposal->label() : 'New proposal' }}</h1>
    <p class="mb-6 text-sm text-zinc-600">Prices are calculated by Company OS: the package line uses the package price, and the total is worked out from the lines and discount.</p>

    <form method="POST" action="{{ $editing ? route('proposals.update', $proposal) : route('proposals.store') }}" class="max-w-4xl space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="card space-y-5" aria-labelledby="who">
            <h2 id="who" class="font-semibold">Recipient and package</h2>
            @if ($editing)
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">For</dt><dd>{{ $proposal->recipientLabel() }}</dd></div>
                    <div><dt class="text-zinc-500">Package</dt><dd>{{ $proposal->scope_snapshot['package'] ?? '' }}</dd></div>
                </dl>
            @else
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="lead_id" class="form-label">Lead</label>
                        <select id="lead_id" name="lead_id" class="form-input">
                            <option value="">No lead</option>
                            @foreach ($leads as $l)
                                <option value="{{ $l->id }}" @selected((int) old('lead_id', $proposal->lead_id) === $l->id)>{{ $l->company_name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="lead_id" />
                    </div>
                    <div>
                        <label for="customer_id" class="form-label">or customer</label>
                        <select id="customer_id" name="customer_id" class="form-input">
                            <option value="">No customer</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}" @selected((int) old('customer_id', $proposal->customer_id) === $c->id)>{{ $c->company_name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="customer_id" />
                    </div>
                    <div class="sm:col-span-2">
                        <label for="service_package_id" class="form-label">Service package</label>
                        <select id="service_package_id" name="service_package_id" required class="form-input">
                            <option value="">Choose a package</option>
                            @foreach ($packages as $pkg)
                                <option value="{{ $pkg->id }}" @selected((int) old('service_package_id') === $pkg->id)>{{ $pkg->name }} ({{ App\Support\Money::format($pkg->base_price_minor, $pkg->currency) }})</option>
                            @endforeach
                        </select>
                        <x-input-error for="service_package_id" />
                        <p class="mt-1 text-xs text-zinc-500">The proposal uses the package's currency, price, scope and exclusions.</p>
                    </div>
                </div>
            @endif
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="recipient_name" class="form-label">Recipient name <span class="font-normal text-zinc-500">{{ $editing ? '' : '(blank to use the contact on record)' }}</span></label>
                    <input id="recipient_name" name="recipient_name" maxlength="150" value="{{ old('recipient_name', $proposal->recipient_name) }}" class="form-input">
                    <x-input-error for="recipient_name" />
                </div>
                <div>
                    <label for="recipient_email" class="form-label">Recipient email <span class="font-normal text-zinc-500">{{ $editing ? '' : '(blank to use the email on record)' }}</span></label>
                    <input id="recipient_email" name="recipient_email" type="email" maxlength="255" value="{{ old('recipient_email', $proposal->recipient_email) }}" class="form-input">
                    <x-input-error for="recipient_email" />
                </div>
            </div>
        </section>

        <section class="card space-y-5" aria-labelledby="content">
            <h2 id="content" class="font-semibold">Content</h2>
            <div>
                <label for="title" class="form-label">Title</label>
                <input id="title" name="title" required maxlength="200" value="{{ old('title', $proposal->title) }}" class="form-input">
                <x-input-error for="title" />
            </div>
            <div>
                <label for="introduction" class="form-label">Introduction</label>
                <textarea id="introduction" name="introduction" rows="4" maxlength="5000" class="form-input">{{ old('introduction', $proposal->introduction) }}</textarea>
                <x-input-error for="introduction" />
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="scope" class="form-label">Scope <span class="font-normal text-zinc-500">(one item per line{{ $editing ? '' : '; blank to use the package scope' }})</span></label>
                    <textarea id="scope" name="scope" rows="6" @required($editing) class="form-input">{{ old('scope', implode("\n", $proposal->scope_snapshot['scope'] ?? [])) }}</textarea>
                    <x-input-error for="scope" />
                </div>
                <div>
                    <label for="exclusions" class="form-label">Exclusions <span class="font-normal text-zinc-500">(one item per line)</span></label>
                    <textarea id="exclusions" name="exclusions" rows="6" class="form-input">{{ old('exclusions', implode("\n", $proposal->scope_snapshot['exclusions'] ?? [])) }}</textarea>
                    <x-input-error for="exclusions" />
                </div>
            </div>
        </section>

        <section class="card space-y-4" aria-labelledby="pricing">
            <h2 id="pricing" class="font-semibold">Pricing</h2>
            <p class="text-sm text-zinc-600">The first line is always the package at its listed price. Add extra lines below; leave a row empty to skip it.</p>
            @php($rows = old('extra_items', $items))
            @php($rows = array_pad(array_values($rows), max(count($rows) + 2, 3), ['description' => '', 'quantity' => '', 'unit_price' => '']))
            <x-input-error for="extra_items" />
            <div class="space-y-3">
                @foreach ($rows as $i => $row)
                    <fieldset class="grid gap-3 sm:grid-cols-6">
                        <legend class="sr-only">Extra line {{ $i + 1 }}</legend>
                        <div class="sm:col-span-4">
                            <label for="item_{{ $i }}_description" class="form-label">Extra line {{ $i + 1 }}</label>
                            <input id="item_{{ $i }}_description" name="extra_items[{{ $i }}][description]" maxlength="300" value="{{ $row['description'] ?? '' }}" class="form-input">
                            <x-input-error for="extra_items.{{ $i }}.description" />
                        </div>
                        <div>
                            <label for="item_{{ $i }}_quantity" class="form-label">Qty</label>
                            <input id="item_{{ $i }}_quantity" name="extra_items[{{ $i }}][quantity]" type="number" min="1" max="1000" value="{{ $row['quantity'] ?? '' }}" class="form-input">
                            <x-input-error for="extra_items.{{ $i }}.quantity" />
                        </div>
                        <div>
                            <label for="item_{{ $i }}_unit_price" class="form-label">Unit price</label>
                            <input id="item_{{ $i }}_unit_price" name="extra_items[{{ $i }}][unit_price]" inputmode="decimal" value="{{ $row['unit_price'] ?? '' }}" class="form-input">
                            <x-input-error for="extra_items.{{ $i }}.unit_price" />
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <div class="grid gap-5 border-t border-zinc-100 pt-4 sm:grid-cols-3">
                <div>
                    <label for="discount_type" class="form-label">Discount</label>
                    <select id="discount_type" name="discount_type" class="form-input">
                        @foreach (['none' => 'No discount', 'percent' => 'Percentage', 'amount' => 'Fixed amount'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('discount_type', $discount['type']) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="discount_type" />
                </div>
                <div>
                    <label for="discount_value" class="form-label">Percent or amount</label>
                    <input id="discount_value" name="discount_value" inputmode="decimal" value="{{ old('discount_value', $discount['value']) }}" class="form-input">
                    <x-input-error for="discount_value" />
                    <p class="mt-1 text-xs text-zinc-500">At most {{ config('company_os.proposals.max_discount_percent') }}% of the subtotal.</p>
                </div>
                <div>
                    <label for="discount_reason" class="form-label">Reason for discount</label>
                    <input id="discount_reason" name="discount_reason" maxlength="500" value="{{ old('discount_reason', $proposal->discount_reason) }}" class="form-input">
                    <x-input-error for="discount_reason" />
                </div>
            </div>
            <x-input-error for="items" />
        </section>

        <section class="card grid gap-5 sm:grid-cols-3" aria-label="Terms">
            <div class="sm:col-span-2">
                <label for="payment_terms" class="form-label">Payment terms</label>
                <textarea id="payment_terms" name="payment_terms" rows="3" maxlength="2000" @required($editing) class="form-input">{{ old('payment_terms', $proposal->payment_terms) }}</textarea>
                <x-input-error for="payment_terms" />
            </div>
            <div>
                <label for="expires_at" class="form-label">Valid until</label>
                <input id="expires_at" name="expires_at" type="date" @required($editing) value="{{ old('expires_at', $proposal->expires_at?->toDateString()) }}" class="form-input">
                <x-input-error for="expires_at" />
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('proposals.show', $proposal) : route('proposals.index') }}" class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100">Cancel</a>
            <button type="submit" class="btn-primary">{{ $editing ? 'Save draft' : 'Create draft' }}</button>
        </div>
    </form>
</x-layouts.app>
