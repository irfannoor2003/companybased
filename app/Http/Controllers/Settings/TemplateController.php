<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function index(): View
    {
        $templates = DocumentTemplate::orderBy('type')->orderBy('name')->get();

        return view('settings.templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('settings.templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:invoice,quote,order,delivery_note,credit_note,purchase_order,purchase_invoice,receipt'],
            'description' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'accent_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'text_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'header_position' => ['nullable', 'string', 'in:left,center,right'],
            'show_logo' => ['boolean'],
            'show_tax' => ['boolean'],
            'header_html' => ['nullable', 'string'],
            'footer_html' => ['nullable', 'string'],
            'css' => ['nullable', 'string'],
            'is_default' => ['boolean'],
        ]);

        $template = DocumentTemplate::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'colors' => [
                'primary' => $data['primary_color'] ?? '#4f46e5',
                'accent' => $data['accent_color'] ?? '#0ea5e9',
                'text' => $data['text_color'] ?? '#1f2937',
            ],
            'layout' => [
                'header' => $data['header_position'] ?? 'left',
                'show_logo' => $request->boolean('show_logo', true),
                'show_tax' => $request->boolean('show_tax', true),
            ],
            'header_html' => $data['header_html'] ?? null,
            'footer_html' => $data['footer_html'] ?? null,
            'css' => $data['css'] ?? null,
            'is_default' => $request->boolean('is_default', false),
        ]);

        if ($template->is_default) {
            DocumentTemplate::where('type', $template->type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
        }

        return redirect()->route('settings.templates.index')
            ->with('toasts', [['type' => 'success', 'message' => "Template \"{$template->name}\" created."]]);
    }

    public function edit(DocumentTemplate $template): View
    {
        return view('settings.templates.edit', compact('template'));
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'accent_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'text_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'header_position' => ['nullable', 'string', 'in:left,center,right'],
            'show_logo' => ['boolean'],
            'show_tax' => ['boolean'],
            'header_html' => ['nullable', 'string'],
            'footer_html' => ['nullable', 'string'],
            'css' => ['nullable', 'string'],
            'is_default' => ['boolean'],
        ]);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'colors' => [
                'primary' => $data['primary_color'] ?? $template->colors['primary'],
                'accent' => $data['accent_color'] ?? $template->colors['accent'],
                'text' => $data['text_color'] ?? $template->colors['text'],
            ],
            'layout' => [
                'header' => $data['header_position'] ?? $template->layout['header'],
                'show_logo' => $request->boolean('show_logo', true),
                'show_tax' => $request->boolean('show_tax', true),
            ],
            'header_html' => $data['header_html'] ?? null,
            'footer_html' => $data['footer_html'] ?? null,
            'css' => $data['css'] ?? null,
            'is_default' => $request->boolean('is_default', false),
        ]);

        if ($template->is_default) {
            DocumentTemplate::where('type', $template->type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Template \"{$template->name}\" updated."]]);
    }

    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        if ($template->is_system) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'System templates cannot be deleted.']]);
        }

        $template->delete();

        return redirect()->route('settings.templates.index')
            ->with('toasts', [['type' => 'success', 'message' => "Template deleted."]]);
    }
}
