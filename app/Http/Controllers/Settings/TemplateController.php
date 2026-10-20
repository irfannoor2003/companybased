<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Support\PdfLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        return view('settings.templates.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:invoice,quote,order,delivery_note,credit_note,purchase_order,purchase_invoice,receipt'],
            'description' => ['nullable', 'string', 'max:500'],
            'style' => ['required', 'string', Rule::in(array_keys(PdfLayout::STYLES))],
            'blocks' => ['nullable', 'array'],
            'blocks.*' => ['string'],
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
            'style' => $data['style'],
            'layout' => $this->layoutFrom($request),
            'colors' => [
                'primary' => $data['primary_color'] ?? '#4f46e5',
                'accent' => $data['accent_color'] ?? '#0ea5e9',
                'text' => $data['text_color'] ?? '#1f2937',
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
        return view('settings.templates.edit', array_merge($this->formData(), compact('template')));
    }

    /**
     * Render a sample document with this template applied, so the operator can
     * see the design before assigning it as the default.
     */
    public function preview(DocumentTemplate $template): View
    {
        $modelClass = match ($template->type) {
            'quote' => \App\Models\SalesQuote::class,
            'order' => \App\Models\SalesOrder::class,
            'delivery_note' => \App\Models\SalesDeliveryNote::class,
            'credit_note' => \App\Models\SalesCreditNote::class,
            'purchase_quote' => \App\Models\PurchaseQuote::class,
            'purchase_order' => \App\Models\PurchaseOrder::class,
            'purchase_invoice' => \App\Models\PurchaseInvoice::class,
            'receipt' => \App\Models\SalesInvoice::class,
            default => \App\Models\SalesInvoice::class,
        };

        $sample = $modelClass::query()->latest('id')->first();

        if ($sample === null) {
            abort(404, 'No sample document available to preview this template.');
        }

        $data = \App\Support\DocumentData::build($sample);
        $data['layout'] = \App\Support\PdfLayout::forTemplate($template);
        $data['number'] = $data['number'] ?? 'PREVIEW';

        return view('documents.pdf', $data);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'style' => ['required', 'string', Rule::in(array_keys(PdfLayout::STYLES))],
            'blocks' => ['nullable', 'array'],
            'blocks.*' => ['string'],
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
            'style' => $data['style'],
            'colors' => [
                'primary' => $data['primary_color'] ?? $template->colors['primary'],
                'accent' => $data['accent_color'] ?? $template->colors['accent'],
                'text' => $data['text_color'] ?? $template->colors['text'],
            ],
            'layout' => $this->layoutFrom($request, $template),
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

    /**
     * Everything the create and edit screens need, so both views stay in step.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'styles' => PdfLayout::STYLES,
            'blocks' => PdfLayout::BLOCKS,
            'defaultOrder' => PdfLayout::DEFAULT_BLOCK_ORDER,
        ];
    }

    /**
     * The stored layout blob.
     *
     * The block list is rebuilt through `PdfLayout` rather than saved raw, so what
     * lands in the database is the normalised order: unknown keys dropped, required
     * blocks restored, letterhead first. Storing the raw POST would mean a stale or
     * tampered form could save an order the renderer then has to defend against on
     * every single print -- and it does defend against it, but storing the clean
     * version means the settings screen and the printer agree.
     */
    private function layoutFrom(Request $request, ?DocumentTemplate $template = null): array
    {
        $style = $request->string('style')->toString();
        $style = in_array($style, array_keys(PdfLayout::STYLES), true) ? $style : PdfLayout::STYLE_RAIL;

        $blocks = (new PdfLayout($style, array_values($request->input('blocks', []))))->blocks();

        return [
            'header' => $request->input('header_position', $template?->layout['header'] ?? 'left'),
            'show_logo' => $request->boolean('show_logo', true),
            'show_tax' => $request->boolean('show_tax', true),
            'style' => $style,
            'blocks' => $blocks,
        ];
    }

    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        if ($template->is_system) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'System templates cannot be deleted.']]);
        }

        $template->delete();

        return redirect()->route('settings.templates.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Template deleted.']]);
    }
}
