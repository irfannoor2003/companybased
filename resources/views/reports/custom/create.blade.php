<x-app-layout :pageTitle="'Custom Report Builder'">
    <x-slot name="header">
        <x-page-header
            title="Custom Report Builder"
            description="Pick a data source, choose columns, apply filters and save for reuse."
            icon="reports"
        >
            <x-slot name="actions">
                <x-button href="{{ route('reports.custom.index') }}" variant="ghost" icon="arrow-left">Back to reports</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
    $moduleFields = [
        'sales' => [
            ['key' => 'number',       'label' => 'Invoice Number'],
            ['key' => 'customer',     'label' => 'Customer'],
            ['key' => 'issue_date',   'label' => 'Issue Date'],
            ['key' => 'due_date',     'label' => 'Due Date'],
            ['key' => 'status',       'label' => 'Status'],
            ['key' => 'subtotal',     'label' => 'Subtotal'],
            ['key' => 'tax_amount',   'label' => 'Tax Amount'],
            ['key' => 'total',        'label' => 'Total'],
            ['key' => 'paid_amount',  'label' => 'Paid Amount'],
        ],
        'inventory' => [
            ['key' => 'name',         'label' => 'Item Name'],
            ['key' => 'sku',          'label' => 'SKU'],
            ['key' => 'category',     'label' => 'Category'],
            ['key' => 'warehouse',    'label' => 'Warehouse'],
            ['key' => 'stock_qty',    'label' => 'Stock Quantity'],
            ['key' => 'unit_cost',    'label' => 'Unit Cost'],
            ['key' => 'reorder_level','label' => 'Reorder Level'],
        ],
        'employees' => [
            ['key' => 'name',         'label' => 'Employee Name'],
            ['key' => 'department',   'label' => 'Department'],
            ['key' => 'attendance_date','label' => 'Date'],
            ['key' => 'check_in_at',  'label' => 'Check In'],
            ['key' => 'check_out_at', 'label' => 'Check Out'],
            ['key' => 'status',       'label' => 'Status'],
            ['key' => 'method',       'label' => 'Method'],
        ],
        'banking' => [
            ['key' => 'account',      'label' => 'Account'],
            ['key' => 'date',         'label' => 'Date'],
            ['key' => 'description',  'label' => 'Description'],
            ['key' => 'type',         'label' => 'Type'],
            ['key' => 'amount',       'label' => 'Amount'],
            ['key' => 'reconciled',   'label' => 'Reconciled'],
        ],
        'suppliers' => [
            ['key' => 'company_name', 'label' => 'Supplier Name'],
            ['key' => 'invoice_number','label' => 'Invoice Number'],
            ['key' => 'issue_date',   'label' => 'Issue Date'],
            ['key' => 'status',       'label' => 'Status'],
            ['key' => 'total',        'label' => 'Total'],
            ['key' => 'paid_amount',  'label' => 'Paid Amount'],
        ],
    ];
    @endphp

    <div
        x-data="reportBuilder({{ json_encode($moduleFields) }})"
        x-cloak
    >
        <form method="POST" action="{{ route('reports.custom.store') }}" @submit="prepareSubmit">
            @csrf

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                {{-- Left: Definition --}}
                <div class="space-y-5 lg:col-span-2">

                    {{-- Basic Info --}}
                    <x-card>
                        <div class="space-y-4">
                            <x-input name="name" label="Report Name" placeholder="e.g. Monthly Sales Summary" required value="{{ old('name') }}" />
                            <x-input name="description" label="Description (optional)" placeholder="What does this report show?" value="{{ old('description') }}" />
                        </div>
                    </x-card>

                    {{-- Data Source --}}
                    <x-card>
                        <p class="mb-3 text-sm font-semibold text-ink">Data Source</p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach(array_keys($moduleFields) as $mod)
                                <label
                                    :class="module === '{{ $mod }}' ? 'border-primary bg-primary/5 text-ink' : 'border-line text-ink-soft hover:border-primary/40'"
                                    class="flex cursor-pointer items-center gap-2 rounded-xl border-2 px-4 py-3 transition-all"
                                    @click="selectModule('{{ $mod }}')"
                                >
                                    <input type="radio" name="module" value="{{ $mod }}" x-model="module" class="sr-only" />
                                    <x-icon name="{{ $mod === 'sales' ? 'invoice' : ($mod === 'inventory' ? 'inventory' : ($mod === 'employees' ? 'employees' : ($mod === 'banking' ? 'bank' : 'purchasing'))) }}" class="size-4 shrink-0" />
                                    <span class="text-sm font-medium capitalize">{{ ucfirst($mod) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <input type="hidden" name="fields" :value="JSON.stringify(selectedFields)" />
                        <input type="hidden" name="filters" :value="JSON.stringify(filters)" />
                    </x-card>

                    {{-- Field Picker --}}
                    <x-card x-show="module !== ''" x-transition>
                        <p class="mb-3 text-sm font-semibold text-ink">Choose Columns</p>
                        <p class="mb-4 text-xs text-ink-faint">Select the columns you want to appear in this report.</p>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="field in availableFields" :key="field.key">
                                <label
                                    :class="selectedFields.includes(field.key) ? 'border-primary bg-primary/5 text-ink' : 'border-line text-ink-soft hover:border-primary/40'"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 transition-all"
                                >
                                    <input
                                        type="checkbox"
                                        :value="field.key"
                                        @change="toggleField(field.key)"
                                        :checked="selectedFields.includes(field.key)"
                                        class="size-4 rounded border-line text-primary"
                                    />
                                    <span class="text-sm" x-text="field.label"></span>
                                </label>
                            </template>
                        </div>
                    </x-card>

                    {{-- Filter Builder --}}
                    <x-card x-show="module !== ''" x-transition>
                        <div class="mb-3 flex items-center justify-between">
                            <p class="text-sm font-semibold text-ink">Filters</p>
                            <button type="button" @click="addFilter()" class="btn-ghost btn-sm inline-flex items-center gap-1 text-xs">
                                <x-icon name="plus" class="size-3.5" />
                                Add filter
                            </button>
                        </div>

                        <div x-show="filters.length === 0" class="rounded-lg border border-dashed border-line py-6 text-center text-sm text-ink-faint">
                            No filters added yet. Click <strong>Add filter</strong> to narrow down results.
                        </div>

                        <div class="space-y-2">
                            <template x-for="(filter, index) in filters" :key="index">
                                <div class="flex items-center gap-2">
                                    <select x-model="filter.field" class="select-base flex-1">
                                        <option value="">Select field…</option>
                                        <template x-for="f in availableFields" :key="f.key">
                                            <option :value="f.key" x-text="f.label"></option>
                                        </template>
                                    </select>
                                    <select x-model="filter.operator" class="select-base w-36">
                                        <option value="equals">equals</option>
                                        <option value="not_equals">not equals</option>
                                        <option value="contains">contains</option>
                                        <option value="gt">greater than</option>
                                        <option value="lt">less than</option>
                                    </select>
                                    <input
                                        type="text"
                                        x-model="filter.value"
                                        placeholder="Value"
                                        class="input-base flex-1"
                                    />
                                    <button type="button" @click="removeFilter(index)" class="btn-ghost btn-icon btn-sm text-rose-500 shrink-0" title="Remove filter">
                                        <x-icon name="trash" class="size-4" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    </x-card>

                </div>

                {{-- Right: Summary + Actions --}}
                <div class="space-y-5">
                    <x-card>
                        <p class="mb-3 text-sm font-semibold text-ink">Summary</p>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-ink-soft">Module</dt>
                                <dd class="font-medium capitalize text-ink" x-text="module || '—'"></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-soft">Columns selected</dt>
                                <dd class="font-medium text-ink" x-text="selectedFields.length"></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-soft">Filters applied</dt>
                                <dd class="font-medium text-ink" x-text="filters.length"></dd>
                            </div>
                        </dl>

                        <div class="mt-5 border-t border-line pt-5 space-y-2">
                            <x-button type="submit" class="w-full" icon="save">Save Report</x-button>
                            <x-button href="{{ route('reports.custom.index') }}" variant="ghost" class="w-full">Cancel</x-button>
                        </div>
                    </x-card>

                    <x-card>
                        <div class="flex gap-3">
                            <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <x-icon name="info" class="size-4" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-ink">How it works</p>
                                <p class="mt-1 text-xs text-ink-soft">Select a module, choose which columns to display, and add optional filters. Save the report to access it anytime from the Reports page.</p>
                            </div>
                        </div>
                    </x-card>
                </div>

            </div>
        </form>
    </div>

    @push('head')
    <script>
        function reportBuilder(moduleFields) {
            return {
                module: '',
                availableFields: [],
                selectedFields: [],
                filters: [],

                selectModule(mod) {
                    this.module = mod;
                    this.availableFields = moduleFields[mod] || [];
                    this.selectedFields = [];
                    this.filters = [];
                },

                toggleField(key) {
                    const idx = this.selectedFields.indexOf(key);
                    if (idx === -1) {
                        this.selectedFields.push(key);
                    } else {
                        this.selectedFields.splice(idx, 1);
                    }
                },

                addFilter() {
                    this.filters.push({ field: '', operator: 'equals', value: '' });
                },

                removeFilter(index) {
                    this.filters.splice(index, 1);
                },

                prepareSubmit(e) {
                    if (!this.module) {
                        e.preventDefault();
                        alert('Please select a data source (module) before saving.');
                    }
                    if (this.selectedFields.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one column.');
                    }
                }
            }
        }
    </script>
    @endpush

</x-app-layout>
