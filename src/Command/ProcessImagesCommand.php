<?php
declare(strict_types=1);

namespace App\Command;

use Survos\AiPipelineBundle\Storage\JsonFileResultStore;
use Survos\AiPipelineBundle\Task\AiPipelineRunner;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Processes manifests (images.json, pdfs.json) through AI pipelines.
 *
 * For single images: runs all tasks directly against the image URL.
 * For PDFs: splits into per-page JPGs, runs page-level tasks (ocr, annotate,
 * transcribe) per page, then runs doc-level tasks (summarize, classify, etc.)
 * with aggregated page text.
 *
 * Per-page results are stored in {sha1}-page-{nn}.json files.
 * Doc-level results plus page references in {sha1}.json.
 *
 * Usage:
 *   bin/console app:process                    # process all entries
 *   bin/console app:process --limit=1          # process first entry only
 *   bin/console app:process --force            # re-run even if results exist
 *   bin/console app:process -m pdfs.json       # process only PDFs
 *   bin/console app:process --tasks=ocr_mistral
 *   bin/console app:process --tasks=annotate_handwriting --failed-only
 *   bin/console app:process -m images.json --prompt
 */
#[AsCommand('app:process', 'Process manifests through configured AI pipelines')]
final class ProcessImagesCommand extends Command
{
    private const REFUSAL_PHRASES = [
        "i'm sorry",
        'i am sorry',
        "can't assist",
        'cannot assist',
        "can't help with",
        'cannot help with',
    ];

    /**
     * Tasks that run per-page on individual page JPGs (for PDFs).
     * Everything else runs at the document level.
     */
    private const PAGE_LEVEL_TASKS = [
        'ocr_mistral',
        'annotate_handwriting',
        'transcribe_handwriting',
        'layout',
    ];

    public function __construct(
        private readonly AiPipelineRunner  $runner,
        private readonly AiTaskRegistry    $registry,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%/public/data')]
        private readonly string $dataDir,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('manifest', 'm', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Path to manifest JSON (absolute, or relative to public/data/). Can be specified multiple times.',
                ['images.json', 'pdfs.json'])
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'Stop after processing this many entries')
            ->addOption('force', 'f', InputOption::VALUE_NONE,
                'Re-run tasks even if a result file already exists')
            ->addOption('tasks', 't', InputOption::VALUE_REQUIRED,
                'Override the pipeline for all entries (comma-separated task names)')
            ->addOption('failed-only', null, InputOption::VALUE_NONE,
                'Only process entries with failed selected tasks; implies --force')
            ->addOption('prompt', 'p', InputOption::VALUE_NONE,
                'Interactively pick one entry URL from the manifest to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $force    = (bool) $input->getOption('force');
        $limit    = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $taskOver = $input->getOption('tasks');
        $failedOnly = (bool) $input->getOption('failed-only');
        $promptOne = (bool) $input->getOption('prompt');

        if ($promptOne && !$input->isInteractive()) {
            $io->error('--prompt requires an interactive terminal.');
            return Command::FAILURE;
        }

        if ($failedOnly && !$force) {
            $force = true;
            $io->comment('failed-only enabled: forcing selected failed tasks to rerun.');
        }

        $io->title('AI Pipeline — Process');
        $io->writeln(sprintf('Data dir : %s', $this->dataDir));

        $registered = array_keys($this->registry->getTaskMap());
        if ($registered === []) {
            $io->error('No tasks registered. Check your services.yaml / bundle configuration.');
            return Command::FAILURE;
        }
        $io->writeln('Registered tasks: ' . implode(', ', $registered));
        $io->newLine();

        $manifests      = (array) $input->getOption('manifest');
        $totalProcessed = 0;

        foreach ($manifests as $manifest) {
            $manifestPath = $this->resolveManifest($manifest);
            if (!is_file($manifestPath)) {
                $io->warning("Manifest not found: {$manifestPath} — skipping.");
                continue;
            }

            $entries = json_decode(file_get_contents($manifestPath), true);
            if (!is_array($entries)) {
                $io->warning("Invalid JSON in {$manifestPath} — skipping.");
                continue;
            }

            $io->section(sprintf('Manifest: %s (%d entries)', basename($manifestPath), count($entries)));

            $selectedIndex = null;
            if ($promptOne) {
                $selectedIndex = $this->promptForEntryIndex($entries, basename($manifestPath), $io);
                if ($selectedIndex === null) {
                    $io->warning('No selectable entries in manifest — skipping.');
                    continue;
                }
            }

            $processed = 0;

            foreach ($entries as $i => &$entry) {
                if ($selectedIndex !== null && $i !== $selectedIndex) {
                    continue;
                }

                if ($limit !== null && $totalProcessed >= $limit) {
                    break;
                }

                $url      = $entry['url']   ?? null;
                $title    = $entry['title'] ?? $url;
                $pipeline = $taskOver
                    ? array_filter(array_map('trim', explode(',', $taskOver)))
                    : ($entry['pipeline'] ?? $registered);

                if ($url === null) {
                    $io->warning("Entry {$i} has no 'url' — skipping.");
                    continue;
                }

                if ($failedOnly) {
                    $failedTasks = $this->getFailedTasksForEntry($url, $pipeline);
                    if ($failedTasks === []) {
                        if ($selectedIndex !== null && $i === $selectedIndex) {
                            $io->comment('  Selected entry has no failed tasks for current filters — skipping.');
                        }
                        continue;
                    }

                    // If no explicit task override was given, rerun only failed tasks.
                    if (!$taskOver) {
                        $pipeline = $failedTasks;
                    }
                }

                $absoluteUrl = $this->resolveUrl($url);

                if (!$this->isAccessible($absoluteUrl, $io)) {
                    continue;
                }

                $io->writeln(sprintf('  [%d/%d] %s', $i + 1, count($entries), $title));

                $isPdf = $this->isPdf($url);

                if ($isPdf) {
                    $this->processPdfEntry($entry, $url, $absoluteUrl, $pipeline, $force, $io);
                } else {
                    $this->processImageEntry($entry, $url, $absoluteUrl, $pipeline, $force, $io);
                }

                $sha1 = sha1($url);
                $entry['result_file'] = $sha1 . '.json';
                $entry['status']      = 'complete';
                $processed++;
                $totalProcessed++;
            }
            unset($entry);

            // Write updated manifest
            file_put_contents(
                $manifestPath,
                json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );

            $io->comment(sprintf('  %d entries processed in %s.', $processed, basename($manifestPath)));
        }

        $io->newLine();
        $io->success(sprintf('Processed %d entries across %d manifest(s).', $totalProcessed, count($manifests)));

        return Command::SUCCESS;
    }

