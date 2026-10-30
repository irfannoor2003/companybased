<x-app-layout :pageTitle="'Invoice '.$invoice->number">
    <x-slot name="header">
        <x-page-header title="Invoice {{ $invoice->number }}" description="{{ $invoice->customer?->company_name }}" icon="invoice" class="print:hidden">
            <x-slot name="actions">
                {{-- Wrapped rather than marking each button: an unmarked action
                     bar is invisible in a browser and obvious on paper, and
                     every button added later would need remembering. --}}
                <div class="flex flex-wrap items-center gap-2 print:hidden">
                    @if (auth()->user()->can('sales.invoices.view'))
                        <x-button :href="route('sales.invoices.pdf', $invoice)" variant="secondary" icon="download" target="_blank" rel="noopener">Export PDF</x-button>
                        <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'send-invoice-modal')" class="btn-secondary">
                            <x-icon name="mail" class="size-4 mr-1.5" />
                            Email Invoice
                        </button>
                    @endif
                    @if (auth()->user()->can('sales.delivery_notes.create'))
                        <x-button href="{{ route('sales.delivery_notes.create', ['invoice' => $invoice->id]) }}" variant="secondary" icon="truck">Create Delivery Note</x-button>
                    @endif
                    @if (auth()->user()->can('sales.invoices.edit'))
                        <form method="POST" action="{{ route('sales.invoices.template', $invoice) }}" class="flex items-center gap-2">
                            @csrf
                            @method('PUT')
                            <label class="text-xs text-ink-faint" for="sales_invoice_template">Template</label>
                            <select name="template" id="sales_invoice_template" onchange="this.form.submit()" class="select-input !py-1.5 !text-sm">
                                @foreach (\App\Models\SalesInvoice::templateOptions() as $value => $label)
                                    <option value="{{ $value }}" @selected($invoice->template === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </form>
                    @endif
                    @if (auth()->user()->can('suppliers.purchase_invoices.create'))
                        <x-button href="{{ route('suppliers.purchase_invoices.create') }}" variant="secondary" icon="invoice">Invoice Against Purchase Order</x-button>
                    @endif
                    <x-button :href="route('sales.invoices.edit', $invoice)" variant="secondary" icon="edit">Edit</x-button>
                    <x-button href="{{ route('sales.invoices.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <div class="mb-6 flex justify-end gap-2 print:hidden">
            <button type="button" onclick="window.print()" class="btn-ghost btn-icon btn-sm" title="Print">
                <x-icon name="printer" class="size-4" />
            </button>
        </div>

        <div class="invoice-document">
            <div class="flex justify-between border-b border-line pb-4 mb-6">
                <div>
                    @if (settings('branding.logo'))
                        <img src="{{ Storage::url(settings('branding.logo')) }}" alt="{{ company_name() }}" class="h-20">
                    @else
                        <span class="text-xl font-bold text-ink">{{ company_name() }}</span>
                    @endif
                    <p class="text-sm text-ink-faint mt-1">{{ settings('company.address') ?: '' }}</p>
                    <p class="text-sm text-ink-faint">{{ settings('company.email') ?: '' }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-bold text-ink">Invoice</h2>
                    <p class="text-sm text-ink-soft mt-1">{{ $invoice->number }}</p>
                    @if ($invoice->reference_number)
                        <p class="text-sm text-ink-soft">Ref: {{ $invoice->reference_number }}</p>
                    @endif
                    <p class="text-sm text-ink-faint">Issue date: {{ $invoice->issue_date?->format('Y-m-d') }}</p>
                    <p class="text-sm text-ink-faint">Due date: {{ $invoice->due_date?->format('Y-m-d') ?: '—' }}</p>
                    <p class="text-sm text-ink-faint">Status: {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</p>
                </div>
            </div>

            <div class="mb-6">
                <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Bill to</h3>
                <p class="font-medium text-ink">{{ $invoice->customer?->company_name }}</p>
                @if ($invoice->customer?->contact_name)
                    <p class="text-sm text-ink-soft">{{ $invoice->customer->contact_name }}</p>
                @endif
                @if ($invoice->customer?->address)
                    <p class="text-sm text-ink-soft">{{ $invoice->customer->address }}</p>
                @endif
                @if ($invoice->customer?->tax_number)
                    <p class="text-sm text-ink-soft">Tax: {{ $invoice->customer->tax_number }}</p>
                @endif
                @if ($invoice->customer?->short_code)
                    <p class="text-sm text-ink-soft">Code: {{ $invoice->customer->short_code }}</p>
                @endif
            </div>

            <table class="table-base mb-6">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Unit price</th>
                        <th class="text-right">Disc.</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        @php
                            $lineGross = (float) $item->qty * (float) $item->unit_price;
                            $lineDiscount = (float) ($item->discount_amount ?? 0) > 0
                                ? (float) $item->discount_amount
                                : round($lineGross * ((float) ($item->discount_percent ?? 0) / 100), 2);
                            $lineNet = round($lineGross - $lineDiscount, 2);
                            $lineTax = $lineNet * ((float) ($item->tax_percent ?? 0) / 100);
                            $lineTotal = $lineNet + $lineTax;
                        @endphp
                        <tr>
                            <td class="text-sm text-ink">{{ $loop->iteration }}</td>
                            <td class="text-sm text-ink">{{ $item->description ?: ($item->product?->name ?? '—') }}</td>
                            <td class="text-right text-sm text-ink">{{ number_format((float) $item->qty, 2) }}</td>
                            <td class="text-right text-sm text-ink">{{ money($item->unit_price, $invoice->currency) }}</td>
                            <td class="text-right text-sm {{ $lineDiscount > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-ink-faint' }}">{{ $lineDiscount > 0 ? '-'.money($lineDiscount, $invoice->currency) : '—' }}</td>
                            <td class="text-right text-sm text-ink">{{ money($lineTax, $invoice->currency) }}</td>
                            <td class="text-right text-sm font-medium text-ink">{{ money($lineTotal, $invoice->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php
                $docGross = $invoice->items->sum(fn ($it) => (float) $it->qty * (float) $it->unit_price);
                $docDiscount = $docGross - (float) $invoice->subtotal;
            @endphp
            <div class="flex justify-end mb-6">
                <div class="w-full max-w-xs space-y-1.5">
                    <div class="flex justify-between"><span class="text-ink-faint">Subtotal</span><span class="text-ink">{{ money($docGross, $invoice->currency) }}</span></div>
                    @if ($docDiscount > 0)
                        <div class="flex justify-between text-emerald-600 dark:text-emerald-400"><span>Discount</span><span>-{{ money($docDiscount, $invoice->currency) }}</span></div>
                    @endif
                    <div class="flex justify-between"><span class="text-ink-faint">Tax</span><span class="text-ink">{{ money($invoice->tax_amount, $invoice->currency) }}</span></div>
                    <div class="flex justify-between border-t border-line pt-1.5 font-semibold"><span>Total</span><span class="text-ink">{{ money($invoice->total, $invoice->currency) }}</span></div>
                    <div class="flex justify-between"><span class="text-ink-faint">Paid</span><span class="text-emerald-600 dark:text-emerald-400">{{ money($invoice->paid_amount, $invoice->currency) }}</span></div>
                    @if ((float) $invoice->credit_applied > 0)
                        {{-- Stated on the document rather than folded into "Paid", so the
                             customer can see this invoice was partly settled with money
                             they had already handed over. --}}
                        <div class="flex justify-between">
                            <span class="text-ink-faint">
                                Credit from customer balance
                                <span class="block text-[11px] leading-4 text-ink-faint/80">already paid on an earlier invoice</span>
                            </span>
                            <span class="text-emerald-600 dark:text-emerald-400">{{ money($invoice->credit_applied, $invoice->currency) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between font-semibold"><span>Balance</span><span class="{{ $invoice->balance() > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ money($invoice->balance(), $invoice->currency) }}</span></div>
                </div>
            </div>

            @if ($invoice->payments->isNotEmpty())
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-ink-faint uppercase mb-3">Payments received</h3>
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Bank account</th>
                                <th>Reference</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->payments as $payment)
                                <tr>
                                    <td class="text-sm text-ink">{{ $payment->payment_date?->format('Y-m-d') }}</td>
                                    <td class="text-sm text-ink-soft">{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
                                    <td class="text-sm text-ink-soft">{{ $payment->bankAccount?->name ?: '—' }}</td>
                                    <td class="text-sm text-ink-soft">{{ $payment->reference ?: '—' }}</td>
                                    <td class="text-right text-sm text-ink">{{ money($payment->amount, $invoice->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($invoice->notes)
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Notes</h3>
                    <p class="text-sm text-ink">{{ $invoice->notes }}</p>
                </div>
            @endif

            <div class="border-t border-line pt-4 text-center text-xs text-ink-faint">
                <p>Thank you for your business.</p>
            </div>
        </div>
    </div>

    <x-modal name="send-invoice-modal" title="Email Invoice {{ $invoice->number }}" description="Send this invoice directly to the customer with the official PDF attached. Sent via Accounts ({{ \App\Support\MailIdentity::accountsFromAddress() }}).">
        <form method="POST" action="{{ route('sales.invoices.send_email', $invoice) }}" class="space-y-4">
            @csrf
            <div>
                <x-input name="recipient_email" label="Recipient Email" type="email" required value="{{ old('recipient_email', $invoice->customer?->email) }}" placeholder="e.g. client@example.com" />
            </div>
            <div>
                <x-textarea name="message" label="Custom Note (Optional)" rows="3" placeholder="Add an optional message or payment reminder note...">{{ old('message') }}</x-textarea>
            </div>
            <div class="rounded-lg bg-surface-muted p-3 text-xs text-ink-soft">
                The official PDF for Invoice <strong>{{ $invoice->number }}</strong> (Total: {{ money($invoice->total, $invoice->currency) }}) will be generated and attached automatically.
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'send-invoice-modal')" class="btn-ghost">Cancel</button>
                <x-button type="submit" icon="send">Send Invoice Email</x-button>
            </div>
        </form>
    </x-modal>
</x-app-layout>
