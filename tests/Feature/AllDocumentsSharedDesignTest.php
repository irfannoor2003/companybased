<?php

namespace Tests\Feature;

use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The print suite has ONE design now. These census assertions stop a second
 * design being introduced quietly: the moment a controller renders a bespoke
 * PDF view again, the shared template stops governing the whole suite.
 */
class AllDocumentsSharedDesignTest extends TestCase
{
    use SeedsDatabase;

    public function test_bespoke_pdf_bodies_are_gone(): void
    {
        foreach ([
            'documents/statement.blade.php',
            'partials/pdf/receipt.blade.php',
            'exports/pdf.blade.php',
        ] as $obsolete) {
            $this->assertFileDoesNotExist(
                resource_path('views/'.$obsolete),
                $obsolete.' is a second design; everything prints through documents/pdf.blade.php',
            );
        }

        $this->assertFileExists(resource_path('views/documents/pdf.blade.php'));
    }

    public function test_every_pdf_controller_uses_the_shared_document_renderer(): void
    {
        $controllers = [
            'app/Http/Controllers/Sales/StatementController.php',
            'app/Http/Controllers/Purchasing/SupplierLedgerController.php',
            'app/Http/Controllers/Employees/PayrollRunController.php',
            'app/Http/Controllers/Pos/ReceiptController.php',
            'app/Support/ExportsCsv.php',
        ];

        foreach ($controllers as $controller) {
            $source = (string) file_get_contents(base_path($controller));

            $this->assertStringContainsString(
                "'documents.pdf'",
                $source,
                $controller.' must render through the shared document view',
            );
        }
    }
}
