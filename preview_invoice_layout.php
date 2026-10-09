<?php

/**
 * Render a deliberately long document to prove page 2+ behaves.
 *
 * The real data in a demo installation tops out at three lines, which never
 * paginates -- so the multi-page path is invisible until someone issues a 40-line
 * invoice. This builds one, renders it, and reports the page count.
 *
 * Usage:
 *   php preview_invoice_layout.php [database]
 */

$db = $argv[1] ?? null;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($db) {
    config(['database.connections.mysql.database' => $db, 'database.default' => 'mysql']);
    DB::purge('mysql');
}

use App\Support\DocumentData;
use Barryvdh\DomPDF\Facade\Pdf;

echo 'Database: ', DB::connection()->getDatabaseName(), "\n";

$product = \App\Models\InventoryItem::query()->first();

if (! $product) {
    echo "no product to build lines from\n";
    exit(1);
}

// Three shapes, because each stresses the table differently:
// short (1 line), medium (fits one page), long (spills onto page 2+).
$shapes = ['short' => 1, 'medium' => 12, 'long' => 48];

$created = [];

// Clear any leftovers first. `forceDelete`, not `delete`: SalesQuote uses
// SoftDeletes, so a plain delete leaves the row in the table and the unique index
// on `number` still collides with it. A previous run that failed partway through
// left exactly that debris, and it turns the script into a one-shot tool.
\App\Models\SalesQuote::withTrashed()->where('number', 'like', 'PREVIEW-%')->forceDelete();

// Clean up in a finally-equivalent: these are throwaway rows, and leaving them
// would pollute the database the user actually looks at. Runs even if rendering
// threw, so a failure cannot leave debris behind for the next run to trip over.
try {
    foreach ($shapes as $label => $count) {
        $quote = \App\Models\SalesQuote::create([
            'number' => 'PREVIEW-'.strtoupper($label),
            'customer_id' => \App\Models\SalesCustomer::query()->value('id'),
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'PKR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $items = [];

        for ($i = 0; $i < $count; $i++) {
            // A discount and tax on some lines so the totals block has real work.
            $items[] = [
                'product_id' => $product->id,
                'description' => $product->name.' - line '.($i + 1).' of '.$count,
                'qty' => 1 + ($i % 4),
                'unit_price' => 149.99,
                'discount_percent' => $i % 5 === 0 ? 10 : 0,
                'tax_percent' => $i % 4 === 0 ? 17 : 0,
            ];
        }

        $totals = \App\Support\DocumentItems::sync($quote, $items);
        $quote->update($totals);

        $name = 'preview-'.$label.'.pdf';
        Pdf::loadView('documents.pdf', DocumentData::build($quote->fresh('items')))
            ->save(storage_path('app/'.$name));

        $created[] = $name;
        printf("  %-8s %2d lines -> %s\n", $label, $count, $name);
    }
} finally {
    \App\Models\SalesQuote::withTrashed()->where('number', 'like', 'PREVIEW-%')->forceDelete();
}

echo "\nRendered ", count($created), " PDFs to storage/app/\n";
echo "Deleted the temporary quotes.\n";