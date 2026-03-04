#!/usr/bin/env python3

import argparse
import json
import os
import re
import subprocess
import tempfile
import urllib.request


def build_lva_pdfs(manifest_path: str, public_dir: str, force: bool = False, dry_run: bool = False) -> tuple[int, int, int]:
    if not os.path.isfile(manifest_path):
        return (0, 0, 0)

    with open(manifest_path, "r", encoding="utf-8") as f:
        entries = json.load(f)

    generated = 0
    skipped = 0
    errors = 0

    for entry in entries:
        md = entry.get("metadata") or {}
        if md.get("source") != "library_of_virginia_chancery":
            continue

        rel_out = (entry.get("url") or "").strip()
        if not (rel_out.startswith("images/lva/") and rel_out.lower().endswith(".pdf")):
            continue

        out_path = os.path.join(public_dir, rel_out)
        if os.path.isfile(out_path) and not force:
            skipped += 1
            continue

        case_images_url = (md.get("case_images_url") or "").strip()
        pages = md.get("selected_pages") or [1]
        if not case_images_url:
            print(f"skip (missing case_images_url): {rel_out}")
            skipped += 1
            continue

        try:
            html = urllib.request.urlopen(case_images_url, timeout=45).read().decode("utf-8", "ignore")
            media = re.findall(r'myImages\[\d+\]\s*=\s*"([^"]+)"\s*;', html)
            pdfs = [u for u in media if re.search(r"\.pdf($|\?)", u, flags=re.I)]
            if not pdfs:
                print(f"skip (no page PDFs found): {rel_out}")
                skipped += 1
                continue

            selected = []
            for p in pages:
                try:
                    p = int(p)
                except Exception:
                    continue
                if 1 <= p <= len(pdfs):
                    selected.append(pdfs[p - 1])

            if not selected:
                print(f"skip (selected_pages out of range): {rel_out}")
                skipped += 1
                continue

            if dry_run:
                print(f"dry-run: would generate {out_path} ({len(selected)} pages)")
                generated += 1
                continue

            os.makedirs(os.path.dirname(out_path), exist_ok=True)
            with tempfile.TemporaryDirectory(prefix="lva_pdf_") as td:
                parts = []
                for i, url in enumerate(selected, start=1):
                    part = os.path.join(td, f"{i:04d}.pdf")
                    urllib.request.urlretrieve(url, part)
                    parts.append(part)

                if len(parts) == 1:
                    with open(parts[0], "rb") as src, open(out_path, "wb") as dst:
                        dst.write(src.read())
                else:
                    cmd = ["pdfunite", *parts, out_path]
                    proc = subprocess.run(cmd, capture_output=True, text=True)
                    if proc.returncode != 0:
                        raise RuntimeError(proc.stderr.strip())

            generated += 1
            print(f"generated: {out_path} ({len(selected)} pages)")
        except Exception as e:
            errors += 1
            print(f"error ({rel_out}): {e}")

    return (generated, skipped, errors)


def main() -> int:
    parser = argparse.ArgumentParser(description="Build missing merged LVA range PDFs referenced in pdfs.json")
    parser.add_argument("--manifest", default="public/data/pdfs.json", help="Path to pdf manifest JSON")
    parser.add_argument("--public-dir", default="public", help="Public web root")
    parser.add_argument("--force", action="store_true", help="Rebuild even when output PDF exists")
    parser.add_argument("--dry-run", action="store_true", help="Log planned outputs without writing files")
    args = parser.parse_args()

    generated, skipped, errors = build_lva_pdfs(
        manifest_path=args.manifest,
        public_dir=args.public_dir,
        force=args.force,
        dry_run=args.dry_run,
    )

    print(f"LVA build summary: generated={generated} skipped={skipped} errors={errors}")
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
