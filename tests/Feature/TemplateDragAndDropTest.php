<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Support\PdfLayout;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The template block list can be reordered by dragging as well as by arrows.
 *
 * There is no JS runner in this project, so the browser gesture itself cannot be
 * executed here. What can be pinned -- and is -- is everything it depends on:
 * the rows are draggable, carry a stable key, wire every native drag event, show
 * a handle, and the CSS drop-target class exists. The server-side half (a reordered
 * `blocks[]` post is stored in that exact order) is pinned too.
 */
class TemplateDragAndDropTest extends TestCase
{
    use SeedsDatabase;

    private function admin(): \App\Models\User
    {
        return \App\Models\User::where('email', 'admin@nexosdigital.test')->firstOrFail();
    }

    public function test_the_design_form_wires_native_drag_events_on_every_block_row(): void
    {
        $template = DocumentTemplate::where('type', 'invoice')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get(route('settings.templates.edit', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('draggable="true"', $html);
        $this->assertStringContainsString('data-block-row="header"', $html);
        $this->assertStringContainsString('x-on:dragstart="dragStart', $html);
        $this->assertStringContainsString('x-on:dragover="dragOver', $html);
        $this->assertStringContainsString('x-on:dragleave="dragLeave', $html);
        $this->assertStringContainsString('x-on:drop="drop', $html);
        $this->assertStringContainsString('x-on:dragend="dragEnd', $html);
        $this->assertStringContainsString('title="Drag to reorder"', $html);

        // The reorder core and the DOM-move helper that keeps the posted order
        // in step with what is on screen.
        $this->assertStringContainsString('reorder(source, target, after)', $html);
        $this->assertStringContainsString('placeRow(source, target, after)', $html);
        $this->assertStringContainsString('insertBefore(sourceRow', $html);

        // The component must be registered in a real script block. It used to sit
        // in a single-quoted x-data attribute, whose own JS strings closed the
        // attribute early and printed the component as text on the page.
        $this->assertStringContainsString("Alpine.data('blockBuilder'", $html);
        $this->assertStringContainsString('x-data="blockBuilder()"', $html);
        $this->assertStringNotContainsString("x-data='{", $html);
    }

    public function test_the_drop_target_highlight_is_styled(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.is-drop-target', $css);
        $this->assertStringContainsString('@apply border-primary bg-primary/5', $css);
    }

    public function test_a_dragged_order_is_stored_exactly(): void
    {
        $this->actingAs($this->admin());

        // Arrows and drag both submit the reordered blocks[] list; the server
        // has no idea which gesture produced it and must preserve it verbatim.
        $requested = ['header', 'totals', 'items', 'party', 'footer'];

        $this->post(route('settings.templates.store'), [
            'name' => 'Dragged '.uniqid(),
            'type' => 'invoice',
            'style' => PdfLayout::STYLE_RAIL,
            'blocks' => $requested,
            'is_default' => false,
        ])->assertSessionHasNoErrors();

        $saved = DocumentTemplate::where('name', 'like', 'Dragged %')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($requested, $saved->layout['blocks']);
    }
}
