@props([
    'workCentres' => [],
    'initialOperations' => [],
])

{{--
    Define a routing: the sequence of operations a product goes through.

    A routing is the process half of a recipe. Without an editor here the
    `inventory_bom_operations` table could only ever be populated by a tinker session,
    so the work-centre capacity report, the planned-versus-actual comparison on a
    production order, and the machine-hour absorption basis all had nothing to read --
    the backend was correct and the data could never get in.

    Operations belong to the BILL OF MATERIALS rather than the product, because
    changing how a thing is made is a change of recipe.

    Existing rows carry their `id` back through the form. That is load-bearing, not a
    convenience: a production order COPIES the routing onto itself and keeps
    `bom_operation_id`, so a delete-and-recreate sync would orphan every released order
    and break re-release matching against jobs that are half done.
--}}

<div
    x-data="{
        operations: {{ \Illuminate\Support\Js::from($initialOperations) }},
        addRow() {
            this.operations.push({
                id: '',
                sequence: this.operations.length + 1,
                work_centre_id: '',
                name: '',
                setup_minutes: 0,
                run_minutes_per_unit: 0,
                notes: '',
            });
        },
        removeRow(i) {
            this.operations.splice(i, 1);
        },
    }"
>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-ink">Routing</p>
            <p class="mt-1 text-xs text-ink-faint">
                The steps the job goes through, and how long each takes. Standard time is
                setup plus run-per-unit times the batch, so a 10-unit and a 1,000-unit
                run on the same centre are correctly different.
            </p>
        </div>
        @if ($workCentres->isNotEmpty())
            <x-button type="button" size="sm" variant="secondary" icon="plus" x-on:click="addRow()">Add operation</x-button>
        @endif
    </div>

    @if ($workCentres->isEmpty())
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No work centres exist yet, so there is nowhere for a routing to point.
            <a href="{{ route('inventory.work_centres.create') }}" class="font-medium text-primary underline">Create one first</a>.
        </p>
    @else
        <template x-if="operations.length === 0">
            <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
                No routing yet. A bill of materials with no routing is still perfectly
                usable — it just has no standard times or work centre load.
            </p>
        </template>

        <div class="space-y-2">
            <template x-for="(op, i) in operations" :key="op.id || 'new-' + i">
                <div class="rounded-lg border border-line bg-surface-muted/40 p-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-[3fr_120px_130px_130px_40px]">
                        <select :name="'operations[' + i + '][work_centre_id]'" x-model="op.work_centre_id" class="select-input">
                            <option value="">— Select work centre —</option>
                            @foreach ($workCentres as $centre)
                                <option value="{{ $centre->id }}">
                                    {{ $centre->name }}{{ $centre->code ? ' ('.$centre->code.')' : '' }} — {{ ucfirst($centre->type) }}
                                </option>
                            @endforeach
                        </select>
                        <input type="text" :name="'operations[' + i + '][name]'" x-model="op.name"
                               class="input" placeholder="Operation name">
                        <input type="number" step="0.01" min="0" :name="'operations[' + i + '][setup_minutes]'" x-model="op.setup_minutes"
                               class="input" placeholder="Setup min">
                        <input type="number" step="0.0001" min="0" :name="'operations[' + i + '][run_minutes_per_unit]'" x-model="op.run_minutes_per_unit"
                               class="input" placeholder="Run min/unit">
                        <button type="button" x-on:click="removeRow(i)" class="btn-ghost btn-icon btn-sm self-center text-rose-500 dark:text-rose-400" title="Remove">
                            <x-icon name="trash" class="size-4" />
                        </button>
                    </div>

                    <input type="hidden" :name="'operations[' + i + '][id]'" x-model="op.id">
                    <input type="hidden" :name="'operations[' + i + '][sequence]'" x-model="op.sequence">

                    <input type="text" :name="'operations[' + i + '][notes]'" x-model="op.notes"
                           class="input mt-2" placeholder="Notes (optional)">
                </div>
            </template>
        </div>
    @endif

    @error('operations')
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
    @enderror
    @error('operations.*')
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
    @enderror
</div>
