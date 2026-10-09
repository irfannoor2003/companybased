<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The document preview is driven entirely by Alpine in app.js, so none of this
 * is covered by the PHP suite. These tests assert the contract between the
 * rendered form and the JS that reads it: that the fields the preview looks for
 * exist, and that the component is wired to keep itself in sync.
 *
 * They cannot execute JavaScript, so the DOM-order and selector bugs that made
 * the preview silently empty are asserted structurally here.
 */
class DocumentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // CheckSubscription blocks every non-Super-Admin route without an
        // active package.
        Subscription::create([
            'plan_name' => 'Test Plan',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The create screens that actually render <x-document-preview>. Payment
     * screens are excluded: they record a single amount and have no line
     * items, and they do not use the component at all.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documentScreens(): array
    {
        return [
            'invoice create' => ['/sales/invoices/create', 'customer_id'],
            'order create' => ['/sales/orders/create', 'customer_id'],
            'quote create' => ['/sales/quotes/create', 'customer_id'],
            'credit note create' => ['/sales/credit-notes/create', 'customer_id'],
            'delivery note create' => ['/sales/delivery-notes/create', 'customer_id'],
            'purchase invoice create' => ['/suppliers/purchase-invoices/create', 'supplier_id'],
            'purchase order create' => ['/suppliers/purchase-orders/create', 'supplier_id'],
            'purchase quote create' => ['/suppliers/purchase-quotes/create', 'supplier_id'],
            'debit note create' => ['/suppliers/debit-notes/create', 'supplier_id'],
        ];
    }

    private ?User $admin = null;

    private function admin(): User
    {
        // userForRole() creates a user each call, so reuse one per test.
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function htmlFor(string $path): string
    {
        return $this->actingAs($this->admin())->get($path)->assertOk()->getContent();
    }

    public function test_the_preview_component_is_present_on_every_document_screen(): void
    {
        foreach (self::documentScreens() as $label => [$path]) {
            $response = $this->actingAs($this->admin())->get($path);

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "{$label} ({$path}) returned ".$response->getStatusCode()
            );

            $this->assertStringContainsString('documentPreview', $response->getContent(), $label);
        }
    }

    /**
     * The preview resolves its form by looking for these field names. If a
     * screen renames one, the preview silently renders an empty document.
     *
     * Delivery notes are handled separately: they use a qty-only editor.
     */
    public function test_the_form_exposes_the_fields_the_preview_reads(): void
    {
        foreach (self::documentScreens() as $label => [$path, $partyField]) {
            if ($label === 'delivery note create') {
                continue;
            }

            $html = $this->htmlFor($path);

            $this->assertStringContainsString('name="'.$partyField.'"', $html, "{$label}: party field");
            $this->assertStringContainsString('name="notes"', $html, "{$label}: notes");
            $this->assertStringContainsString('name="currency"', $html, "{$label}: currency");

            // The preview finds line items by the [qty] name, so the editor's
            // dynamic names must be present in the markup.
            $this->assertStringContainsString('[qty]', $html, "{$label}: qty");
            $this->assertStringContainsString('[unit_price]', $html, "{$label}: unit price");
            $this->assertStringContainsString('[description]', $html, "{$label}: description");
        }
    }

    /**
     * A delivery note is not monetary: no currency select and no unit price.
     * The preview must not break on such a screen, which is why both are
     * resolved defensively rather than assumed.
     */
    public function test_a_non_monetary_document_still_renders_the_preview(): void
    {
        $html = $this->htmlFor('/sales/delivery-notes/create');

        $this->assertStringContainsString('documentPreview', $html);
        $this->assertStringNotContainsString('name="currency"', $html);
        $this->assertStringContainsString('[qty]', $html);
        $this->assertStringContainsString('[description]', $html);
    }

    /**
     * The regression that made the preview blank: the preview button renders in
     * the page header, which is a sibling of <main>, so the document form is
     * never an ancestor of it and `closest('form')` cannot find it.
     */
    public function test_the_preview_is_rendered_outside_the_document_form(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $previewAt = strpos($html, 'documentPreview');
        $formAt = strpos($html, 'name="customer_id"');

        $this->assertIsInt($previewAt);
        $this->assertIsInt($formAt);

        // If this ever flips, the JS could use closest('form') again.
        $this->assertLessThan(
            $formAt,
            $previewAt,
            'The preview now renders inside the form; targetForm() can be simplified back to closest().'
        );
    }

    /**
     * The layout renders a logout form in the header, before the document form.
     * A naive querySelector('form') therefore matches the wrong form, which is
     * why the JS has to select by document fields.
     */
    public function test_a_logout_form_precedes_the_document_form(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertLessThan(
            strpos($html, 'name="customer_id"'),
            strpos($html, '/logout'),
            'Logout form is no longer before the document form; re-check targetForm().'
        );
    }

    public function test_the_preview_refreshes_while_open(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        // The preview used to only re-read the form when the button was
        // clicked, so edits were never reflected.
        $this->assertStringContainsString("addEventListener('input'", $source);
        $this->assertStringContainsString("\$watch('show'", $source);
    }

    public function test_the_preview_does_not_fall_back_to_the_first_form(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString(
            "document.querySelector('form')",
            $source,
            'A bare form selector can match the header logout form.'
        );
    }

    /**
     * The preview used to be a w-full max-w-2xl drawer with no backdrop, so the
     * sidebar and topbar stayed lit beside it and the result read as a panel
     * over the app rather than as the document itself.
     */
    public function test_the_preview_covers_the_viewport_with_a_backdrop(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertStringContainsString(
            'document-preview-overlay',
            $html,
            'The overlay wrapper is gone; a bare panel cannot cover the app chrome.',
        );

        $this->assertMatchesRegularExpression(
            '/document-preview-overlay[^"]*fixed inset-0/',
            $html,
            'The overlay must be pinned to the full viewport so nothing of the app shows through.',
        );
    }

    public function test_the_overlay_is_a_modal_dialog(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('@keydown.escape.window="show = false"', $html, 'Escape must close the preview');
    }

    /**
     * The panel is `print:hidden` and position:fixed, and the data-entry form
     * underneath was not hidden at all. So Ctrl+P from a create screen printed
     * the entire form and dropped the document — the print path produced the
     * opposite of what the user was previewing.
     */
    public function test_printing_the_preview_prints_the_document_not_the_form(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            'body.document-preview-open main',
            $layout,
            'The entry form must be hidden while printing the preview, or the form is what gets printed.',
        );

        $this->assertStringContainsString(
            'body.document-preview-open .document-preview-overlay {',
            $layout,
            'The overlay must be reset to static and unhidden for print.',
        );

        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            "document.body.classList.toggle('document-preview-open'",
            $source,
            'Opening the preview must mark the body, or the print stylesheet never applies.',
        );
    }

    /**
     * The trigger button renders inline in the page header while the overlay is
     * pushed to a stack outside <main> (so printing can hide the form without
     * hiding the document). That makes them two separate Alpine roots, which
     * cannot share one `show` property — so they are coordinated by a document
     * event. Without it the button does nothing.
     */
    public function test_the_trigger_and_the_overlay_are_wired_together(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertStringContainsString(
            'isOverlay: false',
            $html,
            'The inline trigger must be marked as the trigger root.',
        );

        $this->assertStringContainsString(
            'isOverlay: true',
            $html,
            'The pushed overlay must be marked as the overlay root.',
        );

        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            'document-preview:',
            $source,
            'The two roots are separate and need a document event to communicate.',
        );

        $this->assertStringContainsString('document.dispatchEvent', $source);
        $this->assertStringContainsString('document.addEventListener', $source);
    }

    /**
     * The regression that produced a blank print: the overlay was rendered
     * inside <main>, and the print stylesheet hides <main> to remove the
     * data-entry form — so it hid the document too.
     */
    public function test_the_overlay_is_rendered_outside_main(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        // Matched as a directive on its own line, not as a substring: the
        // explanatory Blade comment above it also names @stack('preview'), and a
        // plain strpos() finds that mention first and reports a false pass.
        $this->assertSame(
            1,
            preg_match_all('/^\s*@stack\(\'preview\'\)\s*$/m', $layout, $matches),
            'The layout must render the preview stack exactly once as a directive.',
        );

        $pushAt = strpos($layout, $matches[0][0]);
        $mainClosesAt = strpos($layout, '</main>');

        $this->assertIsInt($pushAt);
        $this->assertIsInt($mainClosesAt);

        $this->assertGreaterThan(
            $mainClosesAt,
            $pushAt,
            'The overlay stack must come after </main>; inside <main> the print rule that hides <main> hides the document and prints a blank page.',
        );
    }

    public function test_the_overlay_is_not_print_hidden(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertStringNotContainsString(
            'document-preview-overlay fixed inset-0 z-50 flex h-full w-full max-w-2xl flex-col border-l border-line bg-surface shadow-xl print:hidden',
            $html,
            'The old drawer styling is back; it covered only part of the viewport and was excluded from print.',
        );
    }

    public function test_the_preview_toolbar_is_excluded_from_print(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            'body.document-preview-open .document-preview-overlay > .sticky',
            $layout,
            'The Print/Close toolbar is UI and must not appear on the printed document.',
        );
    }

    public function test_the_preview_has_a_print_action(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertStringContainsString('printPreview()', $html, 'The preview offers no way to print or export what it shows');
    }

    /**
     * "Bill to" was hardcoded, so every supplier-side document (purchase quote,
     * purchase order, purchase invoice, debit note) was labelled as if it were
     * addressed to a customer.
     */
    public function test_a_supplier_document_is_labelled_supplier(): void
    {
        $this->assertStringContainsString(
            "partyLabel: 'Supplier'",
            $this->htmlFor('/suppliers/purchase-quotes/create'),
        );
    }

    public function test_a_customer_document_is_labelled_bill_to(): void
    {
        $this->assertStringContainsString(
            "partyLabel: 'Bill to'",
            $this->htmlFor('/sales/quotes/create'),
        );
    }

    /**
     * Delivery notes carry no money, so they have no price/discount/tax columns
     * and no totals block. The preview used to render the monetary table
     * regardless, leaving three permanently empty columns.
     */
    public function test_a_delivery_note_preview_omits_the_monetary_columns(): void
    {
        $html = $this->htmlFor('/sales/delivery-notes/create');

        $this->assertStringContainsString('pricing: false', $html);
        $this->assertStringContainsString("footer: 'Prepared for shipment.'", $html);
    }

    public function test_a_monetary_document_preview_keeps_the_pricing_columns(): void
    {
        $this->assertStringContainsString(
            'pricing: true',
            $this->htmlFor('/sales/invoices/create'),
        );
    }

    public function test_only_documents_with_a_due_date_show_one(): void
    {
        // A delivery note has no payment terms, so a "Due:" line was noise.
        $this->assertStringContainsString(
            'showDueDate: false',
            $this->htmlFor('/sales/delivery-notes/create'),
        );

        $this->assertStringContainsString(
            'showDueDate: true',
            $this->htmlFor('/sales/invoices/create'),
        );
    }

    /**
     * The manual number is typed on the form, so the preview has to read it or
     * the user sees a document without the reference they just entered.
     */
    public function test_the_preview_reads_the_manual_number(): void
    {
        $html = $this->htmlFor('/sales/invoices/create');

        $this->assertStringContainsString('name="reference_number"', $html);

        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            "form.querySelector('[name=reference_number]')",
            $source,
        );
    }
}
