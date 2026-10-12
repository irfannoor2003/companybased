<?php

namespace Tests\Support;

/**
 * Minimal PDF text extractor, for asserting on what a document actually prints.
 *
 * There is no way to check a rendered PDF in this project without one of these:
 * no Imagick, no Ghostscript, no pdftotext, no PDF parser in vendor. Grepping the
 * raw bytes does not work either -- DomPDF compresses its streams and embeds
 * fonts as Identity-H CID subsets, so "PAGEMARK" appears as glyph indices, not
 * letters.
 *
 * So this decompresses each stream and reads the text back through the ToUnicode
 * CMaps the PDF carries, tracking the current font so each string is decoded with
 * the mapping that produced it.
 *
 * Scope is deliberately narrow: DomPDF output, single-byte and hex strings, the
 * font operators it emits. It is a test helper, not a general PDF library.
 */
final class PdfText
{
    /**
     * Per-page text, in page order.
     *
     * One entry per PAGE, taken from the page tree -- NOT one per content stream.
     * DomPDF emits several streams for a single page (the fixed rail and the
     * paginating body are written separately) and the viewer draws them onto the
     * same sheet, so treating a stream as a page reported a two-line quote as two
     * pages, the second apparently blank. That was a fault in this helper, not in
     * the template -- `/Type /Page` in the file confirms the document was always
     * one page -- and it nearly sent me off to "fix" a document that was correct.
     *
     * @return array<int, string>
     */
    public static function pages(string $pdf): array
    {
        $cmaps = self::fontMaps($pdf);
        $streams = self::streamByObject($pdf);

        $out = [];

        foreach (self::pageContentObjects($pdf) as $contents) {
            $text = '';

            foreach ($contents as $object) {
                $stream = $streams[$object] ?? null;

                if ($stream !== null && str_contains($stream, 'BT')) {
                    $text .= self::decodeStream($stream, $cmaps).' ';
                }
            }

            $out[] = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        }

        return $out;
    }

    /**
     * The content object numbers referenced by each page, in page order.
     *
     * @return array<int, array<int, int>>
     */
    private static function pageContentObjects(string $pdf): array
    {
        $pages = [];

        if (preg_match_all('#(?P<num>\d+)\s+0\s+obj\s*<<(?P<dict>[^<>]*(?:<<[^>]*>>[^<>]*)*)>>#s', $pdf, $objects, PREG_SET_ORDER)) {
            foreach ($objects as $object) {
                $dict = $object['dict'];

                // /Type /Pages is the tree node, not a page.
                if (! str_contains($dict, '/Type /Page') || str_contains($dict, '/Type /Pages')) {
                    continue;
                }

                if (preg_match('#/Contents\s+(\d+)\s+0\s+R#', $dict, $ref)) {
                    $pages[(int) $object['num']] = [(int) $ref[1]];

                    continue;
                }

                // /Contents may be an array of streams.
                if (preg_match('#/Contents\s*\[(.*?)\]#s', $dict, $array)
                    && preg_match_all('/(\d+)\s+0\s+R/', $array[1], $refs)) {
                    $pages[(int) $object['num']] = array_map('intval', $refs[1]);
                }
            }
        }

        // Ordered by the page tree's /Kids array, because object order in the file
        // is not page order.
        $order = [];

        if (preg_match_all('#/Kids\s*\[(.*?)\]#s', $pdf, $kids, PREG_SET_ORDER)) {
            foreach ($kids as $kid) {
                preg_match_all('/(\d+)\s+0\s+R/', $kid[1], $refs);

                foreach ($refs[1] as $ref) {
                    $order[] = (int) $ref;
                }
            }
        }

        $sorted = [];

        foreach ($order as $ref) {
            if (isset($pages[$ref])) {
                $sorted[] = $pages[$ref];
            }
        }

        // Anything the tree did not list, in file order.
        foreach ($pages as $contents) {
            if (! in_array($contents, $sorted, true)) {
                $sorted[] = $contents;
            }
        }

        return $sorted;
    }

    /**
     * Object number => inflated stream body.
     *
     * @return array<int, string>
     */
    private static function streamByObject(string $pdf): array
    {
        $out = [];

        if (! preg_match_all('#(?P<num>\d+)\s+0\s+obj\s*<<(?P<dict>[^<>]*(?:<<[^>]*>>[^<>]*)*)>>\s*stream\r?\n(?P<body>.*?)\r?\nendstream#s', $pdf, $streams, PREG_SET_ORDER)) {
            return $out;
        }

        foreach ($streams as $stream) {
            $body = $stream['body'];

            if (str_contains($stream['dict'], '/FlateDecode')) {
                $inflated = @gzuncompress($body) ?: @gzinflate($body);

                if ($inflated === false) {
                    continue;
                }

                $body = $inflated;
            }

            $out[(int) $stream['num']] = $body;
        }

        return $out;
    }


    /**
     * Every page's text joined together.
     */
    public static function all(string $pdf): string
    {
        return implode("\n", self::pages($pdf));
    }

