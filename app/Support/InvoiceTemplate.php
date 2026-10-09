<?php

namespace App\Support;

/**
 * Which invoice PDF template to render, and where it lives.
 *
 * The four templates are thin skins over one shared skeleton rather than four
 * independent stylesheets. They used to be independent, which is how the classic
 * and modern ones drifted apart on the header rule, the date format and the
 * totals block — fixing one meant editing four files, and they quietly
 * disagreed about what an invoice looked like.
 *
 * Keeping the name-to-path mapping here means a template is added in one place
 * and validated by the same test that proves the others render.
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
     * The view for a template name.
     *
     * Every name resolves to the same document design. The four names used to be
     * four unrelated stylesheets, which is how they came to disagree about the
     * header rule, the date format and the totals block — and why a customer
     * could be shown two different layouts for the same invoice depending on a
     * setting an office worker changed. They are kept as accepted values so
     * invoices already stored with one still render, and so the existing template
     * switcher does not start rejecting saves.
     */
    public static function view(string $template): string
    {
        return 'sales.invoices.partials.pdf-rail';
    }
}
