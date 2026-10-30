<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\PdfLayout;
use Database\Seeders\DocumentTemplatesSeeder;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Settings -> Templates is operable, not merely present.
 *
 * The design screen compiled, was wired to `TemplateController`, and had never been
 * exercised. Three things followed from that, all of them the same shape of defect --
 * a control that looks real and does nothing:
 *
 *   1. `style` was missing from `DocumentTemplate::$fillable`, so the style picker
 *      saved nothing at all. Pinned here as well as in `PdfLayoutTest`, because this
 *      is the screen that takes the choice.
 *   2. Every block checkbox was rendered `checked disabled`, so "Tick to show" had
 *      no effect; and the checkbox posted nothing either way, so unticking could
 *      not have removed a block even if the browser had allowed it.
 *   3. The block list held only the blocks currently printed, so a control that can
 *      only remove could never add one back.
 *
 * Driven through the real routes as the Admin who would use them, rather than by
 * calling the controller directly -- the form fields and their names are half of what
 * is being tested.
 */
class DocumentTemplateSettingsTest extends TestCase
{
    use SeedsDatabase;

    private function admin(): User
    {
        return User::where('email', 'admin@nexosdigital.test')->firstOrFail();
    }

    private function signIn(): void
    {
        $this->actingAs($this->admin());
    }

