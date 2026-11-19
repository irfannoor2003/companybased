<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a collection of arrays into a downloadable CSV file. When the
 * request asks for ?format=pdf the same headers/rows are rendered through
 * the branded PDF template instead, so every list export gains PDF support
 * without touching the controllers.
 *
 * The ?format=pdf branch borrows the INVOICE design's colours and custom
 * stylesheet: a list export is not a customer document with a template of its
 * own, but an office printing the suite expects the same brand on the sheet
 * it files. The PDF path also inherits the operator's custom stylesheet, so
 * the whole print suite can be restyled from the one place.
 */
trait ExportsCsv
{
    protected function streamCsv(string $filename, array $headers, iterable $rows): HttpResponse|StreamedResponse
    {
        $request = request();

        if ($request->query('format') === 'pdf') {
            $title = $this->pdfTitleFromFilename($filename);

            return $this->renderPdf($filename, $headers, $rows, $title);
        }

        $stream = function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        };

        return Response::streamDownload($stream, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function renderPdf(string $filename, array $headers, iterable $rows, string $title, array $extra = []): HttpResponse
    {
        set_time_limit(0);

        $rows = collect($rows)->map(fn ($row) => array_values((array) $row))->all();

        $meta = [];
        if (filled($extra['subtitle'] ?? null)) {
            $meta[] = ['label' => 'Report', 'value' => (string) $extra['subtitle'], 'show' => true];
        }
        foreach ($extra['meta'] ?? [] as $label => $value) {
            $meta[] = ['label' => (string) $label, 'value' => (string) $value, 'show' => true];
        }

        /**
         * A list export is a document too. It now prints through the same frame
         * as an invoice or a statement -- the operator's template, style, colours,
         * header and footer -- with its rows in the generic table block instead of
         * a standalone sheet that copied the invoice's colour and ignored the rest.
         */
        $payload = DocumentData::make('export', [
            'title' => $title,
            'meta' => $meta,
            'table' => [
                'headers' => array_values($headers),
                'aligns' => array_fill(0, count($headers), 'left'),
                'rows' => $rows,
                'empty' => 'No records found.',
            ],
            'notes' => $extra['notes'] ?? null,
            'blockReady' => [
                'party' => false,
                'items' => false,
                'totals' => false,
                'receipt' => false,
                'bank' => false,
                'terms' => false,
                'signature' => false,
            ],
        ]);

        $html = View::make('documents.pdf', $payload)->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', $extra['orientation'] ?? 'portrait')
            ->setOptions(pdf_options());

        $filename = str_ends_with($filename, '.pdf') ? $filename : preg_replace('/\.(csv|json)$/i', '.pdf', $filename) ?? $filename.'.pdf';

        $output = $pdf->output();

        return Response::streamDownload(function () use ($output) {
            echo $output;
        }, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function pdfTitleFromFilename(string $filename): string
    {
        $base = preg_replace('/-\d{4}-\d{2}-\d{2}.*$/i', '', pathinfo($filename, PATHINFO_FILENAME));

        return ucwords(str_replace(['-', '_'], ' ', $base));
    }
}
