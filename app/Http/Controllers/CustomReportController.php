<?php

namespace App\Http\Controllers;

use App\Models\CustomReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomReportController extends Controller
{
    public function index(): View
    {
        $reports = CustomReport::latest()->paginate(20);
        return view('reports.custom.index', compact('reports'));
    }

    public function create(): View
    {
        return view('reports.custom.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'module' => 'required|string|max:50',
            'fields' => 'nullable',
            'filters' => 'nullable',
        ]);

        $data['fields'] = $this->payloadToArray($data['fields'] ?? null);
        $data['filters'] = $this->payloadToArray($data['filters'] ?? null);
        $data['user_id'] = auth()->id();

        CustomReport::create($data);

        return redirect()->route('reports.custom.index')->with('status', 'Report defined successfully.');
    }

    /**
     * The report builder submits fields/filters as JSON strings via hidden
     * inputs, but clients may also post real arrays. Normalise both.
     */
    private function payloadToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
