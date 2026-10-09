<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grandfather the tax rates existing documents already charge.
 *
 * The tax engine refuses to save a document line that charges tax with no
 * configured rate behind it. That control is correct on its merits -- posting 17%
 * to the ledger with nothing defining which rate it was makes the return
 * irreproducible -- but it arrived with an empty `tax_rates` table, so any
 * deployment that had been trading under the old hand-typed `tax_percent` was
 * locked out of saving its own invoices the moment the engine shipped. Editing a
 * customer to correct their address would fail with a tax error about a
 * percentage the customer had been charged for a year.
 *
 * There are two ways out and only one of them is worth taking:
 *
 *  1. Loosen the engine to allow unconfigured tax. This restores saving and
 *     destroys the control, putting the problem straight back on the return.
 *
 *  2. Adopt the rates the business is demonstrably already charging, dated from
 *     the earliest document that used them. The control still holds -- there is
 *     now a defined rate behind every historic figure -- and nothing has to change
 *     on a document that was already correct.
 *
 * This does (2).
 *
 * Deliberate details worth stating, because each one is a decision:
 *
 *  - Only NON-ZERO percentages are adopted. A zero percentage means "no tax on
 *    this line", which the engine already accepts with no rate configured, so
 *    adopting it would create a catalogue entry that changes nothing while
 *    implying a rate was in force when none was.
 *
 *  - Rates are dated from the EARLIEST document that charged them, not from
 *    today. Dated from today, every document already issued would still resolve
 *    to "no rate in force", because the rate would not have started until after
 *    it was written. That defeats the entire point.
 *
 *  - One rate per distinct percentage, not per document. A business that charged
 *    17% on four thousand invoices has one rate, not four thousand.
 *
 *  - The rate is marked with an "Adopted" name and a note. The business's real
 *    name, code, priority and threshold regime are things only the owner knows;
 *    guessing them would put invented authority into a return. The row is a
 *    factual record of what was charged, and the owner renames it.
 *
 *  - `account_id` is left NULL rather than guessed. It points at the ledger
 *    account the tax posts to, and guessing that wrong would misstate the
 *    balance sheet. An owner sets it when they confirm the rate.
 *
 *  - If the table already has rates, nothing is adopted. A deployment that has
 *    configured its own catalogue knows its own rates, and backfilling over them
 *    would invent history.
 */
