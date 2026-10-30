<x-app-layout :pageTitle="'Customer & Supplier Registers'">
    <x-slot name="header">
        <x-page-header
            title="Customer & Supplier Registers"
            description="Per-party balances and per-document registers — who owes what, what has been settled, and what has been issued or shipped."
            icon="users"
        />
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <a href="{{ route('reports.registers.customers') }}" class="surface-card flex flex-col p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary-ink">
                    <x-icon name="users" class="size-5" />
                </div>
                <p class="text-sm font-semibold text-ink">Customers</p>
            </div>
            <p class="mt-3 flex-1 text-sm text-ink-soft">
                Balances owed by every customer — invoiced, payments received, credit notes applied and what is still due.
            </p>
            <span class="link mt-4 inline-flex items-center gap-1 text-sm">Open report <x-icon name="arrow-right" class="size-4" /></span>
        </a>

        <a href="{{ route('reports.registers.suppliers') }}" class="surface-card flex flex-col p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary-ink">
                    <x-icon name="money" class="size-5" />
                </div>
                <p class="text-sm font-semibold text-ink">Suppliers</p>
            </div>
            <p class="mt-3 flex-1 text-sm text-ink-soft">
                Amounts owed to every supplier — purchase invoices billed against, payments made and debit notes applied.
            </p>
            <span class="link mt-4 inline-flex items-center gap-1 text-sm">Open report <x-icon name="arrow-right" class="size-4" /></span>
        </a>

        <a href="{{ route('reports.registers.credit_notes') }}" class="surface-card flex flex-col p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary-ink">
                    <x-icon name="credit" class="size-5" />
                </div>
                <p class="text-sm font-semibold text-ink">Credit Notes</p>
            </div>
            <p class="mt-3 flex-1 text-sm text-ink-soft">
                Every credit note issued to a customer, with the amount applied and what is still unapplied.
            </p>
            <span class="link mt-4 inline-flex items-center gap-1 text-sm">Open report <x-icon name="arrow-right" class="size-4" /></span>
        </a>

        <a href="{{ route('reports.registers.debit_notes') }}" class="surface-card flex flex-col p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary-ink">
                    <x-icon name="invoice" class="size-5" />
                </div>
                <p class="text-sm font-semibold text-ink">Debit Notes</p>
            </div>
            <p class="mt-3 flex-1 text-sm text-ink-soft">
                Every debit note raised against a supplier, with the amount applied and what is still unapplied.
            </p>
            <span class="link mt-4 inline-flex items-center gap-1 text-sm">Open report <x-icon name="arrow-right" class="size-4" /></span>
        </a>

        <a href="{{ route('reports.registers.delivery_challans') }}" class="surface-card flex flex-col p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary-ink">
                    <x-icon name="truck" class="size-5" />
                </div>
                <p class="text-sm font-semibold text-ink">Delivery Challans</p>
            </div>
            <p class="mt-3 flex-1 text-sm text-ink-soft">
                Goods-issued register — every challan with its carrier, tracking, quantities shipped and delivery status.
            </p>
            <span class="link mt-4 inline-flex items-center gap-1 text-sm">Open report <x-icon name="arrow-right" class="size-4" /></span>
        </a>
    </div>
</x-app-layout>