    /**
     * The full payload the design form posts, for a template of $type.
     *
     * @return array<string, mixed>
     */
    private function payload(string $type = 'quote', array $overrides = []): array
    {
        return array_merge([
            'name' => 'Seasonal '.ucfirst($type),
            'type' => $type,
            'style' => PdfLayout::STYLE_MODERN,
            'blocks' => PdfLayout::DEFAULT_BLOCK_ORDER,
            'primary_color' => '#123456',
            'accent_color' => '#654321',
            'text_color' => '#101010',
            'is_default' => true,
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // The screens render
    // ---------------------------------------------------------------------

    public function test_a_fresh_installation_has_templates_to_choose_from(): void
    {
        // The regression: the ten rows on the development database were inserted by
        // hand, so a genuinely fresh install opened this screen on an empty page and
        // the whole subsystem looked absent.
        $this->assertGreaterThan(
            0,
            DocumentTemplate::count(),
            'a fresh install has no document templates; the seeder is not wired into DatabaseSeeder',
        );

        $this->signIn();

        $response = $this->get(route('settings.templates.index'));

        $response->assertOk();

        $response->assertDontSee('No templates for this type yet.');
        $response->assertSee('Classic Quote');
    }

    public function test_every_document_type_gets_a_default_template(): void
    {
        foreach ([
            'invoice', 'quote', 'order', 'delivery_note',
            'credit_note', 'purchase_order', 'purchase_invoice', 'receipt',
        ] as $type) {
            $this->assertNotNull(
                DocumentTemplate::where('type', $type)->where('is_default', true)->first(),
                $type.' has no default template',
            );
        }
    }

    /**
     * Exactly one default per type, or `PdfLayout::forType()` picks whichever row
     * the database happened to return first and the business's choice is a coin flip.
     */
    public function test_a_type_never_has_two_default_templates(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('quote', [
            'name' => 'Second default',
            'style' => PdfLayout::STYLE_BANDED,
        ]))->assertRedirect(route('settings.templates.index'));

        $this->assertSame(
            1,
            DocumentTemplate::where('type', 'quote')->where('is_default', true)->count(),
            'a type ended up with two default templates',
        );

        $this->assertSame(
            'Second default',
            DocumentTemplate::where('type', 'quote')->where('is_default', true)->first()->name,
            'the template that was just saved default should have taken over',
        );
    }

    public function test_the_create_screen_offers_every_style_and_every_block(): void
    {
        $this->signIn();

        $response = $this->get(route('settings.templates.create'));

        $response->assertOk();

        foreach (PdfLayout::STYLES as $style => $label) {
            $response->assertSee('value="'.$style.'"', false);
            $response->assertSee($label);
        }

        foreach (PdfLayout::BLOCKS as $block => $definition) {
            $response->assertSee($definition['label']);
        }
    }

    /**
     * The form posts every block, because a control that can only remove something
     * cannot add it back.
     */
    public function test_the_design_form_lists_every_block_not_only_the_printed_ones(): void
    {
        $this->signIn();

        $template = DocumentTemplate::create([
            'name' => 'Two blocks only',
            'type' => 'credit_note',
            'style' => PdfLayout::STYLE_RAIL,
            'layout' => ['blocks' => ['header', 'items', 'totals']],
            'is_default' => true,
        ]);

        $response = $this->get(route('settings.templates.edit', $template));

        $response->assertOk();

        foreach (PdfLayout::BLOCKS as $block => $definition) {
            $response->assertSee(
                'name="blocks[]" value="'.$block.'"',
                false,
                $block.' is not on the design form, so it cannot be switched back on',
            );
        }
    }

    // ---------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------

    /**
     * THE REGRESSION: the style picker saved nothing.
     *
     * `style` was absent from `$fillable`, so mass assignment dropped it and every
     * template fell back to the column default. The operator picked a design, the
     * screen reported success, and the document came out exactly as before.
     */
    public function test_the_style_chosen_on_the_create_form_is_stored(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('invoice', [
            'style' => PdfLayout::STYLE_MINIMAL,
        ]))->assertRedirect(route('settings.templates.index'));

        $template = DocumentTemplate::where('name', 'Seasonal Invoice')->firstOrFail();

        $this->assertSame(PdfLayout::STYLE_MINIMAL, $template->style);
        $this->assertSame(PdfLayout::STYLE_MINIMAL, PdfLayout::forDocumentType('invoice')->style());
    }

    public function test_the_style_chosen_on_the_edit_form_is_stored(): void
    {
        $this->signIn();

        $template = DocumentTemplate::firstOrFail();

        $this->put(route('settings.templates.update', $template), [
            'name' => $template->name,
            'style' => PdfLayout::STYLE_BANDED,
            'blocks' => PdfLayout::DEFAULT_BLOCK_ORDER,
        ])->assertSessionHasNoErrors();

        $this->assertSame(PdfLayout::STYLE_BANDED, $template->fresh()->style);
    }

    /**
     * THE REGRESSION: a section could not be switched off.
     *
     * The form used to render every checkbox `checked disabled` and post nothing,
     * so there was no route by which a block could leave a document. What the
     * operator unticked has to be absent from the stored order.
     */
    public function test_switching_a_section_off_removes_it_from_the_template(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('order', [
            'blocks' => ['header', 'party', 'items', 'totals', 'footer'],
        ]))->assertSessionHasNoErrors();

        $template = DocumentTemplate::where('name', 'Seasonal Order')->firstOrFail();

        $this->assertNotContains('bank', $template->layout['blocks']);
        $this->assertNotContains('terms', $template->layout['blocks']);
        $this->assertNotContains('notes', $template->layout['blocks']);
        $this->assertNotContains('signature', $template->layout['blocks']);

        $layout = PdfLayout::forDocumentType('order');

        $this->assertFalse($layout->has('bank'));
        $this->assertTrue($layout->has('party'));
    }

    public function test_a_section_can_be_switched_back_on(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('order', [
            'blocks' => ['header', 'items', 'totals'],
        ]))->assertSessionHasNoErrors();

        $this->post(route('settings.templates.store'), $this->payload('order', [
            'name' => 'With the bank back',
            'blocks' => ['header', 'items', 'totals', 'bank'],
        ]))->assertSessionHasNoErrors();

        $template = DocumentTemplate::where('name', 'With the bank back')->firstOrFail();