return new class extends Migration
{
    /**
     * Item table => [parent table, date column, applies_to].
     *
     * Kept as an explicit list rather than derived, because the parent key and
     * date column differ per table (`order_id`/`order_date` on purchase orders,
     * `invoice_id`/`issue_date` on purchase invoices) and a wrong guess here
     * would join against nothing and silently adopt nothing.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SOURCES = [
        'sales_quote_items' => ['sales_quotes', 'issue_date', 'sales'],
        'sales_order_items' => ['sales_orders', 'issue_date', 'sales'],
        'sales_invoice_items' => ['sales_invoices', 'issue_date', 'sales'],
        'sales_credit_note_items' => ['sales_credit_notes', 'issue_date', 'sales'],
        'sales_recurring_invoice_items' => ['sales_recurring_invoices', 'next_run_date', 'sales'],
        'purchase_quote_items' => ['purchase_quotes', 'issue_date', 'purchase'],
        'purchase_order_items' => ['purchase_orders', 'order_date', 'purchase'],
        'purchase_invoice_items' => ['purchase_invoices', 'issue_date', 'purchase'],
        'debit_note_items' => ['debit_notes', 'issue_date', 'purchase'],
    ];

    public function up(): void
    {
        // An operator who has configured a catalogue knows their own rates.
        if (DB::table('tax_rates')->exists()) {
            return;
        }

        // percent => ['sales'|'purchase' => earliest date]
        $observed = $this->observedPercents();

        if ($observed === []) {
            return;
        }

        $now = now();

        foreach ($observed as $percent => $scopes) {
            foreach ($scopes as $appliesTo => $firstSeen) {
                $code = 'ADOPTED-'.strtoupper($appliesTo === 'purchase' ? 'PUR' : 'SAL').'-'
                    .str_replace('.', '-', number_format($percent, 2, '.', ''));

                // Guard against a collision with a hand-created row, and keep
                // adoption idempotent if this ever runs twice.
                if (DB::table('tax_rates')->where('code', $code)->exists()) {
                    continue;
                }

                DB::table('tax_rates')->insert([
                    'code' => $code,
                    'name' => 'Adopted '.$appliesTo.' rate '.$percent.'%',
                    'rate' => number_format($percent, 4, '.', ''),

                    // A sales-only or purchase-only rate, not "both": the same
                    // percentage charged on a purchase is reclaimable input tax
                    // and lands in a different account, and merging the two would
                    // make the rate wrong for whichever side it did not describe.
                    'applies_to' => $appliesTo,

                    // Intentionally NULL. See the class docblock.
                    'account_id' => null,

                    // Earliest document that charged it, so history still resolves.
                    'effective_from' => $firstSeen,
                    'effective_to' => null,
                    'priority' => 0,
                    'is_compound' => false,
                    'threshold_amount' => null,
                    'is_exempt' => false,
                    'is_active' => true,
                    'currency' => null,
                    'notes' => 'Adopted automatically when the tax rate engine was installed, from the '
                        .'percentage existing documents were already charging on or before '
                        .$firstSeen.'. Confirm the name, the ledger account and any threshold '
                        .'regime, then rename it.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Every distinct non-zero tax percentage already present on saved lines, with
     * the earliest document date it was seen on, keyed by percentage and scope.
     *
     * @return array<string, array<string, string>>
     */
    private function observedPercents(): array
    {
        $observed = [];

        foreach (self::SOURCES as $items => [$parent, $dateColumn, $appliesTo]) {
            if (! Schema::hasTable($items) || ! Schema::hasTable($parent)) {
                continue;
            }

            $rows = DB::table($items)
                ->join($parent, $parent.'.id', '=', $items.'.'.$this->foreignKey($items))
                ->where($items.'.tax_percent', '>', 0)
                ->groupBy($items.'.tax_percent')
                ->selectRaw($items.'.tax_percent AS percent, MIN('.$parent.'.'.$dateColumn.') AS first_seen')
                ->get();

            foreach ($rows as $row) {
                // tax_percent is decimal(5,2); normalised so 17, 17.00 and 17.0000
                // collapse to one rate instead of three near-identical rows.
                $percent = number_format((float) $row->percent, 2, '.', '');

                $current = $observed[$percent][$appliesTo] ?? null;

                if ($current === null || $row->first_seen < $current) {
                    $observed[$percent][$appliesTo] = (string) $row->first_seen;
                }
            }
        }

        return $observed;
    }

    /**
     * The item table's foreign key back to its parent document.
     */
    private function foreignKey(string $itemsTable): string
    {
        return match ($itemsTable) {
            'sales_quote_items' => 'quote_id',
            'sales_order_items' => 'order_id',
            'sales_invoice_items' => 'invoice_id',
            'sales_credit_note_items' => 'credit_note_id',
            'sales_recurring_invoice_items' => 'recurring_invoice_id',
            'purchase_quote_items' => 'quote_id',
            'purchase_order_items' => 'order_id',
            'purchase_invoice_items' => 'invoice_id',
            'debit_note_items' => 'debit_note_id',
        };
    }

    public function down(): void
    {
        // Only the adopted rows, never a rate the operator created or edited.
        //
        // The name is the discriminator rather than the code prefix, because an
        // operator who has since renamed an adopted rate must still have that
        // rename reverted along with the row.
        DB::table('tax_rates')
            ->where('code', 'like', 'ADOPTED-%')
            ->orWhere('name', 'like', 'Adopted %')
            ->delete();
    }
};