"""Rasterise the preview PDFs so they can be looked at.

The test suite proves a PDF is produced and that it contains the right words. It
cannot prove a page looks right, and that gap has cost real defects: the rail
painting over the first character of every heading, and the totals block running
off the right edge so "180.00" printed as "180.0". Both passed every test. Only a
rendered page shows either.

    pip install pypdfium2

    python render_invoice_pdf.py                             every preview-*.pdf
    python render_invoice_pdf.py storage/app/preview-long.pdf   one file
    python render_invoice_pdf.py --scale=2 preview-modern.pdf   bigger, for detail

Output is written beside the PDF: preview-modern.pdf -> preview-modern_p1.png,
preview-modern_p2.png, and so on for every page.

A development tool, not production code. Keep it -- it is the only way to verify
PDF layout without a browser.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

try:
    import pypdfium2 as pdfium
except ImportError:  # pragma: no cover - developer convenience
    sys.exit("pypdfium2 is not installed. Run: pip install pypdfium2")


DEFAULT_PATTERN = "preview-*.pdf"
SEARCH_DIR = Path(__file__).resolve().parent / "storage" / "app"


def find_pdfs(patterns: list[str]) -> list[Path]:
    """Expand arguments to a list of PDFs.

    A bare filename is looked for beside the script as well as in the current
    directory, because the README's own example passes a storage/app path and
    typing it out in full every time is the kind of friction that stops people
    doing the visual check at all.
    """
    found: list[Path] = []

    for pattern in patterns:
        candidate = Path(pattern)
        directory = candidate.parent if str(candidate.parent) != "" else Path(".")

        if candidate.exists():
            found.append(candidate)
            continue

        # A literal filename that is not where we looked: try the bases, then treat
        # the pattern as a glob. `Path(...) / "preview-*.pdf"` is a literal name, so
        # a wildcard has to go through glob() rather than through exists().
        for base in (directory.resolve(), SEARCH_DIR, Path.cwd()):
            match = base / candidate.name
            if match.exists():
                found.append(match)
                break
        else:
            matches = sorted(SEARCH_DIR.glob(candidate.name)) or sorted(Path.cwd().glob(candidate.name))

            if not matches:
                sys.exit(f"no such PDF: {pattern}")

            found.extend(matches)

    # De-duplicate while keeping order.
    seen: set[Path] = set()
    unique: list[Path] = []
    for path in found:
        resolved = path.resolve()
        if resolved not in seen:
            seen.add(resolved)
            unique.append(path)

    return unique


def render(pdf_path: Path, scale: float) -> list[Path]:
    document = pdfium.PdfDocument(str(pdf_path))
    written: list[Path] = []

    for index in range(len(document)):
        page = document[index]
        bitmap = page.render(scale=scale)
        image = bitmap.to_pil()

        target = pdf_path.with_name(f"{pdf_path.stem}_p{index + 1}.png")
        image.save(target)
        written.append(target)

        print(f"  page {index + 1}/{len(document)}  {image.width}x{image.height}  {target.name}")

    return written


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pdfs", nargs="*", default=None, help="PDF files to rasterise")
    parser.add_argument("--scale", type=float, default=1.5, help="render scale (default 1.5)")
    parser.add_argument("--pattern", default=DEFAULT_PATTERN, help=f"glob when no file is named (default {DEFAULT_PATTERN})")
    args = parser.parse_args()

    targets = find_pdfs(args.pdfs or [args.pattern])

    if not targets:
        sys.exit(f"nothing to render: no {args.pattern} in {SEARCH_DIR}")

    print(f"Rasterising {len(targets)} file(s) at scale {args.scale}\n")

    for target in targets:
        print(f"{target.name}")
        render(target, args.scale)
        print()

    print("Open the PNGs. This is the check that catches what no test can.")


if __name__ == "__main__":
    main()
