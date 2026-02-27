# AI Pipeline Demo

A minimal Symfony application that demonstrates [`survos/ai-pipeline-bundle`](https://packagist.org/packages/survos/ai-pipeline-bundle) by running configurable AI pipelines against a list of images and publishing the results as a static site on **GitHub Pages**.

**Live demo:** https://tacman.github.io/ai-pipeline-demo/ _(once GitHub Pages is enabled)_

---

## What it does

1. You maintain `public/data/images.json` — a list of image URLs, titles, and which pipeline tasks to run on each.
2. `bin/console app:process` runs each entry through the pipeline and writes `public/data/{sha1}.json` result files.
3. GitHub Actions runs the command on push (or on a schedule), commits the result files, and deploys `public/` to GitHub Pages.
4. The static site (`index.html` + `viewer.html`) reads the JSON files via `fetch()` — no server needed.

```
images.json  →  app:process  →  {sha1}.json files  →  GitHub Pages  →  browser
```

---

## Project structure

```
public/
├── index.html              ← gallery of all items in images.json
├── viewer.html             ← per-item pipeline result viewer
└── data/
    ├── images.json         ← manifest (source of truth, committed to repo)
    └── {sha1}.json         ← result files (generated, also committed)

src/
├── Command/
│   └── ProcessImagesCommand.php   ← bin/console app:process
└── Task/
    ├── AbstractAgentTask.php
    ├── OcrMistralTask.php
    ├── ClassifyTask.php
    ├── BasicDescriptionTask.php
    ├── KeywordsTask.php
    ├── SummarizeTask.php
    ├── ExtractMetadataTask.php
    ├── GenerateTitleTask.php
    ├── PeopleAndPlacesTask.php
    └── TranscribeHandwritingTask.php

.github/workflows/
└── pipeline.yml            ← runs app:process + deploys to Pages
```

---

## Local setup

### 1. Clone and install

```bash
git clone https://github.com/tacman/ai-pipeline-demo
cd ai-pipeline-demo
composer install
```

### 2. Configure API keys

```bash
cp .env .env.local
# edit .env.local:
OPENAI_API_KEY=sk-...
MISTRAL_API_KEY=...
```

### 3. Edit the manifest

`public/data/images.json` lists subjects and pipelines:

```json
[
  {
    "url": "https://iiif.digitalcommonwealth.org/iiif/2/commonwealth:pz50hp570/full/,1200/0/default.jpg",
    "title": "Stars & Stripes / Burbee Gum trading card",
    "collection": "Digital Commonwealth",
    "pipeline": ["ocr_mistral", "classify", "basic_description", "keywords"]
  }
]
```

Available tasks: `ocr_mistral`, `classify`, `basic_description`, `keywords`, `summarize`, `extract_metadata`, `generate_title`, `people_and_places`, `transcribe_handwriting`

### 4. Run the pipeline

```bash
# Process all entries (skip already-done tasks)
bin/console app:process

# Re-run everything from scratch
bin/console app:process --force

# Process only the first entry
bin/console app:process --limit=1

# Verbose — show task progress
bin/console app:process -v

# Override tasks for all entries
bin/console app:process --tasks=ocr_mistral,classify
```

### 5. View results locally

```bash
php -S localhost:8080 -t public/
# open http://localhost:8080
```

---

## GitHub Pages deployment

### One-time setup

1. Push the repo to GitHub.
2. Go to **Settings → Pages → Source** and select **GitHub Actions**.
3. Add secrets under **Settings → Secrets → Actions**:
   - `OPENAI_API_KEY`
   - `MISTRAL_API_KEY`
   - `APP_SECRET` (any random string, e.g. `openssl rand -hex 16`)

### How it works

The workflow (`.github/workflows/pipeline.yml`) runs on:
- Every push to `main` that touches `images.json`, `src/`, or `config/`
- A weekly schedule (Monday 04:00 UTC)
- Manual dispatch from the GitHub Actions UI (with optional `--force` and `--limit`)

It:
1. Installs PHP/Composer dependencies
2. Runs `bin/console app:process` with the relevant flags
3. Commits any new/updated `public/data/*.json` files back to `main`
4. Deploys `public/` to GitHub Pages

### Manual trigger

Go to **Actions → Run pipelines & deploy to GitHub Pages → Run workflow**.
You can tick "Re-run all tasks" or set a limit for testing.

---

## Adding your own images

Edit `public/data/images.json`. Any publicly accessible URL works — IIIF, S3, direct JPEG, etc.

```json
{
  "url": "https://example.com/scan.jpg",
  "title": "My document",
  "collection": "My archive",
  "pipeline": ["ocr_mistral", "classify", "extract_metadata", "generate_title"]
}
```

Commit and push — the workflow runs automatically.

---

## Adding your own tasks

Implement `AiTaskInterface` (or extend `AbstractAgentTask`), register an agent in `config/packages/ai.yaml`, and add the task name to your pipeline entries.

```php
final class MyCustomTask extends AbstractAgentTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.my_agent')]
        AgentInterface $agent,
    ) { parent::__construct($agent); }

    public function getTask(): string { return 'my_custom'; }

    protected function systemPrompt(array $inputs, array $priorResults): string
    {
        return 'You are a specialist. Return JSON: {"result":"..."}.';
    }

    protected function userPrompt(array $inputs, array $priorResults): string
    {
        return 'Analyse this item. Return only JSON.';
    }
}
```

---

## The viewer

`public/viewer.html` is a zero-dependency static page. Open it with `?url=<subject-url>`:

```
viewer.html?url=https://iiif.example.org/item/full/,1200/0/default.jpg
```

It:
- Computes `sha1(url)` in the browser via Web Crypto API
- Fetches `data/{sha1}.json`
- Shows a sidebar with task badges (done / skipped / failed)
- Renders each task's fields clearly (text, description, keywords, tokens used, etc.)
- Displays **extracted sub-images** (e.g. regions identified by Mistral OCR) as clickable thumbnails — clicking one opens the viewer for *that* artifact's own pipeline results

---

## Relationship to the bundle

This repo is an example consumer of `survos/ai-pipeline-bundle`. The bundle provides:
- `AiTaskInterface` and `AiTaskRegistry`
- `AiPipelineRunner` (stateful, resumable)
- `JsonFileResultStore` / `ArrayResultStore`
- `ai:pipeline:run` and `ai:pipeline:tasks` console commands

This repo adds:
- Concrete task implementations (`src/Task/`)
- The `app:process` command that drives the pipeline from `images.json`
- The static gallery + viewer front-end
- The GitHub Actions deployment workflow
