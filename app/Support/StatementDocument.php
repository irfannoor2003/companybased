<?php

namespace App\Support;

/**
 * Builds the shared document payload for an account statement or supplier ledger.
 *
 * A statement used to print its own HTML with a copy of the invoice frame. It is
 * now just another document shape: letterhead, title, meta, a table of ledger
 * rows and a totals block, all rendered by `documents/pdf.blade.php`. This class
 * turns the ledger rows the controllers already build into that shape so both
 * statements -- customer and supplier -- stay identical by construction.
 */
class StatementDocument
{
    /**
     * @param  iterable<int,array<string,mixed>>  $ledger
     * @param  array<string,float>  $totals
     * @param  array<string,mixed>  $billTo
     * @return array<string,mixed>
     */
    public static function payload(
        string $type,
        string $title,
        string $number,
        array $billTo,
        iterable $ledger,
        array $totals,
        ?string $currency,
    ): array {
        // A customer or supplier with no currency set is common on an older
        // database. Fall back to the company currency rather than 500ing the
        // statement PDF, which is what a strict string parameter did.
        $currency = $currency ?: (settings('company.currency') ?: 'USD');
        $rows = [];

        foreach ($ledger as $entry) {
            $rows[] = [
                $entry['date']?->format('Y-m-d') ?: '-',
                $entry['type'],
                $entry['reference'],
                $entry['debit'] !== null ? money((float) $entry['debit'], $currency) : '',
                $entry['credit'] !== null ? money((float) $entry['credit'], $currency) : '',
                money((float) $entry['balance'], $currency),
            ];
        }

        return DocumentData::make($type, [
            'title' => $title,
            'number' => $number,
            'currency' => $currency,
            'billTo' => $billTo,
            'dateLabel' => 'Statement date',
            'dateValue' => now()->format('d/m/Y'),
            'headlineLabel' => 'Closing balance',
            'headlineValue' => money((float) $totals['closing'], $currency),
            'meta' => [
                ['label' => 'Coverage', 'value' => 'All account activity to date', 'show' => true],
                ['label' => 'Account currency', 'value' => $currency, 'show' => true],
                ['label' => 'Opening balance', 'value' => money((float) $totals['opening'], $currency), 'show' => true],
            ],
            'totals' => [
                ['label' => 'Total debited', 'value' => (float) $totals['debited'], 'total' => false],
                ['label' => 'Total credited', 'value' => (float) $totals['credited'], 'total' => false],
                ['label' => 'Closing balance', 'value' => (float) $totals['closing'], 'total' => true],
            ],
            'table' => [
                'headers' => ['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'],
                'aligns' => ['left', 'left', 'left', 'right', 'right', 'right'],
                'widths' => ['11%', '13%', '33%', '14%', '14%', '15%'],
                'rows' => $rows,
                'empty' => 'No account activity on record.',
            ],
            'hasPricing' => true,
            'blockReady' => [
                'items' => false,
                'receipt' => false,
                'bank' => false,
                'terms' => false,
                'signature' => false,
            ],
        ]);
    }
}
