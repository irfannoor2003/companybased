{{--
    The design half of a document template: which STYLE it prints in, and which
    BLOCKS, in which order.

    Shared by create and edit so the two screens cannot drift apart -- the same reason
    `TemplateController::formData()` hands both views the same `$styles` and `$blocks`.

    BLOCKS, NOT PIXELS
    -----------------
    Each block is a tested, known-good piece of a document. They can be shown, hidden
    and reordered freely -- now by DRAG as well as by the arrows. What the operator
    cannot do is drop a block at an arbitrary pixel coordinate: DomPDF paginates in
    flow, cannot repeat a table row across a page break, cannot count total pages and
    cannot render `@page` margin boxes at `margin: 0`. Free positioning over that
    engine does not produce an unusual layout, it produces a document that breaks on
    page two and is discovered by the customer. So dragging reorders the BLOCK LIST;
    the flow inside a block is not something an operator can break.

    The drag core is native HTML5 drag-and-drop, copied from the visual builder in the
    sister inventory project. It needs no library: a row is `draggable`, `dragover`
    marks the drop target, and `drop` splices the block into the new position.

    EVERY BLOCK IS LISTED, PRINTED OR NOT
    -------------------------------------
    The list is the full set of blocks rather than only the ones currently printed,
    because a control that can only remove things cannot add them back: untick the
    bank details by accident and the only route to them again is a template you did
    not mean to touch.

    `all` is every block in the template's saved order, with any block that was never
    stored appended, so all ten are on screen from the first render -- before Alpine
    boots, which matters because these rows are server-rendered and a screen that
    fills in only after hydration looks broken for a moment on every page load.

    `enabled` holds ONLY the printed blocks, and it is what gets posted as
    `blocks[]`, in order. Both the arrows and a drag move blocks in `all` then rebuild `enabled`
    from it, which is why a hidden block sitting between two printed ones cannot
    shift either of them along when it is moved. The DOM row is moved too, so the
    order of the `name="blocks[]"` inputs -- what the browser actually submits --
    always matches the order shown on screen.

    The sections render as checkboxes: every block, including the letterhead and
    the line items, is the operator's to keep or drop, so the on-screen sentence
    is "tick to print" without exceptions. `PdfLayout::normaliseBlocks()` still
    cleans what is posted: duplicates collapse, unknown keys are dropped, and a
    kept letterhead is forced to print first.
--}}

@php
    $currentStyle = old('style', $template?->style ?? \App\Support\PdfLayout::STYLE_RAIL);
    $currentBlocks = array_values(old('blocks', $template?->layout['blocks'] ?? $defaultOrder));
    /**
     * The eleven blocks, in the order they will print, labelled and flagged removable.
     *
     * Rendered on the server rather than assembled in the browser so the form is
     * correct in the HTML, before any JavaScript runs.
     */
    $orderedBlocks = [];

    foreach ($currentBlocks as $key) {
        if (isset($blocks[$key]) && ! array_key_exists($key, $orderedBlocks)) {
            $orderedBlocks[$key] = true;
        }
    }

    foreach (array_keys($blocks) as $key) {
        $orderedBlocks[$key] = $orderedBlocks[$key] ?? false;
    }

    $printedBlocks = array_keys(array_filter($orderedBlocks));

    $blockState = [
        'all' => array_keys($orderedBlocks),
        'enabled' => $printedBlocks,
        'labels' => collect($blocks)->map(fn ($definition) => $definition['label'])->all(),
        'removable' => collect($blocks)->map(fn ($definition) => $definition['removable'] === true)->all(),
    ];
@endphp

