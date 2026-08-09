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
            'fields' => 'nullable|array',
            'filters' => 'nullable|array',
        ]);

        $data['user_id'] = auth()->id();

        CustomReport::create($data);

        return redirect()->route('reports.custom.index')->with('status', 'Report defined successfully.');
    }
}
