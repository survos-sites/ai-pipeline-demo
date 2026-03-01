# AI Pipeline Demo

A Symfony application that demonstrates [`survos/ai-pipeline-bundle`](https://packagist.org/packages/survos/ai-pipeline-bundle) by running configurable AI pipelines against documents (images and PDFs) and publishing the results as a static site on **GitHub Pages**.

**Live demo:** https://survos-sites.github.io/ai-pipeline-demo/

---

## What it does

1. You maintain manifests (`public/data/images.json` and `public/data/pdfs.json`) listing document URLs, titles, and which pipeline tasks to run.
2. `bin/console app:add <URL>` fetches metadata from Omeka-S, NARA, or direct URLs and appends to the appropriate manifest.
3. `bin/console app:process` runs each entry through the pipeline and writes `public/data/{sha1}.json` result files.
4. GitHub Actions runs the command on push, commits results, splits PDFs into page images, and deploys `public/` to GitHub Pages.
5. The static site (`index.html` + `item.html`) reads the JSON files via `fetch()` — no server needed.

```
manifests → app:process → {sha1}.json sidecar files → GitHub Pages → browser
```

---

## Project structure

```
public/
├── index.html              ← gallery (Documents section on top, Images below)
├── item.html               ← per-item viewer (magnifier, PDF page viewer, task results)
├── data/
│   ├── images.json         ← image manifest (8 entries)
│   ├── pdfs.json           ← PDF manifest (2+ entries)
│   ├── {sha1}.json         ← per-item result files (generated, committed)
│   └── {sha1}-transcript.txt  ← human transcript sidecars (committed)
└── images/
    ├── *.jpg               ← local images (committed)
    └── pages/              ← split PDF page images (gitignored, built in CI)

src/
├── Command/
│   ├── ProcessImagesCommand.php  ← bin/console app:process
│   └── AddDocumentCommand.php    ← bin/console app:add
└── Task/
    └── EstimateValueTask.php     ← example custom task (not in bundle)

config/packages/
├── ai.yaml                 ← AI agent definitions (openai, mistral)
└── survos_ai_pipeline.yaml ← bundle config (store_dir, disabled_tasks)

templates/
├── bundles/SurvosAiPipelineBundle/prompt/
│   └── keywords/           ← overridden keywords prompt (collectibles focus)
└── ai/prompt/
    └── estimate_value/     ← custom task prompt templates

.github/workflows/
└── pipeline.yml            ← CI: process → split PDFs → deploy to Pages
```

---

## Commands

### `app:add` — Add documents to the manifest

```bash
# Omeka-S item page → fetches metadata via API, resolves PDF/image URL
bin/console app:add https://iaamcfh.omeka.net/s/IAAM_CFH/item/3940

# National Archives catalog → fetches via proxy API, finds page images
bin/console app:add https://catalog.archives.gov/id/5939992

# Direct PDF or image URL
bin/console app:add https://example.com/document.pdf

# Preview without writing
bin/console app:add --dry-run https://iaamcfh.omeka.net/s/IAAM_CFH/item/3940
```

The command:
- Detects the URL type (Omeka-S, NARA, direct file)
- Fetches metadata from the source API (title, description, dates, etc.)
- Detects video items and rejects them with a message
- Presents pipeline presets to choose from:
  - `handwritten_document` — OCR + handwriting annotation + NER + metadata
  - `printed_document` — OCR + classify + summarize + keywords
  - `photograph_or_card` — OCR + classify + description + keywords
  - `full_analysis` — everything
- Appends to `images.json` or `pdfs.json` based on media type
- Checks for duplicates

### `app:process` — Run the AI pipeline

```bash
# Process all manifests (default: images.json + pdfs.json)
bin/console app:process

# Process only PDFs
bin/console app:process -m pdfs.json

# Re-run everything from scratch
bin/console app:process --force

# Process first entry only
bin/console app:process --limit=1

# Override tasks for all entries
bin/console app:process --tasks=ocr_mistral,classify
```

---

## Local setup

### 1. Clone and install

```bash
git clone https://github.com/survos-sites/ai-pipeline-demo
cd ai-pipeline-demo
composer install
```

### 2. Configure API keys

```bash
cp .env .env.local
# Edit .env.local:
OPENAI_API_KEY=sk-...
MISTRAL_API_KEY=...
```

### 3. Add a document

```bash
bin/console app:add https://iaamcfh.omeka.net/s/IAAM_CFH/item/3940
# Select pipeline: handwritten_document
```

### 4. Run the pipeline

```bash
bin/console app:process
```

### 5. View results locally

```bash
php -S localhost:8080 -t public/
# Open http://localhost:8080
```

---

## Manifest format

### images.json

```json
[
    {
        "url": "https://example.com/scan.jpg",
        "title": "My document",
        "collection": "My archive",
        "provenance": "https://example.com/item/123",
        "pipeline": ["ocr_mistral", "classify", "extract_metadata", "generate_title"],
        "result_file": "abc123...json",
        "status": "complete"
    }
]
```

### pdfs.json

```json
[
    {
        "url": "https://example.com/document.pdf",
        "provenance": "https://example.com/item/456",
        "title": "Historical document",
        "collection": "Archive Name",
        "pipeline": ["ocr_mistral", "annotate_handwriting", "transcribe_handwriting"],
        "metadata": { ... },
        "transcript_file": "abc123...-transcript.txt",
        "result_file": "abc123...json",
        "status": "complete"
    }
]
```

Fields:
- `url` — direct URL to the image or PDF (required)
- `provenance` — link back to the source catalog page
- `title` — display title
- `collection` — collection name for grouping
- `pipeline` — ordered list of task names to run
- `metadata` — structured metadata from the source (Omeka dcterms, NARA fields, etc.)
- `transcript_file` — path to human transcript sidecar (for comparison with AI OCR)
- `result_file` — auto-populated by `app:process` (SHA1 of URL + `.json`)
- `status` — auto-populated (`complete` or `pending`)

---

## Available pipeline tasks

| Task | What it does | Best for |
|---|---|---|
| `ocr_mistral` | Mistral OCR — markdown + layout blocks + image crops | All documents |
| `classify` | Document type classification | Printed docs, photos |
| `basic_description` | Visual description | Photos, cards |
| `summarize` | Concise summary | Printed documents |
| `keywords` | Keyword tags | All documents |
| `extract_metadata` | Dates, people, places, subjects | All documents |
| `generate_title` | Archival-style title | All documents |
| `people_and_places` | Named entity extraction | Historical documents |
| `transcribe_handwriting` | Handwriting transcription | Handwritten documents |
| `annotate_handwriting` | Mark `<hw>` handwritten / `<?>` uncertain | After OCR on handwritten docs |
| `translate` | Translation to English | Non-English documents |

---

## Adding custom tasks

Implement `AiTaskInterface` or extend `AbstractVisionTask`:

```php
// src/Task/EstimateValueTask.php
final class EstimateValueTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
        TwigEnvironment $twig,
        HttpClientInterface $httpClient,
    ) {
        parent::__construct($agent, $twig, $httpClient);
    }

    public function getTask(): string { return 'estimate_value'; }
}
```

Create prompt templates at `templates/ai/prompt/estimate_value/{system,user}.html.twig`.

The task auto-registers via Symfony autoconfiguration. Add `"estimate_value"` to any manifest entry's `pipeline` array.

---

## GitHub Pages deployment

### One-time setup

1. Push to GitHub.
2. **Settings > Pages > Source**: select **GitHub Actions**.
3. **Settings > Secrets > Actions**: add `OPENAI_API_KEY`, `MISTRAL_API_KEY`, `APP_SECRET`.

### How the workflow works

`.github/workflows/pipeline.yml` triggers on:
- Push to `main` (when manifests, HTML, src, config, or workflows change)
- Weekly schedule (Monday 04:00 UTC)
- Manual dispatch (with optional `--force` and `--limit`)

Steps:
1. Install PHP 8.4 + Composer
2. Run `bin/console app:process`
3. Commit updated `public/data/*.json` back to `main`
4. Fetch remote assets (PDFs referenced by URL)
5. Split PDFs into page images with `pdftoppm` + `mogrify` (cached by `pdfs.json` hash)
6. Deploy `public/` to GitHub Pages

---

## The viewer

`item.html` is opened with `?url=<subject-url>&type=image|pdf`:

**For images**: magnifier overlay (4x zoom on hover) + task result sections below.

**For PDFs**: page-by-page side-by-side view (page image left, OCR text right) with:
- Per-page magnifier
- Proportional text scaling toggle ("Align text to image")
- Handwriting annotations: `<hw>` yellow italic for handwritten text, `<?>` red superscript for uncertain words
- Per-page summary in heading dividers

All task results are shown with token usage pills, done/failed badges, and collapsible raw JSON.

---

## Supported metadata sources

| Source | Detection | API used |
|---|---|---|
| Omeka-S | `*.omeka.net/s/*/item/*` | `/api/items/{id}` + `/api/media/{id}` |
| National Archives | `catalog.archives.gov/id/*` | `/proxy/records/search?naId_is=*` |
| Direct URL | `.pdf`, `.jpg`, `.png`, etc. | None (URL used directly) |

---

## Relationship to the bundle

This repo is an example consumer of [`survos/ai-pipeline-bundle`](https://github.com/survos/ai-pipeline-bundle). The bundle provides:

- `AiTaskInterface`, `AiTaskRegistry`, `AiPipelineRunner`
- 14 built-in tasks (OCR, classify, describe, extract, summarize, etc.)
- `JsonFileResultStore` / `ArrayResultStore`
- `ai:pipeline:run` and `ai:pipeline:tasks` CLI commands
- Twig prompt templates with app-level override support

This repo adds:
- `app:process` — manifest-driven batch processor
- `app:add` — interactive document ingestion with Omeka/NARA API support
- `EstimateValueTask` — example custom app-level task
- Static gallery + viewer front-end
- GitHub Actions deployment workflow
- Human transcript sidecars for AI vs human comparison

For integrating the bundle into a database-backed app (like scanstation), see the bundle's [integration guide](https://github.com/survos/ai-pipeline-bundle/blob/main/docs/integration.md).
