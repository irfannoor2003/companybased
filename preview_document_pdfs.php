<?php

/**
 * Render document PDFs from the database, so they can be looked at.
 *
 * WHY THIS EXISTS
 *
 * Every test in this suite asserts that a PDF is produced and that its bytes contain
 * the right words. None of them can assert that the page looks right, and that gap
 * is not theoretical: rendering to PNG is what caught the rail painting over the
 * first character of every heading, and the totals block running off the right edge
 * so "180.00" printed as "180.0". Both passed every test in the suite.
 *
 * This is that check. Combined with `render_invoice_pdf.py` it turns the renderer
 * into something a human can actually see.
 *
 * WHY STYLES ARE THE DEFAULT SUBJECT
 *
 * `document_templates` carries five designs, wired into the renderer, and until this
 * pass nobody had rendered one. A style that has never been looked at is a style
 * that will print "UOTE" for "QUOTE" and nobody will know until a customer does. So
 * the default output is the SAME document in every style, which is the only way the
 * differences between the designs can be judged at a glance. Five documents five
 * times shows you five documents five times.
 *
 * USAGE
 *
 *   php preview_document_pdfs.php                             every style
 *   php preview_document_pdfs.php companybased_rehearsal      ...another schema
 *   php preview_document_pdfs.php --style=classic             one style
 *   php preview_document_pdfs.php --template=purchase_order   one document type
 *
 * Pass a schema name to render from a copy rather than your working data -- this
 * script creates and deletes rows.
 *
 * OUTPUT
 *
 * storage/app/preview-*.pdf, plus a _p1.png / _p2.png per page from the Python
 * companion.
 *
 * Written as UTF-8 WITHOUT a BOM. `Set-Content -Encoding UTF8` in Windows
 * PowerShell 5.1 writes a BOM, and PHP then rejects the file with "Namespace
 * declaration statement has to be the very first statement", which points at line 3
 * and reads as a syntax error in this code rather than an encoding problem.
 */

use App\Models\DocumentTemplate;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesQuote;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\PdfLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$root = __DIR__;

require $root.'/vendor/autoload.php';

$options = ['schema' => null, 'style' => null, 'template' => 'quote', 'lines' => 6, 'model' => 'quote'];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--style=')) {
        $options['style'] = substr($argument, 8);
    } elseif (str_starts_with($argument, '--template=')) {
        // "--template=" is 11 characters. substr() takes a LENGTH, so the 12 that was
        // here silently dropped the first letter of the type and reported "no
        // template row for type 'nvoice'" -- which reads like a missing seed rather
        // than like a typo in the flag.
        $options['template'] = substr($argument, 11);
    } elseif (str_starts_with($argument, '--model=')) {
        $options['model'] = substr($argument, 8);
    } elseif (str_starts_with($argument, '--lines=')) {
        $options['lines'] = (int) substr($argument, 8);
    } elseif (! str_starts_with($argument, '--')) {
        $options['schema'] = $argument;
    }
}

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($options['schema'] !== null) {
    config(['database.connections.mysql.database' => $options['schema']]);
    DB::purge('mysql');
}

$styles = $options['style'] !== null
    ? [$options['style'] => PdfLayout::STYLES[$options['style']] ?? $options['style']]
    : PdfLayout::STYLES;

$customer = SalesCustomer::orderBy('id')->first() ?? SalesCustomer::create([
    'company_name' => 'Preview Customer Ltd',
    'contact_name' => 'Preview Contact',
    'email' => 'preview@example.test',
    'address' => '1 Preview Street',
    'city' => 'Karachi',
    'country' => 'Pakistan',
]);

/*
 * The document to render.
 *
 * An existing one by default, so the script previews real data on a working
 * database. `--lines=N` forces a generated one instead, which is how the
 * multi-page path gets exercised: neither defect found by rendering -- the rail
 * clipping the canvas, and the totals running off the right edge -- appeared in a
 * three-line document, and the demo data is three lines.
 *
 * `--model=invoice` is the exception that proves the rule: the invoice is not built
 * from lines the way a quote is, so it needs its own builder rather than a
 * `SalesQuote` pretending to be one.
 */
$quote = $options['model'] === 'invoice'
    ? preview_invoice($customer)
    : ($options['lines'] !== null
        ? preview_quote($customer, $options['lines'])
        : (SalesQuote::with('items')->where('status', '!=', 'draft')->orderByDesc('id')->first()
            ?? preview_quote($customer, 6)));

echo 'Schema:   '.config('database.connections.mysql.database').PHP_EOL;
echo 'Document: '.$quote->number.' ('.count($quote->items).' lines)'.PHP_EOL.PHP_EOL;

$written = [];