    /**
     * Font resource name (`/F1`) => [gid => unicode].
     *
     * Each font in the PDF gets its OWN subset and its own ToUnicode CMap, and the
     * same glyph index means different characters in each. Merging them -- which
     * the first version of this did -- decodes page 1 correctly and page 2 into
     * CJK nonsense, because it resolves the second font's codes through the first
     * font's table.
     *
     * So the association is followed properly: `/F1 8 0 R` in the page resources
     * points at font object 8, whose `/ToUnicode 9 0 R` points at the CMap stream
     * that defines it.
     *
     * @return array<string, array<int, string>>
     */
    private static function fontMaps(string $pdf): array
    {
        // object number => the CMap stream it points at, for font objects.
        $fontObjectToCMap = [];

        if (preg_match_all('#(?P<num>\d+)\s+0\s+obj\s*<<(?P<dict>[^>]*?/Type\s*/Font[^>]*?)(?P<tail>[^>]*)>>#s', $pdf, $fonts, PREG_SET_ORDER)) {
            foreach ($fonts as $font) {
                $dict = $font['dict'].$font['tail'];

                if (preg_match('#/ToUnicode\s+(\d+)\s+0\s+R#', $dict, $ref)) {
                    $fontObjectToCMap[(int) $font['num']] = (int) $ref[1];
                }
            }
        }

        // Resource name => font object number, from every /Font resource dict.
        $nameToObject = [];

        if (preg_match_all('#/Font\s*<<(?P<entries>[^>]*)>>#s', $pdf, $resources, PREG_SET_ORDER)) {
            foreach ($resources as $resource) {
                preg_match_all('#/(F\d+|[A-Za-z]+\d*)\s+(\d+)\s+0\s+R#', $resource['entries'], $entries, PREG_SET_ORDER);

                foreach ($entries as $entry) {
                    $nameToObject[$entry[1]] = (int) $entry[2];
                }
            }
        }

        // CMap stream bodies, by object number.
        $cmapBodies = [];

        if (preg_match_all('#(?P<num>\d+)\s+0\s+obj\s*<<(?P<dict>[^<>]*(?:<<[^>]*>>[^<>]*)*)>>\s*stream\r?\n(?P<body>.*?)\r?\nendstream#s', $pdf, $streams, PREG_SET_ORDER)) {
            foreach ($streams as $stream) {
                $body = $stream['body'];

                if (str_contains($stream['dict'], '/FlateDecode')) {
                    $inflated = @gzuncompress($body) ?: @gzinflate($body);

                    if ($inflated === false) {
                        continue;
                    }

                    $body = $inflated;
                }

                if (str_contains($body, 'begincmap')) {
                    $cmapBodies[(int) $stream['num']] = $body;
                }
            }
        }

        $maps = [];

        foreach ($nameToObject as $name => $object) {
            $cmapObject = $fontObjectToCMap[$object] ?? null;

            if ($cmapObject === null || ! isset($cmapBodies[$cmapObject])) {
                continue;
            }

            $maps[$name] = self::parseCMap($cmapBodies[$cmapObject]);
        }

        return $maps;
    }

