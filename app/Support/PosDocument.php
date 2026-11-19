<?php

namespace App\Support;

/**
 * Builds the shared document payload for a POS receipt printed in A4.
 *
 * The till receipt stays thermal -- that is the paper the drawer holds. This is
 * the filing copy: the same sale rendered through the invoice-style frame, so a
 * business printing a receipt next to its invoices gets one design, one set of
 * colours and one operator-controlled template across both.
 */
class PosDocument
{
    /**
     * @param  \App\Models\PosSale  $sale
     * @return array<string,mixed>
     */
    public static function payload($sale): array
    {
        $currency = $sale->currency ?: settings('company.currency') ?: 'USD';

        $rows = $sale->items->map(fn ($item) => [
            'description' => $item->item_name,
            'qty' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount' => 0.0,
            'tax' => 0.0,
            'total' => (float) $item->line_total,
        ])->all();

        $totals = [
            ['label' => 'Subtotal', 'value' => (float) $sale->subtotal, 'total' => false],
        ];

        if ((float) $sale->discount > 0) {
            $totals[] = ['label' => 'Discount', 'value' => -(float) $sale->discount, 'total' => false];
        }

        if ((float) $sale->tax > 0) {
            $totals[] = ['label' => 'Tax', 'value' => (float) $sale->tax, 'total' => false];
        }

        $totals[] = ['label' => 'Total '.$currency, 'value' => (float) $sale->total, 'total' => true];
        $totals[] = ['label' => 'Amount paid', 'value' => (float) $sale->amount_paid, 'total' => false];

        if ((float) $sale->change_due > 0) {
            $totals[] = ['label' => 'Change due', 'value' => (float) $sale->change_due, 'total' => false];
        }

        return DocumentData::make('pos_receipt', [
            'doc' => $sale,
            'title' => 'Sales Receipt',
            'number' => $sale->receipt_number,
            'currency' => $currency,
            'dateLabel' => 'Date',
            'dateValue' => $sale->sold_at?->format('d/m/Y H:i'),
            'headlineLabel' => 'Total '. $currency,
            'headlineValue' => money((float) $sale->total, $currency),
            'billTo' => [
                'heading' => 'Customer',
                'name' => $sale->customer_name ?: 'Walk-in',
                'contact' => null,
                'address' => null,
                'tax' => null,
                'code' => null,
            ],
            'meta' => [
                ['label' => 'Served by', 'value' => $sale->shift?->opener?->name ?: 'Cashier', 'show' => true],
                ['label' => 'Payment', 'value' => $sale->paymentMethod?->name ?: 'Cash', 'show' => true],
                ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', (string) $sale->status)), 'show' => true],
            ],
            'columns' => [
                ['label' => '#', 'align' => 'center'],
                ['label' => 'Description', 'align' => 'left'],
                ['label' => 'Qty', 'align' => 'right'],
                ['label' => 'Unit price', 'align' => 'right'],
                ['label' => 'Disc.', 'align' => 'right'],
                ['label' => 'Tax', 'align' => 'right'],
                ['label' => 'Amount', 'align' => 'right'],
            ],
            'rows' => $rows,
            'totals' => $totals,
            'notes' => $sale->notes,
            'hasPricing' => true,
            'blockReady' => [
                'bank' => false,
                'terms' => false,
                'signature' => false,
                'receipt' => false,
                'table' => false,
            ],
        ]);
    }
}
