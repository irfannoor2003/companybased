<?php

/**
 * Generate every document type as a PDF for visual inspection.
 *
 * Writes to storage/app/preview-doc-*.pdf. Uses whatever documents already exist
 * rather than fabricating rows, so the output reflects real data -- long
 * descriptions, many lines, real totals -- which is where layout actually breaks.
 *
 * Usage:
 *   php preview_document_pdfs.php                 # against the normal database
 *   php preview_document_pdfs.php companybased_rehearsal
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
use App\Support\ReceiptDocument;
use Barryvdh\DomPDF\Facade\Pdf;

echo 'Database: ', DB::connection()->getDatabaseName(), "\n";

$storage = storage_path('app');
if (! is_dir($storage)) {
    mkdir($storage, 0777, true);
}

/**
 * Each entry the shared DocumentData::TYPES registry supports, with the model that
 * produces the longest sample we can find. Preferring a document with the most
 * lines is deliberate: a one-line document proves nothing about table layout.
 */
$models = [
    \App\Models\SalesQuote::class,
    \App\Models\SalesOrder::class,
    \App\Models\SalesInvoice::class,
    \App\Models\SalesCreditNote::class,
    \App\Models\SalesDeliveryNote::class,
    \App\Models\SalesRecurringInvoice::class,
    \App\Models\PurchaseQuote::class,
    \App\Models\PurchaseOrder::class,
    \App\Models\PurchaseInvoice::class,
    \App\Models\DebitNote::class,
];

$written = 0;

foreach ($models as $class) {
    // SalesInvoice is deliberately NOT in DocumentData::TYPES -- it renders from its
    // own view via InvoiceTemplate::view(), which resolves every stored template
    // name to the same shared design. Adding it here would give it a second,
    // divergent PDF path, which is the exact mistake the registry comment warns of.
    if ($class !== \App\Models\SalesInvoice::class && ! (DocumentData::TYPES[$class] ?? false)) {
        continue;
    }

    // The document with the most lines renders the table best.
    $document = $class::query()
        ->with('items')
        ->get()
        ->sortByDesc(fn ($d) => $d->items->count())
        ->first();

    if (! $document) {
        printf("  %-28s no document found, skipped\n", class_basename($class));

        continue;
    }

    try {
        if ($class === \App\Models\SalesInvoice::class) {
            // Mirrors InvoiceController::pdf() exactly: load the same relations,
            // resolve the template to its view, render then loadHTML.
            $document->load(['customer', 'items.product', 'payments', 'creditNotes']);
            $html = view(
                \App\Support\InvoiceTemplate::view((string) $document->template),
                compact('document') + ['invoice' => $document],
            )->render();
            $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        } else {
            $pdf = Pdf::loadView('documents.pdf', DocumentData::build($document));
        }

        $name = 'preview-doc-'.strtolower(class_basename($class)).'.pdf';
        $pdf->save(storage_path('app/'.$name));
        printf("  %-28s %d lines -> %s (%d bytes)\n", class_basename($class), $document->items->count(), $name, filesize(storage_path('app/'.$name)));
        $written++;
    } catch (Throwable $e) {
        printf("  %-28s FAILED: %s\n", class_basename($class), $e->getMessage());
    }
}

// Receipts render through their own support class, not DocumentData.
foreach ([
    'sales-payment' => fn () => \App\Models\SalesPayment::query()->first(),
    'supplier-payment' => fn () => \App\Models\SupplierPayment::query()->first(),
] as $label => $resolve) {
    $record = $resolve();

    if (! $record) {
        continue;
    }

    try {
        $html = str_contains($label, 'supplier')
            ? ReceiptDocument::supplierPayment($record)->render()
            : ReceiptDocument::salesPayment($record)->render();
        $name = 'preview-receipt-'.$label.'.pdf';
        Pdf::loadHTML($html)->save(storage_path('app/'.$name));
        printf("  %-28s -> %s (%d bytes)\n", 'receipt-'.$label, $name, filesize(storage_path('app/'.$name)));
        $written++;
    } catch (Throwable $e) {
        printf("  %-28s FAILED: %s\n", 'receipt-'.$label, $e->getMessage());
    }
}

echo "\n$written PDFs written to storage/app/\n";