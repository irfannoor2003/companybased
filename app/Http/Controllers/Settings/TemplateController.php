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
            'colors' => $this->colorsFrom($data),
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
        /**
         * Use the newest real document of this type when one exists, but fall
         * back to an in-memory sample when the database is empty. A preview that
         * 404s on a fresh install is a design screen with no design on it.
         * `TemplateSample` knows each type's model and the relations DocumentData
         * reads, so a receipt still previews as a receipt and a bill as a bill.
         */
        $sample = \App\Support\TemplateSample::forType($template->type);

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
            'colors' => $this->colorsFrom($data),
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
     * Only genuine colour overrides are stored. An empty palette means "follow
     * the superadmin's brand colour", so a later brand change still reaches the
     * template. The form prefills the brand colour, so an untouched colour field
     * must not silently freeze it into the row.
     *
     * @param  array<string,mixed>  $data
     * @return array<string, string>
     */
    private function colorsFrom(array $data): array
    {
        $defaults = [
            'primary' => settings('branding.primary_color', '#4f46e5'),
            'accent' => settings('branding.accent_color', '#0ea5e9'),
            'text' => '#1f2937',
        ];

        $colors = [];

        foreach ($defaults as $key => $default) {
            $posted = $data[$key.'_color'] ?? null;

            if (filled($posted) && $posted !== $default) {
                $colors[$key] = $posted;
            }
        }

        return $colors;
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
     * lands in the database is the normalised order: unknown keys dropped,
     * duplicates collapsed, letterhead printed first when the operator kept it.
     * Storing the raw POST would mean a stale or tampered form could save an order
     * the renderer then has to defend against on every single print -- which it
     * still defends against, but storing the clean version means the settings
     * screen and the printer agree.
     */
    private function layoutFrom(Request $request, ?DocumentTemplate $template = null): array
    {
        $style = $request->string('style')->toString();
        $style = in_array($style, array_keys(PdfLayout::STYLES), true) ? $style : PdfLayout::STYLE_RAIL;

        $blocks = (new PdfLayout($style, array_values($request->input('blocks', []))))->blocks();

        return [
            'header' => $request->input('header_position', $template?->layout['header'] ?? 'left'),
            // An unchecked checkbox posts nothing at all, so a `true` default here
            // made "Show logo" and "Show tax details" impossible to switch off:
            // unticking posted no key and the default switched it straight back on.
            'show_logo' => $request->boolean('show_logo', false),
            'show_tax' => $request->boolean('show_tax', false),
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