<div class="space-y-6">
    <div>
        <p class="mb-1 text-sm font-medium text-ink">Design</p>
        <p class="mb-3 text-xs text-ink-soft">
            Every design prints through the same tested page flow, so none of them can
            break across page two. Only the frame and the table treatment change.
        </p>

        <div class="space-y-2">
            @foreach ($styles as $value => $label)
                <label class="flex cursor-pointer gap-3 rounded-xl border p-3 transition
                              {{ $currentStyle === $value ? 'border-primary bg-primary/5' : 'border-line hover:bg-surface-muted' }}">
                    <input type="radio" name="style" value="{{ $value }}" class="mt-1"
                           @checked($currentStyle === $value)>
                    <span>
                        <span class="block text-sm font-medium text-ink">{{ $label }}</span>
                        <span class="mt-0.5 block text-xs text-ink-faint">{{ \App\Support\PdfLayout::STYLE_HELP[$value] ?? '' }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>

    <div>
        <p class="mb-1 text-sm font-medium text-ink">Sections</p>
        <p class="mb-3 text-xs text-ink-soft">
            Tick to print a section, untick to drop it, drag the handle (or use the
            arrows) to set the order. Every section is yours to remove -- including
            the letterhead, the document number and the line items -- so the preview
            below is the page you get.
        </p>

        {{-- One scope for the whole list. Alpine resolves an expression up the scope
             chain, so each row below calls `toggle`, `move` and friends directly.

             The PHP state is emitted with `@js()` and the behaviour is written as
             plain JavaScript beside it. Nothing else: a Blade directive wrapping a
             JS object literal compiles to a parse error at render time, which is how
             this screen spent an afternoon not working.

             `on()` and `position()` are functions rather than derived state so there
             is one definition of "is this printed" -- the row's faded appearance, its
             checkbox and the hidden input that actually posts the block all read it. --}}
        {{--
            The component used to live inside a single-quoted x-data attribute,
            but its own JavaScript strings ('text/plain', 'opacity-40', ...) closed
            that attribute early and the rest printed as text on the page. Register
            it with Alpine.data in a real script block: no attribute quoting at all.
        --}}
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('blockBuilder', () => ({
            state: @js($blockState),

            // The block currently being dragged, or null. Native HTML5 drag state
            // lives outside Alpine, so it has to be mirrored here explicitly.
            dragging: null,

            on(block) {
                return this.state.enabled.indexOf(block) !== -1;
            },

            position(block) {
                return this.state.enabled.indexOf(block) + 1;
            },

            toggle(block) {
                if (! this.state.removable[block]) {
                    return;
                }

                const at = this.state.enabled.indexOf(block);

                if (at === -1) {
                    this.state.enabled.push(block);
                } else {
                    this.state.enabled.splice(at, 1);
                }
            },

            move(block, step) {
                const at = this.state.all.indexOf(block);
                const to = at + step;

                if (at === -1 || to < 0 || to >= this.state.all.length) {
                    return;
                }

                // Reuse the drag reorder path so the arrows and a drag cannot
                // disagree about the resulting order. Step down => insert after.
                this.reorder(block, this.state.all[to], step > 0);
            },

            /**
             * The one place that changes order. `all` and `enabled` are rebuilt
             * from the new sequence, and the DOM row actually moves -- which is
             * what makes the browser submit the new order, because the hidden
             * `blocks[]` inputs travel with their rows.
             */
            reorder(source, target, after) {
                if (! source || ! target || source === target) {
                    return;
                }

                const from = this.state.all.indexOf(source);

                if (from === -1) {
                    return;
                }

                const next = this.state.all.slice();
                next.splice(from, 1);

                let to = next.indexOf(target);

                if (to === -1) {
                    return;
                }

                if (after) {
                    to += 1;
                }

                next.splice(to, 0, source);
                this.state.all = next;

                // Rebuild from the new order rather than swapping indices, so a hidden
                // block moving past a visible one cannot shift the visible one.
                this.state.enabled = next.filter((b) => this.state.enabled.indexOf(b) !== -1);

                this.placeRow(source, target, after);
            },

            rowFor(block) {
                return Array.prototype.slice
                    .call(this.$root.querySelectorAll('[data-block-row]'))
                    .find((row) => row.dataset.blockRow === block);
            },

            placeRow(source, target, after) {
                const sourceRow = this.rowFor(source);
                const targetRow = this.rowFor(target);

                if (! sourceRow || ! targetRow || sourceRow === targetRow) {
                    return;
                }

                if (after) {
                    targetRow.parentNode.insertBefore(sourceRow, targetRow.nextSibling);
                } else {
                    targetRow.parentNode.insertBefore(sourceRow, targetRow);
                }
            },

            dragStart(block, event) {
                // A drag that starts on a control is a mis-click, not a reorder.
                if (event.target.closest('input, button, label, a, select')) {
                    event.preventDefault();
                    return;
                }

                this.dragging = block;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', block);
                event.currentTarget.classList.add('opacity-40', 'ring-2', 'ring-primary/30');
            },

            dragOver(block, event) {
                if (! this.dragging || this.dragging === block) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                event.currentTarget.classList.add('is-drop-target');
            },

            dragLeave(event) {
                if (event.currentTarget.contains(event.relatedTarget)) {
                    return;
                }

                event.currentTarget.classList.remove('is-drop-target');
            },

            drop(block, event) {
                event.preventDefault();
                event.currentTarget.classList.remove('is-drop-target');

                if (! this.dragging || this.dragging === block) {
                    return;
                }

                const box = event.currentTarget.getBoundingClientRect();
                const after = event.clientY > box.top + box.height / 2;

                this.reorder(this.dragging, block, after);
                this.dragging = null;
            },

            dragEnd(event) {
                event.currentTarget.classList.remove('opacity-40', 'ring-2', 'ring-primary/30');
                this.$root.querySelectorAll('.is-drop-target').forEach((row) => {
                    row.classList.remove('is-drop-target');
                });
                this.dragging = null;
            },

            up(block) {
                this.move(block, -1);
            },

            down(block) {
                this.move(block, 1);
            },

            first(block) {
                return this.state.all.indexOf(block) === 0;
            },

            last(block) {
                return this.state.all.indexOf(block) === this.state.all.length - 1;
            }
                }));
            });
        </script>

        <div class="space-y-1" x-data="blockBuilder()">

            @foreach (array_keys($orderedBlocks) as $block)
                @php($on = (bool) $orderedBlocks[$block])
                {{--
                    The faded state is rendered on the server AND bound for Alpine.

                    Server-rendered alone is wrong after hydration: a boolean literal
                    in `x-bind:class` short-circuits the expression, so a row that
                    arrived hidden could never un-fade when the box was ticked.
                    Alpine-only is right once booted but leaves every hidden row
                    briefly at full strength before the script runs. Doing both means
                    the correct state is on screen from the first byte and Alpine owns
                    it from then on.
                --}}
                <div @class([
                        'flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2',
                        'opacity-50' => ! $on,
                    ])
                     x-bind:class="on('{{ $block }}') ? '' : 'opacity-50'"
                     draggable="true"
                     data-block-row="{{ $block }}"
                     x-on:dragstart="dragStart('{{ $block }}', $event)"
                     x-on:dragover="dragOver('{{ $block }}', $event)"
                     x-on:dragleave="dragLeave($event)"
                     x-on:drop="drop('{{ $block }}', $event)"
                     x-on:dragend="dragEnd($event)">

                    {{-- One hidden input per listed block, named `blocks[]`.

                         A hidden input posts whether or not it is VISIBLE, not whether
                         it is ticked: `display: none` takes it off the screen but not
                         out of the form data, which is why the old `x-show` gate made
                         every template save the full block list no matter what was
                         unticked. A disabled control is the one state the browser
                         itself excludes from submission -- so "off" is expressed as
                         `disabled`, server-rendered for the pre-Alpine render and
                         bound for everything after. --}}
                    <input type="hidden" name="blocks[]" value="{{ $block }}"
                           @disabled(! $on)
                           x-bind:disabled="! on('{{ $block }}')">

                    {{-- The handle is the affordance: it says "this row can be
                         dragged" without making the checkbox look draggable. The
                         whole row is draggable, but dragStart() refuses drags that
                         begin on a control, so ticking a box never moves a row. --}}
                    <span class="shrink-0 select-none text-ink-faint cursor-grab active:cursor-grabbing"
                          title="Drag to reorder" aria-hidden="true">⠿</span>

                    <label class="flex flex-1 cursor-pointer items-center gap-2">
                        <input type="checkbox" class="rounded border-line text-primary-ink focus:ring-primary"
                               @checked($on)
                               @disabled(! \App\Support\PdfLayout::isRemovable($block))
                               x-on:change="toggle('{{ $block }}')">
                        <span class="text-sm text-ink">{{ $blocks[$block]['label'] }}</span>
                    </label>

                    {{-- Position within the printed sequence, so a block that prints
                         nowhere does not read as "section 5 of 7". --}}
                    <span class="w-9 shrink-0 text-right text-xs tabular-nums text-ink-faint"
                          x-text="on('{{ $block }}') ? String(position('{{ $block }}')).padStart(2, '0') : '—'">{{ $on ? sprintf('%02d', array_search($block, $printedBlocks, true) + 1) : '—' }}</span>

                    <button type="button" title="Move up"
                            class="btn-ghost btn-icon btn-sm"
                            @disabled($block === array_key_first($orderedBlocks))
                            x-on:click="up('{{ $block }}')"
                            x-bind:disabled="first('{{ $block }}')">
                        <x-icon name="chevron-up" class="size-4" />
                    </button>
                    <button type="button" title="Move down"
                            class="btn-ghost btn-icon btn-sm"
                            @disabled($block === array_key_last($orderedBlocks))
                            x-on:click="down('{{ $block }}')"
                            x-bind:disabled="last('{{ $block }}')">
                        <x-icon name="chevron-down" class="size-4" />
                    </button>
                </div>
            @endforeach
        </div>

        @error('style')
            <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
        @enderror

        @error('blocks')
            <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
        @enderror
    </div>
</div>
