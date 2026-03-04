<?php

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\capture;
use function Castor\io;
use function Castor\run;

#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');

    io()->title(sprintf('Hello %s!', $currentUser));
}

#[AsTask(description: 'Build missing ranged LVA PDFs from manifest')]
function build_lva(
    #[AsOption(description: 'Manifest path')] string $manifest = 'public/data/pdfs.json',
    #[AsOption(description: 'Public directory')] string $publicDir = 'public',
    #[AsOption(description: 'Rebuild existing outputs')] bool $force = false,
    #[AsOption(description: 'Do not write files')] bool $dryRun = false,
): void {
    $cmd = ['python3', 'bin/build_lva_pdfs.py', '--manifest', $manifest, '--public-dir', $publicDir];
    if ($force) {
        $cmd[] = '--force';
    }
    if ($dryRun) {
        $cmd[] = '--dry-run';
    }

    run($cmd);
}

#[AsTask(description: 'Apply recommended Git LFS tracking rules')]
function lfs_setup(): void
{
    run(['git', 'lfs', 'install', '--local']);
    run(['git', 'lfs', 'track', 'public/images/*.png']);
    run(['git', 'lfs', 'track', 'public/images/*.jpg']);
    run(['git', 'lfs', 'track', 'public/images/*.jpeg']);
    run(['git', 'lfs', 'track', 'public/images/*.pdf']);
    run(['git', 'lfs', 'track', 'public/images/lva/*.pdf']);

    io()->success('Git LFS tracking rules updated. Review and commit .gitattributes.');
}

#[AsTask(description: 'Prep local workspace to mirror CI PDF inputs')]
function pipeline_ci_prep(
    #[AsOption(description: 'Manifest path')] string $manifest = 'public/data/pdfs.json',
    #[AsOption(description: 'Public directory')] string $publicDir = 'public',
    #[AsOption(description: 'Rebuild existing outputs')] bool $force = false,
    #[AsOption(description: 'Do not write files')] bool $dryRun = false,
): void {
    build_lva($manifest, $publicDir, $force, $dryRun);

    $cmd = [
        'python3',
        '-c',
        'import json, os, sys; m=sys.argv[1]; p=sys.argv[2];\n'
        . 'missing=[]\n'
        . 'entries=json.load(open(m, encoding="utf-8")) if os.path.isfile(m) else []\n'
        . 'for e in entries:\n'
        . '  u=(e.get("url") or "").strip()\n'
        . '  if u and not u.startswith("http") and u.lower().endswith(".pdf"):\n'
        . '    fp=os.path.join(p,u)\n'
        . '    if not os.path.isfile(fp): missing.append(fp)\n'
        . 'print("Missing local PDFs:") if missing else print("All local PDF manifest paths are present.")\n'
        . '[print(" - "+x) for x in missing]\n'
        . 'sys.exit(1 if missing else 0)',
        $manifest,
        $publicDir,
    ];

    run($cmd);
    io()->success('CI prep complete. Local PDF inputs match manifest references.');
}

#[AsTask(description: 'Generate page JPGs for PDFs in manifest')]
function warm_pages(
    #[AsOption(description: 'Manifest path')] string $manifest = 'public/data/pdfs.json',
    #[AsOption(description: 'Public directory')] string $publicDir = 'public',
    #[AsOption(description: 'Render DPI')] int $dpi = 200,
    #[AsOption(description: 'Rebuild existing outputs')] bool $force = false,
    #[AsOption(description: 'Skip whitespace crop')] bool $noCrop = false,
    #[AsOption(description: 'Do not write files')] bool $dryRun = false,
): void {
    $cmd = [
        'python3',
        'bin/split_pdf_pages.py',
        '--manifest', $manifest,
        '--public-dir', $publicDir,
        '--dpi', (string) $dpi,
    ];
    if ($force) {
        $cmd[] = '--force';
    }
    if ($noCrop) {
        $cmd[] = '--no-crop';
    }
    if ($dryRun) {
        $cmd[] = '--dry-run';
    }

    run($cmd);
}

#[AsTask(description: 'One-shot local preview prep (CI prep + warm pages)')]
function pipeline_preview_prep(
    #[AsOption(description: 'Manifest path')] string $manifest = 'public/data/pdfs.json',
    #[AsOption(description: 'Public directory')] string $publicDir = 'public',
    #[AsOption(description: 'Render DPI')] int $dpi = 200,
    #[AsOption(description: 'Rebuild existing outputs')] bool $force = false,
    #[AsOption(description: 'Skip whitespace crop')] bool $noCrop = false,
    #[AsOption(description: 'Do not write files')] bool $dryRun = false,
): void {
    pipeline_ci_prep($manifest, $publicDir, $force, $dryRun);
    warm_pages($manifest, $publicDir, $dpi, $force, $noCrop, $dryRun);
    io()->success('Preview prep complete. PDFs and page JPGs are ready.');
}
