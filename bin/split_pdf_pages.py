#!/usr/bin/env python3

import argparse
import glob
import hashlib
import json
import os
import subprocess
import tempfile
import urllib.request


def sha1_hex(value: str) -> str:
    return hashlib.sha1(value.encode("utf-8")).hexdigest()


def split_pages(manifest_path: str, public_dir: str, dpi: int = 200, force: bool = False, crop: bool = True, dry_run: bool = False) -> tuple[int, int, int]:
    if not os.path.isfile(manifest_path):
        return (0, 0, 0)

    with open(manifest_path, "r", encoding="utf-8") as f:
        entries = json.load(f)

    pages_dir = os.path.join(public_dir, "images", "pages")
    os.makedirs(pages_dir, exist_ok=True)

    generated = 0
    skipped = 0
    errors = 0
    changed_prefixes = []

    for entry in entries:
        url = (entry.get("url") or "").strip()
        if not url or not url.lower().endswith(".pdf"):
            continue

        prefix = sha1_hex(url)
        existing = glob.glob(os.path.join(pages_dir, f"{prefix}-*.jpg"))
        if existing and not force:
            skipped += 1
            continue

        if dry_run:
            print(f"dry-run: would split {url} -> {pages_dir}/{prefix}-*.jpg")
            generated += 1
            continue

        try:
            with tempfile.NamedTemporaryFile(prefix="pdf_", suffix=".pdf", delete=False) as tmp:
                tmp_pdf = tmp.name

            try:
                if url.startswith("http://") or url.startswith("https://"):
                    urllib.request.urlretrieve(url, tmp_pdf)
                else:
                    local_pdf = os.path.join(public_dir, url)
                    if not os.path.isfile(local_pdf):
                        raise RuntimeError(f"missing local PDF: {local_pdf}")
                    with open(local_pdf, "rb") as src, open(tmp_pdf, "wb") as dst:
                        dst.write(src.read())

                base = os.path.join(pages_dir, prefix)
                cmd = ["pdftoppm", "-jpeg", "-r", str(dpi), tmp_pdf, base]
                proc = subprocess.run(cmd, capture_output=True, text=True)
                if proc.returncode != 0:
                    raise RuntimeError(proc.stderr.strip())
            finally:
                if os.path.isfile(tmp_pdf):
                    os.unlink(tmp_pdf)

            generated += 1
            changed_prefixes.append(prefix)
            print(f"generated: {prefix} ({url})")
        except Exception as e:
            errors += 1
            print(f"error ({url}): {e}")

    if crop and changed_prefixes and not dry_run:
        files = []
        for prefix in changed_prefixes:
            files.extend(glob.glob(os.path.join(pages_dir, f"{prefix}-*.jpg")))
        if files:
            cmd = ["mogrify", "-fuzz", "10%", "-trim", "+repage", *files]
            proc = subprocess.run(cmd, capture_output=True, text=True)
            if proc.returncode != 0:
                print(f"warning: crop failed: {proc.stderr.strip()}")

    return (generated, skipped, errors)


def main() -> int:
    parser = argparse.ArgumentParser(description="Split PDFs listed in manifest into page JPGs")
    parser.add_argument("--manifest", default="public/data/pdfs.json", help="Path to pdf manifest JSON")
    parser.add_argument("--public-dir", default="public", help="Public web root")
    parser.add_argument("--dpi", type=int, default=200, help="Render DPI for pdftoppm")
    parser.add_argument("--force", action="store_true", help="Rebuild even when page JPGs exist")
    parser.add_argument("--no-crop", action="store_true", help="Skip whitespace auto-crop")
    parser.add_argument("--dry-run", action="store_true", help="Log planned outputs without writing files")
    args = parser.parse_args()

    generated, skipped, errors = split_pages(
        manifest_path=args.manifest,
        public_dir=args.public_dir,
        dpi=args.dpi,
        force=args.force,
        crop=not args.no_crop,
        dry_run=args.dry_run,
    )

    print(f"Split summary: generated={generated} skipped={skipped} errors={errors}")
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
