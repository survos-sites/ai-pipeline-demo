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
 * Reads public/data/images.json, runs the configured pipeline for each entry,
 * and writes results to public/data/{sha1}.json.
 *
 * After processing, regenerates public/data/images.json with a `result_file`
 * field pointing at the result JSON so the static viewer can find it.
 *
 * Usage:
 *   bin/console app:process                    # process all entries
 *   bin/console app:process --limit=1          # process first entry only
 *   bin/console app:process --force            # re-run even if results exist
 *   bin/console app:process --manifest=other.json
 */
#[AsCommand('app:process', 'Process images.json through configured AI pipelines')]
final class ProcessImagesCommand extends Command
{
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
                'Override the pipeline for all entries (comma-separated task names)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $force    = (bool) $input->getOption('force');
        $limit    = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $taskOver = $input->getOption('tasks');

        $io->title('AI Pipeline — Process Images');
        $io->writeln(sprintf('Data dir : %s', $this->dataDir));

        $registered = array_keys($this->registry->getTaskMap());
        if ($registered === []) {
            $io->error('No tasks registered. Check your services.yaml / bundle configuration.');
            return Command::FAILURE;
        }
        $io->writeln('Registered tasks: ' . implode(', ', $registered));
        $io->newLine();

        $manifests   = (array) $input->getOption('manifest');
        $totalProcessed = 0;

