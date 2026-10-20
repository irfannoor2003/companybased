<?php

namespace App\Support;

/**
 * The invoice template NAMES accepted by the editor.
 *
 * WHAT CHANGED
 *
 * This used to map a name to one of four standalone views. All four are gone: every
 * stored name now resolves to the same shared design, and the invoice renders through
 * `documents/pdf.blade.php` like every other document.
 *
 * The names are kept because invoices already in the database carry one in
 * `sales_invoices.template`, and because the editor's picker offers a choice. Removing
 * the accepted values would make every existing invoice unsaveable for the sake of a
 * tidy enum.
 *
 * The choice the four names appear to offer is now made where it actually takes
 * effect -- Settings -> Templates, which changes the style and the blocks the shared
 * design prints with. Two template systems is one too many.
 */
class InvoiceTemplate
{
    public const CLASSIC = 'classic';

    public const MODERN = 'modern';

    public const MINIMAL = 'minimal';

    public const CORPORATE = 'corporate';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::CLASSIC, self::MODERN, self::MINIMAL, self::CORPORATE];
    }

    /**
     * The view an invoice prints from.
     *
     * Every name resolves to the same document design, and the stored template name is
     * ignored. Kept as a method so a caller reaching for the old seam lands on the
     * single renderer rather than on a removed per-template stylesheet.
     */
    public static function view(string $template): string
    {
        return 'documents.pdf';
    }
}
