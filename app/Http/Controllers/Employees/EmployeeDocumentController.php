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

        $path = $request->file('file')->store('employee-documents', 'public');

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

    public function download(EmployeeDocument $document): BinaryFileResponse
    {
        return response()->download(storage_path('app/public/'.$document->file_path), $document->original_name ?: $document->title);
    }

    public function destroy(EmployeeDocument $document): RedirectResponse
    {
        $document->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Document removed.']]);
    }
}