    /**
     * Parse a ToUnicode CMap into [gid => unicode].
     *
     * @return array<int, string>
     */
    private static function parseCMap(string $cmap): array
    {
        $map = [];

        // beginbfchar: <srcGid> <dstUnicode>
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $chunks, PREG_SET_ORDER)) {
            foreach ($chunks as $chunk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $chunk[1], $pairs, PREG_SET_ORDER);

                foreach ($pairs as $pair) {
                    $map[hexdec($pair[1])] = self::utf16beToUtf8($pair[2]);
                }
            }
        }

        // beginbfrange, simple form: <lo> <hi> <dstStart>
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $chunks, PREG_SET_ORDER)) {
            foreach ($chunks as $chunk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $chunk[1], $ranges, PREG_SET_ORDER);

                foreach ($ranges as $range) {
                    $lo = hexdec($range[1]);
                    $hi = hexdec($range[2]);
                    $start = hexdec(substr($range[3], -4));
                    $prefix = substr($range[3], 0, -4);

                    // Guard against a malformed range claiming the whole space.
                    if ($hi < $lo || ($hi - $lo) > 65535) {
                        continue;
                    }

                    for ($gid = $lo; $gid <= $hi; $gid++) {
                        $map[$gid] = self::utf16beToUtf8($prefix.str_pad(dechex($start + ($gid - $lo)), 4, '0', STR_PAD_LEFT));
                    }
                }

                // beginbfrange, array form: <lo> <hi> [<d1> <d2> ...]
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $chunk[1], $arrays, PREG_SET_ORDER);

                foreach ($arrays as $range) {
                    $lo = hexdec($range[1]);

                    preg_match_all('/<([0-9A-Fa-f]+)>/', $range[3], $items);

                    foreach ($items[1] as $offset => $dst) {
                        $map[$lo + $offset] = self::utf16beToUtf8($dst);
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Decode one content stream, honouring `/Fn Tf` font switches.
     *
     * @param  array<string, array<int, string>>  $cmaps  font resource name => gid map
     */
    private static function decodeStream(string $stream, array $cmaps): string
    {
        $text = '';
        $current = null;

        /**
         * Walk the stream in order so the font in effect is known per string.
         *
         * DomPDF writes text as `[(\L\i\n\e\ \2)] TJ` -- a TJ array of literal
         * strings, each character backslash-escaped -- or as a plain `(...) Tj`.
         * Both are captured; the array brackets only delimit, so the literals inside
         * are read individually.
         */
        $pattern = '~/(?P<font>[A-Za-z0-9]+\s+[\d.]+\s+Tf)'
            .'|(?P<lit>\((?:\\\\.|[^()\\\\])*\))'
            .'|(?P<hex><[0-9A-Fa-f\s]+>)~s';

        preg_match_all($pattern, $stream, $tokens, PREG_SET_ORDER);

        foreach ($tokens as $token) {
            if (($token['font'] ?? '') !== '') {
                // "/F1 7.5 Tf" -- keep the resource name only.
                $parts = preg_split('/\s+/', trim($token['font']));
                $current = (string) ($parts[0] ?? '');

                continue;
            }

            if (($token['lit'] ?? '') !== '') {
                $text .= self::decodeBytes(self::unescapeLiteral($token['lit']), $cmaps, $current);

                continue;
            }

            if (($token['hex'] ?? '') !== '') {
                $hex = preg_replace('/\s+/', '', $token['hex']) ?? '';
                $text .= self::glyphsToText(str_split(trim($hex, '<>'), 4), $cmaps, $current);
            }
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * Decode the bytes of a literal string.
     *
     * DomPDF embeds the font as an Identity-H CID subset and writes the glyph
     * indices into the string as raw bytes, two per glyph. When the subset holds
     * fewer than 256 glyphs the high byte is always zero, so the pairs happen to
     * be ASCII and can be read directly -- but once a document uses more glyphs
     * than that, the codes become real two-byte indices ("L i n e" came out as
     * `4c 06 1b 4b ...`) and only the ToUnicode CMap can decode them.
     *
     * So each pair is looked up in the CMap first, and only then treated as ASCII.
     * Reversing that order is what produced unreadable binary on later pages.
     *
     * @param  array<string, array<int, string>>  $cmaps
     * @param  string|null  $font  the font resource in effect for these bytes
     */
    private static function decodeBytes(string $bytes, array $cmaps, ?string $font): string
    {
        $map = $cmaps[$font] ?? [];

        $out = '';

        foreach (str_split($bytes, 2) ?: [] as $pair) {
            if (strlen($pair) === 1) {
                $pair .= "\0";
            }

            // The font in effect decides the mapping; falling back to any other
            // font's would decode this document's second subset as nonsense.
            if (isset($map[(ord($pair[0]) << 8) | ord($pair[1])])) {
                $out .= $map[(ord($pair[0]) << 8) | ord($pair[1])];

                continue;
            }

            // No entry for this font. A zero high byte is plain ASCII, which is
            // what a small subset produces.
            if ($pair[0] === "\0") {
                $out .= $pair[1];
            }

            // An unmapped pair with a non-zero high byte is not recoverable text;
            // skipping it keeps the words either side of it intact.
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $glyphs
     * @param  array<string, array<int, string>>  $cmaps
     */
    private static function glyphsToText(array $glyphs, array $cmaps, ?string $font): string
    {
        $map = $cmaps[$font] ?? [];
        $out = '';

        foreach ($glyphs as $glyph) {
            if ($glyph === '') {
                continue;
            }

            $gid = hexdec($glyph);
            $out .= $map[$gid] ?? '';
        }

        return $out;
    }

    /**
     * A PDF literal string, unescaped, as plain text.
     */
    private static function unescapeLiteral(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        /**
         * DomPDF escapes every character, so `(\L\i\n\e)` is "Line". An escape
         * outside the recognised set is the literal character itself, which is the
         * PDF rule -- treating `\L` as two characters would corrupt every string.
         */
        $inner = preg_replace_callback(
            '/\\\\(.)/s',
            static function (array $m): string {
                return match ($m[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                    "\n" => '', "\r" => '',
                    default => $m[1],
                };
            },
            $inner,
        ) ?? $inner;

        /**
         * DomPDF writes two-byte glyph indices into a literal string: "Line 1"
         * arrives as `\0L \0i \0n \0e \0  \01`. They are kept here and decoded as
         * pairs by decodeBytes(), which needs the original high bytes to look up
         * the CMap.
         */
        return $inner;
    }

    /**
     * A UTF-16BE code unit (or pair) as UTF-8.
     */
    private static function utf16beToUtf8(string $hex): string
    {
        $hex = ltrim(preg_replace('/\s+/', '', $hex) ?? '', '<>');

        if ($hex === '') {
            return '';
        }

        if (strlen($hex) % 4 !== 0) {
            $hex = str_pad($hex, intdiv(strlen($hex) + 3, 4) * 4, '0', STR_PAD_RIGHT);
        }

        $out = '';

        foreach (str_split($hex, 4) as $unit) {
            $code = hexdec($unit);

            // Surrogate pair.
            if ($code >= 0xD800 && $code <= 0xDBFF) {
                continue;
            }

            $out .= mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE');
        }

        return $out;
    }
}