        foreach ($manifests as $manifest) {
            // ── Load manifest ─────────────────────────────────────────────────
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

            // ── Process each entry ────────────────────────────────────────────
            $processed = 0;

            foreach ($entries as $i => &$entry) {
                if ($limit !== null && $totalProcessed >= $limit) {
                    break;
                }

                $url      = $entry['url']      ?? null;
                $title    = $entry['title']    ?? $url;
                $pipeline = $taskOver
                    ? array_filter(array_map('trim', explode(',', $taskOver)))
                    : ($entry['pipeline'] ?? $registered);

                if ($url === null) {
                    $io->warning("Entry {$i} has no 'url' — skipping.");
                    continue;
                }

                // Resolve relative paths (e.g. "images/foo.jpg") to absolute URLs
                // so the AI tasks receive something fetchable.
                $absoluteUrl = $this->resolveUrl($url);

                // Warn and skip if the image is not accessible.
                if (!$this->isAccessible($absoluteUrl, $io)) {
                    continue;
                }

                $io->writeln(sprintf('  [%d/%d] %s', $i + 1, count($entries), $title));

                // Subject (SHA-1 key) = original manifest url (stable, even if relative).
                // image_url input = absolute URL so tasks can actually fetch it.
                // Extra inputs from the manifest entry (e.g. max_pages) are merged in.
                $extraInputs = $entry['inputs'] ?? [];
                $store     = new JsonFileResultStore($url, $this->dataDir, array_merge(['image_url' => $absoluteUrl], $extraInputs));
                $priorKeys = array_keys($store->getAllPrior());

                if (!$force && $priorKeys !== []) {
                    $pending = array_diff($pipeline, $priorKeys);
                    if ($pending === []) {
                        $io->comment('  All tasks already complete — skipping (use --force to rerun).');
                        $entry['result_file'] = basename($store->getFilePath());
                        $entry['status']      = 'complete';
                        continue;
                    }
                    $io->comment(sprintf('  Resuming — %d task(s) remaining: %s', count($pending), implode(', ', $pending)));
                }

                // Wire progress callbacks
                $this->runner->onBeforeTask(function (string $task) use ($io): void {
                    $io->write(sprintf('  %-28s ', $task));
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

                $queue = array_values($pipeline);
                while ($queue !== []) {
                    if ($this->runner->runNext($store, $queue) === null) {
                        break;
                    }
                }

                $entry['result_file'] = basename($store->getFilePath());
                $entry['status']      = 'complete';
                $processed++;
                $totalProcessed++;
            }
            unset($entry);

            // ── Write updated manifest ────────────────────────────────────────
            file_put_contents(
                $manifestPath,
                json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );

            $io->comment(sprintf('  %d entries processed in %s.', $processed, basename($manifestPath)));
        }

        // ── Split PDFs into page images ──────────────────────────────────────
        $this->splitPdfs($io);

        $io->newLine();
        $io->success(sprintf('Processed %d entries across %d manifest(s).', $totalProcessed, count($manifests)));

        return Command::SUCCESS;
    }

    /**
     * Split PDFs referenced in manifests into per-page JPGs in public/images/pages/.
     * Uses SHA1(url) as the filename prefix to match the result file naming.
     * Handles both local and remote PDFs (downloads remote ones to a temp file).
     * Auto-crops whitespace from scanned pages.
     * Skips PDFs whose pages already exist.
     */
    private function splitPdfs(SymfonyStyle $io): void
    {
        $pagesDir = $this->publicDir . '/images/pages';

        $finder   = new ExecutableFinder();
        $pdftoppm = $finder->find('pdftoppm');
        if (!$pdftoppm) {
            $io->warning('pdftoppm not found — install poppler-utils to split PDFs into page images.');
            return;
        }

        if (!is_dir($pagesDir)) {
            mkdir($pagesDir, 0755, true);
        }

        $mogrify = $finder->find('mogrify');

        // Collect PDF entries from all manifests
        $pdfEntries = [];
        foreach (['images.json', 'pdfs.json'] as $manifest) {
            $path = $this->dataDir . '/' . $manifest;
            if (!is_file($path)) {
                continue;
            }
            $entries = json_decode(file_get_contents($path), true) ?? [];
            foreach ($entries as $entry) {
                $url = $entry['url'] ?? '';
                if (preg_match('/\.pdf(\?.*)?$/i', $url)) {
                    $pdfEntries[] = $entry;
                }
            }
        }

        if (!$pdfEntries) {
            return;
        }

        $io->section('Splitting PDFs into page images');

        foreach ($pdfEntries as $entry) {
            $url  = $entry['url'];
            $sha1 = sha1($url);

            // Skip if pages already exist
            $existing = glob("{$pagesDir}/{$sha1}-*.jpg");
            if ($existing) {
                $io->comment(sprintf('  %s: %d pages exist — skipping.', $entry['title'] ?? $sha1, count($existing)));
                continue;
            }

            $io->write(sprintf('  %s... ', $entry['title'] ?? basename($url)));

            // Resolve to a local file path
            $localPath = $this->resolvePdfToLocal($url);
            if ($localPath === null) {
                $io->writeln('<error>cannot access PDF</error>');
                continue;
            }

            $process = new Process([$pdftoppm, '-jpeg', '-r', '200', $localPath, "{$pagesDir}/{$sha1}"]);
            $process->setTimeout(600);
            $process->run();

            // Clean up temp file for remote PDFs
            if (!str_starts_with($url, 'file://') && !is_file($this->publicDir . '/' . ltrim($url, '/'))) {
                @unlink($localPath);
            }

            if (!$process->isSuccessful()) {
                $io->writeln('<error>FAILED</error>');
                $io->warning($process->getErrorOutput());
                continue;
            }

            $pageFiles = glob("{$pagesDir}/{$sha1}-*.jpg");
            $count     = count($pageFiles);
            $io->write(sprintf('%d pages', $count));

            // Auto-crop whitespace
            if ($mogrify && $pageFiles) {
                $cropProcess = new Process(
                    array_merge([$mogrify, '-fuzz', '10%', '-trim', '+repage'], $pageFiles)
                );
                $cropProcess->setTimeout(300);
                $cropProcess->run();

                $io->writeln($cropProcess->isSuccessful() ? ' <info>(cropped)</info>' : ' <comment>(crop failed)</comment>');
            } else {
                $io->writeln($mogrify ? '' : ' <comment>(install imagemagick for auto-crop)</comment>');
            }
        }
    }

    /**
     * Resolve a PDF URL to a local file path.
     * Downloads remote PDFs to a temp file.
     */
    private function resolvePdfToLocal(string $url): ?string
    {
        // Local relative path (e.g. "images/foo.pdf")
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && !str_starts_with($url, 'file://')) {
            $path = $this->publicDir . '/' . ltrim($url, '/');
            return is_readable($path) ? $path : null;
        }

        // file:// URL
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            return is_readable($path) ? $path : null;
        }

        // Remote URL — download to temp file
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

    /**
     * Convert a manifest url to something tasks can fetch.
     *
     * - Absolute URLs (http/https) are returned as-is.
     * - Relative paths (e.g. "images/foo.jpg") are resolved against public/
     *   and converted to a file:// URL so HttpClient can read local files.
     */
    private function resolveUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        // Relative → absolute local path
        $localPath = rtrim($this->publicDir, '/') . '/' . ltrim($url, '/');
        return 'file://' . $localPath;
    }

    /**
     * Return true if the URL is reachable (HTTP 2xx) or is a readable local file.
     * Logs a warning and returns false otherwise.
     */
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
}
