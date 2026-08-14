<x-app-layout :pageTitle="$supplier->company_name">
    <x-slot name="header">
        <x-page-header title="{{ $supplier->company_name }}" description="{{ $supplier->contact_name ?: 'Supplier' }}" icon="suppliers">
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.supplier_ledger.view'))
                    <x-button href="{{ route('suppliers.supplier_ledger.show', $supplier) }}" variant="secondary" icon="report">Ledger</x-button>
                @endif
                @if (auth()->user()->can('suppliers.suppliers.edit'))
                    <x-button href="{{ route('suppliers.suppliers.edit', $supplier) }}" icon="edit">Edit</x-button>
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
                            ->concat($supplier->purchaseQuotes->map(fn ($q) => ['date' => $q->issue_date, 'type' => 'Quote', 'ref' => $q->number, 'status' => $q->status, 'total' => $q->total, 'url' => route('suppliers.purchase_quotes.edit', $q)]))
                            ->concat($supplier->purchaseOrders->map(fn ($o) => ['date' => $o->order_date, 'type' => 'Order', 'ref' => $o->number, 'status' => $o->status, 'total' => $o->total, 'url' => route('suppliers.purchase_orders.edit', $o)]))
                            ->concat($supplier->purchaseInvoices->map(fn ($i) => ['date' => $i->issue_date, 'type' => 'Invoice', 'ref' => $i->number, 'status' => $i->status, 'total' => $i->total, 'url' => route('suppliers.purchase_invoices.edit', $i)]))
                            ->sortByDesc('date')
                            ->values()
                            ->take(10);
                    @endphp

                    @forelse ($recent as $doc)
                        <a href="{{ $doc['url'] }}" class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-surface-muted/40">
                            <div class="flex items-center gap-3">
                                <span class="w-16 text-xs font-medium text-ink-soft">{{ $doc['type'] }}</span>
                                <span class="text-sm font-medium text-ink">{{ $doc['ref'] }}</span>
                                <x-suppliers.status-badge :status="$doc['status']" />
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
                    <span class="text-2xl font-semibold {{ $supplier->balance() > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ money($supplier->balance(), $supplier->currency) }}
                    </span>
                    <span class="text-sm text-ink-faint">payable</span>
                </div>
                @if (auth()->user()->can('suppliers.supplier_ledger.export'))
                    <div class="mt-4">
                        <x-button href="{{ route('suppliers.supplier_ledger.export', $supplier) }}" variant="secondary" size="sm" icon="export">Export ledger</x-button>
                    </div>
                @endif
            </x-card>

            <x-card title="Contact">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Short code</dt><dd class="text-ink">{{ $supplier->short_code ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Contact</dt><dd class="text-ink">{{ $supplier->contact_name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Email</dt><dd class="text-ink">{{ $supplier->email ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Phone</dt><dd class="text-ink">{{ $supplier->phone ?: ($supplier->mobile ?: '—') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Tax no.</dt><dd class="text-ink">{{ $supplier->tax_number ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Payment terms</dt><dd class="text-ink">{{ $supplier->payment_terms ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">Currency</dt><dd class="text-ink">{{ $supplier->currency ?: '—' }}</dd></div>
                </dl>
                @if ($supplier->notes)
                    <p class="mt-3 border-t border-line pt-3 text-sm text-ink-soft">{{ $supplier->notes }}</p>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