        $this->assertTrue(PdfLayout::forDocumentType('order')->has('bank'));
        $this->assertContains('bank', $template->layout['blocks']);
    }

    /**
     * The order the arrows set is the order stored.
     */
    public function test_the_order_the_arrows_set_is_the_order_that_is_stored(): void
    {
        $this->signIn();

        $requested = ['header', 'totals', 'items', 'party', 'footer'];

        $this->post(route('settings.templates.store'), $this->payload('purchase_order', [
            'blocks' => $requested,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            $requested,
            DocumentTemplate::where('name', 'Seasonal Purchase_order')->firstOrFail()->layout['blocks'],
        );
    }

    /**
     * A crafted POST, or a stale one saved before this pass, cannot smuggle an
     * unknown block INTO the renderer -- but it CAN legitimately strip every
     * section off the document now, because every block is the operator's to
     * remove. The renderer defends pagination; the save defends the keys.
     */
    public function test_a_crafted_post_cannot_add_blocks_the_renderer_does_not_know(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('delivery_note', [
            'blocks' => ['totals', 'footer', 'evil_block', '<script>'],
        ]))->assertSessionHasNoErrors();

        $blocks = DocumentTemplate::where('name', 'Seasonal Delivery_note')->firstOrFail()->layout['blocks'];

        $this->assertSame(['totals', 'footer'], $blocks, 'unknown keys survived the save or the order drifted');
        $this->assertNotContains('evil_block', $blocks);
        $this->assertNotContains('header', $blocks, 'the letterhead was resurrected behind the operator');
    }

    /**
     * An unknown style is refused rather than saved as something that prints.
     */
    public function test_an_unknown_style_is_refused(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('quote', [
            'style' => 'art-deco',
        ]))->assertSessionHasErrors('style');

        $this->assertDatabaseMissing('document_templates', ['style' => 'art-deco']);
    }

    public function test_a_template_needs_a_name_and_a_type(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), [])
            ->assertSessionHasErrors(['name', 'type', 'style']);
    }

    // ---------------------------------------------------------------------
    // Deleting
    // ---------------------------------------------------------------------

    /**
     * A system template may be the default for a document type, and deleting it
     * would leave the type with no template at all.
     */
    public function test_a_system_template_cannot_be_deleted(): void
    {
        $this->signIn();

        $template = DocumentTemplate::where('is_system', true)->firstOrFail();

        $this->delete(route('settings.templates.destroy', $template));

        $this->assertDatabaseHas('document_templates', ['id' => $template->id]);
    }

    public function test_a_template_the_business_wrote_can_be_deleted(): void
    {
        $this->signIn();

        $this->post(route('settings.templates.store'), $this->payload('credit_note', [
            'name' => 'Draft design',
            'is_default' => false,
        ]))->assertSessionHasNoErrors();

        $template = DocumentTemplate::where('name', 'Draft design')->firstOrFail();

        $this->delete(route('settings.templates.destroy', $template))
            ->assertRedirect(route('settings.templates.index'));

        $this->assertDatabaseMissing('document_templates', ['id' => $template->id]);
    }

    // ---------------------------------------------------------------------
    // Seeding is safe to repeat
    // ---------------------------------------------------------------------

    /**
     * `db:seed` after an upgrade is a routine thing to do. It must not silently move
     * a default off whatever the business had chosen -- an operator would come back
     * one morning to documents in a design nobody selected, and no record of when.
     */
    public function test_reseeding_does_not_take_the_default_off_a_chosen_template(): void
    {
        $this->signIn();

        DocumentTemplate::where('type', 'quote')->update(['is_default' => false]);

        $chosen = DocumentTemplate::create([
            'name' => 'The one we chose',
            'type' => 'quote',
            'style' => PdfLayout::STYLE_MINIMAL,
            'layout' => ['blocks' => PdfLayout::DEFAULT_BLOCK_ORDER],
            'is_default' => true,
            'is_system' => false,
        ]);

        $this->seed(DocumentTemplatesSeeder::class);

        $this->assertTrue($chosen->fresh()->is_default, 'reseeding took the default away');
        $this->assertSame(PdfLayout::STYLE_MINIMAL, $chosen->fresh()->style);
    }

    public function test_reseeding_does_not_duplicate_a_preset(): void
    {
        $before = DocumentTemplate::where('is_system', true)->count();

        $this->seed(DocumentTemplatesSeeder::class);
        $this->seed(DocumentTemplatesSeeder::class);

        $this->assertSame($before, DocumentTemplate::where('is_system', true)->count());
        $this->assertSame(0, DocumentTemplate::where('name', 'Classic Quote')->count() - 1);
    }
}
