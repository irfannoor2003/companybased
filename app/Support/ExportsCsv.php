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

        $html = View::make('exports.pdf', array_merge([
            'title' => $title,
            'subtitle' => $extra['subtitle'] ?? null,
            'headers' => $headers,
            'rows' => $rows,
            'meta' => $extra['meta'] ?? [],
        ], $extra))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', $extra['orientation'] ?? 'portrait');

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
