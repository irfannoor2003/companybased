<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeDocumentController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'type' => ['nullable', 'string', 'max:30'],
            'file' => ['required', 'file', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $path = $request->file('file')->store(self::UPLOAD_DIRECTORY, 'public');

        $employee->documents()->create([
            'title' => $data['title'],
            'type' => $data['type'] ?? null,
            'file_path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'notes' => $data['notes'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Document uploaded.']]);
    }

    /**
     * The storage folder `store()` writes into, and the only one a document row
     * may point at. Declared once so upload and download cannot disagree.
     */
    private const UPLOAD_DIRECTORY = 'employee-documents';

    public function download(EmployeeDocument $document): BinaryFileResponse
    {
        /**
         * 404 rather than 500 when the row points at a file that is not there.
         *
         * The row routinely outlives the file: an admin deletes the attachment, or a
         * restore from backup brings the database forward without the uploads that
         * were not in the dump. `response()->download()` on a missing path raises
         * inside Symfony, so the user saw an error page for what is simply a
         * document that no longer exists.
         *
         * The containment check resolves the real path and compares it against the
         * storage root, rather than testing for a directory prefix. A prefix test
         * would have to hard-code whichever folder an upload landed in -- and
         * `store()` is not the only way a row gets written, so it rejected rows
         * that were perfectly valid. Resolving first catches `../` in any form,
         * including an encoded one the web server already decoded.
         */
        $root = realpath(storage_path('app/public'));
        $full = $root === false
            ? false
            : realpath($root.DIRECTORY_SEPARATOR.$document->file_path);

        abort_if($full === false || ! is_file($full), 404);

        abort_unless(
            str_starts_with($full, rtrim((string) $root, '\\/').DIRECTORY_SEPARATOR),
            404,
        );

        return response()->download($full, $document->original_name ?: $document->title);
    }

    public function destroy(EmployeeDocument $document): RedirectResponse
    {
        $document->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Document removed.']]);
    }
}