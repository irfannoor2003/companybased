<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Long values must stay visible while someone is typing a document.
 *
 * The create forms used to be capped at 1152px (and edit forms at roughly two
 * thirds of that), with three cramped header columns and a line-item grid that
 * squeezed a seven-field row into whatever was left. These tests pin the wider
 * containers, the two-column header fields and the horizontal-scroll wrapper
 * that lets an operator slide across a wide row instead of losing the start of
 * a description.
 */
class DocumentFormWidthTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    public static function documentForms(): array
    {
        return [
            '/sales/invoices/create',
            '/sales/orders/create',
            '/sales/quotes/create',
            '/sales/credit-notes/create',
            '/sales/delivery-notes/create',
            '/suppliers/purchase-invoices/create',
            '/suppliers/purchase-orders/create',
            '/suppliers/purchase-quotes/create',
            '/suppliers/debit-notes/create',
            '/sales/withholding-tax-receipts/create',
        ];
    }

    /** Forms with a repeating line-item grid -- the ones that need to slide sideways. */
    public static function lineItemForms(): array
    {
        return [
            '/sales/invoices/create', '/sales/orders/create', '/sales/quotes/create',
            '/sales/credit-notes/create', '/sales/delivery-notes/create',
            '/suppliers/purchase-invoices/create', '/suppliers/purchase-orders/create',
            '/suppliers/purchase-quotes/create', '/suppliers/debit-notes/create',
        ];
    }

    public function test_create_forms_are_wide_and_line_items_scroll_horizontally(): void
    {
        foreach (self::documentForms() as $path) {
            $html = $this->actingAs($this->admin())->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('max-w-none', $html, $path.' still carries a narrow container cap');
            $this->assertStringNotContainsString('max-w-6xl', $html);
        }

        foreach (self::lineItemForms() as $path) {
            $html = $this->actingAs($this->admin())->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('document-form-scroll', $html, $path.' has no horizontal scroll wrapper');
            $this->assertStringContainsString('document-form-inner', $html, $path.' has no minimum row width');
        }
    }

    public function test_header_fields_use_two_wide_columns_rather_than_three_cramped_ones(): void
    {
        foreach (self::documentForms() as $path) {
            $html = $this->actingAs($this->admin())->get($path)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'sm:grid-cols-2 lg:grid-cols-3',
                $html,
                $path.' still packs its header fields into three columns',
            );
        }
    }

    public function test_edit_forms_give_the_editor_three_of_four_columns(): void
    {
        $views = [
            'sales/invoices/edit', 'sales/quotes/edit', 'sales/orders/edit',
            'sales/credit_notes/edit', 'sales/delivery_notes/edit', 'sales/recurring_invoices/edit',
            'suppliers/purchase_orders/edit', 'suppliers/purchase_invoices/edit',
            'suppliers/purchase_quotes/edit',
        ];

        foreach ($views as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));

            $this->assertStringContainsString('lg:grid-cols-4', $source, $view.' still reserves only three columns');
            $this->assertStringContainsString('lg:col-span-3', $source, $view.' editor is still two thirds wide');
        }
    }

    public function test_the_global_container_and_scrollbar_styles_support_wide_forms(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('max-w-[1600px]', $layout);

        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.document-form-scroll', $css);
        $this->assertStringContainsString('overflow-x-auto', $css);
        $this->assertStringContainsString('min-width: 1180px', $css);
    }
}
