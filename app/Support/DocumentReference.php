<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The optional manual / external number a business can put on a document.
 *
 * Most companies issue documents from a system they already used and quote that
 * reference everywhere — on the document itself, in correspondence, and to their
 * accountant. This keeps the sequential system number intact (it is the
 * internal identity used by counters, PDF filenames and exports) and stores the
 * user-supplied reference alongside it.
 *
 * Validation is centralised so every document type enforces the same rules.
 * The important one is uniqueness per table: two invoices carrying the same
 * external reference is always a data-entry mistake, and it is invisible until
 * someone tries to find the second one.
 */
class DocumentReference
{
    /** Long enough for "INV-OS-2026-004471"; short enough to stay an index key. */
    public const MAX_LENGTH = 120;

    /**
     * Validation rules for a document's manual reference number.
     *
     * @return array<int, mixed>
     */
    public static function rules(string $table, ?int $ignoreId = null): array
    {
        return [
            'nullable',
            'string',
            'max:'.self::MAX_LENGTH,
            // Case-insensitive uniqueness. A reference is an external identifier
            // typed by hand, so "inv-4471" and "INV-4471" are the same document
            // to the person looking for it, and MySQL's default collation is
            // already case-insensitive — this rule just makes the intent explicit.
            Rule::unique($table, 'reference_number')->ignore($ignoreId),
        ];
    }

    /**
     * Normalise a submitted reference.
     *
     * Whitespace is trimmed and an empty string collapses to null, so a field
     * the user opened and cleared is stored as "no reference" rather than as an
     * empty string that would then fail the unique index against a second blank
     * submission.
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The value to persist, given the submitted field.
     */
    public static function fromRequest(mixed $value): ?string
    {
        return self::normalise($value);
    }

    /**
     * The label to show in a form, including what the auto-generated number
     * would be, so the user can see there are two distinct numbers rather than
     * being asked to replace one.
     */
    public static function hint(string $generated): string
    {
        return 'Optional. The system number "'.$generated.'" is still used for internal references. '
            .'Use this field only if the document carries a number from another system, e.g. INV-OS-4471.';
    }
}
