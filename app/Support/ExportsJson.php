<?php

namespace App\Support;

use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a value as a downloadable JSON file.
 */
trait ExportsJson
{
    protected function streamJson(string $filename, mixed $data): StreamedResponse
    {
        return Response::streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }
}
