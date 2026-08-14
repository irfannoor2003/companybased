<x-settings-layout page-title="Currencies">
    <div class="mt-6 space-y-6">
        <x-card
            title="Exchange rates"
            description="Reference rates used to convert documents into your reporting currency ({{ $baseCurrency }}). The rate is snapshotted onto each document when it is created, so later changes here never alter existing documents."
        >
            <form method="POST" action="{{ route('settings.currencies.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="flex items-center justify-between rounded-xl border border-line bg-surface-muted px-4 py-3">
                    <div>
                        <p class="text-sm font-medium">Reporting currency</p>
                        <p class="text-xs text-ink-soft">Rates below convert 1 unit of each currency into {{ $baseCurrency }}.</p>
                    </div>
                    <span class="rounded-lg bg-primary/10 px-3 py-1 text-sm font-semibold text-primary">{{ $baseCurrency }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-ink-soft">
                                <th class="px-3 py-2 font-medium">Currency</th>
                                <th class="px-3 py-2 font-medium">Rate to {{ $baseCurrency }}</th>
                                <th class="px-3 py-2 font-medium">Effective date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($currencies as $currency)
                                <tr class="border-b border-line/60">
                                    <td class="px-3 py-2">
                                        <x-input name="rates[{{ $loop->index }}][currency_code]" value="{{ $currency['code'] }}" maxlength="8" class="w-28" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <x-input name="rates[{{ $loop->index }}][rate_to_base]" type="number" step="0.000001" min="0" value="{{ old('rates.'.$loop->index.'.rate_to_base', $currency['rate']) }}" class="w-40" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <x-input name="rates[{{ $loop->index }}][effective_date]" type="date" value="{{ old('rates.'.$loop->index.'.effective_date', $currency['effective_date']) }}" class="w-44" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-3 py-8 text-center text-sm text-ink-soft">
                                        No currencies in use yet. Create documents in another currency and they will appear here.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save rates</x-button>
                </div>
            </form>
        </x-card>

        <x-card title="How rates are used">
            <ul class="list-disc space-y-1 pl-5 text-sm text-ink-soft">
                <li>When a document is created in a non-base currency, the latest rate for its effective date is copied onto the document.</li>
                <li>Each document keeps its own snapshot, so later rate changes do not retroactively change posted documents.</li>
                <li>Company-wide rollups — cash flow totals, statements and supplier ledgers — convert every document using its own snapshotted rate.</li>
            </ul>
        </x-card>
    </div>
</x-settings-layout>