    private function promptForEntryIndex(array $entries, string $manifestName, SymfonyStyle $io): ?int
    {
        $choices = [];
        $choiceToIndex = [];
        foreach ($entries as $i => $entry) {
            $url = $entry['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }

            $title = trim((string) ($entry['title'] ?? ''));
            $title = $title !== '' ? $title : '(untitled)';
            if (mb_strlen($title) > 70) {
                $title = mb_substr($title, 0, 67) . '...';
            }

            $label = sprintf('%s — %s', $title, $url);
            $choices[] = $label;
            $choiceToIndex[$label] = $i;
        }

        if ($choices === []) {
            return null;
        }

        $selected = $io->choice(
            sprintf('Choose one entry to process from %s', $manifestName),
            $choices,
            $choices[0],
        );

        return $choiceToIndex[$selected] ?? null;
    }

    // ── Image processing (unchanged behaviour) ──────────────────────────────

    private function processImageEntry(
        array &$entry,
        string $url,
        string $absoluteUrl,
        array $pipeline,
        bool $force,
        SymfonyStyle $io,
    ): void {
        $extraInputs = $entry['inputs'] ?? [];
        $store = new JsonFileResultStore(
            $url,
            $this->dataDir,
            array_merge(['image_url' => $absoluteUrl], $extraInputs),
        );

        $priorKeys = array_keys($store->getAllPrior());

        if (!$force && $priorKeys !== []) {
            $pending = array_diff($pipeline, $priorKeys);
            if ($pending === []) {
                $io->comment('    All tasks already complete — skipping (use --force to rerun).');
                return;
            }
            $io->comment(sprintf('    Resuming — %d task(s) remaining: %s', count($pending), implode(', ', $pending)));
        }

        $this->wireCallbacks($io, '    ');
        $queue = array_values($pipeline);
        while ($queue !== []) {
            if ($this->runner->runNext($store, $queue) === null) {
                break;
            }
        }

        $this->flagRefusalOutputs($store, $pipeline, $io, '    ');
    }

    // ── PDF processing (per-page + doc-level) ───────────────────────────────

    private function processPdfEntry(
        array &$entry,
        string $url,
        string $absoluteUrl,
        array $pipeline,
        bool $force,
        SymfonyStyle $io,
    ): void {
        $sha1      = sha1($url);
        $pagesDir  = $this->publicDir . '/images/pages';
        $maxPages  = (int) ($entry['inputs']['max_pages'] ?? 0);

        // ── Step 1: Ensure page JPGs exist ──────────────────────────────────
        $pageFiles = $this->ensurePageImages($url, $sha1, $pagesDir, $io);
        if ($pageFiles === []) {
            $io->warning('    No page images — cannot process PDF per-page.');
            return;
        }

        // Apply max_pages limit
        if ($maxPages > 0 && count($pageFiles) > $maxPages) {
            $pageFiles = array_slice($pageFiles, 0, $maxPages);
            $io->comment(sprintf('    Limited to %d pages (max_pages=%d)', $maxPages, $maxPages));
        }

        $pageCount = count($pageFiles);
        $io->writeln(sprintf('    %d page images ready', $pageCount));

        // ── Classify tasks ──────────────────────────────────────────────────
        $pageTasks = array_values(array_intersect($pipeline, self::PAGE_LEVEL_TASKS));
        $docTasks  = array_values(array_diff($pipeline, self::PAGE_LEVEL_TASKS));

        if ($pageTasks) {
            $io->writeln(sprintf('    Page-level tasks: %s', implode(', ', $pageTasks)));
        }
        if ($docTasks) {
            $io->writeln(sprintf('    Doc-level tasks:  %s', implode(', ', $docTasks)));
        }

        // ── Step 2: Run page-level tasks on each page JPG ───────────────────
        $pageResultFiles = [];

        if ($pageTasks) {
            foreach ($pageFiles as $pageIndex => $pageFile) {
                $pageNum     = $pageIndex + 1;
                $paddedNum   = str_pad((string) $pageNum, strlen((string) $pageCount), '0', STR_PAD_LEFT);
                $pageFileUrl  = 'file://' . $pageFile;
                $pageStoreKey = "{$sha1}-page-{$paddedNum}";

                // Create a store for this page with an explicit key so the file
                // is named {docSha1}-page-{nn}.json (predictable, not double-hashed)
                $pageStore = new JsonFileResultStore(
                    $pageFileUrl,          // subject = the page image
                    $this->dataDir,
                    [
                        'image_url'  => $pageFileUrl,
                        'page_index' => $pageIndex,
                        'document'   => $url,
                    ],
                    $pageStoreKey,         // explicit key for filename
                );

                // Check if all page tasks done
                $pagePrior = array_keys($pageStore->getAllPrior());
                if (!$force && $pagePrior !== []) {
                    $pending = array_diff($pageTasks, $pagePrior);
                    if ($pending === []) {
                        $io->comment(sprintf('    Page %d/%d: all tasks complete — skipping.', $pageNum, $pageCount));
                        $pageResultFiles[] = basename($pageStore->getFilePath());
                        continue;
                    }
                }

                $io->writeln(sprintf('    <info>Page %d/%d</info>', $pageNum, $pageCount));
                $this->wireCallbacks($io, '      ');

                $queue = array_values($pageTasks);
                while ($queue !== []) {
                    if ($this->runner->runNext($pageStore, $queue) === null) {
                        break;
                    }
                }

                $this->flagRefusalOutputs($pageStore, $pageTasks, $io, '      ');

                $pageResultFiles[] = basename($pageStore->getFilePath());
            }
        }

        // ── Step 3: Build aggregated text from page OCR results ─────────────
        $pageTexts = [];
        foreach ($pageFiles as $pageIndex => $pageFile) {
            $paddedNum    = str_pad((string) ($pageIndex + 1), strlen((string) $pageCount), '0', STR_PAD_LEFT);
            $pageStoreKey = "{$sha1}-page-{$paddedNum}";
            $pageStore    = new JsonFileResultStore(null, $this->dataDir, [], $pageStoreKey);
            $ocrResult    = $pageStore->getPrior('ocr_mistral');
            $pageTexts[]  = $ocrResult['text'] ?? '';
        }
        $aggregatedText = implode("\n\n--- Page Break ---\n\n", array_filter($pageTexts));

        // ── Step 4: Run doc-level tasks with aggregated text ────────────────
        if ($docTasks) {
            $docStore = new JsonFileResultStore(
                $url,
                $this->dataDir,
                [
                    'image_url'       => $absoluteUrl,
                    'aggregated_text' => $aggregatedText,
                    'page_count'      => $pageCount,
                ],
            );

            // Inject page references and page_count into the doc store data
            $docData = [
                'page_count' => $pageCount,
                'pages'      => $pageResultFiles,
            ];
            // Save this metadata so the viewer can find per-page files
            $docStore->saveResult('_pages', $docData);

            $docPrior = array_keys($docStore->getAllPrior());
            $pendingDoc = array_diff($docTasks, $docPrior);

            if (!$force && $pendingDoc === []) {
                $io->comment('    Doc-level tasks all complete — skipping.');
            } else {
                $io->writeln('    <info>Document-level tasks</info>');
                $this->wireCallbacks($io, '      ');

                $queue = array_values($docTasks);
                while ($queue !== []) {
                    if ($this->runner->runNext($docStore, $queue) === null) {
                        break;
                    }
                }

                $this->flagRefusalOutputs($docStore, $docTasks, $io, '      ');
            }
        } elseif ($pageResultFiles) {
            // Even if no doc tasks, write the page references
            $docStore = new JsonFileResultStore($url, $this->dataDir);
            $docStore->saveResult('_pages', [
                'page_count' => $pageCount,
                'pages'      => $pageResultFiles,
            ]);
        }
    }

    // ── PDF page splitting ──────────────────────────────────────────────────

    /**
     * Ensure page JPGs exist for a PDF. Returns sorted list of absolute paths.
     * Downloads remote PDFs, splits with pdftoppm, auto-crops with mogrify.
     */
    private function ensurePageImages(string $url, string $sha1, string $pagesDir, SymfonyStyle $io): array
    {
        if (!is_dir($pagesDir)) {
            mkdir($pagesDir, 0755, true);
        }

        // Check if already split
        $existing = glob("{$pagesDir}/{$sha1}-*.jpg");
        if ($existing) {
            sort($existing);
            $io->comment(sprintf('    %d page images already exist.', count($existing)));
            return $existing;
        }

        $finder   = new ExecutableFinder();
        $pdftoppm = $finder->find('pdftoppm');
        if (!$pdftoppm) {
            $io->warning('pdftoppm not found — install poppler-utils to split PDFs.');
            return [];
        }

        $io->write('    Splitting PDF... ');

        $localPath = $this->resolvePdfToLocal($url);
        if ($localPath === null) {
            $io->writeln('<error>cannot access PDF</error>');
            return [];
        }

        $process = new Process([$pdftoppm, '-jpeg', '-r', '200', $localPath, "{$pagesDir}/{$sha1}"]);
        $process->setTimeout(600);
        $process->run();

        // Clean up temp file for remote PDFs
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            @unlink($localPath);
        }

        if (!$process->isSuccessful()) {
            $io->writeln('<error>FAILED</error>');
            $io->warning($process->getErrorOutput());
            return [];
        }

        $pageFiles = glob("{$pagesDir}/{$sha1}-*.jpg");
        sort($pageFiles);
        $count = count($pageFiles);
        $io->write(sprintf('%d pages', $count));

        // Auto-crop whitespace
        $mogrify = $finder->find('mogrify');
        if ($mogrify && $pageFiles) {
            $cropProcess = new Process(
                array_merge([$mogrify, '-fuzz', '10%', '-trim', '+repage'], $pageFiles)
            );
            $cropProcess->setTimeout(300);
            $cropProcess->run();
            $io->writeln($cropProcess->isSuccessful() ? ' <info>(cropped)</info>' : ' <comment>(crop failed)</comment>');
        } else {
            $io->writeln('');
        }

        return $pageFiles;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function wireCallbacks(SymfonyStyle $io, string $indent = '  '): void
    {
        $this->runner->onBeforeTask(function (string $task) use ($io, $indent): void {
            $io->write(sprintf('%s%-28s ', $indent, $task));
        });
        $this->runner->onAfterTask(function (string $task, array $result, string $status) use ($io): void {
            $label = match ($status) {
                'done'    => '<info>done</info>',
                'skipped' => '<comment>skipped</comment>',
                'failed'  => '<error>FAILED: ' . ($result['error'] ?? '') . '</error>',
                default   => $status,
            };
            $io->writeln($label);
        });
    }

    private function isPdf(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        return str_ends_with(strtolower($path), '.pdf');
    }

    /**
     * Resolve a PDF URL to a local file path.
     * Downloads remote PDFs to a temp file.
     */
    private function resolvePdfToLocal(string $url): ?string
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && !str_starts_with($url, 'file://')) {
            $path = $this->publicDir . '/' . ltrim($url, '/');
            return is_readable($path) ? $path : null;
        }

        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            return is_readable($path) ? $path : null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 120]);
            if ($response->getStatusCode() >= 300) {
                return null;
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'pdf_');
            file_put_contents($tmpFile, $response->getContent());
            return $tmpFile;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveManifest(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->dataDir . '/' . ltrim($path, '/');
    }

    private function resolveUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $localPath = rtrim($this->publicDir, '/') . '/' . ltrim($url, '/');
        return 'file://' . $localPath;
    }

    private function isAccessible(string $url, SymfonyStyle $io): bool
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            if (!is_readable($path)) {
                $io->warning("Local file not found: {$path} — skipping.");
                return false;
            }
            return true;
        }

        try {
            $response = $this->httpClient->request('HEAD', $url, ['timeout' => 10]);
            $status   = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            $io->warning("Image returned HTTP {$status}: {$url} — skipping.");
            return false;
        } catch (\Throwable $e) {
            $io->warning("Image not accessible ({$e->getMessage()}): {$url} — skipping.");
            return false;
        }
    }

    /**
     * Return the subset of candidate tasks that are marked failed for this entry.
     */
    private function getFailedTasksForEntry(string $url, array $candidateTasks): array
    {
        $resultPath = rtrim($this->dataDir, '/') . '/' . sha1($url) . '.json';
        if (!is_file($resultPath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($resultPath), true);
        if (!is_array($data)) {
            return [];
        }

        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            return [];
        }

        $failed = [];
        foreach ($candidateTasks as $taskName) {
            $taskResult = $results[$taskName] ?? null;
            if (!is_array($taskResult)) {
                continue;
            }

            if (($taskResult['failed'] ?? false) === true || isset($taskResult['error'])) {
                $failed[] = $taskName;
                continue;
            }

            if (in_array($taskName, ['annotate_handwriting', 'transcribe_handwriting'], true)) {
                $text = $this->extractTaskText($taskName, $taskResult);
                if ($this->looksLikeRefusal($text)) {
                    $failed[] = $taskName;
                }
            }
        }

        return $failed;
    }

    /**
     * Convert non-empty model refusal payloads into failed task results.
     */
    private function flagRefusalOutputs(JsonFileResultStore $store, array $taskNames, SymfonyStyle $io, string $indent): void
    {
        foreach (array_intersect($taskNames, ['annotate_handwriting', 'transcribe_handwriting']) as $taskName) {
            $result = $store->getPrior($taskName);
            if (!is_array($result) || ($result['failed'] ?? false) === true || isset($result['error'])) {
                continue;
            }

            $text = $this->extractTaskText($taskName, $result);
            if (!$this->looksLikeRefusal($text)) {
                continue;
            }

            $store->saveResult($taskName, [
                'failed' => true,
                'error' => 'Model refusal returned instead of task output.',
                'raw_response' => $text,
            ]);

            $io->writeln(sprintf('%s<error>%s flagged as failed: refusal payload</error>', $indent, $taskName));
        }
    }

    private function extractTaskText(string $taskName, array $result): ?string
    {
        if ($taskName === 'annotate_handwriting') {
            if (isset($result['annotated_text']) && is_string($result['annotated_text'])) {
                return trim($result['annotated_text']);
            }

            $firstPage = $result['pages'][0]['annotated_text'] ?? null;
            return is_string($firstPage) ? trim($firstPage) : null;
        }

        if (isset($result['text']) && is_string($result['text'])) {
            return trim($result['text']);
        }

        return null;
    }

    private function looksLikeRefusal(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $normalized = strtolower(trim($text));
        foreach (self::REFUSAL_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