foreach ($styles as $style => $label) {
    $template = DocumentTemplate::ofType($options['template'])
        ->where('style', $style)
        ->orderByDesc('is_default')
        ->orderBy('id')
        ->first();

    /**
     * Whatever row we end up with, it has to be the DEFAULT one for the render.
     *
     * `PdfLayout::forType()` only ever reads the default template for a type, so
     * pointing the renderer at a non-default row prints the default's design and
     * quietly reports the size of the wrong file. The whole row is captured and put
     * back afterwards -- style and layout as well as the is_default flag, because a
     * script that leaves a customer's default template restyled is a script nobody
     * will run twice.
     */
    if ($template === null) {
        $template = DocumentTemplate::ofType($options['template'])
            ->where('is_default', true)
            ->orderBy('id')
            ->first();
    }

    if ($template === null) {
        echo "  {$label}: no template row for type '{$options['template']}'".PHP_EOL;

        continue;
    }

    $restore = DocumentTemplate::ofType($options['template'])
        ->get(['id', 'style', 'layout', 'is_default'])
        ->keyBy('id')
        ->map(fn (DocumentTemplate $row) => [
            'style' => $row->style,
            'layout' => $row->layout,
            'is_default' => $row->is_default,
        ])
        ->all();

    try {
        $layout = (array) $template->layout;
        $layout['style'] = $style;

        $template->update(['style' => $style, 'layout' => $layout, 'is_default' => true]);

        DocumentTemplate::ofType($options['template'])
            ->where('id', '!=', $template->id)
            ->update(['is_default' => false]);

        $resolved = PdfLayout::forDocumentType($options['template'])->style();

        if ($resolved !== $style) {
            echo "  {$label}: SKIPPED -- PdfLayout resolved '{$resolved}'".PHP_EOL;

            continue;
        }

        $html = view('documents.pdf', DocumentData::build($quote))->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions(pdf_options())
            ->output();

        $file = 'preview-'.strtolower($style).($options['model'] === 'invoice' ? '-invoice' : '').'.pdf';

        file_put_contents(storage_path('app/'.$file), $pdf);

        $written[] = $file;

        printf("  %-8s %-8s %7.1f KB   %s\n", $style, $label, strlen($pdf) / 1024, $file);
    } catch (Throwable $e) {
        echo "  {$style}: FAILED -- {$e->getMessage()}".PHP_EOL;
    } finally {
        foreach ($restore as $id => $original) {
            DocumentTemplate::where('id', $id)->update($original);
        }
    }
}

echo PHP_EOL.'Rasterise them:  python render_invoice_pdf.py'.PHP_EOL;

/**
 * A throwaway sales invoice.
 *
 * Kept apart from the quote builder because an invoice is a different model with a
 * different set of fields: a due date, and a balance that has to be non-zero for the
 * "Amount due" row to print at all. A quote standing in for an invoice would have
 * previewed a document the application cannot produce.
 */
function preview_invoice(SalesCustomer $customer): SalesInvoice
{
    $invoice = SalesInvoice::create([
        'number' => 'PREVIEW-'.str_pad((string) (SalesInvoice::count() + 1), 5, '0', STR_PAD_LEFT),
        'customer_id' => $customer->id,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => 'sent',
        'currency' => 'USD',
        'exchange_rate' => 1,
        'subtotal' => '0',
        'tax_amount' => '0',
        'total' => '0',
        'paid_amount' => '0',
        'credit_applied' => '0',
        'credit_note_applied' => '0',
        'notes' => 'Preview invoice. Generated by preview_document_pdfs.php and safe to delete.',
    ]);

    $subtotal = 0.0;
    $tax = 0.0;

    foreach ([
        ['Annual platform licence, 12 users', 12, 1450.00, 0, 8],
        ['Implementation and onboarding', 1, 4800.00, 10, 8],
        ['Data migration, legacy system', 1, 1650.00, 15, 8],
    ] as [$description, $qty, $price, $discount, $rate]) {
        $figures = DocumentItems::lineTotals((float) $qty, (float) $price, (float) $discount, (float) $rate);

        $invoice->items()->create([
            'product_id' => null,
            'description' => $description,
            'qty' => $qty,
            'unit_price' => (string) $price,
            'discount_percent' => $discount,
            'tax_percent' => $rate,
            'line_total' => (string) round($figures['line_net'] + $figures['line_tax'], 2),
        ]);

        $subtotal += $figures['line_net'];
        $tax += $figures['line_tax'];
    }

    $invoice->update([
        'subtotal' => (string) $subtotal,
        'tax_amount' => (string) $tax,
        'total' => (string) round($subtotal + $tax, 2),
    ]);

    return $invoice->fresh();
}

/**
 * A throwaway quote, so this works on an empty database.
 *
 * Named rather than anonymous so it reads at the call site, and returned fresh so
 * the caller sees the totals the lines produced rather than the zeroes it was
 * created with.
 */
function preview_quote(SalesCustomer $customer, int $lines): SalesQuote
{
    $quote = SalesQuote::create([
        'number' => 'PREVIEW-'.str_pad((string) (SalesQuote::count() + 1), 5, '0', STR_PAD_LEFT),
        'customer_id' => $customer->id,
        'issue_date' => now()->toDateString(),
        'expiry_date' => now()->addMonth()->toDateString(),
        'status' => 'sent',
        'currency' => 'USD',
        'exchange_rate' => 1,
        'subtotal' => '0',
        'tax_amount' => '0',
        'total' => '0',
        'notes' => 'Preview document. Generated by preview_document_pdfs.php and safe to delete.',
    ]);

    $subtotal = 0.0;
    $tax = 0.0;

    for ($i = 1; $i <= max(1, $lines); $i++) {
        $figures = DocumentItems::lineTotals((float) $i, 125.50, 0, 17);

        $quote->items()->create([
            'product_id' => null,
            'description' => sprintf('Preview line item %03d', $i),
            'qty' => $i,
            'unit_price' => '125.50',
            'discount_percent' => 0,
            'tax_percent' => 17,
            'line_total' => (string) round($figures['line_net'] + $figures['line_tax'], 2),
        ]);

        $subtotal += $figures['line_net'];
        $tax += $figures['line_tax'];
    }

    $quote->update([
        'subtotal' => (string) $subtotal,
        'tax_amount' => (string) $tax,
        'total' => (string) round($subtotal + $tax, 2),
    ]);

    return $quote->fresh();
}
