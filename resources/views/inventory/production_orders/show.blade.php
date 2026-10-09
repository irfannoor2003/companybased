<x-app-layout :pageTitle="'Production order '.$order->number">
    <x-slot name="header">
        <x-page-header title="Production order {{ $order->number }}" description="{{ $order->item?->product?->name }} · {{ $order->warehouse?->name }}" icon="zap">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.production_orders.edit') && ! in_array($order->status, ['completed', 'cancelled']))
                    <x-button href="{{ route('inventory.production_orders.edit', $order) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                @if (auth()->user()->can('suppliers.purchase_invoices.create') && ! in_array($order->status, ['completed', 'cancelled']))
                    <x-button href="{{ route('suppliers.purchase_invoices.create', ['production_order' => $order->id]) }}" variant="secondary" icon="invoice">Convert to purchase invoice</x-button>
                @endif
                <x-button href="{{ route('inventory.production_orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="text-sm font-semibold text-ink">Components</h2>
                    @if ($order->billOfMaterial)
                        <p class="mt-1 text-xs text-ink-faint">Derived from {{ $order->billOfMaterial->name }}.</p>
                    @endif
                </div>

                @if ($order->items->isEmpty())
                    <x-empty-state icon="zap" title="No components" description="No components are linked to this production order." />
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Component</th>
                                    <th>SKU</th>
                                    <th class="text-right">Required</th>
                                    <th class="text-right">Used</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->items as $line)
                                    <tr>
                                        <td class="font-medium text-ink">{{ $line->componentItem?->product?->name ?? '—' }}</td>
                                        <td class="text-ink-soft">{{ $line->componentItem?->product?->sku ?? '—' }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity_required }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity_used }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="text-sm font-semibold text-ink">Labour</h2>
                    <p class="mt-1 text-xs text-ink-faint">
                        Time recorded here is capitalised into work in progress, and transferred to the finished goods when this order completes.
                    </p>
                </div>

                @if (auth()->user()->can('inventory.production_orders.record_labour') && ! in_array($order->status, ['completed', 'cancelled']))
                    <form method="POST" action="{{ route('inventory.production_orders.labour.store', $order) }}"
                        class="grid grid-wrap items-end gap-3 border-b border-line px-5 py-4">
                        @csrf
                        <div class="min-w-[180px] flex-1">
                            <x-select name="employee_id" label="Employee" size="sm" required>
                                <option value="">Select an employee</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                        {{ $employee->first_name }} {{ $employee->last_name }}
                                    </option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="w-32">
                            <x-input type="number" name="minutes" label="Minutes" size="sm" min="1" max="1440"
                                value="{{ old('minutes') }}" required />
                        </div>
                        <div class="w-40">
                            <x-input type="date" name="work_date" label="Work date" size="sm"
                                value="{{ old('work_date', now()->toDateString()) }}" required />
                        </div>
                        <x-button type="submit" size="sm" icon="plus">Record</x-button>
                    </form>
                @endif

                @if ($labour->isEmpty())
                    <x-empty-state icon="clock" title="No labour recorded"
                        description="Time worked on this order will appear here and be absorbed into work in progress." />
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Date</th>
                                    <th class="text-right">Minutes</th>
                                    <th class="text-right">Rate</th>
                                    <th class="text-right">Cost</th>
                                    <th>Absorbed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($labour as $entry)
                                    <tr>
                                        <td class="font-medium text-ink">{{ $entry->employee?->first_name }} {{ $entry->employee?->last_name }}</td>
                                        <td class="text-ink-soft">{{ $entry->work_date?->format('Y-m-d') }}</td>
                                        <td class="text-right text-ink">{{ $entry->minutes }}</td>
                                        <td class="text-right text-ink-soft">{{ number_format((float) $entry->hourly_rate, 2) }}</td>
                                        <td class="text-right font-medium text-ink">{{ number_format((float) $entry->labour_cost, 2) }}</td>
                                        <td class="text-ink-soft">{{ $entry->absorbed_at ? 'Yes' : 'No' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Item to produce</dt>
                        <dd class="font-medium text-ink">{{ $order->item?->product?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Quantity</dt>
                        <dd class="font-medium text-ink">{{ $order->quantity }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Warehouse</dt>
                        <dd class="font-medium text-ink">{{ $order->warehouse?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Start date</dt>
                        <dd class="font-medium text-ink">{{ $order->scheduled_start_date?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">End date</dt>
                        <dd class="font-medium text-ink">{{ $order->scheduled_end_date?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Status</dt>
                        <dd><x-inventory.status-badge :status="$order->status" /></dd>
                    </div>
                </dl>
            </x-card>

            @if ($order->note)
                <x-card title="Note">
                    <p class="text-sm text-ink-soft">{{ $order->note }}</p>
                </x-card>
            @endif

            <x-card title="Summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Components</dt>
                        <dd class="font-medium text-ink">{{ $order->items->count() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Total required</dt>
                        <dd class="font-medium text-ink">{{ $order->items->sum('quantity_required') }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
