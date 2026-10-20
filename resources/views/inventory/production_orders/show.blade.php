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

    {{--
        Yield, only once the run has reported it. These are the numbers the shop
        floor gives when the job is finished, so they are meaningless before then.
    --}}
    @if ($order->status === 'completed' && $order->quantity_good !== null)
        @php
            $scrap = (float) ($order->quantity_scrap ?? 0);
            $produced = (float) $order->quantity_good + $scrap;
            $yieldPercent = $produced > 0 ? round(((float) $order->quantity_good / $produced) * 100, 1) : 0;
        @endphp
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat-card label="Good output" :value="rtrim(rtrim(number_format((float) $order->quantity_good, 3), '0'), '.')"
                         icon="check" tone="success" />
            <x-stat-card label="Scrapped" :value="rtrim(rtrim(number_format($scrap, 3), '0'), '.')"
                         icon="warning" :tone="$scrap > 0 ? 'danger' : 'default'" />
            <x-stat-card label="Yield" :value="$yieldPercent.'%'" icon="chart"
                         :tone="$yieldPercent >= 95 ? 'success' : ($yieldPercent >= 80 ? 'warning' : 'danger')" />
        </div>
        <p class="mt-2 text-xs text-ink-faint">
            Ordered {{ rtrim(rtrim(number_format((float) $order->quantity, 3), '0'), '.') }},
            produced {{ rtrim(rtrim(number_format($produced, 3), '0'), '.') }}.
            Scrap is absorbed into the cost of the good output and surfaces on
            Production Variance against the standard cost.
        </p>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            {{--
                The routing, as released onto this order.

                Planned against actual, because that comparison is the point: a
                standard time nothing records against is an assumption, not a plan.
            --}}
            @if ($order->operations->isNotEmpty())
                <x-card :padding="false" class="mb-6">
                    <div class="border-b border-line px-5 py-4">
                        <h2 class="text-sm font-semibold text-ink">Operations</h2>
                        <p class="mt-1 text-xs text-ink-faint">
                            Released from the bill of materials. Recorded time is never
                            overwritten by re-releasing the routing.
                        </p>
                    </div>
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th class="w-10">#</th>
                                    <th>Operation</th>
                                    <th>Work centre</th>
                                    <th class="text-right">Planned</th>
                                    <th class="text-right">Actual</th>
                                    <th class="text-right">Variance</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->operations as $operation)
                                    <tr>
                                        <td class="text-ink-faint">{{ $operation->sequence }}</td>
                                        <td class="font-medium text-ink">{{ $operation->name }}</td>
                                        <td class="text-ink-soft">{{ $operation->workCentre?->name ?? '—' }}</td>
                                        <td class="text-right text-ink">{{ number_format((float) $operation->planned_minutes, 1) }}</td>
                                        <td class="text-right text-ink">
                                            @if ($operation->isReported())
                                                {{ number_format((float) $operation->minutes_spent, 1) }}
                                            @else
                                                <span class="text-ink-faint">Not reported</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @php $variance = $operation->varianceMinutes(); @endphp
                                            @if ($variance === null)
                                                <span class="text-ink-faint">—</span>
                                            @elseif ($variance < 0)
                                                {{-- Over standard: the number the shop floor
                                                     actually wants to see. --}}
                                                <span class="text-rose-600 dark:text-rose-400">
                                                    {{ number_format(abs($variance), 1) }} over
                                                </span>
                                            @else
                                                <span class="text-emerald-600 dark:text-emerald-400">
                                                    {{ number_format($variance, 1) }} under
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if (auth()->user()->can('inventory.production_orders.record_labour') && ! in_array($order->status, ['completed', 'cancelled']))
                                                <form method="POST" action="{{ route('inventory.production_orders.operations.store', $order) }}" class="flex items-center justify-end gap-2">
                                                    @csrf
                                                    <input type="hidden" name="operation_id" value="{{ $operation->id }}">
                                                    <input type="number" name="minutes" step="0.1" min="0" placeholder="min"
                                                           class="w-20 rounded-lg border-line text-sm text-ink">
                                                    <x-button type="submit" size="sm" variant="ghost" icon="check">Record</x-button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endif

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
                                    <th class="text-right">Scrapped</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->items as $line)
                                    <tr>
                                        <td class="font-medium text-ink">{{ $line->componentItem?->product?->name ?? '—' }}</td>
                                        <td class="text-ink-soft">{{ $line->componentItem?->product?->sku ?? '—' }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity_required }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity_used }}</td>
                                        {{-- Component scrap only appears once reported, so an
                                             order that predates this column stays clean. --}}
                                        @if ((float) ($line->quantity_scrapped ?? 0) > 0)
                                            <td class="text-right text-rose-600 dark:text-rose-400"
                                                title="{{ $line->scrap_reason }}">{{ $line->quantity_scrapped }}</td>
                                        @else
                                            <td class="text-right text-ink-faint">—</td>
                                        @endif
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
                    {{--
                        flex, not grid.

                        Every child here is a flex idiom -- `flex-1`, `min-w-[180px]`,
                        `w-32`, `w-40` -- and `flex-1` does nothing in a grid. This row
                        also carried a `grid-wrap` token that is defined nowhere in
                        app.css and is not a Tailwind utility, so it silently did
                        nothing. The result was four auto-sized columns on one line with
                        no wrapping, overflowing on a narrow screen instead of stacking.
                    --}}
                    <form method="POST" action="{{ route('inventory.production_orders.labour.store', $order) }}"
                        class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
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

            {{--
                Completing the run, with the yield reported on the same submission.

                This used to be reachable only by hand-POSTing quantity_good and
                quantity_scrap to the status endpoint. The figures decide what the
                finished goods cost, so an operator being unable to enter them meant
                the costing was arithmetic performed outside the application.

                Withheld entirely once the order is closed: the yield stat cards at the
                top of the page already report the outcome, so an editable-looking form
                that cannot be submitted would only be misleading.
            --}}
            @if (! in_array($order->status, ['completed', 'cancelled'], true)
                && auth()->user()->can('inventory.production_orders.update_status'))
                <x-card title="Complete and report yield" class="mt-6">
                    <x-inventory.production-yield-form
                        :order="$order"
                        :action="route('inventory.production_orders.status', $order)"
                    />
                </x-card>
            @endif
        </div>

</x-app-layout>
