"""
Rasterise PDFs to PNG so they can be inspected visually.

    python render_invoice_pdf.py storage/app/preview-doc-salesquote.pdf

Renders every page of every PDF passed on the command line, or every
preview-*.pdf in storage/app when none are given. Writes the PNGs beside the
PDF with a _p1, _p2 suffix so multi-page documents are reviewable page by
page -- which is the only way to see whether page 2+ loses the design elements
the first page has.
"""

import sys
from pathlib import Path

import pypdfium2 as pdfium


def render(pdf_path: Path) -> int:
    pdf = pdfium.PdfDocument(str(pdf_path))
    stem = pdf_path.with_suffix("")
    pages = len(pdf)

    for index in range(pages):
        page = pdf[index]
        bitmap = page.render(scale=2.0)  # ~150 DPI, readable without zooming
        image = bitmap.to_pil()
        out = stem.parent / f"{stem.name}_p{index + 1}.png"
        image.save(out)
        print(f"  {pdf_path.name} page {index + 1}/{pages} -> {out.name} ({image.width}x{image.height})")

    pdf.close()
    return pages


def main() -> None:
    targets = [Path(a) for a in sys.argv[1:]]

    if not targets:
        targets = sorted(Path("storage/app").glob("preview-*.pdf"))

    if not targets:
        print("no PDFs found. Run: php preview_document_pdfs.php")
        return

    total = 0
    for target in targets:
        if not target.exists():
            print(f"  {target} not found")
            continue
        total += render(target)

    print(f"\n{total} page(s) rendered")


if __name__ == "__main__":
    main()