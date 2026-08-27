<x-app-layout :pageTitle="$customer->company_name">
    <x-slot name="header">
        <x-page-header title="{{ $customer->company_name }}" description="{{ $customer->contact_name ?: 'Customer' }}" icon="users">
            <x-slot name="actions">
                @if (auth()->user()->can('sales.customers.email'))
                    <x-button href="{{ route('sales.customers.email', $customer) }}" variant="secondary" icon="mail">Send Email</x-button>
                @endif
                @if (auth()->user()->can('sales.statements.view'))
                    <x-button href="{{ route('sales.customers.statement', $customer) }}" variant="secondary" icon="report">Statement</x-button>
                @endif
                @if (auth()->user()->can('sales.customers.edit'))
                    <x-button href="{{ route('sales.customers.edit', $customer) }}" icon="edit">Edit</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Recent documents" :padding="false">
                <div class="divide-y divide-line">
                    @php
                        $recent = collect()
                            ->concat($customer->quotes->map(fn ($q) => ['date' => $q->issue_date, 'type' => 'Quote', 'ref' => $q->number, 'status' => $q->status, 'total' => $q->total, 'url' => route('sales.quotes.edit', $q)]))
                            ->concat($customer->orders->map(fn ($o) => ['date' => $o->issue_date, 'type' => 'Order', 'ref' => $o->number, 'status' => $o->status, 'total' => $o->total, 'url' => route('sales.orders.edit', $o)]))
                            ->concat($customer->invoices->map(fn ($i) => ['date' => $i->issue_date, 'type' => 'Invoice', 'ref' => $i->number, 'status' => $i->status, 'total' => $i->total, 'url' => route('sales.invoices.edit', $i)]))
                            ->sortByDesc('date')
                            ->values()
                            ->take(10);
                    @endphp

                    @forelse ($recent as $doc)
                        <a href="{{ $doc['url'] }}" class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-surface-muted/40">
                            <div class="flex items-center gap-3">
                                <span class="w-16 text-xs font-medium text-ink-soft">{{ $doc['type'] }}</span>
                                <span class="text-sm font-medium text-ink">{{ $doc['ref'] }}</span>
                                <x-sales.status-badge :status="$doc['status']" />
                            </div>
                            <span class="text-sm font-medium text-ink">{{ money($doc['total']) }}</span>
                        </a>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-ink-faint">No documents yet.</div>
                    @endforelse
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Balance">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-semibold {{ $customer->balance() > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ money($customer->balance(), $customer->currency) }}
                    </span>
                    <span class="text-sm text-ink-faint">outstanding</span>
                </div>
                @if ((float) $customer->credit_limit > 0)
                    <p class="mt-2 text-sm text-ink-soft">Credit limit: {{ money($customer->credit_limit, $customer->currency) }}</p>
                @endif
                @if (auth()->user()->can('sales.statements.export'))
                    <div class="mt-4">
                        <x-button href="{{ route('sales.customers.statement.export', $customer) }}" variant="secondary" size="sm" icon="export">Export statement</x-button>
                    </div>
                @endif
            </x-card>

            <x-card title="Contact">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Short code</dt><dd class="text-ink">{{ $customer->short_code ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Contact</dt><dd class="text-ink">{{ $customer->contact_name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Email</dt><dd class="text-ink">{{ $customer->email ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Phone</dt><dd class="text-ink">{{ $customer->phone ?: ($customer->mobile ?: '—') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Tax no.</dt><dd class="text-ink">{{ $customer->tax_number ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Currency</dt><dd class="text-ink">{{ $customer->currency ?: '—' }}</dd></div>
                </dl>
                @if ($customer->notes)
                    <p class="mt-3 border-t border-line pt-3 text-sm text-ink-soft">{{ $customer->notes }}</p>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
